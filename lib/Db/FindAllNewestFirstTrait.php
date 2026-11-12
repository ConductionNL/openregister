<?php

/**
 * FindAllNewestFirstTrait: list a mapper's rows newest first.
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

/**
 * List every row of a mapper's table, newest created first, with optional paging.
 */
trait FindAllNewestFirstTrait {

	/**
	 * Find all rows, newest first.
	 *
	 * @param int|null $limit  Maximum number of rows, or null for all.
	 * @param int|null $offset Rows to skip, or null for none.
	 *
	 * @return \OCP\AppFramework\Db\Entity[] The entities of this mapper.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function findAll(?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created', 'DESC');

		(new QueryPage(limit: $limit, offset: $offset))->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findAll()
}//end trait
