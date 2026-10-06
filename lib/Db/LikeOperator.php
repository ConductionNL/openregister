<?php

/**
 * OpenRegister LikeOperator.
 *
 * The `like` property filter: a case-insensitive substring match.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * Builds the SQL for `?title[like]=foo` (or `?title_like=foo`).
 *
 * The value matches anywhere in the column, ignoring case. `%`, `_` and `\` in
 * the value match themselves: they are escaped with a backslash, the escape
 * character every supported platform uses for LIKE (PostgreSQL and
 * MySQL/MariaDB by default, SQLite through an explicit ESCAPE clause).
 *
 * The column is cast to text first, so the operator also works on numeric,
 * date and JSON columns, where it matches the stored text.
 *
 * The caller supplies the pattern as SQL: a bound parameter placeholder on
 * every path that has a query builder, a platform-quoted literal only on the
 * raw UNION path, which has no parameters to bind.
 *
 * @spec openspec/changes/property-filter-like/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
 */
class LikeOperator {
	/**
	 * The operator key in a filter's operator bag.
	 */
	public const KEY = 'like';

	/**
	 * Platform families, as far as LIKE is concerned.
	 */
	public const PLATFORM_POSTGRES = 'postgres';
	public const PLATFORM_MYSQL = 'mysql';
	public const PLATFORM_SQLITE = 'sqlite';

	/**
	 * The platform family this instance writes SQL for.
	 *
	 * @var string
	 */
	private string $platform;

	/**
	 * Read the platform family from a database platform object.
	 *
	 * @param object $databasePlatform A Doctrine platform, from IDBConnection or a DBAL connection.
	 *
	 * @spec openspec/changes/property-filter-like/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	public function __construct(object $databasePlatform) {
		$class = $databasePlatform::class;
		$this->platform = self::PLATFORM_MYSQL;
		if (stripos($class, 'PostgreSQL') !== false) {
			$this->platform = self::PLATFORM_POSTGRES;
		}

		if (stripos($class, 'Sqlite') !== false) {
			$this->platform = self::PLATFORM_SQLITE;
		}
	}//end __construct()

	/**
	 * The platform family this instance writes SQL for.
	 *
	 * @return string One of the PLATFORM_* constants.
	 *
	 * @spec openspec/changes/property-filter-like/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	public function platform(): string {
		return $this->platform;
	}//end platform()

	/**
	 * The search terms in an operator value.
	 *
	 * A single value is one term; a list (`title[like][]=a&title[like][]=b`)
	 * is several, any of which may match. Empty terms are dropped, so a cleared
	 * table filter (`title[like]=`) adds no condition.
	 *
	 * @param mixed $value The value under the `like` key.
	 *
	 * @return array<int, string> The non-empty terms.
	 *
	 * @spec openspec/changes/property-filter-like/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	public function terms(mixed $value): array {
		$values = [$value];
		if (is_array($value) === true) {
			$values = array_values($value);
		}

		$terms = [];
		foreach ($values as $item) {
			if (is_scalar($item) === false) {
				continue;
			}

			$term = (string)$item;
			if ($term !== '') {
				$terms[] = $term;
			}
		}

		return $terms;
	}//end terms()

	/**
	 * The LIKE pattern for one term: the escaped term between two `%`.
	 *
	 * @param string $term The user's search term.
	 *
	 * @return string The pattern, to bind as a parameter.
	 *
	 * @spec openspec/changes/property-filter-like/specs/zoeken-filteren/spec.md#requirement-like-matches-percent-underscore-and-backslash-literally
	 */
	public function pattern(string $term): string {
		return '%' . addcslashes($term, '\\%_') . '%';
	}//end pattern()

	/**
	 * The SQL condition matching one pattern against one column.
	 *
	 * @param string $column The column reference, already qualified or quoted.
	 * @param string $patternSql The pattern as SQL: a parameter placeholder, or a quoted literal.
	 *
	 * @return string The SQL condition.
	 *
	 * @spec openspec/changes/property-filter-like/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	public function condition(string $column, string $patternSql): string {
		if ($this->platform === self::PLATFORM_POSTGRES) {
			// ILIKE is case-insensitive; backslash is the default LIKE escape.
			return "CAST({$column} AS TEXT) ILIKE {$patternSql}";
		}

		if ($this->platform === self::PLATFORM_SQLITE) {
			// SQLite has no default LIKE escape character, so name it.
			return "LOWER(CAST({$column} AS TEXT)) LIKE LOWER({$patternSql}) ESCAPE '\\'";
		}

		// MySQL / MariaDB: LOWER on both sides stays case-insensitive under a
		// binary collation; backslash is the default LIKE escape.
		return "LOWER(CAST({$column} AS CHAR)) LIKE LOWER({$patternSql})";
	}//end condition()

	/**
	 * One condition for several patterns, any of which may match.
	 *
	 * @param string $column The column reference, already qualified or quoted.
	 * @param array<int, string> $patternSqls The patterns as SQL (placeholders or quoted literals).
	 *
	 * @return string|null The SQL condition, or null when there is nothing to match.
	 *
	 * @spec openspec/changes/property-filter-like/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	public function anyCondition(string $column, array $patternSqls): ?string {
		if ($patternSqls === []) {
			return null;
		}

		$conditions = [];
		foreach ($patternSqls as $patternSql) {
			$conditions[] = $this->condition(column: $column, patternSql: $patternSql);
		}

		return '(' . implode(' OR ', $conditions) . ')';
	}//end anyCondition()
}//end class
