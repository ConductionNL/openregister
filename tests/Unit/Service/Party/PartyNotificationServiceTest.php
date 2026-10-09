<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Notification\OptOutAuthority;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartyNotificationService;
use OCA\OpenRegister\Service\Party\PartyService;
use PHPUnit\Framework\MockObject\MockObject;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A melder with no account is reached over the addresses the party holds.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */
class PartyNotificationServiceTest extends TestCase {

	/**
	 * The link rows.
	 *
	 * @var ContactLinkMapper&MockObject
	 */
	private $links;

	/**
	 * The party records.
	 *
	 * @var PartyService&MockObject
	 */
	private $parties;

	/**
	 * The declared effects.
	 *
	 * @var PartyIndicatorGuard&MockObject
	 */
	private $indicators;

	/**
	 * The email channel.
	 *
	 * @var EmailSender&MockObject
	 */
	private $email;

	/**
	 * The service under test.
	 *
	 * @var PartyNotificationService
	 */
	private PartyNotificationService $service;

	/**
	 * What was handed to the mailer.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $sent = [];

	/**
	 * What the stub integriq listener does: 'answer' or 'ignore' (integriq absent).
	 */
	private string $integriq = 'answer';

	/**
	 * Addresses the stub integriq listener reports as opted out.
	 *
	 * @var array<int, string>
	 */
	private array $optedOut = [];

	/**
	 * Every opt-out question integriq was asked.
	 *
	 * @var array<int, OutboundSendDecisionRequestedEvent>
	 */
	private array $questions = [];

	/**
	 * Build the service on doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findPartiesForObject'])
			->getMock();
		$this->parties = $this->createMock(PartyService::class);
		$this->indicators = $this->createMock(PartyIndicatorGuard::class);
		$this->email = $this->createMock(EmailSender::class);

		$this->email->method('sendToAddress')->willReturnCallback(
			function (string $address, string $displayName, string $subject, string $body, ?array $unsubscribe = null): string {
				$this->sent[] = ['address' => $address, 'name' => $displayName, 'subject' => $subject, 'body' => $body, 'unsubscribe' => $unsubscribe];

				return EmailSender::OUTCOME_DISPATCHED;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				if ($event instanceof OutboundSendDecisionRequestedEvent) {
					$this->answerAsIntegriq(event: $event);
				}
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);

		$this->service = new PartyNotificationService(
			$this->links,
			$this->parties,
			$this->indicators,
			$this->email,
			new OptOutAuthority(eventDispatcher: $dispatcher, appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class))
		);
	}//end setUp()

	/**
	 * A stub integriq listener on the real contract event.
	 *
	 * @param OutboundSendDecisionRequestedEvent $event The question.
	 *
	 * @return void
	 */
	private function answerAsIntegriq(OutboundSendDecisionRequestedEvent $event): void {
		$this->questions[] = $event;
		if ($this->integriq === 'ignore') {
			return;
		}

		foreach ($event->getRecipients() as $recipient) {
			$address = (string)$recipient['address'];
			if (in_array($address, $this->optedOut, true) === true) {
				$event->setDecision($address, ['send' => false, 'overridden' => false, 'code' => 'opted-out', 'reason' => '', 'unsubscribe' => null]);
				continue;
			}

			$link = 'https://nc.example/u/' . md5($address);
			$event->setDecision($address, ['send' => true, 'overridden' => false, 'code' => 'allowed', 'reason' => '', 'unsubscribe' => ['url' => $link, 'oneClickUrl' => $link]]);
		}

		$event->setHandled(true);
	}//end answerAsIntegriq()

	/**
	 * Two parties on one object, each with one correspondence address.
	 *
	 * @return void
	 */
	private function objectHasTwoParties(): void {
		$links = [];
		$byUuid = [];
		foreach (['party-a' => 'jan@example.org', 'party-b' => 'piet@example.org'] as $uuid => $address) {
			$link = new ContactLink();
			$link->setObjectUuid('case-1');
			$link->setPartyUuid($uuid);
			$link->setRole('aanvrager');
			$link->setDisplayName(ucfirst($uuid));
			$links[] = $link;

			$party = new ObjectEntity();
			$party->setUuid($uuid);
			$byUuid[$uuid] = [$party, $address];
		}

		$this->links->method('findPartiesForObject')->willReturn($links);
		$this->parties->method('find')->willReturnCallback(static fn (string $partyUuid): ObjectEntity => $byUuid[$partyUuid][0]);
		$this->parties->method('definitionFor')->willReturn(new PartyDefinition());
		$this->indicators->method('sendRefusalFor')->willReturn(null);
		$this->parties->method('addressesOfKind')->willReturnCallback(
			static fn (ObjectEntity $p, string $kind, ?PartyDefinition $d = null): array => [
				['kind' => 'correspondence', 'type' => 'email', 'value' => $byUuid[(string)$p->getUuid()][1], 'label' => null],
			]
		);
	}//end objectHasTwoParties()

	/**
	 * One party on an object with the addresses and indicators given.
	 *
	 * @param array<int, array<string, mixed>> $addresses The addresses.
	 * @param array<int, array<string, mixed>> $indicators The indicators.
	 *
	 * @return void
	 */
	private function objectHasPartyWith(array $addresses, array $indicators = []): void {
		$link = new ContactLink();
		$link->setObjectUuid('case-1');
		$link->setPartyUuid('party-a');
		$link->setRole('aanvrager');
		$link->setDisplayName('Jan Jansen');
		$this->links->method('findPartiesForObject')->willReturn([$link]);

		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$this->parties->method('find')->willReturn($party);
		$this->parties->method('definitionFor')->willReturn(new PartyDefinition());
		$this->parties->method('indicators')->willReturn($indicators);

		$refusal = null;
		foreach ($indicators as $indicator) {
			if (($indicator['effect'] ?? '') === PartyIndicatorGuard::EFFECT_REFUSE_SEND) {
				$refusal = $indicator['label'];
			}
		}

		$this->indicators->method('sendRefusalFor')->willReturn($refusal);
		$this->parties->method('addressesOfKind')->willReturnCallback(
			static fn (ObjectEntity $p, string $kind, ?PartyDefinition $d = null): array => array_values(
				array_filter($addresses, static fn (array $a): bool => $a['kind'] === $kind)
			)
		);
		$this->parties->method('addresses')->willReturn($addresses);
	}//end objectHasPartyWith()

	/**
	 * An outbound message goes to the correspondence address, not to the
	 * case address that happens to come first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testAMelderWithNoAccountIsNotifiedAtTheCorrespondenceAddress(): void {
		$this->objectHasPartyWith(
			addresses: [
				['kind' => 'case', 'type' => 'email', 'value' => 'zaak@example.org', 'label' => null],
				['kind' => 'correspondence', 'type' => 'email', 'value' => 'jan@example.org', 'label' => null],
			]
		);

		$outcome = $this->service->notifyParties(
			objectUuid: 'case-1',
			subject: 'Uw melding',
			body: 'Wij hebben uw melding ontvangen.'
		);

		$this->assertCount(1, $this->sent);
		$this->assertSame('jan@example.org', $this->sent[0]['address']);
		$this->assertSame('Jan Jansen', $this->sent[0]['name']);
		$this->assertSame(EmailSender::OUTCOME_DISPATCHED, $outcome[0]['outcome']);
	}//end testAMelderWithNoAccountIsNotifiedAtTheCorrespondenceAddress()

	/**
	 * A party carrying a refuse-send indicator gets nothing, and the outcome
	 * says why rather than reporting a delivery that never happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function testARefuseSendIndicatorStopsTheMessage(): void {
		$this->objectHasPartyWith(
			addresses: [['kind' => 'correspondence', 'type' => 'email', 'value' => 'jan@example.org', 'label' => null]],
			indicators: [['key' => 'geen-post', 'label' => 'Geen post', 'effect' => 'refuse-send', 'note' => null]]
		);

		$outcome = $this->service->notifyParties(objectUuid: 'case-1', subject: 'Uw melding', body: 'Tekst');

		$this->assertSame([], $this->sent);
		$this->assertSame('refused-by-indicator', $outcome[0]['outcome']);
		$this->assertSame(
			'Geen post',
			$this->service->recipientsForObject(objectUuid: 'case-1')[0]['refusedBy']
		);
	}//end testARefuseSendIndicatorStopsTheMessage()

	/**
	 * A party holding no address at all is reported as unreachable rather
	 * than silently skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testAPartyWithNoAddressIsReportedNotSkipped(): void {
		$this->objectHasPartyWith(addresses: []);

		$outcome = $this->service->notifyParties(objectUuid: 'case-1', subject: 'Uw melding', body: 'Tekst');

		$this->assertCount(1, $outcome);
		$this->assertSame(EmailSender::OUTCOME_NO_ADDRESS, $outcome[0]['outcome']);
	}//end testAPartyWithNoAddressIsReportedNotSkipped()

	/**
	 * Naming a role narrows the recipients to the parties holding it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testARoleNarrowsTheRecipients(): void {
		$this->objectHasPartyWith(
			addresses: [['kind' => 'correspondence', 'type' => 'email', 'value' => 'jan@example.org', 'label' => null]]
		);

		$this->assertCount(1, $this->service->recipientsForObject(objectUuid: 'case-1', role: 'aanvrager'));
		$this->assertCount(0, $this->service->recipientsForObject(objectUuid: 'case-1', role: 'gemachtigde'));
	}//end testARoleNarrowsTheRecipients()

	/**
	 * An opted-out party is not mailed; the other is, with the link.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-parties-notification-asks-integriq-before-it-mails-a-party-req-ero-003
	 */
	public function testAnOptedOutPartyIsNotMailed(): void {
		$this->objectHasTwoParties();
		$this->optedOut = ['piet@example.org'];

		$outcome = $this->service->notifyParties(objectUuid: 'case-1', subject: 'Uw zaak', body: 'Nieuwe status', category: 'case-update');

		$this->assertCount(1, $this->sent);
		$this->assertSame('jan@example.org', $this->sent[0]['address']);
		$this->assertStringStartsWith('Nieuwe status', $this->sent[0]['body']);
		$this->assertStringEndsWith('https://nc.example/u/' . md5('jan@example.org'), $this->sent[0]['body']);
		$this->assertSame('https://nc.example/u/' . md5('jan@example.org'), $this->sent[0]['unsubscribe']['oneClickUrl']);

		$this->assertSame(['party' => 'party-a', 'address' => 'jan@example.org', 'outcome' => EmailSender::OUTCOME_DISPATCHED], $outcome[0]);
		$this->assertSame(['party' => 'party-b', 'address' => null, 'outcome' => 'refused-opted-out'], $outcome[1]);

		// One question for both parties, with the rule's category.
		$this->assertCount(1, $this->questions);
		$this->assertSame('case-update', $this->questions[0]->getCategory());
		$this->assertSame([['address' => 'jan@example.org'], ['address' => 'piet@example.org']], $this->questions[0]->getRecipients());
	}//end testAnOptedOutPartyIsNotMailed()

	/**
	 * Without integriq a service mail is refused, a besluit is sent without a link.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-parties-notification-asks-integriq-before-it-mails-a-party-req-ero-003
	 */
	public function testWithoutIntegriqOnlyAnExemptMailGoesOut(): void {
		$this->objectHasTwoParties();
		$this->integriq = 'ignore';

		$refused = $this->service->notifyParties(objectUuid: 'case-1', subject: 'S', body: 'B');
		$this->assertSame([], $this->sent);
		$this->assertSame(['authority-unavailable', 'authority-unavailable'], array_column($refused, 'outcome'));

		$sent = $this->service->notifyParties(objectUuid: 'case-1', subject: 'S', body: 'B', category: 'besluit');
		$this->assertCount(2, $this->sent);
		$this->assertSame('B', $this->sent[0]['body']);
		$this->assertNull($this->sent[0]['unsubscribe']);
		$this->assertSame([EmailSender::OUTCOME_DISPATCHED, EmailSender::OUTCOME_DISPATCHED], array_column($sent, 'outcome'));
	}//end testWithoutIntegriqOnlyAnExemptMailGoesOut()

	/**
	 * A refuse-send indicator still comes first: that party is not even asked about.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-parties-notification-asks-integriq-before-it-mails-a-party-req-ero-003
	 */
	public function testAnIndicatorRefusalComesBeforeTheQuestion(): void {
		$this->objectHasPartyWith(
			addresses: [['kind' => 'correspondence', 'type' => 'email', 'value' => 'jan@example.org', 'label' => null]],
			indicators: [['key' => 'geen-post', 'label' => 'Geen post', 'effect' => 'refuse-send', 'note' => null]]
		);
		$this->optedOut = ['jan@example.org'];

		$outcome = $this->service->notifyParties(objectUuid: 'case-1', subject: 'S', body: 'B');

		$this->assertSame('refused-by-indicator', $outcome[0]['outcome']);
		$this->assertSame([], $this->questions);
	}//end testAnIndicatorRefusalComesBeforeTheQuestion()
}//end class
