<?php

/**
 * Closing a blocker releases the tasks that waited on it, with nobody acting.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
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

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskBlockerMapper as TaskMapper;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Listener\TaskBlockerReleaseListener;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The release is post-event work on the committed terminal event.
 *
 * @covers \OCA\OpenRegister\Listener\TaskBlockerReleaseListener
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Event\TaskTerminalEvent
 */
class TaskBlockerReleaseListenerTest extends TestCase {

	/**
	 * A task in the given state.
	 *
	 * @param string $uuid The uuid.
	 * @param string $state The state.
	 *
	 * @return Task The task.
	 */
	private function task(string $uuid, string $state = Task::STATE_COMPLETED): Task {
		$task = new Task();
		$task->setUuid($uuid);
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));

		return $task;
	}//end task()

	/**
	 * Every open dependant gets a `released` audit entry naming the blocker.
	 *
	 * @return void
	 */
	public function testClosingTheBlockerReleasesEveryDependant(): void {
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->expects($this->once())->method('findOpenBlockedBy')->with('task-visit')
			->willReturn([$this->task(uuid: 'task-advice', state: Task::STATE_ACTIVE), $this->task(uuid: 'task-letter', state: Task::STATE_ENABLED)]);

		$recorded = [];
		$service = $this->createMock(TaskService::class);
		$service->method('record')->willReturnCallback(
			function (string $uuid, string $action, ?string $actor, string $reason) use (&$recorded): Task {
				$recorded[] = [$uuid, $action, $actor, $reason];

				return $this->task(uuid: $uuid, state: Task::STATE_ACTIVE);
			}
		);

		$listener = new TaskBlockerReleaseListener(tasks: $tasks, service: $service, logger: new NullLogger());
		$listener->handle(event: new TaskTerminalEvent(task: $this->task(uuid: 'task-visit')));

		$this->assertSame(['task-advice', 'task-letter'], array_column($recorded, 0));
		$this->assertSame(['released', 'released'], array_column($recorded, 1));
		$this->assertNull($recorded[0][2], 'nobody acts: the system releases');
		$this->assertStringContainsString('task-visit', $recorded[0][3]);
	}//end testClosingTheBlockerReleasesEveryDependant()

	/**
	 * The in-transaction dispatch is ignored: closing a task never waits on its dependants.
	 *
	 * @return void
	 */
	public function testTheUncommittedDispatchReleasesNothing(): void {
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->expects($this->never())->method('findOpenBlockedBy');
		$service = $this->createMock(TaskService::class);
		$service->expects($this->never())->method('record');

		$listener = new TaskBlockerReleaseListener(tasks: $tasks, service: $service, logger: new NullLogger());
		$listener->handle(event: new TaskTerminalEvent(task: $this->task(uuid: 'task-visit'), committed: false));
	}//end testTheUncommittedDispatchReleasesNothing()

	/**
	 * One failing dependant does not stop the others.
	 *
	 * @return void
	 */
	public function testOneFailureDoesNotStopTheRest(): void {
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('findOpenBlockedBy')
			->willReturn([$this->task(uuid: 'task-advice', state: Task::STATE_ACTIVE), $this->task(uuid: 'task-letter', state: Task::STATE_ACTIVE)]);

		$recorded = [];
		$service = $this->createMock(TaskService::class);
		$service->method('record')->willReturnCallback(
			function (string $uuid) use (&$recorded): Task {
				if ($uuid === 'task-advice') {
					throw new RuntimeException('database went away');
				}

				$recorded[] = $uuid;

				return $this->task(uuid: $uuid, state: Task::STATE_ACTIVE);
			}
		);

		$listener = new TaskBlockerReleaseListener(tasks: $tasks, service: $service, logger: new NullLogger());
		$listener->handle(event: new TaskTerminalEvent(task: $this->task(uuid: 'task-visit')));

		$this->assertSame(['task-letter'], $recorded);
	}//end testOneFailureDoesNotStopTheRest()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->expects($this->never())->method('findOpenBlockedBy');

		$listener = new TaskBlockerReleaseListener(tasks: $tasks, service: $this->createMock(TaskService::class), logger: new NullLogger());
		$listener->handle(event: new Event());
	}//end testAnotherEventIsIgnored()
}//end class
