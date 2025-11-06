<?php
/**
 * OpenRegister Object Vectorization Service
 *
 * Service for managing object vectorization operations.
 * Handles batch vectorization, automatic vectorization on CRUD, and progress tracking.
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
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * ObjectVectorizationService
 *
 * Service for managing object vectorization operations.
 * This service handles ALL object vectorization business logic.
 *
 * RESPONSIBILITIES:
 * - Batch vectorization of objects
 * - Automatic vectorization on object create/update
 * - Progress tracking for vectorization jobs
 * - Schema-based filtering for selective vectorization
 *
 * ARCHITECTURE:
 * - Uses VectorEmbeddingService for actual embedding generation
 * - Uses SettingsService to read vectorization configuration
 * - Uses ObjectService to retrieve objects for vectorization
 * - Independent from Settings/Chat services (separation of concerns)
 *
 * INTEGRATION POINTS:
 * - VectorEmbeddingService: For generating embeddings
 * - SettingsService: For reading configuration
 * - ObjectService: For retrieving objects
 * - Background Jobs: For async batch processing
 *
 * NOTE: This service handles all object vectorization business logic. 
 * Controllers should delegate to this service.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class ObjectVectorizationService
{
    /**
     * Vector embedding service
     *
     * @var VectorEmbeddingService
     */
    private VectorEmbeddingService $vectorService;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Object entity mapper
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $objectMapper;

    /**
     * View mapper
     *
     * @var ViewMapper
     */
    private ViewMapper $viewMapper;

    /**
     * Background job list
     *
     * @var IJobList
     */
    private IJobList $jobList;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param VectorEmbeddingService $vectorService    Vector embedding service
     * @param SettingsService        $settingsService  Settings service
     * @param ObjectEntityMapper     $objectMapper     Object entity mapper
     * @param ViewMapper             $viewMapper       View mapper
     * @param IJobList               $jobList          Background job list
     * @param LoggerInterface        $logger           Logger
     */
    public function __construct(
        VectorEmbeddingService $vectorService,
        SettingsService $settingsService,
        ObjectEntityMapper $objectMapper,
        ViewMapper $viewMapper,
        IJobList $jobList,
        LoggerInterface $logger
    ) {
        $this->vectorService = $vectorService;
        $this->settingsService = $settingsService;
        $this->objectMapper = $objectMapper;
        $this->viewMapper = $viewMapper;
        $this->jobList = $jobList;
        $this->logger = $logger;
    }//end __construct()


    /**
     * Start batch vectorization of objects
     *
     * @param array|null $schemas   Optional array of schema IDs to vectorize
     * @param int        $batchSize Number of objects to process per batch
     *
     * @return array Status information
     *
     * @throws \Exception If vectorization cannot be started
     */
    public function startBatchVectorization(?array $schemas = null, int $batchSize = 25): array
    {
        $this->logger->info('[ObjectVectorizationService] Starting batch vectorization', [
            'schemas' => $schemas,
            'batchSize' => $batchSize,
        ]);

        try {
            // Get vectorization configuration
            $config = $this->settingsService->getObjectSettingsOnly();

            // Check if vectorization is enabled
            if (!($config['vectorizationEnabled'] ?? false)) {
                throw new \Exception('Object vectorization is not enabled. Please enable it in settings first.');
            }

            // Determine which schemas to vectorize from views
            $targetViews = $schemas ?? ($config['vectorizeAllViews'] ? null : $config['enabledViews'] ?? []);
            $targetSchemas = $this->resolveViewsToSchemas($targetViews);

            // Get count of objects to vectorize
            $totalObjects = $this->getObjectCount($targetSchemas);

            if ($totalObjects === 0) {
                return [
                    'success' => true,
                    'message' => 'No objects found to vectorize',
                    'total_objects' => 0,
                    'vectorized' => 0,
                    'failed' => 0,
                    'started' => false,
                ];
            }

            // Fetch objects to vectorize
            $objects = $this->fetchObjectsToVectorize($targetSchemas, $batchSize);

            $this->logger->info('[ObjectVectorizationService] Starting synchronous batch vectorization', [
                'totalObjects' => $totalObjects,
                'batchSize' => count($objects),
                'schemas' => $targetSchemas,
            ]);

            // Vectorize objects synchronously
            $vectorized = 0;
            $failed = 0;
            $errors = [];

            foreach ($objects as $object) {
                try {
                    $this->vectorizeObject($object->getId());
                    $vectorized++;
                    
                    $this->logger->debug('[ObjectVectorizationService] Object vectorized', [
                        'objectId' => $object->getId(),
                        'progress' => "{$vectorized}/{$batchSize}",
                    ]);
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = [
                        'object_id' => $object->getId(),
                        'error' => $e->getMessage(),
                    ];
                    
                    $this->logger->error('[ObjectVectorizationService] Failed to vectorize object', [
                        'objectId' => $object->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('[ObjectVectorizationService] Batch vectorization completed', [
                'totalObjects' => $totalObjects,
                'processed' => count($objects),
                'vectorized' => $vectorized,
                'failed' => $failed,
            ]);

            return [
                'success' => true,
                'message' => "Batch vectorization completed: {$vectorized} vectorized, {$failed} failed",
                'total_objects' => $totalObjects,
                'processed' => count($objects),
                'vectorized' => $vectorized,
                'failed' => $failed,
                'batch_size' => $batchSize,
                'schemas' => $targetSchemas,
                'errors' => $errors,
                'started' => true,
            ];

        } catch (\Exception $e) {
            $this->logger->error('[ObjectVectorizationService] Failed to start batch vectorization', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }//end try

    }//end startBatchVectorization()


    /**
     * Fetch objects to vectorize
     *
     * @param array|null $schemas Schema IDs to fetch, null for all
     * @param int        $limit   Maximum number of objects to fetch
     *
     * @return array Array of ObjectEntity instances
     */
    private function fetchObjectsToVectorize(?array $schemas, int $limit): array
    {
        try {
            $this->logger->debug('[ObjectVectorizationService] Fetching objects to vectorize', [
                'schemas' => $schemas,
                'limit' => $limit,
            ]);

            // Fetch all objects if no schema filter
            if ($schemas === null || empty($schemas)) {
                $objects = $this->objectMapper->findAll(
                    limit: $limit,
                    filters: [],
                    includeDeleted: false
                );
                
                $this->logger->info('[ObjectVectorizationService] Fetched all objects', [
                    'count' => count($objects),
                ]);
                
                return $objects;
            }

            // Fetch objects for specific schemas
            $allObjects = [];
            $remainingLimit = $limit;
            
            foreach ($schemas as $schemaId) {
                if ($remainingLimit <= 0) {
                    break;
                }
                
                try {
                    // Fetch objects for this schema using filters
                    // Note: filters expect simple values (int, string), not entities
                    $objects = $this->objectMapper->findAll(
                        limit: $remainingLimit,
                        filters: ['schema' => $schemaId],
                        includeDeleted: false
                    );
                    
                    $allObjects = array_merge($allObjects, $objects);
                    $remainingLimit -= count($objects);
                    
                    $this->logger->debug('[ObjectVectorizationService] Fetched objects for schema', [
                        'schema_id' => $schemaId,
                        'count' => count($objects),
                    ]);
                } catch (\Exception $e) {
                    $this->logger->warning('[ObjectVectorizationService] Failed to fetch objects for schema', [
                        'schema_id' => $schemaId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('[ObjectVectorizationService] Fetched objects', [
                'count' => count($allObjects),
                'schemas' => $schemas,
            ]);

            return $allObjects;

        } catch (\Exception $e) {
            $this->logger->error('[ObjectVectorizationService] Failed to fetch objects', [
                'error' => $e->getMessage(),
            ]);
            
            return [];
        }//end try

    }//end fetchObjectsToVectorize()


    /**
     * Get count of objects for vectorization
     *
     * @param array|null $schemas Schema IDs to count, null for all
     *
     * @return int Object count
     */
    private function getObjectCount(?array $schemas): int
    {
        try {
            $this->logger->debug('[ObjectVectorizationService] Counting objects', [
                'schemas' => $schemas,
            ]);

            // Count all objects if no schema filter
            if ($schemas === null || empty($schemas)) {
                $count = $this->objectMapper->countAll();
                $this->logger->info('[ObjectVectorizationService] Counted all objects', [
                    'count' => $count,
                ]);
                return $count;
            }

            // Count objects for specific schemas
            $totalCount = 0;
            foreach ($schemas as $schemaId) {
                try {
                    // Count objects for this schema using filters
                    // Note: filters expect simple values (int, string), not entities
                    $count = $this->objectMapper->countAll(
                        filters: ['schema' => $schemaId]
                    );
                    
                    $totalCount += $count;
                    
                    $this->logger->debug('[ObjectVectorizationService] Counted objects for schema', [
                        'schema_id' => $schemaId,
                        'count' => $count,
                    ]);
                } catch (\Exception $e) {
                    $this->logger->warning('[ObjectVectorizationService] Failed to count objects for schema', [
                        'schema_id' => $schemaId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('[ObjectVectorizationService] Object count retrieved', [
                'count' => $totalCount,
                'schemas' => $schemas,
            ]);

            return $totalCount;

        } catch (\Exception $e) {
            $this->logger->error('[ObjectVectorizationService] Failed to count objects', [
                'error' => $e->getMessage(),
            ]);
            
            return 0;
        }//end try

    }//end getObjectCount()


    /**
     * Resolve view IDs to schema IDs
     *
     * Extracts schema IDs from view configurations. Views can contain
     * multiple schemas in their query configuration.
     *
     * @param array|null $viewIds Array of view IDs, or null for all
     *
     * @return array|null Array of schema IDs, or null for all schemas
     */
    private function resolveViewsToSchemas(?array $viewIds): ?array
    {
        try {
            // If null, vectorize all objects (no view filter)
            if ($viewIds === null) {
                $this->logger->info('[ObjectVectorizationService] Vectorizing all views (no filter)');
                return null;
            }

            if (empty($viewIds)) {
                $this->logger->info('[ObjectVectorizationService] No views enabled for vectorization');
                return [];
            }

            $this->logger->debug('[ObjectVectorizationService] Resolving views to schemas', [
                'views' => $viewIds,
            ]);

            $allSchemas = [];
            
            foreach ($viewIds as $viewId) {
                try {
                    // Get View entity
                    $view = $this->viewMapper->find($viewId);
                    
                    // Extract schemas from view query
                    $query = $view->getQuery() ?? [];
                    $schemas = $query['schemas'] ?? [];
                    
                    if (!empty($schemas)) {
                        $allSchemas = array_merge($allSchemas, $schemas);
                        
                        $this->logger->debug('[ObjectVectorizationService] Extracted schemas from view', [
                            'view_id' => $viewId,
                            'view_name' => $view->getName(),
                            'schemas' => $schemas,
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('[ObjectVectorizationService] Failed to resolve view', [
                        'view_id' => $viewId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Return unique schema IDs
            $uniqueSchemas = array_values(array_unique($allSchemas));
            
            $this->logger->info('[ObjectVectorizationService] Resolved views to schemas', [
                'views' => $viewIds,
                'schemas' => $uniqueSchemas,
            ]);

            return $uniqueSchemas;

        } catch (\Exception $e) {
            $this->logger->error('[ObjectVectorizationService] Failed to resolve views', [
                'error' => $e->getMessage(),
            ]);
            
            return [];
        }//end try

    }//end resolveViewsToSchemas()


    /**
     * Vectorize a single object
     *
     * @param int $objectId Object ID
     *
     * @return array Vectorization result
     *
     * @throws \Exception If vectorization fails
     */
    public function vectorizeObject(int $objectId): array
    {
        $this->logger->info('[ObjectVectorizationService] Vectorizing object', [
            'objectId' => $objectId,
        ]);

        try {
            // Get vectorization configuration
            $config = $this->settingsService->getObjectSettingsOnly();

            // Check if vectorization is enabled
            if (!($config['vectorizationEnabled'] ?? false)) {
                throw new \Exception('Object vectorization is not enabled');
            }

            // Get object entity
            $objectEntity = $this->objectMapper->find($objectId);

            if ($objectEntity === null) {
                throw new \Exception("Object not found: {$objectId}");
            }

            // Get object data as array
            $objectData = $objectEntity->jsonSerialize();

            // Serialize object for vectorization
            $text = $this->serializeObject($objectData, $config);

            // Generate embedding (returns array with 'embedding', 'model', 'dimensions')
            $embeddingResult = $this->vectorService->generateEmbedding($text, $config['provider'] ?? null);

            // Store vector in database
            $vectorId = $this->vectorService->storeVector(
                entityType: 'object',
                entityId: (string) $objectId,
                embedding: $embeddingResult['embedding'],
                model: $embeddingResult['model'],
                dimensions: $embeddingResult['dimensions'],
                chunkIndex: 0,
                totalChunks: 1,
                chunkText: substr($text, 0, 1000), // Store first 1000 chars for reference
                metadata: [
                    'schema_id' => $objectData['schema'] ?? null,
                    'uuid' => $objectData['uuid'] ?? null,
                ]
            );

            $this->logger->info('[ObjectVectorizationService] Object vectorized successfully', [
                'objectId' => $objectId,
                'vectorId' => $vectorId,
                'dimensions' => $embeddingResult['dimensions'],
                'model' => $embeddingResult['model'],
            ]);

            return [
                'success' => true,
                'object_id' => $objectId,
                'vector_id' => $vectorId,
                'dimensions' => $embeddingResult['dimensions'],
                'model' => $embeddingResult['model'],
            ];

        } catch (\Exception $e) {
            $this->logger->error('[ObjectVectorizationService] Failed to vectorize object', [
                'objectId' => $objectId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }//end try

    }//end vectorizeObject()


    /**
     * Serialize object to text for vectorization
     *
     * @param array $object Object data
     * @param array $config Vectorization configuration
     *
     * @return string Serialized text
     */
    private function serializeObject(array $object, array $config): string
    {
        // TODO: Implement proper object serialization based on config
        // For now, just JSON encode
        $includeMetadata = $config['includeMetadata'] ?? true;
        $includeRelations = $config['includeRelations'] ?? true;
        $maxNestingDepth = $config['maxNestingDepth'] ?? 10;

        $this->logger->debug('[ObjectVectorizationService] Serializing object', [
            'includeMetadata' => $includeMetadata,
            'includeRelations' => $includeRelations,
            'maxNestingDepth' => $maxNestingDepth,
        ]);

        return json_encode($object, JSON_PRETTY_PRINT);

    }//end serializeObject()


}//end class

