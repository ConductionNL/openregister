<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyNotificationService;
use OCA\OpenRegister\Service\Party\PartyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
		$this->email = $this->createMock(EmailSender::class);

		$this->email->method('sendToAddress')->willReturnCallback(
			function (string $address, string $displayName, string $subject, string $body): string {
				$this->sent[] = ['address' => $address, 'name' => $displayName, 'subject' => $subject];

				return EmailSender::OUTCOME_DISPATCHED;
			}
		);

		$this->service = new PartyNotificationService($this->links, $this->parties, $this->email);
	}//end setUp()

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
}//end class
