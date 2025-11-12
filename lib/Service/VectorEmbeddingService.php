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
use LLPhant\OllamaConfig;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAIADA002EmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\Ollama\OllamaEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;

/**
 * Vector Embedding Service
 * 
 * Handles vector embeddings generation, storage, and semantic search using LLPhant.
 * This service is INDEPENDENT of SOLR and handles all LLM embedding operations.
 * 
 * RESPONSIBILITIES:
 * - Generate embeddings for text using multiple LLM providers (OpenAI, Fireworks, Ollama)
 * - Store embeddings in the database (oc_openregister_vectors table)
 * - Perform semantic similarity searches using cosine similarity
 * - Test embedding configurations without saving settings
 * - Manage embedding generators for different providers and models
 * 
 * PROVIDER SUPPORT:
 * - OpenAI: text-embedding-ada-002, text-embedding-3-small, text-embedding-3-large
 * - Fireworks AI: Custom OpenAI-compatible API with various models
 * - Ollama: Local models with custom configurations
 * 
 * ARCHITECTURE:
 * - Uses LLPhant library for embedding generation
 * - Stores embeddings in database for semantic search
 * - Independent of SOLR/keyword search infrastructure
 * - Can be used standalone for vector operations
 * 
 * INTEGRATION POINTS:
 * - ChatService: Uses embeddings for RAG (Retrieval Augmented Generation)
 * - SolrObjectService/SolrFileService: Can work together for hybrid search
 * - SettingsController: Delegates testing to this service
 * 
 * NOTE: This service does NOT depend on SOLR. For hybrid search that combines
 * keyword and semantic results, use ChatService or implement in calling code.
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
     * Generate embedding with custom configuration (for testing)
     * 
     * This method allows testing embedding generation with custom config
     * without saving it to settings. Useful for validating API keys and
     * provider configuration before saving.
     * 
     * @param string $text   Text to embed
     * @param array  $config Custom configuration array with:
     *                       - provider: 'openai', 'ollama', or 'fireworks'
     *                       - model: Model name
     *                       - apiKey: API key (for OpenAI/Fireworks)
     *                       - url: URL (for Ollama)
     *                       - baseUrl: Base URL (for Fireworks)
     * 
     * @return array Embedding vector (array of floats)
     * 
     * @throws \Exception If embedding generation fails
     */
    public function generateEmbeddingWithCustomConfig(string $text, array $config): array
    {
        $this->logger->debug('Generating embedding with custom config', [
            'text_length' => strlen($text),
            'provider' => $config['provider'] ?? 'unknown',
            'model' => $config['model'] ?? 'unknown'
        ]);

        try {
            // Normalize config format (frontend uses camelCase, backend uses snake_case)
            $normalizedConfig = [
                'provider' => $config['provider'],
                'model' => $config['model'] ?? null,
                'api_key' => $config['apiKey'] ?? null,
                'base_url' => $config['baseUrl'] ?? $config['url'] ?? null,
            ];

            // Validate required fields
            if (empty($normalizedConfig['provider'])) {
                throw new \Exception('Provider is required');
            }

            if (empty($normalizedConfig['model'])) {
                throw new \Exception('Model is required');
            }

            // Validate API keys for providers that need them
            if (in_array($normalizedConfig['provider'], ['openai', 'fireworks']) && empty($normalizedConfig['api_key'])) {
                throw new \Exception("API key is required for {$normalizedConfig['provider']}");
            }

            // Create embedding generator
            $generator = $this->getEmbeddingGenerator($normalizedConfig);
            
            // Generate embedding
            $embedding = $generator->embedText($text);
            
            $this->logger->debug('Embedding generated successfully with custom config', [
                'dimensions' => count($embedding),
                'model' => $normalizedConfig['model']
            ]);
            
            return $embedding;

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate embedding with custom config', [
                'error' => $e->getMessage(),
                'provider' => $config['provider'] ?? 'unknown',
                'text_length' => strlen($text)
            ]);
            throw new \Exception('Embedding generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Test embedding generation with custom configuration
     * 
     * Tests if the provided embedding configuration works correctly by generating
     * a test embedding. Does not save any configuration or store the embedding.
     * 
     * @param string $provider Provider name ('openai', 'fireworks', 'ollama')
     * @param array  $config   Provider-specific configuration
     * @param string $testText Optional test text to embed
     * 
     * @return array Test results with success status and embedding info
     */
    public function testEmbedding(string $provider, array $config, string $testText = 'This is a test embedding to verify the LLM configuration.'): array
    {
        $this->logger->info('[VectorEmbeddingService] Testing embedding generation', [
            'provider' => $provider,
            'model' => $config['model'] ?? 'unknown',
            'testTextLength' => strlen($testText),
        ]);

        try {
            // Generate embedding using custom config
            $embedding = $this->generateEmbeddingWithCustomConfig($testText, [
                'provider' => $provider,
                'model' => $config['model'] ?? null,
                'apiKey' => $config['apiKey'] ?? null,
                'baseUrl' => $config['baseUrl'] ?? $config['url'] ?? null,
            ]);

            $this->logger->info('[VectorEmbeddingService] Embedding test successful', [
                'provider' => $provider,
                'model' => $config['model'] ?? 'unknown',
                'dimensions' => count($embedding),
            ]);

            return [
                'success' => true,
                'message' => 'Embedding test successful',
                'data' => [
                    'provider' => $provider,
                    'model' => $config['model'] ?? 'unknown',
                    'vectorLength' => count($embedding),
                    'sampleValues' => array_slice($embedding, 0, 5),
                    'testText' => $testText,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('[VectorEmbeddingService] Embedding test failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Failed to generate embedding: ' . $e->getMessage(),
            ];
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

            // Sanitize chunk_text to prevent encoding errors
            // Remove invalid UTF-8 sequences and control characters
            $sanitizedChunkText = $chunkText !== null ? $this->sanitizeText($chunkText) : null;

            $qb = $this->db->getQueryBuilder();
            $qb->insert('openregister_vectors')
                ->values([
                    'entity_type' => $qb->createNamedParameter($entityType),
                    'entity_id' => $qb->createNamedParameter($entityId),
                    'chunk_index' => $qb->createNamedParameter($chunkIndex, \PDO::PARAM_INT),
                    'total_chunks' => $qb->createNamedParameter($totalChunks, \PDO::PARAM_INT),
                    'chunk_text' => $qb->createNamedParameter($sanitizedChunkText),
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
                // Support both string and array for entity_type
                if (is_array($filters['entity_type'])) {
                    $qb->andWhere($qb->expr()->in('entity_type', $qb->createNamedParameter($filters['entity_type'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
                } else {
                    $qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($filters['entity_type'])));
                }
            }

            if (isset($filters['entity_id'])) {
                // Support both string and array for entity_id
                if (is_array($filters['entity_id'])) {
                    $qb->andWhere($qb->expr()->in('entity_id', $qb->createNamedParameter($filters['entity_id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
                } else {
                    $qb->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($filters['entity_id'])));
                }
            }

            if (isset($filters['embedding_model'])) {
                $qb->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($filters['embedding_model'])));
            }
            
            // PERFORMANCE OPTIMIZATION: Limit vectors fetched to reduce PHP similarity calculations
            // TODO: Replace with proper database-level vector search (PostgreSQL + pgvector)
            // For now, limit to most recent vectors to improve performance
            // This is a temporary fix until we migrate to a database with native vector operations
            $maxVectors = $filters['max_vectors'] ?? 500; // Default: Compare against max 500 vectors (reduced from 10000)
            $qb->setMaxResults($maxVectors);
            $qb->orderBy('created_at', 'DESC'); // Get most recent vectors first
            
            $this->logger->debug('[VectorEmbeddingService] Applied vector fetch limit for performance', [
                'max_vectors' => $maxVectors,
                'note' => 'Temporary optimization until PostgreSQL + pgvector migration'
            ]);

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
     * Get vector count for specific entity type(s)
     * 
     * @param string|null $entityType Filter by entity type ('object', 'file', or null for all)
     * @param array       $filters    Additional filters (e.g., entity_id, model)
     * 
     * @return int Total count
     */
    public function getVectorCount(?string $entityType = null, array $filters = []): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total'))
                ->from('openregister_vectors');

            // Filter by entity type
            if ($entityType !== null) {
                $qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));
            }

            // Apply additional filters
            foreach ($filters as $field => $value) {
                $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value)));
            }

            $result = $qb->executeQuery();
            $count = (int) $result->fetchOne();
            $result->closeCursor();

            return $count;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get vector count', [
                'entity_type' => $entityType,
                'error' => $e->getMessage()
            ]);
            return 0;
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
        // Load from LLM settings
        $llmSettings = $this->settingsService->getLLMSettingsOnly();
        
        // Determine provider: use provided, or fall back to configured embedding provider
        $configuredProvider = $provider ?? ($llmSettings['embeddingProvider'] ?? 'openai');
        
        // Get provider-specific configuration
        $providerConfig = match ($configuredProvider) {
            'fireworks' => $llmSettings['fireworksConfig'] ?? [],
            'ollama' => $llmSettings['ollamaConfig'] ?? [],
            'openai' => $llmSettings['openaiConfig'] ?? [],
            default => []
        };
        
        // Extract model and credentials based on provider
        $model = match ($configuredProvider) {
            'fireworks' => $providerConfig['embeddingModel'] ?? 'thenlper/gte-base',
            'ollama' => $providerConfig['model'] ?? 'nomic-embed-text',
            'openai' => $providerConfig['model'] ?? 'text-embedding-ada-002',
            default => 'text-embedding-ada-002'
        };
        
        $apiKey = $providerConfig['apiKey'] ?? null;
        $baseUrl = $providerConfig['baseUrl'] ?? $providerConfig['url'] ?? null;

        return [
            'provider' => $configuredProvider,
            'model' => $model,
            'dimensions' => self::EMBEDDING_DIMENSIONS[$model] ?? 1536,
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

            // Create appropriate generator based on provider and model
            $generator = match ($config['provider']) {
                'openai' => $this->createOpenAIGenerator($config['model'], $config),
                'fireworks' => $this->createFireworksGenerator($config['model'], $config),
                'ollama' => $this->createOllamaGenerator($config['model'], $config),
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
     * @param string $model  Model name
     * @param array  $config Configuration array with api_key and base_url
     * 
     * @return EmbeddingGeneratorInterface Generator instance
     * 
     * @throws \Exception If model is not supported
     */
    private function createOpenAIGenerator(string $model, array $config): EmbeddingGeneratorInterface
    {
        $llphantConfig = new OpenAIConfig();
        
        if (!empty($config['api_key'])) {
            $llphantConfig->apiKey = $config['api_key'];
        }
        
        if (!empty($config['base_url'])) {
            $llphantConfig->url = $config['base_url'];
        }
        
        return match ($model) {
            'text-embedding-ada-002' => new OpenAIADA002EmbeddingGenerator($llphantConfig),
            'text-embedding-3-small' => new OpenAI3SmallEmbeddingGenerator($llphantConfig),
            'text-embedding-3-large' => new OpenAI3LargeEmbeddingGenerator($llphantConfig),
            default => throw new \Exception("Unsupported OpenAI model: {$model}")
        };
    }

    /**
     * Create Fireworks AI embedding generator
     * 
     * Fireworks AI uses OpenAI-compatible API, so we create a custom wrapper
     * that works with any Fireworks model.
     * 
     * @param string $model  Model name (e.g., 'nomic-ai/nomic-embed-text-v1.5')
     * @param array  $config Configuration array with api_key and base_url
     * 
     * @return EmbeddingGeneratorInterface Generator instance
     * 
     * @throws \Exception If model is not supported
     */
    private function createFireworksGenerator(string $model, array $config): EmbeddingGeneratorInterface
    {
        // Create a custom anonymous class that implements the EmbeddingGeneratorInterface
        // This allows us to use any Fireworks model name without LLPhant's restrictions
        return new class($model, $config, $this->logger) implements EmbeddingGeneratorInterface {
            private string $model;
            private array $config;
            private $logger;

            public function __construct(string $model, array $config, $logger)
            {
                $this->model = $model;
                $this->config = $config;
                $this->logger = $logger;
            }

            public function embedText(string $text): array
            {
                $url = rtrim($this->config['base_url'] ?? 'https://api.fireworks.ai/inference/v1', '/') . '/embeddings';
                
                $this->logger->debug('Calling Fireworks AI API', [
                    'url' => $url,
                    'model' => $this->model
                ]);

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $this->config['api_key'],
                    'Content-Type: application/json',
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'model' => $this->model,
                    'input' => $text,
                ]));

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($error) {
                    throw new \Exception("Fireworks API request failed: {$error}");
                }

                if ($httpCode !== 200) {
                    throw new \Exception("Fireworks API returned HTTP {$httpCode}: {$response}");
                }

                $data = json_decode($response, true);
                if (!isset($data['data'][0]['embedding'])) {
                    throw new \Exception("Unexpected Fireworks API response format: {$response}");
                }

                return $data['data'][0]['embedding'];
            }

            public function getEmbeddingLength(): int
            {
                // Return expected dimensions based on model
                return match ($this->model) {
                    'nomic-ai/nomic-embed-text-v1.5' => 768,
                    'thenlper/gte-base' => 768,
                    'thenlper/gte-large' => 1024,
                    'WhereIsAI/UAE-Large-V1' => 1024,
                    default => 768
                };
            }

            public function embedDocument(\LLPhant\Embeddings\Document $document): \LLPhant\Embeddings\Document
            {
                // Embed the document content and store it back in the document
                $document->embedding = $this->embedText($document->content);
                return $document;
            }

            public function embedDocuments(array $documents): array
            {
                // Embed multiple documents
                foreach ($documents as $document) {
                    $document->embedding = $this->embedText($document->content);
                }
                return $documents;
            }
        };
    }

    /**
     * Create Ollama embedding generator
     * 
     * @param string $model  Model name (e.g., 'nomic-embed-text')
     * @param array  $config Configuration array with base_url
     * 
     * @return EmbeddingGeneratorInterface Generator instance
     */
    private function createOllamaGenerator(string $model, array $config): EmbeddingGeneratorInterface
    {
        // Create native Ollama configuration
        $ollamaConfig = new OllamaConfig();
        $ollamaConfig->url = rtrim($config['base_url'] ?? 'http://localhost:11434', '/') . '/api/';
        $ollamaConfig->model = $model;
        
        // Create and return Ollama embedding generator with native config
        return new OllamaEmbeddingGenerator($ollamaConfig);
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

    /**
     * Vectorize file chunks
     * 
     * Generates embeddings for file chunks and stores them in the vector database.
     * 
     * @param int            $fileId   File ID
     * @param array<string>  $chunks   Text chunks
     * @param array          $metadata File metadata
     * @param string|null    $provider Embedding provider (null = use default)
     * 
     * @return array{success: bool, vectors_created: int, errors?: array} Result
     * 
     * @throws \Exception If vectorization fails
     */
    public function vectorizeFileChunks(int $fileId, array $chunks, array $metadata = [], ?string $provider = null): array
    {
        $this->logger->info('Vectorizing file chunks', [
            'file_id' => $fileId,
            'chunk_count' => count($chunks)
        ]);

        $startTime = microtime(true);
        $vectorsCreated = 0;
        $errors = [];

        foreach ($chunks as $index => $chunkText) {
            try {
                // Generate embedding
                $embeddingData = $this->generateEmbedding($chunkText, $provider);

                // Store in vector database
                $this->storeVector(
                    entityType: 'file_chunk',
                    entityId: "{$fileId}_chunk_{$index}",
                    embedding: $embeddingData['embedding'],
                    metadata: array_merge($metadata, [
                        'file_id' => $fileId,
                        'chunk_index' => $index,
                        'chunk_text' => substr($chunkText, 0, 1000), // Store first 1000 chars for preview
                        'model' => $embeddingData['model'],
                        'dimensions' => $embeddingData['dimensions']
                    ])
                );

                $vectorsCreated++;
            } catch (\Exception $e) {
                $errors[$index] = $e->getMessage();
                $this->logger->error('Failed to vectorize chunk', [
                    'file_id' => $fileId,
                    'chunk_index' => $index,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info('Completed file chunk vectorization', [
            'file_id' => $fileId,
            'vectors_created' => $vectorsCreated,
            'errors' => count($errors),
            'execution_time_ms' => $executionTime
        ]);

        return [
            'success' => count($errors) === 0,
            'vectors_created' => $vectorsCreated,
            'errors' => $errors,
            'execution_time_ms' => $executionTime
        ];
    }

    /**
     * Search file chunks by semantic similarity
     * 
     * @param string   $query    Search query text
     * @param int      $limit    Maximum results to return
     * @param float    $minScore Minimum similarity score (0-1)
     * @param int|null $fileId   Optional file ID filter
     * 
     * @return array<array> Search results with scores and metadata
     * 
     * @throws \Exception If search fails
     */
    public function searchFileChunks(string $query, int $limit = 10, float $minScore = 0.7, ?int $fileId = null): array
    {
        $this->logger->debug('Searching file chunks', [
            'query' => $query,
            'limit' => $limit,
            'min_score' => $minScore,
            'file_id' => $fileId
        ]);

        // Generate query embedding
        $queryEmbedding = $this->generateEmbedding($query);

        // Build filter
        $filter = ['entity_type' => 'file_chunk'];
        if ($fileId !== null) {
            $filter['file_id'] = $fileId;
        }

        // Search similar vectors
        $results = $this->searchSimilarVectors(
            $queryEmbedding['embedding'],
            $limit,
            $minScore,
            $filter
        );

        return $results;
    }

    /**
     * Get vector statistics for file chunks
     * 
     * @return array{total_chunks: int, unique_files: int, average_dimensions: int}
     */
    public function getFileChunkVectorStats(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('COUNT(*) as total'))
            ->from('openregister_vectors')
            ->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('file_chunk')));

        $result = $qb->execute();
        $total = (int) $result->fetchOne();
        $result->closeCursor();

        // Count unique files
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(DISTINCT JSON_EXTRACT(metadata, \'$.file_id\')) as unique_files'))
            ->from('openregister_vectors')
            ->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('file_chunk')));

        $result = $qb->execute();
        $uniqueFiles = (int) $result->fetchOne();
        $result->closeCursor();

        // Get average dimensions
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('AVG(dimensions) as avg_dimensions'))
            ->from('openregister_vectors')
            ->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('file_chunk')));

        $result = $qb->execute();
        $avgDimensions = (int) $result->fetchOne();
        $result->closeCursor();

        return [
            'total_chunks' => $total,
            'unique_files' => $uniqueFiles,
            'average_dimensions' => $avgDimensions
        ];
    }

    /**
     * Sanitize text to prevent UTF-8 encoding errors
     * 
     * Removes invalid UTF-8 sequences and problematic control characters
     * that can cause database storage issues.
     * 
     * @param string $text Text to sanitize
     * 
     * @return string Sanitized text safe for UTF-8 storage
     */
    private function sanitizeText(string $text): string
    {
        // Step 1: Remove invalid UTF-8 sequences
        // This handles cases like \xC2 that aren't valid UTF-8
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Step 2: Remove NULL bytes and other problematic control characters
        // but keep newlines, tabs, and carriage returns
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        
        // Step 3: Replace any remaining invalid UTF-8 with replacement character
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        
        // Step 4: Normalize whitespace (optional but helpful)
        $text = preg_replace('/\s+/u', ' ', $text);
        
        return trim($text);
    }

    /**
     * Check if embedding model has changed since vectors were created
     *
     * Compares the configured embedding model with models used in existing vectors.
     * If they don't match, vectors need to be regenerated.
     *
     * @return array Status information with mismatch details
     */
    public function checkEmbeddingModelMismatch(): array
    {
        try {
            // Get current configured model
            $settings = $this->settingsService->getLLMSettingsOnly();
            $currentProvider = $settings['embeddingProvider'] ?? null;
            $currentModel = null;

            if ($currentProvider === 'openai') {
                $currentModel = $settings['openaiConfig']['model'] ?? null;
            } elseif ($currentProvider === 'ollama') {
                $currentModel = $settings['ollamaConfig']['model'] ?? null;
            } elseif ($currentProvider === 'fireworks') {
                $currentModel = $settings['fireworksConfig']['embeddingModel'] ?? null;
            }

            if (!$currentModel) {
                return [
                    'has_vectors' => false,
                    'mismatch' => false,
                    'message' => 'No embedding model configured'
                ];
            }

            // Check if any vectors exist
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total'))
                ->from('openregister_vectors');
            
            $result = $qb->executeQuery();
            $totalVectors = (int) $result->fetchOne();
            $result->closeCursor();

            if ($totalVectors === 0) {
                return [
                    'has_vectors' => false,
                    'mismatch' => false,
                    'current_model' => $currentModel,
                    'message' => 'No vectors exist yet'
                ];
            }

            // Get distinct embedding models used in existing vectors
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('embedding_model')
                ->from('openregister_vectors')
                ->where($qb->expr()->isNotNull('embedding_model'));

            $result = $qb->executeQuery();
            $existingModels = [];
            while ($row = $result->fetch()) {
                $existingModels[] = $row['embedding_model'];
            }
            $result->closeCursor();

            // Count vectors with NULL model (created before tracking)
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'null_count'))
                ->from('openregister_vectors')
                ->where($qb->expr()->isNull('embedding_model'));
            
            $result = $qb->executeQuery();
            $nullModelCount = (int) $result->fetchOne();
            $result->closeCursor();

            // Check for mismatch
            $hasMismatch = false;
            $mismatchDetails = [];

            foreach ($existingModels as $existingModel) {
                if ($existingModel !== $currentModel) {
                    $hasMismatch = true;
                    $mismatchDetails[] = $existingModel;
                }
            }

            return [
                'has_vectors' => true,
                'mismatch' => $hasMismatch || $nullModelCount > 0,
                'current_model' => $currentModel,
                'existing_models' => $existingModels,
                'total_vectors' => $totalVectors,
                'null_model_count' => $nullModelCount,
                'mismatched_models' => $mismatchDetails,
                'message' => $hasMismatch || $nullModelCount > 0
                    ? 'Embedding model has changed. Please clear all vectors and re-vectorize.'
                    : 'All vectors use the current embedding model'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to check embedding model mismatch', [
                'error' => $e->getMessage()
            ]);

            return [
                'has_vectors' => false,
                'mismatch' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Clear all embeddings from the database
     *
     * Deletes all vectors. This should be done when changing embedding models.
     *
     * @return array Result with count of deleted vectors
     */
    public function clearAllEmbeddings(): array
    {
        try {
            // Count vectors before deletion
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total'))
                ->from('openregister_vectors');
            
            $result = $qb->executeQuery();
            $totalVectors = (int) $result->fetchOne();
            $result->closeCursor();

            if ($totalVectors === 0) {
                return [
                    'success' => true,
                    'deleted' => 0,
                    'message' => 'No vectors to delete'
                ];
            }

            // Delete all vectors
            $qb = $this->db->getQueryBuilder();
            $qb->delete('openregister_vectors');
            $deletedCount = $qb->executeStatement();

            $this->logger->info('All embeddings cleared', [
                'deleted_count' => $deletedCount
            ]);

            return [
                'success' => true,
                'deleted' => $deletedCount,
                'message' => "Deleted {$deletedCount} vectors successfully"
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to clear embeddings', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Failed to clear embeddings: ' . $e->getMessage()
            ];
        }
    }
}

