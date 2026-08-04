<?php

/**
 * Mapper for map link entities.
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
 * Class MapLinkMapper
 *
 * @template-extends QBMapper<MapLink>
 */
class MapLinkMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_map_links', entityClass: MapLink::class);
    }//end __construct()

    /**
     * Find map links by object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return MapLink[] Array of map links.
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
     * Find map links by favorite id.
     *
     * @param int $favoriteId The favorite id.
     *
     * @return MapLink[] Array of map links.
     */
    public function findByFavoriteId(int $favoriteId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('favorite_id', $qb->createNamedParameter($favoriteId, IQueryBuilder::PARAM_INT)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByFavoriteId()

    /**
     * Find a specific map link by object UUID and favorite id.
     *
     * @param string $objectUuid The object UUID.
     * @param int    $favoriteId The favorite id.
     *
     * @return MapLink|null The link or null if not found.
     */
    public function findByObjectAndFavorite(string $objectUuid, int $favoriteId): ?MapLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('favorite_id', $qb->createNamedParameter($favoriteId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }//end findByObjectAndFavorite()

    /**
     * Delete all map links for an object UUID.
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
     * Delete a map link by object UUID + favorite id (Tier-2 unlink path).
     *
     * Returns the number of rows actually deleted so callers can
     * distinguish "no such link" (0) from "ok" (>=1).
     *
     * @param string $objectUuid The object UUID.
     * @param int    $favoriteId The favorite id.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByObjectAndFavorite(string $objectUuid, int $favoriteId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('favorite_id', $qb->createNamedParameter($favoriteId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }//end deleteByObjectAndFavorite()
}//end class
