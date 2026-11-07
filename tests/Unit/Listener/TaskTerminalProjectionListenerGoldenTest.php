<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Listener\TaskTerminalProjectionListener}.
 *
 * Pins which events count as carrying a terminal task, the exact withdrawal
 * the notification manager receives, the warning when withdrawal fails, and
 * that the projection is re-rendered either way. Written against the code
 * before its refactor and left unchanged by it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Listener\TaskTerminalProjectionListener;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCP\EventDispatcher\Event;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @no-named-arguments
 */
class TaskTerminalProjectionListenerGoldenTest extends TestCase {

	/**
	 * Every collaborator call and log line, in order.
	 *
	 * @var list<string>
	 */
	private array $trace = [];

	/**
	 * Dispatch one event and return the trace it caused.
	 *
	 * @param Event $event      The event.
	 * @param bool  $failCreate Whether createNotification() throws.
	 *
	 * @return list<string>
	 */
	private function dispatch(Event $event, bool $failCreate = false): array {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnCallback(
			function (string $app) use ($notification): INotification {
				$this->trace[] = 'setApp:' . $app;
				return $notification;
			}
		);
		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $id) use ($notification): INotification {
				$this->trace[] = 'setObject:' . $type . ':' . $id;
				return $notification;
			}
		);
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function () use ($notification, $failCreate): INotification {
				if ($failCreate === true) {
					throw new RuntimeException('manager down');
				}

				return $notification;
			}
		);
		$manager->method('markProcessed')->willReturnCallback(
			function (INotification $marked) use ($notification): void {
				$this->trace[] = 'markProcessed:' . var_export($marked === $notification, true);
			}
		);
		$projections = $this->createMock(TaskProjectionService::class);
		$projections->method('reconcileTask')->willReturnCallback(
			function (Task $task): bool {
				$this->trace[] = 'reconcileTask:' . $task->getUuid();
				return true;
			}
		);

		(new TaskTerminalProjectionListener($manager, $projections, $this->logger()))->handle($event);

		return $this->trace;
	}//end dispatch()

	/**
	 * A logger appending to the trace.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$trace = &$this->trace;

		return new class($trace) extends AbstractLogger {
			/**
			 * @param list<string> $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * @param mixed             $level   Level.
			 * @param string|Stringable $message Message.
			 * @param array<mixed>      $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->trace[] = $level . ': ' . $message . ' ' . json_encode($context);
			}
		};
	}//end logger()

	/**
	 * An event exposing getTask().
	 *
	 * @param mixed $task What getTask() answers.
	 *
	 * @return Event
	 */
	private static function carrying(mixed $task): Event {
		return new class($task) extends Event {
			/**
			 * @param mixed $task The carried task.
			 */
			public function __construct(private mixed $task) {
				parent::__construct();
			}

			/**
			 * @return mixed
			 */
			public function getTask(): mixed {
				return $this->task;
			}
		};
	}//end carrying()

	/**
	 * A task in the given state.
	 *
	 * @param string      $state The state.
	 * @param string|null $uuid  The uuid.
	 *
	 * @return Task
	 */
	private static function task(string $state, ?string $uuid = 'task-1'): Task {
		$task = new Task();
		$task->setState($state);
		$task->setUuid($uuid);

		return $task;
	}//end task()

	/**
	 * @return void
	 */
	public function testTerminalTaskIsWithdrawnThenReconciled(): void {
		$this->assertSame(
			['setApp:openregister', 'setObject:object:task-1', 'markProcessed:true', 'reconcileTask:task-1'],
			$this->dispatch(self::carrying(self::task(Task::STATE_TERMINATED)))
		);
	}//end testTerminalTaskIsWithdrawnThenReconciled()

	/**
	 * @return void
	 */
	public function testWithdrawalFailureWarnsAndStillReconciles(): void {
		$this->assertSame(
			[
				'warning: [TaskTerminalProjectionListener] Could not withdraw the notifications of a terminal task: manager down {"task":"task-1"}',
				'reconcileTask:task-1',
			],
			$this->dispatch(self::carrying(self::task(Task::STATE_COMPLETED)), true)
		);
	}//end testWithdrawalFailureWarnsAndStillReconciles()

	/**
	 * @return void
	 */
	public function testOpenTaskIsIgnored(): void {
		$this->assertSame([], $this->dispatch(self::carrying(self::task(Task::STATE_ACTIVE))));
	}//end testOpenTaskIsIgnored()

	/**
	 * @return void
	 */
	public function testBlankUuidIsIgnored(): void {
		$this->assertSame([], $this->dispatch(self::carrying(self::task(Task::STATE_COMPLETED, '  '))));
		$this->assertSame([], $this->dispatch(self::carrying(self::task(Task::STATE_COMPLETED, null))));
	}//end testBlankUuidIsIgnored()

	/**
	 * @return void
	 */
	public function testNonTaskPayloadIsIgnored(): void {
		$this->assertSame([], $this->dispatch(self::carrying('task-1')));
	}//end testNonTaskPayloadIsIgnored()

	/**
	 * @return void
	 */
	public function testEventWithoutGetTaskIsIgnored(): void {
		$this->assertSame([], $this->dispatch(new Event()));
	}//end testEventWithoutGetTaskIsIgnored()
}//end class
