<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The post-commit seam: a verb announces its committed transition with the
 * previous holder and the actor, AFTER the commit, and a listener that
 * throws cannot fail the verb. A rolled-back verb announces nothing.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TaskServiceAnnouncementTest extends TestCase {
	private TaskMapper&MockObject $tasks;

	private IDBConnection&MockObject $db;

	private IEventDispatcher&MockObject $events;

	/** @var array<int, string> */
	private array $order = [];

	protected function setUp(): void {
		parent::setUp();
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->events = $this->createMock(IEventDispatcher::class);
		$this->order = [];
		$this->db->method('commit')->willReturnCallback(
			function (): void {
				$this->order[] = 'commit';
			}
		);
		$this->db->method('rollBack')->willReturnCallback(
			function (): void {
				$this->order[] = 'rollback';
			}
		);
	}

	private function service(): TaskService {
		return new TaskService(
			tasks: $this->tasks,
			candidates: $this->createMock(TaskCandidateMapper::class),
			relations: $this->createMock(TaskRelationMapper::class),
			audits: $this->createMock(TaskAuditMapper::class),
			authorization: $this->createMock(TaskAuthorizationService::class),
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->db,
			logger: new NullLogger(),
			builder: new TaskBuilder(),
			events: $this->events
		);
	}

	private function openTask(): Task {
		$task = new Task();
		$task->setId(1);
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('former');

		return $task;
	}

	public function testAReassignmentAnnouncesThePreviousHolderAfterTheCommit(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->tasks->method('updateIfOpen')->willReturn(true);
		$this->tasks->method('update')->willReturnArgument(0);

		$announced = null;
		$this->events->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			function (TaskTransitionedEvent $event) use (&$announced): void {
				$this->order[] = 'announce';
				$announced = $event;
			}
		);

		$this->service()->reassign(uuid: 't-1', assignee: 'next', actor: 'manager');

		$this->assertSame(['commit', 'announce'], $this->order);
		$this->assertSame('former', $announced->getPreviousAssignee());
		$this->assertSame('next', $announced->getTask()->getAssignee());
		$this->assertSame('active', $announced->getPreviousState());
		$this->assertSame('manager', $announced->getActor());
		$this->assertSame('reassign', $announced->getAction());
		$this->assertTrue($announced->assigneeChanged());
	}

	public function testAListenerThatThrowsCannotFailTheVerb(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->tasks->method('updateIfOpen')->willReturn(true);
		$this->tasks->method('update')->willReturnArgument(0);
		$this->events->method('dispatchTyped')->willThrowException(new \RuntimeException('calendar backend fails every write'));

		$task = $this->service()->complete(uuid: 't-1', outcome: 'approved', resultText: null, comment: null, actor: 'former');

		$this->assertSame(Task::STATE_COMPLETED, $task->getState());
		$this->assertSame(['commit'], $this->order, 'committed, and never rolled back by the listener');
	}

	public function testARolledBackVerbAnnouncesNothing(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->tasks->method('updateIfOpen')->willThrowException(new \RuntimeException('write failed'));
		$this->events->expects($this->never())->method('dispatchTyped');

		try {
			$this->service()->complete(uuid: 't-1', outcome: 'approved', resultText: null, comment: null, actor: 'former');
			$this->fail('expected the write failure to surface');
		} catch (\RuntimeException $failure) {
			$this->assertSame(['rollback'], $this->order);
		}
	}

	public function testWithoutADispatcherTheLifecycleIsUnchanged(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->tasks->method('updateIfOpen')->willReturn(true);
		$this->tasks->method('update')->willReturnArgument(0);

		$service = new TaskService(
			tasks: $this->tasks,
			candidates: $this->createMock(TaskCandidateMapper::class),
			relations: $this->createMock(TaskRelationMapper::class),
			audits: $this->createMock(TaskAuditMapper::class),
			authorization: $this->createMock(TaskAuthorizationService::class),
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->db,
			logger: new NullLogger(),
			builder: new TaskBuilder()
		);

		$this->assertSame(Task::STATE_TERMINATED, $service->cancel(uuid: 't-1', reason: null, actor: 'former')->getState());
	}
}
