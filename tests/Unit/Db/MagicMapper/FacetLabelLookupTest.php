<?php

/**
 * FacetLabelLookupTest: which facet labels came from the cache and which still need a lookup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\FacetLabelLookup;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\FacetLabelLookup
 */
class FacetLabelLookupTest extends TestCase {

	public function testBeforeAnyLookupEveryUuidIsUncached(): void {
		$lookup = new FacetLabelLookup(['u1', 'u2'], 'owner');

		$this->assertSame(['u1', 'u2'], $lookup->uncached);
		$this->assertSame([], $lookup->result);
	}//end testBeforeAnyLookupEveryUuidIsUncached()

	public function testACompleteCacheAnswersEveryUuid(): void {
		$lookup = new FacetLabelLookup(['u1', 'u2'], 'owner');

		$this->assertTrue($lookup->take(['u2' => 'Bob', 'u1' => 'Ann', 'u3' => 'Extra']));
		$this->assertSame(['u1' => 'Ann', 'u2' => 'Bob'], $lookup->result);
		$this->assertSame([], $lookup->uncached);
	}//end testACompleteCacheAnswersEveryUuid()

	public function testAPartialCacheLeavesTheMissingUuidsUncached(): void {
		$lookup = new FacetLabelLookup(['u1', 'u2', 'u3'], 'owner');

		$this->assertFalse($lookup->take(['u2' => 'Bob']));
		$this->assertSame(['u2' => 'Bob'], $lookup->result);
		$this->assertSame(['u1', 'u3'], $lookup->uncached);
	}//end testAPartialCacheLeavesTheMissingUuidsUncached()

	public function testASecondTakeStartsOver(): void {
		$lookup = new FacetLabelLookup(['u1'], 'owner');
		$lookup->take([]);

		$this->assertTrue($lookup->take(['u1' => 'Ann']));
		$this->assertSame(['u1' => 'Ann'], $lookup->result);
		$this->assertSame([], $lookup->uncached);
	}//end testASecondTakeStartsOver()

	public function testElapsedTimeCountsFromConstruction(): void {
		$lookup = new FacetLabelLookup([], 'owner');
		$lookup->startTime = microtime(true) - 0.25;

		$this->assertGreaterThanOrEqual(250.0, $lookup->elapsedMs());
	}//end testElapsedTimeCountsFromConstruction()
}//end class
