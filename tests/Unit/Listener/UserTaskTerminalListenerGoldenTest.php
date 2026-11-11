<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Listener\UserTaskTerminalListener}.
 *
 * Pins when the listener continues a run (committed terminal event of a task
 * with a run), when it does not (foreign event, uncommitted, blank or null
 * run uuid) and the exact error it logs when continuing fails. Written
 * against the code before its refactor and left unchanged by it.
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
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Listener\UserTaskTerminalListener;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @no-named-arguments
 */
class UserTaskTerminalListenerGoldenTest extends TestCase {

	/**
	 * Every bridge call and log line, in order.
	 *
	 * @var list<string>
	 */
	private array $trace = [];

	/**
	 * The failure the bridge throws, when it throws.
	 *
	 * @var RuntimeException|null
	 */
	private ?RuntimeException $failure = null;

	/**
	 * Dispatch one event and return the trace it caused.
	 *
	 * @param Event $event The event.
	 *
	 * @return list<string>
	 */
	private function dispatch(Event $event): array {
		$bridge = $this->createMock(FlowTaskBridge::class);
		$bridge->method('continueRun')->willReturnCallback(
			function (Task $task) {
				$this->trace[] = 'continueRun:' . $task->getUuid();
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return null;
			}
		);
		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
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
				$exception = $context['exception'] ?? null;
				unset($context['exception']);
				$this->trace[] = $level . ': ' . $message . ' ' . json_encode($context)
					. ' exception=' . ($exception instanceof \Throwable ? $exception->getMessage() : 'none');
			}
		};

		(new UserTaskTerminalListener($bridge, $logger))->handle($event);

		return $this->trace;
	}//end dispatch()

	/**
	 * A task with the given run uuid.
	 *
	 * @param string|null $runUuid The run uuid.
	 *
	 * @return Task
	 */
	private static function task(?string $runUuid): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setState(Task::STATE_COMPLETED);
		$task->setRunUuid($runUuid);

		return $task;
	}//end task()

	/**
	 * @return void
	 */
	public function testCommittedTaskWithRunContinuesIt(): void {
		$this->assertSame(['continueRun:task-1'], $this->dispatch(new TaskTerminalEvent(self::task('run-1'))));
	}//end testCommittedTaskWithRunContinuesIt()

	/**
	 * @return void
	 */
	public function testUncommittedEventIsIgnored(): void {
		$this->assertSame([], $this->dispatch(new TaskTerminalEvent(self::task('run-1'), false)));
	}//end testUncommittedEventIsIgnored()

	/**
	 * @return void
	 */
	public function testTaskWithoutRunIsIgnored(): void {
		$this->assertSame([], $this->dispatch(new TaskTerminalEvent(self::task('  '))));
		$this->assertSame([], $this->dispatch(new TaskTerminalEvent(self::task(null))));
	}//end testTaskWithoutRunIsIgnored()

	/**
	 * @return void
	 */
	public function testForeignEventIsIgnored(): void {
		$this->assertSame([], $this->dispatch(new Event()));
	}//end testForeignEventIsIgnored()

	/**
	 * @return void
	 */
	public function testBridgeFailureIsLoggedNotThrown(): void {
		$this->failure = new RuntimeException('engine busy');
		$this->assertSame(
			[
				'continueRun:task-1',
				'error: [UserTaskTerminalListener] Could not continue the run of task task-1; the task is terminal'
				. ' and the worker will pick the run up: engine busy {"run":"run-1"} exception=engine busy',
			],
			$this->dispatch(new TaskTerminalEvent(self::task('run-1')))
		);
	}//end testBridgeFailureIsLoggedNotThrown()
}//end class
