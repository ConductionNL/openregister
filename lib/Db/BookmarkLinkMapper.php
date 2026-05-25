<?php

/**
 * Mapper for bookmark link entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class BookmarkLinkMapper
 *
 * @template-extends QBMapper<BookmarkLink>
 */
class BookmarkLinkMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_bookmark_links', entityClass: BookmarkLink::class);
    }//end __construct()

    /**
     * Find bookmark links by object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return BookmarkLink[] Array of bookmark links.
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
     * Find bookmark links by bookmark id.
     *
     * @param int $bookmarkId The bookmark id.
     *
     * @return BookmarkLink[] Array of bookmark links.
     */
    public function findByBookmarkId(int $bookmarkId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('bookmark_id', $qb->createNamedParameter($bookmarkId, IQueryBuilder::PARAM_INT)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByBookmarkId()

    /**
     * Find a specific bookmark link by object UUID and bookmark id.
     *
     * @param string $objectUuid The object UUID.
     * @param int    $bookmarkId The bookmark id.
     *
     * @return BookmarkLink|null The link or null if not found.
     */
    public function findByObjectAndBookmark(string $objectUuid, int $bookmarkId): ?BookmarkLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere(
                $qb->expr()->eq('bookmark_id', $qb->createNamedParameter($bookmarkId, IQueryBuilder::PARAM_INT))
            );

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }//end findByObjectAndBookmark()

    /**
     * Delete all bookmark links for an object UUID.
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
     * Delete a bookmark link by object UUID + bookmark id (Tier-2 unlink).
     *
     * Returns the number of rows actually deleted so callers can
     * distinguish "no such link" (0) from "ok" (>=1).
     *
     * @param string $objectUuid The object UUID.
     * @param int    $bookmarkId The bookmark id.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByObjectAndBookmark(string $objectUuid, int $bookmarkId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere(
                $qb->expr()->eq('bookmark_id', $qb->createNamedParameter($bookmarkId, IQueryBuilder::PARAM_INT))
            );

        return $qb->executeStatement();
    }//end deleteByObjectAndBookmark()
}//end class
