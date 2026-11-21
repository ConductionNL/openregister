<?php

/**
 * MagicUnionOrder: the order of a cross-schema search, in SQL and in PHP.
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
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Support\OrderParameter;

/**
 * Resolve the ORDER BY keys of a cross-schema UNION search and sort rows on them.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */
final class MagicUnionOrder {
	use MagicColumnNameTrait;

	/**
	 * The prefix of a metadata column.
	 *
	 * @var string
	 */
	private const PREFIX = '_';

	/**
	 * The column that holds the search score in a UNION.
	 *
	 * @var string
	 */
	private const SCORE = '_search_score';

	/**
	 * The metadata columns a person reads as text, ordered case-insensitively
	 * on every path (the single-schema search reads this list too).
	 *
	 * @var array<int, string>
	 */
	public const TEXT_METADATA_COLUMNS = ['_name', '_description', '_summary'];

	/**
	 * Constructor.
	 *
	 * @param bool          $isPostgres  Whether the platform is PostgreSQL (identifier quoting).
	 * @param array<string> $textColumns The property columns that hold text in every schema of the search.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 * @spec openspec/changes/case-insensitive-ordering/specs/zoeken-filteren/spec.md#requirement-ordering-on-a-string-property-ignores-letter-case-on-every-supported-database-req-cio-001
	 */
	public function __construct(
		private readonly bool $isPostgres,
		private readonly array $textColumns = [],
	) {
	}//end __construct()

	/**
	 * The ORDER BY keys, in order.
	 *
	 * Each entry carries the SQL expression to put in the statement and the
	 * plain row key to read when the same order has to be reproduced in PHP
	 * across batches. The list is never empty: the uuid tiebreaker always
	 * closes it, because LIMIT/OFFSET over an unordered UNION returns a
	 * different page each time it runs (BUG-DB-4).
	 *
	 * @param array $query Search parameters.
	 *
	 * @return array<int, array{row: string, sql: string, dir: string}> Ordered sort keys.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function keys(array $query): array {
		$hasSearch = empty($query['_search'] ?? null) === false;
		$order = (new OrderParameter(order: $query['_order'] ?? []))->fields();

		$keys = [];
		if ($order !== []) {
			$keys = $this->requestedKeys(order: $order, hasSearch: $hasSearch);
		} elseif ($hasSearch === true) {
			$keys[] = ['row' => self::SCORE, 'sql' => self::SCORE, 'dir' => 'DESC'];
		}

		$uuidColumn = self::PREFIX . 'uuid';
		if (in_array($uuidColumn, array_column($keys, 'row'), true) === false) {
			$keys[] = ['row' => $uuidColumn, 'sql' => $uuidColumn, 'dir' => 'ASC'];
		}

		return $keys;
	}//end keys()

	/**
	 * A query whose `_order` keeps only what a union of these columns can order on.
	 *
	 * Like a single-table search, a union leaves out a field it does not know:
	 * in its ORDER BY such a field is an error from the database. A property
	 * counts under its exact name only, because the refusal of a field that may
	 * not be sorted on compares exact names. A request left with nothing orders
	 * on the uuid, not on the search score: every batch of one search has to
	 * order alike for the merge to find the rows of its page.
	 *
	 * @param array                $query   Search parameters.
	 * @param array<string, mixed> $columns Column name => property name.
	 *
	 * @return array The query.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function orderable(array $query, array $columns): array {
		if (array_key_exists('_order', $query) === false) {
			return $query;
		}

		$fields = (new OrderParameter(order: $query['_order']))->fields();
		$query['_order'] = array_filter(
			$fields,
			fn (int|string $field): bool => $this->isOrderable(field: (string) $field, columns: $columns),
			ARRAY_FILTER_USE_KEY
		);
		if ($fields !== [] && $query['_order'] === []) {
			$query['_order'] = [self::PREFIX . 'uuid' => 'ASC'];
		}

		return $query;
	}//end orderable()

	/**
	 * Sort merged rows on the SQL's own order keys.
	 *
	 * Each batch is already ordered by its own statement; merging them is not.
	 * A row that a batch did not project (a property column another schema
	 * owns) sorts as null, which is what the UNION's `NULL AS alias` arm would
	 * have produced.
	 *
	 * @param array $rows      Merged raw rows.
	 * @param array $orderKeys Keys from keys().
	 *
	 * @return array Rows in the merged order.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function sorted(array $rows, array $orderKeys): array {
		usort($rows, fn (array $left, array $right): int => $this->compare(pair: [$left, $right], orderKeys: $orderKeys));

		return $rows;
	}//end sorted()

	/**
	 * Whether a union can order on a field.
	 *
	 * @param string               $field   The field.
	 * @param array<string, mixed> $columns Column name => property name.
	 *
	 * @return bool
	 */
	private function isOrderable(string $field, array $columns): bool {
		$column = $this->sanitizeColumnName(name: $field);
		if ((string) ($columns[$column] ?? '') === $field) {
			return true;
		}

		if (str_starts_with($field, '@self.') === true || in_array($field, ['_relevance', self::SCORE], true) === true) {
			return true;
		}

		return str_starts_with($field, self::PREFIX) === true && array_key_exists($column, MagicMetadataColumns::ALL) === true;
	}//end isOrderable()

	/**
	 * The keys of an `_order` parameter.
	 *
	 * @param array<array-key, string> $order     Field => direction.
	 * @param bool                     $hasSearch Whether the query searches.
	 *
	 * @return array<int, array{row: string, sql: string, dir: string, fold?: bool}>
	 */
	private function requestedKeys(array $order, bool $hasSearch): array {
		$keys = [];
		foreach ($order as $field => $direction) {
			$key = $this->fieldKey(field: (string) $field, hasSearch: $hasSearch);
			if ($key === null) {
				continue;
			}

			if ($this->isText(column: $key['row']) === true) {
				// Text orders by its lower-cased value first, then by the raw
				// value, so two values that lower-case alike still order totally
				// (REQ-CIO-001), as the single-schema search does.
				$keys[] = ['row' => $key['row'], 'sql' => $this->lowerSql(expression: $key['sql']), 'dir' => $direction, 'fold' => true];
			}

			$keys[] = $key + ['dir' => $direction];
		}

		return $keys;
	}//end requestedKeys()

	/**
	 * Whether a union column holds text: a property column that is text in every
	 * schema of the search, or a text metadata column.
	 *
	 * @param string $column The union column.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/case-insensitive-ordering/specs/zoeken-filteren/spec.md#requirement-ordering-on-a-string-property-ignores-letter-case-on-every-supported-database-req-cio-001
	 */
	private function isText(string $column): bool {
		return in_array($column, self::TEXT_METADATA_COLUMNS, true) === true
			|| in_array($column, $this->textColumns, true) === true;
	}//end isText()

	/**
	 * The lower-cased form of an order expression; PostgreSQL casts first, as LOWER() takes text only.
	 *
	 * @param string $expression The column expression.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/case-insensitive-ordering/specs/zoeken-filteren/spec.md#requirement-ordering-on-a-string-property-ignores-letter-case-on-every-supported-database-req-cio-001
	 */
	private function lowerSql(string $expression): string {
		if ($this->isPostgres === true) {
			return "LOWER(CAST({$expression} AS TEXT))";
		}

		return "LOWER({$expression})";
	}//end lowerSql()

	/**
	 * The row key and SQL of one ordered field, or null when it is left out.
	 *
	 * `_relevance` is the search score in a UNION, and means nothing without a search term.
	 *
	 * @param string $field     The field.
	 * @param bool   $hasSearch Whether the query searches.
	 *
	 * @return array{row: string, sql: string}|null
	 */
	private function fieldKey(string $field, bool $hasSearch): ?array {
		if ($field === '_relevance') {
			return $this->scoreKey(hasSearch: $hasSearch);
		}

		if (str_starts_with($field, '@self.') === true) {
			return $this->metadataKey(field: $field);
		}

		$column = $this->sanitizeColumnName(name: $field);
		if (str_starts_with($field, self::PREFIX) === true) {
			return ['row' => $column, 'sql' => $column];
		}

		return ['row' => $column, 'sql' => $this->quoted(column: $column)];
	}//end fieldKey()

	/**
	 * The score key, or null without a search term.
	 *
	 * @param bool $hasSearch Whether the query searches.
	 *
	 * @return array{row: string, sql: string}|null
	 */
	private function scoreKey(bool $hasSearch): ?array {
		if ($hasSearch === false) {
			return null;
		}

		return ['row' => self::SCORE, 'sql' => self::SCORE];
	}//end scoreKey()

	/**
	 * The key of an `@self.` field, or null when no such metadata column exists.
	 *
	 * The name comes from the caller and ends up in concatenated SQL, so only
	 * a column on the metadata allowlist is accepted.
	 *
	 * @param string $field The field, with its `@self.` prefix.
	 *
	 * @return array{row: string, sql: string}|null
	 */
	private function metadataKey(string $field): ?array {
		$column = self::PREFIX . $this->sanitizeColumnName(name: substr($field, 6));
		if (array_key_exists($column, MagicMetadataColumns::ALL) === false) {
			return null;
		}

		return ['row' => $column, 'sql' => $this->quoted(column: $column)];
	}//end metadataKey()

	/**
	 * A quoted column, so reserved words (`order`, `group`) stay valid SQL.
	 *
	 * @param string $column The column.
	 *
	 * @return string
	 */
	private function quoted(string $column): string {
		return (new MagicColumnSql(isPostgres: $this->isPostgres))->quote(name: $column);
	}//end quoted()

	/**
	 * Compare two rows on the order keys.
	 *
	 * @param array{0: array, 1: array} $pair      The rows.
	 * @param array                     $orderKeys The keys.
	 *
	 * @return int
	 */
	private function compare(array $pair, array $orderKeys): int {
		foreach ($orderKeys as $key) {
			// Compared as strings: PHP compares two numeric strings numerically, so a score of
			// "0.9" still beats "0.75" and "1.0E-5" still loses to "0.0001", while a missing
			// column (null, cast to "") is compared as text instead of silently becoming zero.
			$left  = (string)($pair[0][$key['row']] ?? null);
			$right = (string)($pair[1][$key['row']] ?? null);
			if (($key['fold'] ?? false) === true) {
				// The lower-cased key, as the SQL's LOWER() orders it.
				$left  = mb_strtolower($left);
				$right = mb_strtolower($right);
			}

			$comparison = ($left <=> $right);
			if ($comparison !== 0) {
				return $this->directed(comparison: $comparison, direction: $key['dir']);
			}
		}

		return 0;
	}//end compare()

	/**
	 * A comparison in a key's direction.
	 *
	 * @param int    $comparison The ascending comparison.
	 * @param string $direction  The direction.
	 *
	 * @return int
	 */
	private function directed(int $comparison, string $direction): int {
		if ($direction === 'DESC') {
			return -$comparison;
		}

		return $comparison;
	}//end directed()
}//end class
