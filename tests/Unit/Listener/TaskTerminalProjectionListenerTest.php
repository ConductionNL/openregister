<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Dismiss-on-terminality from the user-task node's terminal event: withdraw
 * every notification about the task and re-render its calendar entry; a
 * non-terminal or task-less event does nothing.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-notification-for-an-answered-task-is-withdrawn
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Listener\TaskTerminalProjectionListener;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCP\EventDispatcher\Event;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TaskTerminalProjectionListenerTest extends TestCase {
	private IManager&MockObject $notifications;

	private TaskProjectionService&MockObject $projections;

	protected function setUp(): void {
		parent::setUp();
		$this->notifications = $this->createMock(IManager::class);
		$this->projections = $this->createMock(TaskProjectionService::class);
	}

	private function listener(): TaskTerminalProjectionListener {
		return new TaskTerminalProjectionListener($this->notifications, $this->projections, new NullLogger());
	}

	/**
	 * A stand-in for flow-user-task-node's TaskTerminalEvent: any event
	 * carrying getTask() qualifies, which is exactly the contract.
	 */
	private function terminalEvent(Task $task): Event {
		return new class($task) extends Event {
			public function __construct(private Task $task) {
				parent::__construct();
			}

			public function getTask(): Task {
				return $this->task;
			}
		};
	}

	private function task(string $state): Task {
		$task = new Task();
		$task->setUuid('t-9');
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('approver');

		return $task;
	}

	public function testATerminalTaskIsWithdrawnEverywhereAndReRendered(): void {
		$task = $this->task(Task::STATE_COMPLETED);
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->expects($this->once())->method('setObject')->with('object', 't-9')->willReturnSelf();
		$this->notifications->method('createNotification')->willReturn($notification);
		$this->notifications->expects($this->once())->method('markProcessed');
		$this->projections->expects($this->once())->method('reconcileTask')->with($task);

		$this->listener()->handle($this->terminalEvent($task));
	}

	public function testANonTerminalTaskIsLeftAlone(): void {
		$this->notifications->expects($this->never())->method('markProcessed');
		$this->projections->expects($this->never())->method('reconcileTask');

		$this->listener()->handle($this->terminalEvent($this->task(Task::STATE_ACTIVE)));
	}

	public function testAnEventWithoutATaskIsIgnored(): void {
		$this->notifications->expects($this->never())->method('markProcessed');
		$this->projections->expects($this->never())->method('reconcileTask');

		$this->listener()->handle(new FlowRunTerminalEvent('run-1', 'failed'));
	}

	public function testAWithdrawalFailureStillReRendersTheCalendar(): void {
		$task = $this->task(Task::STATE_TERMINATED);
		$this->notifications->method('createNotification')->willThrowException(new \RuntimeException('notifications down'));
		$this->projections->expects($this->once())->method('reconcileTask')->with($task);

		$this->listener()->handle($this->terminalEvent($task));
	}
}
