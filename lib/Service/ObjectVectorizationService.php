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
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
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
     * Object service for searching objects with view support
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * Object entity mapper for single object operations
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $objectMapper;

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
     * @param ObjectService          $objectService    Object service with view support
     * @param ObjectEntityMapper     $objectMapper     Object entity mapper for single object ops
     * @param IJobList               $jobList          Background job list
     * @param LoggerInterface        $logger           Logger
     */
    public function __construct(
        VectorEmbeddingService $vectorService,
        SettingsService $settingsService,
        ObjectService $objectService,
        ObjectEntityMapper $objectMapper,
        IJobList $jobList,
        LoggerInterface $logger
    ) {
        $this->vectorService = $vectorService;
        $this->settingsService = $settingsService;
        $this->objectService = $objectService;
        $this->objectMapper = $objectMapper;
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
    public function startBatchVectorization(?array $views = null, int $batchSize = 25): array
    {
        $this->logger->info('[ObjectVectorizationService] Starting batch vectorization', [
            'views' => $views,
            'batchSize' => $batchSize,
        ]);

        try {
            // Get vectorization configuration
            $config = $this->settingsService->getObjectSettingsOnly();

            // Check if vectorization is enabled
            if (!($config['vectorizationEnabled'] ?? false)) {
                throw new \Exception('Object vectorization is not enabled. Please enable it in settings first.');
            }

            // Determine which views to vectorize (from parameter or config)
            $targetViews = $views ?? ($config['vectorizeAllViews'] ? null : $config['enabledViews'] ?? []);

            // Get count of objects to vectorize
            $totalObjects = $this->getObjectCount($targetViews);

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
            $objects = $this->fetchObjectsToVectorize($targetViews, $batchSize);

            $this->logger->info('[ObjectVectorizationService] Starting synchronous batch vectorization', [
                'totalObjects' => $totalObjects,
                'batchSize' => count($objects),
                'views' => $targetViews,
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
                'views' => $targetViews,
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
     * Fetch objects to vectorize using ObjectService with native view support
     *
     * Uses ObjectService->searchObjects() which handles view-to-query conversion internally.
     * Views are applied as filters, making this consistent with all other object operations.
     *
     * @param array|null $views View IDs to fetch, null for all
     * @param int        $limit Maximum number of objects to fetch
     *
     * @return array Array of ObjectEntity instances
     */
    private function fetchObjectsToVectorize(?array $views, int $limit): array
    {
        try {
            $this->logger->debug('[ObjectVectorizationService] Fetching objects to vectorize', [
                'views' => $views,
                'limit' => $limit,
            ]);

            // Build query with limit
            $query = [
                '_limit' => $limit,
                '_source' => 'database',  // Always use database for vectorization
            ];

            // Use ObjectService with native view support
            // This applies view filters automatically via applyViewsToQuery()
            $objects = $this->objectService->searchObjects(
                query: $query,
                rbac: false,    // No RBAC for background vectorization
                multi: false,   // No multi-tenancy filtering
                ids: null,
                uses: null,
                views: $views   // ✅ Views applied as filters!
            );

            $this->logger->info('[ObjectVectorizationService] Fetched objects using ObjectService', [
                'count' => count($objects),
                'views' => $views,
            ]);

            return $objects;

        } catch (\Exception $e) {
            $this->logger->error('[ObjectVectorizationService] Failed to fetch objects', [
                'error' => $e->getMessage(),
            ]);
            
            return [];
        }//end try

    }//end fetchObjectsToVectorize()


    /**
     * Get count of objects for vectorization using ObjectService with native view support
     *
     * @param array|null $views View IDs to count, null for all
     *
     * @return int Object count
     */
    private function getObjectCount(?array $views): int
    {
        try {
            $this->logger->debug('[ObjectVectorizationService] Counting objects', [
                'views' => $views,
            ]);

            // Build query for count
            $query = [
                '_count' => true,  // Request count instead of results
                '_source' => 'database',
            ];

            // Use ObjectService with native view support
            $count = $this->objectService->searchObjects(
                query: $query,
                rbac: false,
                multi: false,
                ids: null,
                uses: null,
                views: $views  // ✅ Views applied as filters!
            );

            $this->logger->info('[ObjectVectorizationService] Object count retrieved using ObjectService', [
                'count' => $count,
                'views' => $views,
            ]);

            return $count;

        } catch (\Exception $e) {
            $this->logger->error('[ObjectVectorizationService] Failed to count objects', [
                'error' => $e->getMessage(),
            ]);
            
            return 0;
        }//end try

    }//end getObjectCount()


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

            // Always use LLM embedding provider (configured in LLM settings)
            // Object vectorization doesn't have its own provider - it uses the global LLM config
            $llmSettings = $this->settingsService->getLLMSettingsOnly();
            $provider = $llmSettings['embeddingProvider'] ?? null;
            
            if ($provider === null) {
                throw new \Exception('No embedding provider configured. Please configure LLM settings first.');
            }
            
            $this->logger->debug('[ObjectVectorizationService] Using LLM embedding provider', [
                'provider' => $provider,
            ]);

            // Generate embedding (returns array with 'embedding', 'model', 'dimensions')
            $embeddingResult = $this->vectorService->generateEmbedding($text, $provider);

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

