<?php

/**
 * OpenRegister Unified Vectorization Service
 *
 * Generic service for vectorizing any entity type (objects, files, etc).
 * Uses strategy pattern for entity-specific logic.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\Vectorization\Strategies\VectorizationStrategyInterface;
use Psr\Log\LoggerInterface;

/**
 * VectorizationService
 *
 * Unified service for vectorizing entities using pluggable strategies.
 *
 * ARCHITECTURE:
 * - Generic vectorization logic (batch processing, error handling, progress)
 * - Entity-specific logic delegated to Strategy implementations
 * - Strategies handle: fetching entities, extracting text, preparing metadata
 *
 * BENEFITS:
 * - Single service to maintain
 * - Easy to add new entity types (just add a strategy)
 * - Consistent vectorization API across all entities
 * - Reduced code duplication
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class VectorizationService
{
    /**
     * Vector embeddings coordinator
     *
     * @var VectorEmbeddings
     */
    private VectorEmbeddings $vectorService;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Registered strategies by entity type
     *
     * @var array<string, VectorizationStrategyInterface>
     */
    private array $strategies = [];

    /**
     * Constructor
     *
     * @param VectorEmbeddings $vectorService Vector embeddings coordinator
     * @param LoggerInterface  $logger        Logger
     */
    public function __construct(
        VectorEmbeddings $vectorService,
        LoggerInterface $logger
    ) {
        $this->vectorService = $vectorService;
        $this->logger        = $logger;
    }//end __construct()

    /**
     * Register a vectorization strategy for an entity type
     *
     * @param string                         $entityType Entity type identifier
     * @param VectorizationStrategyInterface $strategy   Strategy implementation
     *
     * @return void
     */
    public function registerStrategy(string $entityType, VectorizationStrategyInterface $strategy): void
    {
        $this->strategies[$entityType] = $strategy;
        $this->logger->debug(
            '[VectorizationService] Strategy registered',
            [
                    'entityType'    => $entityType,
                    'strategyClass' => get_class($strategy),
                ]
        );
    }//end registerStrategy()

    /**
     * Vectorize entities in batch
     *
     * Generic batch vectorization that works for any entity type.
     * Delegates entity-specific logic to registered strategy.
     *
     * @param string $entityType Entity type ('object', 'file', etc)
     * @param array  $options    Strategy-specific options
     *
     * @return ((int|string)[][]|int|string|true)[]
     *
     * @throws \Exception If strategy not found or vectorization fails
     *
     * @psalm-return array{success: true, message: string, entity_type: string, total_entities: int<0, max>, total_items: int<0, max>, vectorized: int<0, max>, failed: int<0, max>, errors?: list<array{entity_id: int|string, error: string, item_index?: array-key}>}
     */
    public function vectorizeBatch(string $entityType, array $options = []): array
    {
        $this->logger->info(
            '[VectorizationService] Starting batch vectorization',
            [
                    'entityType' => $entityType,
                    'options'    => $options,
                ]
        );

        // Get strategy for entity type.
        $strategy = $this->getStrategy($entityType);

        try {
            // Strategy fetches entities to process.
            $entities = $strategy->fetchEntities($options);

            if ($entities === []) {
                return [
                    'success'        => true,
                    'message'        => 'No entities found to vectorize',
                    'entity_type'    => $entityType,
                    'total_entities' => 0,
                    'total_items'    => 0,
                    'vectorized'     => 0,
                    'failed'         => 0,
                ];
            }

            $this->logger->info(
                '[VectorizationService] Processing entities',
                [
                        'entityType'  => $entityType,
                        'entityCount' => count($entities),
                    ]
            );

            // Process each entity.
            $totalItems = 0;
            $vectorized = 0;
            $failed     = 0;
            $errors     = [];

            foreach ($entities as $entity) {
                try {
                    $result = $this->vectorizeEntity(entity: $entity, strategy: $strategy, options: $options);

                    $totalItems += $result['total_items'];
                    $vectorized += $result['vectorized'];
                    $failed     += $result['failed'];

                    if (empty($result['errors']) === false) {
                        $errors = array_merge($errors, $result['errors']);
                    }
                } catch (\Exception $e) {
                    $entityId = $strategy->getEntityIdentifier($entity);
                    $this->logger->error(
                        '[VectorizationService] Failed to vectorize entity',
                        [
                                'entityType' => $entityType,
                                'entityId'   => $entityId,
                                'error'      => $e->getMessage(),
                            ]
                    );
                    $errors[] = [
                        'entity_id' => $entityId,
                        'error'     => $e->getMessage(),
                    ];
                }//end try
            }//end foreach

            $this->logger->info(
                '[VectorizationService] Batch vectorization completed',
                [
                        'entityType'    => $entityType,
                        'totalEntities' => count($entities),
                        'totalItems'    => $totalItems,
                        'vectorized'    => $vectorized,
                        'failed'        => $failed,
                    ]
            );

            return [
                'success'        => true,
                'message'        => "Batch vectorization completed: {$vectorized} vectorized, {$failed} failed",
                'entity_type'    => $entityType,
                'total_entities' => count($entities),
                'total_items'    => $totalItems,
                'vectorized'     => $vectorized,
                'failed'         => $failed,
                'errors'         => $errors,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                '[VectorizationService] Batch vectorization failed',
                [
                        'entityType' => $entityType,
                        'error'      => $e->getMessage(),
                    ]
            );
            throw $e;
        }//end try
    }//end vectorizeBatch()

    /**
     * Vectorize a single entity
     *
     * An entity may produce multiple vectors (e.g., file with multiple chunks).
     *
     * @param mixed                          $entity   Entity to vectorize
     * @param VectorizationStrategyInterface $strategy Strategy to use
     * @param array                          $options  Processing options
     *
     * @return ((int|string)[][]|int)[] Processing results
     *
     * @psalm-return array{
     *     total_items: int<0, max>,
     *     vectorized: int<0, max>,
     *     failed: int<0, max>,
     *     errors: list<array{
     *         entity_id: int|string,
     *         error: string,
     *         item_index: array-key
     *     }>
     * }
     */
    private function vectorizeEntity($entity, VectorizationStrategyInterface $strategy, array $options): array
    {
        $entityId = $strategy->getEntityIdentifier($entity);

        // Strategy extracts vectorization items from entity.
        // For objects: usually 1 item (serialized object).
        // For files: N items (one per chunk).
        $items = $strategy->extractVectorizationItems($entity);

        if ($items === []) {
            return [
                'total_items' => 0,
                'vectorized'  => 0,
                'failed'      => 0,
                'errors'      => [],
            ];
        }

        $vectorized = 0;
        $failed     = 0;
        $errors     = [];

        $mode      = $options['mode'] ?? 'serial';
        $batchSize = $options['batch_size'] ?? 50;

        // Batch processing for efficiency.
        if ($mode === 'parallel' && $batchSize > 1 && count($items) > 1) {
            $itemBatches = array_chunk($items, $batchSize);

            foreach ($itemBatches as $batch) {
                try {
                    $texts      = array_map(fn($item) => $item['text'], $batch);
                    $embeddings = $this->vectorService->generateBatchEmbeddings($texts);

                    foreach ($batch as $index => $item) {
                        $embeddingData = $embeddings[$index] ?? null;

                        if ($embeddingData !== null && (($embeddingData['embedding'] ?? null) !== null) && $embeddingData['embedding'] !== null) {
                            $this->storeVector(entity: $entity, item: $item, embeddingData: $embeddingData, strategy: $strategy);
                            $vectorized++;
                        } else {
                            $failed++;
                            //
                            // EmbeddingData may contain 'error' key even if not in type definition.
                            if (is_array($embeddingData) === true && array_key_exists('error', $embeddingData) === true) {
                                $errorMsg = $embeddingData['error'];
                            } else {
                                $errorMsg = 'Embedding generation failed';
                            }

                            $errors[] = [
                                'entity_id'  => $entityId,
                                'item_index' => $index,
                                'error'      => $errorMsg,
                            ];
                        }
                    }//end foreach
                } catch (\Exception $e) {
                    $failed += count($batch);
                    $this->logger->error(
                        '[VectorizationService] Batch processing failed',
                        [
                                'entityId' => $entityId,
                                'error'    => $e->getMessage(),
                            ]
                    );
                }//end try
            }//end foreach
        } else {
            // Serial processing.
            foreach ($items as $index => $item) {
                try {
                    $embeddingData = $this->vectorService->generateEmbedding($item['text']);
                    $this->storeVector(entity: $entity, item: $item, embeddingData: $embeddingData, strategy: $strategy);
                    $vectorized++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = [
                        'entity_id'  => $entityId,
                        'item_index' => $index,
                        'error'      => $e->getMessage(),
                    ];
                }
            }
        }//end if

        return [
            'total_items' => count($items),
            'vectorized'  => $vectorized,
            'failed'      => $failed,
            'errors'      => $errors,
        ];
    }//end vectorizeEntity()

    /**
     * Store a vector using strategy-provided metadata
     *
     * @param mixed                          $entity        Original entity
     * @param array                          $item          Vectorization item with text
     * @param array                          $embeddingData Embedding result
     * @param VectorizationStrategyInterface $strategy      Strategy
     *
     * @return void
     */
    private function storeVector($entity, array $item, array $embeddingData, VectorizationStrategyInterface $strategy): void
    {
        $metadata = $strategy->prepareVectorMetadata(entity: $entity, item: $item);

        $this->vectorService->storeVector(
            entityType: $metadata['entity_type'],
            entityId: $metadata['entity_id'],
            embedding: $embeddingData['embedding'],
            model: $embeddingData['model'],
            dimensions: $embeddingData['dimensions'],
            chunkIndex: $metadata['chunk_index'] ?? 0,
            totalChunks: $metadata['total_chunks'] ?? 1,
            chunkText: $metadata['chunk_text'] ?? null,
            metadata: $metadata['additional_metadata'] ?? []
        );
    }//end storeVector()

    /**
     * Get strategy for entity type
     *
     * @param string $entityType Entity type identifier
     *
     * @return VectorizationStrategyInterface
     *
     * @throws \Exception If strategy not registered
     */
    private function getStrategy(string $entityType): VectorizationStrategyInterface
    {
        if (isset($this->strategies[$entityType]) === false) {
            throw new Exception("No vectorization strategy registered for entity type: {$entityType}");
        }

        return $this->strategies[$entityType];
    }//end getStrategy()

    // =============================================================================
    // PUBLIC API FACADE METHODS - Delegate to VectorEmbeddingService
    // =============================================================================
    // These methods provide a single entry point for all vector operations.
    // Other services should call VectorizationService instead of
    // VectorEmbeddingService directly.
    // =============================================================================

    /**
     * Generate embedding for a single text
     *
     * Delegates to VectorEmbeddings.
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
    public function generateEmbedding(string $text, ?string $provider = null): array
    {
        return $this->vectorService->generateEmbedding(text: $text, provider: $provider);
    }//end generateEmbedding()

    /**
     * Perform semantic similarity search
     *
     * Delegates to VectorEmbeddings.
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
        int $limit = 10,
        array $filters = [],
        ?string $provider = null
    ): array {
        return $this->vectorService->semanticSearch(query: $query, limit: $limit, filters: $filters, provider: $provider);
    }//end semanticSearch()

    /**
     * Perform hybrid search combining keyword (SOLR) and semantic (vectors)
     *
     * Delegates to VectorEmbeddings.
     *
     * @param string      $query       Query text
     * @param array       $solrFilters SOLR-specific filters
     * @param int         $limit       Maximum results
     * @param array       $weights     Weights for each search type ['solr' => 0.5, 'vector' => 0.5]
     * @param string|null $provider    Embedding provider
     *
     * @return array Hybrid search results
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
        return $this->vectorService->hybridSearch(query: $query, solrFilters: $solrFilters, limit: $limit, weights: $weights, provider: $provider);
    }//end hybridSearch()

    /**
     * Get vector statistics
     *
     * Delegates to VectorEmbeddings.
     *
     * @return array Statistics about stored vectors
     */
    public function getVectorStats(): array
    {
        return $this->vectorService->getVectorStats();
    }//end getVectorStats()

    /**
     * Test embedding generation with custom configuration
     *
     * Delegates to VectorEmbeddings.
     *
     * @param string $provider Provider name ('openai', 'fireworks', 'ollama')
     * @param array  $config   Provider-specific configuration
     * @param string $testText Optional test text to embed
     *
     * @return ((float[]|int|mixed|string)[]|bool|string)[] Test results
     *
     * @psalm-return array{success: bool, error?: string, message: string, data?: array{provider: string, model: 'unknown'|mixed, vectorLength: int<0, max>, sampleValues: array<float>, testText: string}}
     */
    public function testEmbedding(string $provider, array $config, string $testText = 'Test.'): array
    {
        return $this->vectorService->testEmbedding(provider: $provider, config: $config, testText: $testText);
    }//end testEmbedding()

    /**
     * Check if embedding model has changed since vectors were created
     *
     * Delegates to VectorEmbeddings.
     *
     * @return (array|bool|int|mixed|string)[] Model mismatch information
     *
     * @psalm-return array{has_vectors: bool, mismatch: bool, error?: string, message?: string, current_model?: mixed, existing_models?: list{0?: mixed,...}, total_vectors?: int, null_model_count?: int, mismatched_models?: list<mixed>}
     */
    public function checkEmbeddingModelMismatch(): array
    {
        return $this->vectorService->checkEmbeddingModelMismatch();
    }//end checkEmbeddingModelMismatch()

    /**
     * Clear all embeddings from the database
     *
     * Delegates to VectorEmbeddings.
     *
     * @return (bool|int|string)[] Deletion results
     *
     * @psalm-return array{success: bool, error?: string, message: string, deleted?: int}
     */
    public function clearAllEmbeddings(): array
    {
        return $this->vectorService->clearAllEmbeddings();
    }//end clearAllEmbeddings()
}//end class
