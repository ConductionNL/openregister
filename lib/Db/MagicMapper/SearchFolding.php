<?php

/**
 * SearchFolding: the SQL that compares a column with a search pattern, per platform and mode.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * One place that answers how a search compares text: the column expression,
 * the pattern expression and the operator between them.
 *
 * Exact mode is the comparison search has always made: case-insensitive, accent-sensitive
 * (`col::text ILIKE pattern` on PostgreSQL, `LOWER(CAST(col AS CHAR)) LIKE LOWER(pattern)`
 * elsewhere). Folded mode also ignores accents: through the `openregister_fold()` wrapper
 * around `unaccent` on PostgreSQL, and through the `utf8mb4_unicode_ci` collation on
 * MariaDB and MySQL. A platform without a folding answer keeps the exact comparison.
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
 */
final class SearchFolding {

	/**
	 * PostgreSQL.
	 */
	public const PLATFORM_POSTGRES = 'postgres';

	/**
	 * MariaDB and MySQL.
	 */
	public const PLATFORM_MYSQL = 'mysql';

	/**
	 * Any other platform: always the exact comparison.
	 */
	public const PLATFORM_OTHER = 'other';

	/**
	 * The immutable PostgreSQL wrapper around unaccent() that the migration creates.
	 */
	public const FOLD_FUNCTION = 'openregister_fold';

	/**
	 * The accent- and case-insensitive collation used on MariaDB and MySQL.
	 */
	public const MYSQL_COLLATION = 'utf8mb4_unicode_ci';

	/**
	 * The `_accents` request value that asks for the exact comparison.
	 */
	public const EXACT_REQUEST = 'exact';

	/**
	 * Whether this comparison ignores accents.
	 *
	 * @var boolean
	 */
	private readonly bool $folded;

	/**
	 * Constructor.
	 *
	 * @param string $platform One of the PLATFORM_ constants; anything else counts as PLATFORM_OTHER.
	 * @param bool   $folded   Whether to ignore accents; ignored on PLATFORM_OTHER.
	 */
	public function __construct(
		private readonly string $platform,
		bool $folded,
	) {
		$this->folded = $folded === true
			&& in_array($platform, [self::PLATFORM_POSTGRES, self::PLATFORM_MYSQL], true) === true;
	}//end __construct()

	/**
	 * Whether this comparison ignores accents.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
	 */
	public function isFolded(): bool {
		return $this->folded;
	}//end isFolded()

	/**
	 * Whether a request's `_accents` value asks for the exact comparison.
	 *
	 * Only the value `exact` does; any other value, or none, is ignored.
	 *
	 * @param mixed $value The `_accents` request value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
	 */
	public static function requestsExact(mixed $value): bool {
		return $value === self::EXACT_REQUEST;
	}//end requestsExact()

	/**
	 * The expression to compare for a column.
	 *
	 * @param string $quotedColumn The already-quoted column reference.
	 * @param bool   $nullSafe     Wrap the column in COALESCE so a NOT can negate the match.
	 *
	 * @return string The column expression.
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
	 */
	public function column(string $quotedColumn, bool $nullSafe): string {
		if ($this->platform === self::PLATFORM_POSTGRES) {
			$text = "{$quotedColumn}::text";
			if ($nullSafe === true) {
				$text = "COALESCE({$quotedColumn}::text, '')";
			}

			if ($this->folded === true) {
				return self::FOLD_FUNCTION . "({$text})";
			}

			return $text;
		}

		$value = $quotedColumn;
		if ($nullSafe === true) {
			$value = "COALESCE({$quotedColumn}, '')";
		}

		if ($this->folded === true) {
			return "CAST({$value} AS CHAR) COLLATE " . self::MYSQL_COLLATION;
		}

		return "LOWER(CAST({$value} AS CHAR))";
	}//end column()

	/**
	 * The expression a column is compared to.
	 *
	 * @param string $quotedPattern The already-quoted LIKE pattern or literal.
	 *
	 * @return string The pattern expression.
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
	 */
	public function pattern(string $quotedPattern): string {
		if ($this->platform === self::PLATFORM_POSTGRES) {
			if ($this->folded === true) {
				return self::FOLD_FUNCTION . "({$quotedPattern})";
			}

			return $quotedPattern;
		}

		if ($this->folded === true) {
			return "{$quotedPattern} COLLATE " . self::MYSQL_COLLATION;
		}

		return "LOWER({$quotedPattern})";
	}//end pattern()

	/**
	 * The operator between the column and the pattern.
	 *
	 * ILIKE for the exact PostgreSQL comparison, which folds case itself; LIKE
	 * everywhere else, where both sides are already folded.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
	 */
	public function matchOperator(): string {
		if ($this->platform === self::PLATFORM_POSTGRES && $this->folded === false) {
			return 'ILIKE';
		}

		return 'LIKE';
	}//end matchOperator()

	/**
	 * The whole comparison of a column with a pattern.
	 *
	 * @param string $quotedColumn  The already-quoted column reference.
	 * @param string $quotedPattern The already-quoted LIKE pattern.
	 * @param bool   $nullSafe      Wrap the column in COALESCE.
	 *
	 * @return string The SQL condition.
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
	 */
	public function match(string $quotedColumn, string $quotedPattern, bool $nullSafe): string {
		return $this->column(quotedColumn: $quotedColumn, nullSafe: $nullSafe)
			. ' ' . $this->matchOperator()
			. ' ' . $this->pattern(quotedPattern: $quotedPattern);
	}//end match()

	/**
	 * A pg_trgm similarity condition of a text expression against a literal,
	 * both folded when this comparison ignores accents.
	 *
	 * @param string $textExpression The column's text expression (already cast or COALESCEd).
	 * @param string $quotedLiteral  The already-quoted literal.
	 * @param float  $threshold      The similarity threshold.
	 *
	 * @return string The SQL condition.
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
	 */
	public function similarity(string $textExpression, string $quotedLiteral, float $threshold=0.1): string {
		$left  = $textExpression;
		$right = $quotedLiteral;
		if ($this->platform === self::PLATFORM_POSTGRES && $this->folded === true) {
			$left  = self::FOLD_FUNCTION . "({$textExpression})";
			$right = self::FOLD_FUNCTION . "({$quotedLiteral})";
		}

		return "similarity({$left}, {$right}) > {$threshold}";
	}//end similarity()
}//end class
