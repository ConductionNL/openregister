<?php

/**
 * OpenRegister AggregationJoinAnnotationValidator
 *
 * Schema-save validation for the `join` clause of an entry in the
 * `x-openregister-aggregations` annotation.
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
 * Validates the `join` clause of an aggregation spec.
 *
 * Shape:
 *   "join": {
 *     "through": "CommitmentBudget",                     // REQUIRED, joined schema ref
 *     "on":      "CommitmentBudget.programmeCode",       // REQUIRED, string or map
 *     "filter":  { },                                    // optional, map
 *     "select":  ["CommitmentBudget.authorised_amount"]  // REQUIRED, non-empty list
 *   }
 *
 * `on` accepts either an explicit `{parentField: joinedField}` map — the
 * unambiguous spelling, and the only one that can express a COMPOSITE join
 * key — or a single string. As a string it may be written `"Through.column"`
 * or bare `"column"`; the JOINED side is always the column named, and the
 * PARENT side is resolved by `AggregationRunner::resolveJoinKey()` — the
 * same-named group field when one exists, otherwise the FIRST group field.
 *
 * DELIBERATELY NOT VALIDATED HERE: the existence of `through`, and the
 * `on`/`select` columns on the JOINED side. The joined schema is not
 * loadable at annotation-save time, exactly as for the cross-schema `from`
 * target. Only the parent-side half of an explicit `on` map is checked
 * against the host schema's declared properties.
 *
 * Lives in its own class rather than inside
 * {@see AggregationAnnotationValidator} so the join grammar has one home and
 * the host validator does not accumulate a second DSL's worth of branching.
 */
final class AggregationJoinAnnotationValidator {

	/**
	 * Error code and message format per failure, keyed by a short name.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const FAILURES = [
		'malformed' => ['aggregation-join-malformed', 'Aggregation "%s" join must be an object {through, on, filter?, select}.'],
		'through-empty' => ['aggregation-join-through-empty', 'Aggregation "%s" join must have a non-empty `through` schema ref.'],
		'on-field-unknown' => [
			'aggregation-join-on-field-unknown',
			'Aggregation "%s" join.on parent field "%s" is not declared in the schema properties.',
		],
		'on-malformed' => [
			'aggregation-join-on-malformed',
			'Aggregation "%s" join.on must be a "Schema.column" string or a {parentField: joinedField} map.',
		],
		'select-empty' => [
			'aggregation-join-select-empty',
			'Aggregation "%s" join must declare a non-empty `select` list of joined-schema fields.',
		],
		'select-malformed' => [
			'aggregation-join-select-malformed',
			'Aggregation "%s" join.select entries must be "Field" strings or {field, metric?} objects.',
		],
		'filter-malformed' => ['aggregation-join-filter-malformed', 'Aggregation "%s" join filter/where must be a map.'],
		'requires-groupby' => [
			'aggregation-join-requires-groupby',
			'Aggregation "%s" declares a join but no groupBy — joined values attach per group.',
		],
	];

	/**
	 * Validate the `join` clause of one aggregation spec.
	 *
	 * @param AggregationSpecEntry $entry    The aggregation name (for error messages) and its raw spec.
	 * @param array<int, mixed>    $propKeys Declared host-schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function validate(AggregationSpecEntry $entry, array $propKeys): array {
		$join = ($entry->spec['join'] ?? null);
		if ($join === null) {
			return [];
		}

		if (is_array($join) === false || array_is_list($join) === true) {
			return [$this->failed(key: 'malformed', args: [$entry->name])];
		}

		return $this->clauseErrors(clause: new JoinClause(name: $entry->name, join: $join, spec: $entry->spec, propKeys: $propKeys));
	}//end validate()

	/**
	 * Every check on a well-formed join object, in reporting order.
	 *
	 * @param JoinClause $clause The join under validation.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 */
	private function clauseErrors(JoinClause $clause): array {
		return array_merge(
			$this->validateThrough(clause: $clause),
			$this->validateOn(clause: $clause),
			$this->validateSelect(clause: $clause),
			$this->validateFilter(clause: $clause),
			$this->validateGroupByPresence(clause: $clause)
		);
	}//end clauseErrors()

	/**
	 * The joined schema ref must be a non-empty string.
	 *
	 * @param JoinClause $clause The join under validation.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateThrough(JoinClause $clause): array {
		$through = ($clause->join['through'] ?? null);
		if (is_string($through) === true && $through !== '') {
			return [];
		}

		return [$this->failed(key: 'through-empty', args: [$clause->name])];
	}//end validateThrough()

	/**
	 * Validate the `on` clause — a "Schema.column" string, or a
	 * `{parentField: joinedField}` map whose parent-side halves must be
	 * declared host-schema properties.
	 *
	 * @param JoinClause $clause The join under validation.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateOn(JoinClause $clause): array {
		$onClause = ($clause->join['on'] ?? null);
		if (is_string($onClause) === true && $onClause !== '') {
			return [];
		}

		if ($this->isFieldMap(onClause: $onClause) === false) {
			return [$this->failed(key: 'on-malformed', args: [$clause->name])];
		}

		return array_map(
			fn (string $field): array => $this->failed(key: 'on-field-unknown', args: [$clause->name, $field]),
			$this->unknownFields(fields: array_keys($onClause), propKeys: $clause->propKeys)
		);
	}//end validateOn()

	/**
	 * Whether an `on` clause is a non-empty `{parentField: joinedField}` map.
	 *
	 * @param mixed $onClause The raw `on` value.
	 *
	 * @return bool
	 */
	private function isFieldMap(mixed $onClause): bool {
		return is_array($onClause) === true && count($onClause) > 0 && array_is_list($onClause) === false;
	}//end isFieldMap()

	/**
	 * The given field names, cast to string, that the host schema does not declare.
	 *
	 * @param array<int, int|string> $fields   Field names to check.
	 * @param array<int, mixed>      $propKeys Declared host-schema property names.
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
	 * Validate the `select` list — non-empty, every entry a "Field" string
	 * or a `{field, metric?}` object. One error per unusable entry.
	 *
	 * @param JoinClause $clause The join under validation.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateSelect(JoinClause $clause): array {
		$select = ($clause->join['select'] ?? null);
		if (is_array($select) === false || count($select) === 0) {
			return [$this->failed(key: 'select-empty', args: [$clause->name])];
		}

		$unusable = array_filter($select, fn (mixed $entry): bool => $this->isUsableSelectEntry(entry: $entry) === false);
		return array_fill(0, count($unusable), $this->failed(key: 'select-malformed', args: [$clause->name]));
	}//end validateSelect()

	/**
	 * Test whether one `select` entry carries a usable field reference: a
	 * non-empty string, or an object whose `field` is one.
	 *
	 * @param mixed $entry The raw select entry.
	 *
	 * @return bool True when the entry names a field.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function isUsableSelectEntry(mixed $entry): bool {
		$field = $entry;
		if (is_array($entry) === true) {
			$field = ($entry['field'] ?? null);
		}

		return is_string($field) === true && $field !== '';
	}//end isUsableSelectEntry()

	/**
	 * The joined-side filter must be a map when present.
	 *
	 * @param JoinClause $clause The join under validation.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateFilter(JoinClause $clause): array {
		$filter = ($clause->join['filter'] ?? $clause->join['where'] ?? null);
		if ($filter === null || is_array($filter) === true) {
			return [];
		}

		return [$this->failed(key: 'filter-malformed', args: [$clause->name])];
	}//end validateFilter()

	/**
	 * A join attaches values PER GROUP; with no groupBy there is no group to
	 * attach them to. Refused at save time rather than left to throw on
	 * every call.
	 *
	 * @param JoinClause $clause The join under validation.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *   AggregationQuery::normaliseGroupByFields() is deliberately the ONE
	 *   canonicaliser shared by this validator, AggregationQuery and
	 *   AggregationRunner. Injecting it to satisfy the rule would invite a
	 *   second implementation, which is the drift this sharing prevents.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateGroupByPresence(JoinClause $clause): array {
		$groupFields = AggregationQuery::normaliseGroupByFields(groupBy: ($clause->spec['groupBy'] ?? null));
		if (count($groupFields) > 0) {
			return [];
		}

		return [$this->failed(key: 'requires-groupby', args: [$clause->name])];
	}//end validateGroupByPresence()

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
