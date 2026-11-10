<?php

/**
 * SelectByColumnTrait: select the rows of a mapper's table where one column matches.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The select-all-where-column-equals prologue and the find-one-or-null read of QBMapper subclasses.
 */
trait SelectByColumnTrait {

	/**
	 * A query for every column of the rows whose column equals a value.
	 *
	 * @param LinkColumn $column The column and value to match.
	 *
	 * @return IQueryBuilder The query, open for more conditions.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	private function selectWhere(LinkColumn $column): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($column->equals(qb: $qb));

		return $qb;
	}//end selectWhere()

	/**
	 * A query for every row of the table, lowest id first.
	 *
	 * @return IQueryBuilder The query.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	private function selectAllById(): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'ASC');

		return $qb;
	}//end selectAllById()

	/**
	 * The one entity a query finds, or null when it finds none.
	 *
	 * @param IQueryBuilder $query The query.
	 *
	 * @return Entity|null The entity.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	private function findOneOrNull(IQueryBuilder $query): ?Entity {
		try {
			return $this->findEntity(query: $query);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findOneOrNull()
}//end trait
