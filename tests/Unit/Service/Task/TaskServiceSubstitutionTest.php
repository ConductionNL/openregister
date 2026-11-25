<?php

/**
 * TaskService hands a task to the stand-in of an absent assignee on every
 * path that sets an assignee, and gives untouched tasks back when the
 * absence ends.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskActorRef;
use OCA\OpenRegister\Service\Task\TaskAct;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskCommit;
use OCA\OpenRegister\Service\Task\TaskIntake;
use OCA\OpenRegister\Service\Task\TaskLifecycleServices;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskSubstitution;
use OCA\OpenRegister\Service\Task\TaskTables;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Substitution through the task service.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskService
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\TaskAudit
 * @uses \OCA\OpenRegister\Service\Task\TaskAct
 * @uses \OCA\OpenRegister\Service\Task\TaskAssignment
 * @uses \OCA\OpenRegister\Service\Task\TaskBlockerGuard
 * @uses \OCA\OpenRegister\Service\Task\TaskBuilder
 * @uses \OCA\OpenRegister\Service\Task\TaskCommit
 * @uses \OCA\OpenRegister\Service\Task\TaskImport
 * @uses \OCA\OpenRegister\Service\Task\TaskIntake
 * @uses \OCA\OpenRegister\Service\Task\TaskLifecycleServices
 * @uses \OCA\OpenRegister\Service\Task\TaskPriority
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 * @uses \OCA\OpenRegister\Service\Task\TaskTables
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\TaskActorRef
 */
class TaskServiceSubstitutionTest extends TestCase {

	private TaskMapper&MockObject $tasks;

	private TaskAuditMapper&MockObject $audits;

	private TaskSubstitution&MockObject $substitution;

	private TaskPerformerResolver&MockObject $resolver;

	/**
	 * The audit entries written, in order.
	 *
	 * @var array<int, TaskAudit>
	 */
	private array $entries = [];

	/**
	 * Fresh mocks; Anna is away and Chris stands in for her.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->audits = $this->createMock(TaskAuditMapper::class);
		$this->substitution = $this->createMock(TaskSubstitution::class);
		$this->resolver = $this->createMock(TaskPerformerResolver::class);

		$this->tasks->method('insert')->willReturnCallback(
			static function (Task $task): Task {
				$task->setId(41);

				return $task;
			}
		);
		$this->tasks->method('updateIfOpen')->willReturn(true);
		$this->audits->method('insert')->willReturnCallback(
			function (TaskAudit $entry): TaskAudit {
				$this->entries[] = $entry;

				return $entry;
			}
		);
		$this->substitution->method('routeIfAbsent')->willReturnCallback(
			static function (Task $task): ?TaskAct {
				if ($task->getAssignee() !== 'anna') {
					return null;
				}

				$task->setOnBehalfOf('anna');
				$task->setAssignee('chris');

				return new TaskAct(verb: 'substitute', actor: 'absence:42', reason: "'anna' is absent 2026-10-05 to 2026-10-16; 'chris' stands in.");
			}
		);
	}//end setUp()

	/**
	 * The service over the mocks.
	 *
	 * @return TaskService The service.
	 */
	private function service(): TaskService {
		return new TaskService(
			authorization: $this->createMock(TaskAuthorizationService::class),
			services: new TaskLifecycleServices(
				tables: new TaskTables(
					tasks: $this->tasks,
					candidates: $this->createMock(TaskCandidateMapper::class),
					relations: $this->createMock(TaskRelationMapper::class),
					audits: $this->audits
				),
				commit: new TaskCommit(db: $this->createMock(IDBConnection::class), logger: new NullLogger()),
				intake: new TaskIntake(builder: new TaskBuilder()),
				resolver: $this->resolver
			),
			substitution: $this->substitution
		);
	}//end service()

	/**
	 * An open user task.
	 *
	 * @param string|null $assignee   The assignee.
	 * @param string|null $onBehalfOf Who it is handled for.
	 *
	 * @return Task The task.
	 */
	private function openTask(?string $assignee, ?string $onBehalfOf = null): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-7');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee($assignee);
		$task->setOnBehalfOf($onBehalfOf);
		$this->tasks->method('findByUuid')->willReturn($task);

		return $task;
	}//end openTask()

	/**
	 * An audit entry.
	 *
	 * @param string $action The action.
	 * @param string $actor  The actor.
	 *
	 * @return TaskAudit The entry.
	 */
	private function entry(string $action, string $actor): TaskAudit {
		$entry = new TaskAudit();
		$entry->setAction($action);
		$entry->setActor($actor);
		$entry->setAuthorized(true);

		return $entry;
	}//end entry()

	/**
	 * A task a flow creates for Anna reaches Chris, audited after the creation.
	 *
	 * @return void
	 */
	public function testANewTaskReachesTheStandIn(): void {
		$task = $this->service()->import(data: ['title' => 'Decide', 'assignee' => 'anna', 'performerType' => 'user'], actor: 'flow');

		$this->assertSame('chris', $task->getAssignee());
		$this->assertSame('anna', $task->getOnBehalfOf());
		$this->assertSame(['create', 'substitute'], array_map(static fn (TaskAudit $e): string => (string)$e->getAction(), $this->entries));
		$this->assertSame('absence:42', $this->entries[1]->getActor());
		$this->assertStringContainsString('2026-10-05 to 2026-10-16', (string)$this->entries[1]->getReason());
	}//end testANewTaskReachesTheStandIn()

	/**
	 * Assigning to Anna lands with Chris, audited after the assignment.
	 *
	 * @return void
	 */
	public function testAssignReachesTheStandIn(): void {
		$this->openTask(assignee: 'bram');

		$task = $this->service()->assign(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), assignee: 'anna');

		$this->assertSame('chris', $task->getAssignee());
		$this->assertSame('anna', $task->getOnBehalfOf());
		$this->assertSame(['assign', 'substitute'], array_map(static fn (TaskAudit $e): string => (string)$e->getAction(), $this->entries));
	}//end testAssignReachesTheStandIn()

	/**
	 * A present assignee is left alone and only the assignment is audited.
	 *
	 * @return void
	 */
	public function testAPresentAssigneeIsNotSubstituted(): void {
		$this->openTask(assignee: 'bram');

		$task = $this->service()->reassign(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), assignee: 'dora');

		$this->assertSame('dora', $task->getAssignee());
		$this->assertNull($task->getOnBehalfOf());
		$this->assertCount(1, $this->entries);
	}//end testAPresentAssigneeIsNotSubstituted()

	/**
	 * Routing that picks Anna gives the task to Chris.
	 *
	 * @return void
	 */
	public function testOfferRoutingReachesTheStandIn(): void {
		$this->openTask(assignee: null);
		$this->resolver->method('resolveAssignee')->willReturn('anna');

		$task = $this->service()->offer(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), pool: ['routingStrategy' => 'single-role']);

		$this->assertSame('chris', $task->getAssignee());
		$this->assertSame(['offer', 'substitute'], array_map(static fn (TaskAudit $e): string => (string)$e->getAction(), $this->entries));
	}//end testOfferRoutingReachesTheStandIn()

	/**
	 * An absence that starts later moves an open task; a delegated one stays.
	 *
	 * @return void
	 */
	public function testSubstituteForAbsenceMovesOpenTasksOnly(): void {
		$this->openTask(assignee: 'anna');

		$task = $this->service()->substituteForAbsence(uuid: 't-7');

		$this->assertSame('chris', $task?->getAssignee());
		$this->assertSame(['substitute'], array_map(static fn (TaskAudit $e): string => (string)$e->getAction(), $this->entries));
	}//end testSubstituteForAbsenceMovesOpenTasksOnly()

	/**
	 * A task already handled for somebody is not handed on.
	 *
	 * @return void
	 */
	public function testADelegatedTaskIsNotSubstituted(): void {
		$this->openTask(assignee: 'anna', onBehalfOf: 'ed');

		$this->assertNull($this->service()->substituteForAbsence(uuid: 't-7'));
		$this->assertSame([], $this->entries);
	}//end testADelegatedTaskIsNotSubstituted()

	/**
	 * A refused substitution is audited and the task stays.
	 *
	 * @return void
	 */
	public function testARefusedSubstitutionIsAuditedOnly(): void {
		$this->substitution = $this->createMock(TaskSubstitution::class);
		$this->substitution->method('routeIfAbsent')->willReturn(
			new TaskAct(verb: 'substitute-refused', actor: 'absence:42', reason: "Stand-in 'chris' does not hold the mandate 'decide'.")
		);
		$this->openTask(assignee: 'anna');
		$this->tasks->expects($this->never())->method('updateIfOpen');

		$task = $this->service()->substituteForAbsence(uuid: 't-7');

		$this->assertSame('anna', $task?->getAssignee());
		$this->assertSame('substitute-refused', $this->entries[0]->getAction());
	}//end testARefusedSubstitutionIsAuditedOnly()

	/**
	 * When the absence ends, an untouched task goes back to Anna.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-untouched-tasks-return-when-the-absence-ends
	 */
	public function testAnUntouchedTaskReturns(): void {
		$this->openTask(assignee: 'chris', onBehalfOf: 'anna');
		$this->audits->method('findForTask')->willReturn([
			$this->entry(action: 'create', actor: 'flow'),
			$this->entry(action: 'substitute', actor: 'absence:42'),
			$this->entry(action: 'substitute-refused', actor: 'absence:43'),
		]);

		$task = $this->service()->returnFromStandIn(uuid: 't-7', absenceId: '42');

		$this->assertSame('anna', $task?->getAssignee());
		$this->assertNull($task?->getOnBehalfOf());
		$this->assertSame('substitute-return', $this->entries[0]->getAction());
		$this->assertSame('absence:42', $this->entries[0]->getActor());
	}//end testAnUntouchedTaskReturns()

	/**
	 * A task the stand-in ticked stays with them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-untouched-tasks-return-when-the-absence-ends
	 */
	public function testATouchedTaskStays(): void {
		$this->openTask(assignee: 'chris', onBehalfOf: 'anna');
		$this->audits->method('findForTask')->willReturn([
			$this->entry(action: 'substitute', actor: 'absence:42'),
			$this->entry(action: 'checklist', actor: 'chris'),
		]);

		$this->assertNull($this->service()->returnFromStandIn(uuid: 't-7', absenceId: '42'));
		$this->assertSame([], $this->entries);
	}//end testATouchedTaskStays()

	/**
	 * A task substituted for another absence, or reassigned since, stays.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-untouched-tasks-return-when-the-absence-ends
	 */
	public function testATaskMovedSinceStays(): void {
		$this->openTask(assignee: 'chris', onBehalfOf: 'anna');
		$this->audits->method('findForTask')->willReturn([
			$this->entry(action: 'substitute', actor: 'absence:42'),
			$this->entry(action: 'reassign', actor: 'rita'),
		]);

		$this->assertNull($this->service()->returnFromStandIn(uuid: 't-7', absenceId: '42'));
		$this->assertNull($this->service()->returnFromStandIn(uuid: 't-7', absenceId: '99'));
	}//end testATaskMovedSinceStays()
}//end class
