<?php

/**
 * QueryLimit — one answer to "how many rows does `_limit` ask for?"
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

/**
 * Normalises the `_limit` query parameter to either a positive row count or
 * `null`, where `null` means UNLIMITED.
 *
 * WHY THIS EXISTS. `_limit` reached the query builders as whatever the request
 * happened to contain, and the three read paths disagreed about what the odd
 * values meant:
 *
 *   - The canonical single-schema path (`MagicSearchHandler::searchObjects`)
 *     passed the raw value to `setMaxResults()`. Doctrine documents `null` as
 *     "retrieve all results", so an ABSENT `_limit` already issued an unbounded
 *     query — while `_limit=0` became `LIMIT 0` and returned NOTHING.
 *   - The cross-table UNION path clamped with `max(1, min(1000, …))`, so
 *     `_limit=0` returned ONE row and `_limit=5000` silently returned 1000.
 *   - The external-database provider mapped anything `< 1` to its own default
 *     of 200 and capped at 1000.
 *
 * So the same request answered three different ways depending on which path
 * served it, and two of the three answers were silent: a caller asking for
 * everything got one row, or twenty, or a truncated thousand, always with
 * HTTP 200 and never a word about the rows it did not receive. Fleet apps then
 * filtered those partial result sets in the browser and presented the count as
 * a total.
 *
 * That callers read 0 as "no limit" is not a guess: `TmloController::summary()`
 * passes `'_limit' => 0` for exactly that purpose. (That method has its own,
 * unrelated defects and never reaches the query — see the issue it is filed
 * under — but the intent it encodes is the one this class now honours.)
 *
 * WHAT COUNTS AS UNLIMITED. `false`, `null`, an empty string, the strings
 * `'false'`, `'null'`, `'all'`, `'unlimited'` (any case), and any numeric value
 * `<= 0`. Everything else is read as a positive integer row count.
 *
 * Zero is deliberately included. Under the previous behaviour `_limit=0` meant
 * "one row", "zero rows" or "two hundred rows" depending on the path — no
 * caller could have been relying on any of those, and one caller in this
 * repository was already relying on it meaning "unlimited". A caller that wants
 * no rows at all wants `_count=true` and the total, not an empty page.
 *
 * @psalm-suppress UnusedClass Referenced from the query paths; psalm's
 *  entry-point analysis does not follow the controllers that reach them.
 */
final class QueryLimit {

	/**
	 * Values that spell "no limit" when `_limit` is given as a string.
	 *
	 * A query string carries no types: `?_limit=false` arrives as the STRING
	 * `"false"`, which is truthy in PHP and casts to int 0. Listing the spellings
	 * explicitly is what keeps `?_limit=false` from being read as `?_limit=0`
	 * by accident rather than by intent.
	 *
	 * @var string[]
	 */
	private const UNLIMITED_WORDS = ['', 'false', 'null', 'all', 'unlimited', 'none'];

	/**
	 * Resolve `_limit` to a positive row count, or null for unlimited.
	 *
	 * @param mixed $limit The raw `_limit` value from the request or query array.
	 *
	 * @return int|null Positive row count, or null meaning no limit at all.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-limit-supports-an-explicit-unlimited-value
	 */
	public static function normalise(mixed $limit): ?int {
		if ($limit === null || $limit === false) {
			return null;
		}

		if (is_string($limit) === true) {
			$word = strtolower(trim($limit));
			if (in_array($word, self::UNLIMITED_WORDS, true) === true) {
				return null;
			}

			// A non-numeric string is not a row count. Reading it as `(int)`
			// would turn "abc" into 0 and, before this class existed, into a
			// silently empty page.
			if (is_numeric($word) === false) {
				return null;
			}

			$limit = $word;
		}

		if (is_bool($limit) === true) {
			// `true` is not a row count either. It reaches here only from a
			// caller that meant "yes, limit it" without saying to what.
			return null;
		}

		$value = (int)$limit;
		if ($value <= 0) {
			return null;
		}

		return $value;
	}//end normalise()

	/**
	 * Whether a raw `_limit` value asks for every row.
	 *
	 * Sugar over {@see normalise}, for the call sites that only branch on it.
	 *
	 * @param mixed $limit The raw `_limit` value.
	 *
	 * @return bool True when no limit should be applied.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-limit-supports-an-explicit-unlimited-value
	 */
	public static function isUnlimited(mixed $limit): bool {
		return self::normalise(limit: $limit) === null;
	}//end isUnlimited()
}//end class
