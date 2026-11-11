<?php

/**
 * Golden test for what GuardedDescriptorMerge reports as applied.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorRevisions;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use PHPUnit\Framework\TestCase;

/**
 * Pins the applied list for upstream paths, decided and undecided.
 *
 * @covers \OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge
 */
class GuardedDescriptorMergeAppliedGoldenTest extends TestCase {

	/**
	 * Merge one release on top of the baseline.
	 *
	 * @param array<string, mixed> $live      The instance's definition.
	 * @param array<string, mixed> $incoming  What the app now ships.
	 * @param string[]             $decisions Paths an administrator took from upstream.
	 *
	 * @return array<string, mixed> The merge result.
	 */
	private function merge(array $live, array $incoming, array $decisions = []): array {
		$parts = new DescriptorParts();
		$merge = new GuardedDescriptorMerge(parts: $parts, comparator: new DivergenceComparator(parts: $parts));

		return $merge->mergeRevisions(revisions: new DescriptorRevisions(baseline: $this->shipped(), live: $live, incoming: $incoming), decisions: $decisions);
	}//end merge()

	/**
	 * What the app shipped before.
	 *
	 * @return array<string, mixed> The baseline.
	 */
	private function shipped(): array {
		return [
			'properties' => [
				'zaaknummer' => ['type' => 'string', 'title' => 'Zaaknummer'],
				'toelichting' => ['type' => 'string', 'title' => 'Toelichting', 'maxLength' => 500],
			],
		];
	}//end shipped()

	/**
	 * An undecided upstream change is applied, merged and recorded as the new baseline.
	 *
	 * @return void
	 */
	public function testAnUndecidedUpstreamChangeIsReportedApplied(): void {
		$incoming = $this->shipped();
		$incoming['properties']['zaaknummer']['title'] = 'Zaak-ID';

		$result = $this->merge(live: $this->shipped(), incoming: $incoming);

		$this->assertSame(['properties.zaaknummer.title'], $result['applied']);
		$this->assertSame('Zaak-ID', $result['merged']['properties']['zaaknummer']['title']);
		$this->assertSame('Zaak-ID', $result['baseline']['properties']['zaaknummer']['title']);
	}//end testAnUndecidedUpstreamChangeIsReportedApplied()

	/**
	 * An undecided upstream removal is applied and dropped.
	 *
	 * @return void
	 */
	public function testAnUndecidedUpstreamRemovalIsReportedApplied(): void {
		$incoming = $this->shipped();
		unset($incoming['properties']['toelichting']['maxLength']);

		$result = $this->merge(live: $this->shipped(), incoming: $incoming);

		$this->assertSame(['properties.toelichting.maxLength'], $result['applied']);
		$this->assertArrayNotHasKey('maxLength', $result['merged']['properties']['toelichting']);
	}//end testAnUndecidedUpstreamRemovalIsReportedApplied()

	/**
	 * A decided upstream change is merged but not reported a second time.
	 *
	 * @return void
	 */
	public function testADecidedUpstreamChangeIsMergedWithoutBeingReported(): void {
		$incoming = $this->shipped();
		$incoming['properties']['zaaknummer']['title'] = 'Zaak-ID';

		$result = $this->merge(live: $this->shipped(), incoming: $incoming, decisions: ['properties.zaaknummer.title']);

		$this->assertSame([], $result['applied']);
		$this->assertSame('Zaak-ID', $result['merged']['properties']['zaaknummer']['title']);
	}//end testADecidedUpstreamChangeIsMergedWithoutBeingReported()

	/**
	 * A decided conflict is reported applied exactly once.
	 *
	 * @return void
	 */
	public function testADecidedConflictIsReportedAppliedOnce(): void {
		$live = $this->shipped();
		$live['properties']['toelichting']['maxLength'] = 2000;
		$incoming = $this->shipped();
		$incoming['properties']['toelichting']['maxLength'] = 1000;

		$result = $this->merge(live: $live, incoming: $incoming, decisions: ['properties.toelichting.maxLength']);

		$this->assertSame(['properties.toelichting.maxLength'], $result['applied']);
		$this->assertSame(1000, $result['merged']['properties']['toelichting']['maxLength']);
	}//end testADecidedConflictIsReportedAppliedOnce()
}//end class
