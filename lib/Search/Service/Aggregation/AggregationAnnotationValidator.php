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

namespace OCA\OpenRegister\Search\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationRunner;

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

	public const VALID_METRICS = ['count', 'sum', 'avg', 'min', 'max', 'count_distinct'];

	private const REQUIRES_FIELD = ['sum', 'avg', 'min', 'max', 'count_distinct'];

	/**
	 * Error code and message format per failure, keyed by a short name.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const FAILURES = [
		'field-missing' => ['aggregation-field-missing', 'Aggregation "%s" with metric "%s" requires a field.'],
		'field-not-in-schema' => ['aggregation-field-not-in-schema', 'Aggregation "%s" field "%s" is not declared in the schema properties.'],
		'filter-malformed' => ['aggregation-filter-malformed', 'Aggregation "%s" filter/where must be a map.'],
		'filter-field-unknown' => ['aggregation-filter-field-unknown', 'Aggregation "%s" filter references unknown field "%s".'],
		'groupby-shape' => [
			'aggregation-groupby-malformed',
			'Aggregation "%s" groupBy must be {field, bucket?}, {fields: [...]} or a list of field names.',
		],
		'groupby-member' => ['aggregation-groupby-malformed', 'Aggregation "%s" groupBy members must be non-empty field-name strings.'],
		'groupby-duplicate' => ['aggregation-groupby-duplicate-field', 'Aggregation "%s" groupBy names "%s" more than once.'],
		'groupby-unknown' => ['aggregation-groupby-field-unknown', 'Aggregation "%s" groupBy.field "%s" is not declared in the schema properties.'],
	];

	/**
	 * The `join` clause grammar, kept in its own class so this one does not
	 * accumulate a second DSL's worth of branching.
	 *
	 * @var AggregationJoinAnnotationValidator
	 */
	private AggregationJoinAnnotationValidator $joinValidator;

	/**
	 * Validator for the `metrics` clause.
	 *
	 * Split out for the same reason as the join validator: the per-entry
	 * checks are a small decision tree, and inlining them pushed this class
	 * past its complexity threshold.
	 *
	 * @var AggregationMetricsAnnotationValidator
	 */
	private AggregationMetricsAnnotationValidator $metricsValidator;

	/**
	 * Validator for cross-schema entries (`from` set), split out for the
	 * same reason as the other two.
	 *
	 * @var CrossSchemaAggregationValidator
	 */
	private CrossSchemaAggregationValidator $crossSchemaValidator;

	/**
	 * Constructor.
	 *
	 * Plain `new` rather than injection: all three classes are pure shape
	 * validators with no collaborators, and the schema-save call sites
	 * construct this validator directly.
	 */
	public function __construct() {
		$this->joinValidator = new AggregationJoinAnnotationValidator();
		$this->metricsValidator = new AggregationMetricsAnnotationValidator();
		$this->crossSchemaValidator = new CrossSchemaAggregationValidator();
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
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-aggregations']) === false) {
			return [];
		}

		$properties = ($schema['properties'] ?? []);
		$propKeys = [];
		if (is_array($properties) === true) {
			$propKeys = array_keys($properties);
		}

		return $this->validateAggregations(aggregations: $schema['x-openregister-aggregations'], propKeys: $propKeys);
	}//end validate()

	/**
	 * Validate every entry of the annotation, in declaration order.
	 *
	 * @param mixed             $aggregations The annotation value.
	 * @param array<int, mixed> $propKeys     Declared schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Validation error list.
	 */
	private function validateAggregations(mixed $aggregations, array $propKeys): array {
		if (is_array($aggregations) === false || count($aggregations) === 0) {
			return [$this->error(code: 'aggregations-empty', message: 'x-openregister-aggregations must declare at least one aggregation.')];
		}

		$errors = [];
		foreach ($aggregations as $name => $spec) {
			$shapeError = $this->entryShapeError(name: $name, spec: $spec);
			$entryErrors = [$shapeError];
			if ($shapeError === null) {
				$entryErrors = $this->validateEntry(entry: new AggregationSpecEntry(name: $name, spec: $spec), propKeys: $propKeys);
			}
			$errors = array_merge($errors, $entryErrors);
		}

		return $errors;
	}//end validateAggregations()

	/**
	 * The error for an entry whose name is not a non-empty string or whose
	 * spec is not an object; null when both are well formed.
	 *
	 * @param mixed $name The entry key.
	 * @param mixed $spec The entry value.
	 *
	 * @return array{code: string, message: string}|null
	 */
	private function entryShapeError(mixed $name, mixed $spec): ?array {
		if (is_string($name) === false || $name === '') {
			return $this->error(code: 'aggregation-bad-name', message: 'Aggregation names must be non-empty strings.');
		}

		if (is_array($spec) === false) {
			return $this->error(code: 'aggregation-malformed', message: sprintf('Aggregation "%s" must be an object.', $name));
		}

		return null;
	}//end entryShapeError()

	/**
	 * Validate one well-formed entry.
	 *
	 * A cross-schema entry (`from` set) gets the lighter checks, since the
	 * target schema is not available here. A `metrics` entry is validated
	 * through that key instead of `metric`, and would otherwise be rejected
	 * for a `metric` it does not need.
	 *
	 * @param AggregationSpecEntry $entry    The entry.
	 * @param array<int, mixed>    $propKeys Declared schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 */
	private function validateEntry(AggregationSpecEntry $entry, array $propKeys): array {
		if (($entry->spec['from'] ?? null) !== null) {
			return $this->crossSchemaValidator->validate(entry: $entry);
		}

		if (($entry->spec['metrics'] ?? null) !== null) {
			return $this->validateMultiMetricEntry(entry: $entry, propKeys: $propKeys);
		}

		return $this->validateSingleMetricEntry(entry: $entry, propKeys: $propKeys);
	}//end validateEntry()

	/**
	 * Validate an entry with a `metrics` list: each metric is held to the
	 * single-metric rules, and the grouping is checked only when they pass.
	 *
	 * @param AggregationSpecEntry $entry    The entry.
	 * @param array<int, mixed>    $propKeys Declared schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 */
	private function validateMultiMetricEntry(AggregationSpecEntry $entry, array $propKeys): array {
		$metricsErrors = $this->metricsValidator->validate(
			declaration: new MetricsDeclaration(name: $entry->name, metrics: $entry->spec['metrics'], propKeys: $propKeys)
		);
		if ($metricsErrors !== []) {
			return $metricsErrors;
		}

		return $this->validateGroupBy(entry: $entry, propKeys: $propKeys);
	}//end validateMultiMetricEntry()

	/**
	 * Validate an intra-schema entry with one `metric` (or `select`): the
	 * metric, its field, the filter, the grouping and the join.
	 *
	 * @param AggregationSpecEntry $entry    The entry.
	 * @param array<int, mixed>    $propKeys Declared schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 */
	private function validateSingleMetricEntry(AggregationSpecEntry $entry, array $propKeys): array {
		$metric = $this->metricName(spec: $entry->spec);
		if (in_array($metric, self::VALID_METRICS, true) === false) {
			$message = sprintf('Aggregation "%s" metric "%s" is not in [%s].', $entry->name, $metric, implode(', ', self::VALID_METRICS));
			return [$this->error(code: 'aggregation-bad-metric', message: $message)];
		}

		return array_merge(
			$this->validateMetricField(entry: $entry, propKeys: $propKeys),
			$this->validateFilter(entry: $entry, propKeys: $propKeys),
			$this->validateGroupBy(entry: $entry, propKeys: $propKeys),
			$this->joinValidator->validate(name: $entry->name, spec: $entry->spec, propKeys: $propKeys)
		);
	}//end validateSingleMetricEntry()

	/**
	 * The metric an entry names (`metric`, else `select`). A non-scalar value
	 * becomes '' so it fails the metric check instead of the string cast.
	 *
	 * @param array<string, mixed> $spec The raw spec object.
	 *
	 * @return string
	 */
	private function metricName(array $spec): string {
		$metric = ($spec['metric'] ?? $spec['select'] ?? '');
		if (is_scalar($metric) === false) {
			return '';
		}

		return (string) $metric;
	}//end metricName()

	/**
	 * A metric other than `count` needs a field the schema declares.
	 *
	 * @param AggregationSpecEntry $entry    The entry.
	 * @param array<int, mixed>    $propKeys Declared schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 */
	private function validateMetricField(AggregationSpecEntry $entry, array $propKeys): array {
		$metric = $this->metricName(spec: $entry->spec);
		if (in_array($metric, self::REQUIRES_FIELD, true) === false) {
			return [];
		}

		$field = (string) ($entry->spec['field'] ?? '');
		if ($field === '') {
			return [$this->failed(key: 'field-missing', args: [$entry->name, $metric])];
		}

		if (in_array($field, $propKeys, true) === true) {
			return [];
		}

		return [$this->failed(key: 'field-not-in-schema', args: [$entry->name, $field])];
	}//end validateMetricField()

	/**
	 * The `filter` (or `where`) must be a map over declared fields.
	 *
	 * @param AggregationSpecEntry $entry    The entry.
	 * @param array<int, mixed>    $propKeys Declared schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 */
	private function validateFilter(AggregationSpecEntry $entry, array $propKeys): array {
		$filter = ($entry->spec['filter'] ?? $entry->spec['where'] ?? null);
		if ($filter === null) {
			return [];
		}

		if (is_array($filter) === false) {
			return [$this->failed(key: 'filter-malformed', args: [$entry->name])];
		}

		return array_map(
			fn (string $field): array => $this->failed(key: 'filter-field-unknown', args: [$entry->name, $field]),
			$this->unknownFields(fields: array_keys($filter), propKeys: $propKeys)
		);
	}//end validateFilter()

	/**
	 * The given field names, cast to string, that the schema does not declare.
	 *
	 * @param array<int, int|string> $fields   Field names to check.
	 * @param array<int, mixed>      $propKeys Declared schema property names.
	 *
	 * @return array<int, string> The undeclared names, in input order.
	 */
	private function unknownFields(array $fields, array $propKeys): array {
		$names = array_map(static fn (int|string $field): string => (string) $field, $fields);
		return array_values(
			array_filter($names, static fn (string $field): bool => in_array($field, $propKeys, true) === false)
		);
	}//end unknownFields()

	/**
	 * One validation error.
	 *
	 * @param string $code    The error code.
	 * @param string $message The error message.
	 *
	 * @return array{code: string, message: string}
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()

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
		return $this->error(code: $failure[0], message: sprintf($failure[1], ...$args));
	}//end failed()

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
	 * normaliseGroupByFields() returns [] for a non-array groupBy, so that
	 * case shares the malformed-shape error.
	 *
	 * @param AggregationSpecEntry $entry    The entry.
	 * @param array<int, mixed>    $propKeys Declared schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateGroupBy(AggregationSpecEntry $entry, array $propKeys): array {
		if (($entry->spec['groupBy'] ?? null) === null) {
			return [];
		}

		$members = $this->groupMembers(entry: $entry, propKeys: $propKeys);
		if ($members === []) {
			return [$this->failed(key: 'groupby-shape', args: [$entry->name])];
		}

		return array_merge(...array_map(fn (GroupByMember $member): array => $this->groupMemberErrors(member: $member), $members));
	}//end validateGroupBy()

	/**
	 * The members of the entry's groupBy, in order, each with the members
	 * listed before it.
	 *
	 * @param AggregationSpecEntry $entry    The entry (its groupBy is set).
	 * @param array<int, mixed>    $propKeys Declared schema property names.
	 *
	 * @return array<int, GroupByMember>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *   AggregationQuery::normaliseGroupByFields() is deliberately the ONE
	 *   canonicaliser shared by this validator, AggregationQuery and
	 *   AggregationRunner. Injecting it to satisfy the rule would invite a
	 *   second implementation, which is exactly the drift this sharing
	 *   prevents — an accepted-but-differently-executed groupBy is silent
	 *   in both directions.
	 */
	private function groupMembers(AggregationSpecEntry $entry, array $propKeys): array {
		$groupFields = AggregationQuery::normaliseGroupByFields(groupBy: $entry->spec['groupBy']);
		return array_map(
			fn (int $index): GroupByMember => new GroupByMember(
				name: $entry->name,
				field: $groupFields[$index],
				earlier: array_slice($groupFields, 0, $index),
				propKeys: $propKeys
			),
			array_keys($groupFields)
		);
	}//end groupMembers()

	/**
	 * The error for one groupBy member, if any: it must be a non-empty string,
	 * named only once, and declared on the schema — checked in that order.
	 *
	 * @param GroupByMember $member The member with its context.
	 *
	 * @return array<int, array{code: string, message: string}> At most one error.
	 */
	private function groupMemberErrors(GroupByMember $member): array {
		if (is_string($member->field) === false || $member->field === '') {
			return [$this->failed(key: 'groupby-member', args: [$member->name])];
		}

		if (in_array($member->field, $member->earlier, true) === true) {
			return [$this->failed(key: 'groupby-duplicate', args: [$member->name, $member->field])];
		}

		if (in_array($member->field, $member->propKeys, true) === false) {
			return [$this->failed(key: 'groupby-unknown', args: [$member->name, $member->field])];
		}

		return [];
	}//end groupMemberErrors()

}//end class
