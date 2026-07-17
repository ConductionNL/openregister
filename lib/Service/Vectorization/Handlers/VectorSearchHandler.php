<?php

/**
 * Vector Search Handler
 *
 * Handles semantic and hybrid search operations using vectors.
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
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vectorization\Handlers;

use Exception;
use InvalidArgumentException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * VectorSearchHandler
 *
 * Responsible for searching vectors using semantic search and hybrid search.
 * On PostgreSQL with a populated pgvector ANN sidecar (`openregister_vec_ann`)
 * the primary path is an index-backed SQL KNN query (`ORDER BY embedding <=> :query`);
 * the PHP cosine-similarity loop over serialized BLOBs remains as the explicit
 * fallback for MariaDB/SQLite, Postgres installs without the pgvector extension,
 * and rows whose stored dimension doesn't match the column.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class deliberately carries BOTH
 *   ranking paths (index-backed SQL KNN primary + PHP-cosine fallback) plus RRF fusion;
 *   splitting them would separate two implementations of one contract that must stay
 *   result-shape-identical.
 */
class VectorSearchHandler
{
    /**
     * Constructor
     *
     * @param IDBConnection    $db       Database connection
     * @param PgVectorPlatform $pgVector pgvector fast-path capability helper
     * @param LoggerInterface  $logger   PSR-3 logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly PgVectorPlatform $pgVector,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Perform semantic similarity search
     *
     * Primary path: SQL K-nearest-neighbour on PostgreSQL via the pgvector
     * ANN sidecar (`openregister_vec_ann`) and its HNSW index (index-backed
     * `ORDER BY embedding <=> :query LIMIT :n`). Fallback path: fetch
     * candidate rows and score them with a PHP cosine loop (MariaDB/SQLite,
     * missing pgvector extension, dimension mismatch, or no converted rows
     * yet during warm-up).
     *
     * @param array $queryEmbedding Query embedding vector
     * @param int   $limit          Maximum number of results
     * @param array $filters        Additional filters (entity_type, etc.)
     *
     * @return array<int,array<string,mixed>> Search results
     *
     * @throws \Exception If search fails
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Filter handling requires multiple conditions
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive semantic search with error handling
     *
     * @spec openspec/changes/hybrid-document-search/tasks.md#3.1
     */
    public function semanticSearch(
        array $queryEmbedding,
        int $limit=10,
        array $filters=[]
    ): array {
        $startTime = microtime(true);

        $this->logger->info(
            message: '[VectorSearchHandler] Performing semantic search',
            context: [
                'file'    => __FILE__,
                'line'    => __LINE__,
                'limit'   => $limit,
                'filters' => $filters,
            ]
        );

        $results = [];
        try {
            // Primary path: index-backed SQL KNN on PostgreSQL/pgvector.
            $knnResults = $this->knnSearch(
                queryEmbedding: $queryEmbedding,
                limit: $limit,
                filters: $filters
            );

            if ($knnResults !== null && $knnResults !== []) {
                $searchTime = round((microtime(true) - $startTime) * 1000, 2);

                $this->logger->info(
                    message: '[VectorSearchHandler] Semantic search completed (pgvector KNN path)',
                    context: [
                        'file'           => __FILE__,
                        'line'           => __LINE__,
                        'results_count'  => count($knnResults),
                        'top_similarity' => $knnResults[0]['similarity'] ?? 0,
                        'search_time_ms' => $searchTime,
                    ]
                );

                return $knnResults;
            }

            // Fallback path: PHP cosine loop over candidate BLOB rows.
            $vectors = $this->fetchVectors(filters: $filters);

            if ($vectors === []) {
                $this->logger->warning(
                    message: '[VectorSearchHandler] No vectors found in database',
                    context: [
                        'file'    => __FILE__,
                        'line'    => __LINE__,
                        'filters' => $filters,
                    ]
                );
                return [];
            }

            // Calculate cosine similarity for each vector.
            $results = [];
            foreach ($vectors as $vector) {
                try {
                    // PostgreSQL returns BLOB columns as stream resources
                    // (live-verified on PG16); normalise to a string first —
                    // unserialize() on a resource is a TypeError, which the
                    // per-row catch below would not have caught.
                    $blob = $vector['embedding'];
                    if (is_resource($blob) === true) {
                        $blob = stream_get_contents($blob);
                    }

                    if (is_string($blob) === false) {
                        continue;
                    }

                    // SEC-SVC-9: embeddings are plain float arrays; never
                    // allow object instantiation during unserialize to
                    // avoid PHP object-injection from a tampered blob.
                    $storedEmbedding = unserialize($blob, ['allowed_classes' => false]);

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
                        message: '[VectorSearchHandler] Failed to process vector',
                        context: [
                            'file'      => __FILE__,
                            'line'      => __LINE__,
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

            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                message: '[VectorSearchHandler] Semantic search completed (PHP fallback path)',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'results_count'  => count($results),
                    'top_similarity' => $results[0]['similarity'] ?? 0,
                    'search_time_ms' => $searchTime,
                ]
            );

            return $results;
        } catch (Exception $e) {
            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error(
                message: '[VectorSearchHandler] Semantic search failed',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'error'          => $e->getMessage(),
                    'search_time_ms' => $searchTime,
                ]
            );
            throw new Exception('Semantic search failed: '.$e->getMessage());
        }//end try
    }//end semanticSearch()

    /**
     * Index-backed SQL KNN search on PostgreSQL via pgvector.
     *
     * Executes `ORDER BY a.embedding <=> :queryVector LIMIT :limit`
     * (cosine-distance operator, ascending = most similar first) against the
     * HNSW index on the `openregister_vec_ann` sidecar table (joined to the
     * main vectors table for entity data), honouring `entity_type` /
     * `entity_id` filters as WHERE predicates. Returns null when the fast path
     * is unavailable (non-Postgres platform, missing sidecar, or
     * query/sidecar dimension mismatch) or when the KNN query itself fails —
     * the caller then uses the PHP fallback.
     *
     * @param array $queryEmbedding Query embedding vector
     * @param int   $limit          Maximum number of results
     * @param array $filters        Additional filters (entity_type, entity_id)
     *
     * @return array<int,array<string,mixed>>|null Results, or null when unavailable
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Filter handling requires multiple conditions
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple filter handling paths
     *
     * @spec openspec/changes/hybrid-document-search/tasks.md#3.1
     */
    private function knnSearch(array $queryEmbedding, int $limit, array $filters=[]): ?array
    {
        $columnDimension = $this->pgVector->getVectorColumnDimension();

        if ($columnDimension === null || count($queryEmbedding) !== $columnDimension) {
            return null;
        }

        $where  = [];
        $params = ['qvec' => $this->pgVector->formatVector($queryEmbedding)];
        $types  = [];

        if (($filters['entity_type'] ?? null) !== null) {
            if (is_array($filters['entity_type']) === true) {
                $where[] = 'v.entity_type IN (:entityTypes)';
                $params['entityTypes'] = $filters['entity_type'];
                $types['entityTypes']  = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY;
            }

            if (is_array($filters['entity_type']) === false) {
                $where[] = 'v.entity_type = :entityType';
                $params['entityType'] = $filters['entity_type'];
            }
        }

        if (($filters['entity_id'] ?? null) !== null) {
            if (is_array($filters['entity_id']) === true) {
                $where[] = 'v.entity_id IN (:entityIds)';
                $params['entityIds'] = $filters['entity_id'];
                $types['entityIds']  = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY;
            }

            if (is_array($filters['entity_id']) === false) {
                $where[]            = 'v.entity_id = :entityId';
                $params['entityId'] = $filters['entity_id'];
            }
        }

        $whereSql = '';
        if ($where !== []) {
            $whereSql = 'WHERE '.implode(' AND ', $where).' ';
        }

        $sql = 'SELECT v.id, v.entity_type, v.entity_id, v.chunk_index, v.total_chunks, v.chunk_text, '
            .'v.metadata, v.embedding_model, v.embedding_dimensions, '
            .'(a.embedding <=> :qvec::vector) AS distance '
            .'FROM '.PgVectorPlatform::SIDECAR_TABLE.' a '
            .'JOIN *PREFIX*openregister_vectors v ON v.id = a.vector_id '
            .$whereSql
            .'ORDER BY a.embedding <=> :qvec::vector ASC '
            .'LIMIT '.max(1, $limit);

        try {
            $result = $this->db->executeQuery($sql, $params, $types);
            $rows   = $result->fetchAll();
            $result->closeCursor();
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[VectorSearchHandler] pgvector KNN query failed, using PHP fallback',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return null;
        }

        $results = [];
        foreach ($rows as $row) {
            $metadata = [];
            if (empty($row['metadata']) === false) {
                $metadata = json_decode($row['metadata'], true) ?? [];
            }

            $results[] = [
                'vector_id'    => $row['id'],
                'entity_type'  => $row['entity_type'],
                'entity_id'    => $row['entity_id'],
                // Cosine distance = 1 - cosine similarity.
                'similarity'   => 1 - (float) $row['distance'],
                'chunk_index'  => $row['chunk_index'],
                'total_chunks' => $row['total_chunks'],
                'chunk_text'   => $row['chunk_text'],
                'metadata'     => $metadata,
                'model'        => $row['embedding_model'],
                'dimensions'   => $row['embedding_dimensions'],
            ];
        }//end foreach

        return $results;
    }//end knnSearch()

    /**
     * Perform hybrid search combining keyword and semantic (vectors)
     *
     * Uses Reciprocal Rank Fusion (RRF) to combine results.
     * The keyword results set is passed in by the caller; vector search
     * always runs against the PostgreSQL database backend.
     *
     * @param array $queryEmbedding Query embedding vector
     * @param array $keywordResults Keyword search results to fuse with vector results
     * @param int   $limit          Maximum results
     * @param array $weights        Weights for each search type ['keyword' => 0.5, 'vector' => 0.5]
     *
     * @return (((array|bool|float|int|mixed|null)[]|float|int)[]|float|int)[]
     *
     * @throws \Exception If hybrid search fails
     *
     * @psalm-return array{results: list<array{chunk_index: 0|mixed,
     *     chunk_text: mixed|null, combined_score: 0|float, entity_id: mixed,
     *     entity_type: mixed, in_keyword: bool, in_vector: bool,
     *     metadata: array<never, never>|mixed, keyword_rank: int|null,
     *     keyword_score: mixed|null, vector_rank: int|null,
     *     vector_similarity: mixed|null}>, total: int<0, max>,
     *     search_time_ms: float,
     *     source_breakdown: array{vector_only: int<0, max>,
     *     keyword_only: int<0, max>, both: int<0, max>},
     *     weights: array{keyword: float, vector: float}}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Hybrid search combines multiple result sets
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple search path combinations
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive hybrid search with result fusion
     *
     * @spec openspec/specs/vector-embeddings/spec.md
     */
    public function hybridSearch(
        array $queryEmbedding,
        array $keywordResults=[],
        int $limit=20,
        array $weights=['keyword' => 0.5, 'vector' => 0.5]
    ): array {
        $startTime = microtime(true);

        try {
            // Validate and normalize weights.
            $keywordWeight = $weights['keyword'] ?? 0.5;
            $vectorWeight  = $weights['vector'] ?? 0.5;

            $totalWeight = $keywordWeight + $vectorWeight;
            if ($totalWeight > 0) {
                $keywordWeight = $keywordWeight / $totalWeight;
                $vectorWeight  = $vectorWeight / $totalWeight;
            }

            // Perform vector semantic search against PostgreSQL.
            $vectorResults = [];
            if ($vectorWeight > 0) {
                try {
                    $vectorResults = $this->semanticSearch(
                        queryEmbedding: $queryEmbedding,
                        limit: $limit * 2,
                        filters: []
                    );
                } catch (Exception $e) {
                    $this->logger->warning(
                        message: '[VectorSearchHandler] Vector search failed in hybrid search',
                        context: [
                            'file'  => __FILE__,
                            'line'  => __LINE__,
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }

            // Combine results using Reciprocal Rank Fusion (RRF).
            $combined = $this->reciprocalRankFusion(
                vectorResults: $vectorResults,
                keywordResults: $keywordResults,
                vectorWeight: $vectorWeight,
                keywordWeight: $keywordWeight
            );

            // Return top N results.
            $finalResults = array_slice($combined, 0, $limit);
            $searchTime   = round((microtime(true) - $startTime) * 1000, 2);

            // Calculate source breakdown.
            $vectorOnly  = 0;
            $keywordOnly = 0;
            $both        = 0;

            foreach ($finalResults as $result) {
                if ($result['in_vector'] === true && $result['in_keyword'] === true) {
                    $both++;
                } else if ($result['in_vector'] === true) {
                    $vectorOnly++;
                } else if ($result['in_keyword'] === true) {
                    $keywordOnly++;
                }
            }

            return [
                'results'          => $finalResults,
                'total'            => count($finalResults),
                'search_time_ms'   => $searchTime,
                'source_breakdown' => [
                    'vector_only'  => $vectorOnly,
                    'keyword_only' => $keywordOnly,
                    'both'         => $both,
                ],
                'weights'          => [
                    'keyword' => $keywordWeight,
                    'vector'  => $vectorWeight,
                ],
            ];
        } catch (Exception $e) {
            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error(
                message: '[VectorSearchHandler] Hybrid search failed',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
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
     * @param array $vectorResults  Results from vector search
     * @param array $keywordResults Results from keyword search
     * @param float $vectorWeight   Weight for vector results (0-1)
     * @param float $keywordWeight  Weight for keyword results (0-1)
     *
     * @return (array|bool|float|int|mixed|null)[][]
     *
     * @psalm-return list<array{chunk_index: 0|mixed, chunk_text: mixed|null,
     *     combined_score: 0|float, entity_id: mixed, entity_type: mixed,
     *     in_keyword: bool, in_vector: bool,
     *     metadata: array<never, never>|mixed, keyword_rank: int|null,
     *     keyword_score: mixed|null, vector_rank: int|null,
     *     vector_similarity: mixed|null}>
     */
    private function reciprocalRankFusion(
        array $vectorResults,
        array $keywordResults,
        float $vectorWeight=0.5,
        float $keywordWeight=0.5
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
                    'keyword_score'     => null,
                    'combined_score'    => 0,
                    'in_vector'         => false,
                    'in_keyword'        => false,
                    'vector_rank'       => null,
                    'keyword_rank'      => null,
                ];
            }

            $rrfScore = $vectorWeight / ($k + (int) $rank + 1);
            $combinedScores[$key]['combined_score'] += $rrfScore;
            $combinedScores[$key]['in_vector']       = true;
            $combinedScores[$key]['vector_rank']     = (int) $rank + 1;
        }//end foreach

        // Process keyword results.
        foreach ($keywordResults as $rank => $result) {
            $key = $result['entity_type'].'_'.$result['entity_id'];

            if (isset($combinedScores[$key]) === false) {
                $combinedScores[$key] = [
                    'entity_type'       => $result['entity_type'],
                    'entity_id'         => $result['entity_id'],
                    'chunk_index'       => $result['chunk_index'] ?? 0,
                    'chunk_text'        => $result['chunk_text'] ?? null,
                    'metadata'          => $result['metadata'] ?? [],
                    'vector_similarity' => null,
                    'keyword_score'     => $result['score'] ?? null,
                    'combined_score'    => 0,
                    'in_vector'         => false,
                    'in_keyword'        => false,
                    'vector_rank'       => null,
                    'keyword_rank'      => null,
                ];
            }

            $rrfScore = $keywordWeight / ($k + (int) $rank + 1);
            $combinedScores[$key]['combined_score'] += $rrfScore;
            $combinedScores[$key]['in_keyword']      = true;
            $combinedScores[$key]['keyword_rank']    = (int) $rank + 1;
            $combinedScores[$key]['keyword_score']   = $result['score'] ?? null;
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Filter handling requires multiple conditions
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple filter handling paths
     *
     * @spec openspec/changes/hybrid-document-search/tasks.md#3.2
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
                }

                if (is_array($filters['entity_type']) === false) {
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
                }

                if (is_array($filters['entity_id']) === false) {
                    $qb->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($filters['entity_id'])));
                }
            }

            // Approximate-fallback bound (hybrid-document-search, decision 4):
            // max_vectors caps how many BLOB rows the O(n) PHP cosine fallback
            // will unserialize and score per request. It is a documented safety
            // cap, NOT a relevance mechanism — ordering is by primary key
            // (stable, non-biasing) instead of the former `created_at DESC`,
            // which silently substituted "newest" for "most relevant" past the
            // cap. The index-backed pgvector KNN path has no such cap.
            $maxVectors = $filters['max_vectors'] ?? 500;
            $qb->setMaxResults($maxVectors);
            $qb->orderBy('id', 'ASC');

            $result  = $qb->executeQuery();
            $vectors = $result->fetchAll();

            return $vectors;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[VectorSearchHandler] Failed to fetch vectors',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
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
}//end class
