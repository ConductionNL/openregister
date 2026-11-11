<?php

/**
 * AggregationQueryShape — the normalised shapes of an aggregation request.
 *
 * How the groupBy, metrics entries and filter of a request are put in canonical form.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * Canonical forms of the parts of an aggregation request.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class AggregationQueryShape {

	/**
	 * Normalise any accepted groupBy shape to a flat, ordered candidate list.
	 *
	 * Shared canonicaliser used by both the value object and the runner so
	 * the single-field, multi-field, and plain-list shapes are honoured
	 * identically across the native-SQL and PHP-fallback paths. Returns the
	 * raw candidate values (which the caller validates) rather than filtering
	 * silently — dropping invalid entries here would reintroduce the very
	 * silent-ignore bug this feature fixes.
	 *
	 * @param mixed $groupBy The raw groupBy spec (array, list, or null).
	 *
	 * @return array<int, mixed> Ordered candidate field values.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public static function groupByFields(mixed $groupBy): array {
		if (is_array($groupBy) === false) {
			return [];
		}

		// Plain ordered list of field names: `['a', 'b']`.
		if (array_is_list($groupBy) === true) {
			return $groupBy;
		}

		// Multi-field cross-tab shape: `{fields: ['a', 'b']}`.
		if (is_array($groupBy['fields'] ?? null) === true) {
			return array_values($groupBy['fields']);
		}

		// Single-field shape: `{field: 'a'}`.
		if (array_key_exists('field', $groupBy) === true) {
			return [$groupBy['field']];
		}

		return [];
	}//end groupByFields()

	/**
	 * Normalise one `metrics` entry to `{metric, field}` plus its `condition` and `as`.
	 *
	 * Carry `condition` and `as` through. getMetrics() used to rebuild each
	 * entry as exactly {metric, field}, which silently DROPPED both before the
	 * runner ever saw them: a conditional metric came back as the
	 * unconditioned figure, and two aliased entries collided on one derived
	 * key. Neither failed — they just answered wrongly.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array{metric: string, field: ?string, condition?: array<mixed>, as?: string}
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public static function normaliseMetricEntry(array $entry): array {
		$normalised = ['metric' => (string) $entry['metric'], 'field' => self::fieldOrNull(field: ($entry['field'] ?? null))];

		$condition = ($entry['condition'] ?? null);
		if (is_array($condition) === true && $condition !== []) {
			$normalised['condition'] = $condition;
		}

		$alias = ($entry['as'] ?? null);
		if (is_string($alias) === true && $alias !== '') {
			$normalised['as'] = $alias;
		}

		return $normalised;
	}//end normaliseMetricEntry()

	/**
	 * An empty-string field reads as no field.
	 *
	 * @param mixed $field The entry's field.
	 *
	 * @return mixed
	 */
	private static function fieldOrNull(mixed $field): mixed {
		if ($field === '') {
			return null;
		}

		return $field;
	}//end fieldOrNull()

	/**
	 * Recursively ksort the filter map.
	 *
	 * Operator sub-arrays (e.g. `{gt: 5, lte: 10}`) get the same treatment
	 * so the resulting JSON encoding is stable across input orderings.
	 *
	 * @param array<string, mixed> $filter Filter map to canonicalise.
	 *
	 * @return array<string, mixed> Sorted filter map.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public static function canonicaliseFilter(array $filter): array {
		ksort($filter);
		foreach ($filter as $key => $value) {
			if (is_array($value) === true) {
				$sub = $value;
				ksort($sub);
				$filter[$key] = $sub;
			}
		}

		return $filter;
	}//end canonicaliseFilter()
}//end class
