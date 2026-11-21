<?php

/**
 * StatisticsPairFilter: which register/schema pairs a statistics query counts.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper\StatisticsPairFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\StatisticsPairFilter
 */
class StatisticsPairFilterTest extends TestCase {

	public function testNoWantedIdsMatchEveryPair(): void {
		$filter = new StatisticsPairFilter(['register' => null, 'schema' => null], []);

		$this->assertTrue($filter->matches([1, 2]));
		$this->assertTrue($filter->matches([99, 0]));
	}

	public function testASingleIdMatchesOnlyThatId(): void {
		$filter = new StatisticsPairFilter(['register' => 3, 'schema' => 4], []);

		$this->assertTrue($filter->matches([3, 4]));
		$this->assertFalse($filter->matches([3, 5]));
		$this->assertFalse($filter->matches([2, 4]));
	}

	public function testAListMatchesAnyOfItsIds(): void {
		$filter = new StatisticsPairFilter(['register' => [1, 2], 'schema' => null], []);

		$this->assertTrue($filter->matches([1, 8]));
		$this->assertTrue($filter->matches([2, 8]));
		$this->assertFalse($filter->matches([3, 8]));
	}

	public function testAListComparesIdsStrictly(): void {
		$filter = new StatisticsPairFilter(['register' => null, 'schema' => ['7']], []);

		$this->assertFalse($filter->matches([1, 7]));
	}

	public function testAnExcludedPairIsNotCountedEvenWhenWanted(): void {
		$filter = new StatisticsPairFilter(['register' => null, 'schema' => null], [
			['register' => '1', 'schema' => '2'],
			['register' => 5],
		]);

		$this->assertFalse($filter->matches([1, 2]));
		$this->assertTrue($filter->matches([1, 3]));
		$this->assertTrue($filter->matches([5, 2]));
	}
}
