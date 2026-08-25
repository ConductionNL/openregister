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
	private const VALID_METRICS = ['count', 'sum', 'avg', 'min', 'max', 'count_distinct'];

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
