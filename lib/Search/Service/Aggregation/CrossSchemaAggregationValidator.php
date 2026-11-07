<?php

/**
 * OpenRegister CrossSchemaAggregationValidator
 *
 * Schema-save validation for a cross-schema entry (one with a `from` key)
 * of the `x-openregister-aggregations` annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * Validates a cross-schema aggregation entry to the extent possible without
 * loading the target schema: `from` must be a non-empty string, a
 * `metric`/`select` must be known when present, `where`/`filter` must be a
 * map when present, and `join` must be absent. Field existence is not
 * checked (the target schema is not available at annotation-save time).
 */
final class CrossSchemaAggregationValidator {

	/**
	 * Error code and message format per failure, keyed by a short name.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const FAILURES = [
		'from' => ['aggregation-from-empty', 'Cross-schema aggregation "%s" must have a non-empty `from` string.'],
		'metric' => ['aggregation-bad-metric', 'Cross-schema aggregation "%s" metric "%s" is not in [%s].'],
		'filter' => ['aggregation-filter-malformed', 'Cross-schema aggregation "%s" where/filter must be a map.'],
		'join' => [
			'aggregation-join-with-from',
			'Cross-schema aggregation "%s" must not combine `from` with `join` — join is intra-schema only.',
		],
	];

	/**
	 * Validate a cross-schema aggregation spec (`from` key present).
	 *
	 * @param AggregationSpecEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 */
	public function validate(AggregationSpecEntry $entry): array {
		return array_merge(
			$this->crossSchemaFromErrors(entry: $entry),
			$this->crossSchemaMetricErrors(entry: $entry),
			$this->crossSchemaFilterErrors(entry: $entry),
			$this->crossSchemaJoinErrors(entry: $entry)
		);
	}//end validate()

	/**
	 * A cross-schema entry needs a non-empty `from` string.
	 *
	 * @param AggregationSpecEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> At most one error.
	 */
	private function crossSchemaFromErrors(AggregationSpecEntry $entry): array {
		$from = ($entry->spec['from'] ?? null);
		if (is_string($from) === true && $from !== '') {
			return [];
		}

		return [$this->failed(key: 'from', args: [$entry->name])];
	}//end crossSchemaFromErrors()

	/**
	 * `metric`/`select` defaults to `count` when omitted — only reject unknown
	 * non-null values.
	 *
	 * @param AggregationSpecEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> At most one error.
	 */
	private function crossSchemaMetricErrors(AggregationSpecEntry $entry): array {
		$rawMetric = ($entry->spec['metric'] ?? $entry->spec['select'] ?? null);
		if ($rawMetric === null) {
			return [];
		}

		$metric = (string) $rawMetric;
		if (in_array($metric, AggregationAnnotationValidator::VALID_METRICS, true) === true) {
			return [];
		}

		return [$this->failed(key: 'metric', args: [$entry->name, $metric, implode(', ', AggregationAnnotationValidator::VALID_METRICS)])];
	}//end crossSchemaMetricErrors()

	/**
	 * The where/filter clause must be a map when present.
	 *
	 * @param AggregationSpecEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> At most one error.
	 */
	private function crossSchemaFilterErrors(AggregationSpecEntry $entry): array {
		$filter = ($entry->spec['where'] ?? $entry->spec['filter'] ?? null);
		if ($filter === null || is_array($filter) === true) {
			return [];
		}

		return [$this->failed(key: 'filter', args: [$entry->name])];
	}//end crossSchemaFilterErrors()

	/**
	 * `from` + `join` would name THREE schemas in one spec (host, `from`
	 * target, `join` target) with no declared relationship between the
	 * second and third. Refuse at save time rather than ship a spec the
	 * runner silently ignores half of: runCrossSchema() has no join stage,
	 * so a `join` written beside a `from` would never execute.
	 *
	 * @param AggregationSpecEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> At most one error.
	 */
	private function crossSchemaJoinErrors(AggregationSpecEntry $entry): array {
		if (isset($entry->spec['join']) === false) {
			return [];
		}

		return [$this->failed(key: 'join', args: [$entry->name])];
	}//end crossSchemaJoinErrors()

	/**
	 * One validation error from the FAILURES table.
	 *
	 * @param string            $key  The FAILURES key.
	 * @param array<int, mixed> $args The sprintf arguments for its message.
	 *
	 * @return array{code: string, message: string}
	 */
	private function failed(string $key, array $args): array {
		$failure = self::FAILURES[$key];
		return ['code' => $failure[0], 'message' => sprintf($failure[1], ...$args)];
	}//end failed()
}//end class
