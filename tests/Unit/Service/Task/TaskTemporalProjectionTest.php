<?php

/**
 * Overdue is derived from the clock, never from a write.
 *
 * The load-bearing assertion is byte-identity: a task becomes overdue by
 * TIME PASSING, with its stored row provably untouched — the same task
 * serialises identically before and after the clock crosses `due_at`,
 * and only the projection's answer changes. That is the property the
 * three fleet schemas storing `overdue` by hand cannot have.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use PHPUnit\Framework\TestCase;

/**
 * The clock-controlled overdue derivation.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskTemporalProjection
 * @covers \OCA\OpenRegister\Db\Task
 */
class TaskTemporalProjectionTest extends TestCase {

	/**
	 * The projection under test.
	 *
	 * @var TaskTemporalProjection
	 */
	private TaskTemporalProjection $projection;

	/**
	 * Build the projection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->projection = new TaskTemporalProjection();
	}//end setUp()

	/**
	 * A task becomes overdue with NO write: the row is byte-identical while
	 * the projection's answer flips with the injected clock.
	 *
	 * @return void
	 */
	public function testOverdueFlipsWithTheClockAndTheRowIsByteIdentical(): void {
		$task = new Task();
		$task->setState(Task::STATE_ACTIVE);
		$task->setDueAt(new DateTime('2026-09-01T12:00:00+00:00'));

		$serialisedBefore = json_encode($task->jsonSerialize());

		$before = $this->projection->project(task: $task, now: new DateTime('2026-09-01T11:59:59+00:00'));
		$after = $this->projection->project(task: $task, now: new DateTime('2026-09-03T12:00:01+00:00'));

		$serialisedAfter = json_encode($task->jsonSerialize());

		$this->assertFalse($before['overdue']);
		$this->assertTrue($after['overdue']);
		$this->assertSame(2, $after['daysOverdue']);
		$this->assertNull($after['daysUntilDue']);
		// The whole point: time passed, nothing was written.
		$this->assertSame($serialisedBefore, $serialisedAfter);
	}//end testOverdueFlipsWithTheClockAndTheRowIsByteIdentical()

	/**
	 * A passed due date does not touch state — due_at ADVISES.
	 *
	 * @return void
	 */
	public function testAPassedDueDateLeavesStateAlone(): void {
		$task = new Task();
		$task->setState(Task::STATE_ACTIVE);
		$task->setDueAt(new DateTime('2026-08-01T00:00:00+00:00'));
		$task->setExpiresAt(null);

		$result = $this->projection->project(task: $task, now: new DateTime('2026-08-31T00:00:00+00:00'));

		$this->assertTrue($result['overdue']);
		$this->assertSame(Task::STATE_ACTIVE, $task->getState());
	}//end testAPassedDueDateLeavesStateAlone()

	/**
	 * No deadline, no overdue — ever.
	 *
	 * @return void
	 */
	public function testNoDeadlineIsNeverOverdue(): void {
		$task = new Task();
		$result = $this->projection->project(task: $task, now: new DateTime('2099-01-01T00:00:00+00:00'));

		$this->assertFalse($result['overdue']);
		$this->assertNull($result['daysUntilDue']);
		$this->assertNull($result['daysOverdue']);
	}//end testNoDeadlineIsNeverOverdue()

	/**
	 * With only expires_at set, the enforcing deadline drives the projection.
	 *
	 * @return void
	 */
	public function testExpiresAtBacksTheProjectionWhenDueAtIsNull(): void {
		$task = new Task();
		$task->setExpiresAt(new DateTime('2026-09-05T00:00:00+00:00'));

		$future = $this->projection->project(task: $task, now: new DateTime('2026-09-01T00:00:00+00:00'));
		$past = $this->projection->project(task: $task, now: new DateTime('2026-09-06T00:00:00+00:00'));

		$this->assertFalse($future['overdue']);
		$this->assertSame(4, $future['daysUntilDue']);
		$this->assertTrue($past['overdue']);
	}//end testExpiresAtBacksTheProjectionWhenDueAtIsNull()

	/**
	 * The Task entity stores no overdue anywhere: not as a field, not in the
	 * serialisation. The projection is the only source.
	 *
	 * @return void
	 */
	public function testNothingStoredSpellsOverdue(): void {
		$task = new Task();
		$row = $task->jsonSerialize();

		$this->assertArrayNotHasKey('overdue', $row);
		$this->assertArrayNotHasKey('daysUntilDue', $row);
		$this->assertArrayNotHasKey('daysOverdue', $row);
		$this->assertFalse(property_exists($task, 'overdue'));
	}//end testNothingStoredSpellsOverdue()
}//end class
