<?php

/**
 * A committed terminal task advances its sequence; failures are logged, not thrown.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Listener\TaskSequenceProgressListener;
use OCA\OpenRegister\Service\Task\TaskSequenceServiceInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Only committed terminal tasks progress a sequence, and the exact task is handed over.
 */
class TaskSequenceProgressBehaviourTest extends TestCase {
	private function task(): Task {
		$task = new Task();
		$task->setUuid('task-9');
		return $task;
	}

	public function testHandsTheExactCommittedTaskToTheSequenceService(): void {
		$task      = $this->task();
		$sequences = $this->createMock(TaskSequenceServiceInterface::class);
		$sequences->expects($this->once())->method('onTaskTerminal')->with($this->identicalTo($task));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		(new TaskSequenceProgressListener($sequences, $logger))->handle(new TaskTerminalEvent($task, true));
	}

	public function testAnUncommittedOrForeignEventDoesNotProgress(): void {
		$sequences = $this->createMock(TaskSequenceServiceInterface::class);
		$sequences->expects($this->never())->method('onTaskTerminal');
		$listener = new TaskSequenceProgressListener($sequences, $this->createMock(LoggerInterface::class));

		$listener->handle(new TaskTerminalEvent($this->task(), false));
		$listener->handle(new Event());
	}

	public function testAFailureIsLoggedWithTheTaskUuidAndTheException(): void {
		$failure   = new RuntimeException('cursor clash');
		$sequences = $this->createMock(TaskSequenceServiceInterface::class);
		$sequences->method('onTaskTerminal')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[TaskSequenceProgressListener] Sequence progression failed for task task-9: cursor clash',
			['exception' => $failure]
		);

		(new TaskSequenceProgressListener($sequences, $logger))->handle(new TaskTerminalEvent($this->task(), true));
	}
}
