<?php

/**
 * Golden test pinning what AggregationQuery::create accepts and refuses.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationLayout;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use PHPUnit\Framework\TestCase;

/**
 * Pins every refusal message, the normalised shape and the static helpers.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 */
final class AggregationQueryRulesGoldenTest extends TestCase {

	/**
	 * Input that create() refuses, with the exact message.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refused(): array {
		$bucket = ['field' => 'd', 'start' => '2020-01-01', 'end' => '2020-02-01', 'gap' => 'day'];
		return [
			'unknown metric' => [['metric' => 'median'], 'aggregation metric MUST be one of: count, sum, avg, min, max (got: median)'],
			'sum without field' => [['metric' => 'sum'], 'aggregation metric "sum" MUST specify a field'],
			'sum with empty field' => [['metric' => 'sum', 'field' => ''], 'aggregation metric "sum" MUST specify a field'],
			'empty metrics' => [['metric' => 'count', 'metrics' => []], 'metrics list, when supplied, MUST NOT be empty'],
			'metrics entry not array' => [['metric' => 'count', 'metrics' => ['count']], 'each metrics entry MUST be an array with a `metric` key'],
			'metrics entry without metric' => [['metric' => 'count', 'metrics' => [['field' => 'a']]], 'each metrics entry MUST be an array with a `metric` key'],
			'metrics entry bad metric' => [['metric' => 'count', 'metrics' => [['metric' => 'x']]], 'metrics entry has an invalid metric "x" (MUST be one of: count, sum, avg, min, max)'],
			'metrics entry without field' => [['metric' => 'count', 'metrics' => [['metric' => 'avg', 'field' => '']]], 'metrics entry "avg" MUST specify a field'],
			'metrics with bucket' => [['metric' => 'count', 'metrics' => [['metric' => 'count']], 'dateBucket' => $bucket], 'a multi-metric `metrics` list MUST NOT be combined with dateBucket (time-bucket) aggregation'],
			'metrics entry checked before bucket' => [['metric' => 'count', 'metrics' => [['metric' => 'x']], 'dateBucket' => $bucket], 'metrics entry has an invalid metric "x" (MUST be one of: count, sum, avg, min, max)'],
			'empty groupBy' => [['metric' => 'count', 'groupBy' => []], 'groupBy MUST include a non-empty `field` or a non-empty `fields` list'],
			'groupBy unknown shape' => [['metric' => 'count', 'groupBy' => ['x' => 'a']], 'groupBy MUST include a non-empty `field` or a non-empty `fields` list'],
			'groupBy empty field' => [['metric' => 'count', 'groupBy' => ['field' => '']], 'groupBy MUST include a non-empty `field`; every group field MUST be a non-empty string'],
			'groupBy non-string field' => [['metric' => 'count', 'groupBy' => ['fields' => ['a', 3]]], 'groupBy MUST include a non-empty `field`; every group field MUST be a non-empty string'],
			'groupBy duplicate' => [['metric' => 'count', 'groupBy' => ['a', 'a']], 'groupBy fields MUST be distinct (duplicate field in groupBy list)'],
			'bucket missing gap' => [['metric' => 'count', 'dateBucket' => ['field' => 'd', 'start' => 's', 'end' => 'e']], 'dateBucket MUST include non-empty `field`, `start`, `end`, `gap`'],
			'bucket empty start' => [['metric' => 'count', 'dateBucket' => ['field' => 'd', 'start' => '', 'end' => 'e', 'gap' => 'day']], 'dateBucket MUST include non-empty `field`, `start`, `end`, `gap`'],
			'bucket bad gap' => [['metric' => 'count', 'dateBucket' => ['field' => 'd', 'start' => 's', 'end' => 'e', 'gap' => 'decade']], 'dateBucket gap MUST be one of: minute, hour, day, week, month, quarter, year (got: decade)'],
			'groupBy and bucket' => [['metric' => 'count', 'groupBy' => ['field' => 'a'], 'dateBucket' => $bucket], 'groupBy and dateBucket MUST NOT be combined — pick one bucketing strategy'],
			'cumulative without bucket' => [['metric' => 'count', 'cumulative' => true], 'cumulative MUST only be combined with a dateBucket (time-bucket) aggregation'],
			'metric checked first' => [['metric' => 'bad', 'groupBy' => [], 'cumulative' => true], 'aggregation metric MUST be one of: count, sum, avg, min, max (got: bad)'],
			'groupBy checked before bucket' => [['metric' => 'count', 'groupBy' => [], 'dateBucket' => ['gap' => 'x']], 'groupBy MUST include a non-empty `field` or a non-empty `fields` list'],
			'bucket checked before cumulative' => [['metric' => 'count', 'dateBucket' => ['gap' => 'x'], 'cumulative' => true], 'dateBucket MUST include non-empty `field`, `start`, `end`, `gap`'],
		];
	}//end refused()

	/**
	 * Every refusal and its message.
	 *
	 * @param array<string, mixed> $input   The create() arguments.
	 * @param string               $message The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider refused
	 */
	public function testRefused(array $input, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		self::createFrom(args: $input);
	}//end testRefused()

	/**
	 * Input that create() accepts, with the toArray shape it yields.
	 *
	 * @return void
	 */
	public function testAcceptedShapes(): void {
		$bucket = ['field' => 'd', 'start' => '2020-01-01', 'end' => '2020-02-01', 'gap' => 'day'];
		$this->assertSame(
			['metric' => 'count', 'field' => null, 'filter' => [], 'groupBy' => null, 'dateBucket' => null, 'metrics' => null, 'cumulative' => false],
			AggregationQuery::create(metric: 'count')->toArray()
		);
		$query = AggregationQuery::create(
			metric: 'sum',
			field: 'p',
			filter: ['b' => ['z' => 1, 'a' => 2], 'a' => 1],
			layout: new AggregationLayout(dateBucket: $bucket, cumulative: true)
		);
		$this->assertSame(
			['metric' => 'sum', 'field' => 'p', 'filter' => ['a' => 1, 'b' => ['a' => 2, 'z' => 1]], 'groupBy' => null, 'dateBucket' => $bucket, 'metrics' => null, 'cumulative' => true],
			$query->toArray()
		);
		$this->assertTrue($query->hasDateBucket());
		$this->assertTrue($query->isCumulative());
		$this->assertFalse($query->isGrouped());
		$this->assertSame(null, $query->getGroupByField());
	}//end testAcceptedShapes()

	/**
	 * The groupBy shapes and the derived accessors.
	 *
	 * @return void
	 */
	public function testGroupByShapes(): void {
		$this->assertSame(['a', 'b'], AggregationQuery::create(metric: 'count', layout: new AggregationLayout(groupBy: ['a', 'b']))->getGroupByFields());
		$keyed = AggregationQuery::create(metric: 'count', layout: new AggregationLayout(groupBy: ['fields' => ['x' => 'a', 'y' => 'b']]));
		$this->assertSame(['a', 'b'], $keyed->getGroupByFields());
		$single = AggregationQuery::create(metric: 'count', layout: new AggregationLayout(groupBy: ['field' => 'a']));
		$this->assertSame(['a'], $single->getGroupByFields());
		$this->assertSame('a', $single->getGroupByField());
		$this->assertFalse($single->isMultiFieldGroupBy());
		$this->assertTrue(AggregationQuery::create(metric: 'count', layout: new AggregationLayout(groupBy: ['a', 'b']))->isMultiFieldGroupBy());
		$this->assertSame([], AggregationQuery::normaliseGroupByFields(groupBy: 'a'));
		$this->assertSame([], AggregationQuery::normaliseGroupByFields(groupBy: ['x' => 1]));
		$this->assertSame(['a', 'a'], AggregationQuery::normaliseGroupByFields(groupBy: ['a', 'a']));
		$this->assertSame([null], AggregationQuery::normaliseGroupByFields(groupBy: ['field' => null]));
	}//end testGroupByShapes()

	/**
	 * The metrics list and response keys.
	 *
	 * @return void
	 */
	public function testMetrics(): void {
		$this->assertSame([['metric' => 'count', 'field' => null]], AggregationQuery::create(metric: 'count')->getMetrics());
		$query = AggregationQuery::create(
			metric: 'count',
			layout: new AggregationLayout(
				metrics: [
				['metric' => 'count', 'field' => '', 'condition' => [], 'as' => ''],
				['metric' => 'sum', 'field' => 'p', 'condition' => ['s' => 1], 'as' => 'total'],
			]
			)
		);
		$this->assertSame(
			[['metric' => 'count', 'field' => null], ['metric' => 'sum', 'field' => 'p', 'condition' => ['s' => 1], 'as' => 'total']],
			$query->getMetrics()
		);
		$this->assertTrue($query->isMultiMetric());
		$this->assertFalse(AggregationQuery::create(metric: 'count', layout: new AggregationLayout(metrics: [['metric' => 'count']]))->isMultiMetric());
		$this->assertSame('count', AggregationQuery::metricResponseKey(metric: 'count', field: 'x'));
		$this->assertSame('sum', AggregationQuery::metricResponseKey(metric: 'sum', field: ''));
		$this->assertSame('sum', AggregationQuery::metricResponseKey(metric: 'sum', field: null));
		$this->assertSame('sum_p', AggregationQuery::metricResponseKey(metric: 'sum', field: 'p'));
	}//end testMetrics()

	/**
	 * Call AggregationQuery::create() with a flat argument map, moving the layout keys
	 * (groupBy, dateBucket, metrics, cumulative) into one AggregationLayout.
	 *
	 * @param array<string, mixed> $args The flat create() arguments.
	 *
	 * @return AggregationQuery
	 */
	private static function createFrom(array $args): AggregationQuery {
		$layoutKeys = ['groupBy' => null, 'dateBucket' => null, 'metrics' => null, 'cumulative' => null];
		$layout     = array_intersect_key($args, $layoutKeys);
		$direct     = array_diff_key($args, $layoutKeys);
		if ($layout !== []) {
			$direct['layout'] = new AggregationLayout(...$layout);
		}

		return AggregationQuery::create(...$direct);
	}//end createFrom()
}//end class
