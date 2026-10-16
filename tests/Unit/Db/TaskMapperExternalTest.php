<?php

/**
 * The task mapper's external-performer predicates: excluded from every inbox
 * shape but the object-anchored read, and the party-scoped finders.
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

use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the external branch of {@see TaskMapper}.
 *
 * @covers \OCA\OpenRegister\Db\TaskMapper
 * @covers \OCA\OpenRegister\Db\TaskInboxCriteria
 * @uses \OCA\OpenRegister\Db\Task
 */
class TaskMapperExternalTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * Every inbox shape without an object anchor excludes external tasks in
	 * the WHERE clause, page and count alike; the anchored read does not.
	 *
	 * @return void
	 */
	public function testEveryInboxShapeButTheAnchoredReadExcludesExternalTasks(): void {
		foreach ([TaskInboxCriteria::SCOPE_ASSIGNED, TaskInboxCriteria::SCOPE_POOLED, TaskInboxCriteria::SCOPE_WATCHED, TaskInboxCriteria::SCOPE_ALL] as $scope) {
			foreach ([false, true] as $admin) {
				$this->calls = [];
				$mapper = new TaskMapper(db: $this->connectionWith(rows: []));
				$criteria = new TaskInboxCriteria(uid: 'alice', groupIds: ['g'], isAdmin: $admin, scope: $scope);
				$mapper->findInbox(criteria: $criteria);
				$this->assertTrue($this->saw('expr.neq', 'performer_type'), "$scope admin=" . var_export($admin, true) . ' page excludes external');
				$this->assertTrue($this->saw('expr.isNull', 'performer_type'), 'legacy rows with no type stay visible');

				$this->calls = [];
				$mapper->countInbox(criteria: $criteria);
				$this->assertTrue($this->saw('expr.neq', 'performer_type'), "$scope count excludes external");
			}
		}

		$this->calls = [];
		$mapper = new TaskMapper(db: $this->connectionWith(rows: []));
		$mapper->findInbox(criteria: new TaskInboxCriteria(uid: 'alice', isAdmin: true, scope: TaskInboxCriteria::SCOPE_ALL, objectUuid: 'case-7'));
		$this->assertTrue($this->saw('expr.eq', 'object_uuid'));
		$this->assertFalse($this->saw('expr.neq', 'performer_type'), 'the case-anchored read shows the external task to the caseworker');
	}//end testEveryInboxShapeButTheAnchoredReadExcludesExternalTasks()

	/**
	 * The party finders predicate on assignee, performer type and openness,
	 * in the page and the count alike.
	 *
	 * @return void
	 */
	public function testThePartyFindersPredicateOnPartyTypeAndOpenness(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: [['id' => 1, 'uuid' => 't-1', 'state' => 'active', 'is_terminal' => 0, 'performer_type' => 'external', 'assignee' => 'party:bsn-1']]));
		$page = $mapper->findOpenExternalForParty(partyReference: 'party:bsn-1', limit: 10, offset: 20);
		$this->assertCount(1, $page);
		$this->assertSame('party:bsn-1', $page[0]->getAssignee());
		$this->assertTrue($this->saw('expr.eq', 'assignee'));
		$this->assertTrue($this->saw('expr.eq', 'performer_type'));
		$this->assertTrue($this->saw('expr.eq', 'is_terminal'));
		$this->assertTrue($this->saw('setMaxResults', 10));
		$this->assertTrue($this->saw('setFirstResult', 20));

		$this->calls = [];
		$counter = new TaskMapper(db: $this->connectionWith(rows: [['total' => 3]]));
		$this->assertSame(3, $counter->countOpenExternalForParty(partyReference: 'party:bsn-1'));
		$this->assertTrue($this->saw('expr.eq', 'assignee'));
		$this->assertTrue($this->saw('expr.eq', 'is_terminal'));
		$this->assertSame(0, (new TaskMapper(db: $this->connectionWith(rows: [])))->countOpenExternalForParty(partyReference: 'party:nobody'));
	}//end testThePartyFindersPredicateOnPartyTypeAndOpenness()

	/**
	 * A portal task held back by an open blocker is in neither the party's page nor its total.
	 *
	 * @return void
	 */
	public function testThePartyFindersLeaveOutATaskWhoseBlockerIsOpen(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: []));
		$mapper->findOpenExternalForParty(partyReference: 'party:bsn-1');
		$this->assertTrue($this->saw('expr.notIn', 'blocked_by'), 'the page excludes a task whose blocker is open');
		$this->assertTrue($this->saw('expr.isNull', 'blocked_by'), 'a task with no blocker stays listed');

		$this->calls = [];
		(new TaskMapper(db: $this->connectionWith(rows: [['total' => 0]])))->countOpenExternalForParty(partyReference: 'party:bsn-1');
		$this->assertTrue($this->saw('expr.notIn', 'blocked_by'), 'the total excludes it too, over the same predicate');
	}//end testThePartyFindersLeaveOutATaskWhoseBlockerIsOpen()
}//end class
