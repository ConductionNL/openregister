<?php
/**
 * OpenRegister FileText Mapper
 *
 * Mapper for FileText entities to handle database operations.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * FileTextMapper
 *
 * Mapper for FileText entities to handle database operations.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 *
 * @template-extends QBMapper<FileText>
 *
 * @method FileText insert(Entity $entity)
 * @method FileText update(Entity $entity)
 * @method FileText insertOrUpdate(Entity $entity)
 * @method FileText delete(Entity $entity)
 * @method FileText find(int|string $id)
 * @method FileText findEntity(IQueryBuilder $query)
 * @method FileText[] findAll(int|null $limit = null, int|null $offset = null)
 * @method FileText[] findEntities(IQueryBuilder $query)
 */
class FileTextMapper extends QBMapper
{


    /**
     * Constructor
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_file_texts', FileText::class);

    }//end __construct()


    /**
     * Find file text by file ID
     *
     * @param int $fileId Nextcloud file ID
     *
     * @return FileText
     * @throws DoesNotExistException If not found
     * @throws MultipleObjectsReturnedException If multiple found
     */
    public function findByFileId(int $fileId): FileText
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);

    }//end findByFileId()


    /**
     * Check if file text exists for file ID
     *
     * @param int $fileId Nextcloud file ID
     *
     * @return bool True if exists
     */
    public function existsByFileId(int $fileId): bool
    {
        try {
            $this->findByFileId($fileId);
            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }

    }//end existsByFileId()


    /**
     * Insert a new file text entity
     *
     * @param Entity $entity FileText entity to insert
     *
     * @return Entity The inserted file text with updated ID
     */
    public function insert(Entity $entity): Entity
    {
        if ($entity instanceof FileText) {
            // Generate UUID if not set.
            if (empty($entity->getUuid()) === true) {
                $entity->setUuid(
                        \OC::$server->get(\OCP\Security\ISecureRandom::class)->generate(
                    36,
                    \OCP\Security\ISecureRandom::CHAR_ALPHANUMERIC
                )
                        );
            }
        }

        return parent::insert($entity);

    }//end insert()


    /**
     * Find all file texts with pagination, excluding directories
     *
     * @param int|null $limit  Maximum number of results
     * @param int|null $offset Offset for pagination
     *
     * @return array<FileText>
     */
    public function findAll(?int $limit=100, ?int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('ft.*')
            ->from($this->getTableName(), 'ft')
            ->leftJoin('ft', 'filecache', 'fc', $qb->expr()->eq('ft.file_id', 'fc.fileid'))
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->where($qb->expr()->neq('mt.mimetype', $qb->createNamedParameter('httpd/unix-directory', IQueryBuilder::PARAM_STR)))
            ->orderBy('ft.created_at', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null && $offset > 0) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Find all files with specific extraction status, excluding directories
     *
     * @param string $status Status to filter by (pending, processing, completed, failed)
     * @param int    $limit  Maximum number of results
     * @param int    $offset Offset for pagination
     *
     * @return array<FileText>
     */
    public function findByStatus(string $status, int $limit=100, int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('ft.*')
            ->from($this->getTableName(), 'ft')
            ->leftJoin('ft', 'filecache', 'fc', $qb->expr()->eq('ft.file_id', 'fc.fileid'))
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->where($qb->expr()->eq('ft.extraction_status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->neq('mt.mimetype', $qb->createNamedParameter('httpd/unix-directory', IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('ft.created_at', 'ASC');

        return $this->findEntities($qb);

    }//end findByStatus()


    /**
     * Find files that need extraction (pending or failed with old timestamp)
     *
     * @param int $limit Maximum number of results
     *
     * @return array<FileText>
     */
    public function findPendingExtractions(int $limit=100): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('extraction_status', $qb->createNamedParameter('pending', IQueryBuilder::PARAM_STR)),
                    $qb->expr()->andX(
                        $qb->expr()->eq('extraction_status', $qb->createNamedParameter('failed', IQueryBuilder::PARAM_STR)),
                        $qb->expr()->lt('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s', strtotime('-1 hour')), IQueryBuilder::PARAM_STR))
                    )
                )
            )
            ->setMaxResults($limit)
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);

    }//end findPendingExtractions()


    /**
     * Find completed extractions
     *
     * @param int|null $limit Maximum number of results (null = no limit)
     *
     * @return array<FileText>
     */
    public function findCompleted(?int $limit=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('extraction_status', $qb->createNamedParameter('completed', IQueryBuilder::PARAM_STR)))
            ->orderBy('extracted_at', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $this->findEntities($qb);

    }//end findCompleted()


    /**
     * Find files not yet indexed in SOLR
     *
     * @param int $limit Maximum number of results
     *
     * @return array<FileText>
     */
    public function findNotIndexedInSolr(int $limit=100): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('extraction_status', $qb->createNamedParameter('completed', IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('indexed_in_solr', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
                )
            )
            ->setMaxResults($limit)
            ->orderBy('extracted_at', 'ASC');

        return $this->findEntities($qb);

    }//end findNotIndexedInSolr()


    /**
     * Find files not yet vectorized
     *
     * @param int $limit Maximum number of results
     *
     * @return array<FileText>
     */
    public function findNotVectorized(int $limit=100): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('extraction_status', $qb->createNamedParameter('completed', IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('vectorized', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
                )
            )
            ->setMaxResults($limit)
            ->orderBy('extracted_at', 'ASC');

        return $this->findEntities($qb);

    }//end findNotVectorized()


    /**
     * Count files by status
     *
     * @param string $status Status to count
     *
     * @return int Count of files
     */
    public function countByStatus(string $status): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('extraction_status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR)));

        $result = $qb->execute();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countByStatus()


    /**
     * Get extraction statistics
     *
     * @return array{total: int, pending: int, processing: int, completed: int, failed: int, indexed: int, vectorized: int, total_text_size: int}
     */
    public function getStats(): array
    {
        $qb = $this->db->getQueryBuilder();

        // Total count.
        $qb->select($qb->createFunction('COUNT(*) as total'))
            ->from($this->getTableName());
        $result = $qb->execute();
        $total  = (int) $result->fetchOne();
        $result->closeCursor();

        return [
            'total'           => $total,
            'pending'         => $this->countByStatus('pending'),
            'processing'      => $this->countByStatus('processing'),
            'completed'       => $this->countByStatus('completed'),
            'failed'          => $this->countByStatus('failed'),
            'indexed'         => $this->countIndexed(),
            'vectorized'      => $this->countVectorized(),
            'total_text_size' => $this->getTotalTextSize(),
            'totalChunks'     => $this->getTotalChunks(),
        ];

    }//end getStats()


    /**
     * Get total number of chunks across all files
     *
     * @return int Total chunk count
     */
    private function getTotalChunks(): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('SUM(chunk_count) as total_chunks'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('extraction_status', $qb->createNamedParameter('completed', IQueryBuilder::PARAM_STR)));

        $result = $qb->execute();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end getTotalChunks()


    /**
     * Get file types with their file and chunk counts
     *
     * Only returns file types that have completed extractions with chunks
     *
     * @return array Array of file types with counts
     */
    public function getFileTypeStats(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('mime_type')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'file_count')
            ->selectAlias($qb->createFunction('SUM(chunk_count)'), 'chunk_count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('extraction_status', $qb->createNamedParameter('completed', IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->gt('chunk_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->groupBy('mime_type')
            ->orderBy('file_count', 'DESC');

        $result = $qb->executeQuery();
        $types  = [];

        while (($row = $result->fetch()) !== false) {
            $types[] = [
                'mime'       => $row['mime_type'],
                'name'       => $this->formatMimeType($row['mime_type']),
                'fileCount'  => (int) $row['file_count'],
                'chunkCount' => (int) $row['chunk_count'],
            ];
        }

        $result->closeCursor();

        return $types;

    }//end getFileTypeStats()


    /**
     * Format MIME type for display
     *
     * @param string $mimeType MIME type
     *
     * @return string Formatted name
     */
    private function formatMimeType(string $mimeType): string
    {
        $mimeTypeMap = [
            'application/pdf'                                                         => 'PDF',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
            'application/msword'                                                      => 'DOC',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'XLSX',
            'application/vnd.ms-excel'                                                => 'XLS',
            'text/plain'                                                              => 'Text',
            'text/markdown'                                                           => 'Markdown',
            'text/html'                                                               => 'HTML',
            'application/json'                                                        => 'JSON',
            'text/xml'                                                                => 'XML',
            'application/xml'                                                         => 'XML',
            'text/csv'                                                                => 'CSV',
        ];

        return $mimeTypeMap[$mimeType] ?? strtoupper(explode('/', $mimeType)[1] ?? 'Unknown');

    }//end formatMimeType()


    /**
     * Count indexed files
     *
     * @return int Count of indexed files
     */
    private function countIndexed(): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('indexed_in_solr', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $result = $qb->execute();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countIndexed()


    /**
     * Count vectorized files
     *
     * @return int Count of vectorized files
     */
    private function countVectorized(): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('vectorized', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $result = $qb->execute();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countVectorized()


    /**
     * Get total text size stored
     *
     * @return int Total text length in characters
     */
    private function getTotalTextSize(): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('SUM(text_length) as total_size'))
            ->from($this->getTableName());

        $result = $qb->execute();
        $size   = (int) $result->fetchOne();
        $result->closeCursor();

        return $size;

    }//end getTotalTextSize()


    /**
     * Delete by file ID
     *
     * @param int $fileId Nextcloud file ID
     *
     * @return void
     */
    public function deleteByFileId(int $fileId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $qb->execute();

    }//end deleteByFileId()


    /**
     * Clean up invalid file_texts entries
     *
     * Removes entries for:
     * - Files that no longer exist in filecache
     * - Directories (httpd/unix-directory mimetype)
     * - System files (appdata, trash, versions, etc.)
     * - Files from non-user storages
     *
     * @return array{deleted: int, reasons: array<string, int>} Statistics about deleted entries
     */
    public function cleanupInvalidEntries(): array
    {
        $deleted = 0;
        $reasons = [
            'file_not_found'   => 0,
            'is_directory'     => 0,
            'system_file'      => 0,
            'non_user_storage' => 0,
        ];

        // Find entries where file doesn't exist in filecache.
        $qb = $this->db->getQueryBuilder();
        $qb->select('ft.id', 'ft.file_id')
            ->from($this->getTableName(), 'ft')
            ->leftJoin('ft', 'filecache', 'fc', $qb->expr()->eq('ft.file_id', 'fc.fileid'))
            ->where($qb->expr()->isNull('fc.fileid'));

        $result      = $qb->executeQuery();
        $idsToDelete = [];
        while (($row = $result->fetch()) !== false) {
            $idsToDelete[] = $row['id'];
            $reasons['file_not_found']++;
        }

        $result->closeCursor();

        // Find entries that are directories.
        $qb = $this->db->getQueryBuilder();
        $qb->select('ft.id')
            ->from($this->getTableName(), 'ft')
            ->leftJoin('ft', 'filecache', 'fc', $qb->expr()->eq('ft.file_id', 'fc.fileid'))
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->where($qb->expr()->eq('mt.mimetype', $qb->createNamedParameter('httpd/unix-directory', IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        while ($row !== false) {
            if (in_array($row['id'], $idsToDelete) === false) {
                $idsToDelete[] = $row['id'];
                $reasons['is_directory']++;
            }

            $row = $result->fetch();
        }

        $result->closeCursor();

        // Find entries from non-user storages or system paths.
        $qb = $this->db->getQueryBuilder();
        $qb->select('ft.id')
            ->from($this->getTableName(), 'ft')
            ->leftJoin('ft', 'filecache', 'fc', $qb->expr()->eq('ft.file_id', 'fc.fileid'))
            ->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->notLike(
                        'st.id',
                        $qb->createNamedParameter('home::%', IQueryBuilder::PARAM_STR)
                    ),
                    $qb->expr()->like('fc.path', $qb->createNamedParameter('%files_trashbin%', IQueryBuilder::PARAM_STR)),
                    $qb->expr()->like('fc.path', $qb->createNamedParameter('appdata_%', IQueryBuilder::PARAM_STR)),
                    $qb->expr()->like('fc.path', $qb->createNamedParameter('%files_versions%', IQueryBuilder::PARAM_STR)),
                    $qb->expr()->like('fc.path', $qb->createNamedParameter('%cache%', IQueryBuilder::PARAM_STR)),
                    $qb->expr()->like('fc.path', $qb->createNamedParameter('%thumbnails%', IQueryBuilder::PARAM_STR))
                )
            );

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        while ($row !== false) {
            if (in_array($row['id'], $idsToDelete) === false) {
                $idsToDelete[] = $row['id'];
                $reasons['system_file']++;
            }

            $row = $result->fetch();
        }

        $result->closeCursor();

        // Delete all collected IDs.
        if (empty($idsToDelete) === false) {
            // Delete in batches of 1000 to avoid query size limits.
            $chunks = array_chunk($idsToDelete, 1000);
            foreach ($chunks as $chunk) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($this->getTableName())
                    ->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
                $deleted += $qb->executeStatement();
            }
        }

        return [
            'deleted' => $deleted,
            'reasons' => $reasons,
        ];

    }//end cleanupInvalidEntries()


}//end class
