<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The published state/status mapping: lossy on render, a REQUESTED
 * TRANSITION on interpret, and a refusal for a status that names no legal
 * transition.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-status-mapping
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Service\Task\TaskPriority;
use OCA\OpenRegister\Service\Task\TaskVtodoStatusMapping;
use PHPUnit\Framework\TestCase;

class TaskVtodoStatusMappingTest extends TestCase {
	private function taskIn(string $state): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));

		return $task;
	}

	public function testEverySateRendersOntoOneOfFourValues(): void {
		$expected = [
			Task::STATE_AVAILABLE => 'NEEDS-ACTION',
			Task::STATE_ENABLED => 'NEEDS-ACTION',
			Task::STATE_ACTIVE => 'IN-PROCESS',
			Task::STATE_COMPLETED => 'COMPLETED',
			Task::STATE_TERMINATED => 'CANCELLED',
			Task::STATE_DISABLED => 'CANCELLED',
		];

		$this->assertSame($expected, TaskVtodoStatusMapping::mapping());
		foreach (Task::STATES as $state) {
			$this->assertSame($expected[$state], TaskVtodoStatusMapping::render($state));
		}
	}

	public function testCompletedRequestsTheCompleteVerbNeverAState(): void {
		$this->assertSame('complete', TaskVtodoStatusMapping::requestedVerb('COMPLETED', $this->taskIn(Task::STATE_ACTIVE)));
		$this->assertSame('complete', TaskVtodoStatusMapping::requestedVerb('completed', $this->taskIn(Task::STATE_ENABLED)));
	}

	public function testCancelledRequestsTheCancelVerb(): void {
		$this->assertSame('cancel', TaskVtodoStatusMapping::requestedVerb('CANCELLED', $this->taskIn(Task::STATE_ACTIVE)));
	}

	public function testAStatusRestatingTheRenderedOneIsNoRequest(): void {
		$this->assertNull(TaskVtodoStatusMapping::requestedVerb('IN-PROCESS', $this->taskIn(Task::STATE_ACTIVE)));
		$this->assertNull(TaskVtodoStatusMapping::requestedVerb('COMPLETED', $this->taskIn(Task::STATE_COMPLETED)));
	}

	public function testAProgressNoteOnAnOpenTaskIsNoRequest(): void {
		// NEEDS-ACTION on an active task: projection-owned, overwritten by the next render.
		$this->assertNull(TaskVtodoStatusMapping::requestedVerb('NEEDS-ACTION', $this->taskIn(Task::STATE_ACTIVE)));
	}

	public function testReopeningATerminalTaskIsRefusedNamingTheState(): void {
		$this->expectException(TaskConflictException::class);
		$this->expectExceptionMessage("terminal state 'completed'");

		TaskVtodoStatusMapping::requestedVerb('NEEDS-ACTION', $this->taskIn(Task::STATE_COMPLETED));
	}

	public function testPriorityRoundTripsThroughTheImportMapping(): void {
		foreach (['urgent', 'high', 'normal', 'low'] as $priority) {
			$ical = TaskVtodoStatusMapping::priority($priority);
			$this->assertSame($priority, TaskPriority::normalise($ical), sprintf('%s -> %d does not round-trip', $priority, $ical));
		}

		$this->assertSame(5, TaskVtodoStatusMapping::priority(null));
	}
}
