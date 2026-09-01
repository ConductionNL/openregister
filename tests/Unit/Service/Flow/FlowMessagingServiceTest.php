<?php

/**
 * FlowMessagingService guardrail and attribution tests.
 *
 * Every guardrail is exercised WITH ITS POSITIVE CONTROL — the send that DOES
 * go out when the guard is off — so a green test cannot be a guard that fires
 * always (or never). The senders are the REAL shared units over mocked
 * Nextcloud services, so the assertion "the flow send went through the same
 * channel sender" is structural, not declared.
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
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowMessagingService;
use OCA\OpenRegister\Service\Flow\FlowStepReport;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Service\Notification\NotificationChannelPolicy;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Service\Notification\RateLimiter;
use OCA\OpenRegister\Service\Notification\TalkSender;
use OCA\OpenRegister\Service\Notification\TalkSendException;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A tiny in-memory ICache for the rate limiter, so bucket state is real
 * between calls without a cache backend.
 */
class MessagingTestCache implements ICache {
	/** @var array<string, mixed> */
	private array $data = [];

	public function get($key) {
		return ($this->data[$key] ?? null);
	}

	public function set($key, $value, $ttl = 0) {
		$this->data[$key] = $value;
		return true;
	}

	public function hasKey($key) {
		return isset($this->data[$key]);
	}

	public function remove($key) {
		unset($this->data[$key]);
		return true;
	}

	public function clear($prefix = '') {
		$this->data = [];
		return true;
	}

	public static function isAvailable(): bool {
		return true;
	}
}//end class

/**
 * A TalkSender whose Talk-app boundary is replaced by a recorder, so the
 * decision logic above it (attribution, kill switch, failure escalation)
 * runs for real without a Talk installation.
 */
class RecordingTalkSender extends TalkSender {
	/** @var array<int, array<string, string>> */
	public array $posts = [];

	public ?TalkSendException $throwOnPost = null;

	protected function postViaTalkApp(string $token, string $message, string $actorUid): void {
		if ($this->throwOnPost !== null) {
			throw $this->throwOnPost;
		}

		$this->posts[] = [
			'token' => $token,
			'message' => $message,
			'actor' => $actorUid,
		];
	}
}//end class

/**
 * Guardrails, attribution and outcome reporting of the flow messaging bridge.
 */
class FlowMessagingServiceTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private IUserManager&MockObject $userManager;

	private IGroupManager&MockObject $groupManager;

	private INotificationManager&MockObject $notificationManager;

	private IMailer&MockObject $mailer;

	private IJobList&MockObject $jobList;

	private IConfig&MockObject $config;

	private RecordingTalkSender $talkSender;

	private MessagingTestCache $cache;

	/**
	 * Mutable app-config values read through the IAppConfig mock.
	 *
	 * @var array<string, string>
	 */
	private array $appValues = [];

	/**
	 * Mutable user-config values read through the IConfig mock (preferences).
	 *
	 * @var array<string, string>
	 */
	private array $userValues = [];

	/**
	 * Users that exist, uid => enabled.
	 *
	 * @var array<string, bool>
	 */
	private array $users = [
		'alice' => true,
		'bob' => true,
		'carol' => true,
	];

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
			fn (string $uid): bool => isset($this->users[$uid])
		);
		$this->userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if (isset($this->users[$uid]) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn($this->users[$uid]);
				$user->method('getEMailAddress')->willReturn($uid . '@example.org');
				$user->method('getDisplayName')->willReturn(ucfirst($uid));
				return $user;
			}
		);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string => ($this->userValues[$uid . '|' . $key] ?? $default)
		);

		$this->cache = new MessagingTestCache();
		$this->talkSender = new RecordingTalkSender(
			httpClient: $this->createMock(IClientService::class),
			logger: $this->createMock(LoggerInterface::class),
			config: null,
			channelPolicy: $this->channelPolicy()
		);
	}//end setUp()

	/**
	 * A real channel policy over the mutable app-config map.
	 *
	 * @return NotificationChannelPolicy The policy.
	 */
	private function channelPolicy(): NotificationChannelPolicy {
		return new NotificationChannelPolicy(
			appConfig: $this->appConfig,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end channelPolicy()

	/**
	 * The service under test, wired onto the REAL shared units.
	 *
	 * @return FlowMessagingService The service.
	 */
	private function makeService(): FlowMessagingService {
		$logger = $this->createMock(LoggerInterface::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		return new FlowMessagingService(
			channelPolicy: $this->channelPolicy(),
			recipientResolver: new NotificationRecipientResolver(
				userManager: $this->userManager,
				groupManager: $this->groupManager,
				logger: $logger
			),
			templating: new NotificationTemplating(logger: $logger),
			ncSender: new NcNotificationSender(
				notificationManager: $this->notificationManager,
				logger: $logger,
				userManager: $this->userManager,
				jobList: $this->jobList,
				channelPolicy: $this->channelPolicy()
			),
			emailSender: new EmailSender(
				userManager: $this->userManager,
				mailer: $this->mailer,
				logger: $logger,
				channelPolicy: $this->channelPolicy()
			),
			talkSender: $this->talkSender,
			rateLimiter: $this->rateLimiter(),
			preferences: new NotificationPreferenceService(
				config: $this->config,
				schemaMapper: $this->createMock(SchemaMapper::class),
				logger: $logger
			),
			userManager: $this->userManager,
			appConfig: $this->appConfig,
			logger: $logger
		);
	}//end makeService()

	/**
	 * A real rate limiter over the shared in-memory cache.
	 *
	 * @return RateLimiter The limiter.
	 */
	private function rateLimiter(): RateLimiter {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		return new RateLimiter(
			cacheFactory: $cacheFactory,
			appConfig: $this->appConfig,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end rateLimiter()

	/**
	 * A run context with an acting user and a step report handle.
	 *
	 * @param string|null $runAs The acting user, or null for none.
	 *
	 * @return array The context.
	 */
	private function contextFor(?string $runAs = 'alice'): array {
		$context = [FlowStepReport::CONTEXT_KEY => new FlowStepReport()];
		if ($runAs !== null) {
			$context['runAs'] = $runAs;
		}

		return $context;
	}//end contextFor()

	/**
	 * One item with the given json.
	 *
	 * @param array $json The item json.
	 *
	 * @return array The item list.
	 */
	private function itemsOf(array $json = ['name' => 'Case 7']): array {
		return [FlowItems::item(json: $json)];
	}//end itemsOf()

	/**
	 * A mock INotification whose fluent setters chain.
	 *
	 * @return INotification&MockObject The mock.
	 */
	private function fluentNotification(): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();

		return $notification;
	}//end fluentNotification()

	// ---- Attribution -------------------------------------------------------

	public function testMissingActingUserFailsTheStepAndSendsNothing(): void {
		$this->notificationManager->expects($this->never())->method('notify');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/runAs/');

		$this->makeService()->sendNotification(
			config: ['recipients' => ['bob'], 'message' => 'hi'],
			items: $this->itemsOf(),
			context: $this->contextFor(runAs: null),
			stepName: 'openregister.send-notification'
		);
	}//end testMissingActingUserFailsTheStepAndSendsNothing()

	public function testDisabledActingUserFailsTheStepAndSendsNothing(): void {
		$this->users['ghosted'] = false;
		$this->notificationManager->expects($this->never())->method('notify');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/disabled/');

		$this->makeService()->sendNotification(
			config: ['recipients' => ['bob'], 'message' => 'hi'],
			items: $this->itemsOf(),
			context: $this->contextFor(runAs: 'ghosted'),
			stepName: 'openregister.send-notification'
		);
	}//end testDisabledActingUserFailsTheStepAndSendsNothing()

	public function testTalkPostIsAttributedToTheActingUser(): void {
		$report = $this->makeService()->sendTalkMessage(
			config: ['conversation' => 'room-token', 'message' => 'Case {{ name }} moved'],
			items: $this->itemsOf(json: ['name' => 'Case 7']),
			context: $this->contextFor(runAs: 'alice'),
			stepName: 'openregister.send-talk-message'
		);

		$this->assertCount(1, $this->talkSender->posts);
		$this->assertSame('alice', $this->talkSender->posts[0]['actor']);
		$this->assertSame('room-token', $this->talkSender->posts[0]['token']);
		$this->assertSame('Case Case 7 moved', $this->talkSender->posts[0]['message']);
		$this->assertSame('alice', $report['actor']);
		$this->assertSame(1, $report['delivered']['count']);
	}//end testTalkPostIsAttributedToTheActingUser()

	public function testTalkNonParticipantIsAStepFailureNotAnAutoJoin(): void {
		$this->talkSender->throwOnPost = new TalkSendException(
			'User "alice" is not a participant of Talk conversation "room-token"; the message was not sent and the user was not auto-joined.'
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/not a participant/');

		$this->makeService()->sendTalkMessage(
			config: ['conversation' => 'room-token', 'message' => 'hello'],
			items: $this->itemsOf(),
			context: $this->contextFor(runAs: 'alice'),
			stepName: 'openregister.send-talk-message'
		);
	}//end testTalkNonParticipantIsAStepFailureNotAnAutoJoin()

	// ---- Kill switches -----------------------------------------------------

	public function testChannelKillSwitchSilencesFlowSendsAndRecordsTheSkip(): void {
		// POSITIVE CONTROL first: with the switch off, the send goes out
		// through the real channel sender.
		$this->notificationManager->method('createNotification')->willReturn($this->fluentNotification());
		$this->notificationManager->expects($this->exactly(1))->method('notify');

		$service = $this->makeService();
		$context = $this->contextFor();
		$service->sendNotification(
			config: ['recipients' => ['bob'], 'message' => 'hi'],
			items: $this->itemsOf(),
			context: $context,
			stepName: 'openregister.send-notification'
		);

		// Now the guard: switch thrown, nothing may leave, and the outcome is
		// a SKIP in the report — not a failure, not silence.
		$this->appValues['notification_channel_nc_notification_enabled'] = 'false';
		$report = $service->sendNotification(
			config: ['recipients' => ['bob'], 'message' => 'hi'],
			items: $this->itemsOf(),
			context: $context,
			stepName: 'openregister.send-notification'
		);

		$this->assertSame(1, $report['skippedByKillSwitch']['count']);
		$this->assertSame(['bob'], $report['skippedByKillSwitch']['sample']);
		$this->assertSame(0, $report['delivered']['count']);
	}//end testChannelKillSwitchSilencesFlowSendsAndRecordsTheSkip()

	public function testEmailKillSwitchStopsTheMailer(): void {
		$this->mailer->expects($this->never())->method('send');
		$this->appValues['notification_channel_email_enabled'] = 'false';

		$report = $this->makeService()->sendEmail(
			config: ['recipients' => ['bob'], 'subject' => 's', 'body' => 'b'],
			items: $this->itemsOf(),
			context: $this->contextFor(),
			stepName: 'openregister.send-email'
		);

		$this->assertSame(1, $report['skippedByKillSwitch']['count']);
	}//end testEmailKillSwitchStopsTheMailer()

	// ---- Preferences -------------------------------------------------------

	public function testRecipientPreferenceSkipsTheChannelAndIsRecordedAsSkipNotFailure(): void {
		// bob turned flow sends off; carol did not. One mail leaves, for
		// carol — the positive control inside the same dispatch.
		$this->userValues['bob|notification_pref/flow/send'] = json_encode(['enabled' => false]);
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('setPlainBody')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->expects($this->exactly(1))->method('send');

		$report = $this->makeService()->sendEmail(
			config: ['recipients' => ['bob', 'carol'], 'subject' => 's', 'body' => 'b'],
			items: $this->itemsOf(),
			context: $this->contextFor(),
			stepName: 'openregister.send-email'
		);

		$this->assertSame(1, $report['skippedByPreference']['count']);
		$this->assertSame(['bob'], $report['skippedByPreference']['sample']);
		$this->assertSame(1, $report['delivered']['count']);
		$this->assertSame(['carol'], $report['delivered']['sample']);
		$this->assertSame(0, $report['failed']['count']);
	}//end testRecipientPreferenceSkipsTheChannelAndIsRecordedAsSkipNotFailure()

	// ---- Recipient bound ---------------------------------------------------

	public function testExplodingRecipientListIsRefusedBeforeAnythingSends(): void {
		// A group that expands to 30 members against the default bound of 25.
		$members = [];
		for ($i = 0; $i < 30; $i++) {
			$uid = sprintf('user%02d', $i);
			$this->users[$uid] = true;
			$member = $this->createMock(IUser::class);
			$member->method('getUID')->willReturn($uid);
			$members[] = $member;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($members);
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->groupManager->method('get')->willReturn($group);

		$this->notificationManager->expects($this->never())->method('notify');

		try {
			$this->makeService()->sendNotification(
				config: ['recipients' => ['everyone'], 'message' => 'hi'],
				items: $this->itemsOf(),
				context: $this->contextFor(),
				stepName: 'openregister.send-notification'
			);
			$this->fail('The exploding recipient list must be refused.');
		} catch (RuntimeException $e) {
			// The failure names the resolved count and the bound.
			$this->assertStringContainsString('30', $e->getMessage());
			$this->assertStringContainsString('25', $e->getMessage());
		}
	}//end testExplodingRecipientListIsRefusedBeforeAnythingSends()

	public function testRecipientBoundIsAppConfigRaisable(): void {
		$this->appValues[FlowMessagingService::CONFIG_RECIPIENT_BOUND] = '2';
		$this->notificationManager->method('createNotification')->willReturn($this->fluentNotification());
		$this->notificationManager->expects($this->exactly(2))->method('notify');

		// Two recipients under a bound of two: sends proceed.
		$this->makeService()->sendNotification(
			config: ['recipients' => ['bob', 'carol'], 'message' => 'hi'],
			items: $this->itemsOf(),
			context: $this->contextFor(),
			stepName: 'openregister.send-notification'
		);
	}//end testRecipientBoundIsAppConfigRaisable()

	// ---- Rate limiting -----------------------------------------------------

	public function testDeclarativeFillBlocksTheFlowSendTheBudgetIsShared(): void {
		// The DECLARATIVE subsystem fills bob's shared per-recipient budget
		// (default bucket 10) under its own rule ids…
		$limiter = $this->rateLimiter();
		for ($i = 0; $i < 10; $i++) {
			$this->assertTrue($limiter->tryConsume('schema-rule-' . $i, 'bob'));
		}

		// …and the flow send to bob in the same window is rate-limited, while
		// carol — whose budget is untouched — receives: the positive control.
		$this->notificationManager->method('createNotification')->willReturn($this->fluentNotification());
		$this->notificationManager->expects($this->exactly(1))->method('notify');

		$report = $this->makeService()->sendNotification(
			config: ['recipients' => ['bob', 'carol'], 'message' => 'hi'],
			items: $this->itemsOf(),
			context: $this->contextFor(),
			stepName: 'openregister.send-notification'
		);

		$this->assertSame(1, $report['rateLimited']['count']);
		$this->assertSame(['bob'], $report['rateLimited']['sample']);
		$this->assertSame(['carol'], $report['delivered']['sample']);
	}//end testDeclarativeFillBlocksTheFlowSendTheBudgetIsShared()

	// ---- Failures and the run log -----------------------------------------

	public function testSendFailureIsAStepFailureAndTheReportNamesIt(): void {
		$this->mailer->method('createMessage')->willThrowException(new RuntimeException('SMTP down'));

		$context = $this->contextFor();
		try {
			$this->makeService()->sendEmail(
				config: ['recipients' => ['bob'], 'subject' => 's', 'body' => 'b'],
				items: $this->itemsOf(),
				context: $context,
				stepName: 'openregister.send-email'
			);
			$this->fail('A failed handoff must fail the step.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('email', $e->getMessage());
		}

		// The report reached the run-log handle BEFORE the throw, so a failed
		// step still explains itself.
		$handle = $context[FlowStepReport::CONTEXT_KEY];
		$detail = $handle->take();
		$this->assertSame(1, $detail['messaging']['failed']['count']);
		$this->assertSame(['bob'], $detail['messaging']['failed']['sample']);
	}//end testSendFailureIsAStepFailureAndTheReportNamesIt()

	public function testUnknownRecipientsAreReportedNotSilentlyDropped(): void {
		$this->notificationManager->method('createNotification')->willReturn($this->fluentNotification());

		$context = $this->contextFor();
		$report = $this->makeService()->sendNotification(
			config: ['recipients' => ['bob', 'nobody-here'], 'message' => 'hi'],
			items: $this->itemsOf(),
			context: $context,
			stepName: 'openregister.send-notification'
		);

		$this->assertSame(1, $report['unknownRecipients']['count']);
		$this->assertSame(['nobody-here'], $report['unknownRecipients']['sample']);
	}//end testUnknownRecipientsAreReportedNotSilentlyDropped()

	public function testTemplateRecipientResolvesAFieldOnTheItem(): void {
		$this->notificationManager->method('createNotification')->willReturn($this->fluentNotification());
		$this->notificationManager->expects($this->exactly(1))->method('notify');

		$report = $this->makeService()->sendNotification(
			config: ['recipients' => ['{{ item.assignee }}'], 'message' => 'hi'],
			items: $this->itemsOf(json: ['assignee' => 'carol']),
			context: $this->contextFor(),
			stepName: 'openregister.send-notification'
		);

		$this->assertSame(['carol'], $report['delivered']['sample']);
	}//end testTemplateRecipientResolvesAFieldOnTheItem()

	public function testWebPushRidesAlongWithADeliveredNotification(): void {
		$this->notificationManager->method('createNotification')->willReturn($this->fluentNotification());
		// One notification, one ride-along job — with no flow-side
		// configuration asking for it.
		$this->jobList->expects($this->exactly(1))->method('add');

		$this->makeService()->sendNotification(
			config: ['recipients' => ['bob'], 'message' => 'hi'],
			items: $this->itemsOf(),
			context: $this->contextFor(),
			stepName: 'openregister.send-notification'
		);
	}//end testWebPushRidesAlongWithADeliveredNotification()
}//end class
