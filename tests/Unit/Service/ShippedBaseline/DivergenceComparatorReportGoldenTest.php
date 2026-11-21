<?php

/**
 * Characterises DivergenceComparator::report(): every entry, its shape and its order.
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
use PHPUnit\Framework\TestCase;

/**
 * Pins changed, added and removed parts in path order, with presence flags.
 */
class DivergenceComparatorReportGoldenTest extends TestCase {

	public function testChangedAddedAndRemovedPartsAreReportedInPathOrder(): void {
		$comparator = new DivergenceComparator(parts: new DescriptorParts());

		$this->assertSame(
			[
				[
					'path' => 'added',
					'state' => DivergenceComparator::LOCAL,
					'shipped' => null,
					'live' => 'x',
					'shippedPresent' => false,
					'livePresent' => true,
				],
				[
					'path' => 'changed',
					'state' => DivergenceComparator::LOCAL,
					'shipped' => 1,
					'live' => 2,
					'shippedPresent' => true,
					'livePresent' => true,
				],
				[
					'path' => 'removed',
					'state' => DivergenceComparator::LOCAL,
					'shipped' => 'y',
					'live' => null,
					'shippedPresent' => true,
					'livePresent' => false,
				],
			],
			$comparator->report(
				baseline: ['same' => 1, 'removed' => 'y', 'changed' => 1, 'nulled' => null],
				live: ['changed' => 2, 'same' => 1, 'added' => 'x', 'nulled' => null]
			)
		);
	}//end testChangedAddedAndRemovedPartsAreReportedInPathOrder()
}//end class
