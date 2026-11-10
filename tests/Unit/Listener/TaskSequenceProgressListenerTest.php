<?php

/**
 * The sequence progresses on COMMITTED terminality only, and a progression
 * failure never reaches the deciding caller
 * (flow-approval-consolidation task 2.1).
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
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Listener\TaskSequenceProgressListener;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\TaskSequenceProgressListener
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Event\TaskTerminalEvent
 */
class TaskSequenceProgressListenerTest extends TestCase {

	private TaskSequenceService&MockObject $sequences;
	private LoggerInterface&MockObject $logger;
	private TaskSequenceProgressListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->sequences = $this->createMock(TaskSequenceService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new TaskSequenceProgressListener(sequences: $this->sequences, logger: $this->logger);
	}//end setUp()

	private function terminalTask(): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setSequenceUuid('seq-1');
		$task->setState(Task::STATE_COMPLETED);
		$task->setOutcome('approved');

		return $task;
	}//end terminalTask()

	public function testACommittedTerminalTaskProgressesItsSequence(): void {
		$this->sequences->expects(self::once())->method('onTaskTerminal');
		$this->listener->handle(new TaskTerminalEvent(task: $this->terminalTask(), committed: true));
	}//end testACommittedTerminalTaskProgressesItsSequence()

	public function testAnUncommittedDispatchIsSkipped(): void {
		// The mapper's in-transaction announcement must not progress the
		// sequence: the decision could still roll back.
		$this->sequences->expects(self::never())->method('onTaskTerminal');
		$this->listener->handle(new TaskTerminalEvent(task: $this->terminalTask(), committed: false));
	}//end testAnUncommittedDispatchIsSkipped()

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->sequences->expects(self::never())->method('onTaskTerminal');
		$this->listener->handle(new Event());
	}//end testAnUnrelatedEventIsIgnored()

	public function testAProgressionFailureIsLoggedNotRethrown(): void {
		$this->sequences->method('onTaskTerminal')->willThrowException(new RuntimeException('cursor clash'));
		$this->logger->expects(self::once())->method('error')->with(self::stringContains('cursor clash'), self::anything());

		$this->listener->handle(new TaskTerminalEvent(task: $this->terminalTask(), committed: true));
	}//end testAProgressionFailureIsLoggedNotRethrown()
}//end class
