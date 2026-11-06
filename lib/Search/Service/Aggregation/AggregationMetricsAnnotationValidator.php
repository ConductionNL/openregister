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

namespace OCA\OpenRegister\Search\Service\Aggregation;

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
	 * @param MetricsDeclaration $declaration The declared `metrics` value, its aggregation and schema properties.
	 *
	 * @return array<int, array{code: string, message: string}> Validation errors.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validate(MetricsDeclaration $declaration): array {
		$metrics = $declaration->metrics;
		if (is_array($metrics) === false || $metrics === [] || array_is_list($metrics) === false) {
			$message = sprintf('Aggregation "%s" metrics must be a non-empty list of {metric, field?} objects.', $declaration->name);
			return [['code' => 'aggregation-metrics-malformed', 'message' => $message]];
		}

		$errors = [];
		foreach ($metrics as $index => $entry) {
			$errors = array_merge($errors, $this->validateEntry(site: $declaration->siteAt(index: (int)$index), entry: $entry));
		}

		return $errors;
	}//end validate()

	/**
	 * Validate a single `metrics` entry.
	 *
	 * @param MetricEntrySite $site   Where the entry sits.
	 * @param mixed           $entry The declared entry.
	 *
	 * @return array<int, array{code: string, message: string}> Errors for this entry.
	 */
	private function validateEntry(MetricEntrySite $site, mixed $entry): array {
		if (is_array($entry) === false) {
			return $site->error(code: 'aggregation-metrics-entry-malformed', detail: ' must be an object.');
		}

		$metric = $this->asString(value: ($entry['metric'] ?? ''));
		if (in_array($metric, self::VALID_METRICS, true) === false) {
			$detail = sprintf(' metric "%s" is not in [%s].', $metric, implode(', ', self::VALID_METRICS));
			return $site->error(code: 'aggregation-metrics-bad-metric', detail: $detail);
		}

		if ($metric === self::METRIC_EXPRESSION) {
			return $this->validateExpression(site: $site, entry: $entry);
		}

		return $this->validateMeasured(site: $site, entry: $entry);
	}//end validateEntry()

	/**
	 * Validate a metric over rows: its alias and condition, then its field when it reads one.
	 *
	 * @param MetricEntrySite      $site   Where the entry sits.
	 * @param array<string, mixed> $entry The declared entry, with a known metric.
	 *
	 * @return array<int, array{code: string, message: string}> The first error, or none.
	 */
	private function validateMeasured(MetricEntrySite $site, array $entry): array {
		$extra = [...$this->validateAlias(site: $site, entry: $entry), ...$this->validateCondition(site: $site, entry: $entry)];
		if ($extra !== [] || in_array($entry['metric'], self::REQUIRES_FIELD, true) === false) {
			return array_slice($extra, 0, 1);
		}

		return $this->validateField(site: $site, entry: $entry);
	}//end validateMeasured()

	/**
	 * Validate a derived metric: it reads the figures beside it, so it has no
	 * field, and without `as` its value has no response key at all.
	 *
	 * @param MetricEntrySite      $site   Where the entry sits.
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return array<int, array{code: string, message: string}> The first error, or none.
	 */
	private function validateExpression(MetricEntrySite $site, array $entry): array {
		if (trim($this->asString(value: ($entry['expression'] ?? ''))) === '') {
			$detail = ' declares metric "expression" with no `expression` to evaluate.';
			return $site->error(code: 'aggregation-metrics-expression-empty', detail: $detail);
		}

		if (trim($this->asString(value: ($entry['as'] ?? ''))) === '') {
			$detail = ' is a derived metric and must declare `as`: it has no field or metric name to derive a response key from.';
			return $site->error(code: 'aggregation-metrics-expression-unnamed', detail: $detail);
		}

		if (isset($entry['field']) === true) {
			$detail = ' is a derived metric and takes no `field`: it reads the aliases of the metrics beside it, not the rows.';
			return $site->error(code: 'aggregation-metrics-expression-has-field', detail: $detail);
		}

		return [];
	}//end validateExpression()

	/**
	 * Validate the optional `as` alias.
	 *
	 * @param MetricEntrySite      $site   Where the entry sits.
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return array<int, array{code: string, message: string}> The error, or none.
	 */
	private function validateAlias(MetricEntrySite $site, array $entry): array {
		$alias = ($entry['as'] ?? null);
		if ($alias !== null && (is_string($alias) === false || $alias === '')) {
			return $site->error(code: 'aggregation-metrics-alias-malformed', detail: ' "as" must be a non-empty string.');
		}

		return [];
	}//end validateAlias()

	/**
	 * Validate the optional `condition`: a non-empty filter object on declared properties.
	 *
	 * @param MetricEntrySite      $site   Where the entry sits.
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return array<int, array{code: string, message: string}> The first error, or none.
	 */
	private function validateCondition(MetricEntrySite $site, array $entry): array {
		$condition = ($entry['condition'] ?? null);
		if ($condition === null) {
			return [];
		}

		if (is_array($condition) === false || $condition === []) {
			$detail = ' "condition" must be a non-empty filter object, '
				. 'the same shape as the aggregation\'s own filter — not a string expression.';
			return $site->error(code: 'aggregation-metrics-condition-malformed', detail: $detail);
		}

		return $this->validateConditionProperties(site: $site, condition: $condition);
	}//end validateCondition()

	/**
	 * Validate that a condition filters only on declared properties.
	 *
	 * @param MetricEntrySite      $site       Where the entry sits.
	 * @param array<string, mixed> $condition The declared condition.
	 *
	 * @return array<int, array{code: string, message: string}> The error for the first undeclared property, or none.
	 */
	private function validateConditionProperties(MetricEntrySite $site, array $condition): array {
		foreach (array_keys($condition) as $prop) {
			if ($site->declares(prop: (string)$prop) === false) {
				$detail = sprintf(' condition property "%s" is not declared in the schema properties. ', (string)$prop)
					. 'A filter on an undeclared property matches nothing and returns an empty result rather than an error.';
				return $site->error(code: 'aggregation-metrics-condition-not-in-schema', detail: $detail);
			}
		}

		return [];
	}//end validateConditionProperties()

	/**
	 * Validate the field a field-bound metric reads.
	 *
	 * @param MetricEntrySite      $site   Where the entry sits.
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return array<int, array{code: string, message: string}> The error, or none.
	 */
	private function validateField(MetricEntrySite $site, array $entry): array {
		$field = $this->asString(value: ($entry['field'] ?? ''));
		if ($field === '') {
			$detail = sprintf(' with metric "%s" requires a field.', $this->asString(value: $entry['metric']));
			return $site->error(code: 'aggregation-metrics-field-missing', detail: $detail);
		}

		if ($site->declares(prop: $field) === false) {
			$detail = sprintf(' field "%s" is not declared in the schema properties.', $field);
			return $site->error(code: 'aggregation-metrics-field-not-in-schema', detail: $detail);
		}

		return [];
	}//end validateField()

	/**
	 * Coerce a scalar to string; anything else becomes the empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function asString(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end asString()
}//end class
