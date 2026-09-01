<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Clock-controlled overdue: a task becomes notifiable without anything
 * writing to it, the candidate set comes from the inbox's derived-overdue
 * filter, a terminal task is not chased, and a second sweep with the row
 * byte-identical dedupes.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\BackgroundJob\TaskScheduledNotificationJob;
use OCA\OpenRegister\Db\NotificationDedupeState;
use OCA\OpenRegister\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\ScheduledFilterEvaluator;
use OCA\OpenRegister\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Service\Notification\TaskObjectAdapter;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TaskScheduledNotificationJobTest extends TestCase {
	private TaskMapper&MockObject $tasks;

	private NotificationDedupeStateMapper&MockObject $dedupe;

	private AnnotationNotificationDispatcher&MockObject $dispatcher;

	private IAppConfig&MockObject $appConfig;

	/** @var array<string, string> */
	private array $config = [];

	protected function setUp(): void {
		parent::setUp();
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->dedupe = $this->createMock(NotificationDedupeStateMapper::class);
		$this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = [];
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
	}

	private function job(DateTime $now): TaskScheduledNotificationJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now->getTimestamp());
		$temporal = new class($now) extends TaskTemporalProjection {
			public function __construct(private DateTime $clock) {
			}

			public function now(): DateTime {
				return clone $this->clock;
			}
		};
		$inbox = $this->createMock(TaskInboxService::class);
		$inbox->method('enrich')->willReturnCallback(
			static fn (Task $task): array => ['displayTitle' => (string)$task->getTitle(), 'overdue' => true, 'subject' => null]
		);

		return new TaskScheduledNotificationJob(
			$time,
			new TaskNotificationRules(),
			$this->tasks,
			$inbox,
			$temporal,
			new ScheduledFilterEvaluator(),
			$this->dedupe,
			$this->dispatcher,
			$this->appConfig,
			new NullLogger()
		);
	}

	private function run(TaskScheduledNotificationJob $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	private function overdueTask(): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-late');
		$task->setTitle('Late review');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setLastAction('assign');
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('approver');
		$task->setDueAt(new DateTime('2026-09-01T09:00:00+00:00'));

		return $task;
	}

	public function testATaskBecomesNotifiableByTheClockAloneAndTheRowIsNeverWritten(): void {
		$task = $this->overdueTask();
		$before = $task->jsonSerialize();
		$captured = null;
		$this->tasks->method('findInbox')->willReturnCallback(
			static function (TaskInboxCriteria $criteria) use (&$captured, $task): array {
				$captured = $criteria;
				return [$task];
			}
		);
		$this->tasks->expects($this->never())->method('update');
		$this->tasks->expects($this->never())->method('updateIfOpen');
		$this->dedupe->method('findOne')->willReturn(null);
		$this->dedupe->expects($this->once())->method('upsert')
			->with(TaskScheduledNotificationJob::DEDUPE_SCHEMA_ID, 'taskOverdue', 't-late', $this->anything(), $this->anything(), true);
		$this->dispatcher->expects($this->once())->method('dispatchWithSchema')
			->with(
				$this->callback(static fn (TaskObjectAdapter $adapter): bool => $adapter->getUuid() === 't-late'),
				'scheduled',
				['notificationName' => 'taskOverdue'],
				$this->callback(static fn ($schema): bool => array_keys($schema->getConfiguration()['x-openregister-notifications']) === ['taskOverdue'])
			);

		$this->run($this->job(new DateTime('2026-09-02T09:00:00+00:00')));

		// The candidate query is the inbox's derived-overdue filter, over open tasks, as an admin sweep.
		$this->assertTrue($captured->isAdmin);
		$this->assertSame(TaskInboxCriteria::SCOPE_ALL, $captured->scope);
		$this->assertFalse($captured->isTerminal);
		$this->assertSame('2026-09-02T09:00:00+00:00', $captured->overdueAt->format('c'));
		$this->assertSame($before, $task->jsonSerialize(), 'the stored row is byte-identical after the evaluation');
	}

	public function testATaskNotYetDueIsNotNotified(): void {
		$task = $this->overdueTask();
		$this->tasks->method('findInbox')->willReturn([$task]);
		$this->dispatcher->expects($this->never())->method('dispatchWithSchema');

		// The clock is BEFORE the deadline: the declared filter (dueAt before now) does not match.
		$this->run($this->job(new DateTime('2026-08-31T09:00:00+00:00')));
	}

	public function testATerminalTaskIsNotChased(): void {
		$task = $this->overdueTask();
		$task->setState(Task::STATE_COMPLETED);
		$task->setIsTerminal(true);
		$this->tasks->method('findInbox')->willReturn([$task]);
		$this->dispatcher->expects($this->never())->method('dispatchWithSchema');

		$this->run($this->job(new DateTime('2026-09-02T09:00:00+00:00')));
	}

	public function testASecondSweepWithTheSameRowDedupes(): void {
		$task = $this->overdueTask();
		$this->tasks->method('findInbox')->willReturn([$task]);
		$state = new NotificationDedupeState();
		$state->setFingerprint(sha1((string)json_encode(['taskUuid' => 't-late'])));
		$this->dedupe->method('findOne')->willReturn($state);
		$this->dispatcher->expects($this->never())->method('dispatchWithSchema');

		$this->run($this->job(new DateTime('2026-09-02T09:00:00+00:00')));
	}

	public function testTheIntervalIsHonoured(): void {
		$this->config['sched_task:taskOverdue'] = (string)(new DateTime('2026-09-02T08:00:00+00:00'))->getTimestamp();
		$this->tasks->expects($this->never())->method('findInbox');

		// One hour later, a daily rule is not due.
		$this->run($this->job(new DateTime('2026-09-02T09:00:00+00:00')));
	}
}
