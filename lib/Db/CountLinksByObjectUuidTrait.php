<?php

/**
 * OpenRegister CountLinksByObjectUuidTrait
 *
 * Counts the link rows of one object.
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
 * Counts the link rows of one object.
 *
 * The using mapper provides `$this->db` and `getTableName()` (as QBMapper does).
 */
trait CountLinksByObjectUuidTrait {

	/**
	 * Count the links of an object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Count of links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function countByObjectUuid(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countByObjectUuid()
}//end trait
