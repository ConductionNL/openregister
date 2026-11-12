<?php

/**
 * OpenRegister DeleteLinksByObjectUuidTrait
 *
 * Deletes the link rows of one object.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Deletes the link rows of one object.
 *
 * The using mapper provides `$this->db` and `getTableName()` (as QBMapper does).
 */
trait DeleteLinksByObjectUuidTrait {

	/**
	 * Delete all links of an object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted rows.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function deleteByObjectUuid(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end deleteByObjectUuid()
}//end trait
