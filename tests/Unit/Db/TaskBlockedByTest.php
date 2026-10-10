<?php

/**
 * A task may wait on another task: the declaration, the derived flag and the
 * inbox predicate.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskBlockerMapper;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The blocker travels from create to read, and the inbox leaves blocked work out.
 *
 * @covers \OCA\OpenRegister\Db\Task
 * @covers \OCA\OpenRegister\Db\TaskMapper
 * @covers \OCA\OpenRegister\Db\TaskBlockerMapper
 * @covers \OCA\OpenRegister\Db\TaskInboxCriteria
 * @covers \OCA\OpenRegister\Service\Task\TaskBuilder
 * @covers \OCA\OpenRegister\Service\Task\TaskInboxService
 * @uses \OCA\OpenRegister\Service\Task\TaskPriority
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 * @uses \OCA\OpenRegister\Service\Task\TaskTemporalProjection
 */
class TaskBlockedByTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * The builder carries `blockedBy`, and leaves it null when the payload is silent.
	 *
	 * @return void
	 */
	public function testTheBuilderCarriesTheBlocker(): void {
		$builder = new TaskBuilder();

		$waiting = $builder->fromData(data: ['title' => 'Write the advice', 'blockedBy' => 'task-visit'], actor: 'anna');
		$this->assertSame('task-visit', $waiting->getBlockedBy());

		$plain = $builder->fromData(data: ['title' => 'Visit the site'], actor: 'anna');
		$this->assertNull($plain->getBlockedBy());
	}//end testTheBuilderCarriesTheBlocker()

	/**
	 * The stored row carries the declaration, and never a stored `blocked`.
	 *
	 * @return void
	 */
	public function testTheSerialisedRowCarriesTheBlockerButNoStoredFlag(): void {
		$task = new Task();
		$task->setBlockedBy('task-visit');

		$row = $task->jsonSerialize();

		$this->assertSame('task-visit', $row['blockedBy']);
		$this->assertArrayNotHasKey('blocked', $row, 'blocked is derived by the read surface, never stored on the entity');
	}//end testTheSerialisedRowCarriesTheBlockerButNoStoredFlag()

	/**
	 * An ordinary inbox read leaves out tasks whose blocker is still open.
	 *
	 * @return void
	 */
	public function testTheInboxLeavesBlockedTasksOut(): void {
		$mapper = new TaskMapper(db: $this->connectionWith());

		$mapper->findInbox(criteria: new TaskInboxCriteria(uid: 'anna'));

		$this->assertTrue($this->saw('expr.notIn', 'blocked_by'), 'the page must exclude tasks whose blocker is open');
		$this->assertTrue($this->saw('expr.isNull', 'blocked_by'), 'a task with no blocker must stay listed');
	}//end testTheInboxLeavesBlockedTasksOut()

	/**
	 * The total uses the same predicate, so the badge never counts a waiting task.
	 *
	 * @return void
	 */
	public function testTheTotalLeavesBlockedTasksOut(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: [['total' => 0]]));

		$mapper->countInbox(criteria: new TaskInboxCriteria(uid: 'anna'));

		$this->assertTrue($this->saw('expr.notIn', 'blocked_by'));
	}//end testTheTotalLeavesBlockedTasksOut()

	/**
	 * A read anchored to the case lists blocked tasks: the case shows what waits.
	 *
	 * @return void
	 */
	public function testAnObjectAnchoredReadKeepsBlockedTasks(): void {
		$mapper = new TaskMapper(db: $this->connectionWith());

		$mapper->findInbox(criteria: new TaskInboxCriteria(uid: 'anna', objectUuid: 'case-1'));

		$this->assertFalse($this->saw('expr.notIn', 'blocked_by'));
	}//end testAnObjectAnchoredReadKeepsBlockedTasks()

	/**
	 * A run-anchored read lists them too, like the external exclusion it mirrors.
	 *
	 * @return void
	 */
	public function testARunAnchoredReadKeepsBlockedTasks(): void {
		$mapper = new TaskMapper(db: $this->connectionWith());

		$mapper->findInbox(criteria: new TaskInboxCriteria(uid: 'anna', runUuid: 'run-1'));

		$this->assertFalse($this->saw('expr.notIn', 'blocked_by'));
	}//end testARunAnchoredReadKeepsBlockedTasks()

	/**
	 * `includeBlocked` brings them back in any scope.
	 *
	 * @return void
	 */
	public function testIncludeBlockedBringsThemBack(): void {
		$mapper = new TaskMapper(db: $this->connectionWith());

		$mapper->findInbox(criteria: new TaskInboxCriteria(uid: 'anna', includeBlocked: true));

		$this->assertFalse($this->saw('expr.notIn', 'blocked_by'));
	}//end testIncludeBlockedBringsThemBack()

	/**
	 * The mapper answers which of the named uuids are still open tasks.
	 *
	 * @return void
	 */
	public function testOpenBlockerUuidsAsksForOpenTasksByUuid(): void {
		$mapper = new TaskBlockerMapper(db: $this->connectionWith(rows: [['uuid' => 'task-visit']]));

		$open = $mapper->openBlockerUuids(uuids: ['task-visit', 'task-closed']);

		$this->assertSame(['task-visit'], $open);
		$this->assertTrue($this->saw('expr.in', 'uuid'));
		$this->assertTrue($this->saw('expr.eq', 'is_terminal'));
	}//end testOpenBlockerUuidsAsksForOpenTasksByUuid()

	/**
	 * No uuids, no query.
	 *
	 * @return void
	 */
	public function testOpenBlockerUuidsOfNothingIsNothing(): void {
		$mapper = new TaskBlockerMapper(db: $this->connectionWith());

		$this->assertSame([], $mapper->openBlockerUuids(uuids: []));
		$this->assertFalse($this->saw('select'));
	}//end testOpenBlockerUuidsOfNothingIsNothing()

	/**
	 * The inbox row carries the derived flag, from ONE lookup for the page.
	 *
	 * @return void
	 */
	public function testEveryRowCarriesTheDerivedFlagFromOneLookup(): void {
		$waiting = new Task();
		$waiting->setUuid('task-advice');
		$waiting->setBlockedBy('task-visit');
		$released = new Task();
		$released->setUuid('task-letter');
		$released->setBlockedBy('task-closed');
		$free = new Task();
		$free->setUuid('task-call');

		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('findInbox')->willReturn([$waiting, $released, $free]);
		$tasks->method('countInbox')->willReturn(3);
		$blockers = $this->createMock(TaskBlockerMapper::class);
		$blockers->expects($this->once())
			->method('openBlockerUuids')
			->with(['task-visit', 'task-closed'])
			->willReturn(['task-visit']);

		$inbox = new TaskInboxService(tasks: $tasks, temporal: new TaskTemporalProjection(), logger: new NullLogger(), objects: null, blockers: $blockers);
		$rows = $inbox->inbox(criteria: new TaskInboxCriteria(uid: 'anna', objectUuid: 'case-1'))['results'];

		$this->assertTrue($rows[0]['blocked']);
		$this->assertSame('task-visit', $rows[0]['blockedBy']);
		$this->assertFalse($rows[1]['blocked'], 'a closed blocker releases the task on the next read');
		$this->assertFalse($rows[2]['blocked']);
	}//end testEveryRowCarriesTheDerivedFlagFromOneLookup()

	/**
	 * A single read (the projections' row) derives the flag for its one blocker.
	 *
	 * @return void
	 */
	public function testASingleReadDerivesTheFlagToo(): void {
		$waiting = new Task();
		$waiting->setUuid('task-advice');
		$waiting->setBlockedBy('task-visit');

		$tasks = $this->createMock(TaskMapper::class);
		$blockers = $this->createMock(TaskBlockerMapper::class);
		$blockers->method('openBlockerUuids')->willReturn(['task-visit']);

		$inbox = new TaskInboxService(tasks: $tasks, temporal: new TaskTemporalProjection(), logger: new NullLogger(), objects: null, blockers: $blockers);

		$this->assertTrue($inbox->enrich(task: $waiting)['blocked']);
	}//end testASingleReadDerivesTheFlagToo()
}//end class
