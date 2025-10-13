<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
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
    }

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
    }

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
    }

    /**
     * Find all files with specific extraction status
     *
     * @param string $status Status to filter by (pending, processing, completed, failed)
     * @param int    $limit  Maximum number of results
     * @param int    $offset Offset for pagination
     * 
     * @return array<FileText>
     */
    public function findByStatus(string $status, int $limit = 100, int $offset = 0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('extraction_status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR)))
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find files that need extraction (pending or failed with old timestamp)
     *
     * @param int $limit Maximum number of results
     * 
     * @return array<FileText>
     */
    public function findPendingExtractions(int $limit = 100): array
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
    }

    /**
     * Find files not yet indexed in SOLR
     *
     * @param int $limit Maximum number of results
     * 
     * @return array<FileText>
     */
    public function findNotIndexedInSolr(int $limit = 100): array
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
    }

    /**
     * Find files not yet vectorized
     *
     * @param int $limit Maximum number of results
     * 
     * @return array<FileText>
     */
    public function findNotVectorized(int $limit = 100): array
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
    }

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
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    /**
     * Get extraction statistics
     *
     * @return array{total: int, pending: int, processing: int, completed: int, failed: int, indexed: int, vectorized: int, total_text_size: int}
     */
    public function getStats(): array
    {
        $qb = $this->db->getQueryBuilder();

        // Total count
        $qb->select($qb->createFunction('COUNT(*) as total'))
            ->from($this->getTableName());
        $result = $qb->execute();
        $total = (int) $result->fetchOne();
        $result->closeCursor();

        return [
            'total' => $total,
            'pending' => $this->countByStatus('pending'),
            'processing' => $this->countByStatus('processing'),
            'completed' => $this->countByStatus('completed'),
            'failed' => $this->countByStatus('failed'),
            'indexed' => $this->countIndexed(),
            'vectorized' => $this->countVectorized(),
            'total_text_size' => $this->getTotalTextSize(),
        ];
    }

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
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }

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
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }

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
        $size = (int) $result->fetchOne();
        $result->closeCursor();

        return $size;
    }

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
    }
}

