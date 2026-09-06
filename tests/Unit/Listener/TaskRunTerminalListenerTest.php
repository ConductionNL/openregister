<?php

/**
 * Cancellation propagation: the listener between a terminal run and its tasks.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\TaskRunTerminalListener;
use OCA\OpenRegister\Service\Task\TaskService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The listener hands the run's identity to propagation and never rethrows.
 *
 * @covers \OCA\OpenRegister\Listener\TaskRunTerminalListener
 * @covers \OCA\OpenRegister\Event\FlowRunTerminalEvent
 */
class TaskRunTerminalListenerTest extends TestCase {

	/**
	 * A terminal-run event terminates that run's tasks, by uuid and status.
	 *
	 * @return void
	 */
	public function testATerminalRunTerminatesItsTasks(): void {
		$tasks = $this->createMock(originalClassName: TaskService::class);
		$tasks->expects($this->once())
			->method('terminateForRun')
			->with(runUuid: 'run-9', runStatus: 'stopped')
			->willReturn(3);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->stringContains('3 task(s)'));

		$event = new FlowRunTerminalEvent(runUuid: 'run-9', status: 'stopped');
		$this->assertSame('run-9', $event->getRunUuid());
		$this->assertSame('stopped', $event->getStatus());

		(new TaskRunTerminalListener(tasks: $tasks, logger: $logger))->handle(event: $event);
	}//end testATerminalRunTerminatesItsTasks()

	/**
	 * A propagation failure is logged, never rethrown: the run's own terminal
	 * write must not be unwound by task bookkeeping.
	 *
	 * @return void
	 */
	public function testAPropagationFailureIsLoggedNotRethrown(): void {
		$tasks = $this->createMock(originalClassName: TaskService::class);
		$tasks->method('terminateForRun')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('db down'), $this->arrayHasKey('run'));

		(new TaskRunTerminalListener(tasks: $tasks, logger: $logger))->handle(
			event: new FlowRunTerminalEvent(runUuid: 'run-9', status: 'failed')
		);
	}//end testAPropagationFailureIsLoggedNotRethrown()

	/**
	 * Any other event is ignored without touching the service.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$tasks = $this->createMock(originalClassName: TaskService::class);
		$tasks->expects($this->never())->method('terminateForRun');

		(new TaskRunTerminalListener(tasks: $tasks, logger: $this->createMock(originalClassName: LoggerInterface::class)))
			->handle(event: $this->createMock(originalClassName: ObjectDeletedEvent::class));
	}//end testOtherEventsAreIgnored()
}//end class
