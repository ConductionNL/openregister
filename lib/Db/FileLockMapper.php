<?php

/**
 * OpenRegister FileLock Mapper
 *
 * This file contains the class for the FileLock mapper.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class FileLockMapper
 *
 * Mapper for FileLock entities. Backs FileLockHandler's advisory file locks
 * with real storage so a lock survives past the request that created it.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method FileLock insert(Entity $entity)
 * @method FileLock update(Entity $entity)
 * @method FileLock insertOrUpdate(Entity $entity)
 * @method FileLock delete(Entity $entity)
 * @method FileLock find(int|string $id)
 * @method FileLock findEntity(\OCP\DB\QueryBuilder\IQueryBuilder $query)
 * @method FileLock[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<FileLock> findEntities(\OCP\DB\QueryBuilder\IQueryBuilder $query)
 *
 * @template-extends QBMapper<FileLock>
 */
class FileLockMapper extends QBMapper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_file_locks', entityClass: FileLock::class);
    }//end __construct()

    /**
     * Find the lock row for a given file, if any.
     *
     * @param int $fileId The Nextcloud filecache file ID.
     *
     * @return FileLock|null The lock, or null if the file has no lock row.
     *
     * @throws MultipleObjectsReturnedException If more than one lock row exists for the file.
     */
    public function findByFileId(int $fileId): ?FileLock
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }//end findByFileId()

    /**
     * Delete the lock row for a given file, if any.
     *
     * @param int $fileId The Nextcloud filecache file ID.
     *
     * @return void
     */
    public function deleteByFileId(int $fileId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }//end deleteByFileId()
}//end class
