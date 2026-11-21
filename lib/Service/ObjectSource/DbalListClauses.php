<?php

/**
 * DbalListClauses: the WHERE and ORDER BY clauses of a DBAL-source list or count.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use OCA\OpenRegister\Db\LikeOperatorInterface;
use OCA\OpenRegister\Db\LikeOperators;

/**
 * Translates the OR query's filters, `_search` and sort onto one statement,
 * as bound parameters, on allowlisted columns only.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class DbalListClauses {

	/**
	 * Constructor.
	 *
	 * @param DbalStatement      $statement  The statement (mutated by every clause).
	 * @param array<int, string> $filterable The columns a filter or search may touch.
	 */
	public function __construct(
		private readonly DbalStatement $statement,
		private readonly array $filterable,
	) {
	}//end __construct()

	/**
	 * Apply equality and `like` filters on the filterable columns and a `_search` LIKE, all bound.
	 *
	 * @param array<string, mixed> $query The OR query.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	public function filter(array $query): void {
		$filters = ($query['filters'] ?? []);
		if (is_array($filters) === false) {
			$filters = [];
		}

		foreach ($filters as $column => $value) {
			$this->filterColumn(column: $column, value: $value);
		}

		$this->search(query: $query);
	}//end filter()

	/**
	 * Apply one filter entry: a `like` bag or a scalar equality on an allowlisted column.
	 *
	 * Any other value (a non-`like` bag, an object) stays unsupported on this path.
	 *
	 * @param int|string $column The filter key.
	 * @param mixed      $value  The filter value.
	 *
	 * @return void
	 */
	private function filterColumn(int|string $column, mixed $value): void {
		if (self::isAllowed(column: $column, allowed: $this->filterable) === false) {
			return;
		}

		if (self::isLikeFilter(value: $value) === true) {
			$this->like(column: (string)$column, value: $value[LikeOperatorInterface::KEY]);
			return;
		}

		if (is_array($value) === false && is_object($value) === false) {
			$this->statement->andEquals(pairs: [[$column, $value]]);
		}
	}//end filterColumn()

	/**
	 * Whether a key names an allowlisted column.
	 *
	 * @param int|string         $column  The filter or sort key.
	 * @param array<int, string> $allowed The allowlisted columns.
	 *
	 * @return bool
	 */
	private static function isAllowed(int|string $column, array $allowed): bool {
		return is_string($column) === true && in_array($column, $allowed, true) === true;
	}//end isAllowed()

	/**
	 * Whether a filter value is an operator bag carrying a `like` term.
	 *
	 * @param mixed $value The filter value.
	 *
	 * @return bool
	 */
	private static function isLikeFilter(mixed $value): bool {
		return is_array($value) === true && array_key_exists(LikeOperatorInterface::KEY, $value) === true;
	}//end isLikeFilter()

	/**
	 * Apply a `like` filter on one allowlisted column with bound parameters.
	 *
	 * The other operators in the bag stay unsupported on this path, as before.
	 *
	 * @param string $column The allowlisted column.
	 * @param mixed  $value  The value under the `like` key: one term or a list.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
	 */
	private function like(string $column, mixed $value): void {
		$like = LikeOperators::forPlatform(databasePlatform: $this->statement->connection->getDatabasePlatform());
		$placeholders = [];
		foreach ($like->terms(value: $value) as $term) {
			$placeholders[] = $this->statement->qb->createNamedParameter($like->pattern(term: $term));
		}

		$condition = $like->anyCondition(column: $this->statement->quote(identifier: $column), patternSqls: $placeholders);
		if ($condition !== null) {
			$this->statement->qb->andWhere($condition);
		}
	}//end like()

	/**
	 * Apply a `_search` term as a bound LIKE across the filterable columns.
	 *
	 * @param array<string, mixed> $query The OR query.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function search(array $query): void {
		$search = (string)($query['_search'] ?? $query['search'] ?? '');
		if ($search === '' || $this->filterable === []) {
			return;
		}

		$castType = $this->textCastType();
		$param = $this->statement->qb->createNamedParameter('%' . $search . '%');
		$ors   = [];
		foreach ($this->filterable as $column) {
			$ors[] = 'CAST(' . $this->statement->quote(identifier: $column) . ' AS ' . $castType . ') LIKE ' . $param;
		}

		$this->statement->qb->andWhere('(' . implode(' OR ', $ors) . ')');
	}//end search()

	/**
	 * The platform's text type for a CAST.
	 *
	 * LIKE only exists for text operands: on PostgreSQL `integer LIKE
	 * text` raises "operator does not exist" (observed live), so every
	 * column is CAST to the platform's text type first. MySQL spells the
	 * cast target CHAR; PostgreSQL and SQLite use TEXT.
	 *
	 * @return string
	 */
	private function textCastType(): string {
		$castType = 'TEXT';
		if ($this->statement->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform === true) {
			$castType = 'CHAR';
		}

		return $castType;
	}//end textCastType()

	/**
	 * Apply a validated sort column/direction.
	 *
	 * @param array<string, mixed> $query   The OR query.
	 * @param array<int, string>   $columns The allowlisted scalar columns.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function sort(array $query, array $columns): void {
		$sort = ($query['sort'] ?? $query['_order'] ?? null);
		if (is_array($sort) === false || $sort === []) {
			return;
		}

		foreach ($sort as $column => $direction) {
			if (self::isAllowed(column: $column, allowed: $columns) === false) {
				continue;
			}

			$this->statement->qb->addOrderBy($this->statement->quote(identifier: $column), self::sortDirection(direction: $direction));
		}
	}//end sort()

	/**
	 * The SQL direction for a requested sort direction: DESC only for `desc`, any case.
	 *
	 * @param mixed $direction The requested direction.
	 *
	 * @return string ASC or DESC.
	 */
	private static function sortDirection(mixed $direction): string {
		if (strtolower((string)$direction) === 'desc') {
			return 'DESC';
		}

		return 'ASC';
	}//end sortDirection()
}//end class
