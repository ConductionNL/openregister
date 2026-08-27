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

namespace OCA\OpenRegister\Service\Aggregation;

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
	 * Validate the `join` clause of one aggregation spec.
	 *
	 * @param string $name Aggregation name (for error messages).
	 * @param array<string, mixed> $spec The raw aggregation spec.
	 * @param array<int, mixed> $propKeys Declared host-schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function validate(string $name, array $spec, array $propKeys): array {
		$join = ($spec['join'] ?? null);
		if ($join === null) {
			return [];
		}

		if (is_array($join) === false || array_is_list($join) === true) {
			return [
				[
					'code' => 'aggregation-join-malformed',
					'message' => sprintf(
						'Aggregation "%s" join must be an object {through, on, filter?, select}.',
						$name
					),
				],
			];
		}

		return array_merge(
			$this->validateThrough(name: $name, join: $join),
			$this->validateOn(name: $name, join: $join, propKeys: $propKeys),
			$this->validateSelect(name: $name, join: $join),
			$this->validateFilter(name: $name, join: $join),
			$this->validateGroupByPresence(name: $name, spec: $spec)
		);
	}//end validate()

	/**
	 * The joined schema ref must be a non-empty string.
	 *
	 * @param string $name Aggregation name (for error messages).
	 * @param array<string, mixed> $join The raw join spec.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateThrough(string $name, array $join): array {
		$through = ($join['through'] ?? null);
		if (is_string($through) === true && $through !== '') {
			return [];
		}

		return [
			[
				'code' => 'aggregation-join-through-empty',
				'message' => sprintf('Aggregation "%s" join must have a non-empty `through` schema ref.', $name),
			],
		];
	}//end validateThrough()

	/**
	 * Validate the `on` clause — a "Schema.column" string, or a
	 * `{parentField: joinedField}` map whose parent-side halves must be
	 * declared host-schema properties.
	 *
	 * @param string $name Aggregation name (for error messages).
	 * @param array<string, mixed> $join The raw join spec.
	 * @param array<int, mixed> $propKeys Declared host-schema property names.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateOn(string $name, array $join, array $propKeys): array {
		$onClause = ($join['on'] ?? null);

		if (is_string($onClause) === true && $onClause !== '') {
			return [];
		}

		if (is_array($onClause) === true && count($onClause) > 0 && array_is_list($onClause) === false) {
			$errors = [];
			foreach (array_keys($onClause) as $parentField) {
				if (in_array((string)$parentField, $propKeys, true) === true) {
					continue;
				}

				$errors[] = [
					'code' => 'aggregation-join-on-field-unknown',
					'message' => sprintf(
						'Aggregation "%s" join.on parent field "%s" is not declared in the schema properties.',
						$name,
						(string)$parentField
					),
				];
			}

			return $errors;
		}//end if

		return [
			[
				'code' => 'aggregation-join-on-malformed',
				'message' => sprintf(
					'Aggregation "%s" join.on must be a "Schema.column" string or a {parentField: joinedField} map.',
					$name
				),
			],
		];
	}//end validateOn()

	/**
	 * Validate the `select` list — non-empty, every entry a "Field" string
	 * or a `{field, metric?}` object.
	 *
	 * @param string $name Aggregation name (for error messages).
	 * @param array<string, mixed> $join The raw join spec.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateSelect(string $name, array $join): array {
		$select = ($join['select'] ?? null);
		if (is_array($select) === false || count($select) === 0) {
			return [
				[
					'code' => 'aggregation-join-select-empty',
					'message' => sprintf(
						'Aggregation "%s" join must declare a non-empty `select` list of joined-schema fields.',
						$name
					),
				],
			];
		}

		$errors = [];
		foreach ($select as $entry) {
			if ($this->isUsableSelectEntry(entry: $entry) === true) {
				continue;
			}

			$errors[] = [
				'code' => 'aggregation-join-select-malformed',
				'message' => sprintf(
					'Aggregation "%s" join.select entries must be "Field" strings or {field, metric?} objects.',
					$name
				),
			];
		}

		return $errors;
	}//end validateSelect()

	/**
	 * Test whether one `select` entry carries a usable field reference.
	 *
	 * @param mixed $entry The raw select entry.
	 *
	 * @return bool True when the entry names a field.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function isUsableSelectEntry(mixed $entry): bool {
		if (is_string($entry) === true && $entry !== '') {
			return true;
		}

		if (is_array($entry) === false) {
			return false;
		}

		return (isset($entry['field']) === true
			&& is_string($entry['field']) === true
			&& $entry['field'] !== '');
	}//end isUsableSelectEntry()

	/**
	 * The joined-side filter must be a map when present.
	 *
	 * @param string $name Aggregation name (for error messages).
	 * @param array<string, mixed> $join The raw join spec.
	 *
	 * @return array<int, array{code: string, message: string}> Error list (empty = valid).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validateFilter(string $name, array $join): array {
		$filter = ($join['filter'] ?? $join['where'] ?? null);
		if ($filter === null || is_array($filter) === true) {
			return [];
		}

		return [
			[
				'code' => 'aggregation-join-filter-malformed',
				'message' => sprintf('Aggregation "%s" join filter/where must be a map.', $name),
			],
		];
	}//end validateFilter()

	/**
	 * A join attaches values PER GROUP; with no groupBy there is no group to
	 * attach them to. Refused at save time rather than left to throw on
	 * every call.
	 *
	 * @param string $name Aggregation name (for error messages).
	 * @param array<string, mixed> $spec The raw aggregation spec.
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
	private function validateGroupByPresence(string $name, array $spec): array {
		$groupFields = AggregationQuery::normaliseGroupByFields(groupBy: ($spec['groupBy'] ?? null));
		if (count($groupFields) > 0) {
			return [];
		}

		return [
			[
				'code' => 'aggregation-join-requires-groupby',
				'message' => sprintf(
					'Aggregation "%s" declares a join but no groupBy — joined values attach per group.',
					$name
				),
			],
		];
	}//end validateGroupByPresence()
}//end class
