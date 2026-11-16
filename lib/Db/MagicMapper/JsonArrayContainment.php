<?php

/**
 * JsonArrayContainment
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The `contains` operator on an array property: the array holds one entry
 * with all the given keys and values.
 *
 * `sourceIdentifiers[contains][system]=decos&sourceIdentifiers[contains][identifier]=Z-1`
 * matches an object whose `sourceIdentifiers` holds an entry
 * `{"system": "decos", "identifier": "Z-1", ...}`. Both keys must sit on the
 * SAME entry, which a filter per key cannot express. The strings `true` and
 * `false` compare as booleans, the way a query string carries them. An
 * operand that is not a non-empty map of scalars matches nothing.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class JsonArrayContainment {

	/**
	 * The operator key.
	 */
	public const KEY = 'contains';

	/**
	 * The condition that matches no row, for an unusable operand.
	 */
	public const NO_MATCH = '1 = 0';

	/**
	 * Whether a filter value uses the operator.
	 *
	 * @param mixed $value The filter value of an array property.
	 *
	 * @return bool True when the value is an operator bag holding `contains`.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public static function applies(mixed $value): bool {
		return is_array($value) === true && array_key_exists(self::KEY, $value) === true;
	}//end applies()

	/**
	 * The JSON the array must contain: a one-entry list, or null when the operand is unusable.
	 *
	 * @param mixed $value The filter value, holding `contains`.
	 *
	 * @return string|null The JSON, such as `[{"system":"decos","identifier":"Z-1"}]`.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public static function json(mixed $value): ?string {
		$operand = null;
		if (is_array($value) === true) {
			$operand = ($value[self::KEY] ?? null);
		}

		if (is_array($operand) === false || $operand === [] || array_is_list($operand) === true) {
			return null;
		}

		$entry = [];
		foreach ($operand as $key => $part) {
			if (is_string($key) === false || $key === '' || ($part !== null && is_scalar($part) === false)) {
				return null;
			}

			$entry[$key] = self::scalar(value: $part);
		}

		$json = json_encode([$entry], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			return null;
		}

		return $json;
	}//end json()

	/**
	 * The containment condition on a column, with the JSON already in SQL form.
	 *
	 * @param string $column     The column reference.
	 * @param string $json       The JSON, as a parameter placeholder or a quoted literal.
	 * @param bool   $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return string The condition.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public static function condition(string $column, string $json, bool $isPostgres): string {
		if ($isPostgres === true) {
			return "COALESCE({$column}, '[]')::jsonb @> {$json}::jsonb";
		}

		return "JSON_CONTAINS(COALESCE({$column}, '[]'), {$json})";
	}//end condition()

	/**
	 * A query-string boolean as a boolean; anything else unchanged.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value to compare.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private static function scalar(mixed $value): mixed {
		if ($value === 'true') {
			return true;
		}

		if ($value === 'false') {
			return false;
		}

		return $value;
	}//end scalar()
}//end class
