<?php

/**
 * A terminal task or run cancels its open timers, idempotently, and a
 * propagation failure never unwinds the subject's write.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Listener\FlowTimerSubjectTerminalListener;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\FlowTimerSubjectTerminalListener
 */
class FlowTimerSubjectTerminalListenerTest extends TestCase {

	private FlowTimerService&MockObject $timers;

	private LoggerInterface&MockObject $logger;

	private FlowTimerSubjectTerminalListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->timers = $this->createMock(FlowTimerService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new FlowTimerSubjectTerminalListener(timers: $this->timers, logger: $this->logger);
	}//end setUp()

	private function terminalTask(string $uuid, string $state, ?string $outcome): TaskTerminalEvent {
		$task = new Task();
		$task->setUuid($uuid);
		$task->setState($state);
		$task->setOutcome($outcome);

		return new TaskTerminalEvent(task: $task);
	}//end terminalTask()

	public function testATerminalTaskCancelsItsTimersWithTheReasonRecorded(): void {
		$event = $this->terminalTask(uuid: 'task-1', state: 'completed', outcome: 'approved');
		self::assertSame('task-1', (string)$event->getTask()->getUuid());

		$this->timers->expects(self::once())->method('cancelForSubject')
			->with('task', 'task-1', "Task 'task-1' reached terminal state 'completed' (outcome 'approved').", 'task:task-1')
			->willReturn(2);
		$this->logger->expects(self::once())->method('info')->with(self::stringContains('Cancelled 2 timer(s) of task task-1'));
		$this->listener->handle($event);
	}//end testATerminalTaskCancelsItsTimersWithTheReasonRecorded()

	public function testATerminalRunCancelsItsTimers(): void {
		$this->timers->expects(self::once())->method('cancelForRun')
			->with('run-1', "Run 'run-1' reached terminal status 'failed'.", 'flow-run:run-1')
			->willReturn(0);
		$this->logger->expects(self::never())->method('info');
		$this->listener->handle(new FlowRunTerminalEvent(runUuid: 'run-1', status: 'failed'));
	}//end testATerminalRunCancelsItsTimers()

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->timers->expects(self::never())->method('cancelForSubject');
		$this->timers->expects(self::never())->method('cancelForRun');
		$this->listener->handle(new Event());
	}//end testAnUnrelatedEventIsIgnored()

	public function testAFailureIsLoggedNotRethrown(): void {
		$this->timers->method('cancelForSubject')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects(self::once())->method('error')->with(self::stringContains('boom'), self::anything());
		$this->listener->handle($this->terminalTask(uuid: 'task-1', state: 'terminated', outcome: null));
	}//end testAFailureIsLoggedNotRethrown()
}//end class
