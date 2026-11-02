<?php

/**
 * OpenRegister NullFilter.
 *
 * The list endpoint's spelling of "this field is empty" and "this field is
 * filled", read in one place for every query path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

/**
 * Reads `IS NULL`, `IS NOT NULL` and `{isnull: bool}` filter criteria.
 *
 * The list search, nested-path filters and the aggregation runner all read
 * emptiness through this class, so the endpoints that count a list and the
 * one that lists it cannot disagree about what "empty" means.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class NullFilter {

	/**
	 * The scalar filter value that asks for an empty field (`assignee=IS NULL`).
	 */
	public const IS_NULL = 'IS NULL';

	/**
	 * The scalar filter value that asks for a filled field (`assignee=IS NOT NULL`).
	 */
	public const IS_NOT_NULL = 'IS NOT NULL';

	/**
	 * The operator that asks the same with a boolean (`assignee[isnull]=true`).
	 */
	public const ISNULL_OPERATOR = 'isnull';

	/**
	 * The modifier that also admits an empty field (`flag[ne]=true&flag[orNull]=true`).
	 */
	public const OR_NULL_MODIFIER = 'orNull';

	/**
	 * Whether an `isnull` operator value asks for IS NULL rather than IS NOT NULL.
	 *
	 * A query string only delivers strings, so a strict `=== true` can never
	 * match and raw truthiness would read "false" as true.
	 * `FILTER_VALIDATE_BOOLEAN` reads true/"true"/"1"/"on"/"yes" as true and
	 * everything else, "false"/"0"/"" included, as false.
	 *
	 * @param mixed $value The raw operator value.
	 *
	 * @return bool True when the caller asked for IS NULL.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public static function isNullOperatorAsksForNull(mixed $value): bool {
		return filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
	}//end isNullOperatorAsksForNull()

	/**
	 * What a filter criterion says about emptiness, in the list endpoint's syntax.
	 *
	 * `IS NULL` and `{isnull: true}` ask for an empty field, `IS NOT NULL` and
	 * `{isnull: false}` for a filled one. Any other criterion says nothing about
	 * emptiness. An operator bag that carries `isnull` beside other operators
	 * is not read here; its other operators still apply.
	 *
	 * @param mixed $criterion A filter value: a scalar or an operator bag.
	 *
	 * @return bool|null True for "must be empty", false for "must be filled", null otherwise.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public static function nullCheck(mixed $criterion): ?bool {
		if ($criterion === self::IS_NULL) {
			return true;
		}

		if ($criterion === self::IS_NOT_NULL) {
			return false;
		}

		if (is_array($criterion) === true
			&& array_keys($criterion) === [self::ISNULL_OPERATOR]
		) {
			return self::isNullOperatorAsksForNull(value: $criterion[self::ISNULL_OPERATOR]);
		}

		return null;
	}//end nullCheck()

	/**
	 * Take the `orNull` modifier off an operator bag.
	 *
	 * Every comparison SQL offers (`=`, `<>`, `IN`, `NOT IN`, `<`, ...) is
	 * unknown for a NULL column, so a row whose field was never written drops
	 * out of `{ne: true}` as well as `{eq: false}`. `orNull: true` asks for
	 * those rows back: the bag's own operators still AND together, and the
	 * result is OR-ed with `IS NULL`. The value is read like `isnull`'s.
	 *
	 * @param mixed $criterion A filter value: a scalar or an operator bag.
	 *
	 * @return array{0: mixed, 1: bool} The criterion without the modifier, and whether NULL is admitted.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public static function splitOrNull(mixed $criterion): array {
		if (is_array($criterion) === false || array_key_exists(self::OR_NULL_MODIFIER, $criterion) === false) {
			return [$criterion, false];
		}

		$orNull = self::isNullOperatorAsksForNull(value: $criterion[self::OR_NULL_MODIFIER]);
		unset($criterion[self::OR_NULL_MODIFIER]);

		return [$criterion, $orNull];
	}//end splitOrNull()

	/**
	 * Build one field's conditions, honouring an `orNull` modifier in its criterion.
	 *
	 * `$build` receives the criterion without the modifier and returns the
	 * field's conditions. A bag that held nothing but `orNull` adds no condition.
	 *
	 * @param mixed    $criterion A filter value: a scalar or an operator bag.
	 * @param string   $column    The column reference the conditions test.
	 * @param callable $build     fn(mixed $criterion): list<string|\Stringable>.
	 *
	 * @return list<string|\Stringable> The conditions to AND into the query.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public static function conditionsFor(mixed $criterion, string $column, callable $build): array {
		[$criterion, $orNull] = self::splitOrNull(criterion: $criterion);
		if ($orNull === true && $criterion === []) {
			return [];
		}

		return self::admitNull(conditions: $build($criterion), column: $column, orNull: $orNull);
	}//end conditionsFor()

	/**
	 * Join one field's conditions into `(a AND b) OR column IS NULL`.
	 *
	 * Without `orNull`, or without any condition to widen, the list is
	 * returned as it came: no condition at all already admits NULL.
	 *
	 * @param list<T> $conditions The field's conditions, each a SQL predicate.
	 * @param string  $column     The column reference the conditions test.
	 * @param bool    $orNull     Whether the caller asked for NULL rows too.
	 *
	 * @return list<T|string> The conditions to AND into the query.
	 *
	 * @template T of string|\Stringable
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public static function admitNull(array $conditions, string $column, bool $orNull): array {
		if ($orNull === false || $conditions === []) {
			return $conditions;
		}

		$group = implode(' AND ', array_map(static fn (string|\Stringable $condition): string => '(' . $condition . ')', $conditions));
		if (count($conditions) > 1) {
			$group = '(' . $group . ')';
		}

		return ['(' . $group . " OR {$column} IS NULL)"];
	}//end admitNull()
}//end class
