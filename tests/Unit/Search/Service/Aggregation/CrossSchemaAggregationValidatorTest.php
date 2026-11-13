<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Search\Service\Aggregation\CrossSchemaAggregationValidator}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation;

use OCA\OpenRegister\Search\Service\Aggregation\AggregationSpecEntry;
use OCA\OpenRegister\Search\Service\Aggregation\CrossSchemaAggregationValidator;
use PHPUnit\Framework\TestCase;

/**
 * A cross-schema aggregation needs a `from`, a known metric, a map as filter
 * and no join; every failure is reported, in that order, with its code.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\CrossSchemaAggregationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationSpecEntry
 */
class CrossSchemaAggregationValidatorTest extends TestCase {

	/**
	 * The failures for this spec.
	 *
	 * @param array $spec The aggregation spec.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private static function failures(array $spec): array {
		return (new CrossSchemaAggregationValidator())->validate(entry: new AggregationSpecEntry(name: 'openCases', spec: $spec));
	}//end failures()

	/**
	 * A complete spec passes, with the metric under `metric` or `select` and the filter under `where` or `filter`.
	 *
	 * @return void
	 */
	public function testAValidSpecHasNoFailures(): void {
		$this->assertSame([], self::failures(spec: ['from' => 'case', 'metric' => 'count', 'where' => ['status' => 'open']]));
		$this->assertSame([], self::failures(spec: ['from' => 'case', 'select' => 'count_distinct', 'filter' => []]));
		$this->assertSame([], self::failures(spec: ['from' => 'case']));
	}//end testAValidSpecHasNoFailures()

	/**
	 * Every rule that fails is reported, in rule order, with its code and the aggregation's name.
	 *
	 * @return void
	 */
	public function testEveryFailureIsReportedInOrder(): void {
		$this->assertSame(
			[
				['code' => 'aggregation-from-empty', 'message' => 'Cross-schema aggregation "openCases" must have a non-empty `from` string.'],
				['code' => 'aggregation-bad-metric', 'message' => 'Cross-schema aggregation "openCases" metric "median" is not in [count, sum, avg, min, max, count_distinct].'],
				['code' => 'aggregation-filter-malformed', 'message' => 'Cross-schema aggregation "openCases" where/filter must be a map.'],
				['code' => 'aggregation-join-with-from', 'message' => 'Cross-schema aggregation "openCases" must not combine `from` with `join` — join is intra-schema only.'],
			],
			self::failures(spec: ['from' => '', 'metric' => 'median', 'where' => 'status = open', 'join' => ['schema' => 'party']])
		);
	}//end testEveryFailureIsReportedInOrder()

	/**
	 * A `from` that is not a string fails like an empty one.
	 *
	 * @return void
	 */
	public function testANonStringFromIsRefused(): void {
		$this->assertSame(['aggregation-from-empty'], array_column(self::failures(spec: ['from' => ['case']]), 'code'));
	}//end testANonStringFromIsRefused()

	/**
	 * `metric` wins over `select`, and `where` over `filter`.
	 *
	 * @return void
	 */
	public function testThePrimaryKeysWinOverTheirAliases(): void {
		$this->assertSame([], self::failures(spec: ['from' => 'case', 'metric' => 'sum', 'select' => 'median', 'where' => [], 'filter' => 'x']));
		$this->assertSame(
			['aggregation-bad-metric', 'aggregation-filter-malformed'],
			array_column(self::failures(spec: ['from' => 'case', 'metric' => 'COUNT', 'select' => 'count', 'where' => 'x', 'filter' => []]), 'code')
		);
	}//end testThePrimaryKeysWinOverTheirAliases()

	/**
	 * A join is refused even when it is empty or null-valued but present.
	 *
	 * @return void
	 */
	public function testAnyJoinIsRefused(): void {
		$this->assertSame(['aggregation-join-with-from'], array_column(self::failures(spec: ['from' => 'case', 'join' => []]), 'code'));
		$this->assertSame([], self::failures(spec: ['from' => 'case', 'join' => null]));
	}//end testAnyJoinIsRefused()
}//end class
