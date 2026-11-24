<?php

/**
 * TaskService::delegate() asks the mandate guard about the delegate before
 * anything is written, and records the evidence on the task and its audit.
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
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Exception\TaskMandateRefusedException;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskActorRef;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskDelegation;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskCommit;
use OCA\OpenRegister\Service\Task\TaskIntake;
use OCA\OpenRegister\Service\Task\TaskLifecycleServices;
use OCA\OpenRegister\Service\Task\TaskMandateGuard;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTables;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Delegation with a mandate.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskService
 * @uses \OCA\OpenRegister\Service\Task\TaskMandateGuard
 * @uses \OCA\OpenRegister\Exception\TaskMandateRefusedException
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\TaskAudit
 * @uses \OCA\OpenRegister\Service\Task\TaskAct
 * @uses \OCA\OpenRegister\Service\Task\TaskAssignment
 * @uses \OCA\OpenRegister\Service\Task\TaskBuilder
 * @uses \OCA\OpenRegister\Service\Task\TaskCommit
 * @uses \OCA\OpenRegister\Service\Task\TaskIntake
 * @uses \OCA\OpenRegister\Service\Task\TaskLifecycleServices
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 * @uses \OCA\OpenRegister\Service\Task\TaskTables
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\TaskActorRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\TaskDelegation
 */
class TaskServiceDelegationMandateTest extends TestCase {

	private TaskMapper&MockObject $tasks;

	private TaskAuditMapper&MockObject $audits;

	private TaskMandateGuard&MockObject $guard;

	/**
	 * The audit entries written.
	 *
	 * @var array<int, TaskAudit>
	 */
	private array $entries = [];

	/**
	 * A permit task assigned to Anna, requiring `decide`.
	 *
	 * @var Task
	 */
	private Task $task;

	/**
	 * Fresh mocks per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->audits = $this->createMock(TaskAuditMapper::class);
		$this->guard = $this->createMock(TaskMandateGuard::class);

		$this->task = new Task();
		$this->task->setId(7);
		$this->task->setUuid('t-7');
		$this->task->setState(Task::STATE_ACTIVE);
		$this->task->setIsTerminal(false);
		$this->task->setPerformerType(Task::PERFORMER_USER);
		$this->task->setAssignee('anna');
		$this->task->setRequiredMandate('decide');
		$this->task->setObjectUuid('case-1');

		$this->tasks->method('findByUuid')->willReturn($this->task);
		$this->audits->method('insert')->willReturnCallback(
			function (TaskAudit $entry): TaskAudit {
				$this->entries[] = $entry;

				return $entry;
			}
		);
	}//end setUp()

	/**
	 * The service over the mocks, with the guard.
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
				resolver: $this->createMock(TaskPerformerResolver::class)
			),
			mandates: $this->guard
		);
	}//end service()

	/**
	 * A delegate without the mandate is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testARefusedDelegationChangesNothing(): void {
		$this->guard->expects($this->once())->method('assertHolds')
			->with($this->task, 'bram')
			->willThrowException(new TaskMandateRefusedException(verb: 'decide', uid: 'bram'));
		$this->tasks->expects($this->never())->method('updateIfOpen');

		try {
			$this->service()->delegate(
				ref: new TaskActorRef(uuid: 't-7', actor: 'anna'),
				delegation: new TaskDelegation(delegate: ' bram ', mandate: 'Volmacht 2026'),
			);
			$this->fail('The delegation went through.');
		} catch (TaskMandateRefusedException $refused) {
			$this->assertSame('decide', $refused->getVerb());
		}

		$this->assertSame('anna', $this->task->getAssignee());
		$this->assertNull($this->task->getOnBehalfOf());
		$this->assertSame([], $this->entries);
	}//end testARefusedDelegationChangesNothing()

	/**
	 * A mandated delegate takes over, and the evidence lands on the task and the delegate audit entry.
	 *
	 * @return void
	 */
	public function testAMandatedDelegationRecordsTheEvidence(): void {
		$evidence = ['verb' => 'decide', 'source' => 'schema', 'rule' => ['principal' => 'chris', 'until' => '2026-12-31'], 'until' => '2026-12-31'];
		$this->guard->method('assertHolds')->with($this->task, 'chris')->willReturn($evidence);
		$this->tasks->expects($this->once())->method('updateIfOpen')->willReturn(true);

		$task = $this->service()->delegate(
			ref: new TaskActorRef(uuid: 't-7', actor: 'anna'),
			delegation: new TaskDelegation(delegate: 'chris', mandate: 'Volmacht 2026'),
		);

		$this->assertSame('chris', $task->getAssignee());
		$this->assertSame('anna', $task->getOnBehalfOf());
		$this->assertSame($evidence, $task->getMandateEvidence());
		$this->assertCount(1, $this->entries);
		$this->assertSame('delegate', $this->entries[0]->getAction());
		$this->assertSame($evidence, $this->entries[0]->getMandateEvidence());
		$this->assertSame('Volmacht 2026', $this->entries[0]->getMandate());
	}//end testAMandatedDelegationRecordsTheEvidence()

	/**
	 * An assignment ends the delegation, its evidence included.
	 *
	 * @return void
	 */
	public function testAssignClearsTheEvidence(): void {
		$this->task->setOnBehalfOf('anna');
		$this->task->setAssignee('chris');
		$this->task->setMandate('Volmacht 2026');
		$this->task->setMandateEvidence(['verb' => 'decide']);
		$this->tasks->method('updateIfOpen')->willReturn(true);

		$task = $this->service()->assign(ref: new TaskActorRef(uuid: 't-7', actor: 'rita'), assignee: 'dora');

		$this->assertSame('dora', $task->getAssignee());
		$this->assertNull($task->getMandateEvidence());
		$this->assertNull($this->entries[0]->getMandateEvidence());
	}//end testAssignClearsTheEvidence()
}//end class
