<?php

/**
 * FlowExpressionOperators: the operators flows need and JSONLogic does not ship.
 *
 * Deliberately small. Each one exists because a flow author would otherwise
 * have to reach for a Code node to do something ordinary — which is the gap
 * the expression language is trying not to open.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;

/**
 * The custom JSONLogic operators, by name, in registration order.
 *
 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
 */
final class FlowExpressionOperators {

	/**
	 * Every custom operator: strings, dates, arrays, then structure.
	 *
	 * @return array<string, callable> Operator name => implementation.
	 *
	 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
	 */
	public static function all(): array {
		return array_merge(self::strings(), self::dates(), self::arrays(), self::structure());
	}//end all()

	/**
	 * The string operators.
	 *
	 * @return array<string, callable> Operator name => implementation.
	 */
	private static function strings(): array {
		return [
			'upper' => static fn ($a) => mb_strtoupper((string)$a),
			'lower' => static fn ($a) => mb_strtolower((string)$a),
			'trim' => static fn ($a) => trim((string)$a),
			'split' => static fn ($a, $sep = ',') => explode((string)$sep, (string)$a),
			'join' => static fn ($a, $sep = ',') => implode((string)$sep, (array)$a),
			'replace' => static fn ($a, $s, $r) => str_replace((string)$s, (string)$r, (string)$a),
			'matches' => self::matches(...),
		];
	}//end strings()

	/**
	 * The date operators. `now` takes no argument; `dateFormat` and `dateAdd`
	 * accept anything strtotime understands, which is what a stored ISO string is.
	 *
	 * @return array<string, callable> Operator name => implementation.
	 */
	private static function dates(): array {
		return [
			'now' => static fn () => (new DateTime())->format('c'),
			'dateFormat' => self::dateFormat(...),
			'dateAdd' => self::dateAdd(...),
		];
	}//end dates()

	/**
	 * The array operators.
	 *
	 * @return array<string, callable> Operator name => implementation.
	 */
	private static function arrays(): array {
		return [
			'unique' => static fn ($a) => array_values(array_unique((array)$a)),
			'sort' => self::sorted(...),
			'length' => self::length(...),
		];
	}//end arrays()

	/**
	 * The structure operators. `coalesce` is the null-safe read authors reach for most.
	 *
	 * @return array<string, callable> Operator name => implementation.
	 */
	private static function structure(): array {
		return [
			'coalesce' => self::coalesce(...),
			'toJson' => static fn ($a) => json_encode($a),
			'fromJson' => static fn ($a) => json_decode((string)$a, true),
		];
	}//end structure()

	/**
	 * Whether a value matches a pattern.
	 *
	 * A bad pattern is the author's error and must not warn into the log on
	 * every item; @ plus an explicit false check.
	 *
	 * @param mixed $a       The value.
	 * @param mixed $pattern The PCRE pattern.
	 *
	 * @return boolean True on a match.
	 */
	private static function matches($a, $pattern): bool {
		return @preg_match((string)$pattern, (string)$a) === 1;
	}//end matches()

	/**
	 * Format a date, or null when it does not parse.
	 *
	 * @param mixed $value  Anything strtotime understands.
	 * @param mixed $format The date() format.
	 *
	 * @return string|null The formatted date.
	 */
	private static function dateFormat($value, $format = 'c'): ?string {
		$time = strtotime((string)$value);
		if ($time === false) {
			return null;
		}

		return date((string)$format, $time);
	}//end dateFormat()

	/**
	 * Shift a date by a relative modifier, or null when the result does not parse.
	 *
	 * @param mixed $value    Anything strtotime understands.
	 * @param mixed $modifier A relative modifier such as `+1 day`.
	 *
	 * @return string|null The shifted date, ISO 8601.
	 */
	private static function dateAdd($value, $modifier): ?string {
		$time = strtotime((string)$modifier, strtotime((string)$value));
		if ($time === false) {
			return null;
		}

		return date('c', $time);
	}//end dateAdd()

	/**
	 * The number of members of a list, or of characters of anything else.
	 *
	 * @param mixed $a The value.
	 *
	 * @return integer The length.
	 */
	private static function length($a): int {
		if (is_array($a) === true) {
			return count($a);
		}

		return mb_strlen((string)$a);
	}//end length()

	/**
	 * A sorted copy of a list.
	 *
	 * @param mixed $a The list.
	 *
	 * @return array<int|string, mixed> The sorted list.
	 */
	private static function sorted($a): array {
		$list = (array)$a;
		sort($list);
		return $list;
	}//end sorted()

	/**
	 * The first value that is neither null nor the empty string.
	 *
	 * @param mixed ...$values The candidates.
	 *
	 * @return mixed The first present value, or null.
	 */
	private static function coalesce(...$values): mixed {
		foreach ($values as $value) {
			if ($value !== null && $value !== '') {
				return $value;
			}
		}

		return null;
	}//end coalesce()
}//end class
