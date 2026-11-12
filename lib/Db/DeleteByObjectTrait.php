<?php

/**
 * DeleteByObjectTrait: delete the rows of one object.
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
 * Delete every row of a mapper's table that belongs to one object (column object_uuid).
 */
trait DeleteByObjectTrait {

	/**
	 * Remove every row of one object.
	 *
	 * Called when the object is deleted, so a re-created uuid never inherits
	 * the rows of the object it replaces.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many rows were removed.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function deleteByObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();

	}//end deleteByObject()
}//end trait
