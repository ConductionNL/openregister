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
 * @spec openspec/changes/aggregation-null-filters/specs/aggregation-api/spec.md
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
	 * @spec openspec/changes/aggregation-null-filters/specs/aggregation-api/spec.md
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
	 * @spec openspec/changes/aggregation-null-filters/specs/aggregation-api/spec.md
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
}//end class
