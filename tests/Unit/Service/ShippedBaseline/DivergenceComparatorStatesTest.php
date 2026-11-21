<?php

/**
 * Characterises DivergenceComparator::states(): the state of every part, absence included.
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
use PHPUnit\Framework\TestCase;

/**
 * Pins the five states, sorted paths, and how absence and null read.
 */
class DivergenceComparatorStatesTest extends TestCase {

	public function testEveryPartGetsItsStateInPathOrder(): void {
		$baseline = ['u' => 1, 'l' => 1, 'p' => 1, 'b' => 1, 'c' => 1, 'gone' => 1, 'n' => null];
		$live     = ['u' => 1, 'l' => 2, 'p' => 1, 'b' => 2, 'c' => 3, 'n' => null, 'local' => 'x'];
		$incoming = ['u' => 1, 'l' => 1, 'p' => 2, 'b' => 3, 'c' => 3, 'gone' => 1, 'new' => 'y'];

		$states = $this->states(baseline: $baseline, live: $live, incoming: $incoming);

		$this->assertSame(
			[
				'b' => DivergenceComparator::BOTH,
				'c' => DivergenceComparator::CONVERGED,
				'gone' => DivergenceComparator::LOCAL,
				'l' => DivergenceComparator::LOCAL,
				'local' => DivergenceComparator::LOCAL,
				// A part holding null reads as absent today (the lookup uses `??`).
				'n' => DivergenceComparator::UNCHANGED,
				'new' => DivergenceComparator::UPSTREAM,
				'p' => DivergenceComparator::UPSTREAM,
				'u' => DivergenceComparator::UNCHANGED,
			],
			$states
		);
	}

	public function testNestedPartsAreComparedNormalised(): void {
		$states = $this->states(
			baseline: ['properties' => ['a' => ['enum' => ['x', 'y']]]],
			live: ['properties' => ['a' => ['enum' => ['y', 'x']]]],
			incoming: ['properties' => ['a' => ['enum' => ['x', 'y', 'z']]]]
		);

		$this->assertSame(['properties.a.enum' => DivergenceComparator::UPSTREAM], $states);
	}

	/**
	 * The states of three revisions of a descriptor.
	 *
	 * @param array<string, mixed> $baseline What the app shipped.
	 * @param array<string, mixed> $live     What the instance runs.
	 * @param array<string, mixed> $incoming What the app now ships.
	 *
	 * @return array<string, string> Path to state.
	 */
	private function states(array $baseline, array $live, array $incoming): array {
		$comparator = new DivergenceComparator(parts: new DescriptorParts());

		return $comparator->states(descriptors: new DescriptorRevisions(baseline: $baseline, live: $live, incoming: $incoming));
	}
}//end class
