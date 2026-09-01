<?php

/**
 * FlowRunVisibilityIntegrationTest
 *
 * Task 9.4 / design D7 of `shared-credentials-and-flows`: run HISTORY is not
 * carried by a share. A recipient sees their own runs; runs triggered by the
 * owner or by other recipients stay invisible.
 *
 * This is a live-database test because the scoping is a SQL predicate, and the
 * defect it closes was a missing predicate: `findAllRuns()` filtered only by
 * flowId and status, so `GET /api/flow-runs` returned every run on the instance
 * to any authenticated caller — including each run's log, which records the
 * subject data the flow touched. A run log is exactly the data D7 exists to keep
 * out of a share.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/tasks.md#task-9-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Live-DB coverage for per-caller run-history scoping.
 */
class FlowRunVisibilityIntegrationTest extends TestCase {
	private FlowRunMapper $mapper;

	private IDBConnection $db;

	/**
	 * Unique suffix so parallel runs and leftovers cannot collide.
	 *
	 * @var string
	 */
	private string $tag;

	/**
	 * Resolve the mapper from the real container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mapper = \OC::$server->get(FlowRunMapper::class);
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->tag = substr(bin2hex(random_bytes(6)), 0, 12);
	}

	/**
	 * Remove only the rows this test created.
	 *
	 * Deliberately keyed on the per-test tag rather than a range or a wildcard —
	 * a broad delete in a shared development database is how real fixtures get
	 * destroyed.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('openregister_flow_runs')
			->where($qb->expr()->like('flow_id', $qb->createNamedParameter('%' . $this->tag)));
		$qb->executeStatement();

		parent::tearDown();
	}

	/**
	 * Persist one run.
	 *
	 * @param string $flowSuffix Distinguishes the flow within this test.
	 * @param string|null $triggeredBy The triggering uid, or null for a cron run.
	 *
	 * @return FlowRun
	 */
	private function makeRun(string $flowSuffix, ?string $triggeredBy): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-' . $flowSuffix . '-' . bin2hex(random_bytes(4)));
		$run->setFlowId($flowSuffix . '-' . $this->tag);
		$run->setStatus(FlowRun::STATUS_QUEUED);
		$run->setTriggeredBy($triggeredBy);

		return $this->mapper->insert($run);
	}

	/**
	 * Flow ids visible to a caller.
	 *
	 * @param string|null $uid The caller, or null for the unscoped read.
	 * @param array<int, string> $owned Flow ids the caller owns.
	 *
	 * @return array<int, string>
	 */
	private function visibleFlowIds(?string $uid, array $owned = []): array {
		$runs = $this->mapper->findAllRuns(
			flowId: null,
			status: null,
			limit: 200,
			offset: 0,
			requesterUid: $uid,
			ownedFlowIds: $owned
		);

		$ids = [];
		foreach ($runs as $run) {
			$flowId = $run->getFlowId();
			if (is_string($flowId) === true && str_ends_with($flowId, $this->tag) === true) {
				$ids[] = $flowId;
			}
		}

		return $ids;
	}

	/**
	 * A caller sees their own runs and NOT another user's.
	 *
	 * The second assertion is the one that matters. "Alice sees her run" would
	 * pass just as well against the old unscoped query, which returned
	 * everything; only "Alice does NOT see Bob's" distinguishes a working filter
	 * from no filter at all.
	 *
	 * @return void
	 */
	public function testACallerSeesTheirOwnRunsAndNotAnotherUsers(): void {
		$this->makeRun('mine', 'alice-' . $this->tag);
		$this->makeRun('theirs', 'bob-' . $this->tag);

		$visible = $this->visibleFlowIds('alice-' . $this->tag);

		$this->assertContains('mine-' . $this->tag, $visible, 'a caller must see the runs they triggered');
		$this->assertNotContains(
			'theirs-' . $this->tag,
			$visible,
			"another user's run must be invisible — this is the assertion the missing predicate failed"
		);
	}

	/**
	 * Runs of a flow the caller OWNS are visible whoever triggered them.
	 *
	 * `triggered_by` is NULL for cron- and trigger-fired runs, so without this
	 * disjunct a flow's own owner would be blind to every automated run of it.
	 *
	 * The control is the same fixture WITHOUT the ownership claim: it must be
	 * invisible then, or "owned runs are visible" would be indistinguishable from
	 * "everything is visible".
	 *
	 * @return void
	 */
	public function testRunsOfAnOwnedFlowAreVisibleEvenWhenCronTriggered(): void {
		$this->makeRun('owned', null);

		// CONTROL: not claimed as owned, and not triggered by this caller.
		$withoutOwnership = $this->visibleFlowIds('carol-' . $this->tag);
		$this->assertNotContains(
			'owned-' . $this->tag,
			$withoutOwnership,
			'control: an unowned, cron-triggered run must NOT be visible'
		);

		// Same row, now claimed.
		$withOwnership = $this->visibleFlowIds('carol-' . $this->tag, ['owned-' . $this->tag]);
		$this->assertContains(
			'owned-' . $this->tag,
			$withOwnership,
			"a flow's owner must see its automated runs"
		);
	}

	/**
	 * Owning no flows narrows to "runs I triggered" — it does not widen.
	 *
	 * An empty owned-ids list must not collapse the predicate into nothing (which
	 * would hide the caller's own runs) or into everything (which would restore
	 * the leak). Both failure directions are asserted.
	 *
	 * @return void
	 */
	public function testAnEmptyOwnedListNarrowsRatherThanWidens(): void {
		$this->makeRun('own', 'dave-' . $this->tag);
		$this->makeRun('other', 'erin-' . $this->tag);

		$visible = $this->visibleFlowIds('dave-' . $this->tag, []);

		$this->assertContains('own-' . $this->tag, $visible, 'owning no flows must not hide my own runs');
		$this->assertNotContains('other-' . $this->tag, $visible, 'owning no flows must not reveal everybody else');
	}

	/**
	 * A null uid means NO scoping — reserved for administrators.
	 *
	 * Pinned deliberately: it is the branch that reproduces the old leak, so it
	 * must be reached only on purpose. `index()` guards it behind an explicit
	 * `isAdmin()` that fails closed when the group manager or the session is
	 * absent.
	 *
	 * @return void
	 */
	public function testANullRequesterAppliesNoScoping(): void {
		$this->makeRun('a', 'frank-' . $this->tag);
		$this->makeRun('b', 'grace-' . $this->tag);

		$visible = $this->visibleFlowIds(null);

		$this->assertContains('a-' . $this->tag, $visible);
		$this->assertContains('b-' . $this->tag, $visible, 'a null requester is the admin path and sees both');
	}
}//end class
