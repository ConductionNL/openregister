<?php

/**
 * External email recipients, the sent-email event and role-shaped fields.
 *
 * The senders, the recipient resolver and the rate limiter are the REAL
 * shared units over mocked Nextcloud services, and the event is the REAL
 * FlowEmailSentEvent the service builds, captured at the dispatcher. Every
 * refusal is asserted next to a send that does go out, so a green test
 * cannot be an allowlist that refuses everything.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\FlowEmailSentEvent;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowMessagingService;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowStepReport;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Service\Notification\NotificationChannelPolicy;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Service\Notification\RateLimiter;
use OCA\OpenRegister\Service\Notification\TalkSender;
use OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture\RecordingMessage;
use OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture\RecordingSymfonyMessage;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * External recipients, FlowEmailSentEvent and role fields.
 */
class FlowMessagingServiceExternalRecipientsTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private IUserManager&MockObject $userManager;

	private INotificationManager&MockObject $notificationManager;

	private IMailer&MockObject $mailer;

	private IEventDispatcher&MockObject $dispatcher;

	private FlowRunContext $runContext;

	/**
	 * Mutable app-config values.
	 *
	 * @var array<string, string>
	 */
	private array $appValues = [];

	/**
	 * Users that exist.
	 *
	 * @var array<int, string>
	 */
	private array $users = ['alice', 'bob', 'carol', 'dave@corp.example'];

	/**
	 * Every address the mailer was handed, in order.
	 *
	 * @var array<int, string>
	 */
	private array $mailedTo = [];

	/**
	 * Every event the dispatcher was handed.
	 *
	 * @var array<int, Event>
	 */
	private array $events = [];

	/**
	 * Whether the mailer throws on send.
	 */
	private bool $mailerThrows = false;

	/**
	 * Every message the mailer was handed, in order.
	 *
	 * @var array<int, RecordingMessage>
	 */
	private array $messages = [];

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
	 * The rate limiter's cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $cacheStore = [];

	/**
	 * Every opt-out question integriq was asked.
	 *
	 * @var array<int, OutboundSendDecisionRequestedEvent>
	 */
	private array $questions = [];

	/**
	 * Uids the notification manager was asked to notify.
	 *
	 * @var array<int, string>
	 */
	private array $notified = [];

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appValues[$key] ?? $default)
		);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->appValues[$key] ?? $default)
		);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('userExists')->willReturnCallback(
			fn (string $uid): bool => in_array($uid, $this->users, true)
		);
		$this->userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if (in_array($uid, $this->users, true) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn(true);
				$user->method('getEMailAddress')->willReturn(str_replace('@', '.at.', $uid) . '@users.example');
				$user->method('getDisplayName')->willReturn(ucfirst($uid));
				return $user;
			}
		);

		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createMessage')->willReturnCallback(
			fn (): RecordingMessage => new RecordingSymfonyMessage()
		);
		$this->mailer->method('send')->willReturnCallback(
			function (RecordingMessage $message): array {
				foreach (array_keys($message->to) as $address) {
					$this->mailedTo[] = (string)$address;
				}

				$this->messages[] = $message;
				if ($this->mailerThrows === true) {
					throw new RuntimeException('SMTP down');
				}

				return [];
			}
		);

		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->notificationManager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setUser')->willReturnCallback(
					function (string $uid) use ($notification): INotification {
						$this->notified[] = $uid;
						return $notification;
					}
				);
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setObject')->willReturnSelf();
				$notification->method('setSubject')->willReturnSelf();
				return $notification;
			}
		);

		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				if ($event instanceof OutboundSendDecisionRequestedEvent) {
					$this->answerAsIntegriq(event: $event);
					return;
				}

				$this->events[] = $event;
			}
		);

		$this->runContext = new FlowRunContext();
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

		$exempt = in_array($event->getCategory(), ['besluit', 'statutory', 'account', 'security'], true);
		foreach ($event->getRecipients() as $recipient) {
			$address = (string)$recipient['address'];
			$link = 'https://nc.example/u/' . md5($address);
			$unsubscribe = ['url' => $link, 'oneClickUrl' => $link, 'smsText' => null, 'headers' => []];
			if ($exempt === true) {
				$unsubscribe = null;
			}

			if ($exempt === false && in_array($address, $this->optedOut, true) === true) {
				$event->setDecision($address, ['send' => false, 'overridden' => false, 'code' => 'opted-out', 'reason' => 'opted out', 'unsubscribe' => null]);
				continue;
			}

			$event->setDecision($address, ['send' => true, 'overridden' => false, 'code' => 'allowed', 'reason' => '', 'unsubscribe' => $unsubscribe]);
		}

		$event->setHandled(true);
	}//end answerAsIntegriq()

	/**
	 * The service under test, wired onto the REAL shared units.
	 *
	 * @return FlowMessagingService The service.
	 */
	private function makeService(): FlowMessagingService {
		$logger = $this->createMock(LoggerInterface::class);
		$policy = new NotificationChannelPolicy(appConfig: $this->appConfig, logger: $logger);

		// An in-memory cache, so the rate limiter's buckets persist across
		// steps within a test and a test can read whether one was used.
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cacheStore[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->cacheStore[$key] = $value;
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnArgument(3);

		return new FlowMessagingService(
			channelPolicy: $policy,
			recipientResolver: new NotificationRecipientResolver(
				userManager: $this->userManager,
				groupManager: $this->createMock(IGroupManager::class),
				logger: $logger
			),
			templating: new NotificationTemplating(logger: $logger),
			ncSender: new NcNotificationSender(
				notificationManager: $this->notificationManager,
				logger: $logger,
				userManager: $this->userManager,
				jobList: $this->createMock(IJobList::class),
				channelPolicy: $policy
			),
			emailSender: new EmailSender(
				userManager: $this->userManager,
				mailer: $this->mailer,
				logger: $logger,
				channelPolicy: $policy
			),
			talkSender: new TalkSender(httpClient: $this->createMock(IClientService::class), logger: $logger),
			rateLimiter: new RateLimiter(cacheFactory: $cacheFactory, appConfig: $this->appConfig, logger: $logger),
			preferences: new NotificationPreferenceService(
				config: $config,
				schemaMapper: $this->createMock(SchemaMapper::class),
				logger: $logger
			),
			userManager: $this->userManager,
			appConfig: $this->appConfig,
			logger: $logger,
			eventDispatcher: $this->dispatcher,
			runContext: $this->runContext
		);
	}//end makeService()

	/**
	 * A run context acting as alice.
	 *
	 * @return array The context.
	 */
	private function context(): array {
		return [
			FlowStepReport::CONTEXT_KEY => new FlowStepReport(),
			'runAs' => 'alice',
			FlowRunService::FLOW_ID_CONTEXT_KEY => 'flow-42',
			FlowRunContext::CONTEXT_RUN => 'run-7',
		];
	}//end context()

	/**
	 * Send an email for one item.
	 *
	 * @param array $config The step config.
	 * @param array $json The item json.
	 *
	 * @return array The report.
	 */
	private function sendEmail(array $config, array $json = ['name' => 'Case 7']): array {
		return $this->makeService()->sendEmail(
			config: $config + ['subject' => 'About {{ name }}', 'body' => 'Dear reader of {{ name }}'],
			items: [FlowItems::item(json: $json)],
			context: $this->context(),
			stepName: 'openregister.send-email'
		);
	}//end sendEmail()

	// ---- Allowlist modes ---------------------------------------------------

	public function testTheDefaultModeRefusesAnAddressAndStillMailsTheUser(): void {
		$report = $this->sendEmail(config: ['recipients' => ['citizen@example.org', 'bob']]);

		// POSITIVE CONTROL: the user on the same step is mailed.
		$this->assertSame(['bob@users.example'], $this->mailedTo);
		$this->assertSame(1, $report['delivered']['count']);

		$this->assertSame(1, $report['refusedRecipients']['count']);
		$this->assertSame(
			[['recipient' => 'citizen@example.org', 'reason' => FlowMessagingService::REFUSED_EXTERNAL_OFF]],
			$report['refusedRecipients']['sample']
		);
		$this->assertArrayNotHasKey('unknownRecipients', $report);
	}//end testTheDefaultModeRefusesAnAddressAndStillMailsTheUser()

	public function testAnUnrecognisedModeFallsBackToClosed(): void {
		$report = $this->sendEmail(config: ['recipients' => ['citizen@example.org'], 'externalRecipients' => 'everyone']);

		$this->assertSame([], $this->mailedTo);
		$this->assertSame(FlowMessagingService::REFUSED_EXTERNAL_OFF, $report['refusedRecipients']['sample'][0]['reason']);
	}//end testAnUnrecognisedModeFallsBackToClosed()

	public function testTheObjectModeMailsOnlyAddressesOnTheItem(): void {
		$json = [
			'name' => 'Case 7',
			'contacts' => [
				['email' => 'a@example.org', 'name' => 'Anna'],
				['emailAddress' => 'c@example.org'],
			],
			'requester' => ['correspondence' => ['value' => 'D@Example.org']],
		];

		$report = $this->sendEmail(
			config: [
				'recipients' => ['{{ contacts }}', 'b@example.org', 'd@example.org'],
				'externalRecipients' => 'object',
			],
			json: $json
		);

		// On the item: both contacts, and a literal that the item holds in a
		// nested field under a different case. Not on the item: refused.
		$this->assertSame(['a@example.org', 'c@example.org', 'd@example.org'], $this->mailedTo);
		$this->assertSame(3, $report['delivered']['count']);
		$this->assertSame(
			[['recipient' => 'b@example.org', 'reason' => FlowMessagingService::REFUSED_NOT_ON_ITEM]],
			$report['refusedRecipients']['sample']
		);
	}//end testTheObjectModeMailsOnlyAddressesOnTheItem()

	public function testTheAnyModeMailsAValidAddressAndRefusesAMalformedOne(): void {
		$report = $this->sendEmail(
			config: ['recipients' => ['x@example.org', '{{ contact }}'], 'externalRecipients' => 'any'],
			json: ['name' => 'Case 7', 'contact' => 'not an @ address']
		);

		$this->assertSame(['x@example.org'], $this->mailedTo);
		$this->assertSame(
			[['recipient' => 'not an @ address', 'reason' => FlowMessagingService::REFUSED_INVALID_ADDRESS]],
			$report['refusedRecipients']['sample']
		);
	}//end testTheAnyModeMailsAValidAddressAndRefusesAMalformedOne()

	public function testAFieldHoldingAListOfAddressesIsMailedOncePerAddress(): void {
		$this->sendEmail(
			config: ['recipients' => ['{{ item.cc }}'], 'externalRecipients' => 'any'],
			json: ['cc' => ['one@example.org', 'ONE@example.org', 'two@example.org']]
		);

		$this->assertSame(['one@example.org', 'two@example.org'], $this->mailedTo);
	}//end testAFieldHoldingAListOfAddressesIsMailedOncePerAddress()

	public function testAUidThatLooksLikeAnAddressStaysAUser(): void {
		$report = $this->sendEmail(config: ['recipients' => ['dave@corp.example']]);

		// Mailed through the ACCOUNT path (the user's own address), not
		// refused as an external address under the closed default.
		$this->assertSame(['dave.at.corp.example@users.example'], $this->mailedTo);
		$this->assertArrayNotHasKey('refusedRecipients', $report);
	}//end testAUidThatLooksLikeAnAddressStaysAUser()

	public function testANotificationStepStillReadsAnAddressAsUnknown(): void {
		$report = $this->makeService()->sendNotification(
			config: ['recipients' => ['citizen@example.org', 'bob'], 'message' => 'hi', 'externalRecipients' => 'any'],
			items: [FlowItems::item(json: ['name' => 'Case 7'])],
			context: $this->context(),
			stepName: 'openregister.send-notification'
		);

		$this->assertSame(['bob'], $this->notified);
		$this->assertSame(['citizen@example.org'], $report['unknownRecipients']['sample']);
		$this->assertArrayNotHasKey('refusedRecipients', $report);
		$this->assertSame([], $this->events);
	}//end testANotificationStepStillReadsAnAddressAsUnknown()

	public function testAddressesCountTowardTheRecipientBound(): void {
		$this->appValues[FlowMessagingService::CONFIG_RECIPIENT_BOUND] = '1';

		try {
			$this->sendEmail(config: ['recipients' => ['bob', 'x@example.org'], 'externalRecipients' => 'any']);
			$this->fail('Two recipients above a bound of one must be refused.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('2 recipients', $e->getMessage());
		}

		$this->assertSame([], $this->mailedTo);
	}//end testAddressesCountTowardTheRecipientBound()

	// ---- FlowEmailSentEvent ------------------------------------------------

	public function testEachSentEmailIsAnnouncedWithTheWholeContract(): void {
		$this->runContext->push(runUuid: 'run-7', nodeId: 'mail-the-citizen', sequence: 3);
		try {
			$this->sendEmail(
				config: ['recipients' => ['bob', 'x@example.org'], 'externalRecipients' => 'any'],
				json: [
					'name' => 'Case 7',
					'@self' => ['id' => 'obj-1', 'register' => 5, 'schema' => 9],
				]
			);
		} finally {
			$this->runContext->pop();
		}

		$this->assertCount(2, $this->events);
		[$user, $external] = $this->events;
		$this->assertInstanceOf(FlowEmailSentEvent::class, $user);
		$this->assertInstanceOf(FlowEmailSentEvent::class, $external);

		$this->assertSame('bob', $user->getRecipient());
		$this->assertSame(FlowEmailSentEvent::KIND_USER, $user->getChannelKind());
		$this->assertSame('x@example.org', $external->getRecipient());
		$this->assertSame(FlowEmailSentEvent::KIND_EXTERNAL, $external->getChannelKind());

		foreach ([$user, $external] as $event) {
			$this->assertSame('5', $event->getRegister());
			$this->assertSame('9', $event->getSchema());
			$this->assertSame('obj-1', $event->getObjectUuid());
			$this->assertSame('About Case 7', $event->getSubject());
			$this->assertSame('Dear reader of Case 7', $event->getBody());
			$this->assertSame('flow-42', $event->getFlowId());
			$this->assertSame('run-7', $event->getRunId());
			$this->assertSame('mail-the-citizen', $event->getStepName());
			$this->assertSame('alice', $event->getActingUser());
		}
	}//end testEachSentEmailIsAnnouncedWithTheWholeContract()

	public function testWithoutARunFrameTheStepNameIsTheNodeTypeAndANonObjectHasNoUuid(): void {
		$this->sendEmail(config: ['recipients' => ['bob']], json: ['name' => 'Case 7']);

		$this->assertCount(1, $this->events);
		$this->assertSame('openregister.send-email', $this->events[0]->getStepName());
		$this->assertNull($this->events[0]->getObjectUuid());
		$this->assertNull($this->events[0]->getRegister());
		$this->assertNull($this->events[0]->getSchema());
	}//end testWithoutARunFrameTheStepNameIsTheNodeTypeAndANonObjectHasNoUuid()

	public function testAFailedSendIsNotAnnounced(): void {
		$this->mailerThrows = true;

		try {
			$this->sendEmail(config: ['recipients' => ['bob', 'x@example.org'], 'externalRecipients' => 'any']);
			$this->fail('A failed handoff must fail the step.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('x@example.org', $e->getMessage());
		}

		$this->assertSame([], $this->events);
	}//end testAFailedSendIsNotAnnounced()

	public function testARefusedAddressIsNotAnnounced(): void {
		$this->sendEmail(config: ['recipients' => ['x@example.org']]);

		$this->assertSame([], $this->events);
	}//end testARefusedAddressIsNotAnnounced()

	public function testAThrowingListenerDoesNotFailTheStep(): void {
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->willThrowException(new RuntimeException('filing failed'));

		$report = $this->sendEmail(config: ['recipients' => ['bob']]);

		// The email went out; failing the step now would retry and send it twice.
		$this->assertSame(1, $report['delivered']['count']);
		$this->assertSame(['bob@users.example'], $this->mailedTo);
	}//end testAThrowingListenerDoesNotFailTheStep()

	// ---- Opt-out before send (opt-out-before-send) --------------------------

	/**
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function testAnOptedOutAddressIsSkippedAndNotAnnounced(): void {
		$this->optedOut = ['jan@example.nl'];

		$report = $this->sendEmail(
			config: ['recipients' => ['jan@example.nl', 'piet@example.nl'], 'externalRecipients' => 'any', 'messageCategory' => 'service']
		);

		// POSITIVE CONTROL: the other address on the same step is mailed.
		$this->assertSame(['piet@example.nl'], $this->mailedTo);
		$this->assertSame(1, $report['delivered']['count']);
		$this->assertSame(['count' => 1, 'sample' => ['jan@example.nl']], $report['optedOut']);
		$this->assertSame(0, $report['authorityUnavailable']['count']);

		$this->assertCount(1, $this->events);
		$this->assertSame('piet@example.nl', $this->events[0]->getRecipient());

		// One question for the whole step, with the declared category.
		$this->assertCount(1, $this->questions);
		$this->assertSame('service', $this->questions[0]->getCategory());
		$this->assertSame('email', $this->questions[0]->getChannel());
		$this->assertSame('openregister', $this->questions[0]->getSourceApp());
	}//end testAnOptedOutAddressIsSkippedAndNotAnnounced()

	/**
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function testASkippedAddressDoesNotUseTheRateLimit(): void {
		$this->optedOut = ['jan@example.nl'];
		$this->appValues['notification_rate_limit_default_bucket_size'] = '1';
		$this->appValues['notification_rate_limit_default_refill_seconds'] = '86400';

		$this->sendEmail(config: ['recipients' => ['jan@example.nl'], 'externalRecipients' => 'any']);
		$this->optedOut = [];
		$report = $this->sendEmail(config: ['recipients' => ['jan@example.nl'], 'externalRecipients' => 'any']);

		$this->assertSame(['jan@example.nl'], $this->mailedTo);
		$this->assertSame(1, $report['delivered']['count']);
	}//end testASkippedAddressDoesNotUseTheRateLimit()

	/**
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function testWithoutIntegriqAServiceMailIsRefusedAndTheUserStillMailed(): void {
		$this->integriq = 'ignore';

		$report = $this->sendEmail(config: ['recipients' => ['jan@example.nl', 'bob'], 'externalRecipients' => 'any']);

		$this->assertSame(['bob@users.example'], $this->mailedTo);
		$this->assertSame(['count' => 1, 'sample' => ['jan@example.nl']], $report['authorityUnavailable']);
		$this->assertSame(0, $report['optedOut']['count']);
	}//end testWithoutIntegriqAServiceMailIsRefusedAndTheUserStillMailed()

	/**
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function testWithoutIntegriqABesluitIsSentWithoutALinkOrHeader(): void {
		$this->integriq = 'ignore';

		$report = $this->sendEmail(config: ['recipients' => ['jan@example.nl'], 'externalRecipients' => 'any', 'messageCategory' => 'besluit']);

		$this->assertSame(['jan@example.nl'], $this->mailedTo);
		$this->assertSame(1, $report['delivered']['count']);
		$this->assertSame('Dear reader of Case 7', $this->messages[0]->body);
		$this->assertSame([], $this->messages[0]->headers);
	}//end testWithoutIntegriqABesluitIsSentWithoutALinkOrHeader()

	/**
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004
	 */
	public function testAServiceMailCarriesTheLinkAndTheHeaders(): void {
		$this->sendEmail(config: ['recipients' => ['piet@example.nl'], 'externalRecipients' => 'any']);

		$link = 'https://nc.example/u/' . md5('piet@example.nl');
		$this->assertStringStartsWith('Dear reader of Case 7', $this->messages[0]->body);
		$this->assertStringEndsWith('Stop receiving these messages: ' . $link, $this->messages[0]->body);
		$this->assertSame('<' . $link . '>', $this->messages[0]->headers['List-Unsubscribe']);
		$this->assertSame('List-Unsubscribe=One-Click', $this->messages[0]->headers['List-Unsubscribe-Post']);
	}//end testAServiceMailCarriesTheLinkAndTheHeaders()

	/**
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004
	 */
	public function testABesluitMailCarriesNoLinkWithIntegriqPresent(): void {
		$this->sendEmail(config: ['recipients' => ['piet@example.nl'], 'externalRecipients' => 'any', 'messageCategory' => 'besluit']);

		$this->assertSame('besluit', $this->questions[0]->getCategory());
		$this->assertSame('Dear reader of Case 7', $this->messages[0]->body);
		$this->assertSame([], $this->messages[0]->headers);
	}//end testABesluitMailCarriesNoLinkWithIntegriqPresent()

	/**
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-step-declares-a-message-category-req-ero-002
	 */
	public function testAnOldStepAsksAsServiceAndAUserOnlyStepAsksNothing(): void {
		$this->sendEmail(config: ['recipients' => ['piet@example.nl'], 'externalRecipients' => 'any']);
		$this->assertSame('service', $this->questions[0]->getCategory());

		$this->questions = [];
		$this->sendEmail(config: ['recipients' => ['bob']]);
		$this->assertSame([], $this->questions);
	}//end testAnOldStepAsksAsServiceAndAUserOnlyStepAsksNothing()

	// ---- send-notification role fields -------------------------------------

	/**
	 * Role-shaped fields and the uids each must notify.
	 *
	 * @return array<string, array{0: mixed, 1: array<int, string>, 2: array<int, string>}>
	 */
	public static function roleShapes(): array {
		return [
			'a uid' => ['bob', ['bob'], []],
			'a list of uids' => [['carol', 'alice'], ['carol', 'alice'], []],
			'a list of objects with userId' => [[['userId' => 'bob', 'name' => 'Bob B']], ['bob'], []],
			'a list of objects with uid' => [[['uid' => 'carol'], ['uid' => 'ghost']], ['carol'], ['ghost']],
			'a single object' => [['uid' => 'carol', 'displayName' => 'alice'], ['carol'], []],
		];
	}//end roleShapes()

	/**
	 * A role field on the item notifies exactly the uids it names.
	 *
	 * @param mixed $value The field's value.
	 * @param array<int, string> $expected The uids notified.
	 * @param array<int, string> $unknown The uids reported unknown.
	 *
	 * @dataProvider roleShapes
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('roleShapes')]
	public function testSendNotificationReadsRoleShapedFields(mixed $value, array $expected, array $unknown): void {
		$report = $this->makeService()->sendNotification(
			config: ['recipients' => ['{{ handler }}'], 'message' => 'Case {{ name }} needs you'],
			items: [FlowItems::item(json: ['name' => 'Case 7', 'handler' => $value])],
			context: $this->context(),
			stepName: 'openregister.send-notification'
		);

		$this->assertSame($expected, $this->notified);
		if ($unknown === []) {
			$this->assertArrayNotHasKey('unknownRecipients', $report);
			return;
		}

		$this->assertSame($unknown, $report['unknownRecipients']['sample']);
	}//end testSendNotificationReadsRoleShapedFields()
}//end class
