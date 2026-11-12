<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use DateTime;
use OCA\OpenRegister\Notifications\BackgroundJob\NotificationQueueFlushJob;
use OCA\OpenRegister\Notifications\BackgroundJob\QueueFlushDelivery;
use OCA\OpenRegister\Notifications\BackgroundJob\QueueFlushTiming;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Db\QueuedNotification;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ChannelSet;
use OCA\OpenRegister\Notifications\Service\Notification\DispatchGates;
use OCA\OpenRegister\Notifications\Service\Notification\AppIntegrations;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryLedger;
use OCA\OpenRegister\Notifications\Service\Notification\RuleAudience;
use OCA\OpenRegister\Notifications\Service\Notification\MessageRendering;
use OCA\OpenRegister\Notifications\Service\Notification\DispatchThrottle;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryHold;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeliveryWindowService;
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
 * Full-path integration coverage (tasks.md 7.5): a rule with a `digest`
 * schedule + a recipient with an active quiet-hours window → the event is
 * QUEUED (never dropped, never immediately delivered) → once BOTH the
 * window has cleared and the digest time has been reached,
 * `NotificationQueueFlushJob` flushes it through the REAL
 * `AnnotationNotificationDispatcher::dispatchQueued()` path → exactly one
 * notification is delivered and exactly one notification-history row
 * transitions to `dispatched`.
 *
 * Uses a real `AnnotationNotificationDispatcher` and a real
 * `NotificationQueueFlushJob` wired together through a shared in-memory
 * fake of `QueuedNotificationMapper`, so the test exercises the actual
 * queue → flush contract rather than asserting on mock call shapes only.
 *
 * @spec openspec/changes/notification-delivery-windows/specs/notificatie-engine/spec.md
 */
class NotificationDeliveryWindowIntegrationTest extends TestCase {
	/**
	 * @var array<int, QueuedNotification>
	 */
	private array $store = [];

	private int $nextId = 1;

	private QueuedNotificationMapper&MockObject $queuedMapper;
	private NotificationHistoryMapper&MockObject $historyMapper;
	private ITimeFactory&MockObject $time;
	private array $recordedHistory = [];

	protected function setUp(): void {
		parent::setUp();

		$this->queuedMapper = $this->createMock(QueuedNotificationMapper::class);
		$this->queuedMapper->method('insert')->willReturnCallback(function (QueuedNotification $entity) {
			$entity->setId($this->nextId);
			$this->store[$this->nextId] = $entity;
			$this->nextId++;
			return $entity;
		});
		$this->queuedMapper->method('findAll')->willReturnCallback(fn () => array_values($this->store));
		$this->queuedMapper->method('deleteById')->willReturnCallback(function (int $id): void {
			unset($this->store[$id]);
		});

		$this->historyMapper = $this->createMock(NotificationHistoryMapper::class);
		$this->historyMapper->method('record')->willReturnCallback(
			function (\OCA\OpenRegister\Notifications\Db\HistoryEntry $entry) {
				$this->recordedHistory[] = $entry->delivery->status;
				return new \OCA\OpenRegister\Notifications\Db\NotificationHistory();
			}
		);

		$this->time = $this->createMock(ITimeFactory::class);
	}

	public function testDigestPlusQuietHoursQueuesThenFlushesExactlyOnce(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('grade-entry');
		$schema->setConfiguration([
			'x-openregister-notifications' => [
				'grade-published' => [
					'trigger' => ['type' => 'created'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'users', 'users' => ['ouder-1']]],
					'subject' => 'Grade published',
					'digest' => ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Europe/Amsterdam'],
				],
			],
		]);
		$schemaMapper->method('find')->willReturn($schema);

		$notificationManager = $this->createMock(INotificationManager::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturn(true);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $default ?? 'http://localhost'
		);
		$serverContainer = $this->createMock(IServerContainer::class);
		$serverContainer->method('get')->willReturnCallback(
			fn (string $id): mixed => ($id === IConfig::class) ? $config : null
		);

		$windowConfig = $this->createMock(IConfig::class);
		$storedWindow = json_encode(['enabled' => true, 'start' => '22:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']);
		$windowConfig->method('getUserValue')->willReturn($storedWindow);
		$windowService = new NotificationDeliveryWindowService($windowConfig, null);
		$digestEvaluator = new DigestScheduleEvaluator($windowService);

		// 3 events fire at 14:00, 16:30, 21:00 the previous day (all before
		// quiet hours + digest time).
		$this->time->method('getDateTime')->willReturnOnConsecutiveCalls(
			new DateTime('2026-07-11T14:00:00+02:00'),
			new DateTime('2026-07-11T16:30:00+02:00'),
			new DateTime('2026-07-11T21:00:00+02:00')
		);

		$dispatcher = new AnnotationNotificationDispatcher(
			channels: new ChannelSet(
				ncSender: new NcNotificationSender(
					notificationManager: $notificationManager,
					logger: $this->createMock(LoggerInterface::class),
					userManager: $userManager
				),
				emailSender: new EmailSender(
					userManager: $userManager,
					mailer: $this->createMock(IMailer::class),
					logger: $this->createMock(LoggerInterface::class)
				),
				talkSender: new TalkSender(
					httpClient: $this->createMock(IClientService::class),
					logger: $this->createMock(LoggerInterface::class),
					config: $serverContainer->get(IConfig::class)
				),
				activityManager: $this->createMock(IActivityManager::class)
			),
			gates: new DispatchGates(
				throttle: new DispatchThrottle(),
				deliveryHold: new DeliveryHold(windowService: $windowService, digestEvaluator: $digestEvaluator, queue: $this->queuedMapper, timeFactory: $this->time)
			),
			integrations: new AppIntegrations(
				httpClient: $this->createMock(IClientService::class),
				serverContainer: $serverContainer
			),
			ledger: new DeliveryLedger(
				logger: $this->createMock(LoggerInterface::class),
				historyMapper: $this->historyMapper
			),
			audience: new RuleAudience(
				schemaMapper: $schemaMapper,
				recipientResolver: new NotificationRecipientResolver(
					userManager: $userManager,
					groupManager: $this->createMock(IGroupManager::class),
					logger: $this->createMock(LoggerInterface::class),
					serverContainer: $serverContainer
				)
			),
			rendering: new MessageRendering(
				templating: new NotificationTemplating(
					logger: $this->createMock(LoggerInterface::class)
				)
			)
		);

		// No individual notification MUST be delivered before the flush —
		// tracked via a shared counter rather than expects(never()) so the
		// SAME mock can legitimately deliver exactly once later at flush.
		$notifCaptured = 0;
		$notificationManager->method('createNotification')->willReturnCallback(function () use (&$notifCaptured) {
			$notifCaptured++;
			$notif = $this->createMock(INotification::class);
			$notif->method('setApp')->willReturnSelf();
			$notif->method('setUser')->willReturnSelf();
			$notif->method('setDateTime')->willReturnSelf();
			$notif->method('setObject')->willReturnSelf();
			$notif->method('setSubject')->willReturnSelf();
			return $notif;
		});

		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setSchema('grade-entry');
		$object->setRegister('r');
		$object->setObject(['title' => 'Wiskunde']);

		$dispatcher->dispatch($object, 'created');
		$dispatcher->dispatch($object, 'created');
		$dispatcher->dispatch($object, 'created');

		$this->assertCount(3, $this->store, '3 events must be queued, not dropped.');
		$this->assertSame(['queued-digest', 'queued-digest', 'queued-digest'], $this->recordedHistory);
		$this->assertSame(0, $notifCaptured, 'No individual notification may be delivered before the flush.');

		// Flush job ticks AFTER 08:00 Europe/Amsterdam the following
		// morning — both quiet hours (until 08:00) and the digest (at
		// 07:00) have cleared.
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getDateTime')->willReturn(new DateTime('2026-07-12T08:30:00+02:00'));

		// Rebuild the dispatcher's flush-facing clock reference via the
		// job (the dispatcher's OWN clock is only consulted at enqueue
		// time in dispatch(), not at dispatchQueued() flush time).
		$flushJob = new NotificationQueueFlushJob(
			$this->time,
			new QueueFlushTiming($schemaMapper, $windowService, $digestEvaluator),
			new QueueFlushDelivery($this->queuedMapper, $dispatcher, $this->createMock(LoggerInterface::class))
		);

		$reflection = new \ReflectionClass($flushJob);
		$method = $reflection->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($flushJob, null);

		// Exactly ONE grouped notification delivered for the 3 queued events.
		$this->assertSame(1, $notifCaptured);
		$this->assertCount(0, $this->store, 'Flushed rows must be removed from the queue.');
		$this->assertSame('dispatched', end($this->recordedHistory));
	}
}
