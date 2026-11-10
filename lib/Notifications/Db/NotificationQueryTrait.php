<?php

/**
 * NotificationQueryTrait: the query shapes the notification mappers share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Equality reads and deletes, one-row lookups and the optional narrowings.
 *
 * Every helper builds exactly the calls the mappers used to spell out by hand,
 * in the same order: the first equality is a `where`, every later one an
 * `andWhere`, an int is bound as PARAM_INT and anything else as a string.
 * Meant for QBMapper subclasses: it reads `$this->db` and the table name.
 */
trait NotificationQueryTrait {

	/**
	 * `SELECT *` from the mapper's table, narrowed by column equalities.
	 *
	 * @param array<string, int|string|bool> $columns Column name => value, in clause order.
	 *
	 * @return IQueryBuilder The query, ready for more clauses.
	 */
	private function selectWhereEquals(array $columns): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		$this->whereEquals(qb: $qb, columns: $columns);

		return $qb;

	}//end selectWhereEquals()

	/**
	 * `DELETE` from the mapper's table, narrowed by column equalities.
	 *
	 * @param array<string, int|string> $columns Column name => value, in clause order.
	 *
	 * @return IQueryBuilder The statement, not yet executed.
	 */
	private function deleteWhereEquals(array $columns): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		$this->whereEquals(qb: $qb, columns: $columns);

		return $qb;

	}//end deleteWhereEquals()

	/**
	 * Add one equality per column: `where` for the first, `andWhere` after.
	 *
	 * @param IQueryBuilder                  $qb      The query.
	 * @param array<string, int|string|bool> $columns Column name => value.
	 *
	 * @return void
	 */
	private function whereEquals(IQueryBuilder $qb, array $columns): void {
		$first = true;
		foreach ($columns as $column => $value) {
			$clause = $qb->expr()->eq($column, $this->boundValue(qb: $qb, value: $value));
			if ($first === true) {
				$qb->where($clause);
				$first = false;
				continue;
			}

			$qb->andWhere($clause);
		}

	}//end whereEquals()

	/**
	 * A named parameter typed by the value: int, bool, or string.
	 *
	 * @param IQueryBuilder   $qb    The query.
	 * @param int|string|bool $value The value.
	 *
	 * @return IParameter The parameter.
	 */
	private function boundValue(IQueryBuilder $qb, int|string|bool $value): IParameter {
		if (is_int($value) === true) {
			return $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT);
		}

		if (is_bool($value) === true) {
			return $qb->createNamedParameter($value, IQueryBuilder::PARAM_BOOL);
		}

		return $qb->createNamedParameter($value);

	}//end boundValue()

	/**
	 * The first row the query finds, or null when there is none.
	 *
	 * @param IQueryBuilder $qb The query; it is capped at one row here.
	 *
	 * @return Entity|null The entity, or null.
	 */
	private function findFirstOrNull(IQueryBuilder $qb): ?Entity {
		$qb->setMaxResults(1);
		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findFirstOrNull()

	/**
	 * Narrow each scope column to rows scoped to the value or to nothing at all ("mine or nobody's").
	 *
	 * @param IQueryBuilder              $qb      The query.
	 * @param array<string, string|null> $columns Scope column to the scope asked for; a null value leaves the query alone.
	 *
	 * @return void
	 */
	private function whereScopedOrUnscoped(IQueryBuilder $qb, array $columns): void {
		foreach ($columns as $column => $value) {
			if ($value === null) {
				continue;
			}

			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull($column),
					$qb->expr()->eq($column, $qb->createNamedParameter($value))
				)
			);
		}

	}//end whereScopedOrUnscoped()

	/**
	 * Narrow to each column value that is given.
	 *
	 * @param IQueryBuilder              $qb      The query.
	 * @param array<string, string|null> $columns Column to value; a null value leaves the query alone.
	 *
	 * @return void
	 */
	private function whereEqualsWhenGiven(IQueryBuilder $qb, array $columns): void {
		foreach ($columns as $column => $value) {
			if ($value !== null) {
				$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
			}
		}

	}//end whereEqualsWhenGiven()

	/**
	 * Narrow to a set of objects when one is given.
	 *
	 * @param IQueryBuilder          $qb          The query.
	 * @param array<int, string>|null $objectUuids The objects; null leaves the query alone.
	 *
	 * @return void
	 */
	private function whereObjectIn(IQueryBuilder $qb, ?array $objectUuids): void {
		if ($objectUuids !== null) {
			$qb->andWhere(
				$qb->expr()->in(
					'object_uuid',
					$qb->createNamedParameter($objectUuids, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);
		}

	}//end whereObjectIn()

	/**
	 * Apply an optional limit and offset.
	 *
	 * @param IQueryBuilder $qb     The query.
	 * @param int|null      $limit  The row cap, or null for none.
	 * @param int|null      $offset The rows to skip, or null for none.
	 *
	 * @return void
	 */
	private function page(IQueryBuilder $qb, ?int $limit, ?int $offset): void {
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

	}//end page()

	/**
	 * Delete every row whose timestamp column is before the cutoff; 0 on failure.
	 *
	 * @param string $column The timestamp column.
	 * @param string $cutoff The cutoff, formatted as the column stores it.
	 *
	 * @return int The number of rows deleted, or 0 when the delete failed.
	 */
	private function deleteBeforeQuietly(string $column, string $cutoff): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->lt($column, $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_STR)));

			return (int)$qb->executeStatement();
		} catch (\Throwable) {
			return 0;
		}

	}//end deleteBeforeQuietly()

	/**
	 * Run a statement, answering 0 instead of failing.
	 *
	 * @param callable(): IQueryBuilder $statement Builds the statement.
	 *
	 * @return int The affected rows, or 0 when building or running it failed.
	 */
	private function executeQuietly(callable $statement): int {
		try {
			return (int)$statement()->executeStatement();
		} catch (\Throwable) {
			return 0;
		}

	}//end executeQuietly()
}//end trait
