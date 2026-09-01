<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Terminality is announced AFTER the transaction commits, once per
 * terminal transition, and a listener failure cannot undo the transition.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
 */
class TaskServiceTerminalEventTest extends TestCase {

	private TaskMapper&MockObject $tasks;

	private IDBConnection&MockObject $db;

	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * The order in which commit and dispatch happened.
	 *
	 * @var array<int, string>
	 */
	private array $sequence = [];

	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->tasks->method('update')->willReturnArgument(0);
		$this->tasks->method('updateIfOpen')->willReturn(true);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('commit')->willReturnCallback(function (): void {
			$this->sequence[] = 'commit';
		});
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
	}//end setUp()

	private function service(): TaskService {
		$audits = $this->createMock(TaskAuditMapper::class);
		$audits->method('insert')->willReturnArgument(0);

		return new TaskService(
			tasks: $this->tasks,
			candidates: $this->createMock(TaskCandidateMapper::class),
			relations: $this->createMock(TaskRelationMapper::class),
			audits: $audits,
			authorization: $this->createMock(TaskAuthorizationService::class),
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->db,
			logger: new NullLogger(),
			builder: new TaskBuilder(),
			dispatcher: $this->dispatcher
		);
	}//end service()

	private function openTask(): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-7');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('alice');
		$task->setRunUuid('run-1');

		return $task;
	}//end openTask()

	/**
	 * The event fires once, carries the completed task, and fires after the
	 * commit: a listener that walks the run must find a completion that
	 * already exists on its own.
	 */
	public function testACompletionIsAnnouncedOnceAfterTheCommit(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->dispatcher->expects($this->once())
			->method('dispatchTyped')
			->with($this->callback(function (Event $event): bool {
				$this->sequence[] = 'dispatch';
				$this->assertInstanceOf(TaskTerminalEvent::class, $event);
				$this->assertSame(Task::STATE_COMPLETED, $event->getTask()->getState());
				$this->assertSame('approved', $event->getTask()->getOutcome());

				return true;
			}));

		$this->service()->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, actor: 'alice');

		$this->assertSame(['commit', 'dispatch'], $this->sequence);
	}//end testACompletionIsAnnouncedOnceAfterTheCommit()

	public function testACancellationIsAnnounced(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->with($this->isInstanceOf(TaskTerminalEvent::class));

		$this->service()->cancel(uuid: 't-7', reason: 'moot', actor: 'rita');
	}//end testACancellationIsAnnounced()

	/**
	 * A refused verb moves nothing, so it announces nothing.
	 */
	public function testARefusedCompletionAnnouncesNothing(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(TaskValidationException::class);

		$this->service()->complete(uuid: 't-7', outcome: 'rejected', resultText: null, comment: '', actor: 'alice');
	}//end testARefusedCompletionAnnouncesNothing()

	/**
	 * A listener that throws cannot undo the completion: the task is returned
	 * completed, and the caller sees no error.
	 */
	public function testAListenerFailureDoesNotUndoTheCompletion(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->db->expects($this->never())->method('rollBack');
		$this->dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('listener blew up'));

		$completed = $this->service()->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, actor: 'alice');

		$this->assertSame(Task::STATE_COMPLETED, $completed->getState());
		$this->assertTrue($completed->getIsTerminal());
	}//end testAListenerFailureDoesNotUndoTheCompletion()
}//end class
