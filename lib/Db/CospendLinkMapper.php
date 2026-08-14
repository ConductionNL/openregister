<?php

/**
 * Mapper for cospend link entities.
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
 * Class CospendLinkMapper
 *
 * @template-extends QBMapper<CospendLink>
 */
class CospendLinkMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_cospend_links', entityClass: CospendLink::class);
	}//end __construct()

	/**
	 * Find cospend links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return CospendLink[] Array of cospend links.
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
	 * Find a cospend link by id, scoped to the owning object UUID.
	 *
	 * The object UUID scope guards against deleting another object's link
	 * by guessing an entry id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $entryId The link row id.
	 *
	 * @return CospendLink|null The link or null if not found.
	 */
	public function findByObjectAndId(string $objectUuid, int $entryId): ?CospendLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectAndId()

	/**
	 * Find a duplicate link by the composite-unique tuple.
	 *
	 * Bill rows match on (object_uuid, entry_type, project_id, bill_id);
	 * project rows pass billId = null which the query matches with an
	 * IS NULL predicate.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $entryType The entry type (`project`|`bill`).
	 * @param string $projectId The Cospend project id.
	 * @param int|null $billId The Cospend bill id, or null for projects.
	 *
	 * @return CospendLink|null The existing link or null.
	 */
	public function findDuplicate(
		string $objectUuid,
		string $entryType,
		string $projectId,
		?int $billId,
	): ?CospendLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('entry_type', $qb->createNamedParameter($entryType)))
			->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)));

		if ($billId === null) {
			$qb->andWhere($qb->expr()->isNull('bill_id'));
		}

		if ($billId !== null) {
			$qb->andWhere($qb->expr()->eq('bill_id', $qb->createNamedParameter($billId, IQueryBuilder::PARAM_INT)));
		}

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findDuplicate()

	/**
	 * Delete all cospend links for an object UUID.
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
	 * Delete a cospend link by object UUID + link row id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $entryId The link row id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndId(string $objectUuid, int $entryId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteByObjectAndId()
}//end class
