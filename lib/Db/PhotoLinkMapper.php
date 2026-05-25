<?php

/**
 * Mapper for photo link entities.
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
 * Class PhotoLinkMapper
 *
 * @template-extends QBMapper<PhotoLink>
 */
class PhotoLinkMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_photo_links', entityClass: PhotoLink::class);
    }//end __construct()

    /**
     * Find photo links by object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return PhotoLink[] Array of photo links.
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
     * Find photo links by album id.
     *
     * @param int $albumId The album id.
     *
     * @return PhotoLink[] Array of photo links.
     */
    public function findByAlbumId(int $albumId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('album_id', $qb->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByAlbumId()

    /**
     * Find a specific photo link by object UUID and album id.
     *
     * @param string $objectUuid The object UUID.
     * @param int    $albumId    The album id.
     *
     * @return PhotoLink|null The link or null if not found.
     */
    public function findByObjectAndAlbum(string $objectUuid, int $albumId): ?PhotoLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('album_id', $qb->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }//end findByObjectAndAlbum()

    /**
     * Delete all photo links for an object UUID.
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
     * Delete a photo link by object UUID + album id (Tier-2 unlink path).
     *
     * Returns the number of rows actually deleted so callers can
     * distinguish "no such link" (0) from "ok" (>=1).
     *
     * @param string $objectUuid The object UUID.
     * @param int    $albumId    The album id.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByObjectAndAlbum(string $objectUuid, int $albumId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('album_id', $qb->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }//end deleteByObjectAndAlbum()
}//end class
