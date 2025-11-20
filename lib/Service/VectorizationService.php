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

use OCA\OpenRegister\Service\VectorEmbeddingService;
use OCA\OpenRegister\Service\Vectorization\VectorizationStrategyInterface;
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
     * Vector embedding service
     *
     * @var VectorEmbeddingService
     */
    private VectorEmbeddingService $vectorService;

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
     * @param VectorEmbeddingService $vectorService Vector embedding service
     * @param LoggerInterface        $logger        Logger
     */
    public function __construct(
        VectorEmbeddingService $vectorService,
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
     * @return array Vectorization results
     *
     * @throws \Exception If strategy not found or vectorization fails
     */
    public function vectorizeBatch(string $entityType, array $options=[]): array
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
                    $result = $this->vectorizeEntity($entity, $strategy, $options);

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
     * @return array Processing results
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

                        if ($embeddingData !== null && isset($embeddingData['embedding']) === true && $embeddingData['embedding'] !== null) {
                            $this->storeVector($entity, $item, $embeddingData, $strategy);
                            $vectorized++;
                        } else {
                            $failed++;
                            $errors[] = [
                                'entity_id'  => $entityId,
                                'item_index' => $index,
                                'error'      => $embeddingData['error'] ?? 'Embedding generation failed',
                            ];
                        }
                    }
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
                    $this->storeVector($entity, $item, $embeddingData, $strategy);
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
        $metadata = $strategy->prepareVectorMetadata($entity, $item);

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
            throw new \Exception("No vectorization strategy registered for entity type: {$entityType}");
        }

        return $this->strategies[$entityType];

    }//end getStrategy()


}//end class
