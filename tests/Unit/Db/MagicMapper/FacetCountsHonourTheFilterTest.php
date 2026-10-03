<?php

/**
 * Every facet path must count the same rows the list shows.
 *
 * 🔴 A FACET COUNT THAT IGNORES A FILTER THE LIST HONOURS IS WORSE THAN NO
 * COUNT. The user narrows cases to the ones carrying a property, and the facet
 * beside the result still describes every case in the register. Nothing looks
 * broken. The numbers are simply answers to a different question, and there is
 * nothing on screen that could tell anyone.
 *
 * 🔑 THIS IS A DERIVED TEST, NOT A RESTATED ONE. It reads the handler's own
 * source and finds every `buildFilteredQuery(` call, so a facet path added
 * later is covered the day it is written rather than the day someone remembers
 * to extend a list here. That matters because the defect it guards was created
 * by exactly that: a call site that predated the filter and was never revisited.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use PHPUnit\Framework\TestCase;

/**
 * Structural: the facet handler's own calls.
 *
 * @coversNothing
 */
class FacetCountsHonourTheFilterTest extends TestCase {

	/**
	 * The handler's source.
	 *
	 * @return string The source.
	 */
	private function source(): string {
		$path = __DIR__ . '/../../../../lib/Db/MagicMapper/MagicFacetHandler.php';
		$this->assertFileExists($path);

		return (string)file_get_contents($path);
	}//end source()

	/**
	 * Every `buildFilteredQuery(` call in the facet handler names a register.
	 *
	 * @return void
	 */
	public function testEveryFacetQueryPassesARegister(): void {
		$source = $this->source();
		$offset = 0;
		$calls  = 0;

		while (($start = strpos($source, 'buildFilteredQuery(', $offset)) !== false) {
			$end  = strpos($source, ');', $start);
			$call = substr($source, $start, (($end - $start) + 2));

			$this->assertStringContainsString(
				'registerId:',
				$call,
				'A facet query without a register cannot honour a related-row filter, '
				. 'so its counts would describe the unfiltered set beside a filtered list.'
			);

			$calls++;
			$offset = $end;
		}

		// The control: without it, a refactor that renames the method makes this
		// test pass by finding nothing at all.
		$this->assertGreaterThanOrEqual(2, $calls, 'Expected the facet handler to build filtered queries.');
	}//end testEveryFacetQueryPassesARegister()
}//end class
