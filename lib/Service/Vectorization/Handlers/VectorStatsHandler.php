<?php

/**
 * Vector Statistics Handler
 *
 * Handles gathering statistics about stored vectors from database and Solr.
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
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;

/**
 * VectorStatsHandler
 *
 * Responsible for gathering statistics about stored vectors.
 * Supports both database and Solr backends.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
class VectorStatsHandler
{


    /**
     * Constructor
     *
     * @param IDBConnection   $db              Database connection
     * @param SettingsService $settingsService Settings service
     * @param IndexService    $indexService    Index service for Solr
     * @param LoggerInterface $logger          PSR-3 logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly SettingsService $settingsService,
        private readonly IndexService $indexService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Get vector statistics
     *
     * @param string $backend Backend to use ('php', 'database', or 'solr')
     *
     * @return ((int|mixed)[]|int|string)[] Statistics about stored vectors
     *
     * @psalm-return array{total_vectors: int, by_type: array<int>, by_model: array<int|mixed>, object_vectors?: int, file_vectors?: int, source?: 'solr'|'solr_error'|'solr_unavailable'}
     */
    public function getStats(string $backend='php'): array
    {
        try {
            if ($backend === 'solr') {
                return $this->getStatsFromSolr();
            }

            // Default: get stats from database.
            return $this->getStatsFromDatabase();
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to get vector stats',
                context: ['error' => $e->getMessage()]
            );
            return [
                'total_vectors' => 0,
                'by_type'       => [],
                'by_model'      => [],
            ];
        }//end try

    }//end getStats()


    /**
     * Get vector statistics from database
     *
     * @return array Statistics from database
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


    /**
     * Get vector statistics from Solr collections
     *
     * @return (array|int|string)[] Vector statistics from Solr
     *
     * @psalm-return array{
     *     total_vectors: int,
     *     by_type: array{object?: int, file?: int},
     *     by_model: array,
     *     object_vectors: int,
     *     file_vectors: int,
     *     source: 'solr'|'solr_error'|'solr_unavailable'
     * }
     */
    private function getStatsFromSolr(): array
    {
        try {
            $solrBackend = $this->indexService->getBackend('solr');
            if ($solrBackend === null || $solrBackend->isAvailable() === false) {
                $this->logger->warning(
                    message: '[VectorStatsHandler] Solr not available for stats'
                );
                return [
                    'total_vectors'  => 0,
                    'by_type'        => [],
                    'by_model'       => [],
                    'object_vectors' => 0,
                    'file_vectors'   => 0,
                    'source'         => 'solr_unavailable',
                ];
            }

            $settings         = $this->settingsService->getSettings();
            $vectorField      = $settings['llm']['vectorConfig']['solrField'] ?? '_embedding_';
            $objectCollection = $settings['solr']['objectCollection'] ?? $settings['solr']['collection'] ?? null;
            $fileCollection   = $settings['solr']['fileCollection'] ?? null;

            $objectCount = 0;
            $fileCount   = 0;
            $byModel     = [];

            // Count objects with embeddings.
            if ($objectCollection !== null && $objectCollection !== '') {
                try {
                    $objectStats = $this->countVectorsInCollection(
                        $objectCollection,
                        $vectorField,
                        $solrBackend
                    );
                    $objectCount = $objectStats['count'];
                    $byModel     = array_merge($byModel, $objectStats['by_model']);
                } catch (Exception $e) {
                    $this->logger->warning(
                        message: '[VectorStatsHandler] Failed to get object vector stats from Solr',
                        context: ['error' => $e->getMessage()]
                    );
                }
            }

            // Count files with embeddings.
            if ($fileCollection !== null && $fileCollection !== '') {
                try {
                    $fileStats = $this->countVectorsInCollection(
                        $fileCollection,
                        $vectorField,
                        $solrBackend
                    );
                    $fileCount = $fileStats['count'];
                    foreach ($fileStats['by_model'] as $model => $count) {
                        $byModel[$model] = ($byModel[$model] ?? 0) + $count;
                    }
                } catch (Exception $e) {
                    $this->logger->warning(
                        message: '[VectorStatsHandler] Failed to get file vector stats from Solr',
                        context: ['error' => $e->getMessage()]
                    );
                }
            }

            $total = $objectCount + $fileCount;

            return [
                'total_vectors'  => $total,
                'by_type'        => [
                    'object' => $objectCount,
                    'file'   => $fileCount,
                ],
                'by_model'       => $byModel,
                'object_vectors' => $objectCount,
                'file_vectors'   => $fileCount,
                'source'         => 'solr',
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: '[VectorStatsHandler] Failed to get vector stats from Solr',
                context: ['error' => $e->getMessage()]
            );
            return [
                'total_vectors'  => 0,
                'by_type'        => [],
                'by_model'       => [],
                'object_vectors' => 0,
                'file_vectors'   => 0,
                'source'         => 'solr_error',
            ];
        }//end try

    }//end getStatsFromSolr()


    /**
     * Count vectors in a specific Solr collection
     *
     * @param string $collection  Collection name
     * @param string $vectorField Vector field name
     * @param mixed  $solrBackend Solr backend instance
     *
     * @return array{count: int, by_model: array} Count and breakdown by model
     */
    private function countVectorsInCollection(
        string $collection,
        string $vectorField,
        mixed $solrBackend
    ): array {
        // Get Solr configuration for authentication.
        $settings   = $this->settingsService->getSettings();
        $solrConfig = $settings['solr'] ?? [];

        // Build request options.
        $options = [
            'query' => [
                'q'           => "{$vectorField}:*",
                'rows'        => 0,
                'wt'          => 'json',
                'facet'       => 'true',
                'facet.field' => '_embedding_model_',
            ],
        ];

        // Add HTTP authentication if configured.
        if (empty($solrConfig['username']) === false && empty($solrConfig['password']) === false) {
            $options['auth'] = [$solrConfig['username'], $solrConfig['password']];
        }

        // Query Solr.
        $solrUrl  = $solrBackend->buildSolrBaseUrl()."/{$collection}/select";
        $response = $solrBackend->getHttpClient()->get($solrUrl, $options);

        $data  = json_decode((string) $response->getBody(), true);
        $count = $data['response']['numFound'] ?? 0;

        // Extract model counts from facets.
        $byModel = [];
        if (($data['facet_counts']['facet_fields']['_embedding_model_'] ?? null) !== null) {
            $facets     = $data['facet_counts']['facet_fields']['_embedding_model_'];
            $facetCount = count($facets);
            for ($i = 0; $i < $facetCount; $i += 2) {
                if (($facets[$i] ?? null) !== null && ($facets[$i + 1] ?? null) !== null) {
                    $modelName  = $facets[$i];
                    $modelCount = $facets[$i + 1];
                    if ($modelName !== null && $modelName !== '' && $modelCount > 0) {
                        $byModel[$modelName] = $modelCount;
                    }
                }
            }
        }

        return [
            'count'    => $count,
            'by_model' => $byModel,
        ];

    }//end countVectorsInCollection()


}//end class

