<?php

/**
 * Vector Embeddings Coordinator
 *
 * Main entry point for all vector embedding operations.
 * Coordinates handlers for generation, storage, search, and statistics.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
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

namespace OCA\OpenRegister\Service\Vectorization;

use Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use OCA\OpenRegister\Service\Vectorization\Handlers\VectorStorageHandler;
use OCA\OpenRegister\Service\Vectorization\Handlers\VectorSearchHandler;
use OCA\OpenRegister\Service\Vectorization\Handlers\VectorStatsHandler;

/**
 * VectorEmbeddings
 *
 * Facade/Coordinator for all vector embedding operations.
 * Delegates to specialized handlers for generation, storage, search, and statistics.
 *
 * ARCHITECTURE:
 * - This is the public API for vector operations
 * - All operations are delegated to specialized handlers
 * - Handlers are: EmbeddingGeneratorHandler, VectorStorageHandler, VectorSearchHandler, VectorStatsHandler
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 */
class VectorEmbeddings
{
    /**
     * Constructor
     *
     * @param IDBConnection             $db               Database connection
     * @param SettingsService           $settingsService  Settings service
     * @param EmbeddingGeneratorHandler $generatorHandler Embedding generator handler
     * @param VectorStorageHandler      $storageHandler   Vector storage handler
     * @param VectorSearchHandler       $searchHandler    Vector search handler
     * @param VectorStatsHandler        $statsHandler     Vector statistics handler
     * @param LoggerInterface           $logger           PSR-3 logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly SettingsService $settingsService,
        private readonly EmbeddingGeneratorHandler $generatorHandler,
        private readonly VectorStorageHandler $storageHandler,
        private readonly VectorSearchHandler $searchHandler,
        private readonly VectorStatsHandler $statsHandler,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    // =============================================================================
    // EMBEDDING GENERATION
    // =============================================================================

    /**
     * Generate embedding for a single text
     *
     * @param string      $text     Text to embed
     * @param string|null $provider Embedding provider (null = use default from settings)
     *
     * @return (float[]|int|string)[] Embedding data
     *
     * @throws \Exception If embedding generation fails
     *
     * @psalm-return array{embedding: array<float>, model: string, dimensions: int<0, max>}
     */
    public function generateEmbedding(string $text, ?string $provider=null): array
    {
        $config = $this->getEmbeddingConfig($provider);

        $this->logger->debug(
            message: 'Generating embedding',
            context: [
                'text_length' => strlen($text),
                'provider'    => $config['provider'],
                'model'       => $config['model'],
            ]
        );

        try {
            $generator  = $this->generatorHandler->getGenerator($config);
            $embedding  = $generator->embedText($text);
            $dimensions = count($embedding);

            $this->logger->debug(
                message: 'Embedding generated successfully',
                context: [
                    'dimensions' => $dimensions,
                    'model'      => $config['model'],
                ]
            );

            return [
                'embedding'  => $embedding,
                'model'      => $config['model'],
                'dimensions' => $dimensions,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to generate embedding',
                context: [
                    'error'       => $e->getMessage(),
                    'provider'    => $config['provider'],
                    'text_length' => strlen($text),
                ]
            );
            throw new Exception('Embedding generation failed: '.$e->getMessage());
        }//end try
    }//end generateEmbedding()

    /**
     * Generate embedding with custom configuration (for testing)
     *
     * @param string $text   Text to embed
     * @param array  $config Custom configuration array
     *
     * @return float[] Embedding vector (array of floats)
     *
     * @throws \Exception If embedding generation fails
     *
     * @psalm-return array<float>
     */
    public function generateEmbeddingWithCustomConfig(string $text, array $config): array
    {
        $this->logger->debug(
            message: 'Generating embedding with custom config',
            context: [
                'text_length' => strlen($text),
                'provider'    => $config['provider'] ?? 'unknown',
                'model'       => $config['model'] ?? 'unknown',
            ]
        );

        try {
            // Normalize config format.
            $normalizedConfig = [
                'provider' => $config['provider'],
                'model'    => $config['model'] ?? null,
                'api_key'  => $config['apiKey'] ?? null,
                'base_url' => $config['baseUrl'] ?? $config['url'] ?? null,
            ];

            // Validate required fields.
            if (empty($normalizedConfig['provider']) === true) {
                throw new Exception('Provider is required');
            }

            if (empty($normalizedConfig['model']) === true) {
                throw new Exception('Model is required');
            }

            // Validate API keys for providers that need them.
            if (in_array($normalizedConfig['provider'], ['openai', 'fireworks'], true) === true
                && empty($normalizedConfig['api_key']) === true
            ) {
                throw new Exception("API key is required for {$normalizedConfig['provider']}");
            }

            // Create embedding generator and generate.
            $generator = $this->generatorHandler->getGenerator($normalizedConfig);
            $embedding = $generator->embedText($text);

            $this->logger->debug(
                message: 'Embedding generated successfully with custom config',
                context: [
                    'dimensions' => count($embedding),
                    'model'      => $normalizedConfig['model'],
                ]
            );

            return $embedding;
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to generate embedding with custom config',
                context: [
                    'error'       => $e->getMessage(),
                    'provider'    => $config['provider'] ?? 'unknown',
                    'text_length' => strlen($text),
                ]
            );
            throw new Exception('Embedding generation failed: '.$e->getMessage());
        }//end try
    }//end generateEmbeddingWithCustomConfig()

    /**
     * Test embedding generation with custom configuration
     *
     * @param string $provider Provider name ('openai', 'fireworks', 'ollama')
     * @param array  $config   Provider-specific configuration
     * @param string $testText Optional test text to embed
     *
     * @return ((float[]|int|mixed|string)[]|bool|string)[]
     *
     * @psalm-return array{success: bool, error?: string, message: string,
     *     data?: array{provider: string, model: 'unknown'|mixed,
     *     vectorLength: int<0, max>, sampleValues: array<float>, testText: string}>
     */
    public function testEmbedding(string $provider, array $config, string $testText='Test.'): array
    {
        $this->logger->info(
            message: '[VectorEmbeddings] Testing embedding generation',
            context: [
                'provider'       => $provider,
                'model'          => $config['model'] ?? 'unknown',
                'testTextLength' => strlen($testText),
            ]
        );

        try {
            $embedding = $this->generateEmbeddingWithCustomConfig(
                text: $testText,
                config: [
                    'provider' => $provider,
                    'model'    => $config['model'] ?? null,
                    'apiKey'   => $config['apiKey'] ?? null,
                    'baseUrl'  => $config['baseUrl'] ?? $config['url'] ?? null,
                ]
            );

            $this->logger->info(
                message: '[VectorEmbeddings] Embedding test successful',
                context: [
                    'provider'   => $provider,
                    'model'      => $config['model'] ?? 'unknown',
                    'dimensions' => count($embedding),
                ]
            );

            return [
                'success' => true,
                'message' => 'Embedding test successful',
                'data'    => [
                    'provider'     => $provider,
                    'model'        => $config['model'] ?? 'unknown',
                    'vectorLength' => count($embedding),
                    'sampleValues' => array_slice($embedding, 0, 5),
                    'testText'     => $testText,
                ],
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: '[VectorEmbeddings] Embedding test failed',
                context: [
                    'provider' => $provider,
                    'error'    => $e->getMessage(),
                ]
            );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'message' => 'Failed to generate embedding: '.$e->getMessage(),
            ];
        }//end try
    }//end testEmbedding()

    /**
     * Generate embeddings for multiple texts in batch
     *
     * @param array<string> $texts    Array of texts to embed
     * @param string|null   $provider Embedding provider
     *
     * @return array<int, array{embedding: array<float>, model: string, dimensions: int}> Array of embeddings
     *
     * @throws \Exception If batch embedding generation fails
     */
    public function generateBatchEmbeddings(array $texts, ?string $provider=null): array
    {
        $config = $this->getEmbeddingConfig($provider);

        $this->logger->info(
            message: 'Generating batch embeddings',
            context: [
                'count'    => count($texts),
                'provider' => $config['provider'],
                'model'    => $config['model'],
            ]
        );

        try {
            $generator = $this->generatorHandler->getGenerator($config);

            // Generate embeddings individually for each text.
            $results = [];
            foreach ($texts as $index => $text) {
                try {
                    $embedding = $generator->embedText($text);
                    $results[] = [
                        'embedding'  => $embedding,
                        'model'      => $config['model'],
                        'dimensions' => count($embedding),
                    ];
                } catch (Exception $e) {
                    $this->logger->warning(
                        message: 'Failed to generate embedding for text',
                        context: [
                            'index' => $index,
                            'error' => $e->getMessage(),
                        ]
                    );
                    $results[] = [
                        'embedding'  => null,
                        'model'      => $config['model'],
                        'dimensions' => 0,
                        'error'      => $e->getMessage(),
                    ];
                }//end try
            }//end foreach

            $successful = count(array_filter($results, fn($r) => $r['embedding'] !== null));

            $this->logger->info(
                message: 'Batch embedding generation completed',
                context: [
                    'total'      => count($texts),
                    'successful' => $successful,
                    'failed'     => count($texts) - $successful,
                ]
            );

            return $results;
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to generate batch embeddings',
                context: [
                    'error' => $e->getMessage(),
                    'count' => count($texts),
                ]
            );
            throw new Exception('Batch embedding generation failed: '.$e->getMessage());
        }//end try
    }//end generateBatchEmbeddings()

    // =============================================================================
    // VECTOR STORAGE
    // =============================================================================

    /**
     * Store vector embedding
     *
     * @param string      $entityType  Entity type ('object' or 'file')
     * @param string      $entityId    Entity UUID
     * @param array       $embedding   Vector embedding (array of floats)
     * @param string      $model       Model used to generate embedding
     * @param int         $dimensions  Number of dimensions
     * @param int         $chunkIndex  Chunk index (0 for objects, N for file chunks)
     * @param int         $totalChunks Total number of chunks
     * @param string|null $chunkText   The text that was embedded
     * @param array       $metadata    Additional metadata as associative array
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
        int $chunkIndex=0,
        int $totalChunks=1,
        ?string $chunkText=null,
        array $metadata=[]
    ): int {
        $backend = $this->getVectorSearchBackend();

        return $this->storageHandler->storeVector(
            entityType: $entityType,
            entityId: $entityId,
            embedding: $embedding,
            model: $model,
            dimensions: $dimensions,
            chunkIndex: $chunkIndex,
            totalChunks: $totalChunks,
            chunkText: $chunkText,
            metadata: $metadata,
            backend: $backend
        );
    }//end storeVector()

    // =============================================================================
    // VECTOR SEARCH
    // =============================================================================

    /**
     * Perform semantic similarity search
     *
     * @param string      $query    Query text to search for
     * @param int         $limit    Maximum number of results
     * @param array       $filters  Additional filters (entity_type, etc.)
     * @param string|null $provider Embedding provider
     *
     * @return array<int,array<string,mixed>> Search results
     *
     * @throws \Exception If search fails
     */
    public function semanticSearch(
        string $query,
        int $limit=10,
        array $filters=[],
        ?string $provider=null
    ): array {
        // Generate query embedding.
        $queryEmbeddingData = $this->generateEmbedding(text: $query, provider: $provider);
        $queryEmbedding     = $queryEmbeddingData['embedding'];

        // Delegate to search handler.
        $backend = $this->getVectorSearchBackend();

        return $this->searchHandler->semanticSearch(
            queryEmbedding: $queryEmbedding,
            limit: $limit,
            filters: $filters,
            backend: $backend
        );
    }//end semanticSearch()

    /**
     * Perform hybrid search combining keyword (SOLR) and semantic (vectors)
     *
     * @param string      $query       Query text
     * @param array       $solrFilters SOLR-specific filters
     * @param int         $limit       Maximum results
     * @param array       $weights     Weights for each search type ['solr' => 0.5, 'vector' => 0.5]
     * @param string|null $provider    Embedding provider
     *
     * @return (((array|bool|float|int|mixed|null)[]|float|int)[]|float|int)[] Hybrid search results
     *
     * @throws \Exception If hybrid search fails
     *
     * @psalm-return array{results: list<array{chunk_index: 0|mixed,
     *     chunk_text: mixed|null, combined_score: 0|float, entity_id: mixed,
     *     entity_type: mixed, in_solr: bool, in_vector: bool,
     *     metadata: array<never, never>|mixed, solr_rank: float|int|null,
     *     solr_score: mixed|null, vector_rank: float|int|null,
     *     vector_similarity: mixed|null}>, total: int<0, max>,
     *     search_time_ms: float, source_breakdown: array{vector_only: int<0, max>,
     *     solr_only: int<0, max>, both: int<0, max>},
     *     weights: array{solr: float, vector: float}>
     */
    public function hybridSearch(
        string $query,
        array $solrFilters=[],
        int $limit=20,
        array $weights=['solr' => 0.5, 'vector' => 0.5],
        ?string $provider=null
    ): array {
        // Generate query embedding.
        $queryEmbeddingData = $this->generateEmbedding(text: $query, provider: $provider);
        $queryEmbedding     = $queryEmbeddingData['embedding'];

        // Get SOLR results (placeholder - implement when integrating with SOLR).
        $solrResults = $solrFilters['solr_results'] ?? [];

        // Delegate to search handler.
        $backend = $this->getVectorSearchBackend();

        return $this->searchHandler->hybridSearch(
            queryEmbedding: $queryEmbedding,
            solrResults: $solrResults,
            limit: $limit,
            weights: $weights,
            backend: $backend
        );
    }//end hybridSearch()

    // =============================================================================
    // STATISTICS
    // =============================================================================

    /**
     * Get vector statistics
     *
     * @return ((int|mixed)[]|int|string)[] Statistics about stored vectors
     *
     * @psalm-return array{total_vectors: int, by_type: array<int>,
     *     by_model: array<int|mixed>, object_vectors?: int, file_vectors?: int,
     *     source?: 'solr'|'solr_error'|'solr_unavailable'}
     */
    public function getVectorStats(): array
    {
        $backend = $this->getVectorSearchBackend();

        return $this->statsHandler->getStats($backend);
    }//end getVectorStats()

    // =============================================================================
    // MANAGEMENT
    // =============================================================================

    /**
     * Check if embedding model has changed since vectors were created
     *
     * @return (array|bool|int|mixed|string)[]
     *
     * @psalm-return array{has_vectors: bool, mismatch: bool, error?: string,
     *     message?: string, current_model?: mixed, existing_models?: list<mixed>,
     *     total_vectors?: int, null_model_count?: int,
     *     mismatched_models?: list<mixed>}
     */
    public function checkEmbeddingModelMismatch(): array
    {
        try {
            // Get current configured model.
            $settings        = $this->settingsService->getLLMSettingsOnly();
            $currentProvider = $settings['embeddingProvider'] ?? null;
            $currentModel    = null;

            if ($currentProvider === 'openai') {
                $currentModel = $settings['openaiConfig']['model'] ?? null;
            } else if ($currentProvider === 'ollama') {
                $currentModel = $settings['ollamaConfig']['model'] ?? null;
            } else if ($currentProvider === 'fireworks') {
                $currentModel = $settings['fireworksConfig']['embeddingModel'] ?? null;
            }

            if ($currentModel === null || $currentModel === '') {
                return [
                    'has_vectors' => false,
                    'mismatch'    => false,
                    'message'     => 'No embedding model configured',
                ];
            }

            // Check database for existing vectors.
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total'))
                ->from('openregister_vectors');
            $result       = $qb->executeQuery();
            $totalVectors = (int) $result->fetchOne();
            $result->closeCursor();

            if ($totalVectors === 0) {
                return [
                    'has_vectors'   => false,
                    'mismatch'      => false,
                    'current_model' => $currentModel,
                    'message'       => 'No vectors exist yet',
                ];
            }

            // Get distinct embedding models.
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('embedding_model')
                ->from('openregister_vectors')
                ->where($qb->expr()->isNotNull('embedding_model'));
            $result         = $qb->executeQuery();
            $existingModels = [];
            while (($row = $result->fetch()) !== false) {
                $existingModels[] = $row['embedding_model'];
            }

            $result->closeCursor();

            // Count vectors with NULL model.
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'null_count'))
                ->from('openregister_vectors')
                ->where($qb->expr()->isNull('embedding_model'));
            $result         = $qb->executeQuery();
            $nullModelCount = (int) $result->fetchOne();
            $result->closeCursor();

            // Check for mismatch.
            $hasMismatch     = false;
            $mismatchDetails = [];

            foreach ($existingModels as $existingModel) {
                if ($existingModel !== $currentModel) {
                    $hasMismatch       = true;
                    $mismatchDetails[] = $existingModel;
                }
            }

            $message = 'All vectors use the same embedding model.';
            if ($hasMismatch === true) {
                $message = 'Multiple embedding models detected. Consider re-embedding all vectors with a single model.';
            } else if ($nullModelCount > 0) {
                $message = sprintf('%d vectors have no model information.', $nullModelCount);
            }

            return [
                'has_vectors'       => true,
                'mismatch'          => $hasMismatch || $nullModelCount > 0,
                'current_model'     => $currentModel,
                'existing_models'   => $existingModels,
                'total_vectors'     => $totalVectors,
                'null_model_count'  => $nullModelCount,
                'mismatched_models' => $mismatchDetails,
                'message'           => $message,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to check embedding model mismatch',
                context: ['error' => $e->getMessage()]
            );

            return [
                'has_vectors' => false,
                'mismatch'    => false,
                'error'       => $e->getMessage(),
            ];
        }//end try
    }//end checkEmbeddingModelMismatch()

    /**
     * Clear all embeddings from the database
     *
     * @return (bool|int|string)[]
     *
     * @psalm-return array{success: bool, error?: string, message: string, deleted?: int}
     */
    public function clearAllEmbeddings(): array
    {
        try {
            // Count vectors before deletion.
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total'))
                ->from('openregister_vectors');
            $result       = $qb->executeQuery();
            $totalVectors = (int) $result->fetchOne();
            $result->closeCursor();

            if ($totalVectors === 0) {
                return [
                    'success' => true,
                    'deleted' => 0,
                    'message' => 'No vectors to delete',
                ];
            }

            // Delete all vectors.
            $qb = $this->db->getQueryBuilder();
            $qb->delete('openregister_vectors');
            $deletedCount = $qb->executeStatement();

            $this->logger->info(
                message: 'All embeddings cleared',
                context: ['deleted_count' => $deletedCount]
            );

            return [
                'success' => true,
                'deleted' => $deletedCount,
                'message' => "Deleted {$deletedCount} vectors successfully",
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to clear embeddings',
                context: ['error' => $e->getMessage()]
            );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'message' => 'Failed to clear embeddings: '.$e->getMessage(),
            ];
        }//end try
    }//end clearAllEmbeddings()

    // =============================================================================
    // CONFIGURATION HELPERS
    // =============================================================================

    /**
     * Get the configured vector search backend
     *
     * @return string Vector search backend ('php', 'database', or 'solr')
     */
    private function getVectorSearchBackend(): string
    {
        try {
            $llmSettings = $this->settingsService->getLLMSettingsOnly();
            return $llmSettings['vectorConfig']['backend'] ?? 'php';
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[VectorEmbeddings] Failed to get vector search backend, defaulting to PHP',
                context: ['error' => $e->getMessage()]
            );
            return 'php';
        }
    }//end getVectorSearchBackend()

    /**
     * Get embedding configuration from settings
     *
     * @param string|null $provider Override provider (null = use default from settings)
     *
     * @return array{provider: string, model: string, dimensions: int, api_key: string|null, base_url: string|null} Configuration
     */
    private function getEmbeddingConfig(?string $provider=null): array
    {
        $llmSettings = $this->settingsService->getLLMSettingsOnly();

        // Determine provider.
        $configuredProvider = $provider ?? ($llmSettings['embeddingProvider'] ?? 'openai');

        // Get provider-specific configuration.
        $providerConfig = match ($configuredProvider) {
            'fireworks' => $llmSettings['fireworksConfig'] ?? [],
            'ollama' => $llmSettings['ollamaConfig'] ?? [],
            'openai' => $llmSettings['openaiConfig'] ?? [],
            default => []
        };

        // Extract model and credentials.
        $model = match ($configuredProvider) {
            'fireworks' => $providerConfig['embeddingModel'] ?? 'thenlper/gte-base',
            'ollama' => $providerConfig['model'] ?? 'nomic-embed-text',
            'openai' => $providerConfig['model'] ?? 'text-embedding-ada-002',
            default => 'text-embedding-ada-002'
        };

        $apiKey  = $providerConfig['apiKey'] ?? null;
        $baseUrl = $providerConfig['baseUrl'] ?? $providerConfig['url'] ?? null;

        return [
            'provider'   => $configuredProvider,
            'model'      => $model,
            'dimensions' => $this->generatorHandler->getDefaultDimensions($model),
            'api_key'    => $apiKey,
            'base_url'   => $baseUrl,
        ];
    }//end getEmbeddingConfig()
}//end class
