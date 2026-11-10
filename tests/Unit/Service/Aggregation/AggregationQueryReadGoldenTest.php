<?php

/**
 * Golden test for the read side of AggregationQuery.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   Unit\Service\Aggregation
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Search\Service\Aggregation\AggregationLayout;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case reads a query (or calls a static helper) and records every
 * accessor. Compared with fixtures/aggregation-query-read-golden.json; set
 * UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 */
class AggregationQueryReadGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/aggregation-query-read-golden.json';

	/**
	 * @return array<string, array{0: callable(): mixed}>
	 */
	public static function cases(): array {
		$bucket = ['field' => 'd', 'start' => 'a', 'end' => 'b', 'gap' => 'day'];
		return [
			'legacy single metric, nested filter' => [static fn () => self::read(AggregationQuery::create(
				metric: 'sum',
				field: 'amount',
				filter: ['b' => ['lte' => 10, 'gt' => 5], 'a' => 1, 'c' => [['z' => 1, 'a' => 2]]]
			))],
			'metrics list with condition and alias' => [static fn () => self::read(AggregationQuery::create(
				metric: 'count',
				layout: new AggregationLayout(
					groupBy: ['fields' => ['x' => 'status', 'y' => 'kind']],
					metrics: [
					['metric' => 'count'],
					['metric' => 'sum', 'field' => 'amount', 'condition' => ['status' => 'open'], 'as' => 'openSum'],
					['metric' => 'count', 'field' => '', 'condition' => [], 'as' => ''],
					['metric' => 'avg', 'field' => 'x', 'as' => 5, 'condition' => 'str'],
				]
				)
			))],
			'one metric in list, list groupBy' => [static fn () => self::read(AggregationQuery::create(
				metric: 'count',
				layout: new AggregationLayout(
					groupBy: self::asGroupBy(['status', 'kind']),
					metrics: [['metric' => 'max', 'field' => 'n', 'extra' => 1]]
				)
			))],
			'single field groupBy' => [static fn () => self::read(
				AggregationQuery::create(metric: 'min', field: 'n', layout: new AggregationLayout(groupBy: ['field' => 'status']))
			)],
			'cumulative date bucket' => [static fn () => self::read(
				AggregationQuery::create(metric: 'count', layout: new AggregationLayout(dateBucket: $bucket, cumulative: true))
			)],
			'normaliseGroupByFields' => [static function (): array {
				$shapes = [
					null, 'status', 7, [], ['a', 'b'], ['fields' => ['k' => 'a', 'b']], ['fields' => 'a', 'field' => 'b'],
					['fields' => ['a'], 'field' => 'b'], ['field' => null], ['other' => 'x'], [1 => 'a'], ['fields' => null, 'field' => 'c'],
				];
				return array_map(static fn ($shape) => AggregationQuery::normaliseGroupByFields($shape), $shapes);
			},
			],
			'metricResponseKey' => [static fn () => [
				AggregationQuery::metricResponseKey('count', 'x'),
				AggregationQuery::metricResponseKey('sum', null),
				AggregationQuery::metricResponseKey('sum', ''),
				AggregationQuery::metricResponseKey('sum', 'price'),
			],
			],
		];
	}

	/**
	 * @param callable(): mixed $read The read to record.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testRead(callable $read): void {
		$actual = $read();
		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}

	/**
	 * Pass a plain list groupBy: an accepted shape that create()'s declared type does not spell.
	 *
	 * @param mixed $groupBy The groupBy.
	 *
	 * @return array<string, mixed>
	 */
	private static function asGroupBy(mixed $groupBy): array {
		$typed = [];
		foreach ((array) $groupBy as $key => $value) {
			$typed[(string) $key] = $value;
		}

		return $typed;
	}

	/**
	 * @param AggregationQuery $query The query.
	 *
	 * @return array<string, mixed>
	 */
	private static function read(AggregationQuery $query): array {
		return [
			'metrics'       => $query->getMetrics(),
			'multiMetric'   => $query->isMultiMetric(),
			'grouped'       => $query->isGrouped(),
			'groupByField'  => $query->getGroupByField(),
			'groupByFields' => $query->getGroupByFields(),
			'multiField'    => $query->isMultiFieldGroupBy(),
			'dateBucket'    => $query->hasDateBucket(),
			'cumulative'    => $query->isCumulative(),
			'array'         => $query->toArray(),
		];
	}
}
