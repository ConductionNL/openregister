<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\OpenRegister\Db\Flow;
use PHPUnit\Framework\TestCase;

/**
 * The three rules the Flow entity carries in code rather than in a column.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class FlowTest extends TestCase {
	private function flow(?string $owner = 'alice', bool $enabled = true): Flow {
		$flow = new Flow();
		$flow->setOwner($owner);
		$flow->setEnabled($enabled);

		return $flow;
	}//end flow()

	public function testADispatchableFlowIsEnabledAndOwned(): void {
		$this->assertTrue($this->flow()->canDispatch());
	}//end testADispatchableFlowIsEnabledAndOwned()

	public function testAnOwnerlessFlowCannotDispatch(): void {
		$this->assertFalse($this->flow(owner: null)->canDispatch());
	}//end testAnOwnerlessFlowCannotDispatch()

	/**
	 * A whitespace-only owner is not an owner. It is the shape a blanked form
	 * field takes, and treating it as present would hand a run an identity of
	 * "".
	 */
	public function testAWhitespaceOwnerCannotDispatch(): void {
		$this->assertFalse($this->flow(owner: '   ')->canDispatch());
	}//end testAWhitespaceOwnerCannotDispatch()

	public function testADisabledFlowCannotDispatch(): void {
		$this->assertFalse($this->flow(enabled: false)->canDispatch());
	}//end testADisabledFlowCannotDispatch()

	// --- organisation guard ------------------------------------------------
	public function testBelongsToMatchesTheSameOrganisation(): void {
		$flow = $this->flow();
		$flow->setOrganisation('org-1');

		$this->assertTrue($flow->belongsTo('org-1'));
		$this->assertFalse($flow->belongsTo('org-2'));
	}//end testBelongsToMatchesTheSameOrganisation()

	/**
	 * FAIL CLOSED in both directions. An unattributed flow belongs to nobody and
	 * a caller with no organisation owns nothing; treating either blank as a
	 * wildcard is how a scoping check becomes a no-op that still reads present.
	 */
	public function testAnUnattributedFlowBelongsToNobody(): void {
		$flow = $this->flow();
		$flow->setOrganisation(null);

		$this->assertFalse($flow->belongsTo('org-1'));
	}//end testAnUnattributedFlowBelongsToNobody()

	public function testACallerWithoutAnOrganisationOwnsNothing(): void {
		$flow = $this->flow();
		$flow->setOrganisation('org-1');

		$this->assertFalse($flow->belongsTo(null));
		$this->assertFalse($flow->belongsTo(''));
	}//end testACallerWithoutAnOrganisationOwnsNothing()

	// --- retention ---------------------------------------------------------
	public function testRetentionFallsBackToTheAdministratorDefault(): void {
		$flow = $this->flow();
		$flow->setRetentionDays(null);

		$this->assertSame(31, $flow->effectiveRetentionDays(31));
	}//end testRetentionFallsBackToTheAdministratorDefault()

	/**
	 * The override must win in BOTH directions — a noisy flow keeping less than
	 * the instance default, and an audited one keeping more.
	 */
	public function testAShorterOverrideWins(): void {
		$flow = $this->flow();
		$flow->setRetentionDays(7);

		$this->assertSame(7, $flow->effectiveRetentionDays(31));
	}//end testAShorterOverrideWins()

	public function testALongerOverrideWins(): void {
		$flow = $this->flow();
		$flow->setRetentionDays(365);

		$this->assertSame(365, $flow->effectiveRetentionDays(31));
	}//end testALongerOverrideWins()

	/**
	 * An unset override must TRACK the administrator setting, not a value
	 * captured when the flow was created.
	 */
	public function testAnUnsetOverrideTracksAChangedDefault(): void {
		$flow = $this->flow();
		$flow->setRetentionDays(null);

		$this->assertSame(31, $flow->effectiveRetentionDays(31));
		$this->assertSame(14, $flow->effectiveRetentionDays(14));
	}//end testAnUnsetOverrideTracksAChangedDefault()

	// --- audit / oversight inheritance -------------------------------------
	public function testAuditingFollowsTheInstanceDefaultWhenUnset(): void {
		$flow = $this->flow();

		$this->assertFalse($flow->auditsEachHop(false));
		$this->assertTrue($flow->auditsEachHop(true));
	}//end testAuditingFollowsTheInstanceDefaultWhenUnset()

	public function testAnExplicitAuditOptInBeatsTheDefault(): void {
		$flow = $this->flow();
		$flow->setAuditEnabled(true);

		$this->assertTrue($flow->auditsEachHop(false));
	}//end testAnExplicitAuditOptInBeatsTheDefault()

	public function testOversightFollowsTheInstanceDefaultWhenUnset(): void {
		$flow = $this->flow();

		$this->assertTrue($flow->gatesEachHop(true));
		$this->assertFalse($flow->gatesEachHop(false));
	}//end testOversightFollowsTheInstanceDefaultWhenUnset()

	/**
	 * An explicit opt-out is honoured — a flow may be exempted — but it has to
	 * be chosen, not inherited from an unset column.
	 */
	public function testAnExplicitOversightOptOutIsHonoured(): void {
		$flow = $this->flow();
		$flow->setOversightEnabled(false);

		$this->assertFalse($flow->gatesEachHop(true));
	}//end testAnExplicitOversightOptOutIsHonoured()

	/**
	 * A flow with no applicationSlug stays fully valid: it serialises the
	 * field as null rather than omitting it or defaulting it to an empty
	 * string, which the filter's non-empty check relies on being able to
	 * skip.
	 */
	public function testAFlowWithNoApplicationSlugSerialisesItAsNull(): void {
		$flow = $this->flow();

		$this->assertNull($flow->getApplicationSlug());
		$this->assertArrayHasKey('applicationSlug', $flow->jsonSerialize());
		$this->assertNull($flow->jsonSerialize()['applicationSlug']);
	}//end testAFlowWithNoApplicationSlugSerialisesItAsNull()

	/**
	 * A flow with an applicationSlug carries it through to serialisation.
	 */
	public function testAFlowWithAnApplicationSlugSerialisesIt(): void {
		$flow = $this->flow();
		$flow->setApplicationSlug('hydra');

		$this->assertSame('hydra', $flow->jsonSerialize()['applicationSlug']);
	}//end testAFlowWithAnApplicationSlugSerialisesIt()
}//end class
