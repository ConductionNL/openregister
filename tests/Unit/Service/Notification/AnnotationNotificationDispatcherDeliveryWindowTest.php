<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use DateTime;
use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\QueuedNotification;
use OCA\OpenRegister\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Service\Notification\NotificationDeliveryWindowService;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the delivery-window (quiet-hours) / digest-schedule dispatcher
 * gate added by notification-delivery-windows:
 *  - a non-critical rule fired while the recipient is inside their quiet
 *    hours is QUEUED (a QueuedNotification row is persisted, history
 *    records `queued-quiet-hours`), never dropped, and never immediately
 *    delivered;
 *  - `critical: true` bypasses the gate — immediate dispatch, no queued
 *    row;
 *  - a recipient with no configured window and a rule with no `digest`
 *    schedule dispatches immediately (backward compatibility — apps
 *    declaring nothing keep today's behaviour);
 *  - broadcast channels (webhook/talk) are unaffected by the gate.
 *
 * @spec openspec/changes/notification-delivery-windows/specs/notificatie-engine/spec.md
 */
class AnnotationNotificationDispatcherDeliveryWindowTest extends TestCase {
	private SchemaMapper&MockObject $schemaMapper;
	private INotificationManager&MockObject $notificationManager;
	private LoggerInterface&MockObject $logger;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private IMailer&MockObject $mailer;
	private IActivityManager&MockObject $activityManager;
	private IClientService&MockObject $httpClient;
	private IServerContainer&MockObject $serverContainer;
	private NotificationHistoryMapper&MockObject $historyMapper;
	private QueuedNotificationMapper&MockObject $queuedMapper;
	private NotificationDeliveryWindowService&MockObject $windowService;
	private DigestScheduleEvaluator&MockObject $digestEvaluator;
	private ITimeFactory&MockObject $timeFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->activityManager = $this->createMock(IActivityManager::class);
		$this->httpClient = $this->createMock(IClientService::class);
		$this->serverContainer = $this->createMock(IServerContainer::class);
		$this->historyMapper = $this->createMock(NotificationHistoryMapper::class);
		$this->queuedMapper = $this->createMock(QueuedNotificationMapper::class);
		$this->windowService = $this->createMock(NotificationDeliveryWindowService::class);
		$this->digestEvaluator = $this->createMock(DigestScheduleEvaluator::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->userManager->method('userExists')->willReturn(true);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $default ?? 'http://localhost'
		);
		$this->serverContainer->method('get')->willReturnCallback(
			fn (string $id): mixed => ($id === IConfig::class) ? $config : null
		);

		$this->timeFactory->method('getDateTime')->willReturn(new DateTime('2026-07-12T20:15:00+02:00'));
	}

	private function makeDispatcher(): AnnotationNotificationDispatcher {
		return new AnnotationNotificationDispatcher(
			$this->schemaMapper,
			$this->notificationManager,
			$this->logger,
			$this->groupManager,
			$this->userManager,
			$this->mailer,
			$this->activityManager,
			$this->httpClient,
			$this->serverContainer,
			null,
			null,
			$this->historyMapper,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			$this->windowService,
			$this->queuedMapper,
			$this->digestEvaluator,
			$this->timeFactory
		);
	}

	/**
	 * @param array<string, mixed> $ruleOverrides Keys merged onto the base rule spec.
	 */
	private function schemaWithRule(array $ruleOverrides): Schema {
		$spec = array_merge(
			[
				'trigger' => ['type' => 'created'],
				'channels' => ['nc-notification'],
				'recipients' => [['kind' => 'users', 'users' => ['medewerker-1']]],
				'subject' => 'demo',
			],
			$ruleOverrides
		);

		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('meldingen');
		$schema->setConfiguration(['x-openregister-notifications' => ['rule-x' => $spec]]);
		return $schema;
	}

	private function object(Schema $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setSchema((string)$schema->getSlug());
		$object->setRegister('r');
		$object->setObject(['title' => 'demo']);
		return $object;
	}

	public function testQuietHoursQueuesInsteadOfDispatching(): void {
		$schema = $this->schemaWithRule([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->windowService->method('getForUser')->with('medewerker-1')->willReturn(
			['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']
		);
		$this->windowService->method('isInsideWindow')->willReturn(true);

		$this->notificationManager->expects($this->never())->method('createNotification');

		$this->queuedMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static function (QueuedNotification $entity): bool {
				return $entity->getRuleKey() === 'rule-x'
					&& $entity->getRecipient() === 'medewerker-1'
					&& $entity->getReason() === QueuedNotification::REASON_QUIET_HOURS;
			}));

		$this->makeDispatcher()->dispatch($this->object($schema), 'created');
	}

	public function testHistoryRecordsQueuedQuietHoursStatus(): void {
		$schema = $this->schemaWithRule([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->windowService->method('getForUser')->willReturn(
			['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']
		);
		$this->windowService->method('isInsideWindow')->willReturn(true);

		$capturedStatus = null;
		$this->historyMapper->expects($this->atLeastOnce())
			->method('record')
			->willReturnCallback(function (string $ruleId, string $channel, string $recipient, string $status, ...$rest) use (&$capturedStatus) {
				$capturedStatus = $status;
				return new \OCA\OpenRegister\Db\NotificationHistory();
			});

		$this->makeDispatcher()->dispatch($this->object($schema), 'created');

		$this->assertSame('queued-quiet-hours', $capturedStatus);
	}

	public function testCriticalBypassesQuietHours(): void {
		$schema = $this->schemaWithRule(['critical' => true]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->windowService->method('getForUser')->willReturn(
			['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']
		);
		$this->windowService->method('isInsideWindow')->willReturn(true);

		$this->queuedMapper->expects($this->never())->method('insert');
		$this->notificationManager->expects($this->once())->method('createNotification')
			->willReturnCallback(function () {
				$notif = $this->createMock(INotification::class);
				$notif->method('setApp')->willReturnSelf();
				$notif->method('setUser')->willReturnSelf();
				$notif->method('setDateTime')->willReturnSelf();
				$notif->method('setObject')->willReturnSelf();
				$notif->method('setSubject')->willReturnSelf();
				return $notif;
			});

		$this->makeDispatcher()->dispatch($this->object($schema), 'created');
	}

	/**
	 * Backward compatibility: a recipient with no configured window and a
	 * rule with no `digest` schedule dispatches immediately — apps
	 * declaring nothing keep today's (pre-change) behaviour.
	 */
	public function testNoWindowAndNoDigestDispatchesImmediately(): void {
		$schema = $this->schemaWithRule([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->windowService->method('getForUser')->willReturn(null);

		$this->queuedMapper->expects($this->never())->method('insert');
		$this->notificationManager->expects($this->once())->method('createNotification')
			->willReturnCallback(function () {
				$notif = $this->createMock(INotification::class);
				$notif->method('setApp')->willReturnSelf();
				$notif->method('setUser')->willReturnSelf();
				$notif->method('setDateTime')->willReturnSelf();
				$notif->method('setObject')->willReturnSelf();
				$notif->method('setSubject')->willReturnSelf();
				return $notif;
			});

		$this->makeDispatcher()->dispatch($this->object($schema), 'created');
	}

	/**
	 * A rule declaring a `digest` schedule always queues (never dispatches
	 * immediately) — the flush job decides when the schedule is due.
	 */
	public function testDigestScheduleAlwaysQueuesRegardlessOfWindow(): void {
		$schema = $this->schemaWithRule(['digest' => ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Europe/Amsterdam']]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->windowService->method('getForUser')->willReturn(null);
		$this->digestEvaluator->method('isValidDigestSpec')->willReturn(true);

		$this->notificationManager->expects($this->never())->method('createNotification');
		$this->queuedMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static function (QueuedNotification $entity): bool {
				return $entity->getReason() === QueuedNotification::REASON_DIGEST_SCHEDULE;
			}));

		$this->makeDispatcher()->dispatch($this->object($schema), 'created');
	}

	/**
	 * Both an active window AND a digest schedule declared -> reason
	 * records both (window-overlap: neither alone determines flush; the
	 * flush job requires both to clear).
	 */
	public function testWindowAndDigestBothActiveRecordsBothReason(): void {
		$schema = $this->schemaWithRule(['digest' => ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Europe/Amsterdam']]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->windowService->method('getForUser')->willReturn(
			['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']
		);
		$this->windowService->method('isInsideWindow')->willReturn(true);
		$this->digestEvaluator->method('isValidDigestSpec')->willReturn(true);

		$this->queuedMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static function (QueuedNotification $entity): bool {
				return $entity->getReason() === QueuedNotification::REASON_BOTH;
			}));

		$this->makeDispatcher()->dispatch($this->object($schema), 'created');
	}

	/**
	 * Broadcast channels (webhook) fire once per dispatch regardless of
	 * the recipient's quiet-hours window — the gate only applies to
	 * per-recipient channels.
	 */
	public function testBroadcastWebhookChannelUnaffectedByQuietHours(): void {
		$spec = [
			'trigger' => ['type' => 'created'],
			'channels' => ['nc-notification', 'webhook'],
			'recipients' => [['kind' => 'users', 'users' => ['medewerker-1']]],
			'subject' => 'demo',
			'webhook' => ['url' => 'https://example.com/hook'],
		];
		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('meldingen');
		$schema->setConfiguration(['x-openregister-notifications' => ['rule-x' => $spec]]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->windowService->method('getForUser')->willReturn(
			['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']
		);
		$this->windowService->method('isInsideWindow')->willReturn(true);

		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->method('request')->willReturn($this->createMock(\OCP\Http\Client\IResponse::class));
		$this->httpClient->method('newClient')->willReturn($client);

		// nc-notification is queued, but webhook (broadcast) still fires.
		$this->notificationManager->expects($this->never())->method('createNotification');
		$client->expects($this->once())->method('request');

		$this->makeDispatcher()->dispatch($this->object($schema), 'created');
	}
}
