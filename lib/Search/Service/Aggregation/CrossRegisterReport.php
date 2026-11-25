<?php

/**
 * CrossRegisterReport — one aggregation over several registers.
 *
 * Runs a count (or any one metric) on every register/schema pair that has a
 * magic table, discovered through `getAllRegisterSchemaPairs()`, and merges
 * the per-pair figures per register: a total with a `byRegister` breakdown,
 * or a time series with one series per register. Every pair goes through
 * the aggregation runner, so RBAC and multi-tenancy apply to each pair as
 * they do to a single-schema report. A pair the caller may not read, or
 * that no longer resolves, is left out without an error, so a register the
 * caller cannot see adds nothing and is not named.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-cross-register-reporting
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaPairsInterface;
use OCA\OpenRegister\Search\Controller\Support\AggregationControllerServices;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use RuntimeException;

/**
 * Merges per-pair aggregations into an organisation-wide report.
 */
class CrossRegisterReport {
	/**
	 * Constructor.
	 *
	 * @param AggregationControllerServices $services  Its runner runs each pair's aggregation, RBAC and
	 *                                                 multi-tenancy included.
	 * @param RegisterSchemaPairsInterface  $locator   Lists the register/schema pairs that hold objects.
	 * @param RegisterMapperInterface       $registers Names a register by its slug.
	 */
	public function __construct(
		private readonly AggregationControllerServices $services,
		private readonly RegisterSchemaPairsInterface $locator,
		private readonly RegisterMapperInterface $registers,
	) {
	}//end __construct()

	/**
	 * The metric over every readable pair, in total and per register.
	 *
	 * @param string             $metric       The metric: count, sum, min or max (an average of averages is not one).
	 * @param string|null        $field        The field the metric runs over; null for count.
	 * @param array<int, string> $registerRefs Register slugs or ids to cover; every register when empty.
	 *
	 * @return array{total: int|float, byRegister: list<array<string, string|int|float>>} Each register entry
	 *                                                                                   names the register and
	 *                                                                                   carries the figure under
	 *                                                                                   the metric's name.
	 *
	 * @throws InvalidArgumentException When the metric or field is not a valid aggregation, or is avg.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-cross-register-reporting
	 */
	public function totals(string $metric, ?string $field, array $registerRefs=[]): array {
		if ($metric === 'avg') {
			throw new InvalidArgumentException('avg cannot be combined across registers; ask for sum and count instead');
		}

		$query = AggregationQuery::create(metric: $metric, field: $field);
		$perRegister = [];
		foreach ($this->pairs(registerRefs: $registerRefs) as $pair) {
			$result = $this->runPair(pair: $pair, query: static fn (): AggregationQuery => $query);
			if ($result === null || is_numeric($result['value'] ?? null) === false) {
				continue;
			}

			$perRegister[$pair['register']][] = $result['value'] + 0;
		}

		$byRegister = [];
		$figures = [];
		foreach ($perRegister as $register => $values) {
			$value = self::combine(metric: $metric, values: $values);
			$byRegister[] = ['register' => (string)$register, $metric => $value];
			$figures[] = $value;
		}

		$total = self::combine(metric: $metric, values: $figures);

		return ['total' => $total, 'byRegister' => $byRegister];
	}//end totals()

	/**
	 * A count time series per register over the objects' creation date.
	 *
	 * Every series carries every bucket of the range, 0 where a register
	 * had nothing, so the series line up with the shared labels.
	 *
	 * @param array<int, string>              $registerRefs Register slugs or ids to cover; every register when empty.
	 * @param string                          $gap          The bucket width (day, week, month, quarter, year).
	 * @param array{from: string, to: string} $range        The ISO-8601 range, `to` exclusive.
	 * @param callable                        $queryFor     Builds the validated time-series query for a pair:
	 *                                                      fn (string $register, string $schema): AggregationQuery.
	 *
	 * @return array{labels: list<string>, series: list<array{name: string, data: list<int|float>}>}
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-cross-register-reporting
	 */
	public function series(array $registerRefs, string $gap, array $range, callable $queryFor): array {
		$labels = [];
		$perRegister = [];
		foreach ($this->pairs(registerRefs: $registerRefs) as $pair) {
			$result = $this->runPair(
				pair: $pair,
				query: static fn (): AggregationQuery => $queryFor((string)$pair['registerId'], (string)$pair['schemaId'])
			);
			if ($result === null) {
				continue;
			}

			$buckets = AggregateReportPeriods::fillGaps(
				groups: ($result['groups'] ?? []),
				gap: $gap,
				range: $range,
				metric: AggregationQuery::METRIC_COUNT
			);
			$perRegister[$pair['register']] ??= [];
			foreach ($buckets as $bucket) {
				$label = AggregateReportPeriods::label(key: (string)($bucket['key'] ?? ''), gap: $gap);
				$labels[$label] = true;
				$perRegister[$pair['register']][$label] = (($perRegister[$pair['register']][$label] ?? 0) + (($bucket['value'] ?? 0) + 0));
			}
		}//end foreach

		$labels = array_keys($labels);
		sort($labels);
		$series = [];
		foreach ($perRegister as $register => $values) {
			$data = [];
			foreach ($labels as $label) {
				$data[] = ($values[$label] ?? 0);
			}

			$series[] = ['name' => (string)$register, 'data' => $data];
		}

		return ['labels' => array_map('strval', $labels), 'series' => $series];
	}//end series()

	/**
	 * The pairs to aggregate, each with the register's slug as its name.
	 *
	 * @param array<int, string> $registerRefs Register slugs or ids; every register when empty.
	 *
	 * @return list<array{registerId: int, schemaId: int, register: string}>
	 */
	private function pairs(array $registerRefs): array {
		$slugs = $this->registers->getIdToSlugMap();
		$wanted = [];
		foreach ($registerRefs as $ref) {
			$ref = strtolower(trim((string)$ref));
			if ($ref !== '') {
				$wanted[] = $ref;
			}
		}

		$pairs = [];
		foreach ($this->locator->getAllRegisterSchemaPairs() as $pair) {
			$registerId = (int)($pair['registerId'] ?? 0);
			$name = (string)($slugs[$registerId] ?? $registerId);
			if ($wanted !== [] && in_array(strtolower($name), $wanted, true) === false && in_array((string)$registerId, $wanted, true) === false) {
				continue;
			}

			$pairs[] = ['registerId' => $registerId, 'schemaId' => (int)($pair['schemaId'] ?? 0), 'register' => $name];
		}

		return $pairs;
	}//end pairs()

	/**
	 * Run one pair, or null when the caller may not read it or it no longer resolves.
	 *
	 * @param array{registerId: int, schemaId: int, register: string} $pair  The pair.
	 * @param callable                                                $query Builds the pair's query.
	 *
	 * @return array<string, mixed>|null The runner's envelope.
	 */
	private function runPair(array $pair, callable $query): ?array {
		try {
			return $this->services->runner->runAdhocByRef(
				registerRef: (string)$pair['registerId'],
				schemaRef: (string)$pair['schemaId'],
				query: $query()
			);
		} catch (NotAuthorizedException | RuntimeException | InvalidArgumentException) {
			// Transparent RBAC: a pair the caller may not read adds nothing,
			// and neither does one whose schema or date field does not resolve.
			return null;
		}
	}//end runPair()

	/**
	 * One register's figure from its pairs' figures.
	 *
	 * Count and sum add up; min and max take the extreme.
	 *
	 * @param string                $metric The metric.
	 * @param array<int, int|float> $values The pair figures.
	 *
	 * @return int|float
	 */
	private static function combine(string $metric, array $values): int|float {
		if ($values === []) {
			return 0;
		}

		return match ($metric) {
			'min' => min($values),
			'max' => max($values),
			default => array_sum($values),
		};
	}//end combine()
}//end class
