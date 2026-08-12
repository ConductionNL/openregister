<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\NotificationQueueFlushJob;
use OCA\OpenRegister\Db\QueuedNotification;
use OCA\OpenRegister\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Service\Notification\NotificationDeliveryWindowService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers `NotificationQueueFlushJob`'s live re-evaluation semantics:
 *  - a quiet-hours-queued row flushes once the window clears, not before;
 *  - multiple queued rows sharing (schema, rule, recipient) are grouped
 *    into one `dispatchQueued()` call and their rows are deleted together;
 *  - window overlap: a row queued for both quiet-hours AND a not-yet-due
 *    digest schedule waits for the LATER of the two to clear;
 *  - live re-evaluation across a DST transition uses the CURRENT offset,
 *    not a stale one computed at enqueue time.
 */
class NotificationQueueFlushJobTest extends TestCase {
	private QueuedNotificationMapper&MockObject $queuedMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private AnnotationNotificationDispatcher&MockObject $dispatcher;
	private LoggerInterface&MockObject $logger;
	private ITimeFactory&MockObject $time;
	private NotificationDeliveryWindowService $windowService;
	private DigestScheduleEvaluator $digestEvaluator;

	protected function setUp(): void {
		parent::setUp();
		$this->queuedMapper = $this->createMock(QueuedNotificationMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->time = $this->createMock(ITimeFactory::class);

		$config = $this->createMock(IConfig::class);
		$this->windowService = new NotificationDeliveryWindowService($config, null);
		$config->method('getUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, $default = '') {
				return $this->storedWindows[$uid] ?? $default;
			}
		);
		$this->digestEvaluator = new DigestScheduleEvaluator($this->windowService);
	}

	/**
	 * @var array<string, string>
	 */
	private array $storedWindows = [];

	private function withWindow(string $uid, array $window): void {
		$this->storedWindows[$uid] = json_encode($window);
	}

	private function row(int $schemaId, string $ruleKey, string $recipient, string $reason, DateTime $createdAt, ?int $id = null): QueuedNotification {
		$row = new QueuedNotification();
		if ($id !== null) {
			$row->setId($id);
		}

		$row->setSchemaId($schemaId);
		$row->setRuleKey($ruleKey);
		$row->setRecipient($recipient);
		$row->setReason($reason);
		$row->setPayload(json_encode(['subject' => 'demo', 'message' => '', 'channels' => ['nc-notification'], 'action' => 'created']));
		$row->setDueAtHint($createdAt);
		$row->setCreatedAt($createdAt);
		return $row;
	}

	private function schemaWithDigest(int $id, string $ruleKey, array $digest): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('grade-entry');
		$schema->setConfiguration([
			'x-openregister-notifications' => [
				$ruleKey => [
					'trigger' => ['type' => 'created'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'users', 'users' => ['ouder-1']]],
					'subject' => 'demo',
					'digest' => $digest,
				],
			],
		]);
		return $schema;
	}

	private function runJob(): void {
		$job = new NotificationQueueFlushJob(
			$this->time,
			$this->queuedMapper,
			$this->schemaMapper,
			$this->dispatcher,
			$this->windowService,
			$this->digestEvaluator,
			$this->logger
		);

		$reflection = new \ReflectionClass($job);
		$method = $reflection->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	public function testQuietHoursRowNotFlushedWhileWindowStillActive(): void {
		$this->withWindow('medewerker-1', ['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']);
		$this->time->method('getDateTime')->willReturn(new DateTime('2026-07-12T22:00:00+02:00'));

		$row = $this->row(9, 'rule-x', 'medewerker-1', QueuedNotification::REASON_QUIET_HOURS, new DateTime('2026-07-12T21:00:00+02:00'), 1);
		$this->queuedMapper->method('findAll')->willReturn([$row]);

		$this->dispatcher->expects($this->never())->method('dispatchQueued');
		$this->queuedMapper->expects($this->never())->method('deleteById');

		$this->runJob();
	}

	public function testQuietHoursRowFlushesOnceWindowClears(): void {
		$this->withWindow('medewerker-1', ['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']);
		// 08:30 CEST — past the window end.
		$this->time->method('getDateTime')->willReturn(new DateTime('2026-07-13T08:30:00+02:00'));

		$row = $this->row(9, 'rule-x', 'medewerker-1', QueuedNotification::REASON_QUIET_HOURS, new DateTime('2026-07-12T21:00:00+02:00'), 1);
		$this->queuedMapper->method('findAll')->willReturn([$row]);
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('no schema needed for pure quiet-hours row'));

		$this->dispatcher->expects($this->once())->method('dispatchQueued')->with([$row]);
		$this->queuedMapper->expects($this->once())->method('deleteById')->with(1);

		$this->runJob();
	}

	public function testGroupsMultipleRowsIntoOneDispatchQueuedCall(): void {
		// No window configured for this recipient — the group is due
		// immediately once found (both rows share (schema, rule, recipient)).
		$this->time->method('getDateTime')->willReturn(new DateTime('2026-07-13T08:30:00+02:00'));

		$rowA = $this->row(9, 'rule-x', 'ouder-1', QueuedNotification::REASON_QUIET_HOURS, new DateTime('2026-07-12T14:00:00+02:00'), 1);
		$rowB = $this->row(9, 'rule-x', 'ouder-1', QueuedNotification::REASON_QUIET_HOURS, new DateTime('2026-07-12T16:30:00+02:00'), 2);
		$this->queuedMapper->method('findAll')->willReturn([$rowA, $rowB]);
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('no digest on this rule'));

		$this->dispatcher->expects($this->once())->method('dispatchQueued')->with([$rowA, $rowB]);
		$this->queuedMapper->expects($this->exactly(2))->method('deleteById');

		$this->runJob();
	}

	/**
	 * Window overlap: quiet-hours until 08:00, digest scheduled at 07:00 —
	 * the row must NOT flush at 07:00 (still inside quiet hours) and MUST
	 * flush once quiet hours clears at/after 08:00.
	 */
	public function testWindowOverlapWaitsForLaterOfQuietHoursAndDigest(): void {
		$this->withWindow('ouder-1', ['enabled' => true, 'start' => '22:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']);

		$digestSchema = $this->schemaWithDigest(9, 'rule-x', ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Europe/Amsterdam']);
		$this->schemaMapper->method('find')->willReturn($digestSchema);

		$row = $this->row(
			9,
			'rule-x',
			'ouder-1',
			QueuedNotification::REASON_BOTH,
			new DateTime('2026-07-12T23:00:00+02:00'),
			1
		);
		$this->queuedMapper->method('findAll')->willReturn([$row]);

		// 07:30 CEST — digest is due, but quiet hours are still active.
		$this->time->method('getDateTime')->willReturn(new DateTime('2026-07-13T07:30:00+02:00'));
		$this->dispatcher->expects($this->never())->method('dispatchQueued');
		$this->runJob();
	}

	public function testWindowOverlapFlushesOnceQuietHoursClearsAfterDigestTime(): void {
		$this->withWindow('ouder-1', ['enabled' => true, 'start' => '22:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']);

		$digestSchema = $this->schemaWithDigest(9, 'rule-x', ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Europe/Amsterdam']);
		$this->schemaMapper->method('find')->willReturn($digestSchema);

		$row = $this->row(
			9,
			'rule-x',
			'ouder-1',
			QueuedNotification::REASON_BOTH,
			new DateTime('2026-07-12T23:00:00+02:00'),
			1
		);
		$this->queuedMapper->method('findAll')->willReturn([$row]);

		// 08:15 CEST — both quiet hours cleared AND digest time reached.
		$this->time->method('getDateTime')->willReturn(new DateTime('2026-07-13T08:15:00+02:00'));

		$this->dispatcher->expects($this->once())->method('dispatchQueued')->with([$row]);
		$this->queuedMapper->expects($this->once())->method('deleteById')->with(1);

		$this->runJob();
	}

	/**
	 * A row created AFTER the last digest occurrence must NOT flush
	 * alongside an older group member sharing the window-cleared state —
	 * each row's OWN digest due-ness is evaluated independently.
	 */
	public function testRowCreatedAfterDigestOccurrenceWaitsForNextOne(): void {
		$digestSchema = $this->schemaWithDigest(9, 'rule-x', ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Europe/Amsterdam']);
		$this->schemaMapper->method('find')->willReturn($digestSchema);

		// Queued at 07:30 CEST — just AFTER today's 07:00 occurrence.
		$lateRow = $this->row(9, 'rule-x', 'ouder-1', QueuedNotification::REASON_DIGEST_SCHEDULE, new DateTime('2026-07-12T07:30:00+02:00'), 1);
		$this->queuedMapper->method('findAll')->willReturn([$lateRow]);

		// Same day, later — not due yet (waits for tomorrow's 07:00).
		$this->time->method('getDateTime')->willReturn(new DateTime('2026-07-12T12:00:00+02:00'));

		$this->dispatcher->expects($this->never())->method('dispatchQueued');
		$this->runJob();
	}

	/**
	 * Live re-evaluation across a DST transition: the job must use the
	 * CURRENT offset at flush time, never a `due_at_hint` computed before
	 * the transition.
	 */
	public function testFlushDecisionUsesLiveOffsetAcrossDstTransition(): void {
		$this->withWindow('medewerker-1', ['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam']);

		// Row queued BEFORE the 2026-03-29 spring-forward, with a
		// (deliberately stale) due_at_hint that predates the transition.
		$row = $this->row(9, 'rule-x', 'medewerker-1', QueuedNotification::REASON_QUIET_HOURS, new DateTime('2026-03-28T22:00:00+01:00'), 1);
		$this->queuedMapper->method('findAll')->willReturn([$row]);
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('no digest'));

		// AFTER the spring-forward, local wall-clock is 08:30 CEST — past
		// the window end, so the row is due. A naive pre-transition UTC+1
		// assumption would compute 07:30 (still inside the window) and
		// wrongly withhold the flush.
		$this->time->method('getDateTime')->willReturn(new DateTime('2026-03-29T06:30:00+00:00'));

		$this->dispatcher->expects($this->once())->method('dispatchQueued')->with([$row]);

		$this->runJob();
	}
}
