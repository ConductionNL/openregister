<?php

/**
 * Creating a task that waits on another: what the create path refuses.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The blocked state is derived, so a caller can declare a blocker but never the state.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskService
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Service\Task\TaskBuilder
 * @uses \OCA\OpenRegister\Service\Task\TaskPriority
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 * @uses \OCA\OpenRegister\Db\TaskAudit
 * @covers \OCA\OpenRegister\Service\Task\TaskBlockerGuard
 * @uses \OCA\OpenRegister\Service\Task\TaskSubjectAccessGuard
 */
class TaskBlockedByCreateTest extends TestCase {

	/**
	 * The task mapper.
	 *
	 * @var TaskMapper&MockObject
	 */
	private TaskMapper&MockObject $tasks;

	/**
	 * Stored tasks by uuid, as findByUuid answers them.
	 *
	 * @var array<string, Task>
	 */
	private array $stored = [];

	/**
	 * Fresh mapper per test, answering from $stored.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->stored = [];
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->tasks->method('findByUuid')->willReturnCallback(
			function (string $uuid): Task {
				if (array_key_exists($uuid, $this->stored) === false) {
					throw new DoesNotExistException('none');
				}

				return $this->stored[$uuid];
			}
		);
		$this->tasks->method('insert')->willReturnCallback(
			static function (Task $task): Task {
				$task->setId(41);

				return $task;
			}
		);
	}//end setUp()

	/**
	 * The service under test, with real builder and mocked stores.
	 *
	 * @param bool                   $administrator Whether the actor is an administrator.
	 * @param array<int, string>|null $readable     The uuids of the tasks an ordinary actor may read.
	 *
	 * @return TaskService The service.
	 */
	private function service(bool $administrator = true, ?array $readable = null): TaskService {
		$audits = $this->createMock(TaskAuditMapper::class);
		$audits->method('insert')->willReturnArgument(0);
		$authorization = $this->createMock(TaskAuthorizationService::class);
		$authorization->method('isAdministrator')->willReturn($administrator);
		$authorization->method('mayRead')->willReturnCallback(
			static fn (Task $task, ?string $uid): bool => in_array($task->getUuid(), ($readable ?? []), true)
		);

		return new TaskService(
			tasks: $this->tasks,
			candidates: $this->createMock(TaskCandidateMapper::class),
			relations: $this->createMock(TaskRelationMapper::class),
			audits: $audits,
			authorization: $authorization,
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger(),
			builder: new TaskBuilder()
		);
	}//end service()

	/**
	 * Store a task.
	 *
	 * @param string $uuid The uuid.
	 * @param string|null $blockedBy Its blocker.
	 *
	 * @return void
	 */
	private function store(string $uuid, ?string $blockedBy = null): void {
		$task = new Task();
		$task->setUuid($uuid);
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setBlockedBy($blockedBy);
		$this->stored[$uuid] = $task;
	}//end store()

	/**
	 * A task blocked by an existing open task is created with the declaration.
	 *
	 * @return void
	 */
	public function testATaskMayNameTheTaskItWaitsOn(): void {
		$this->store(uuid: 'task-visit');

		$created = $this->service()->create(data: ['title' => 'Write the advice', 'blockedBy' => 'task-visit'], actor: 'anna');

		$this->assertSame('task-visit', $created->getBlockedBy());
	}//end testATaskMayNameTheTaskItWaitsOn()

	/**
	 * Writing `blocked` by hand is refused, and nothing is inserted.
	 *
	 * @return void
	 */
	public function testWritingBlockedByHandIsRefused(): void {
		$this->tasks->expects($this->never())->method('insert');
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage('blockedBy');

		$this->service()->create(data: ['title' => 'Write the advice', 'blocked' => true], actor: 'anna');
	}//end testWritingBlockedByHandIsRefused()

	/**
	 * The legacy state `blocked` is refused on the create path too.
	 *
	 * @return void
	 */
	public function testTheStateBlockedIsRefusedOnCreate(): void {
		$this->tasks->expects($this->never())->method('insert');
		$this->expectException(TaskValidationException::class);

		$this->service()->create(data: ['title' => 'Write the advice', 'state' => 'blocked'], actor: 'anna');
	}//end testTheStateBlockedIsRefusedOnCreate()

	/**
	 * A task cannot wait on itself.
	 *
	 * @return void
	 */
	public function testATaskCannotWaitOnItself(): void {
		$this->tasks->expects($this->never())->method('insert');
		$this->expectException(TaskValidationException::class);

		$this->service()->create(data: ['uuid' => 'task-a', 'title' => 'Loop', 'blockedBy' => 'task-a'], actor: 'anna');
	}//end testATaskCannotWaitOnItself()

	/**
	 * A blocker no task carries is refused, naming it.
	 *
	 * @return void
	 */
	public function testAnUnknownBlockerIsRefused(): void {
		$this->tasks->expects($this->never())->method('insert');
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage('task-ghost');

		$this->service()->create(data: ['title' => 'Write the advice', 'blockedBy' => 'task-ghost'], actor: 'anna');
	}//end testAnUnknownBlockerIsRefused()

	/**
	 * A chain that leads back to the new task is a loop, and is refused.
	 *
	 * @return void
	 */
	public function testALoopOfBlockersIsRefused(): void {
		// B waits on A; creating A blocked by B closes the loop.
		$this->store(uuid: 'task-b', blockedBy: 'task-a');
		$this->tasks->expects($this->never())->method('insert');
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage('loop');

		$this->service()->create(data: ['uuid' => 'task-a', 'title' => 'A', 'blockedBy' => 'task-b'], actor: 'anna');
	}//end testALoopOfBlockersIsRefused()

	/**
	 * An ordinary caller may not name a blocker they cannot read: it answers like a task that does not exist.
	 *
	 * @return void
	 */
	public function testABlockerTheCallerMayNotReadAnswersLikeOneThatDoesNotExist(): void {
		$this->store(uuid: 'task-elsewhere');
		$this->tasks->expects($this->never())->method('insert');

		$messages = [];
		foreach (['task-elsewhere', 'task-ghost'] as $blocker) {
			try {
				$this->service(administrator: false, readable: [])->create(data: ['title' => 'Write the advice', 'blockedBy' => $blocker], actor: 'anna');
				$this->fail('Expected the blocker to be refused');
			} catch (TaskValidationException $e) {
				$messages[$blocker] = str_replace($blocker, '<uuid>', $e->getMessage());
			}
		}

		$this->assertSame($messages['task-ghost'], $messages['task-elsewhere'], 'the two refusals must read the same');
	}//end testABlockerTheCallerMayNotReadAnswersLikeOneThatDoesNotExist()

	/**
	 * A blocker the ordinary caller may read is accepted as before.
	 *
	 * @return void
	 */
	public function testABlockerTheCallerMayReadIsAccepted(): void {
		$this->store(uuid: 'task-visit');

		$created = $this->service(administrator: false, readable: ['task-visit'])
			->create(data: ['title' => 'Write the advice', 'blockedBy' => 'task-visit'], actor: 'anna');

		$this->assertSame('task-visit', $created->getBlockedBy());
	}//end testABlockerTheCallerMayReadIsAccepted()

	/**
	 * The trusted import path names blockers of its own making and is not held to a read rule.
	 *
	 * @return void
	 */
	public function testTheTrustedImportPathIsNotHeldToAReadRule(): void {
		$this->store(uuid: 'task-visit');

		$created = $this->service(administrator: false, readable: [])
			->import(data: ['title' => 'Write the advice', 'blockedBy' => 'task-visit'], actor: 'anna');

		$this->assertSame('task-visit', $created->getBlockedBy());
	}//end testTheTrustedImportPathIsNotHeldToAReadRule()
}//end class
