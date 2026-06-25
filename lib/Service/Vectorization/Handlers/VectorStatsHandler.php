<?php

/**
 * Vector Statistics Handler
 *
 * Handles gathering statistics about stored vectors from database and Solr.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vectorization\Handlers;

use Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * VectorStatsHandler
 *
 * Responsible for gathering statistics about stored vectors from the PostgreSQL database.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
class VectorStatsHandler
{
    /**
     * Constructor
     *
     * @param IDBConnection   $db     Database connection
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Get vector statistics from the PostgreSQL database
     *
     * @return ((int|mixed)[]|int|string)[] Statistics about stored vectors
     *
     * @psalm-return array{total_vectors: int, by_type: array<int>,
     *     by_model: array<int|mixed>, object_vectors: int, file_vectors: int}
     *
     * @spec openspec/changes/retrofit-2026-05-24-newcap-vector-embeddings/tasks.md#task-5
     */
    public function getStats(): array
    {
        try {
            return $this->getStatsFromDatabase();
        } catch (Exception $e) {
            $this->logger->error(
                message: '[VectorStatsHandler] Failed to get vector stats',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return [
                'total_vectors'  => 0,
                'by_type'        => [],
                'by_model'       => [],
                'object_vectors' => 0,
                'file_vectors'   => 0,
            ];
        }//end try
    }//end getStats()

    /**
     * Get vector statistics from database
     *
     * @return (int[])[] Statistics from database
     *
     * @psalm-return array{total_vectors: int, by_type: array<int>,
     *     by_model: array<int>, object_vectors: int, file_vectors: int}
     *
     * @spec openspec/changes/retrofit-2026-05-24-newcap-vector-embeddings/tasks.md#task-5
     */
    private function getStatsFromDatabase(): array
    {
        $qb = $this->db->getQueryBuilder();

        // Total vectors.
        $qb->select($qb->func()->count('id', 'total'))
            ->from('openregister_vectors');
        $total = (int) $qb->executeQuery()->fetchOne();

        // By entity type.
        $qb = $this->db->getQueryBuilder();
        $qb->select('entity_type', $qb->func()->count('id', 'count'))
            ->from('openregister_vectors')
            ->groupBy('entity_type');
        $result = $qb->executeQuery();
        $byType = [];
        while (($row = $result->fetch()) !== false) {
            $byType[$row['entity_type']] = (int) $row['count'];
        }

        $result->closeCursor();

        // By model.
        $qb = $this->db->getQueryBuilder();
        $qb->select('embedding_model', $qb->func()->count('id', 'count'))
            ->from('openregister_vectors')
            ->groupBy('embedding_model');
        $result  = $qb->executeQuery();
        $byModel = [];
        while (($row = $result->fetch()) !== false) {
            $byModel[$row['embedding_model']] = (int) $row['count'];
        }

        $result->closeCursor();

        return [
            'total_vectors'  => $total,
            'by_type'        => $byType,
            'by_model'       => $byModel,
            'object_vectors' => $byType['object'] ?? 0,
            'file_vectors'   => $byType['file'] ?? 0,
        ];
    }//end getStatsFromDatabase()
}//end class
