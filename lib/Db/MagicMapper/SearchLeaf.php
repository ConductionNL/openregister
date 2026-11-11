<?php

/**
 * SearchLeaf
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
 * One literal search term in its scope, and the raw SQL that matches a column against it.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
final class SearchLeaf {

	/**
	 * Constructor.
	 *
	 * @param SearchLeafScope $scope    The schema, platform and fuzzy flag of the search
	 * @param string          $pattern  The LIKE pattern, unquoted
	 * @param string          $literal  The literal text, for the fuzzy arm
	 * @param bool            $nullSafe Wrap every column in COALESCE so NOT can negate it
	 */
	public function __construct(
		public readonly SearchLeafScope $scope,
		public readonly string $pattern,
		public readonly string $literal,
		public readonly bool $nullSafe,
	) {
	}//end __construct()

	/**
	 * Quote a value through the scope's connection.
	 *
	 * @param string $value The value
	 *
	 * @return string The quoted value.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function quote(string $value): string {
		return $this->scope->connection->quote($value);
	}//end quote()

	/**
	 * A pg_trgm similarity match of a column against the literal.
	 *
	 * @param string $plain The column expression on the plain path
	 * @param string $text  The text expression COALESCE wraps on the null-safe path
	 *
	 * @return string The SQL condition.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function similarity(string $plain, string $text): string {
		$column = $plain;
		if ($this->nullSafe === true) {
			$column = "COALESCE({$text}, '')";
		}

		return "similarity({$column}, {$this->quote(value: $this->literal)}) > 0.1";
	}//end similarity()

	/**
	 * Match one column against a LIKE pattern, per platform.
	 *
	 * On the null-safe path the column is wrapped in COALESCE. A NULL column
	 * makes LIKE evaluate to NULL, and `NOT NULL` is NULL rather than TRUE, so
	 * without it a record whose searchable columns are empty would fall out of
	 * a `NOT` term. The plain path keeps the historical SQL.
	 *
	 * @param string $column      The already-quoted column reference
	 * @param string $likePattern The already-quoted LIKE pattern
	 *
	 * @return string The SQL condition for this column.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function columnMatch(string $column, string $likePattern): string {
		if ($this->scope->isPostgres === true) {
			// ILIKE is always case-insensitive on PostgreSQL.
			$expression = "{$column}::text";
			if ($this->nullSafe === true) {
				$expression = "COALESCE({$column}::text, '')";
			}

			return "{$expression} ILIKE {$likePattern}";
		}

		// CAST + LOWER on both sides keeps MySQL case-insensitive regardless of
		// collation (e.g., utf8mb4_bin), matching applyFullTextSearch().
		$expression = "LOWER(CAST({$column} AS CHAR))";
		if ($this->nullSafe === true) {
			$expression = "LOWER(CAST(COALESCE({$column}, '') AS CHAR))";
		}

		return "{$expression} LIKE LOWER({$likePattern})";
	}//end columnMatch()
}//end class
