<?php

/**
 * Mapper for collective link entities.
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
 * Class CollectiveLinkMapper
 *
 * @template-extends QBMapper<CollectiveLink>
 */
class CollectiveLinkMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_collective_links', entityClass: CollectiveLink::class);
    }//end __construct()

    /**
     * Find collective links by object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return CollectiveLink[] Array of collective links.
     */
    public function findByObjectUuid(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByObjectUuid()

    /**
     * Find collective links by page id.
     *
     * @param int $pageId The page id.
     *
     * @return CollectiveLink[] Array of collective links.
     */
    public function findByPageId(int $pageId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId, IQueryBuilder::PARAM_INT)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByPageId()

    /**
     * Find a specific collective link by object UUID and page id.
     *
     * @param string $objectUuid The object UUID.
     * @param int    $pageId     The page id.
     *
     * @return CollectiveLink|null The link or null if not found.
     */
    public function findByObjectAndPage(string $objectUuid, int $pageId): ?CollectiveLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }//end findByObjectAndPage()

    /**
     * Delete all collective links for an object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByObjectUuid(string $objectUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

        return $qb->executeStatement();
    }//end deleteByObjectUuid()

    /**
     * Delete a collective link by object UUID + page id (Tier-2 unlink path).
     *
     * Returns the number of rows actually deleted so callers can
     * distinguish "no such link" (0) from "ok" (>=1).
     *
     * @param string $objectUuid The object UUID.
     * @param int    $pageId     The page id.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByObjectAndPage(string $objectUuid, int $pageId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }//end deleteByObjectAndPage()
}//end class
