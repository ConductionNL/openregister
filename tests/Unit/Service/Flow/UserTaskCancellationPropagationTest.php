<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Listener\TaskRunTerminalListener;
use OCA\OpenRegister\Listener\UserTaskTerminalListener;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Cancellation propagation from the run onto its tasks, observed twice;
 * and the completion listener's contract with the bridge.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
 */
class UserTaskCancellationPropagationTest extends TestCase {

	private function openTask(string $uuid, string $assignee): Task {
		$task = new Task();
		$task->setId(crc32($uuid));
		$task->setUuid($uuid);
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee($assignee);
		$task->setRunUuid('run-9');
		$task->setNodeId('ask-' . $assignee);

		return $task;
	}//end openTask()

	/**
	 * Stopping a run with two open tasks for two people terminates both with
	 * a reason naming the run, and a SECOND observation of the same
	 * terminality records nothing more.
	 */
	public function testStoppingARunTerminatesItsTasksOnceAcrossTwoObservations(): void {
		$tasks = $this->createMock(TaskMapper::class);
		$first = $this->openTask('t-a', 'alice');
		$second = $this->openTask('t-b', 'bob');

		// First observation finds both open; the second, after they were
		// terminated, finds none. That is the mapper predicate doing the
		// idempotence, and it is what makes a re-fired event harmless.
		$tasks->expects($this->exactly(2))
			->method('findOpenByRunUuid')
			->with('run-9')
			->willReturnOnConsecutiveCalls([$first, $second], []);
		$tasks->method('update')->willReturnArgument(0);
		$tasks->method('updateIfOpen')->willReturn(true);

		$audits = $this->createMock(TaskAuditMapper::class);
		$entries = [];
		$audits->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$entries): TaskAudit {
				$entries[] = $entry;

				return $entry;
			}
		);

		$service = new TaskService(
			tasks: $tasks,
			candidates: $this->createMock(TaskCandidateMapper::class),
			relations: $this->createMock(TaskRelationMapper::class),
			audits: $audits,
			authorization: $this->createMock(TaskAuthorizationService::class),
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger(),
			builder: new TaskBuilder()
		);
		$listener = new TaskRunTerminalListener($service, new NullLogger());

		$listener->handle(new FlowRunTerminalEvent(runUuid: 'run-9', status: 'stopped'));
		$listener->handle(new FlowRunTerminalEvent(runUuid: 'run-9', status: 'stopped'));

		$this->assertSame(Task::STATE_TERMINATED, $first->getState());
		$this->assertSame(Task::STATE_TERMINATED, $second->getState());
		$this->assertTrue($first->getIsTerminal());
		$this->assertCount(2, $entries, 'two tasks, two termination entries, and NOT four');
		foreach ($entries as $entry) {
			$this->assertSame('terminate', $entry->getAction());
			$this->assertSame('flow-run:run-9', $entry->getActor());
			$this->assertStringContainsString('run-9', (string)$entry->getReason());
			$this->assertStringContainsString('stopped', (string)$entry->getReason());
		}
	}//end testStoppingARunTerminatesItsTasksOnceAcrossTwoObservations()

	/**
	 * The completion listener hands a run-bound terminal task to the bridge,
	 * ignores a standalone one, and never lets a bridge failure escape: the
	 * completion is already committed and the caller must be told so.
	 */
	public function testTheCompletionListenerContinuesRunBoundTasksOnly(): void {
		$bridge = $this->createMock(FlowTaskBridge::class);
		$listener = new UserTaskTerminalListener($bridge, new NullLogger());

		$bound = $this->openTask('t-a', 'alice');
		$bound->setState(Task::STATE_COMPLETED);
		$bound->setIsTerminal(true);

		$standalone = $this->openTask('t-s', 'carol');
		$standalone->setRunUuid(null);
		$standalone->setState(Task::STATE_COMPLETED);

		$bridge->expects($this->once())->method('continueRun')->with($bound);

		$listener->handle(new TaskTerminalEvent(task: $bound));
		$listener->handle(new TaskTerminalEvent(task: $standalone));
	}//end testTheCompletionListenerContinuesRunBoundTasksOnly()

	public function testABridgeFailureIsSwallowedByTheListener(): void {
		$bridge = $this->createMock(FlowTaskBridge::class);
		$bridge->method('continueRun')->willThrowException(new RuntimeException('engine away'));
		$listener = new UserTaskTerminalListener($bridge, new NullLogger());

		$listener->handle(new TaskTerminalEvent(task: $this->openTask('t-a', 'alice')));

		$this->addToAssertionCount(1);
	}//end testABridgeFailureIsSwallowedByTheListener()
}//end class
