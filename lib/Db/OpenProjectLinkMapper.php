<?php

/**
 * Mapper for OpenProject link entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class OpenProjectLinkMapper
 *
 * @template-extends QBMapper<OpenProjectLink>
 */
class OpenProjectLinkMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_openproject_links', entityClass: OpenProjectLink::class);
	}//end __construct()

	/**
	 * Find OpenProject links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return OpenProjectLink[] Array of OpenProject links.
	 */
	public function findByObjectUuid(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * Find OpenProject links by work-package id.
	 *
	 * @param int $workPackageId The work-package id.
	 *
	 * @return OpenProjectLink[] Array of OpenProject links.
	 */
	public function findByWorkPackageId(int $workPackageId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('work_package_id', $qb->createNamedParameter($workPackageId, IQueryBuilder::PARAM_INT)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByWorkPackageId()

	/**
	 * Find a specific OpenProject link by object UUID and work-package id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $workPackageId The work-package id.
	 *
	 * @return OpenProjectLink|null The link or null if not found.
	 */
	public function findByObjectAndWorkPackage(string $objectUuid, int $workPackageId): ?OpenProjectLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('work_package_id', $qb->createNamedParameter($workPackageId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectAndWorkPackage()

	/**
	 * Delete all OpenProject links for an object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectUuid(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end deleteByObjectUuid()

	/**
	 * Delete an OpenProject link by object UUID + work-package id (Tier-2
	 * unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $workPackageId The work-package id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndWorkPackage(string $objectUuid, int $workPackageId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('work_package_id', $qb->createNamedParameter($workPackageId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteByObjectAndWorkPackage()
}//end class
