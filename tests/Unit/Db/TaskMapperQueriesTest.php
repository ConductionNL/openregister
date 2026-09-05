<?php

/**
 * TaskMapper's query vocabulary, walked without a database.
 *
 * Each public query is a distinct question the service or the inbox asks;
 * these tests walk each one's builder code and pin the predicate that makes
 * it correct: the conditional claim and update carry the openness guard, the
 * pooled scope carries the candidate EXISTS, the overdue filter carries the
 * COALESCE, the sorts carry a stable tiebreak, and the type guards refuse a
 * foreign entity by name.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The mapper's builder code paths.
 *
 * @covers \OCA\OpenRegister\Db\TaskMapper
 * @covers \OCA\OpenRegister\Db\TaskInboxCriteria
 * @covers \OCA\OpenRegister\Db\Task
 */
class TaskMapperQueriesTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * A stored task row as the database returns it.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(): array {
		return [
			'id' => 7,
			'uuid' => 't-7',
			'state' => Task::STATE_ACTIVE,
			'is_terminal' => 0,
			'performer_type' => 'user',
			'assignee' => 'alice',
			'priority' => 'normal',
			'candidate_users' => '["pat"]',
			'created' => '2026-09-01 10:00:00',
		];
	}//end row()

	/**
	 * findByUuid maps one row to a Task, and throws on none.
	 *
	 * @return void
	 */
	public function testFindByUuidMapsARowAndThrowsOnNone(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: [$this->row()]));
		$task = $mapper->findByUuid(uuid: 't-7');
		$this->assertSame('t-7', $task->getUuid());
		$this->assertSame(['pat'], $task->getCandidateUsers());
		$this->assertTrue($this->saw('expr.eq', 'uuid'));

		$this->expectException(DoesNotExistException::class);
		(new TaskMapper(db: $this->connectionWith(rows: [])))->findByUuid(uuid: 'ghost');
	}//end testFindByUuidMapsARowAndThrowsOnNone()

	/**
	 * claim is ONE conditional update: it sets the assignee and active state
	 * and guards on `is_terminal` plus an empty assignee; the affected-row
	 * count decides who won.
	 *
	 * @return void
	 */
	public function testClaimIsAConditionalUpdateDecidedByTheRowCount(): void {
		$winner = new TaskMapper(db: $this->connectionWith(affectedRows: 1));
		$this->assertTrue($winner->claim(taskId: 7, uid: 'bob'));
		$this->assertTrue($this->saw('set', 'assignee'));
		$this->assertTrue($this->saw('set', 'state'));
		$this->assertTrue($this->saw('expr.eq', 'is_terminal'));
		$this->assertTrue($this->saw('expr.isNull', 'assignee'));

		$loser = new TaskMapper(db: $this->connectionWith(affectedRows: 0));
		$this->assertFalse($loser->claim(taskId: 7, uid: 'carol'));
	}//end testClaimIsAConditionalUpdateDecidedByTheRowCount()

	/**
	 * updateIfOpen writes only the changed fields, guards on `is_terminal`,
	 * and reports whether the open row was hit.
	 *
	 * @return void
	 */
	public function testUpdateIfOpenWritesChangedFieldsUnderTheOpennessGuard(): void {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-7');
		$task->resetUpdatedFields();
		$task->setState(Task::STATE_COMPLETED);
		$task->setOutcome('approved');

		$mapper = new TaskMapper(db: $this->connectionWith(affectedRows: 1));
		$this->assertTrue($mapper->updateIfOpen(task: $task));
		$this->assertTrue($this->saw('set', 'state'));
		$this->assertTrue($this->saw('set', 'outcome'));
		$this->assertTrue($this->saw('set', 'updated'));
		$this->assertFalse($this->saw('set', 'title'), 'an untouched field is not written');
		$this->assertTrue($this->saw('expr.eq', 'is_terminal'));

		$this->assertFalse((new TaskMapper(db: $this->connectionWith(affectedRows: 0)))->updateIfOpen(task: $task));
	}//end testUpdateIfOpenWritesChangedFieldsUnderTheOpennessGuard()

	/**
	 * A task without an id cannot be conditionally updated.
	 *
	 * @return void
	 */
	public function testUpdateIfOpenRefusesAnUnsavedTask(): void {
		$this->expectException(InvalidArgumentException::class);
		(new TaskMapper(db: $this->connectionWith()))->updateIfOpen(task: new Task());
	}//end testUpdateIfOpenRefusesAnUnsavedTask()

	/**
	 * insert stamps `created` and update stamps `updated`; both refuse a
	 * foreign entity by name.
	 *
	 * @return void
	 */
	public function testInsertAndUpdateStampAndGuardTheEntityType(): void {
		$mapper = new TaskMapper(db: $this->connectionWith());
		$task = new Task();
		$task->setUuid('t-new');
		$task->setState(Task::STATE_AVAILABLE);
		$task->setPerformerType('user');

		$inserted = $mapper->insert(entity: $task);
		$this->assertNotNull($inserted->getCreated());
		$this->assertSame(77, $inserted->getId());

		$inserted->setTitle('renamed');
		$updated = $mapper->update(entity: $inserted);
		$this->assertNotNull($updated->getUpdated());

		try {
			$mapper->insert(entity: new FlowRun());
			$this->fail('A FlowRun was accepted by TaskMapper::insert.');
		} catch (InvalidArgumentException $refused) {
			$this->assertStringContainsString('Task entities only', $refused->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$mapper->update(entity: new FlowRun());
	}//end testInsertAndUpdateStampAndGuardTheEntityType()

	/**
	 * The sweep's task scan (task-expiry-and-outcomes D-3) selects only open
	 * rows that DECLARE a timeout behaviour, orders by the deadline, and is
	 * bounded by a floored batch limit.
	 *
	 * @return void
	 */
	public function testFindDueTimeoutsScansOpenDeclaredRowsBoundedAndOrdered(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: [$this->row()]));
		$due = $mapper->findDueTimeouts(now: new \DateTime('2026-09-02 10:00:00'), limit: 200);
		$this->assertCount(1, $due);
		$this->assertSame('t-7', $due[0]->getUuid());
		$this->assertTrue($this->saw('expr.eq', 'is_terminal'));
		$this->assertTrue($this->saw('expr.isNotNull', 'on_timeout'));
		$this->assertTrue($this->saw('orderBy', 'expires_at'));
		$this->assertTrue($this->saw('setMaxResults', 200));

		// The batch limit is floored at one, never zero or negative.
		(new TaskMapper(db: $this->connectionWith(rows: [])))->findDueTimeouts(now: new \DateTime('2026-09-02 10:00:00'), limit: -5);
		$this->assertTrue($this->saw('setMaxResults', 1));
	}//end testFindDueTimeoutsScansOpenDeclaredRowsBoundedAndOrdered()

	/**
	 * The propagation read selects by run uuid AND openness — the structural
	 * half of "a standalone task survives everything".
	 *
	 * @return void
	 */
	public function testFindOpenByRunUuidSelectsByRunAndOpenness(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: [$this->row()]));
		$open = $mapper->findOpenByRunUuid(runUuid: 'run-9');
		$this->assertCount(1, $open);
		$this->assertTrue($this->saw('expr.eq', 'run_uuid'));
		$this->assertTrue($this->saw('expr.eq', 'is_terminal'));
	}//end testFindOpenByRunUuidSelectsByRunAndOpenness()

	/**
	 * The two routing reads: open counts and latest assignment per uid, and
	 * both short-circuit on an empty pool without touching the database.
	 *
	 * @return void
	 */
	public function testRoutingReadsGroupByAssigneeAndShortCircuitOnAnEmptyPool(): void {
		$counts = new TaskMapper(
			db: $this->connectionWith(rows: [['assignee' => 'anna', 'open_count' => '4'], ['assignee' => 'bert', 'open_count' => '1']])
		);
		$this->assertSame(['anna' => 4, 'bert' => 1], $counts->countOpenAssigned(uids: ['anna', 'bert']));
		$this->assertTrue($this->saw('groupBy', 'assignee'));

		$latest = new TaskMapper(db: $this->connectionWith(rows: [['assignee' => 'anna', 'latest_created' => '2026-08-30 10:00:00']]));
		$this->assertSame(['anna' => '2026-08-30 10:00:00'], $latest->latestAssignedAt(uids: ['anna']));

		$db = $this->connectionWith();
		$db->expects($this->never())->method('getQueryBuilder');
		$idle = new TaskMapper(db: $db);
		$this->assertSame([], $idle->countOpenAssigned(uids: []));
		$this->assertSame([], $idle->latestAssignedAt(uids: []));
	}//end testRoutingReadsGroupByAssigneeAndShortCircuitOnAnEmptyPool()

	/**
	 * The assigned scope filters on assignee; visibility for a non-admin
	 * adds the five-relationship disjunction; the page is bounded and has a
	 * stable tiebreak.
	 *
	 * @return void
	 */
	public function testFindInboxAssignedScopeForANonAdmin(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: [$this->row()]));
		$criteria = new TaskInboxCriteria(uid: 'alice', groupIds: ['reviewers'], isAdmin: false);

		$page = $mapper->findInbox(criteria: $criteria, limit: 25, offset: 50);

		$this->assertCount(1, $page);
		$this->assertTrue($this->saw('expr.eq', 'assignee'));
		$this->assertTrue($this->saw('expr.eq', 'requester'), 'visibility disjunction present for a non-admin');
		$this->assertTrue($this->saw('setMaxResults', 25));
		$this->assertTrue($this->saw('setFirstResult', 50));
		$this->assertTrue($this->saw('orderBy', 'due_at'));
		$this->assertTrue($this->saw('addOrderBy', 'id'));
		// The watcher predicate is the cast LIKE; the pool predicate is the EXISTS.
		$this->assertTrue((bool)array_filter($this->functions, static fn (string $f): bool => str_contains($f, 'CAST(`watchers` AS CHAR) LIKE')));
		$this->assertTrue((bool)array_filter($this->functions, static fn (string $f): bool => str_starts_with($f, 'EXISTS (SELECT 1 FROM')));
	}//end testFindInboxAssignedScopeForANonAdmin()

	/**
	 * An administrator gets no visibility narrowing; the pooled scope guards
	 * on an empty assignee plus the candidate EXISTS; watched uses the LIKE.
	 *
	 * @return void
	 */
	public function testFindInboxScopesForAnAdmin(): void {
		$mapper = new TaskMapper(db: $this->connectionWith());

		$mapper->findInbox(criteria: new TaskInboxCriteria(uid: 'root', isAdmin: true, scope: TaskInboxCriteria::SCOPE_POOLED));
		$this->assertTrue($this->saw('expr.isNull', 'assignee'));
		$this->assertFalse($this->saw('expr.eq', 'requester'), 'an admin is not narrowed by visibility');

		$this->calls = [];
		$this->functions = [];
		$mapper->findInbox(criteria: new TaskInboxCriteria(uid: 'root', isAdmin: true, scope: TaskInboxCriteria::SCOPE_WATCHED));
		$this->assertCount(1, $this->functions);
		$this->assertStringContainsString('LIKE', $this->functions[0]);

		$this->calls = [];
		$mapper->findInbox(criteria: new TaskInboxCriteria(uid: 'root', isAdmin: true, scope: TaskInboxCriteria::SCOPE_ALL));
		$this->assertFalse($this->saw('expr.eq', 'assignee'));
	}//end testFindInboxScopesForAnAdmin()

	/**
	 * Every filter lands in the WHERE clause, the overdue filter as the
	 * COALESCE comparison, and every sort key has its ORDER BY.
	 *
	 * @return void
	 */
	public function testFindInboxFiltersAndSortsInTheDatastore(): void {
		$mapper = new TaskMapper(db: $this->connectionWith());
		$criteria = new TaskInboxCriteria(
			uid: 'root',
			isAdmin: true,
			scope: TaskInboxCriteria::SCOPE_ALL,
			states: [Task::STATE_ACTIVE, Task::STATE_ENABLED],
			isTerminal: false,
			priority: 'high',
			objectUuid: 'obj-1',
			overdueAt: new DateTime('2026-09-01T00:00:00+00:00'),
			sort: TaskInboxCriteria::SORT_PRIORITY,
			sortDescending: true
		);

		$mapper->findInbox(criteria: $criteria);

		$this->assertTrue($this->saw('expr.in', 'state'));
		$this->assertTrue($this->saw('expr.eq', 'is_terminal'));
		$this->assertTrue($this->saw('expr.eq', 'priority'));
		$this->assertTrue($this->saw('expr.eq', 'object_uuid'));
		$this->assertTrue((bool)array_filter($this->functions, static fn (string $f): bool => str_starts_with($f, 'COALESCE(`due_at`, `expires_at`) <')));
		$this->assertTrue((bool)array_filter($this->functions, static fn (string $f): bool => str_starts_with($f, 'CASE `priority` WHEN')));

		$this->calls = [];
		$mapper->findInbox(criteria: new TaskInboxCriteria(uid: 'root', isAdmin: true, sort: TaskInboxCriteria::SORT_CREATED));
		$this->assertTrue($this->saw('orderBy', 'created'));
	}//end testFindInboxFiltersAndSortsInTheDatastore()

	/**
	 * countInbox reads the total off the same predicates; no row is zero.
	 *
	 * @return void
	 */
	public function testCountInboxReadsTheTotal(): void {
		$counted = new TaskMapper(db: $this->connectionWith(rows: [['total' => '120']]));
		$this->assertSame(120, $counted->countInbox(criteria: new TaskInboxCriteria(uid: 'alice')));
		$this->assertTrue($this->saw('expr.eq', 'assignee'));

		$empty = new TaskMapper(db: $this->connectionWith(rows: []));
		$this->assertSame(0, $empty->countInbox(criteria: new TaskInboxCriteria(uid: 'alice')));
	}//end testCountInboxReadsTheTotal()
}//end class
