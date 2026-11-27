<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The deadline warning, through the real TaskService over mocked tables: a
 * fired preBreach rung of a task's due timer records `due-soon` once per
 * deadline value and announces it as that action; a terminal task, a task
 * without a deadline, any other rung or timer, and a second fire for the same
 * deadline record nothing; a moved deadline is warned again.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskAuditEntry;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\TimerFiring;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskCommit;
use OCA\OpenRegister\Service\Task\TaskDueSoonWarning;
use OCA\OpenRegister\Service\Task\TaskIntake;
use OCA\OpenRegister\Service\Task\TaskLifecycleServices;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTables;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TaskDueSoonWarningTest extends TestCase {
	/**
	 * Friday 12 March 2027, 17:00 Amsterdam.
	 */
	private const DEADLINE = '2027-03-12 17:00:00';

	/** @var TaskMapper&MockObject */
	private TaskMapper&MockObject $tasks;

	/** @var TaskAuditMapper&MockObject */
	private TaskAuditMapper&MockObject $audits;

	/** @var IEventDispatcher&MockObject */
	private IEventDispatcher&MockObject $events;

	/** @var Task */
	private Task $task;

	/** @var array<int, TaskAudit> */
	private array $trail = [];

	/** @var array<int, TaskTransitionedEvent> */
	private array $announced = [];

	protected function setUp(): void {
		parent::setUp();
		$this->trail = [];
		$this->announced = [];
		$this->task = $this->openTask();

		$this->tasks = $this->createMock(TaskMapper::class);
		$this->tasks->method('findByUuid')->willReturnCallback(
			function (string $uuid): Task {
				if ($uuid !== 't-1') {
					throw new DoesNotExistException('no task');
				}

				return $this->task;
			}
		);
		$this->tasks->method('updateIfOpen')->willReturnCallback(fn (Task $task): bool => $task->isInTerminalState() === false);

		$this->audits = $this->createMock(TaskAuditMapper::class);
		$this->audits->method('insert')->willReturnCallback(
			function (TaskAudit $entry): TaskAudit {
				$this->trail[] = $entry;

				return $entry;
			}
		);
		$this->audits->method('findForTask')->willReturnCallback(fn (): array => $this->trail);

		$this->events = $this->createMock(IEventDispatcher::class);
		$this->events->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				if ($event instanceof TaskTransitionedEvent) {
					$this->announced[] = $event;
				}
			}
		);
	}

	private function openTask(): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('fatima');
		$task->setLastAction('claim');
		$task->setDueAt(self::deadline(at: self::DEADLINE));

		return $task;
	}

	private static function deadline(string $at): DateTime {
		return new DateTime($at, new DateTimeZone('Europe/Amsterdam'));
	}

	private function warning(): TaskDueSoonWarning {
		$service = new TaskService(
			authorization: $this->createMock(TaskAuthorizationService::class),
			services: new TaskLifecycleServices(
				tables: new TaskTables(
					tasks: $this->tasks,
					candidates: $this->createMock(TaskCandidateMapper::class),
					relations: $this->createMock(TaskRelationMapper::class),
					audits: $this->audits
				),
				commit: new TaskCommit(db: $this->createMock(IDBConnection::class), logger: new NullLogger(), dispatcher: $this->events),
				intake: new TaskIntake(builder: new TaskBuilder()),
				resolver: $this->createMock(TaskPerformerResolver::class)
			)
		);

		return new TaskDueSoonWarning(tasks: $service);
	}

	private function service(): TaskService {
		return (new \ReflectionProperty(TaskDueSoonWarning::class, 'tasks'))->getValue($this->warning());
	}

	private static function dueTimer(string $deadline = self::DEADLINE, string $uuid = 'timer-1'): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid($uuid);
		$timer->setSubjectType('task');
		$timer->setSubjectUuid('t-1');
		$timer->setPurpose(FlowTimer::PURPOSE_DUE);
		$timer->setFireAt(self::deadline(at: $deadline));

		return $timer;
	}

	private static function fired(
		FlowTimer $timer,
		?string $trigger = EscalationLadderService::TRIGGER_PRE_BREACH,
		string $key = 'preBreach:1:businessDays'
	): FlowTimerFiredEvent {
		return new FlowTimerFiredEvent(
			timer: $timer,
			firing: new TimerFiring(kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'escalation:' . $key, rungKey: $key, rungTrigger: $trigger)
		);
	}

	/** @return array<int, TaskAudit> */
	private function dueSoonEntries(): array {
		return array_values(array_filter($this->trail, static fn (TaskAudit $entry): bool => $entry->getAction() === 'due-soon'));
	}

	public function testAPreBreachRungRecordsDueSoonAndAnnouncesItAsThatAction(): void {
		$this->assertTrue($this->warning()->onRungFired(event: self::fired(timer: self::dueTimer())));

		$entries = $this->dueSoonEntries();
		$this->assertCount(1, $entries);
		$this->assertSame('flow-timer:timer-1', $entries[0]->getActor());
		$this->assertStringContainsString('2027-03-12T16:00:00+00:00', (string)$entries[0]->getReason());
		$this->assertSame(Task::STATE_ACTIVE, $this->task->getState(), 'a warning moves no state');
		$this->assertSame('due-soon', $this->task->getLastAction());

		$this->assertCount(1, $this->announced);
		$this->assertSame('due-soon', $this->announced[0]->getAction(), 'the taskDueSoon rule addresses this action');
		$this->assertSame('fatima', $this->announced[0]->getTask()->getAssignee());
	}

	public function testASecondEvaluationForTheSameDeadlineRecordsNothingNew(): void {
		$warning = $this->warning();
		$this->assertTrue($warning->onRungFired(event: self::fired(timer: self::dueTimer())));

		// An hour later: a second preBreach rung of the same ladder, and a
		// replay of the first, both for the SAME deadline value.
		$this->assertFalse($warning->onRungFired(event: self::fired(timer: self::dueTimer(), key: 'preBreach:2:hours')));
		$this->assertFalse($warning->onRungFired(event: self::fired(timer: self::dueTimer())));

		$this->assertCount(1, $this->dueSoonEntries());
		$this->assertCount(1, $this->announced);
	}

	public function testTheSameMomentInAnotherZoneIsTheSameDeadline(): void {
		$warning = $this->warning();
		$this->assertTrue($warning->onRungFired(event: self::fired(timer: self::dueTimer())));

		$utc = self::dueTimer();
		$utc->setFireAt(new DateTime('2027-03-12 16:00:00', new DateTimeZone('UTC')));
		$this->assertFalse($warning->onRungFired(event: self::fired(timer: $utc)));
	}

	public function testAMovedDeadlineIsWarnedAgain(): void {
		$warning = $this->warning();
		$this->assertTrue($warning->onRungFired(event: self::fired(timer: self::dueTimer())));

		// The handler moves the deadline a week: the timer is superseded and
		// its successor's preBreach rung fires for the NEW deadline value.
		$this->task->setDueAt(self::deadline(at: '2027-03-19 17:00:00'));
		$this->assertTrue($warning->onRungFired(event: self::fired(timer: self::dueTimer(deadline: '2027-03-19 17:00:00', uuid: 'timer-2'))));

		$entries = $this->dueSoonEntries();
		$this->assertCount(2, $entries);
		$this->assertStringContainsString('2027-03-19T16:00:00+00:00', (string)$entries[1]->getReason());
		$this->assertSame('flow-timer:timer-2', $entries[1]->getActor());
		$this->assertCount(2, $this->announced);
	}

	public function testAFinishedTaskIsNotWarned(): void {
		$this->task->setState(Task::STATE_COMPLETED);
		$this->task->setIsTerminal(true);

		$this->assertFalse($this->warning()->onRungFired(event: self::fired(timer: self::dueTimer())));
		$this->assertSame([], $this->trail);
		$this->assertSame([], $this->announced);
	}

	public function testATaskWithoutADeadlineIsNotWarned(): void {
		$this->task->setDueAt(null);

		$this->assertFalse($this->warning()->onRungFired(event: self::fired(timer: self::dueTimer())));
		$this->assertSame([], $this->trail);
	}

	public function testOnlyAPreBreachRungOfATasksDueTimerWarns(): void {
		$warning = $this->warning();

		$this->assertFalse($warning->onRungFired(event: self::fired(timer: self::dueTimer(), trigger: EscalationLadderService::TRIGGER_BREACHED)));
		$this->assertFalse($warning->onRungFired(event: self::fired(timer: self::dueTimer(), trigger: EscalationLadderService::TRIGGER_POST_BREACH)));
		$this->assertFalse($warning->onRungFired(event: self::fired(timer: self::dueTimer(), trigger: null)));

		$expiry = self::dueTimer();
		$expiry->setPurpose(FlowTimer::PURPOSE_EXPIRY);
		$this->assertFalse($warning->onRungFired(event: self::fired(timer: $expiry)));

		$onAnObject = self::dueTimer();
		$onAnObject->setSubjectType('object');
		$this->assertFalse($warning->onRungFired(event: self::fired(timer: $onAnObject)));

		$noMoment = self::dueTimer();
		$noMoment->setFireAt(null);
		$this->assertFalse($warning->onRungFired(event: self::fired(timer: $noMoment)));

		$expired = new FlowTimerFiredEvent(timer: self::dueTimer(), firing: new TimerFiring(kind: FlowTimerFiredEvent::KIND_EXPIRY, transition: 'expiry'));
		$this->assertFalse($warning->onRungFired(event: $expired));

		$this->assertSame([], $this->trail);
	}

	public function testAGoneTaskIsNotWarned(): void {
		$timer = self::dueTimer();
		$timer->setSubjectUuid('t-gone');

		$this->assertFalse($this->warning()->onRungFired(event: self::fired(timer: $timer)));
		$this->assertSame([], $this->trail);
	}

	public function testATaskClosedBetweenTheReadAndTheWriteIsNotWarned(): void {
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('findByUuid')->willReturn($this->task);
		$tasks->method('updateIfOpen')->willReturn(false);
		$this->tasks = $tasks;

		$this->assertFalse($this->warning()->onRungFired(event: self::fired(timer: self::dueTimer())));
		$this->assertSame([], $this->dueSoonEntries());
		$this->assertSame([], $this->announced);
	}

	public function testRecordingAnActionOnATerminalTaskIsRefusedAndWritesNothing(): void {
		$this->task->setState(Task::STATE_TERMINATED);
		$this->task->setIsTerminal(true);

		try {
			$this->service()->recordAction(entry: new TaskAuditEntry(uuid: 't-1', action: 'due-soon', actor: 'flow-timer:x', reason: 'r'));
			$this->fail('a terminal task was stamped');
		} catch (TaskConflictException $refused) {
			$this->assertStringContainsString("terminal state 'terminated'", $refused->getMessage());
		}

		$this->assertSame('claim', $this->task->getLastAction());
		$this->assertSame([], $this->trail);
		$this->assertSame([], $this->announced);
	}
}
