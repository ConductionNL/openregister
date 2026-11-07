<?php

/**
 * Operator Evaluator
 *
 * Evaluates MongoDB-style comparison operators used in RBAC match conditions.
 * Supports $eq, $ne, $in, $nin, $contains, $exists, $gt, $gte, $lt, $lte
 * operators.
 *
 * Extracted from PropertyRbacHandler / ConditionMatcher to keep class
 * complexity manageable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Extracted from PropertyRbacHandler
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Psr\Log\LoggerInterface;

/**
 * Evaluates MongoDB-style comparison operators for RBAC condition matching
 */
class OperatorEvaluator {
	/**
	 * The method that checks each supported operator.
	 *
	 * @var array<string, string>
	 */
	private const OPERATOR_METHODS = [
		'$eq' => 'operatorEquals',
		'$ne' => 'operatorNotEquals',
		'$in' => 'operatorIn',
		'$nin' => 'operatorNotIn',
		'$contains' => 'operatorContains',
		'$exists' => 'operatorExists',
		'$gt' => 'operatorGreaterThan',
		'$gte' => 'operatorGreaterThanOrEqual',
		'$lt' => 'operatorLessThan',
		'$lte' => 'operatorLessThanOrEqual',
	];

	/**
	 * Constructor for OperatorEvaluator
	 *
	 * @param LoggerInterface $logger Logger for debugging
	 * @param ComparableInstants $instants Compares two dates as UTC instants
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ComparableInstants $instants = new ComparableInstants(),
	) {
	}//end __construct()

	/**
	 * Check if a value matches all operator conditions
	 *
	 * @param mixed $value Object value
	 * @param array $operators Operator conditions (e.g. ['$gt' => 5, '$lt' => 10])
	 *
	 * @return bool True if value matches all operators
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#the-condition-syntax-must-support-mongodb-style-operators-for-match-expressions
	 *       (evaluates $eq/$ne/$in/$nin/$exists/$gt/$gte/$lt/$lte
	 *       for RLS/FLS match conditions, fail-closed on unknown operators with null-handling that mirrors SQL
	 *       three-valued logic so list and find verdicts stay aligned)
	 */
	public function valueMatchesOperator(mixed $value, array $operators): bool {
		foreach ($operators as $operator => $operand) {
			if ($this->applySingleOperator(value: $value, operator: $operator, operand: $operand) === false) {
				return false;
			}
		}//end foreach

		return true;
	}//end valueMatchesOperator()

	/**
	 * Apply a single operator check against a value
	 *
	 * @param mixed $value Object value
	 * @param string $operator Operator name (e.g. '$eq', '$gt')
	 * @param mixed $operand Operand to compare against
	 *
	 * @return bool True if value satisfies the operator condition
	 */
	private function applySingleOperator(mixed $value, string $operator, mixed $operand): bool {
		$method = (self::OPERATOR_METHODS[$operator] ?? null);
		if ($method === null) {
			return $this->rejectUnknownOperator(operator: $operator);
		}

		return $this->{$method}(value: $value, operand: $operand);
	}//end applySingleOperator()

	/**
	 * Refuse a match on an operator this evaluator does not know.
	 *
	 * Fail-closed on unknown operators to match the SQL path. MagicRbacHandler
	 * emits the impossible predicate (1 = 0) for an operator it cannot build,
	 * so the whole rule denies on the list even beside other properties
	 * (openregister#4089). Returning true here would grant access on malformed
	 * rules (fail-open), creating a list-vs-find security drift.
	 *
	 * @param string $operator The unknown operator name.
	 *
	 * @return false Always: the match is refused.
	 */
	private function rejectUnknownOperator(string $operator): bool {
		$this->logger->warning(
			message: '[OperatorEvaluator] Unknown operator — rejecting match',
			context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $operator]
		);
		return false;
	}//end rejectUnknownOperator()

	/**
	 * Check $eq operator: value must strictly equal operand
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand Expected value
	 *
	 * @return bool True if value equals operand
	 */
	private function operatorEquals(mixed $value, mixed $operand): bool {
		return $value === $operand;
	}//end operatorEquals()

	/**
	 * Check $ne operator: value must not equal operand
	 *
	 * SQL three-valued logic: NULL != X evaluates to NULL, which WHERE treats
	 * as false (row filtered out). PHP must match: if value is null, return
	 * false unless operand is also null (i.e. $ne: null explicitly asking for
	 * "has a value").
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand Value to exclude
	 *
	 * @return bool True if value does not equal operand
	 */
	private function operatorNotEquals(mixed $value, mixed $operand): bool {
		if ($value === null && $operand !== null) {
			return false;
		}

		return $value !== $operand;
	}//end operatorNotEquals()

	/**
	 * Check $in operator: value must be in the operand array
	 *
	 * SQL three-valued logic: NULL IN (...) evaluates to NULL, which WHERE treats
	 * as false (row filtered out). PHP's in_array happens to return false when
	 * looking up null in most arrays, but returns true for an array containing
	 * null — which would diverge from SQL. Explicitly reject null values to
	 * keep list and find verdicts aligned.
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand Array of allowed values
	 *
	 * @return bool True if value is in operand array
	 */
	private function operatorIn(mixed $value, mixed $operand): bool {
		// Only a list is a list of values: a scalar or a map (such as an
		// unsupported `$lookup`) differs from its own array_values() and
		// denies (openregister#4089).
		if ($this->isListLookup(value: $value, operand: $operand) === false) {
			return false;
		}

		return in_array($value, $operand, true);
	}//end operatorIn()

	/**
	 * Whether `$in`/`$nin` can decide at all: a list operand and a non-null value.
	 *
	 * Only a list is a list of values: a scalar or a map (such as an unsupported
	 * `$lookup`) differs from its own array_values() and denies (openregister#4089).
	 * A null value is SQL's NULL IN (...), which WHERE filters out.
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand The operand
	 *
	 * @return bool True when membership decides the verdict.
	 */
	private function isListLookup(mixed $value, mixed $operand): bool {
		return $operand === array_values((array)$operand) && $value !== null;
	}//end isListLookup()

	/**
	 * Check $contains operator: the object's ARRAY-valued property must contain the operand.
	 *
	 * The mirror image of `$in`. `$in` asks "is the object's scalar one of these
	 * literals?"; `$contains` asks "is this value one of the object's array
	 * members?" — which is what a per-object principal list needs and what no
	 * existing operator could express: `{"sharedWith": "$userId"}` compares an
	 * array to a string, and `{"sharedWith": {"$in": ["$userId"]}}` calls
	 * `in_array(array, [uid])`. Both deny for every object.
	 *
	 * When the operand is itself an array the semantics are ANY-intersection, so
	 * an array-valued dynamic token (`$user.groups`) matches when the object
	 * lists any one of the user's groups. This mirrors the OR-of-containment SQL
	 * that MagicSearchHandler::buildArrayPropertyConditionSql already emits for
	 * multi-value array filters.
	 *
	 * SQL parity (the SQL side emits `COALESCE(col, '[]')` containment, per
	 * platform: `::jsonb @> to_jsonb(?::text)` on PostgreSQL,
	 * `JSON_CONTAINS(col, JSON_QUOTE(?))` on MariaDB):
	 *   - NULL column → `COALESCE` makes it `'[]'`, which contains nothing → deny.
	 *   - Non-array column (a scalar) → jsonb containment against a one-element
	 *     array is false → deny.
	 *   - Empty operand → there is no principal to match, so deny. NOTE this
	 *     deliberately does NOT copy `buildArrayPropertyConditionSql`'s
	 *     `$values[0] ?? ''` fallback, which would test containment of the empty
	 *     string for an empty filter; for an access-control operator that would
	 *     admit any object whose list happened to contain `''`.
	 *   - Comparison is STRICT, matching jsonb's typed comparison: the string
	 *     `"1"` and the number `1` are different members.
	 *
	 * @param mixed $value Object value (expected: an array).
	 * @param mixed $operand Value(s) that must appear in it.
	 *
	 * @return bool True when the object's array contains the operand (or any of them).
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/specs/flow-sharing/spec.md#requirement-the-single-object-and-list-access-decisions-agree
	 */
	private function operatorContains(mixed $value, mixed $operand): bool {
		// A null or non-array property contains nothing — mirrors COALESCE(col, '[]').
		if (is_array($value) === false) {
			return false;
		}

		// An array-valued operand is ANY-intersection ($user.groups).
		if (is_array($operand) === true) {
			return $this->containsAny(value: $value, candidates: $operand);
		}

		// Nothing to look for cannot be found.
		if ($operand === null) {
			return false;
		}

		return in_array($operand, $value, true);
	}//end operatorContains()

	/**
	 * Whether the object's array holds any one of the candidates; a null candidate never matches.
	 *
	 * @param array $value The object's array value.
	 * @param array $candidates The values to look for.
	 *
	 * @return bool True when at least one non-null candidate is a strict member.
	 */
	private function containsAny(array $value, array $candidates): bool {
		foreach ($candidates as $candidate) {
			if ($candidate !== null && in_array($candidate, $value, true) === true) {
				return true;
			}
		}

		return false;
	}//end containsAny()

	/**
	 * Check $nin operator: value must not be in the operand array
	 *
	 * SQL three-valued logic: NULL NOT IN (...) evaluates to NULL, filtered out
	 * by WHERE. Conservative semantics: if we cannot tell whether the value is
	 * in the list (because it is null), deny the match.
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand Array of excluded values
	 *
	 * @return bool True if value is not in operand array
	 */
	private function operatorNotIn(mixed $value, mixed $operand): bool {
		// A scalar or map operand denies, as the list query does; it used to
		// grant every object (openregister#4089). See operatorIn().
		if ($this->isListLookup(value: $value, operand: $operand) === false) {
			return false;
		}

		return in_array($value, $operand, true) === false;
	}//end operatorNotIn()

	/**
	 * Check $exists operator: value must exist (or not) based on operand
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand True to require existence, false to require absence
	 *
	 * @return bool True if existence matches expectation
	 */
	private function operatorExists(mixed $value, mixed $operand): bool {
		if ($operand === true && $value === null) {
			return false;
		}

		if ($operand === false && $value !== null) {
			return false;
		}

		return true;
	}//end operatorExists()

	/**
	 * Check $gt operator: value must be greater than operand
	 *
	 * Matches SQL three-valued logic: NULL <op> X evaluates to NULL, which is
	 * filtered out by WHERE. PHP's loose comparison would coerce null to 0/""
	 * and yield a misleading true/false — we suppress that.
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand Threshold value
	 *
	 * @return bool True if value is strictly greater than operand
	 */
	private function operatorGreaterThan(mixed $value, mixed $operand): bool {
		$pair = $this->comparable(value: $value, operand: $operand);

		return $pair !== null && $pair[0] > $pair[1];
	}//end operatorGreaterThan()

	/**
	 * Check $gte operator: value must be greater than or equal to operand
	 *
	 * SQL three-valued logic applies — see operatorGreaterThan().
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand Threshold value
	 *
	 * @return bool True if value is greater than or equal to operand
	 */
	private function operatorGreaterThanOrEqual(mixed $value, mixed $operand): bool {
		$pair = $this->comparable(value: $value, operand: $operand);

		return $pair !== null && $pair[0] >= $pair[1];
	}//end operatorGreaterThanOrEqual()

	/**
	 * Check $lt operator: value must be less than operand
	 *
	 * SQL three-valued logic applies — see operatorGreaterThan().
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand Threshold value
	 *
	 * @return bool True if value is strictly less than operand
	 */
	private function operatorLessThan(mixed $value, mixed $operand): bool {
		$pair = $this->comparable(value: $value, operand: $operand);

		return $pair !== null && $pair[0] < $pair[1];
	}//end operatorLessThan()

	/**
	 * Check $lte operator: value must be less than or equal to operand
	 *
	 * SQL three-valued logic applies — see operatorGreaterThan(). This is the
	 * operator that surfaced the reported `publishedAt: null` bug: PHP's loose
	 * comparison treated `null <= '<datetime>'` as true (because null coerces
	 * to an empty string which is lexicographically less than any non-empty
	 * string), while SQL correctly filtered the row out.
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand Threshold value
	 *
	 * @return bool True if value is less than or equal to operand
	 */
	private function operatorLessThanOrEqual(mixed $value, mixed $operand): bool {
		$pair = $this->comparable(value: $value, operand: $operand);

		return $pair !== null && $pair[0] <= $pair[1];
	}//end operatorLessThanOrEqual()

	/**
	 * The two sides of an ordering comparison, or null when either is NULL.
	 *
	 * Matches SQL three-valued logic: NULL <op> X is NULL, which WHERE filters
	 * out, so a null side refuses instead of PHP coercing it to 0/"".
	 *
	 * @param mixed $value Object value
	 * @param mixed $operand Threshold value
	 *
	 * @return array{0: mixed, 1: mixed}|null The comparable pair, or null to refuse.
	 */
	private function comparable(mixed $value, mixed $operand): ?array {
		if ($value === null || $operand === null) {
			return null;
		}

		return $this->instants->pair(value: $value, operand: $operand);
	}//end comparable()
}//end class
