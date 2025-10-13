<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use LLPhant\OpenAIConfig;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAIADA002EmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\Ollama\OllamaEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;

/**
 * Vector Embedding Service
 * 
 * Handles vector embeddings generation, storage, and semantic search using LLPhant.
 * Supports multiple embedding providers (OpenAI, Ollama, local models) and provides
 * similarity search capabilities for both objects and file chunks.
 * 
 * This service works in conjunction with:
 * - SolrObjectService: For object indexing and keyword search
 * - SolrFileService: For file processing and keyword search
 * - Hybrid search: Combining keyword (SOLR) and semantic (vectors) results
 * 
 * @category Service
 * @package  OCA\OpenRegister\Service
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class VectorEmbeddingService
{
    /**
     * Default embedding dimensions for different models
     */
    private const EMBEDDING_DIMENSIONS = [
        'text-embedding-ada-002' => 1536,
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'ollama-default' => 384,
    ];

    /**
     * Cache for embedding generators (to avoid recreating them)
     * 
     * @var array<string, mixed>
     */
    private array $generatorCache = [];

    /**
     * Constructor
     * 
     * @param IDBConnection     $db              Database connection
     * @param SettingsService   $settingsService Settings management service
     * @param LoggerInterface   $logger          PSR-3 logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generate embedding for a single text
     * 
     * @param string      $text     Text to embed
     * @param string|null $provider Embedding provider (null = use default from settings)
     * 
     * @return array{embedding: array<float>, model: string, dimensions: int} Embedding data
     * 
     * @throws \Exception If embedding generation fails
     */
    public function generateEmbedding(string $text, ?string $provider = null): array
    {
        $config = $this->getEmbeddingConfig($provider);
        
        $this->logger->debug('Generating embedding', [
            'text_length' => strlen($text),
            'provider' => $config['provider'],
            'model' => $config['model']
        ]);

        try {
            $generator = $this->getEmbeddingGenerator($config);
            
            // Generate embedding using LLPhant
            $embedding = $generator->embedText($text);
            
            $dimensions = count($embedding);
            
            $this->logger->debug('Embedding generated successfully', [
                'dimensions' => $dimensions,
                'model' => $config['model']
            ]);
            
            return [
                'embedding' => $embedding,
                'model' => $config['model'],
                'dimensions' => $dimensions
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate embedding', [
                'error' => $e->getMessage(),
                'provider' => $config['provider'],
                'text_length' => strlen($text)
            ]);
            throw new \Exception('Embedding generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate embeddings for multiple texts in batch
     * 
     * More efficient than calling generateEmbedding() multiple times.
     * 
     * @param array<string> $texts    Array of texts to embed
     * @param string|null   $provider Embedding provider
     * 
     * @return array<int, array{embedding: array<float>, model: string, dimensions: int}> Array of embeddings
     * 
     * @throws \Exception If batch embedding generation fails
     */
    public function generateBatchEmbeddings(array $texts, ?string $provider = null): array
    {
        $config = $this->getEmbeddingConfig($provider);
        
        $this->logger->info('Generating batch embeddings', [
            'count' => count($texts),
            'provider' => $config['provider'],
            'model' => $config['model']
        ]);

        try {
            $generator = $this->getEmbeddingGenerator($config);
            
            // Generate embeddings individually for each text
            // Note: For true batch processing, use LLPhant's Document system
            $results = [];
            foreach ($texts as $index => $text) {
                try {
                    $embedding = $generator->embedText($text);
                    $results[] = [
                        'embedding' => $embedding,
                        'model' => $config['model'],
                        'dimensions' => count($embedding)
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to generate embedding for text', [
                        'index' => $index,
                        'error' => $e->getMessage()
                    ]);
                    // Skip this text but continue with others
                    $results[] = [
                        'embedding' => null,
                        'model' => $config['model'],
                        'dimensions' => 0,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $successful = count(array_filter($results, fn($r) => $r['embedding'] !== null));
            
            $this->logger->info('Batch embedding generation completed', [
                'total' => count($texts),
                'successful' => $successful,
                'failed' => count($texts) - $successful
            ]);
            
            return $results;

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate batch embeddings', [
                'error' => $e->getMessage(),
                'count' => count($texts)
            ]);
            throw new \Exception('Batch embedding generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Store vector embedding in database
     * 
     * @param string      $entityType   Entity type ('object' or 'file')
     * @param string      $entityId     Entity UUID
     * @param array       $embedding    Vector embedding (array of floats)
     * @param string      $model        Model used to generate embedding
     * @param int         $dimensions   Number of dimensions
     * @param int         $chunkIndex   Chunk index (0 for objects, N for file chunks)
     * @param int         $totalChunks  Total number of chunks
     * @param string|null $chunkText    The text that was embedded
     * @param array       $metadata     Additional metadata as associative array
     * 
     * @return int The ID of the inserted vector
     * 
     * @throws \Exception If storage fails
     */
    public function storeVector(
        string $entityType,
        string $entityId,
        array $embedding,
        string $model,
        int $dimensions,
        int $chunkIndex = 0,
        int $totalChunks = 1,
        ?string $chunkText = null,
        array $metadata = []
    ): int {
        $this->logger->debug('Storing vector in database', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'chunk_index' => $chunkIndex,
            'dimensions' => $dimensions
        ]);

        try {
            // Serialize embedding to binary format
            $embeddingBlob = serialize($embedding);
            
            // Serialize metadata to JSON
            $metadataJson = !empty($metadata) ? json_encode($metadata) : null;

            $qb = $this->db->getQueryBuilder();
            $qb->insert('openregister_vectors')
                ->values([
                    'entity_type' => $qb->createNamedParameter($entityType),
                    'entity_id' => $qb->createNamedParameter($entityId),
                    'chunk_index' => $qb->createNamedParameter($chunkIndex, \PDO::PARAM_INT),
                    'total_chunks' => $qb->createNamedParameter($totalChunks, \PDO::PARAM_INT),
                    'chunk_text' => $qb->createNamedParameter($chunkText),
                    'embedding' => $qb->createNamedParameter($embeddingBlob, \PDO::PARAM_LOB),
                    'embedding_model' => $qb->createNamedParameter($model),
                    'embedding_dimensions' => $qb->createNamedParameter($dimensions, \PDO::PARAM_INT),
                    'metadata' => $qb->createNamedParameter($metadataJson),
                    'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                    'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s'))
                ])
                ->executeStatement();

            $vectorId = (int) $qb->getLastInsertId();
            
            $this->logger->info('Vector stored successfully', [
                'vector_id' => $vectorId,
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);

            return $vectorId;

        } catch (\Exception $e) {
            $this->logger->error('Failed to store vector', [
                'error' => $e->getMessage(),
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            throw new \Exception('Vector storage failed: ' . $e->getMessage());
        }
    }

    /**
     * Perform semantic similarity search
     * 
     * Find the most similar vectors to a query using cosine similarity.
     * 
     * @param string      $query       Query text to search for
     * @param int         $limit       Maximum number of results
     * @param array       $filters     Additional filters (entity_type, etc.)
     * @param string|null $provider    Embedding provider
     * 
     * @return array<int, array{entity_type: string, entity_id: string, similarity: float, chunk_index: int, chunk_text: string|null, metadata: array, vector_id: int}> Search results
     * 
     * @throws \Exception If search fails
     */
    public function semanticSearch(
        string $query,
        int $limit = 10,
        array $filters = [],
        ?string $provider = null
    ): array {
        $startTime = microtime(true);

        $this->logger->info('Performing semantic search', [
            'query_length' => strlen($query),
            'limit' => $limit,
            'filters' => $filters
        ]);

        try {
            // Step 1: Generate embedding for query
            $this->logger->debug('Step 1: Generating query embedding');
            $queryEmbeddingData = $this->generateEmbedding($query, $provider);
            $queryEmbedding = $queryEmbeddingData['embedding'];

            // Step 2: Fetch vectors from database with filters
            $this->logger->debug('Step 2: Fetching vectors from database');
            $vectors = $this->fetchVectors($filters);

            if (empty($vectors)) {
                $this->logger->warning('No vectors found in database', ['filters' => $filters]);
                return [];
            }

            // Step 3: Calculate cosine similarity for each vector
            $this->logger->debug('Step 3: Calculating similarities', ['vector_count' => count($vectors)]);
            $results = [];
            
            foreach ($vectors as $vector) {
                try {
                    $storedEmbedding = unserialize($vector['embedding']);
                    
                    if (!is_array($storedEmbedding)) {
                        $this->logger->warning('Invalid embedding format', ['vector_id' => $vector['id']]);
                        continue;
                    }

                    $similarity = $this->cosineSimilarity($queryEmbedding, $storedEmbedding);
                    
                    // Parse metadata
                    $metadata = [];
                    if (!empty($vector['metadata'])) {
                        $metadata = json_decode($vector['metadata'], true) ?? [];
                    }

                    $results[] = [
                        'vector_id' => $vector['id'],
                        'entity_type' => $vector['entity_type'],
                        'entity_id' => $vector['entity_id'],
                        'similarity' => $similarity,
                        'chunk_index' => $vector['chunk_index'],
                        'total_chunks' => $vector['total_chunks'],
                        'chunk_text' => $vector['chunk_text'],
                        'metadata' => $metadata,
                        'model' => $vector['embedding_model'],
                        'dimensions' => $vector['embedding_dimensions']
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to process vector', [
                        'vector_id' => $vector['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Step 4: Sort by similarity descending
            usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

            // Step 5: Return top N results
            $results = array_slice($results, 0, $limit);

            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info('Semantic search completed', [
                'results_count' => count($results),
                'top_similarity' => $results[0]['similarity'] ?? 0,
                'search_time_ms' => $searchTime
            ]);

            return $results;

        } catch (\Exception $e) {
            $searchTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->error('Semantic search failed', [
                'error' => $e->getMessage(),
                'search_time_ms' => $searchTime
            ]);
            throw new \Exception('Semantic search failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch vectors from database with optional filters
     * 
     * @param array $filters Filters (entity_type, entity_id, etc.)
     * 
     * @return array<int, array> Vector records from database
     * 
     * @throws \Exception If query fails
     */
    private function fetchVectors(array $filters = []): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('openregister_vectors');

            // Apply filters
            if (isset($filters['entity_type'])) {
                $qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($filters['entity_type'])));
            }

            if (isset($filters['entity_id'])) {
                $qb->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($filters['entity_id'])));
            }

            if (isset($filters['embedding_model'])) {
                $qb->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($filters['embedding_model'])));
            }

            // Order by creation date (most recent first) for consistency
            $qb->orderBy('created_at', 'DESC');

            // Limit to prevent memory issues (configurable later)
            $maxVectors = $filters['max_vectors'] ?? 10000;
            $qb->setMaxResults($maxVectors);

            $result = $qb->executeQuery();
            $vectors = $result->fetchAll();

            $this->logger->debug('Fetched vectors from database', [
                'count' => count($vectors),
                'filters' => $filters
            ]);

            return $vectors;

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch vectors', [
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Failed to fetch vectors: ' . $e->getMessage());
        }
    }

    /**
     * Perform hybrid search combining keyword (SOLR) and semantic (vectors)
     * 
     * Uses Reciprocal Rank Fusion (RRF) to combine results from both search methods.
     * 
     * @param string      $query          Query text
     * @param array       $solrFilters    SOLR-specific filters
     * @param int         $limit          Maximum results
     * @param array       $weights        Weights for each search type ['solr' => 0.5, 'vector' => 0.5]
     * @param string|null $provider       Embedding provider
     * 
     * @return array Combined and ranked results
     * 
     * @throws \Exception If hybrid search fails
     */
    public function hybridSearch(
        string $query,
        array $solrFilters = [],
        int $limit = 20,
        array $weights = ['solr' => 0.5, 'vector' => 0.5],
        ?string $provider = null
    ): array {
        $startTime = microtime(true);

        $this->logger->info('Performing hybrid search', [
            'query' => $query,
            'limit' => $limit,
            'weights' => $weights,
            'solr_filters' => $solrFilters
        ]);

        try {
            // Validate weights
            $solrWeight = $weights['solr'] ?? 0.5;
            $vectorWeight = $weights['vector'] ?? 0.5;
            
            // Normalize weights
            $totalWeight = $solrWeight + $vectorWeight;
            if ($totalWeight > 0) {
                $solrWeight = $solrWeight / $totalWeight;
                $vectorWeight = $vectorWeight / $totalWeight;
            }

            // Step 1: Perform vector semantic search (get 2x limit for better coverage)
            $this->logger->debug('Step 1: Performing semantic search');
            $vectorResults = [];
            if ($vectorWeight > 0) {
                try {
                    $vectorResults = $this->semanticSearch(
                        $query,
                        $limit * 2,
                        $solrFilters['vector_filters'] ?? [],
                        $provider
                    );
                } catch (\Exception $e) {
                    $this->logger->warning('Vector search failed, continuing with SOLR only', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Step 2: TODO - Perform SOLR keyword search (get 2x limit)
            // This will be implemented when we integrate with SOLR services
            $this->logger->debug('Step 2: SOLR keyword search (not yet integrated)');
            $solrResults = [];
            
            // For now, just use vector results
            // In Phase 6.2, we'll integrate actual SOLR search

            // Step 3: Combine results using Reciprocal Rank Fusion (RRF)
            $this->logger->debug('Step 3: Merging results with RRF');
            $combined = $this->reciprocalRankFusion(
                $vectorResults,
                $solrResults,
                $vectorWeight,
                $solrWeight
            );

            // Step 4: Return top N results
            $finalResults = array_slice($combined, 0, $limit);

            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            // Calculate source breakdown
            $vectorOnly = 0;
            $solrOnly = 0;
            $both = 0;
            
            foreach ($finalResults as $result) {
                if ($result['in_vector'] && $result['in_solr']) {
                    $both++;
                } elseif ($result['in_vector']) {
                    $vectorOnly++;
                } elseif ($result['in_solr']) {
                    $solrOnly++;
                }
            }

            $this->logger->info('Hybrid search completed', [
                'results_count' => count($finalResults),
                'search_time_ms' => $searchTime,
                'source_breakdown' => [
                    'vector_only' => $vectorOnly,
                    'solr_only' => $solrOnly,
                    'both' => $both
                ]
            ]);

            return [
                'results' => $finalResults,
                'total' => count($finalResults),
                'search_time_ms' => $searchTime,
                'source_breakdown' => [
                    'vector_only' => $vectorOnly,
                    'solr_only' => $solrOnly,
                    'both' => $both
                ],
                'weights' => [
                    'solr' => $solrWeight,
                    'vector' => $vectorWeight
                ]
            ];

        } catch (\Exception $e) {
            $searchTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->error('Hybrid search failed', [
                'error' => $e->getMessage(),
                'search_time_ms' => $searchTime
            ]);
            throw new \Exception('Hybrid search failed: ' . $e->getMessage());
        }
    }

    /**
     * Combine search results using Reciprocal Rank Fusion (RRF)
     * 
     * RRF formula: score(d) = Σ 1 / (k + rank(d))
     * where k is a constant (typically 60) and rank is the position in the result list
     * 
     * @param array $vectorResults Results from vector search
     * @param array $solrResults   Results from SOLR search
     * @param float $vectorWeight  Weight for vector results (0-1)
     * @param float $solrWeight    Weight for SOLR results (0-1)
     * 
     * @return array Combined and ranked results
     */
    private function reciprocalRankFusion(
        array $vectorResults,
        array $solrResults,
        float $vectorWeight = 0.5,
        float $solrWeight = 0.5
    ): array {
        $k = 60; // RRF constant
        $combinedScores = [];

        // Process vector results
        foreach ($vectorResults as $rank => $result) {
            $key = $result['entity_type'] . '_' . $result['entity_id'];
            
            if (!isset($combinedScores[$key])) {
                $combinedScores[$key] = [
                    'entity_type' => $result['entity_type'],
                    'entity_id' => $result['entity_id'],
                    'chunk_index' => $result['chunk_index'],
                    'chunk_text' => $result['chunk_text'],
                    'metadata' => $result['metadata'],
                    'vector_similarity' => $result['similarity'],
                    'solr_score' => null,
                    'combined_score' => 0,
                    'in_vector' => false,
                    'in_solr' => false,
                    'vector_rank' => null,
                    'solr_rank' => null
                ];
            }

            $rrfScore = $vectorWeight / ($k + $rank + 1);
            $combinedScores[$key]['combined_score'] += $rrfScore;
            $combinedScores[$key]['in_vector'] = true;
            $combinedScores[$key]['vector_rank'] = $rank + 1;
        }

        // Process SOLR results
        foreach ($solrResults as $rank => $result) {
            $key = $result['entity_type'] . '_' . $result['entity_id'];
            
            if (!isset($combinedScores[$key])) {
                $combinedScores[$key] = [
                    'entity_type' => $result['entity_type'],
                    'entity_id' => $result['entity_id'],
                    'chunk_index' => $result['chunk_index'] ?? 0,
                    'chunk_text' => $result['chunk_text'] ?? null,
                    'metadata' => $result['metadata'] ?? [],
                    'vector_similarity' => null,
                    'solr_score' => $result['score'],
                    'combined_score' => 0,
                    'in_vector' => false,
                    'in_solr' => false,
                    'vector_rank' => null,
                    'solr_rank' => null
                ];
            }

            $rrfScore = $solrWeight / ($k + $rank + 1);
            $combinedScores[$key]['combined_score'] += $rrfScore;
            $combinedScores[$key]['in_solr'] = true;
            $combinedScores[$key]['solr_rank'] = $rank + 1;
            $combinedScores[$key]['solr_score'] = $result['score'];
        }

        // Convert to array and sort by combined score
        $results = array_values($combinedScores);
        usort($results, fn($a, $b) => $b['combined_score'] <=> $a['combined_score']);

        return $results;
    }

    /**
     * Delete vectors for an entity
     * 
     * @param string $entityType Entity type
     * @param string $entityId   Entity ID
     * 
     * @return int Number of vectors deleted
     * 
     * @throws \Exception If deletion fails
     */
    public function deleteVectors(string $entityType, string $entityId): int
    {
        $this->logger->info('Deleting vectors', [
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);

        try {
            $qb = $this->db->getQueryBuilder();
            $deleted = $qb->delete('openregister_vectors')
                ->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
                ->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId)))
                ->executeStatement();

            $this->logger->info('Vectors deleted', [
                'count' => $deleted,
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);

            return $deleted;

        } catch (\Exception $e) {
            $this->logger->error('Failed to delete vectors', [
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Vector deletion failed: ' . $e->getMessage());
        }
    }

    /**
     * Get vector statistics
     * 
     * @return array Statistics about stored vectors
     */
    public function getVectorStats(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            
            // Total vectors
            $qb->select($qb->func()->count('id', 'total'))
                ->from('openregister_vectors');
            $total = (int) $qb->executeQuery()->fetchOne();

        // By entity type
        $qb = $this->db->getQueryBuilder();
        $qb->select('entity_type', $qb->func()->count('id', 'count'))
            ->from('openregister_vectors')
            ->groupBy('entity_type');
        $result = $qb->executeQuery();
        $byType = [];
        while ($row = $result->fetch()) {
            $byType[$row['entity_type']] = (int)$row['count'];
        }
        $result->closeCursor();

        // By model
        $qb = $this->db->getQueryBuilder();
        $qb->select('embedding_model', $qb->func()->count('id', 'count'))
            ->from('openregister_vectors')
            ->groupBy('embedding_model');
        $result = $qb->executeQuery();
        $byModel = [];
        while ($row = $result->fetch()) {
            $byModel[$row['embedding_model']] = (int)$row['count'];
        }
        $result->closeCursor();

            return [
                'total_vectors' => $total,
                'by_type' => $byType,
                'by_model' => $byModel,
                'object_vectors' => $byType['object'] ?? 0,
                'file_vectors' => $byType['file'] ?? 0
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to get vector stats', [
                'error' => $e->getMessage()
            ]);
            return [
                'total_vectors' => 0,
                'by_type' => [],
                'by_model' => []
            ];
        }
    }

    /**
     * Get embedding configuration from settings
     * 
     * @param string|null $provider Override provider (null = use default from settings)
     * 
     * @return array{provider: string, model: string, dimensions: int, api_key: string|null, base_url: string|null} Configuration
     */
    private function getEmbeddingConfig(?string $provider = null): array
    {
        // Load from settings service
        $settings = $this->settingsService->getSettings();
        $vectorSettings = $settings['vector_embeddings'] ?? [];
        
        // Determine provider and model
        $configuredProvider = $provider ?? ($vectorSettings['provider'] ?? 'openai');
        $configuredModel = $vectorSettings['model'] ?? 'text-embedding-ada-002';
        $apiKey = $vectorSettings['api_key'] ?? null;
        $baseUrl = $vectorSettings['base_url'] ?? null;

        return [
            'provider' => $configuredProvider,
            'model' => $configuredModel,
            'dimensions' => self::EMBEDDING_DIMENSIONS[$configuredModel] ?? 1536,
            'api_key' => $apiKey,
            'base_url' => $baseUrl
        ];
    }

    /**
     * Get or create embedding generator for a configuration
     * 
     * @param array $config Embedding configuration
     * 
     * @return EmbeddingGeneratorInterface LLPhant embedding generator instance
     * 
     * @throws \Exception If configuration is invalid or generator cannot be created
     */
    private function getEmbeddingGenerator(array $config): EmbeddingGeneratorInterface
    {
        $cacheKey = $config['provider'] . '_' . $config['model'];

        if (!isset($this->generatorCache[$cacheKey])) {
            $this->logger->debug('Creating new embedding generator', [
                'provider' => $config['provider'],
                'model' => $config['model']
            ]);

            // Create LLPhant config
            $llphantConfig = new OpenAIConfig();
            
            if (!empty($config['api_key'])) {
                $llphantConfig->apiKey = $config['api_key'];
            }
            
            if (!empty($config['base_url'])) {
                $llphantConfig->url = $config['base_url'];
            }

            // Create appropriate generator based on provider and model
            $generator = match ($config['provider']) {
                'openai' => $this->createOpenAIGenerator($config['model'], $llphantConfig),
                'ollama' => $this->createOllamaGenerator($config['model'], $llphantConfig),
                default => throw new \Exception("Unsupported embedding provider: {$config['provider']}")
            };

            $this->generatorCache[$cacheKey] = $generator;
            
            $this->logger->info('Embedding generator created', [
                'provider' => $config['provider'],
                'model' => $config['model'],
                'dimensions' => $generator->getEmbeddingLength()
            ]);
        }

        return $this->generatorCache[$cacheKey];
    }

    /**
     * Create OpenAI embedding generator
     * 
     * @param string        $model  Model name
     * @param OpenAIConfig  $config LLPhant config
     * 
     * @return EmbeddingGeneratorInterface Generator instance
     * 
     * @throws \Exception If model is not supported
     */
    private function createOpenAIGenerator(string $model, OpenAIConfig $config): EmbeddingGeneratorInterface
    {
        return match ($model) {
            'text-embedding-ada-002' => new OpenAIADA002EmbeddingGenerator($config),
            'text-embedding-3-small' => new OpenAI3SmallEmbeddingGenerator($config),
            'text-embedding-3-large' => new OpenAI3LargeEmbeddingGenerator($config),
            default => throw new \Exception("Unsupported OpenAI model: {$model}")
        };
    }

    /**
     * Create Ollama embedding generator
     * 
     * @param string        $model  Model name
     * @param OpenAIConfig  $config LLPhant config (used for base URL)
     * 
     * @return EmbeddingGeneratorInterface Generator instance
     */
    private function createOllamaGenerator(string $model, OpenAIConfig $config): EmbeddingGeneratorInterface
    {
        // Ollama generator uses different initialization
        // For now, return a generic Ollama generator
        // The user will need to have Ollama running locally
        return new OllamaEmbeddingGenerator();
    }


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
            throw new \InvalidArgumentException('Vectors must have same dimensions');
        }

        $dotProduct = 0.0;
        $magnitude1 = 0.0;
        $magnitude2 = 0.0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] ** 2;
            $magnitude2 += $vector2[$i] ** 2;
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0.0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }
}

