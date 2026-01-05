<?php

/**
 * Bulk Object Save Operations Handler
 *
 * High-performance bulk saving operations for multiple objects.
 * Implements performance optimizations including schema analysis caching,
 * memory optimization, single-pass processing, and batch database operations.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 * @since     2.0.0 Initial SaveObjects implementation with performance optimizations
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkValidationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\ChunkProcessingHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\PreparationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\TransformationHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Bulk Object Save Operations Handler
 *
 * High-performance bulk saving operations for multiple objects.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Bulk operations require many optimization methods
 * @SuppressWarnings(PHPMD.TooManyMethods)           Many methods required for bulk processing pipeline
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex bulk operation optimization logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Bulk operations require many handler dependencies
 */
class SaveObjects
{

    /**
     * Static schema cache to avoid repeated database lookups
     *
     * @var array<int|string, Schema>
     */
    private static array $schemaCache = [];

    /**
     * Static schema analysis cache for comprehensive schema data
     *
     * @var array<int|string, array>
     */
    private static array $schemaAnalysisCache = [];

    /**
     * Static register cache to avoid repeated database lookups
     *
     * @var array<int|string, Register>
     */
    private static array $registerCache = [];

    /**
     * Constructor for SaveObjects handler
     *
     * @param ObjectEntityMapper     $objectEntityMapper  Mapper for object entity database operations
     * @param SchemaMapper           $schemaMapper        Mapper for schema operations
     * @param RegisterMapper         $registerMapper      Mapper for register operations
     * @param SaveObject             $saveHandler         Handler for individual object operations
     * @param BulkValidationHandler  $bulkValidHandler    Handler for bulk validation operations
     * @param BulkRelationHandler    $bulkRelationHandler Handler for bulk relation operations
     * @param TransformationHandler  $transformHandler    Handler for data transformation
     * @param PreparationHandler     $preparationHandler  Handler for data preparation
     * @param ChunkProcessingHandler $chunkProcHandler    Handler for chunk processing
     * @param OrganisationService    $organisationService Organisation service for organisation operations
     * @param IUserSession           $userSession         User session for getting current user
     * @param LoggerInterface        $logger              Logger for error and debug logging
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SaveObject $saveHandler,
        private readonly BulkValidationHandler $bulkValidHandler,
        private readonly BulkRelationHandler $bulkRelationHandler,
        private readonly TransformationHandler $transformHandler,
        private readonly PreparationHandler $preparationHandler,
        private readonly ChunkProcessingHandler $chunkProcHandler,
        private readonly OrganisationService $organisationService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Load schema from cache or database with performance optimization
     *
     * @param int|string $schemaId Schema ID to load
     *
     * @return Schema The loaded schema
     * @throws \Exception If schema cannot be found
     */
    private function loadSchemaWithCache(int|string $schemaId): Schema
    {
        // Check static cache first.
        if ((self::$schemaCache[$schemaId] ?? null) !== null) {
            return self::$schemaCache[$schemaId];
        }

        // Load from database and cache.
        $schema = $this->schemaMapper->find($schemaId);
        self::$schemaCache[$schemaId] = $schema;

        return $schema;
    }//end loadSchemaWithCache()

    /**
     * Get comprehensive schema analysis from cache or generate new analysis
     *
     * @param Schema $schema Schema to analyze
     *
     * @return array Comprehensive schema analysis
     */
    private function getSchemaAnalysisWithCache(Schema $schema): array
    {
        $schemaId = $schema->getId();

        // Check static cache first.
        if ((self::$schemaAnalysisCache[$schemaId] ?? null) !== null) {
            return self::$schemaAnalysisCache[$schemaId];
        }

        // Generate analysis and cache.
        $analysis = $this->performComprehensiveSchemaAnalysis($schema);
        self::$schemaAnalysisCache[$schemaId] = $analysis;

        return $analysis;
    }//end getSchemaAnalysisWithCache()

    /**
     * Load register from cache or database with performance optimization
     *
     * @param int|string $registerId Register ID to load
     *
     * @return Register The loaded register
     * @throws \Exception If register cannot be found
     *
     * @psalm-suppress UnusedReturnValue
     */
    private function loadRegisterWithCache(int|string $registerId): Register
    {
        // Check static cache first.
        if ((self::$registerCache[$registerId] ?? null) !== null) {
            return self::$registerCache[$registerId];
        }

        // Load from database and cache.
        $register = $this->registerMapper->find($registerId);
        self::$registerCache[$registerId] = $register;

        return $register;
    }//end loadRegisterWithCache()

    /**
     * Save multiple objects with high-performance bulk operations
     *
     * BULK SAVE WORKFLOW:
     * 1. Comprehensive schema analysis and caching
     * 2. Memory-optimized object preparation with relation processing
     * 3. Optional validation with minimal copying
     * 4. In-place format transformation
     * 5. Batch database operations
     * 6. Optimized inverse relation processing
     * 7. Bulk writeBack operations
     *
     * @param array                    $objects       Array of objects in serialized format
     * @param Register|string|int|null $register      Optional register context
     * @param Schema|string|int|null   $schema        Optional schema context
     * @param bool                     $_rbac         Whether to apply RBAC filtering
     * @param bool                     $_multitenancy Whether to apply multi-organization filtering
     * @param bool                     $validation    Whether to validate objects against schema definitions
     * @param bool                     $events        Whether to dispatch object lifecycle events
     *
     * @throws \InvalidArgumentException If required fields are missing from any object
     * @throws \OCP\DB\Exception If a database error occurs during bulk operations
     *
     * @phpstan-param array<int, array<string, mixed>> $objects
     *
     * @psalm-param array<int, array<string, mixed>> $objects
     *
     * @return array Bulk save results with performance, statistics, and object categorizations.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flags control bulk save behavior options
     */
    public function saveObjects(
        array $objects,
        Register|string|int|null $register=null,
        Schema|string|int|null $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $validation=false,
        bool $events=false
    ): array {
        // Return early if no objects provided.
        if (empty($objects) === true) {
            return $this->createEmptyResult(totalObjects: 0);
        }

        $startTime     = microtime(true);
        $totalObjects  = count($objects);
        $isMixedSchema = ($schema === null);

        // Log large operations.
        $this->logBulkOperationStart(
            totalObjects: $totalObjects,
            isMixedSchema: $isMixedSchema
        );

        // Prepare objects for bulk save.
        [$processedObjects, $schemaCache, $invalidObjects] = $this->prepareObjectsForSave(
            objects: $objects,
            register: $register,
            schema: $schema,
            isMixedSchema: $isMixedSchema
        );

        // Initialize result with invalid objects from preparation.
        $result = $this->initializeResult(
            totalObjects: $totalObjects,
            invalidObjects: $invalidObjects
        );

        // Return if no valid objects to process.
        if (empty($processedObjects) === true) {
            $result['errors'][] = [
                'error' => 'No objects were successfully prepared for bulk save',
                'type'  => 'NoObjectsPreparedException',
            ];
            return $result;
        }

        // Update statistics for actually processed count.
        $result['statistics']['totalProcessed'] = count($processedObjects);

        // Process objects in optimized chunks.
        $result = $this->processObjectsInChunks(
            processedObjects: $processedObjects,
            schemaCache: $schemaCache,
            result: $result,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            validation: $validation,
            events: $events,
            register: $register,
            schema: $schema
        );

        // Add performance metrics.
        $result['performance'] = $this->calculatePerformanceMetrics(
            startTime: $startTime,
            processedCount: count($processedObjects),
            totalRequested: $totalObjects,
            unchangedCount: count($result['unchanged'])
        );

        return $result;
    }//end saveObjects()

    /**
     * Create empty result structure.
     *
     * @param int $totalObjects Total number of objects
     *
     * @return array Empty result structure with saved, updated, unchanged, invalid, errors, and statistics.
     */
    private function createEmptyResult(int $totalObjects): array
    {
        return [
            'saved'      => [],
            'updated'    => [],
            'unchanged'  => [],
            'invalid'    => [],
            'errors'     => [],
            'statistics' => [
                'totalProcessed'   => $totalObjects,
                'saved'            => 0,
                'updated'          => 0,
                'unchanged'        => 0,
                'invalid'          => 0,
                'errors'           => 0,
                'processingTimeMs' => 0,
            ],
        ];
    }//end createEmptyResult()

    /**
     * Log bulk operation start for large operations.
     *
     * @param int  $totalObjects  Total number of objects
     * @param bool $isMixedSchema Whether this is a mixed-schema operation
     *
     * @return void
     */
    private function logBulkOperationStart(int $totalObjects, bool $isMixedSchema): void
    {
        // Determine log threshold based on operation type.
        $logThreshold = 10000;
        if ($isMixedSchema === true) {
            $logThreshold = 1000;
        }

        if ($totalObjects <= $logThreshold) {
            return;
        }

        $operationType = 'single-schema';
        if ($isMixedSchema === true) {
            $operationType = 'mixed-schema';
        }

        $logMessage = "Starting {$operationType} bulk save operation";

        $this->logger->info(
            $logMessage,
            [
                'totalObjects' => $totalObjects,
                'operation'    => $operationType,
            ]
        );
    }//end logBulkOperationStart()

    /**
     * Prepare objects for bulk save operation.
     *
     * @param array                    $objects       Objects to save
     * @param Register|string|int|null $register      Register parameter
     * @param Schema|string|int|null   $schema        Schema parameter
     * @param bool                     $isMixedSchema Whether mixed-schema operation
     *
     * @return array [processedObjects, schemaCache, invalidObjects].
     */
    private function prepareObjectsForSave(
        array $objects,
        Register|string|int|null $register,
        Schema|string|int|null $schema,
        bool $isMixedSchema
    ): array {
        // Use fast path for single-schema operations.
        if ($isMixedSchema === false && $schema !== null) {
            return $this->prepareSingleSchemaObjectsOptimized(
                objects: $objects,
                register: $register,
                schema: $schema
            );
        }

        // Use standard path for mixed-schema operations.
        return $this->preparationHandler->prepareObjectsForBulkSave($objects);
    }//end prepareObjectsForSave()

    /**
     * Initialize result structure with invalid objects from preparation.
     *
     * @param int   $totalObjects   Total objects requested
     * @param array $invalidObjects Objects that failed preparation
     *
     * @return array Initialized result with invalid objects added and statistics updated.
     */
    private function initializeResult(int $totalObjects, array $invalidObjects): array
    {
        $result = $this->createEmptyResult(totalObjects: $totalObjects);

        // Add preparation failures to result.
        foreach ($invalidObjects as $invalidObj) {
            $result['invalid'][] = $invalidObj;
            $result['statistics']['invalid']++;
            $result['statistics']['errors']++;
        }

        return $result;
    }//end initializeResult()

    /**
     * Process objects in optimized chunks.
     *
     * @param array                    $processedObjects Prepared objects to process
     * @param array                    $schemaCache      Schema cache
     * @param array                    $result           Result array to update
     * @param bool                     $_rbac            Apply RBAC
     * @param bool                     $_multitenancy    Apply multitenancy
     * @param bool                     $validation       Enable validation
     * @param bool                     $events           Enable events
     * @param Register|string|int|null $register         Register to use
     * @param Schema|string|int|null   $schema           Schema to use
     *
     * @return array Updated result array
     */
    private function processObjectsInChunks(
        array $processedObjects,
        array $schemaCache,
        array $result,
        bool $_rbac,
        bool $_multitenancy,
        bool $validation,
        bool $events,
        Register|string|int|null $register=null,
        Schema|string|int|null $schema=null
    ): array {
        $chunkSize = $this->calculateOptimalChunkSize(count($processedObjects));
        $chunks    = array_chunk($processedObjects, $chunkSize);

        foreach ($chunks as $chunkIndex => $objectsChunk) {
            // Process chunk.
            $chunkResult = $this->chunkProcHandler->processObjectsChunk(
                objects: $objectsChunk,
                schemaCache: $schemaCache,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                _validation: $validation,
                _events: $events,
                register: $register,
                schema: $schema
            );

            // Merge chunk results.
            $result = $this->mergeChunkResult(
                result: $result,
                chunkResult: $chunkResult,
                chunkIndex: $chunkIndex,
                chunkCount: count($objectsChunk)
            );
        }//end foreach

        return $result;
    }//end processObjectsInChunks()

    /**
     * Merge chunk result into main result.
     *
     * @param array $result      Main result array
     * @param array $chunkResult Chunk processing result
     * @param int   $chunkIndex  Chunk index
     * @param int   $chunkCount  Number of objects in chunk
     *
     * @return array Updated result with merged chunk data and statistics.
     */
    private function mergeChunkResult(
        array $result,
        array $chunkResult,
        int $chunkIndex,
        int $chunkCount
    ): array {
        // Merge arrays.
        $result['saved']   = array_merge($result['saved'], $chunkResult['saved']);
        $result['updated'] = array_merge($result['updated'], $chunkResult['updated']);
        $result['invalid'] = array_merge($result['invalid'], $chunkResult['invalid']);
        $result['errors']  = array_merge($result['errors'], $chunkResult['errors']);

        // Update statistics.
        $result['statistics']['saved']   += $chunkResult['statistics']['saved'] ?? 0;
        $result['statistics']['updated'] += $chunkResult['statistics']['updated'] ?? 0;
        $result['statistics']['invalid'] += $chunkResult['statistics']['invalid'] ?? 0;
        $result['statistics']['errors']  += $chunkResult['statistics']['errors'] ?? 0;

        // Store per-chunk statistics.
        if (isset($result['chunkStatistics']) === false) {
            $result['chunkStatistics'] = [];
        }

        $result['chunkStatistics'][] = [
            'chunkIndex' => $chunkIndex,
            'count'      => $chunkCount,
            'saved'      => $chunkResult['statistics']['saved'] ?? 0,
            'updated'    => $chunkResult['statistics']['updated'] ?? 0,
            'invalid'    => $chunkResult['statistics']['invalid'] ?? 0,
        ];

        return $result;
    }//end mergeChunkResult()

    /**
     * Calculate performance metrics for bulk operation.
     *
     * @param float $startTime      Operation start time
     * @param int   $processedCount Number of processed objects
     * @param int   $totalRequested Total objects requested
     * @param int   $unchangedCount Number of unchanged objects
     *
     * @return array Performance metrics with time, speed, and efficiency stats.
     */
    private function calculatePerformanceMetrics(
        float $startTime,
        int $processedCount,
        int $totalRequested,
        int $unchangedCount
    ): array {
        $totalTime    = microtime(true) - $startTime;
        $overallSpeed = $processedCount / max($totalTime, 0.001);

        // Calculate efficiency percentage.
        $efficiency = 0;
        if ($processedCount > 0) {
            $efficiency = round(($processedCount / $totalRequested) * 100, 1);
        }

        $performance = [
            'totalTime'        => round($totalTime, 3),
            'totalTimeMs'      => round($totalTime * 1000, 2),
            'objectsPerSecond' => round($overallSpeed, 2),
            'totalProcessed'   => $processedCount,
            'totalRequested'   => $totalRequested,
            'efficiency'       => $efficiency,
        ];

        // Add deduplication efficiency if applicable.
        if ($unchangedCount > 0) {
            $totalWithUnchanged = $processedCount + $unchangedCount;
            $deduplicationPct   = round(($unchangedCount / $totalWithUnchanged) * 100, 1);
            $performance['deduplicationEfficiency'] = "{$deduplicationPct}% operations avoided";
        }

        return $performance;
    }//end calculatePerformanceMetrics()

    /**
     * Calculate optimal chunk size based on total objects for internal processing
     *
     * @param int $totalObjects Total number of objects to process
     *
     * @return int Optimal chunk size
     *
     * @psalm-return int<min, 10000>
     */
    private function calculateOptimalChunkSize(int $totalObjects): int
    {
        // ULTRA-PERFORMANCE: Aggressive chunk sizes for sub-1-second imports.
        // Optimized for 33k+ object datasets.
        if ($totalObjects <= 100) {
            // Process all at once for small sets.
            return $totalObjects;
        } else if ($totalObjects <= 1000) {
            // Process all at once for medium sets.
            return $totalObjects;
        } else if ($totalObjects <= 5000) {
            // Large chunks for large sets.
            return 2000;
        } else if ($totalObjects <= 10000) {
            // Very large chunks.
            return 3000;
        }

        if ($totalObjects <= 50000) {
            // Ultra-large chunks for massive datasets.
            return 5000;
        }

        // Maximum chunk size for huge datasets.
        return 10000;
    }//end calculateOptimalChunkSize()

    /**
     * PERFORMANCE OPTIMIZED: Prepare objects for single-schema bulk operations
     *
     * This is a highly optimized fast path for single-schema operations (like CSV imports)
     * that avoids the overhead of mixed-schema validation and processing.
     *
     * METADATA MAPPING: This method applies schema-based metadata hydration using
     * SaveObject::hydrateObjectMetadata() to extract name, description, summary, etc.
     * from object data based on schema configuration.
     *
     * @param array               $objects  Array of objects in serialized format
     * @param Register|string|int $register Register context
     * @param Schema|string|int   $schema   Schema context
     *
     * @return (Schema|mixed)[][] Array containing [prepared objects, schema cache, invalid objects]
     *
     * @see website/docs/developers/import-flow.md for complete import flow documentation
     * @see SaveObject::hydrateObjectMetadata() for metadata extraction details
     *
     * @psalm-return list{array, array<int|string, Schema>, array<never, never>}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex single-schema optimization logic
     * @SuppressWarnings(PHPMD.NPathComplexity)       Many conditional paths for optimized preparation
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive single-schema optimization requires detailed logic
     */
    private function prepareSingleSchemaObjectsOptimized(
        array $objects,
        Register|string|int $register,
        Schema|string|int $schema
    ): array {
        $startTime = microtime(true);

        // PERFORMANCE OPTIMIZATION: Single cached register and schema load instead of per-object loading.
        if ($register instanceof Register) {
            $registerId = $register->getId();
            // Cache the provided register object.
            self::$registerCache[$registerId] = $register;
        }

        if (($register instanceof Register) === false) {
            $registerId = $register;
            // PERFORMANCE: Use cached register loading.
            $this->loadRegisterWithCache($registerId);
        }

        if ($schema instanceof Schema) {
            $schemaObj = $schema;
            $schemaId  = $schema->getId();
            // Cache the provided schema object.
            self::$schemaCache[$schemaId] = $schemaObj;
        }

        if (($schema instanceof Schema) === false) {
            $schemaId = $schema;
            // PERFORMANCE: Use cached schema loading.
            $schemaObj = $this->loadSchemaWithCache($schemaId);
        }

        // PERFORMANCE OPTIMIZATION: Single cached schema analysis for all objects.
        $schemaCache    = [$schemaId => $schemaObj];
        $schemaAnalysis = [$schemaId => $this->getSchemaAnalysisWithCache($schemaObj)];

        // PERFORMANCE OPTIMIZATION: Pre-calculate metadata once.
        $currentUser  = $this->userSession->getUser();
        $defaultOwner = null;
        if ($currentUser !== null) {
            $defaultOwner = $currentUser->getUID();
        }

        // NO ERROR SUPPRESSION: Let organisation service errors bubble up immediately!
        $defaultOrganisation = null;
        // TODO.
        $now = new DateTime();
        $now->format('c');

        // PERFORMANCE OPTIMIZATION: Process all objects with pre-calculated values.
        $preparedObjects = [];
        $invalidObjects  = [];

        foreach ($objects as $index => $object) {
            // Suppress unused variable warning for $index - it's part of foreach iteration.
            unset($index);
            // NO ERROR SUPPRESSION: Let single-schema preparation errors bubble up immediately!
            $selfData = $object['@self'] ?? [];

                // PERFORMANCE: Use pre-loaded values instead of per-object lookups.
                $selfData['register'] = $selfData['register'] ?? $registerId;
                $selfData['schema']   = $selfData['schema'] ?? $schemaId;

                // PERFORMANCE: Accept any non-empty string as ID, prioritize CSV 'id' column.
                $providedId   = $object['id'] ?? $selfData['id'] ?? null;
            $selfData['uuid'] = Uuid::v4()->toRfc4122();
            if (($providedId !== null) === true && empty(trim($providedId)) === false) {
                // Also set in @self for consistency.
                $selfData['uuid'] = $providedId;
            }

                // Set @self.id to generated UUID.
                // PERFORMANCE: Use pre-calculated metadata values.
                $selfData['owner']        = $selfData['owner'] ?? $defaultOwner;
                $selfData['organisation'] = $selfData['organisation'] ?? $defaultOrganisation;
                // DATABASE-MANAGED: created and updated are handled by database, don't set here to avoid false changes.
                // Update object's @self data before hydration.
                $object['@self'] = $selfData;

                // METADATA HYDRATION: Create temporary entity for metadata extraction.
                $tempEntity = new ObjectEntity();
                $tempEntity->setObject($object);

                // CRITICAL FIX: Hydrate @self data into the entity before calling hydrateObjectMetadata.
                // Convert datetime strings to DateTime objects for proper hydration.
            if (($object['@self'] ?? null) !== null && is_array($object['@self']) === true) {
                $selfDataForHydration = $object['@self'];

                // Convert published/depublished strings to DateTime objects.
                $hasPublished      = ($selfDataForHydration['published'] ?? null) !== null;
                $isPublishedString = is_string($selfDataForHydration['published'] ?? null);
                if ($hasPublished === true && $isPublishedString === true) {
                    try {
                        $selfDataForHydration['published'] = new DateTime($selfDataForHydration['published']);
                    } catch (Exception $e) {
                        // Keep as string if conversion fails.
                        $this->logger->warning(
                            'Failed to convert published date to DateTime',
                            [
                                'value' => $selfDataForHydration['published'],
                                'error' => $e->getMessage(),
                            ]
                        );
                    }
                }

                $hasDepublished = ($selfDataForHydration['depublished'] ?? null) !== null;
                $isDepubString  = is_string($selfDataForHydration['depublished'] ?? null);
                if ($hasDepublished === true && $isDepubString === true) {
                    try {
                        $selfDataForHydration['depublished'] = new DateTime($selfDataForHydration['depublished']);
                    } catch (Exception $e) {
                        // Keep as string if conversion fails.
                    }
                }

                $tempEntity->hydrate($selfDataForHydration);
            }//end if

                $this->saveHandler->hydrateObjectMetadata(entity: $tempEntity, schema: $schemaObj);

                // AUTO-PUBLISH LOGIC: Only set published for NEW objects if not already set from CSV.
                // Note: For updates to existing objects, published status should be preserved unless explicitly changed.
                $config      = $schemaObj->getConfiguration();
                $isNewObject = empty($selfData['uuid']) === true || isset($selfData['uuid']) === false;
            if (($config['autoPublish'] ?? null) !== null && $config['autoPublish'] === true && ($isNewObject === true)) {
                // Check if published date was already set from @self data (CSV).
                $publishedFromCsv = ($selfData['published'] ?? null) !== null && (empty($selfData['published']) === false);
                if (($publishedFromCsv === false) === true && $tempEntity->getPublished() === null) {
                    $this->logger->debug(
                        'Auto-publishing NEW object in bulk creation (single schema)',
                        [
                            'schema'           => $schemaObj->getTitle(),
                            'autoPublish'      => true,
                            'isNewObject'      => true,
                            'publishedFromCsv' => false,
                        ]
                    );
                    $tempEntity->setPublished(new DateTime());
                } else if ($publishedFromCsv === true) {
                    $this->logger->debug(
                        'Skipping auto-publish - published date provided from CSV',
                        [
                            'schema'           => $schemaObj->getTitle(),
                            'publishedFromCsv' => true,
                            'csvPublishedDate' => $selfData['published'],
                        ]
                    );
                }//end if
            }//end if

                // Extract hydrated metadata back to @self data AND top level (for bulk SQL).
            if ($tempEntity->getName() !== null) {
                $selfData['name'] = $tempEntity->getName();
                // TOP LEVEL for bulk SQL.
            }

            if ($tempEntity->getDescription() !== null) {
                $selfData['description'] = $tempEntity->getDescription();
                // TOP LEVEL for bulk SQL.
            }

            if ($tempEntity->getSummary() !== null) {
                $selfData['summary'] = $tempEntity->getSummary();
                // TOP LEVEL for bulk SQL.
            }

            if ($tempEntity->getImage() !== null) {
                $selfData['image'] = $tempEntity->getImage();
                // TOP LEVEL for bulk SQL.
            }

            if ($tempEntity->getSlug() !== null) {
                $selfData['slug'] = $tempEntity->getSlug();
                // TOP LEVEL for bulk SQL.
            }

            if ($tempEntity->getPublished() !== null) {
                $publishedFormatted    = $tempEntity->getPublished()->format('c');
                $selfData['published'] = $publishedFormatted;
                // TOP LEVEL for bulk SQL.
            }

            if ($tempEntity->getDepublished() !== null) {
                $depublishedFormatted    = $tempEntity->getDepublished()->format('c');
                $selfData['depublished'] = $depublishedFormatted;
                // TOP LEVEL for bulk SQL.
            }

                // Determine @self keys for debugging.
            $selfKeys = 'none';
            if ((($object['@self'] ?? null) !== null) === true) {
                $selfKeys = array_keys($object['@self']);
            }

                // DEBUG: Log actual data structure to understand what we're receiving.
                $this->logger->info(
                    "[SaveObjects] DEBUG - Single schema object structure",
                    [
                        'available_keys'      => array_keys($object),
                        'has_@self'           => (($object['@self'] ?? null) !== null) === true,
                        '@self_keys'          => $selfKeys,
                        'has_object_property' => (($object['object'] ?? null) !== null) === true,
                    // First 3 key-value pairs for structure.
                    ]
                );

                // TEMPORARY FIX: Extract business data properly based on actual structure.
            if (($object['object'] ?? null) !== null && is_array($object['object']) === true) {
                // NEW STRUCTURE: object property contains business data.
                $businessData = $object['object'];
                $this->logger->info("[SaveObjects] Using object property for business data");
            }

            if (($object['object'] ?? null) === null || is_array($object['object']) === false) {
                // LEGACY STRUCTURE: Remove metadata fields to isolate business data.
                $businessData   = $object;
                $metadataFields = [
                    '@self',
                    'name',
                    'description',
                    'summary',
                    'image',
                    'slug',
                    'published',
                    'depublished',
                    'register',
                    'schema',
                    'organisation',
                    'uuid',
                    'owner',
                    'created',
                    'updated',
                    'id',
                ];

                foreach ($metadataFields as $field) {
                    unset($businessData[$field]);
                }

                // CRITICAL DEBUG: Log what we're removing and what remains.
                $this->logger->info(
                    "[SaveObjects] Metadata removal applied",
                    [
                        'removed_fields'       => array_intersect($metadataFields, array_keys($object)),
                        'remaining_keys'       => array_keys($businessData),
                        'business_data_sample' => array_slice($businessData, 0, 3, true),
                    ]
                );
            }//end if

                // RELATIONS EXTRACTION: Scan the business data for relations (UUIDs and URLs).
                // This ensures relations metadata is populated during bulk import.
                $relations = $this->scanForRelations(data: $businessData, prefix: '', schema: $schemaObj);
                $selfData['relations'] = $relations;

                $this->logger->info(
                    "[SaveObjects] Relations scanned in preparation (single schema)",
                    [
                        'uuid'             => $selfData['uuid'] ?? 'unknown',
                        'relationCount'    => count($relations),
                        'businessDataKeys' => array_keys($businessData),
                        'relationsPreview' => array_slice($relations, 0, 5, true),
                    ]
                );

                // Store the clean business data in the database object column.
                $selfData['object'] = $businessData;

                $preparedObjects[] = $selfData;
        }//end foreach

        // INVERSE RELATIONS PROCESSING - Handle bulk inverse relations.
        $this->handleBulkInverseRelationsWithAnalysis(
            preparedObjects: $preparedObjects,
            schemaAnalysis: $schemaAnalysis
        );

        $endTime  = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);

        // Minimal logging for performance.
        if (count($objects) > 10000) {
            $this->logger->debug(
                'Single-schema preparation completed',
                [
                    'objectsProcessed' => count($preparedObjects),
                    'timeMs'           => $duration,
                    'speed'            => round(count($preparedObjects) / max(($endTime - $startTime), 0.001), 2),
                ]
            );
        }

        return [$preparedObjects, $schemaCache, $invalidObjects];
    }//end prepareSingleSchemaObjectsOptimized()

    /**
     * Perform comprehensive schema analysis for bulk operations
     *
     * PERFORMANCE OPTIMIZATION: This method analyzes schemas once and caches all needed information
     * for the entire bulk operation, including metadata field mapping, inverse relation properties,
     * validation requirements, and property configurations. This eliminates redundant schema analysis.
     *
     * @param Schema $schema Schema to analyze
     *
     * @return (((bool|mixed)[]|mixed)[]|bool|null)[]
     *
     * @psalm-return   array{metadataFields: array<string, mixed>,
     *     inverseProperties: array<array{inversedBy: mixed, writeBack: bool,
     *     isArray: bool}>, validationRequired: bool, properties: array|null,
     *     configuration: array|null}
     * @phpstan-return array<string, mixed>
     */
    private function performComprehensiveSchemaAnalysis(Schema $schema): array
    {
        // Delegate to BulkValidationHandler for schema analysis.
        return $this->bulkValidHandler->performComprehensiveSchemaAnalysis($schema);
    }//end performComprehensiveSchemaAnalysis()

    /**
     * Handle bulk inverse relations using cached schema analysis
     *
     * PERFORMANCE OPTIMIZATION: This method uses pre-analyzed inverse relation properties
     * to process relations without re-analyzing schema properties for each object.
     *
     * @param array $preparedObjects Prepared objects to process (passed by reference)
     * @param array $schemaAnalysis  Pre-analyzed schema information indexed by schema ID
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess)         Uuid::isValid is standard Symfony UID pattern
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex inverse relation analysis logic
     * @SuppressWarnings(PHPMD.NPathComplexity)      Many conditional paths for relation handling
     */
    private function handleBulkInverseRelationsWithAnalysis(array &$preparedObjects, array $schemaAnalysis): void
    {
        // Track statistics for debugging/monitoring.
        $_appliedCount   = 0;
        $_processedCount = 0;

        // Create direct UUID to object reference mapping.
        $objectsByUuid = [];
        foreach ($preparedObjects as $_index => &$object) {
            $selfData   = $object['@self'] ?? [];
            $objectUuid = $selfData['id'] ?? null;
            if ($objectUuid !== null && $objectUuid !== '') {
                $objectsByUuid[$objectUuid] = &$object;
            }
        }

        // Process inverse relations using cached analysis.
        foreach ($preparedObjects as $_index => &$object) {
            $selfData   = $object['@self'] ?? [];
            $schemaId   = $selfData['schema'] ?? null;
            $objectUuid = $selfData['id'] ?? null;

            if ($schemaId === false || $objectUuid === false || isset($schemaAnalysis[$schemaId]) === false) {
                continue;
            }

            $analysis = $schemaAnalysis[$schemaId];

            // PERFORMANCE OPTIMIZATION: Use pre-analyzed inverse properties.
            foreach ($analysis['inverseProperties'] ?? [] as $property => $propertyInfo) {
                if (isset($object[$property]) === false) {
                    continue;
                }

                $value      = $object[$property];
                $inversedBy = $propertyInfo['inversedBy'];

                // Handle single object relations.
                if (($propertyInfo['isArray'] === false) === true
                    && is_string($value) === true
                    && \Symfony\Component\Uid\Uuid::isValid($value) === true
                ) {
                    if (isset($objectsByUuid[$value]) === true) {
                        // @psalm-suppress EmptyArrayAccess - Already checked isset above.
                        $targetObject = &$objectsByUuid[$value];
                        // @psalm-suppress EmptyArrayAccess - Already checked isset above.
                        $existingValues = ($targetObject[$inversedBy] ?? []);
                        // @psalm-suppress EmptyArrayAccess - $existingValues is initialized with ?? []
                        if (is_array($existingValues) === false) {
                            $existingValues = [];
                        }

                        if (in_array($objectUuid, $existingValues, true) === false) {
                            $existingValues[]          = $objectUuid;
                            $targetObject[$inversedBy] = $existingValues;
                            $_appliedCount++;
                        }

                        $_processedCount++;
                    }
                } else if (($propertyInfo['isArray'] === true) && is_array($value) === true) {
                    // Handle array of object relations.
                    foreach ($value as $relatedUuid) {
                        $isValidUuid = \Symfony\Component\Uid\Uuid::isValid($relatedUuid);
                        if (is_string($relatedUuid) === true && $isValidUuid === true) {
                            if (isset($objectsByUuid[$relatedUuid]) === true) {
                                // @psalm-suppress EmptyArrayAccess - Already checked isset above.
                                $targetObject = &$objectsByUuid[$relatedUuid];
                                // @psalm-suppress EmptyArrayAccess - $targetObject is guaranteed to exist from isset check
                                $existingValues = ($targetObject[$inversedBy] ?? []);
                                if (is_array($existingValues) === false) {
                                    $existingValues = [];
                                }

                                if (in_array($objectUuid, $existingValues, true) === false) {
                                    $existingValues[]          = $objectUuid;
                                    $targetObject[$inversedBy] = $existingValues;
                                    $_appliedCount++;
                                }

                                $_processedCount++;
                            }
                        }
                    }//end foreach
                }//end if
            }//end foreach
        }//end foreach
    }//end handleBulkInverseRelationsWithAnalysis()

    /**
     * Scans an object for relations (UUIDs and URLs) and returns them in dot notation
     *
     * This method checks schema properties for relation types:
     * - Properties with type 'text' and format 'uuid', 'uri', or 'url'
     * - Properties with type 'object' that contain string values (always treated as relations)
     * - Properties with type 'array' of objects that contain string values
     *
     * This is ported from SaveObject.php to provide consistent relation handling
     * for bulk operations.
     *
     * @param array       $data   The object data to scan
     * @param string      $prefix The current prefix for dot notation (used in recursion)
     * @param Schema|null $schema The schema to check property definitions against
     *
     * @return array Array of relations with dot notation paths as keys and UUIDs/URLs as values
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex relation scanning with recursive logic
     * @SuppressWarnings(PHPMD.NPathComplexity)      Many conditional paths for relation detection
     * @SuppressWarnings(PHPMD.ElseExpression)       Else clauses used for clear array vs non-array branching
     */
    private function scanForRelations(array $data, string $prefix='', ?Schema $schema=null): array
    {
        $relations = [];

        // NO ERROR SUPPRESSION: Let relation scanning errors bubble up immediately!
        // Get schema properties if available.
        $schemaProperties = null;
        if ($schema !== null) {
            // NO ERROR SUPPRESSION: Let schema property parsing errors bubble up immediately!
            $schemaProperties = $schema->getProperties();
        }

        foreach ($data as $key => $value) {
            // Skip if key is not a string or is empty.
            if (is_string($key) === false || empty($key) === true) {
                continue;
            }

            if (($prefix !== '') === true) {
                $currentPath = $prefix.'.'.$key;
            } else {
                $currentPath = $key;
            }

            if (is_array($value) === true && empty($value) === false) {
                // Check if this is an array property in the schema.
                $propertyConfig   = $schemaProperties[$key] ?? null;
                $isArrayOfObjects = ($propertyConfig !== null) === true &&
                                  ($propertyConfig['type'] ?? '') === 'array' &&
                                  (($propertyConfig['items']['type'] ?? null) !== null) === true &&
                                  ($propertyConfig['items']['type'] === 'object') === true;

                if ($isArrayOfObjects === true) {
                    // For arrays of objects, scan each item for relations.
                    foreach ($value as $index => $item) {
                        if (is_array($item) === true) {
                            $itemRelations = $this->scanForRelations(
                                data: $item,
                                prefix: $currentPath.'.'.$index,
                                schema: $schema
                            );
                            $relations     = array_merge($relations, $itemRelations);
                        } else if (is_string($item) === true && empty($item) === false) {
                            // String values in object arrays are always treated as relations.
                            $relations[$currentPath.'.'.$index] = $item;
                        }
                    }
                } else {
                    // For non-object arrays, check each item.
                    foreach ($value as $index => $item) {
                        if (is_array($item) === true) {
                            // Recursively scan nested arrays/objects.
                            $itemRelations = $this->scanForRelations(
                                data: $item,
                                prefix: $currentPath.'.'.$index,
                                schema: $schema
                            );
                            $relations     = array_merge($relations, $itemRelations);
                        } else if (is_string($item) === true && empty($item) === false && trim($item) !== '') {
                            // Check if the string looks like a reference.
                            if ($this->isReference($item) === true) {
                                $relations[$currentPath.'.'.$index] = $item;
                            }
                        }
                    }
                }//end if
            } else if (is_string($value) === true && empty($value) === false && trim($value) !== '') {
                $treatAsRelation = false;

                // Check schema property configuration first.
                if ($schemaProperties !== null && (($schemaProperties[$key] ?? null) !== null) === true) {
                    $propertyConfig = $schemaProperties[$key];
                    $propertyType   = $propertyConfig['type'] ?? '';
                    $propertyFormat = $propertyConfig['format'] ?? '';

                    // Check for explicit relation types.
                    if ($propertyType === 'text' && in_array($propertyFormat, ['uuid', 'uri', 'url'], true) === true) {
                        $treatAsRelation = true;
                    } else if ($propertyType === 'object') {
                        // Object properties with string values are always relations.
                        $treatAsRelation = true;
                    }
                }

                // If not determined by schema, check for reference patterns.
                if ($treatAsRelation === false) {
                    $treatAsRelation = $this->isReference($value);
                }

                if ($treatAsRelation === true) {
                    $relations[$currentPath] = $value;
                }
            }//end if
        }//end foreach

        return $relations;
    }//end scanForRelations()

    /**
     * Determines if a string value should be treated as a reference to another object
     *
     * This method checks for various reference patterns including:
     * - Standard UUIDs (e.g., "dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4")
     * - Prefixed IDs (e.g., "id-819c2fe5-db4e-4b6f-8071-6a63fd400e34")
     * - URLs
     * - Other identifier patterns
     *
     * @param string $value The string value to check
     *
     * @return bool True if the value should be treated as a reference
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex pattern matching for various reference formats
     */
    private function isReference(string $value): bool
    {
        $value = trim($value);

        // Empty strings are not references.
        if (empty($value) === true) {
            return false;
        }

        // Check for standard UUID pattern (8-4-4-4-12 format).
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === true) {
            return true;
        }

        // Check for prefixed UUID patterns (e.g., "id-uuid", "ref-uuid", etc.).
        if (preg_match('/^[a-z]+-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === true) {
            return true;
        }

        // Check for URLs.
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        // Check for other common ID patterns, but be more selective to avoid false positives.
        // Only consider strings that look like identifiers, not regular text.
        if (preg_match('/^[a-z0-9][a-z0-9_-]{7,}$/i', $value) === 1) {
            // Must contain at least one hyphen or underscore (indicating it's likely an ID).
            // AND must not contain spaces or common text words.
            if ((strpos($value, '-') !== false || strpos($value, '_') !== false)
                && preg_match('/\s/', $value) === false
                && $this->isCommonTextWord($value) === false
            ) {
                return true;
            }
        }

        return false;
    }//end isReference()

    /**
     * Check if a value is a common text word that should not be treated as a reference.
     *
     * @param string $value The value to check.
     *
     * @return bool True if the value is a common text word.
     */
    private function isCommonTextWord(string $value): bool
    {
        $commonWords = ['applicatie', 'systeemsoftware', 'open-source', 'closed-source'];
        return in_array(strtolower($value), $commonWords, true);
    }//end isCommonTextWord()
}//end class
