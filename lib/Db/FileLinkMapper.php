<?php

/**
 * Mapper for FileLink entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-photos/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class FileLinkMapper
 *
 * @template-extends QBMapper<FileLink>
 */
class FileLinkMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_file_links', entityClass: FileLink::class);
    }//end __construct()

    /**
     * Find all file links for a given object UUID.
     *
     * @param string $objectUuid The OR object UUID.
     *
     * @return FileLink[] Array of file links ordered by linked_at DESC.
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
     * Find image file links for a given object UUID (MIME type starts with image/).
     *
     * @param string $objectUuid The OR object UUID.
     *
     * @return FileLink[] Array of image file links ordered by linked_at DESC.
     */
    public function findImagesByObjectUuid(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->like('mime_type', $qb->createNamedParameter('image/%')))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findImagesByObjectUuid()

    /**
     * Find a specific file link by object UUID and NC file ID.
     *
     * @param string $objectUuid The OR object UUID.
     * @param int    $fileId     The Nextcloud file ID.
     *
     * @return FileLink|null The link or null if not found.
     */
    public function findByObjectAndFile(string $objectUuid, int $fileId): ?FileLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findByObjectAndFile()

    /**
     * Find a file link by its ID.
     *
     * @param int $id The link row ID.
     *
     * @return FileLink|null
     */
    public function findById(int $id): ?FileLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findById()
}//end class
