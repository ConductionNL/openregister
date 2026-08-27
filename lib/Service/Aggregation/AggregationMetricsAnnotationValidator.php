<?php

/**
 * OpenRegister AggregationMetricsAnnotationValidator
 *
 * Schema-save validation for the `metrics` clause of an entry in the
 * `x-openregister-aggregations` annotation — several figures over ONE
 * grouping, e.g. a budget line needing sum(committed) AND sum(invoiced) per
 * coding combination.
 *
 * WHY VALIDATION HERE MATTERS MORE THAN IT LOOKS. A `metrics` entry the runner
 * cannot execute does not throw at read time: it comes back as a missing
 * figure inside an otherwise well-formed envelope, which is indistinguishable
 * from a genuine zero. Refusing it at SAVE time is the only point at which the
 * author can still be told.
 *
 * Each entry is held to exactly the rules a single `metric` is — a metric from
 * the closed vocabulary, and a field the schema actually declares whenever the
 * metric needs one — so the two spellings cannot diverge in what they accept.
 *
 * Lives in its own class rather than as a method on
 * {@see AggregationAnnotationValidator}, mirroring
 * {@see AggregationJoinAnnotationValidator}: the per-entry checks are a small
 * decision tree, and inlining them pushed that class past its complexity
 * threshold. Splitting keeps each validator readable and independently
 * testable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Validates the `metrics` list of an aggregation annotation.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class AggregationMetricsAnnotationValidator {

	/**
	 * Metrics the aggregation engine can execute.
	 *
	 * @var array<int, string>
	 */
	private const VALID_METRICS = ['count', 'sum', 'avg', 'min', 'max', 'count_distinct', 'expression'];

	/**
	 * The DERIVED metric: arithmetic over the aliases of the metrics beside it,
	 * rather than an aggregate over rows.
	 *
	 * It takes no `field` — there is nothing to aggregate — and it MUST carry
	 * both an `expression` and an `as`, because its value has no metric+field
	 * pair to derive a response key from.
	 *
	 * @var string
	 */
	private const METRIC_EXPRESSION = 'expression';

	/**
	 * Metrics that are meaningless without a field to aggregate.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRES_FIELD = ['sum', 'avg', 'min', 'max', 'count_distinct'];

	/**
	 * Validate a declared `metrics` list.
	 *
	 * Reports EVERY bad entry rather than stopping at the first: a validator
	 * that short-circuits turns one fix-and-retry cycle into several, and each
	 * message names the entry's index because "some field is wrong" is not
	 * actionable across a list.
	 *
	 * @param string             $name     The aggregation name, for messages.
	 * @param mixed              $metrics  The declared `metrics` value.
	 * @param array<int, string> $propKeys Property names the schema declares.
	 *
	 * @return array<int, array{code: string, message: string}> Validation errors.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validate(string $name, mixed $metrics, array $propKeys): array {
		if (is_array($metrics) === false || $metrics === [] || array_is_list($metrics) === false) {
			return [
				[
					'code' => 'aggregation-metrics-malformed',
					'message' => sprintf(
						'Aggregation "%s" metrics must be a non-empty list of {metric, field?} objects.',
						$name
					),
				],
			];
		}

		$errors = [];
		foreach ($metrics as $index => $entry) {
			$entryErrors = $this->validateEntry(
				name: $name,
				index: (int)$index,
				entry: $entry,
				propKeys: $propKeys
			);
			$errors = array_merge($errors, $entryErrors);
		}

		return $errors;
	}//end validate()

	/**
	 * Validate one `{metric, field?}` entry.
	 *
	 * @param string             $name     The aggregation name, for messages.
	 * @param int                $index    The entry's position in the list.
	 * @param mixed              $entry    The entry itself.
	 * @param array<int, string> $propKeys Property names the schema declares.
	 *
	 * @return array<int, array{code: string, message: string}> Validation errors.
	 */
	private function validateEntry(string $name, int $index, mixed $entry, array $propKeys): array {
		if (is_array($entry) === false) {
			return [
				[
					'code' => 'aggregation-metrics-entry-malformed',
					'message' => sprintf('Aggregation "%s" metrics[%d] must be an object.', $name, $index),
				],
			];
		}

		$metric = $this->asString(value: ($entry['metric'] ?? ''));
		if (in_array($metric, self::VALID_METRICS, true) === false) {
			return [
				[
					'code' => 'aggregation-metrics-bad-metric',
					'message' => sprintf(
						'Aggregation "%s" metrics[%d] metric "%s" is not in [%s].',
						$name,
						$index,
						$metric,
						implode(', ', self::VALID_METRICS)
					),
				],
			];
		}

		if ($metric === self::METRIC_EXPRESSION) {
			// A derived metric is validated on different terms: it reads the
			// figures beside it, so it has no field, and without `as` its value
			// has no response key at all.
			$expression = $this->asString(value: ($entry['expression'] ?? ''));
			if (trim($expression) === '') {
				return [
					[
						'code' => 'aggregation-metrics-expression-empty',
						'message' => sprintf(
							'Aggregation "%s" metrics[%d] declares metric "expression" with no '
							.'`expression` to evaluate.',
							$name,
							$index
						),
					],
				];
			}

			$alias = $this->asString(value: ($entry['as'] ?? ''));
			if (trim($alias) === '') {
				return [
					[
						'code' => 'aggregation-metrics-expression-unnamed',
						'message' => sprintf(
							'Aggregation "%s" metrics[%d] is a derived metric and must declare `as`: '
							.'it has no field or metric name to derive a response key from.',
							$name,
							$index
						),
					],
				];
			}

			if (isset($entry['field']) === true) {
				return [
					[
						'code' => 'aggregation-metrics-expression-has-field',
						'message' => sprintf(
							'Aggregation "%s" metrics[%d] is a derived metric and takes no `field`: '
							.'it reads the aliases of the metrics beside it, not the rows.',
							$name,
							$index
						),
					],
				];
			}

			return [];
		}

		$extra = $this->validateConditionAndAlias(
			name: $name,
			index: $index,
			entry: $entry,
			propKeys: $propKeys
		);
		if ($extra !== []) {
			return $extra;
		}

		if (in_array($metric, self::REQUIRES_FIELD, true) === false) {
			return [];
		}

		return $this->validateField(
			name: $name,
			index: $index,
			metric: $metric,
			field: $this->asString(value: ($entry['field'] ?? '')),
			propKeys: $propKeys
		);
	}//end validateEntry()

	/**
	 * Validate a metric entry's optional `condition` and `as`.
	 *
	 * `condition` scopes ONE metric to a subset of the grouped rows — the
	 * debit/credit split. It is a filter object, the same shape as the
	 * aggregation's own `filter`, and its keys are checked against the schema
	 * here for the reason every filter needs checking in this stack: a filter
	 * naming a property the schema does not declare is not an error at run
	 * time, it matches nothing and returns an empty set with HTTP 200. Caught
	 * at declaration time it is a typo; caught at run time it is a page showing
	 * "no data" over live rows.
	 *
	 * `as` names the response key. It is required in practice whenever two
	 * entries share a metric+field pair — two conditional sums over `amount`
	 * both derive `sum_amount`, and the second would overwrite the first.
	 *
	 * @param string             $name     The aggregation name, for messages.
	 * @param int                $index    The entry's position in the list.
	 * @param array<mixed>       $entry    The metric entry.
	 * @param array<int, string> $propKeys Property names the schema declares.
	 *
	 * @return array<int, array{code: string, message: string}> Validation errors.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateConditionAndAlias(
		string $name,
		int $index,
		array $entry,
		array $propKeys,
	): array {
		$alias = ($entry['as'] ?? null);
		if ($alias !== null && (is_string($alias) === false || $alias === '')) {
			return [
				[
					'code' => 'aggregation-metrics-alias-malformed',
					'message' => sprintf(
						'Aggregation "%s" metrics[%d] "as" must be a non-empty string.',
						$name,
						$index
					),
				],
			];
		}

		$condition = ($entry['condition'] ?? null);
		if ($condition === null) {
			return [];
		}

		if (is_array($condition) === false || $condition === []) {
			return [
				[
					'code' => 'aggregation-metrics-condition-malformed',
					'message' => sprintf(
						'Aggregation "%s" metrics[%d] "condition" must be a non-empty filter object, '
						. 'the same shape as the aggregation\'s own filter — not a string expression.',
						$name,
						$index
					),
				],
			];
		}

		foreach (array_keys($condition) as $prop) {
			if (in_array((string)$prop, $propKeys, true) === false) {
				return [
					[
						'code' => 'aggregation-metrics-condition-not-in-schema',
						'message' => sprintf(
							'Aggregation "%s" metrics[%d] condition property "%s" is not declared in the '
							. 'schema properties. A filter on an undeclared property matches nothing and '
							. 'returns an empty result rather than an error.',
							$name,
							$index,
							(string)$prop
						),
					],
				];
			}
		}

		return [];
	}//end validateConditionAndAlias()

	/**
	 * Validate the field of a metric that requires one.
	 *
	 * @param string             $name     The aggregation name, for messages.
	 * @param int                $index    The entry's position in the list.
	 * @param string             $metric   The entry's metric.
	 * @param string             $field    The entry's field.
	 * @param array<int, string> $propKeys Property names the schema declares.
	 *
	 * @return array<int, array{code: string, message: string}> Validation errors.
	 */
	private function validateField(
		string $name,
		int $index,
		string $metric,
		string $field,
		array $propKeys,
	): array {
		if ($field === '') {
			return [
				[
					'code' => 'aggregation-metrics-field-missing',
					'message' => sprintf(
						'Aggregation "%s" metrics[%d] with metric "%s" requires a field.',
						$name,
						$index,
						$metric
					),
				],
			];
		}

		if (in_array($field, $propKeys, true) === false) {
			return [
				[
					'code' => 'aggregation-metrics-field-not-in-schema',
					'message' => sprintf(
						'Aggregation "%s" metrics[%d] field "%s" is not declared in the schema properties.',
						$name,
						$index,
						$field
					),
				],
			];
		}

		return [];
	}//end validateField()

	/**
	 * Coerce a declared value to a string without tripping on arrays.
	 *
	 * A schema author passing an array must not reach a bare string cast —
	 * PHP emits "Array to string conversion" — so it collapses to '' and fails
	 * the vocabulary check with a proper validation error instead.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string The value as a string, or '' when it is not scalar.
	 */
	private function asString(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end asString()
}//end class
