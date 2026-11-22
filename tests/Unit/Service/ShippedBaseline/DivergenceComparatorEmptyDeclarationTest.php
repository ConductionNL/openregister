<?php

/**
 * An empty declaration root reads as absent: `[]`, `null` and a missing key are the same part.
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
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorRevisions;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the empty-versus-absent rule on the comparator, the merge and the recorded baseline.
 */
class DivergenceComparatorEmptyDeclarationTest extends TestCase {

	/**
	 * The three spellings of "no authorization block" a baseline and a live schema can carry.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function emptyAuthorizationSpellings(): array {
		return [
			'null baseline, [] live'    => [['authorization' => null], ['authorization' => []]],
			'missing baseline, [] live' => [[], ['authorization' => []]],
			'[] baseline, missing live' => [['authorization' => []], []],
			'[] baseline, null live'    => [['authorization' => []], ['authorization' => null]],
		];
	}

	/**
	 * @param array<string, mixed> $baseline The shipped side.
	 * @param array<string, mixed> $live     The instance side.
	 */
	#[DataProvider('emptyAuthorizationSpellings')]
	public function testAnEmptyAuthorizationIsNotALocalChange(array $baseline, array $live): void {
		$base = ['properties' => ['title' => ['type' => 'string']]];

		$this->assertSame([], $this->comparator()->report(baseline: $base + $baseline, live: $base + $live));
	}

	public function testTheShippedReadRuleLandsOverTheEntityDefault(): void {
		// The launchpad case: the baseline recorded no rule, the entity holds
		// its default `[]`, and the app now ships an audience read rule.
		$rule   = ['read' => ['launchpad-audience']];
		$result = $this->merge()->mergeRevisions(
			revisions: new DescriptorRevisions(
				baseline: ['authorization' => null],
				live: ['authorization' => []],
				incoming: ['authorization' => $rule]
			)
		);

		$this->assertSame([], $result['conflicts']);
		$this->assertSame($rule, $result['merged']['authorization']);
		$this->assertSame($rule, $result['baseline']['authorization']);
	}

	public function testAnEmptyPropertyAuthorizationIsNotALocalChangeEither(): void {
		$rule   = ['read' => ['hr']];
		$result = $this->merge()->mergeRevisions(
			revisions: new DescriptorRevisions(
				baseline: ['properties' => ['salary' => ['type' => 'number']]],
				live: ['properties' => ['salary' => ['type' => 'number', 'authorization' => []]]],
				incoming: ['properties' => ['salary' => ['type' => 'number', 'authorization' => $rule]]]
			)
		);

		$this->assertSame([], $result['conflicts']);
		$this->assertSame($rule, $result['merged']['properties']['salary']['authorization']);
	}

	public function testEmptyPropertiesRequiredAndConfigurationAreNotLocalChanges(): void {
		$report = $this->comparator()->report(
			baseline: ['properties' => null, 'configuration' => ['x-openregister-notifications' => null]],
			live: ['properties' => [], 'required' => [], 'configuration' => ['x-openregister-notifications' => []]]
		);

		$this->assertSame([], $report);
	}

	public function testARuleAddedOnTheInstanceIsStillALocalChange(): void {
		$report = $this->comparator()->report(
			baseline: ['authorization' => null],
			live: ['authorization' => ['read' => ['staff']]]
		);

		$this->assertSame(['authorization.read'], array_column($report, 'path'));
		$this->assertSame(DivergenceComparator::LOCAL, $report[0]['state']);
	}

	public function testARuleClearedOnTheInstanceIsStillALocalChange(): void {
		$report = $this->comparator()->report(
			baseline: ['authorization' => ['read' => ['staff']]],
			live: ['authorization' => []]
		);

		$this->assertSame(['authorization.read'], array_column($report, 'path'));
	}

	public function testAnEmptyActionListInsideABlockKeepsItsMeaning(): void {
		// `read: []` is a rule (nobody), not the absence of one.
		$report = $this->comparator()->report(
			baseline: ['authorization' => ['update' => ['staff']]],
			live: ['authorization' => ['update' => ['staff'], 'read' => []]]
		);

		$this->assertSame(['authorization.read'], array_column($report, 'path'));
	}

	public function testBothSidesMovingTheBlockIsStillAConflict(): void {
		$result = $this->merge()->mergeRevisions(
			revisions: new DescriptorRevisions(
				baseline: ['authorization' => null],
				live: ['authorization' => ['read' => ['staff']]],
				incoming: ['authorization' => ['read' => ['launchpad-audience']]]
			)
		);

		$this->assertSame(['authorization'], array_column($result['conflicts'], 'path'));
		$this->assertSame(['read' => ['staff']], $result['merged']['authorization']);
	}

	public function testTheDeclarationRoots(): void {
		$this->assertTrue(DivergenceComparator::isDeclarationRoot(path: 'authorization'));
		$this->assertTrue(DivergenceComparator::isDeclarationRoot(path: 'required'));
		$this->assertTrue(DivergenceComparator::isDeclarationRoot(path: 'properties.salary.authorization'));
		$this->assertTrue(DivergenceComparator::isDeclarationRoot(path: 'configuration.x-openregister-notifications'));
		$this->assertFalse(DivergenceComparator::isDeclarationRoot(path: 'authorization.read'));
		$this->assertFalse(DivergenceComparator::isDeclarationRoot(path: 'properties.salary'));
		$this->assertFalse(DivergenceComparator::isDeclarationRoot(path: 'properties.tags.enum'));
	}

	public function testTheRecordedBaselineSpellsAnEmptyDeclarationAsAnEmptyArray(): void {
		$recorded = $this->comparator()->recordable(
			definition: [
				'authorization' => null,
				'required' => ['title'],
				'properties' => [
					'salary' => ['type' => 'number', 'authorization' => null],
					'title' => ['type' => 'string'],
				],
				'configuration' => ['x-openregister-notifications' => null],
			]
		);

		$this->assertSame(
			[
				'authorization' => [],
				'required' => ['title'],
				'properties' => [
					'salary' => ['type' => 'number', 'authorization' => []],
					'title' => ['type' => 'string'],
				],
				'configuration' => ['x-openregister-notifications' => []],
			],
			$recorded
		);
	}

	public function testSamePartAtReadsEmptyAndAbsentAlike(): void {
		$comparator = $this->comparator();

		$this->assertTrue($comparator->samePartAt(shipped: [], live: ['authorization' => []], path: 'authorization'));
		$this->assertTrue($comparator->samePartAt(shipped: ['required' => null], live: ['required' => []], path: 'required'));
		$this->assertFalse($comparator->samePartAt(shipped: [], live: ['authorization.read' => []], path: 'authorization.read'));
	}

	/**
	 * A comparator on the real flattener.
	 *
	 * @return DivergenceComparator
	 */
	private function comparator(): DivergenceComparator {
		return new DivergenceComparator(parts: new DescriptorParts());
	}

	/**
	 * A merge on the real flattener and comparator, with no decision in effect.
	 *
	 * @return GuardedDescriptorMerge
	 */
	private function merge(): GuardedDescriptorMerge {
		$parts = new DescriptorParts();

		return new GuardedDescriptorMerge(parts: $parts, comparator: new DivergenceComparator(parts: $parts));
	}
}
