<?php

/**
 * The four states, the preserved local addition and the conflict that waits.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-LCA-003: apply upstream, preserve local, report both.
 */
class GuardedDescriptorMergeTest extends TestCase {

	/**
	 * The subject under test.
	 *
	 * @var GuardedDescriptorMerge
	 */
	private GuardedDescriptorMerge $merge;

	/**
	 * The comparator, used directly for the state table.
	 *
	 * @var DivergenceComparator
	 */
	private DivergenceComparator $comparator;

	/**
	 * Build the collaborators; none of them touches a database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$parts = new DescriptorParts();
		$this->comparator = new DivergenceComparator(parts: $parts);
		$this->merge = new GuardedDescriptorMerge(parts: $parts, comparator: $this->comparator);
	}//end setUp()

	/**
	 * What an app shipped two releases ago.
	 *
	 * @return array<string, mixed> The baseline.
	 */
	private function shipped(): array {
		return [
			'properties' => [
				'zaaknummer' => ['type' => 'string', 'title' => 'Zaaknummer'],
				'toelichting' => ['type' => 'string', 'title' => 'Toelichting', 'maxLength' => 500],
			],
			'required' => ['zaaknummer'],
		];
	}//end shipped()

	/**
	 * Each of the four states gets its own name.
	 *
	 * @return void
	 */
	public function testTheFourStates(): void {
		$baseline = $this->shipped();

		$live = $baseline;
		$live['properties']['toelichting']['maxLength'] = 2000;
		$live['properties']['wijk'] = ['type' => 'string', 'title' => 'Wijk'];

		$incoming = $baseline;
		$incoming['properties']['zaaknummer']['title'] = 'Zaak-ID';
		$incoming['properties']['toelichting']['maxLength'] = 1000;

		$states = $this->comparator->states(baseline: $baseline, live: $live, incoming: $incoming);

		$this->assertSame(
			DivergenceComparator::UNCHANGED,
			$states['properties.zaaknummer.type'],
			'a part nobody touched is unchanged'
		);
		$this->assertSame(
			DivergenceComparator::UPSTREAM,
			$states['properties.zaaknummer.title'],
			'a part only the app moved is upstream'
		);
		$this->assertSame(
			DivergenceComparator::LOCAL,
			$states['properties.wijk.title'],
			'a part only the instance added is local'
		);
		$this->assertSame(
			DivergenceComparator::BOTH,
			$states['properties.toelichting.maxLength'],
			'a part both moved, differently, is a conflict'
		);
	}//end testTheFourStates()

	/**
	 * 🔴 The scenario the row opens with: the extra field survives the release.
	 *
	 * @return void
	 */
	public function testTheExtraFieldSurvivesTheRelease(): void {
		$baseline = $this->shipped();

		$live = $baseline;
		$live['properties']['wijk'] = ['type' => 'string', 'title' => 'Wijk'];

		$incoming = $baseline;
		$incoming['properties']['zaaknummer']['title'] = 'Zaak-ID';

		$result = $this->merge->merge(baseline: $baseline, live: $live, incoming: $incoming);

		$this->assertArrayHasKey(
			'wijk',
			$result['merged']['properties'],
			'the property the municipality added must still be there after the upgrade'
		);
		$this->assertSame(
			'Zaak-ID',
			$result['merged']['properties']['zaaknummer']['title'],
			'and the upstream change must be applied'
		);
		$this->assertSame([], $result['conflicts'], 'nothing here needed a person');
	}//end testTheExtraFieldSurvivesTheRelease()

	/**
	 * 🔴 A part changed on both sides keeps its local value and is reported.
	 *
	 * @return void
	 */
	public function testAPartChangedOnBothSidesWaitsForAPerson(): void {
		$baseline = $this->shipped();

		$live = $baseline;
		$live['properties']['toelichting']['maxLength'] = 2000;

		$incoming = $baseline;
		$incoming['properties']['toelichting']['maxLength'] = 1000;

		$result = $this->merge->merge(baseline: $baseline, live: $live, incoming: $incoming);

		$this->assertSame(
			2000,
			$result['merged']['properties']['toelichting']['maxLength'],
			'the local definition is kept: an unattended upgrade must never choose'
		);
		$this->assertCount(1, $result['conflicts'], 'and the conflict is reported');
		$this->assertSame('properties.toelichting.maxLength', $result['conflicts'][0]['path']);
		$this->assertSame(1000, $result['conflicts'][0]['shipped'], 'the report names both definitions');
		$this->assertSame(2000, $result['conflicts'][0]['live']);
	}//end testAPartChangedOnBothSidesWaitsForAPerson()

	/**
	 * A conflict moves only on an explicit decision for that part.
	 *
	 * @return void
	 */
	public function testAConflictMovesOnlyOnAnExplicitDecision(): void {
		$baseline = $this->shipped();

		$live = $baseline;
		$live['properties']['toelichting']['maxLength'] = 2000;

		$incoming = $baseline;
		$incoming['properties']['toelichting']['maxLength'] = 1000;

		$result = $this->merge->merge(
			baseline: $baseline,
			live: $live,
			incoming: $incoming,
			decisions: ['properties.toelichting.maxLength']
		);

		$this->assertSame(
			1000,
			$result['merged']['properties']['toelichting']['maxLength'],
			'the decided part takes the shipped definition'
		);
		$this->assertSame([], $result['conflicts'], 'and it is no longer reported as waiting');
	}//end testAConflictMovesOnlyOnAnExplicitDecision()

	/**
	 * A decision for one part does not move another.
	 *
	 * @return void
	 */
	public function testADecisionIsPerPart(): void {
		$baseline = $this->shipped();

		$live = $baseline;
		$live['properties']['toelichting']['maxLength'] = 2000;
		$live['properties']['zaaknummer']['title'] = 'Ons kenmerk';

		$incoming = $baseline;
		$incoming['properties']['toelichting']['maxLength'] = 1000;
		$incoming['properties']['zaaknummer']['title'] = 'Zaak-ID';

		$result = $this->merge->merge(
			baseline: $baseline,
			live: $live,
			incoming: $incoming,
			decisions: ['properties.toelichting.maxLength']
		);

		$this->assertSame(
			'Ons kenmerk',
			$result['merged']['properties']['zaaknummer']['title'],
			'the part nobody decided about keeps its local value'
		);
		$this->assertCount(1, $result['conflicts'], 'and is still reported');
	}//end testADecisionIsPerPart()

	/**
	 * An upstream removal of an untouched part is applied.
	 *
	 * @return void
	 */
	public function testAnUpstreamRemovalOfAnUntouchedPartIsApplied(): void {
		$baseline = $this->shipped();
		$live = $baseline;

		$incoming = $baseline;
		unset($incoming['properties']['toelichting']);

		$result = $this->merge->merge(baseline: $baseline, live: $live, incoming: $incoming);

		$this->assertArrayNotHasKey(
			'toelichting',
			$result['merged']['properties'],
			'the app removed it and nobody locally disagreed'
		);
	}//end testAnUpstreamRemovalOfAnUntouchedPartIsApplied()

	/**
	 * 🔴 An upstream removal of a LOCALLY CHANGED part is a conflict, not a
	 * deletion.
	 *
	 * @return void
	 */
	public function testAnUpstreamRemovalOfALocallyChangedPartIsAConflict(): void {
		$baseline = $this->shipped();

		$live = $baseline;
		$live['properties']['toelichting']['maxLength'] = 4000;

		$incoming = $baseline;
		unset($incoming['properties']['toelichting']);

		$result = $this->merge->merge(baseline: $baseline, live: $live, incoming: $incoming);

		$this->assertSame(
			4000,
			$result['merged']['properties']['toelichting']['maxLength'],
			'a part somebody locally changed is not deleted by an upgrade without a decision'
		);
		$this->assertNotSame([], $result['conflicts'], 'and the removal is reported as a conflict');
	}//end testAnUpstreamRemovalOfALocallyChangedPartIsAConflict()

	/**
	 * Both sides moving to the same value is nobody disagreeing.
	 *
	 * @return void
	 */
	public function testBothSidesMovingToTheSameValueIsNotAConflict(): void {
		$baseline = $this->shipped();

		$live = $baseline;
		$live['properties']['toelichting']['maxLength'] = 1000;

		$incoming = $baseline;
		$incoming['properties']['toelichting']['maxLength'] = 1000;

		$result = $this->merge->merge(baseline: $baseline, live: $live, incoming: $incoming);

		$this->assertSame([], $result['conflicts'], 'there is nothing to resolve');
		$this->assertSame(1000, $result['merged']['properties']['toelichting']['maxLength']);
	}//end testBothSidesMovingToTheSameValueIsNotAConflict()

	/**
	 * The new baseline follows what was applied, and not what was preserved
	 * (D-5): two upgrades later the report still names the version the part
	 * diverged from.
	 *
	 * @return void
	 */
	public function testTheBaselineMovesOnlyForWhatWasApplied(): void {
		$baseline = $this->shipped();

		$live = $baseline;
		$live['properties']['toelichting']['maxLength'] = 2000;

		$incoming = $baseline;
		$incoming['properties']['zaaknummer']['title'] = 'Zaak-ID';
		$incoming['properties']['toelichting']['maxLength'] = 1000;

		$result = $this->merge->merge(baseline: $baseline, live: $live, incoming: $incoming);

		$this->assertSame(
			'Zaak-ID',
			$result['baseline']['properties']['zaaknummer']['title'],
			'the baseline records the new shipped definition for the part that was applied'
		);
		$this->assertSame(
			500,
			$result['baseline']['properties']['toelichting']['maxLength'],
			'and still names what the conflicting part was shipped as, not what either side now holds'
		);
	}//end testTheBaselineMovesOnlyForWhatWasApplied()

	/**
	 * An untouched instance reports nothing.
	 *
	 * @return void
	 */
	public function testAnUntouchedInstanceReportsNothing(): void {
		$baseline = $this->shipped();

		$this->assertSame(
			[],
			$this->comparator->report(baseline: $baseline, live: $baseline),
			'every part unchanged reports nothing, rather than reporting everything as fine'
		);
	}//end testAnUntouchedInstanceReportsNothing()

	/**
	 * Two local edits are both listed.
	 *
	 * @return void
	 */
	public function testTwoLocalEditsAreBothListed(): void {
		$baseline = $this->shipped();

		$live = $baseline;
		$live['properties']['toelichting']['maxLength'] = 2000;
		$live['properties']['wijk'] = ['type' => 'string', 'title' => 'Wijk'];

		$report = $this->comparator->report(baseline: $baseline, live: $live);
		$paths = array_column($report, 'path');

		$this->assertContains('properties.toelichting.maxLength', $paths);
		$this->assertContains('properties.wijk.title', $paths);
	}//end testTwoLocalEditsAreBothListed()

	/**
	 * 🔴 An absent baseline is not an empty one.
	 *
	 * @return void
	 */
	public function testAnAbsentBaselineIsNotAnEmptyOne(): void {
		$this->assertFalse($this->comparator->hasBaseline(baseline: null), 'null means nobody ever recorded one');
		$this->assertFalse($this->comparator->hasBaseline(baseline: []), 'and neither does an empty one');
		$this->assertTrue($this->comparator->hasBaseline(baseline: ['properties' => []]));
	}//end testAnAbsentBaselineIsNotAnEmptyOne()

	/**
	 * `required` is a set: reordering it is not a change.
	 *
	 * @return void
	 */
	public function testReorderingARequiredListIsNotAChange(): void {
		$baseline = ['required' => ['a', 'b', 'c']];
		$live = ['required' => ['c', 'a', 'b']];

		$this->assertSame(
			[],
			$this->comparator->report(baseline: $baseline, live: $live),
			'a set with the same members in another order is the same set'
		);
	}//end testReorderingARequiredListIsNotAChange()

	/**
	 * Adding a member to `required` IS a change.
	 *
	 * The mirror of the test above: a comparison that normalised everything
	 * away would pass that one and fail this one.
	 *
	 * @return void
	 */
	public function testAddingARequiredMemberIsAChange(): void {
		$baseline = ['required' => ['a', 'b']];
		$live = ['required' => ['a', 'b', 'c']];

		$this->assertCount(
			1,
			$this->comparator->report(baseline: $baseline, live: $live),
			'a member the instance added to required is a local change'
		);
	}//end testAddingARequiredMemberIsAChange()
}//end class
