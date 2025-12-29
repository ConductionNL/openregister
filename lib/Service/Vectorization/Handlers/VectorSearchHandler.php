<?php

/**
 * Vector Search Handler
 *
 * Handles semantic and hybrid search operations using vectors.
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
use InvalidArgumentException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;

/**
 * VectorSearchHandler
 *
 * Responsible for searching vectors using semantic search and hybrid search.
 * Handles both database (cosine similarity) and Solr (KNN) backends.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
class VectorSearchHandler
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
     * Perform semantic similarity search
     *
     * @param array  $queryEmbedding Query embedding vector
     * @param int    $limit          Maximum number of results
     * @param array  $filters        Additional filters (entity_type, etc.)
     * @param string $backend        Search backend ('php', 'database', or 'solr')
     *
     * @return array<int,array<string,mixed>> Search results
     *
     * @throws \Exception If search fails
     */
    public function semanticSearch(
        array $queryEmbedding,
        int $limit=10,
        array $filters=[],
        string $backend='php'
    ): array {
        $startTime = microtime(true);

        $this->logger->info(
            message: '[VectorSearchHandler] Performing semantic search',
            context: [
                'backend' => $backend,
                'limit'   => $limit,
                'filters' => $filters,
            ]
        );

        try {
            // Route to appropriate backend for vector search.
            if ($backend === 'solr') {
                $results = $this->searchVectorsInSolr(
                    queryEmbedding: $queryEmbedding,
                    limit: $limit,
                    filters: $filters
                );
            } else {
                // Use PHP/database similarity calculation.
                $vectors = $this->fetchVectors($filters);

                if ($vectors === []) {
                    $this->logger->warning(
                        message: 'No vectors found in database',
                        context: ['filters' => $filters]
                    );
                    return [];
                }

                // Calculate cosine similarity for each vector.
                $results = [];
                foreach ($vectors as $vector) {
                    try {
                        $storedEmbedding = unserialize($vector['embedding']);

                        if (is_array($storedEmbedding) === false) {
                            continue;
                        }

                        $similarity = $this->cosineSimilarity(
                            vector1: $queryEmbedding,
                            vector2: $storedEmbedding
                        );

                        // Parse metadata.
                        $metadata = [];
                        if (empty($vector['metadata']) === false) {
                            $metadata = json_decode($vector['metadata'], true) ?? [];
                        }

                        $results[] = [
                            'vector_id'    => $vector['id'],
                            'entity_type'  => $vector['entity_type'],
                            'entity_id'    => $vector['entity_id'],
                            'similarity'   => $similarity,
                            'chunk_index'  => $vector['chunk_index'],
                            'total_chunks' => $vector['total_chunks'],
                            'chunk_text'   => $vector['chunk_text'],
                            'metadata'     => $metadata,
                            'model'        => $vector['embedding_model'],
                            'dimensions'   => $vector['embedding_dimensions'],
                        ];
                    } catch (Exception $e) {
                        $this->logger->warning(
                            message: 'Failed to process vector',
                            context: [
                                'vector_id' => $vector['id'],
                                'error'     => $e->getMessage(),
                            ]
                        );
                    }//end try
                }//end foreach

                // Sort by similarity descending.
                usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

                // Return top N results.
                $results = array_slice($results, 0, $limit);
            }//end if

            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                message: '[VectorSearchHandler] Semantic search completed',
                context: [
                    'backend'        => $backend,
                    'results_count'  => count($results),
                    'top_similarity' => $results[0]['similarity'] ?? 0,
                    'search_time_ms' => $searchTime,
                ]
            );

            return $results;
        } catch (Exception $e) {
            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error(
                message: 'Semantic search failed',
                context: [
                    'error'          => $e->getMessage(),
                    'search_time_ms' => $searchTime,
                ]
            );
            throw new Exception('Semantic search failed: '.$e->getMessage());
        }//end try
    }//end semanticSearch()

    /**
     * Search vectors in Solr using dense vector KNN
     *
     * @param array $queryEmbedding Query vector embedding
     * @param int   $limit          Maximum number of results
     * @param array $filters        Additional filters (entity_type, etc.)
     *
     * @return (array|float|int|mixed|null|string)[][]
     *
     * @throws \Exception If search fails or Solr is not configured
     *
     * @psalm-return list<array{chunk_index: 0|mixed, chunk_text: mixed|null, dimensions: 0|mixed, entity_id: string, entity_type: string, metadata: array, model: ''|mixed, similarity: float(0)|mixed, total_chunks: 1|mixed, vector_id: mixed}>
     */
    private function searchVectorsInSolr(
        array $queryEmbedding,
        int $limit=10,
        array $filters=[]
    ): array {
        $this->logger->debug(
            message: '[VectorSearchHandler] Searching vectors in Solr',
            context: [
                'limit'   => $limit,
                'filters' => $filters,
            ]
        );

        try {
            // Get Solr backend.
            $solrBackend = $this->indexService->getBackend();
            if ($solrBackend->isAvailable() === false) {
                throw new Exception('Solr service is not available');
            }

            $settings = $this->settingsService->getSettings();
            // Get vector field from LLM configuration, default to '_embedding_'.
            /*
             * @psalm-suppress InvalidArrayOffset
             */
            $vectorField = $settings['llm']['vectorConfig']['solrField'] ?? '_embedding_';
            $allResults  = [];

            // Determine which collections to search based on entity_type filter.
            $collectionsToSearch = $this->getCollectionsToSearch($filters);

            if ($collectionsToSearch === []) {
                throw new Exception('No Solr collections configured for vector search');
            }

            // Build Solr KNN query.
            $vectorString = '['.implode(', ', $queryEmbedding).']';
            $knnQuery     = "{!knn f={$vectorField} topK={$limit}}{$vectorString}";

            // Search each collection.
            foreach ($collectionsToSearch as $collectionInfo) {
                $collection = $collectionInfo['collection'];
                $entityType = $collectionInfo['type'];

                $queryParams = [
                    'q'    => $knnQuery,
                    'rows' => $limit,
                    'fl'   => '*,score',
                    'wt'   => 'json',
                ];

                /*
                 * @psalm-suppress UndefinedInterfaceMethod - buildSolrBaseUrl and getHttpClient exist on Solr backend implementation
                 */
                $solrUrl = $solrBackend->buildSolrBaseUrl()."/{$collection}/select";

                try {
                    /*
                     * @psalm-suppress UndefinedInterfaceMethod
                     */
                    $response = $solrBackend->getHttpClient()->get(
                        $solrUrl,
                        ['query' => $queryParams]
                    );

                    $responseData = json_decode((string) $response->getBody(), true);

                    if (isset($responseData['response']['docs']) === false) {
                        continue;
                    }

                    // Transform Solr documents.
                    foreach ($responseData['response']['docs'] as $doc) {
                        $allResults[] = [
                            'vector_id'    => $doc['id'],
                            'entity_type'  => $entityType,
                            'entity_id'    => $this->extractEntityId(
                                doc: $doc,
                                entityType: $entityType
                            ),
                            'similarity'   => $doc['score'] ?? 0.0,
                            'chunk_index'  => $doc['chunk_index'] ?? $doc['chunk_index_i'] ?? 0,
                            'total_chunks' => $doc['chunk_total'] ?? $doc['total_chunks_i'] ?? 1,
                            'chunk_text'   => $doc['chunk_text'] ?? $doc['chunk_text_txt'] ?? null,
                            'metadata'     => $doc,
                            'model'        => $doc['_embedding_model_'] ?? $doc['embedding_model_s'] ?? '',
                            'dimensions'   => $doc['_embedding_dim_'] ?? $doc['embedding_dimensions_i'] ?? 0,
                        ];
                    }
                } catch (Exception $e) {
                    $this->logger->warning(
                        message: '[VectorSearchHandler] Failed to search collection',
                        context: [
                            'collection' => $collection,
                            'error'      => $e->getMessage(),
                        ]
                    );
                }//end try
            }//end foreach

            // Sort all results by similarity and limit.
            usort($allResults, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
            $allResults = array_slice($allResults, 0, $limit);

            return $allResults;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[VectorSearchHandler] Solr vector search failed',
                context: ['error' => $e->getMessage()]
            );
            throw new Exception('Solr vector search failed: '.$e->getMessage());
        }//end try
    }//end searchVectorsInSolr()

    /**
     * Perform hybrid search combining keyword (SOLR) and semantic (vectors)
     *
     * Uses Reciprocal Rank Fusion (RRF) to combine results.
     *
     * @param array  $queryEmbedding Query embedding vector
     * @param array  $solrResults    SOLR keyword search results
     * @param int    $limit          Maximum results
     * @param array  $weights        Weights for each search type ['solr' => 0.5, 'vector' => 0.5]
     * @param string $backend        Vector search backend
     *
     * @return (((array|bool|float|int|mixed|null)[]|float|int)[]|float|int)[]
     *
     * @throws \Exception If hybrid search fails
     *
     * @psalm-return array{results: list<array{chunk_index: 0|mixed, chunk_text: mixed|null, combined_score: 0|float, entity_id: mixed, entity_type: mixed, in_solr: bool, in_vector: bool, metadata: array<never, never>|mixed, solr_rank: int|null, solr_score: mixed|null, vector_rank: int|null, vector_similarity: mixed|null}>, total: int<0, max>, search_time_ms: float, source_breakdown: array{vector_only: int<0, max>, solr_only: int<0, max>, both: int<0, max>}, weights: array{solr: float, vector: float}}
     */
    public function hybridSearch(
        array $queryEmbedding,
        array $solrResults=[],
        int $limit=20,
        array $weights=['solr' => 0.5, 'vector' => 0.5],
        string $backend='php'
    ): array {
        $startTime = microtime(true);

        try {
            // Validate and normalize weights.
            $solrWeight   = $weights['solr'] ?? 0.5;
            $vectorWeight = $weights['vector'] ?? 0.5;

            $totalWeight = $solrWeight + $vectorWeight;
            if ($totalWeight > 0) {
                $solrWeight   = $solrWeight / $totalWeight;
                $vectorWeight = $vectorWeight / $totalWeight;
            }

            // Perform vector semantic search.
            $vectorResults = [];
            if ($vectorWeight > 0) {
                try {
                    $vectorResults = $this->semanticSearch(
                        queryEmbedding: $queryEmbedding,
                        limit: $limit * 2,
                        filters: [],
                        backend: $backend
                    );
                } catch (Exception $e) {
                    $this->logger->warning(
                        message: 'Vector search failed in hybrid search',
                        context: ['error' => $e->getMessage()]
                    );
                }
            }

            // Combine results using Reciprocal Rank Fusion (RRF).
            $combined = $this->reciprocalRankFusion(
                vectorResults: $vectorResults,
                solrResults: $solrResults,
                vectorWeight: $vectorWeight,
                solrWeight: $solrWeight
            );

            // Return top N results.
            $finalResults = array_slice($combined, 0, $limit);
            $searchTime   = round((microtime(true) - $startTime) * 1000, 2);

            // Calculate source breakdown.
            $vectorOnly = 0;
            $solrOnly   = 0;
            $both       = 0;

            foreach ($finalResults as $result) {
                if ($result['in_vector'] === true && $result['in_solr'] === true) {
                    $both++;
                } else if ($result['in_vector'] === true) {
                    $vectorOnly++;
                } else if ($result['in_solr'] === true) {
                    $solrOnly++;
                }
            }

            return [
                'results'          => $finalResults,
                'total'            => count($finalResults),
                'search_time_ms'   => $searchTime,
                'source_breakdown' => [
                    'vector_only' => $vectorOnly,
                    'solr_only'   => $solrOnly,
                    'both'        => $both,
                ],
                'weights'          => [
                    'solr'   => $solrWeight,
                    'vector' => $vectorWeight,
                ],
            ];
        } catch (Exception $e) {
            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error(
                message: 'Hybrid search failed',
                context: [
                    'error'          => $e->getMessage(),
                    'search_time_ms' => $searchTime,
                ]
            );
            throw new Exception('Hybrid search failed: '.$e->getMessage());
        }//end try
    }//end hybridSearch()

    /**
     * Combine search results using Reciprocal Rank Fusion (RRF)
     *
     * @param array $vectorResults Results from vector search
     * @param array $solrResults   Results from SOLR search
     * @param float $vectorWeight  Weight for vector results (0-1)
     * @param float $solrWeight    Weight for SOLR results (0-1)
     *
     * @return (array|bool|float|int|mixed|null)[][]
     *
     * @psalm-return list<array{chunk_index: 0|mixed, chunk_text: mixed|null, combined_score: 0|float, entity_id: mixed, entity_type: mixed, in_solr: bool, in_vector: bool, metadata: array<never, never>|mixed, solr_rank: int|null, solr_score: mixed|null, vector_rank: int|null, vector_similarity: mixed|null}>
     */
    private function reciprocalRankFusion(
        array $vectorResults,
        array $solrResults,
        float $vectorWeight=0.5,
        float $solrWeight=0.5
    ): array {
        $k = 60;
        $combinedScores = [];

        // Process vector results.
        foreach ($vectorResults as $rank => $result) {
            $key = $result['entity_type'].'_'.$result['entity_id'];

            if (isset($combinedScores[$key]) === false) {
                $combinedScores[$key] = [
                    'entity_type'       => $result['entity_type'],
                    'entity_id'         => $result['entity_id'],
                    'chunk_index'       => $result['chunk_index'],
                    'chunk_text'        => $result['chunk_text'],
                    'metadata'          => $result['metadata'],
                    'vector_similarity' => $result['similarity'],
                    'solr_score'        => null,
                    'combined_score'    => 0,
                    'in_vector'         => false,
                    'in_solr'           => false,
                    'vector_rank'       => null,
                    'solr_rank'         => null,
                ];
            }

            $rrfScore = $vectorWeight / ($k + (int) $rank + 1);
            $combinedScores[$key]['combined_score'] += $rrfScore;
            $combinedScores[$key]['in_vector']       = true;
            $combinedScores[$key]['vector_rank']     = (int) $rank + 1;
        }//end foreach

        // Process SOLR results.
        foreach ($solrResults as $rank => $result) {
            $key = $result['entity_type'].'_'.$result['entity_id'];

            if (isset($combinedScores[$key]) === false) {
                $combinedScores[$key] = [
                    'entity_type'       => $result['entity_type'],
                    'entity_id'         => $result['entity_id'],
                    'chunk_index'       => $result['chunk_index'] ?? 0,
                    'chunk_text'        => $result['chunk_text'] ?? null,
                    'metadata'          => $result['metadata'] ?? [],
                    'vector_similarity' => null,
                    'solr_score'        => $result['score'],
                    'combined_score'    => 0,
                    'in_vector'         => false,
                    'in_solr'           => false,
                    'vector_rank'       => null,
                    'solr_rank'         => null,
                ];
            }

            $rrfScore = $solrWeight / ($k + (int) $rank + 1);
            $combinedScores[$key]['combined_score'] += $rrfScore;
            $combinedScores[$key]['in_solr']         = true;
            $combinedScores[$key]['solr_rank']       = (int) $rank + 1;
            $combinedScores[$key]['solr_score']      = $result['score'];
        }//end foreach

        // Convert to array and sort by combined score.
        $results = array_values($combinedScores);
        usort($results, fn($a, $b) => $b['combined_score'] <=> $a['combined_score']);

        return $results;
    }//end reciprocalRankFusion()

    /**
     * Fetch vectors from database with optional filters
     *
     * @param array $filters Filters (entity_type, entity_id, etc.)
     *
     * @return array<int, array> Vector records from database
     *
     * @throws \Exception If query fails
     */
    private function fetchVectors(array $filters=[]): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('openregister_vectors');

            // Apply filters.
            if (($filters['entity_type'] ?? null) !== null) {
                if (is_array($filters['entity_type']) === true) {
                    $qb->andWhere(
                        $qb->expr()->in(
                            'entity_type',
                            $qb->createNamedParameter(
                                $filters['entity_type'],
                                \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
                            )
                        )
                    );
                } else {
                    $qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($filters['entity_type'])));
                }
            }

            if (($filters['entity_id'] ?? null) !== null) {
                if (is_array($filters['entity_id']) === true) {
                    $qb->andWhere(
                        $qb->expr()->in(
                            'entity_id',
                            $qb->createNamedParameter(
                                $filters['entity_id'],
                                \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
                            )
                        )
                    );
                } else {
                    $qb->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($filters['entity_id'])));
                }
            }

            // Performance optimization: Limit vectors fetched.
            $maxVectors = $filters['max_vectors'] ?? 500;
            $qb->setMaxResults($maxVectors);
            $qb->orderBy('created_at', 'DESC');

            $result  = $qb->executeQuery();
            $vectors = $result->fetchAll();

            return $vectors;
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to fetch vectors',
                context: ['error' => $e->getMessage()]
            );
            throw new Exception('Failed to fetch vectors: '.$e->getMessage());
        }//end try
    }//end fetchVectors()

    /**
     * Calculate cosine similarity between two vectors
     *
     * @param array<float> $vector1 First vector
     * @param array<float> $vector2 Second vector
     *
     * @return float Similarity score (0-1, where 1 is identical)
     */
    private function cosineSimilarity(array $vector1, array $vector2): float
    {
        if (count($vector1) !== count($vector2)) {
            throw new InvalidArgumentException('Vectors must have same dimensions');
        }

        $dotProduct   = 0.0;
        $magnitude1   = 0.0;
        $magnitude2   = 0.0;
        $vectorLength = count($vector1);

        for ($i = 0; $i < $vectorLength; $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] ** 2;
            $magnitude2 += $vector2[$i] ** 2;
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 === 0.0 || $magnitude2 === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }//end cosineSimilarity()

    /**
     * Extract entity ID from Solr document based on entity type
     *
     * @param array  $doc        Solr document
     * @param string $entityType Entity type ('file' or 'object')
     *
     * @return string Entity ID
     */
    private function extractEntityId(array $doc, string $entityType): string
    {
        if ($entityType === 'file' || $entityType === 'files') {
            return (string) ($doc['file_id'] ?? $doc['file_id_l'] ?? '');
        }

        return $doc['self_uuid'] ?? $doc['self_object_id'] ?? $doc['id'] ?? '';
    }//end extractEntityId()

    /**
     * Get collections to search based on filters
     *
     * @param array $filters Search filters
     *
     * @return array<int,array{type:string,collection:string}> Collections to search
     */
    private function getCollectionsToSearch(array $filters): array
    {
        $collectionsToSearch = [];
        $settings            = $this->settingsService->getSettings();

        if (($filters['entity_type'] ?? null) !== null) {
            $entityTypes = is_array($filters['entity_type']) === true ? $filters['entity_type'] : [$filters['entity_type']];

            foreach ($entityTypes as $entityType) {
                $collection = $this->getSolrCollectionForEntityType(
                    entityType: $entityType,
                    settings: $settings
                );
                if ($collection !== null && $collection !== '') {
                    $collectionsToSearch[] = [
                        'type'       => $entityType,
                        'collection' => $collection,
                    ];
                }
            }
        } else {
            // Search both object and file collections.
            $objectCollection = $settings['solr']['objectCollection'] ?? $settings['solr']['collection'] ?? null;
            $fileCollection   = $settings['solr']['fileCollection'] ?? null;

            if ($objectCollection !== null && $objectCollection !== '') {
                $collectionsToSearch[] = ['type' => 'object', 'collection' => $objectCollection];
            }

            if ($fileCollection !== null && $fileCollection !== '') {
                $collectionsToSearch[] = ['type' => 'file', 'collection' => $fileCollection];
            }
        }//end if

        return $collectionsToSearch;
    }//end getCollectionsToSearch()

    /**
     * Get Solr collection for entity type
     *
     * @param string $entityType Entity type
     * @param array  $settings   Settings array
     *
     * @return string|null Collection name
     */
    private function getSolrCollectionForEntityType(string $entityType, array $settings): ?string
    {
        $entityType = strtolower($entityType);

        if ($entityType === 'file' || $entityType === 'files') {
            return $settings['solr']['fileCollection'] ?? null;
        }

        return $settings['solr']['objectCollection'] ?? $settings['solr']['collection'] ?? null;
    }//end getSolrCollectionForEntityType()
}//end class
