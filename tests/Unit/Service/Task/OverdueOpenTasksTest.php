<?php

/**
 * Unit tests for OverdueOpenTasks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\Notifications\OverdueTaskSourceInterface;
use OCA\OpenRegister\Service\Task\OverdueOpenTasks;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Task\OverdueOpenTasks
 * @uses   \OCA\OpenRegister\Db\TaskInboxAudience
 * @uses   \OCA\OpenRegister\Db\TaskInboxCriteria
 * @uses   \OCA\OpenRegister\Db\TaskInboxDueWindow
 * @uses   \OCA\OpenRegister\Db\TaskInboxFilter
 * @uses   \OCA\OpenRegister\Db\TaskInboxOrder
 * @uses   \OCA\OpenRegister\Db\TaskInboxSelection
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
final class OverdueOpenTasksTest extends TestCase {

	/**
	 * The query is the inbox's derived-overdue filter over open tasks, as an admin sweep, in creation order.
	 *
	 * @return void
	 */
	public function testItAsksTheInboxForOpenOverdueTasksAsAnAdminSweep(): void {
		$now      = new DateTime('2026-09-02T09:00:00+00:00');
		$page     = new ListQuery(limit: 200, offset: 400);
		$task     = new Task();
		$captured = null;
		$given    = null;

		$mapper = $this->createMock(TaskMapper::class);
		$mapper->expects(self::once())->method('findInbox')->willReturnCallback(
			static function (TaskInboxCriteria $criteria, ListQuery $listQuery) use (&$captured, &$given, $task): array {
				$captured = $criteria;
				$given    = $listQuery;
				return [$task];
			}
		);

		$source = new OverdueOpenTasks(tasks: $mapper);

		self::assertInstanceOf(OverdueTaskSourceInterface::class, $source);
		self::assertSame(expected: [$task], actual: $source->overdueOpenTasks(now: $now, page: $page));
		self::assertSame(expected: $page, actual: $given);
		self::assertSame(expected: OverdueOpenTasks::SWEEP_UID, actual: $captured->uid);
		self::assertSame(expected: '__openregister_task_sweep__', actual: $captured->uid);
		self::assertTrue(condition: $captured->isAdmin);
		self::assertSame(expected: TaskInboxCriteria::SCOPE_ALL, actual: $captured->scope);
		self::assertFalse(condition: $captured->isTerminal);
		self::assertSame(expected: $now, actual: $captured->overdueAt);
		self::assertSame(expected: TaskInboxCriteria::SORT_CREATED, actual: $captured->sort);
	}//end testItAsksTheInboxForOpenOverdueTasksAsAnAdminSweep()

	/**
	 * A failing query is not swallowed here: the sweep decides what a failure means.
	 *
	 * @return void
	 */
	public function testAFailingQueryReachesTheCaller(): void {
		$mapper = $this->createMock(TaskMapper::class);
		$mapper->method('findInbox')->willThrowException(new RuntimeException('db down'));

		$this->expectException(RuntimeException::class);

		(new OverdueOpenTasks(tasks: $mapper))->overdueOpenTasks(now: new DateTime(), page: new ListQuery(limit: 1, offset: 0));
	}//end testAFailingQueryReachesTheCaller()
}//end class
