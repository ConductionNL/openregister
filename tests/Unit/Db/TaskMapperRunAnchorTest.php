<?php

/**
 * The run anchor: a task read scoped to one flow run rather than to one inbox.
 *
 * Three properties, and the first two are the ones a plain filter would have
 * got wrong. `runUuid` REPLACES the scope, because the run's view asks what
 * the run asked and `scope` defaults to `assigned` — filtering instead of
 * anchoring would answer "what did this run ask ME", and answer it with an
 * empty list, which reads as a run that asked nobody. It also lifts the
 * external exclusion, for the same reason the object anchor does: a run that
 * asked a resident through the portal did ask somebody. Visibility is the one
 * thing it does NOT relax.
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
 * Tests for the run-anchored branch of {@see TaskMapper}.
 *
 * @covers \OCA\OpenRegister\Db\TaskMapper
 * @covers \OCA\OpenRegister\Db\TaskInboxCriteria
 * @uses \OCA\OpenRegister\Db\Task
 */
class TaskMapperRunAnchorTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * The anchor predicates on `run_uuid`, in the page and the count alike.
	 *
	 * @return void
	 */
	public function testTheRunAnchorPredicatesOnRunUuidInPageAndCount(): void {
		$criteria = new TaskInboxCriteria(uid: 'alice', isAdmin: true, runUuid: 'run-7');

		$mapper = new TaskMapper(db: $this->connectionWith(rows: []));
		$mapper->findInbox(criteria: $criteria);
		$this->assertTrue($this->saw('expr.eq', 'run_uuid'), 'the page filters on the run');

		$this->calls = [];
		$mapper->countInbox(criteria: $criteria);
		$this->assertTrue($this->saw('expr.eq', 'run_uuid'), 'the count filters on the run too');
	}//end testTheRunAnchorPredicatesOnRunUuidInPageAndCount()

	/**
	 * The anchor drops the scope narrowing, whichever scope the caller sent.
	 *
	 * `assignee = :uid` is what SCOPE_ASSIGNED adds and what the default
	 * would therefore have added to every run view. Asserted for an ADMIN so
	 * the visibility clause — which also predicates on `assignee` — is out of
	 * the way and the absence means what it says.
	 *
	 * @return void
	 */
	public function testTheRunAnchorReplacesTheScopeNarrowing(): void {
		foreach ([
			TaskInboxCriteria::SCOPE_ASSIGNED,
			TaskInboxCriteria::SCOPE_POOLED,
			TaskInboxCriteria::SCOPE_WATCHED,
			TaskInboxCriteria::SCOPE_ALL,
		] as $scope) {
			$this->calls = [];
			$mapper = new TaskMapper(db: $this->connectionWith(rows: []));
			$mapper->findInbox(
				criteria: new TaskInboxCriteria(uid: 'alice', groupIds: ['g'], isAdmin: true, scope: $scope, runUuid: 'run-7')
			);

			$this->assertTrue($this->saw('expr.eq', 'run_uuid'), "$scope still anchors on the run");
			$this->assertFalse($this->saw('expr.eq', 'assignee'), "$scope does not narrow a run view to the caller");
		}
	}//end testTheRunAnchorReplacesTheScopeNarrowing()

	/**
	 * Visibility is NOT relaxed: a non-admin still needs a relationship.
	 *
	 * The run's own tasks satisfy it through `requester`, which the engine
	 * stamps with the run's acting identity — so the run's owner sees all of
	 * them and a passer-by sees none, without the anchor having to know
	 * anything about ownership.
	 *
	 * @return void
	 */
	public function testTheRunAnchorDoesNotRelaxVisibility(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: []));
		$mapper->findInbox(
			criteria: new TaskInboxCriteria(uid: 'alice', groupIds: ['g'], isAdmin: false, scope: TaskInboxCriteria::SCOPE_ALL, runUuid: 'run-7')
		);

		$this->assertTrue($this->saw('expr.eq', 'run_uuid'));
		$this->assertTrue($this->saw('expr.eq', 'requester'), 'a non-admin is still held to a sanctioned relationship');
	}//end testTheRunAnchorDoesNotRelaxVisibility()

	/**
	 * The anchor lifts the external exclusion, as the object anchor does.
	 *
	 * @return void
	 */
	public function testTheRunAnchorShowsAPortalAskTheRunRaised(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: []));
		$mapper->findInbox(
			criteria: new TaskInboxCriteria(uid: 'alice', isAdmin: true, scope: TaskInboxCriteria::SCOPE_ALL, runUuid: 'run-7')
		);

		$this->assertFalse(
			$this->saw('expr.neq', 'performer_type'),
			'a run that asked a resident through the portal did ask somebody'
		);
	}//end testTheRunAnchorShowsAPortalAskTheRunRaised()

	/**
	 * No anchor, no change: every existing inbox read is untouched.
	 *
	 * @return void
	 */
	public function testWithoutAnAnchorNothingAboutTheInboxChanges(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: []));
		$mapper->findInbox(criteria: new TaskInboxCriteria(uid: 'alice'));

		$this->assertFalse($this->saw('expr.eq', 'run_uuid'), 'no run predicate without a run');
		$this->assertTrue($this->saw('expr.eq', 'assignee'), 'the default scope still narrows to the caller');
		$this->assertTrue($this->saw('expr.neq', 'performer_type'), 'and still excludes external tasks');
	}//end testWithoutAnAnchorNothingAboutTheInboxChanges()
}//end class
