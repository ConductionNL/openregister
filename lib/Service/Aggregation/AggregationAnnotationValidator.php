<?php

/**
 * OpenRegister AggregationAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-aggregations` annotation.
 * Returns a list of errors; empty = valid.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Validates the `x-openregister-aggregations` schema annotation shape.
 *
 * Each aggregation maps a name → spec object.  Two DSL variants are supported:
 *
 * Intra-schema (legacy + new alias):
 *   { metric|select, field?, filter|where?, groupBy?, join? }
 *   - `metric`/`select` MUST be one of count|sum|avg|min|max|count_distinct.
 *   - `field` is REQUIRED for sum|avg|min|max|count_distinct, MUST exist on the schema.
 *   - `groupBy` (when present) MUST name only declared schema properties. Three
 *     interchangeable spellings are accepted — see below.
 *   - `filter`/`where` is a flat map of field → value-or-operator-shape.
 *   - `join` (when present) attaches aggregates from a SECOND schema to each
 *     group — see {@see validateJoinSpec()}.
 *
 * Cross-schema (new):
 *   { from, metric|select?, field?, where|filter?, groupBy? }
 *   - `from` names a foreign schema slug.
 *   - `metric`/`select` defaults to `count` when omitted.
 *   - `where`/`filter` values may contain `@self.<field>` parent-references.
 *   - Field existence is **not** validated against the host schema's properties
 *     (the target schema is not available at annotation-save time).
 *
 * groupBy spellings (all three normalise to the SAME ordered field list via
 * {@see AggregationQuery::normaliseGroupByFields()}, which is the one
 * canonicaliser the RUNNER also uses — validator and executor MUST NOT own
 * separate copies of this grammar or they drift and a spec accepted at save
 * time silently groups on something else at run time):
 *   - single-field object: `{ "field": "status" }`         (legacy, still live)
 *   - multi-field object:  `{ "fields": ["a", "b"] }`
 *   - plain ordered list:  `[ "a", "b" ]`
 */
final class AggregationAnnotationValidator {

	private const VALID_METRICS = ['count', 'sum', 'avg', 'min', 'max', 'count_distinct'];

	private const REQUIRES_FIELD = ['sum', 'avg', 'min', 'max', 'count_distinct'];

	/**
	 * The `join` clause grammar, kept in its own class so this one does not
	 * accumulate a second DSL's worth of branching.
	 *
	 * @var AggregationJoinAnnotationValidator
	 */
	private AggregationJoinAnnotationValidator $joinValidator;

	/**
	 * Constructor.
	 *
	 * Plain `new` rather than injection: both classes are pure shape
	 * validators with no collaborators, and the schema-save call sites
	 * construct this validator directly.
	 */
	public function __construct() {
		$this->joinValidator = new AggregationJoinAnnotationValidator();
	}//end __construct()

	/**
	 * Validate the `x-openregister-aggregations` annotation on a schema.
	 *
	 * Cross-schema specs (those with a `from` key) are validated to the
	 * extent possible without loading the target schema:
	 *   - `from` must be a non-empty string.
	 *   - `metric`/`select` must be a known metric when present.
	 *   - `where`/`filter` must be a map when present.
	 *   - Field existence is skipped (target schema not available here).
	 *
	 * @param array<string, mixed> $schema Full schema definition (must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}> Validation error list.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-aggregations']) === false) {
			return [];
		}

		$aggregations = $schema['x-openregister-aggregations'];
		if (is_array($aggregations) === false || count($aggregations) === 0) {
			return [
				[
					'code' => 'aggregations-empty',
					'message' => 'x-openregister-aggregations must declare at least one aggregation.',
				],
			];
		}

		$properties = ($schema['properties'] ?? []);
		$propKeys = [];
		if (is_array($properties) === true) {
			$propKeys = array_keys($properties);
		}

		$errors = [];
		foreach ($aggregations as $name => $spec) {
			if (is_string($name) === false || $name === '') {
				$errors[] = [
					'code' => 'aggregation-bad-name',
					'message' => 'Aggregation names must be non-empty strings.',
				];
				continue;
			}

			if (is_array($spec) === false) {
				$errors[] = [
					'code' => 'aggregation-malformed',
					'message' => sprintf('Aggregation "%s" must be an object.', $name),
				];
				continue;
			}

			// Cross-schema aggregation: lighter validation (no property check).
			$fromRef = ($spec['from'] ?? null);
			if ($fromRef !== null) {
				$errors = array_merge(
					$errors,
					$this->validateCrossSchemaSpec(name: $name, spec: $spec)
				);
				continue;
			}

			// Intra-schema aggregation: full property-existence checks.
			// Support `select` as alias for `metric`. Non-scalar values (a
			// schema author passing an array) must not reach the string cast —
			// PHP emits "Array to string conversion" — so they collapse to ''
			// and fail the metric check with a proper validation error.
			// `metrics`: several figures over one grouping. Validated BEFORE the
			// single-metric check because a spec carrying `metrics` satisfies
			// the "what am I computing" question through that key instead, and
			// would otherwise be rejected for a missing `metric` it does not
			// need. Each entry is held to exactly the rules a single metric is:
			// a metric from the closed vocabulary, and a field that the schema
			// actually declares when the metric needs one. A `metrics` list the
			// runner cannot execute must fail here rather than at read time,
			// where it would surface as an empty envelope.
			$metricsSpec = ($spec['metrics'] ?? null);
			if ($metricsSpec !== null) {
				$metricsErrors = $this->validateMetricsList(
					name: $name,
					metrics: $metricsSpec,
					propKeys: $propKeys
				);
				$errors = array_merge($errors, $metricsErrors);
				if ($metricsErrors !== []) {
					continue;
				}

				$errors = array_merge(
					$errors,
					$this->validateGroupBy(name: $name, spec: $spec, propKeys: $propKeys)
				);
				continue;
			}

			$metric = ($spec['metric'] ?? $spec['select'] ?? '');
			if (is_scalar($metric) === false) {
				$metric = '';
			}

			$metric = (string)$metric;
			if (in_array($metric, self::VALID_METRICS, true) === false) {
				$errors[] = [
					'code' => 'aggregation-bad-metric',
					'message' => sprintf(
						'Aggregation "%s" metric "%s" is not in [%s].',
						$name,
						$metric,
						implode(', ', self::VALID_METRICS)
					),
				];
				continue;
			}

			if (in_array($metric, self::REQUIRES_FIELD, true) === true) {
				$field = (string)($spec['field'] ?? '');
				if ($field === '') {
					$errors[] = [
						'code' => 'aggregation-field-missing',
						'message' => sprintf('Aggregation "%s" with metric "%s" requires a field.', $name, $metric),
					];
				} elseif (in_array($field, $propKeys, true) === false) {
					$errors[] = [
						'code' => 'aggregation-field-not-in-schema',
						'message' => sprintf(
							'Aggregation "%s" field "%s" is not declared in the schema properties.',
							$name,
							$field
						),
					];
				}
			}

			// Support `where` as alias for `filter`.
			$filter = ($spec['filter'] ?? $spec['where'] ?? null);
			if ($filter !== null && is_array($filter) === false) {
				$errors[] = [
					'code' => 'aggregation-filter-malformed',
					'message' => sprintf('Aggregation "%s" filter/where must be a map.', $name),
				];
			} elseif (is_array($filter) === true) {
				foreach (array_keys($filter) as $filterField) {
					if (in_array((string)$filterField, $propKeys, true) === false) {
						$errors[] = [
							'code' => 'aggregation-filter-field-unknown',
							'message' => sprintf(
								'Aggregation "%s" filter references unknown field "%s".',
								$name,
								(string)$filterField
							),
						];
					}
				}
			}//end if

			$errors = array_merge(
				$errors,
				$this->validateGroupBy(name: $name, spec: $spec, propKeys: $propKeys)
			);

			$errors = array_merge(
				$errors,
				$this->joinValidator->validate(name: $name, spec: $spec, propKeys: $propKeys)
			);
		}//end foreach

		return $errors;
	}//end validate()

	/**
	 * Validate the `groupBy` clause of an intra-schema aggregation spec.
	 *
	 * Accepts all three spellings the runner accepts — `{field}`,
	 * `{fields: [...]}` and a plain ordered list — by delegating the shape
	 * question to {@see AggregationQuery::normaliseGroupByFields()}, the
	 * SAME canonicaliser {@see AggregationRunner::resolveGroupFields()}
	 * uses. Sharing the canonicaliser is deliberate: a validator that owns
	 * its own copy of the grammar accepts specs the executor then reads
	 * differently, and the disagreement is silent in both directions.
	 *
	 * Every named field MUST be a declared schema property — the same check
	 * the single-field form has always applied, now applied per member so a
	 * composite groupBy cannot smuggle in an undeclared column.
	 *
	 * @param string $name Aggregation name (for error messages).
	 * @param array<string, mixed> $spec The raw spec object.
	 * @param array<int, mixed> $propKeys Declared schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *   AggregationQuery::normaliseGroupByFields() is deliberately the ONE
	 *   canonicaliser shared by this validator, AggregationQuery and
	 *   AggregationRunner. Injecting it to satisfy the rule would invite a
	 *   second implementation, which is exactly the drift this sharing
	 *   prevents — an accepted-but-differently-executed groupBy is silent
	 *   in both directions.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateGroupBy(string $name, array $spec, array $propKeys): array {
		$groupBy = ($spec['groupBy'] ?? null);
		if ($groupBy === null) {
			return [];
		}

		if (is_array($groupBy) === false) {
			return [
				[
					'code' => 'aggregation-groupby-malformed',
					'message' => sprintf(
						'Aggregation "%s" groupBy must be {field, bucket?}, {fields: [...]} or a list of field names.',
						$name
					),
				],
			];
		}

		$groupFields = AggregationQuery::normaliseGroupByFields(groupBy: $groupBy);
		if (count($groupFields) === 0) {
			return [
				[
					'code' => 'aggregation-groupby-malformed',
					'message' => sprintf(
						'Aggregation "%s" groupBy must be {field, bucket?}, {fields: [...]} or a list of field names.',
						$name
					),
				],
			];
		}

		$errors = [];
		$seen = [];
		foreach ($groupFields as $groupField) {
			if (is_string($groupField) === false || $groupField === '') {
				$errors[] = [
					'code' => 'aggregation-groupby-malformed',
					'message' => sprintf(
						'Aggregation "%s" groupBy members must be non-empty field-name strings.',
						$name
					),
				];
				continue;
			}

			if (in_array($groupField, $seen, true) === true) {
				$errors[] = [
					'code' => 'aggregation-groupby-duplicate-field',
					'message' => sprintf(
						'Aggregation "%s" groupBy names "%s" more than once.',
						$name,
						$groupField
					),
				];
				continue;
			}

			$seen[] = $groupField;

			if (in_array($groupField, $propKeys, true) === false) {
				$errors[] = [
					'code' => 'aggregation-groupby-field-unknown',
					'message' => sprintf(
						'Aggregation "%s" groupBy.field "%s" is not declared in the schema properties.',
						$name,
						$groupField
					),
				];
			}
		}//end foreach

		return $errors;
	}//end validateGroupBy()

	/**
	 * Validate a cross-schema aggregation spec (`from` key present).
	 *
	 * @param string $name Aggregation name (for error messages).
	 * @param array<string, mixed> $spec The raw spec object.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 */
	private function validateCrossSchemaSpec(string $name, array $spec): array {
		$errors = [];

		$from = ($spec['from'] ?? null);
		if (is_string($from) === false || $from === '') {
			$errors[] = [
				'code' => 'aggregation-from-empty',
				'message' => sprintf('Cross-schema aggregation "%s" must have a non-empty `from` string.', $name),
			];
		}

		// `metric`/`select` defaults to `count` when omitted — only reject unknown non-empty values.
		$rawMetric = ($spec['metric'] ?? $spec['select'] ?? null);
		if ($rawMetric !== null) {
			$metric = (string)$rawMetric;
			if (in_array($metric, self::VALID_METRICS, true) === false) {
				$errors[] = [
					'code' => 'aggregation-bad-metric',
					'message' => sprintf(
						'Cross-schema aggregation "%s" metric "%s" is not in [%s].',
						$name,
						$metric,
						implode(', ', self::VALID_METRICS)
					),
				];
			}
		}

		// The where/filter clause must be a map when present.
		$filter = ($spec['where'] ?? $spec['filter'] ?? null);
		if ($filter !== null && is_array($filter) === false) {
			$errors[] = [
				'code' => 'aggregation-filter-malformed',
				'message' => sprintf('Cross-schema aggregation "%s" where/filter must be a map.', $name),
			];
		}

		// `from` + `join` would name THREE schemas in one spec (host, `from`
		// target, `join` target) with no declared relationship between the
		// second and third. Refuse at save time rather than ship a spec the
		// runner silently ignores half of: runCrossSchema() has no join
		// stage, so a `join` written beside a `from` would never execute.
		if (isset($spec['join']) === true) {
			$errors[] = [
				'code' => 'aggregation-join-with-from',
				'message' => sprintf(
					'Cross-schema aggregation "%s" must not combine `from` with `join` — join is intra-schema only.',
					$name
				),
			];
		}

		return $errors;
	}//end validateCrossSchemaSpec()

	/**
	 * Validate a `metrics` list — several figures over one grouping.
	 *
	 * Holds every entry to exactly the rules a single `metric` is held to: a
	 * metric drawn from the closed vocabulary, and a field the schema actually
	 * declares whenever the metric needs one. The point is that an entry the
	 * runner cannot execute fails HERE, at save time, rather than at read time
	 * where a rejected metric shows up as a missing figure in an otherwise
	 * well-formed envelope — indistinguishable from a genuine zero.
	 *
	 * A single-entry list is accepted rather than rewritten to `metric`: the
	 * runner treats `count($metrics) > 1` as the multi path and falls back to
	 * the scalar shape below that, so one entry behaves exactly like the
	 * single-metric spelling without the author having to know which to use.
	 *
	 * @param string             $name     The aggregation name, for messages.
	 * @param mixed              $metrics  The declared `metrics` value.
	 * @param array<int, string> $propKeys Property names the schema declares.
	 *
	 * @return array<int, array{code: string, message: string}> Validation errors.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateMetricsList(string $name, mixed $metrics, array $propKeys): array {
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
			if (is_array($entry) === false) {
				$errors[] = [
					'code' => 'aggregation-metrics-entry-malformed',
					'message' => sprintf('Aggregation "%s" metrics[%d] must be an object.', $name, $index),
				];
				continue;
			}

			$entryMetric = ($entry['metric'] ?? '');
			if (is_scalar($entryMetric) === false) {
				$entryMetric = '';
			}

			$entryMetric = (string)$entryMetric;
			if (in_array($entryMetric, self::VALID_METRICS, true) === false) {
				$errors[] = [
					'code' => 'aggregation-metrics-bad-metric',
					'message' => sprintf(
						'Aggregation "%s" metrics[%d] metric "%s" is not in [%s].',
						$name,
						$index,
						$entryMetric,
						implode(', ', self::VALID_METRICS)
					),
				];
				continue;
			}

			if (in_array($entryMetric, self::REQUIRES_FIELD, true) === false) {
				continue;
			}

			$entryField = ($entry['field'] ?? '');
			if (is_scalar($entryField) === false) {
				$entryField = '';
			}

			$entryField = (string)$entryField;
			if ($entryField === '') {
				$errors[] = [
					'code' => 'aggregation-metrics-field-missing',
					'message' => sprintf(
						'Aggregation "%s" metrics[%d] with metric "%s" requires a field.',
						$name,
						$index,
						$entryMetric
					),
				];
				continue;
			}

			if (in_array($entryField, $propKeys, true) === false) {
				$errors[] = [
					'code' => 'aggregation-metrics-field-not-in-schema',
					'message' => sprintf(
						'Aggregation "%s" metrics[%d] field "%s" is not declared in the schema properties.',
						$name,
						$index,
						$entryField
					),
				];
			}
		}//end foreach

		return $errors;
	}//end validateMetricsList()
}//end class
