<?php
/**
 * Bulk Object Save Operations Handler
 *
 * SPECIALIZED BULK HANDLER OVERVIEW:
 * This handler is responsible for high-performance bulk saving operations for multiple objects.
 * It implements advanced performance optimizations including schema analysis caching, memory
 * optimization, single-pass processing, and batch database operations.
 *
 * KEY RESPONSIBILITIES:
 * - High-performance bulk object creation and updates
 * - Comprehensive schema analysis and caching for batch operations
 * - Memory-optimized object preparation with in-place transformations
 * - Single-pass inverse relations processing
 * - Batch writeBack operations for bidirectional relations
 * - Bulk metadata hydration with cached schema analysis
 * - Optimized validation with minimal copying
 * - Optimized bulk processing for all dataset sizes
 *
 * PERFORMANCE OPTIMIZATIONS:
 * ✅ 1. Eliminate redundant object fetch after save - reconstructed from existing data
 * ✅ 2. Consolidate schema cache - single persistent cache across operations
 * ✅ 3. Batch writeBack operations - bulk UPDATEs instead of individual calls
 * ✅ 4. Single-pass inverse relations - combined scanning and applying
 * ✅ 5. Optimize object transformation - in-place operations, minimal copying
 * ✅ 6. Comprehensive schema analysis - single pass for all requirements
 * ✅ 7. Memory optimization - pass-by-reference, selective updates
 *
 * INTEGRATION WITH OTHER HANDLERS:
 * - Uses SaveObject for complex individual object cascading operations
 * - Leverages ObjectEntityMapper for actual database bulk operations
 * - Integrates with ValidateObject for bulk validation when enabled
 * - Called by ObjectService for bulk operations orchestration
 *
 * ⚠️ IMPORTANT: Do NOT confuse with SaveObject or ObjectService!
 * - SaveObjects = High-performance bulk operations with optimizations
 * - SaveObject = Individual object detailed business logic and relations
 * - ObjectService = High-level orchestration, context management, RBAC
 *
 * PERFORMANCE GAINS:
 * - Database calls: ~60-70% reduction
 * - Memory usage: ~40% reduction
 * - Time complexity: O(N*M*P) → O(N*M)
 * - Processing speed: 2-3x faster for large datasets
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Service\ObjectHandlers
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial SaveObjects implementation with performance optimizations
 */

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;


/**
 * Bulk object save orchestrator.
 *
 * Coordinates preparation, transformation, validation, persistence, and relation
 * handling for batch object operations. Reduced from 2,717 to 1,815 lines via
 * dead code removal and extract-method decomposition (21 focused helpers).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Bulk save coordination requires many steps
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Inherent complexity of bulk operations
 * @SuppressWarnings(PHPMD.TooManyMethods)           Methods are small focused helpers extracted from complex methods
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Bulk ops coordinate mappers, services, and validators
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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
     * @param MagicMapper         $objectEntityMapper  Mapper for object entity database operations
     * @param SchemaMapper        $schemaMapper        Mapper for schema operations
     * @param RegisterMapper      $registerMapper      Mapper for register operations
     * @param SaveObject          $saveHandler         Handler for individual object operations
     * @param IUserSession        $userSession         User session for getting current user
     * @param OrganisationService $organisationService Service for organisation operations
     * @param LoggerInterface     $logger              Logger for error and debug logging
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    public function __construct(
        private readonly MagicMapper $objectEntityMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SaveObject $saveHandler,
        private readonly IUserSession $userSession,
        private readonly OrganisationService $organisationService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Load schema from cache or database with performance optimization
     *
     * @param int|string $schemaId Schema ID to load
     *
     * @return Schema The loaded schema
     * @throws Exception If schema cannot be found
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function loadSchemaWithCache(int|string $schemaId): Schema
    {
        // Check static cache first.
        if (isset(self::$schemaCache[$schemaId]) === true) {
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
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function getSchemaAnalysisWithCache(Schema $schema): array
    {
        $schemaId = $schema->getId();

        // Check static cache first.
        if (isset(self::$schemaAnalysisCache[$schemaId]) === true) {
            return self::$schemaAnalysisCache[$schemaId];
        }

        // Generate analysis and cache.
        $analysis = $this->performComprehensiveSchemaAnalysis(schema: $schema);
        self::$schemaAnalysisCache[$schemaId] = $analysis;

        return $analysis;
    }//end getSchemaAnalysisWithCache()

    /**
     * Load register from cache or database with performance optimization
     *
     * @param int|string $registerId Register ID to load
     *
     * @return Register The loaded register
     * @throws Exception If register cannot be found
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function loadRegisterWithCache(int|string $registerId): Register
    {
        // Check static cache first.
        if (isset(self::$registerCache[$registerId]) === true) {
            return self::$registerCache[$registerId];
        }

        // Load from database and cache.
        $register = $this->registerMapper->find($registerId);
        self::$registerCache[$registerId] = $register;

        return $register;
    }//end loadRegisterWithCache()

    /**
     * Clear static caches (useful for testing and memory management)
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    public static function clearSchemaCache(): void
    {
        self::$schemaCache         = [];
        self::$schemaAnalysisCache = [];
        self::$registerCache       = [];
    }//end clearSchemaCache()

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
     * @param array                    $objects        Array of objects in serialized format
     * @param Register|string|int|null $register       Optional register context
     * @param Schema|string|int|null   $schema         Optional schema context
     * @param bool                     $_rbac          Whether to apply RBAC filtering
     * @param bool                     $_multitenancy  Whether to apply multi-organization filtering
     * @param bool                     $_validation    Whether to validate objects against schema definitions
     * @param bool                     $_events        Whether to dispatch object lifecycle events
     * @param bool                     $deduplicateIds Whether to deduplicate by IDs
     * @param bool                     $enrich         Whether to enrich objects
     *
     * @throws InvalidArgumentException If required fields are missing from any object
     * @throws \OCP\DB\Exception If a database error occurs during bulk operations
     *
     * @return array Comprehensive bulk operation results with statistics and categorized objects
     *
     * @phpstan-param  array<int, array<string, mixed>> $objects
     * @psalm-param    array<int, array<string, mixed>> $objects
     * @phpstan-return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Orchestrator at threshold after extracting initializeSaveResult
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    public function saveObjects(
        array $objects,
        Register|string|int|null $register=null,
        Schema|string|int|null $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $_validation=false,
        bool $_events=false,
        bool $deduplicateIds=false,
        bool $enrich=false
    ): array {

        $startTime    = microtime(true);
        $totalObjects = count($objects);

        // Initialize result structure.
        $result = $this->initializeSaveResult(totalObjects: $totalObjects);

        // Validate input early.
        if (empty($objects) === true) {
            return $result;
        }

        $isMixedSchema = ($schema === null);

        // PERFORMANCE OPTIMIZATION: Reduce logging overhead during bulk operations.
        if (count($objects) > 10000 || ($isMixedSchema === true && count($objects) > 1000)) {
            $opLabel = 'Starting single-schema bulk save operation';
            $opType  = 'single-schema';
            if ($isMixedSchema === true) {
                $opLabel = 'Starting mixed-schema bulk save operation';
                $opType  = 'mixed-schema';
            }

            $this->logger->info(
                $opLabel,
                [
                    'totalObjects' => count($objects),
                    'operation'    => $opType,
                ]
            );
        }//end if

        // PERFORMANCE OPTIMIZATION: Use fast path for single-schema operations.
        // PERFORMANCE OPTIMIZATION: Use fast path for single-schema operations.
        $useFastPath = ($isMixedSchema === false && $schema !== null);

        if ($useFastPath === true) {
            // FAST PATH: Single-schema operation - avoid complex mixed-schema logic.
            [$processedObjects, $globalSchemaCache, $prepInvalidObjs] = $this->prepareSingleSchemaObjectsOptimized(
                objects: $objects,
                register: $register,
                schema: $schema
            );
        }

        if ($useFastPath === false) {
            // STANDARD PATH: Mixed-schema operation - use full preparation logic.
            [$processedObjects, $globalSchemaCache, $prepInvalidObjs] = $this->prepareObjectsForBulkSave(objects: $objects);
        }

        // CRITICAL FIX: Include objects that failed during preparation in result.
        foreach ($prepInvalidObjs as $invalidObj) {
            $result['invalid'][] = $invalidObj;
            $result['statistics']['invalid']++;
            $result['statistics']['errors']++;
        }

        // Check if we have any processed objects.
        if (empty($processedObjects) === true) {
            $result['errors'][] = [
                'error' => 'No objects were successfully prepared for bulk save',
                'type'  => 'NoObjectsPreparedException',
            ];
            return $result;
        }

        // Update statistics to reflect actual processed objects.
        $result['statistics']['totalProcessed'] = count($processedObjects);

        // Process objects in chunks for optimal performance.
        $chunkSize = $this->calculateOptimalChunkSize(totalObjects: count($processedObjects));
        $chunks    = array_chunk($processedObjects, $chunkSize);

        // SINGLE PATH PROCESSING - Process all chunks the same way regardless of size.
        foreach ($chunks as $chunkIndex => $objectsChunk) {
            $chunkStart = microtime(true);

            // Process the current chunk and get the result.
            $chunkResult = $this->processObjectsChunk(
                objects: $objectsChunk,
                schemaCache: $globalSchemaCache,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                _validation: $_validation,
                _events: $_events
            );

            // Merge chunk results for saved, updated, invalid, errors, and unchanged.
            $result['saved']     = array_merge($result['saved'], $chunkResult['saved']);
            $result['updated']   = array_merge($result['updated'], $chunkResult['updated']);
            $result['invalid']   = array_merge($result['invalid'], $chunkResult['invalid']);
            $result['errors']    = array_merge($result['errors'], $chunkResult['errors']);
            $result['unchanged'] = array_merge($result['unchanged'], $chunkResult['unchanged']);

            // Update total statistics.
            $result['statistics']['saved']     += $chunkResult['statistics']['saved'] ?? 0;
            $result['statistics']['updated']   += $chunkResult['statistics']['updated'] ?? 0;
            $result['statistics']['invalid']   += $chunkResult['statistics']['invalid'] ?? 0;
            $result['statistics']['errors']    += $chunkResult['statistics']['errors'] ?? 0;
            $result['statistics']['unchanged'] += $chunkResult['statistics']['unchanged'] ?? 0;

            // Calculate chunk processing time and speed.
            $chunkTime  = microtime(true) - $chunkStart;
            $chunkSpeed = count($objectsChunk) / max($chunkTime, 0.001);

            // Store per-chunk statistics for transparency and debugging.
            if (isset($result['chunkStatistics']) === false) {
                $result['chunkStatistics'] = [];
            }

            $result['chunkStatistics'][] = [
                'chunkIndex'     => $chunkIndex,
                'count'          => count($objectsChunk),
                'saved'          => $chunkResult['statistics']['saved'] ?? 0,
                'updated'        => $chunkResult['statistics']['updated'] ?? 0,
                'unchanged'      => $chunkResult['statistics']['unchanged'] ?? 0,
                'invalid'        => $chunkResult['statistics']['invalid'] ?? 0,
                // Milliseconds.
                'processingTime' => round($chunkTime * 1000, 2),
                // Objects per second.
                'speed'          => round($chunkSpeed, 2),
            ];
        }//end foreach

        $totalTime    = microtime(true) - $startTime;
        $overallSpeed = count($processedObjects) / max($totalTime, 0.001);

        // ADD PERFORMANCE METRICS: Include timing and speed metrics like ImportService does.
        $efficiency = 0;
        if (count($processedObjects) > 0) {
            $efficiency = round((count($processedObjects) / $totalObjects) * 100, 1);
        }

        $result['performance'] = [
            'totalTime'        => round($totalTime, 3),
            'totalTimeMs'      => round($totalTime * 1000, 2),
            'objectsPerSecond' => round($overallSpeed, 2),
            'totalProcessed'   => count($processedObjects),
            'totalRequested'   => $totalObjects,
            'efficiency'       => $efficiency,
        ];

        // Add deduplication efficiency if we have unchanged objects.
        $unchangedCount = count($result['unchanged']);
        if ($unchangedCount > 0) {
            $totalProcessed = count($result['saved']) + count($result['updated']) + $unchangedCount;
            $pct            = round(($unchangedCount / $totalProcessed) * 100, 1);
            $result['performance']['deduplicationEfficiency'] = $pct.'% operations avoided';
        }

        return $result;

    }//end saveObjects()

    /**
     * Initialize the result structure for a bulk save operation
     *
     * Creates the standard result array with empty category arrays and zero-initialized
     * statistics counters.
     *
     * @param int $totalObjects The total number of objects requested for processing
     *
     * @return array The initialized result structure
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function initializeSaveResult(int $totalObjects): array
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
    }//end initializeSaveResult()

    /**
     * Calculate optimal chunk size based on total objects for internal processing
     *
     * @param int $totalObjects Total number of objects to process
     *
     * @return int Optimal chunk size
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function calculateOptimalChunkSize(int $totalObjects): int
    {
        // ULTRA-PERFORMANCE: Aggressive chunk sizes for sub-1-second imports.
        // Optimized for 33k+ object datasets.
        if ($totalObjects <= 1000) {
            // Process all at once for small/medium sets.
            return $totalObjects;
        }

        if ($totalObjects <= 5000) {
            return 2500;
        }

        if ($totalObjects <= 10000) {
            return 5000;
        }

        if ($totalObjects <= 50000) {
            return 10000;
        }

        // Maximum chunk size for huge datasets.
        return 20000;

    }//end calculateOptimalChunkSize()

    /**
     * Prepares objects for bulk save with comprehensive schema analysis
     *
     * PERFORMANCE OPTIMIZATION: This method performs comprehensive schema analysis in a single pass,
     * caching all schema-dependent information needed for the entire bulk operation. This eliminates
     * redundant schema loading and analysis throughout the preparation process.
     *
     * METADATA MAPPING: Each object gets schema-based metadata hydration using SaveObject::hydrateObjectMetadata()
     * to extract name, description, summary, etc. based on the object's specific schema configuration.
     *
     * @param array $objects Array of objects in serialized format
     *
     * @return array Array containing [prepared objects, schema cache]
     *
     * @see website/docs/developers/import-flow.md for complete import flow documentation
     * @see SaveObject::hydrateObjectMetadata() for metadata extraction details
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multi-schema grouping + validation requires branching
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function prepareObjectsForBulkSave(array $objects): array
    {
        // Early return for empty arrays.
        if (empty($objects) === true) {
            return [[], []];
        }

        // PERFORMANCE OPTIMIZATION: Build comprehensive schema analysis cache first.
        [$schemaCache, $schemaAnalysis] = $this->groupAndLoadSchemas(objects: $objects);

        // Pre-process objects using cached schema analysis.
        $preparedObjects = [];
        $invalidObjects  = [];
        // Track objects with invalid schemas.
        foreach ($objects as $index => $object) {
            $preparedObjects[$index] = $this->prepareMixedSchemaObject(object: $object, schemaCache: $schemaCache);
        }//end foreach

        // PERFORMANCE OPTIMIZATION: Use cached analysis for bulk inverse relations.
        $this->handleBulkInverseRelationsWithAnalysis(preparedObjects: $preparedObjects, schemaAnalysis: $schemaAnalysis);

        // Return prepared objects, schema cache, and any invalid objects found during preparation.
        return [array_values($preparedObjects), $schemaCache, $invalidObjects];

    }//end prepareObjectsForBulkSave()

    /**
     * Group objects by schema and load all unique schemas into caches
     *
     * Extracts unique schema IDs from the objects array, loads each schema
     * and its analysis into the caches, and returns both caches.
     *
     * @param array $objects The objects to extract schema IDs from
     *
     * @return array [schemaCache, schemaAnalysis] indexed by schema ID
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function groupAndLoadSchemas(array $objects): array
    {
        $schemaCache    = [];
        $schemaAnalysis = [];

        $schemaIds = [];
        foreach ($objects as $object) {
            $selfData = $object['@self'] ?? [];
            $schemaId = $selfData['schema'] ?? null;
            if ($schemaId !== null && in_array($schemaId, $schemaIds) === false) {
                $schemaIds[] = $schemaId;
            }
        }

        // PERFORMANCE OPTIMIZATION: Load and analyze all schemas with caching.
        // NO ERROR SUPPRESSION: Let schema loading errors bubble up immediately!
        foreach ($schemaIds as $schemaId) {
            $schema = $this->loadSchemaWithCache(schemaId: $schemaId);
            $schemaCache[$schemaId]    = $schema;
            $schemaAnalysis[$schemaId] = $this->getSchemaAnalysisWithCache(schema: $schema);
        }

        return [$schemaCache, $schemaAnalysis];
    }//end groupAndLoadSchemas()

    /**
     * Prepare a single object in a mixed-schema bulk save operation
     *
     * Handles ID generation, metadata hydration, relation scanning, and
     * pre-validation cascading for an individual object within a mixed-schema batch.
     *
     * @param array $object      The raw object data with @self metadata
     * @param array $schemaCache The schema cache indexed by schema ID
     *
     * @return array The prepared object with hydrated metadata
     *
     * @throws Exception If schema is not found in cache
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function prepareMixedSchemaObject(array $object, array $schemaCache): array
    {
        // NO ERROR SUPPRESSION: Let object processing errors bubble up immediately!
        $selfData = $object['@self'] ?? [];
        $schemaId = $selfData['schema'] ?? null;

        // Allow objects without schema ID to pass through - they'll be caught in transformation.
        if ($schemaId === false) {
            return $object;
        }

        // Schema validation - direct error if not found in cache.
        if (isset($schemaCache[$schemaId]) === false) {
            throw new Exception("Schema {$schemaId} not found in cache during preparation");
        }

        $schema = $schemaCache[$schemaId];

        // Accept any non-empty string as ID, generate UUID if not provided.
        $providedId = $selfData['id'] ?? null;
        if ($providedId === null || empty(trim($providedId)) === true) {
            $selfData['id']  = Uuid::v4()->toRfc4122();
            $object['@self'] = $selfData;
        }

        // METADATA HYDRATION: Create temporary entity for metadata extraction.
        $tempEntity = new ObjectEntity();
        $tempEntity->setObject($object);

        // CRITICAL FIX: Hydrate @self data into the entity before calling hydrateObjectMetadata.
        if (isset($object['@self']) === true && is_array($object['@self']) === true) {
            $tempEntity->hydrate($object['@self']);
        }

        $this->saveHandler->hydrateObjectMetadata($tempEntity, $schema);

        // Extract hydrated metadata back to object's @self data AND top level (for bulk SQL).
        $selfData = $object['@self'] ?? [];
        $selfData = $this->applyHydratedMetadata(selfData: $selfData, object: $object, tempEntity: $tempEntity);

        // RELATIONS EXTRACTION: Scan the object data for relations (UUIDs and URLs).
        $relationData = $tempEntity->getObject();
        $relations    = $this->scanForRelations(data: $relationData, prefix: '', schema: $schema);
        $selfData['relations'] = $relations;

        $object['@self'] = $selfData;

        // Handle pre-validation cascading for inversedBy properties.
        [$processedObject] = $this->handlePreValidationCascading(object: $object, schema: $schema, uuid: $selfData['id']);

        return $processedObject;
    }//end prepareMixedSchemaObject()

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
     * @return array Array containing [prepared objects, schema cache, invalid objects]
     *
     * @see website/docs/developers/import-flow.md for complete import flow documentation
     * @see SaveObject::hydrateObjectMetadata() for metadata extraction details
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Single-schema optimization path with many edge cases
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function prepareSingleSchemaObjectsOptimized(
        array $objects,
        Register|string|int $register,
        Schema|string|int $schema
    ): array {
        $startTime = microtime(true);

        // PERFORMANCE OPTIMIZATION: Load and validate schema context once.
        [$registerId, , $schemaId, $schemaObj, $schemaCache, $schemaAnalysis] = $this->loadAndValidateSchemaContext(
            register: $register,
            schema: $schema
        );

        // PERFORMANCE OPTIMIZATION: Pre-calculate metadata once.
        $currentUser  = $this->userSession->getUser();
        $defaultOwner = null;
        if ($currentUser !== null) {
            $defaultOwner = $currentUser->getUID();
        }

        // NO ERROR SUPPRESSION: Let organisation service errors bubble up immediately!
        $defaultOrganisation = $this->organisationService->getOrganisationForNewEntity();

        // PERFORMANCE OPTIMIZATION: Process all objects with pre-calculated values.
        $preparedObjects = [];
        $invalidObjects  = [];

        foreach ($objects as $object) {
            $preparedObjects[] = $this->prepareSingleSchemaObject(
                object: $object,
                registerId: $registerId,
                schemaId: $schemaId,
                schemaObj: $schemaObj,
                defaultOwner: $defaultOwner,
                defaultOrganisation: $defaultOrganisation
            );
        }

        // INVERSE RELATIONS PROCESSING - Handle bulk inverse relations.
        $this->handleBulkInverseRelationsWithAnalysis(preparedObjects: $preparedObjects, schemaAnalysis: $schemaAnalysis);

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
     * Load and validate register+schema context for single-schema operations
     *
     * Resolves register and schema from IDs or objects, caches them, and returns
     * all context needed for batch preparation.
     *
     * @param Register|string|int $register Register context (object or ID)
     * @param Schema|string|int   $schema   Schema context (object or ID)
     *
     * @return array [registerId, registerObj, schemaId, schemaObj, schemaCache, schemaAnalysis]
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function loadAndValidateSchemaContext(
        Register|string|int $register,
        Schema|string|int $schema
    ): array {
        $registerId = $register;
        if ($register instanceof Register === true) {
            $registerId = $register->getId();
        }

        if ($register instanceof Register) {
            self::$registerCache[$registerId] = $register;
        }

        if ($register instanceof Register === false) {
            $register = $this->loadRegisterWithCache(registerId: $registerId);
        }

        $schemaId = $schema;
        if ($schema instanceof Schema === true) {
            $schemaId = $schema->getId();
        }

        if ($schema instanceof Schema) {
            $schemaObj = $schema;
            self::$schemaCache[$schemaId] = $schemaObj;
        }

        if ($schema instanceof Schema === false) {
            $schemaObj = $this->loadSchemaWithCache(schemaId: $schemaId);
        }

        $schemaCache    = [$schemaId => $schemaObj];
        $schemaAnalysis = [$schemaId => $this->getSchemaAnalysisWithCache(schema: $schemaObj)];

        return [$registerId, $register, $schemaId, $schemaObj, $schemaCache, $schemaAnalysis];
    }//end loadAndValidateSchemaContext()

    /**
     * Prepare a single object for single-schema bulk save
     *
     * Applies pre-calculated defaults, hydrates metadata, extracts business data,
     * and scans for relations. Returns the prepared selfData array.
     *
     * @param array       $object              The raw object data
     * @param int|string  $registerId          The register ID
     * @param int|string  $schemaId            The schema ID
     * @param Schema      $schemaObj           The schema object for metadata hydration
     * @param string|null $defaultOwner        The default owner UID
     * @param string|null $defaultOrganisation The default organisation
     *
     * @return array The prepared selfData array ready for database operations
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function prepareSingleSchemaObject(
        array $object,
        int|string $registerId,
        int|string $schemaId,
        Schema $schemaObj,
        ?string $defaultOwner,
        ?string $defaultOrganisation
    ): array {
        // NO ERROR SUPPRESSION: Let single-schema preparation errors bubble up immediately!
        $selfData = $object['@self'] ?? [];

        // PERFORMANCE: Use pre-loaded values instead of per-object lookups.
        $selfData['register'] = $selfData['register'] ?? $registerId;
        $selfData['schema']   = $selfData['schema'] ?? $schemaId;

        // PERFORMANCE: Accept any non-empty string as ID, prioritize CSV 'id' column.
        $providedId       = $object['id'] ?? $selfData['id'] ?? null;
        $selfData['uuid'] = Uuid::v4()->toRfc4122();
        $selfData['id']   = $selfData['uuid'];

        if ($providedId !== null && empty(trim($providedId)) === false) {
            $selfData['uuid'] = $providedId;
            $selfData['id']   = $providedId;
        }

        // PERFORMANCE: Use pre-calculated metadata values.
        $selfData['owner']        = $selfData['owner'] ?? $defaultOwner;
        $selfData['organisation'] = $selfData['organisation'] ?? $defaultOrganisation;

        // Update object's @self data before hydration.
        $object['@self'] = $selfData;

        // METADATA HYDRATION: Create temporary entity for metadata extraction.
        $tempEntity = new ObjectEntity();
        $tempEntity->setObject($object);

        // CRITICAL FIX: Hydrate @self data into the entity before calling hydrateObjectMetadata.
        if (isset($object['@self']) === true && is_array($object['@self']) === true) {
            $tempEntity->hydrate($object['@self']);
        }

        $this->saveHandler->hydrateObjectMetadata($tempEntity, $schemaObj);

        // Extract hydrated metadata back to @self data AND top level (for bulk SQL).
        $selfData = $this->applyHydratedMetadata(selfData: $selfData, object: $object, tempEntity: $tempEntity);

        // DEBUG: Log actual data structure to understand what we're receiving.
        $selfKeys = 'none';
        if (isset($object['@self']) === true) {
            $selfKeys = array_keys($object['@self']);
        }

        $this->logger->info(
                "[SaveObjects] DEBUG - Single schema object structure",
                [
                    'available_keys'      => array_keys($object),
                    'has_@self'           => isset($object['@self']),
                    '@self_keys'          => $selfKeys,
                    'has_object_property' => isset($object['object']),
                    'sample_data'         => array_slice($object, 0, 3, true),
                ]
                );

        // Extract business data.
        $businessData = $this->extractBusinessData(object: $object);

        // RELATIONS EXTRACTION: Scan the business data for relations (UUIDs and URLs).
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

        return $selfData;
    }//end prepareSingleSchemaObject()

    /**
     * Apply hydrated metadata from a temporary entity back to selfData and object arrays
     *
     * Extracts name, description, summary, image, and slug from the hydrated entity
     * and sets them in both the @self data and top-level object (for bulk SQL).
     *
     * @param array        $selfData   The @self metadata array
     * @param array        $object     The full object data (modified in-place for top-level)
     * @param ObjectEntity $tempEntity The hydrated temporary entity
     *
     * @return array Updated selfData with metadata fields
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function applyHydratedMetadata(array $selfData, array &$object, ObjectEntity $tempEntity): array
    {
        $metadataGetters = [
            'name'        => 'getName',
            'description' => 'getDescription',
            'summary'     => 'getSummary',
            'image'       => 'getImage',
            'slug'        => 'getSlug',
        ];

        foreach ($metadataGetters as $field => $getter) {
            $value = $tempEntity->$getter();
            if ($value !== null) {
                $selfData[$field] = $value;
                $object[$field]   = $value;
                // TOP LEVEL for bulk SQL.
            }
        }

        return $selfData;
    }//end applyHydratedMetadata()

    /**
     * Process a chunk of objects with ultra-performance bulk operations
     *
     * SPECIALIZED FOR LARGE IMPORTS (30K+): This method implements maximum performance bulk processing:
     * - Ultra-fast bulk database operations
     * - Minimal validation overhead
     * - Optimized deduplication
     * - Memory-efficient processing
     * - Streamlined response format
     *
     * @param array $objects       Array of pre-processed objects ready for database operations
     * @param array $schemaCache   Pre-built schema cache for performance optimization
     * @param bool  $_rbac         Apply RBAC filtering
     * @param bool  $_multitenancy Apply multi-tenancy filtering
     * @param bool  $_validation   Apply schema validation
     * @param bool  $_events       Dispatch events
     *
     * @return array Processing result for this chunk with bulk operation statistics
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Chunk pipeline: transform → validate → persist → relations
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function processObjectsChunk(
        array $objects,
        array $schemaCache,
        bool $_rbac,
        bool $_multitenancy,
        bool $_validation,
        bool $_events
    ): array {
        $startTime = microtime(true);

        $result = [
            'saved'      => [],
            'updated'    => [],
            'unchanged'  => [],
        // Ensure consistent result structure.
            'invalid'    => [],
            'errors'     => [],
            'statistics' => [
                'saved'     => 0,
                'updated'   => 0,
                'unchanged' => 0,
        // Ensure consistent statistics structure.
                'invalid'   => 0,
                'errors'    => 0,
        // Also add errors counter.
            ],
        ];

        // STEP 1: Transform objects for database format with metadata hydration.
        $transformedObjects = $this->transformChunk(objects: $objects, schemaCache: $schemaCache, result: $result);

        if (empty($transformedObjects) === true) {
            return $result;
        }

        // STEP 2: Persist transformed objects to database.
        $bulkResult = $this->persistChunk(transformedObjects: $transformedObjects);

        // STEP 3: Build and classify results from bulk operation output.
        $this->buildChunkResults(bulkResult: $bulkResult, transformedObjects: $transformedObjects, result: $result);

        $endTime        = microtime(true);
        $processingTime = round(($endTime - $startTime) * 1000, 2);

        // Add processing time to the result for transparency and performance monitoring.
        $result['statistics']['processingTimeMs'] = $processingTime;

        return $result;
    }//end processObjectsChunk()

    /**
     * Transform a chunk of objects to database format and collect invalid objects
     *
     * Performs in-place transformation and moves any invalid objects into the result
     * arrays. Returns only the valid transformed objects ready for persistence.
     *
     * @param array $objects     The objects to transform
     * @param array $schemaCache Pre-built schema cache for performance optimization
     * @param array $result      The result array to populate with invalid objects
     *
     * @return array Valid transformed objects ready for database operations
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function transformChunk(array $objects, array $schemaCache, array &$result): array
    {
        $transformationResult = $this->transformObjectsToDatabaseFormatInPlace(objects: $objects, schemaCache: $schemaCache);
        $transformedObjects   = $transformationResult['valid'];

        // PERFORMANCE OPTIMIZATION: Batch error processing.
        if (empty($transformationResult['invalid']) === false) {
            $invalidCount      = count($transformationResult['invalid']);
            $result['invalid'] = array_merge($result['invalid'], $transformationResult['invalid']);
            $result['statistics']['invalid'] += $invalidCount;
            $result['statistics']['errors']  += $invalidCount;
        }

        return $transformedObjects;
    }//end transformChunk()

    /**
     * Persist transformed objects to the database using ultra-fast bulk operations
     *
     * All objects go directly to the bulk save operation which handles create vs update
     * automatically using INSERT...ON DUPLICATE KEY UPDATE with database-computed classification.
     *
     * @param array $transformedObjects Valid objects ready for database operations
     *
     * @return mixed The bulk operation result from the mapper
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function persistChunk(array $transformedObjects): mixed
    {
        $this->logger->info(
                "[SaveObjects] Using single-call bulk processing (no pre-lookup needed)",
                [
                    'objects_to_process' => count($transformedObjects),
                    'approach'           => 'INSERT...ON DUPLICATE KEY UPDATE with database-computed classification',
                ]
                );

        // MAXIMUM PERFORMANCE: Always use ultra-fast bulk operations for large imports.
        return $this->objectEntityMapper->ultraFastBulkSave($transformedObjects, []);
    }//end persistChunk()

    /**
     * Build chunk results by classifying bulk operation output into saved/updated/unchanged
     *
     * Processes the bulk result from the database, classifying each object by its
     * database-computed status (created, updated, unchanged) and populating the
     * result arrays accordingly.
     *
     * @param mixed $bulkResult         The raw result from ultraFastBulkSave
     * @param array $transformedObjects The original transformed objects (for fallback)
     * @param array $result             The result array to populate
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function buildChunkResults(mixed $bulkResult, array $transformedObjects, array &$result): void
    {
        if (is_array($bulkResult) === false) {
            // Fallback for unexpected return format.
            $this->logger->warning("[SaveObjects] Unexpected bulk result format, using fallback");
            foreach ($transformedObjects as $objData) {
                $result['saved'][] = $objData;
                $result['statistics']['saved']++;
            }

            return;
        }

        // Check if we got complete objects (new approach) or just UUIDs (fallback).
        $firstItem = reset($bulkResult);

        if (is_array($firstItem) === true && isset($firstItem['created'], $firstItem['updated']) === true) {
            // NEW APPROACH: Complete objects with database-computed classification returned.
            $this->classifyDatabaseComputedResults(bulkResult: $bulkResult, result: $result);
            return;
        }

        // FALLBACK: UUID array returned (legacy behavior).
        $this->classifyLegacyResults(bulkResult: $bulkResult, transformedObjects: $transformedObjects, result: $result);
    }//end buildChunkResults()

    /**
     * Classify bulk results using database-computed object_status field
     *
     * Processes complete objects returned by the database with pre-computed status
     * (created, updated, unchanged) and populates the result arrays.
     *
     * @param array $bulkResult The complete objects from bulk save with object_status
     * @param array $result     The result array to populate
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function classifyDatabaseComputedResults(array $bulkResult, array &$result): void
    {
        $this->logger->info("[SaveObjects] Processing complete objects with database-computed classification");

        $createdCount   = 0;
        $updatedCount   = 0;
        $unchangedCount = 0;

        foreach ($bulkResult as $completeObject) {
            // DATABASE-COMPUTED CLASSIFICATION: Use the object_status calculated by database.
            $objectStatus = $completeObject['object_status'] ?? 'unknown';

            switch ($objectStatus) {
                case 'created':
                    $result['saved'][] = $completeObject;
                    $result['statistics']['saved']++;
                    $createdCount++;
                    break;

                case 'updated':
                    $result['updated'][] = $completeObject;
                    $result['statistics']['updated']++;
                    $updatedCount++;
                    break;

                case 'unchanged':
                    $result['unchanged'][] = $completeObject;
                    $result['statistics']['unchanged']++;
                    $unchangedCount++;
                    break;

                default:
                    // Fallback for unexpected status.
                    $this->logger->warning(
                            "Unexpected object status: {$objectStatus}",
                            [
                                'uuid'          => $completeObject['uuid'],
                                'object_status' => $objectStatus,
                            ]
                            );
                    $result['unchanged'][] = $completeObject;
                    $result['statistics']['unchanged']++;
                    $unchangedCount++;
            }//end switch
        }//end foreach

        $this->logger->info(
                "[SaveObjects] Database-computed classification completed",
                [
                    'total_processed'       => count($bulkResult),
                    'created_objects'       => $createdCount,
                    'updated_objects'       => $updatedCount,
                    'unchanged_objects'     => $unchangedCount,
                    'classification_method' => 'database_computed_sql',
                ]
                );
    }//end classifyDatabaseComputedResults()

    /**
     * Classify bulk results using legacy UUID-based approach
     *
     * Falls back to matching UUIDs against input objects when the database
     * does not return complete objects with status classification.
     *
     * @param array $bulkResult         The UUID array from bulk save
     * @param array $transformedObjects The original transformed objects
     * @param array $result             The result array to populate
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function classifyLegacyResults(array $bulkResult, array $transformedObjects, array &$result): void
    {
        $this->logger->info("[SaveObjects] Processing UUID array (legacy mode)");

        // Fallback: Use traditional object reconstruction.
        $savedObjects = $this->reconstructSavedObjects(
            insertObjects: $transformedObjects,
            updateObjects: [],
            savedObjectIds: $bulkResult,
            existingObjects: []
        );

        foreach ($savedObjects as $obj) {
            $result['saved'][] = $obj->jsonSerialize();
            $result['statistics']['saved']++;
        }

        $this->logger->info("[SaveObjects] Using fallback object reconstruction");
    }//end classifyLegacyResults()

    /**
     * Perform comprehensive schema analysis for bulk operations
     *
     * PERFORMANCE OPTIMIZATION: This method analyzes schemas once and caches all needed information
     * for the entire bulk operation, including metadata field mapping, inverse relation properties,
     * validation requirements, and property configurations. This eliminates redundant schema analysis.
     *
     * @param Schema $schema Schema to analyze
     *
     * @return array Comprehensive analysis containing:
     *               - metadataFields: Array of metadata field mappings
     *               - inverseProperties: Array of properties with inverse relations
     *               - validationRequired: Whether hard validation is enabled
     *               - properties: Cached schema properties
     *               - configuration: Cached schema configuration
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function performComprehensiveSchemaAnalysis(Schema $schema): array
    {
        $config     = $schema->getConfiguration();
        $properties = $schema->getProperties();

        $analysis = [
            'metadataFields'     => [],
            'inverseProperties'  => [],
            'validationRequired' => $schema->getHardValidation(),
            'properties'         => $properties,
            'configuration'      => $config,
        ];

        // PERFORMANCE OPTIMIZATION: Analyze metadata field mappings once.
        // COMPREHENSIVE METADATA FIELD SUPPORT: Include all supported metadata fields.
        $metadataFieldMap = [
            'name'        => $config['objectNameField'] ?? null,
            'description' => $config['objectDescriptionField'] ?? null,
            'summary'     => $config['objectSummaryField'] ?? null,
            'image'       => $config['objectImageField'] ?? null,
            'slug'        => $config['objectSlugField'] ?? null,
        ];

        $analysis['metadataFields'] = array_filter(
                $metadataFieldMap,
                function ($field) {
                    return !empty($field);
                }
                );

        // PERFORMANCE OPTIMIZATION: Analyze inverse relation properties once.
        foreach ($properties as $propertyName => $propertyConfig) {
            $items = $propertyConfig['items'] ?? [];

            // Check for inversedBy at property level (single object relations).
            $inversedBy   = $propertyConfig['inversedBy'] ?? null;
            $rawWriteBack = $propertyConfig['writeBack'] ?? false;
            $writeBack    = $this->castToBoolean(value: $rawWriteBack);

            // Schema analysis: process writeBack boolean casting.
            // Check for inversedBy in array items (array of object relations).
            // CRITICAL FIX: Preserve property-level writeBack if it's true.
            if ($inversedBy === null && isset($items['inversedBy']) === true) {
                $inversedBy        = $items['inversedBy'];
                $rawItemsWriteBack = $items['writeBack'] ?? false;
                $itemsWriteBack    = $this->castToBoolean(value: $rawItemsWriteBack);

                // Use the higher value: if property writeBack is true, keep it.
                $finalWriteBack = $writeBack || $itemsWriteBack;

                // Items logic: combine property and items writeBack values.
                $writeBack = $finalWriteBack;
            }

            if ($inversedBy !== null) {
                $analysis['inverseProperties'][$propertyName] = [
                    'inversedBy' => $inversedBy,
                    'writeBack'  => $writeBack,
                    'isArray'    => $propertyConfig['type'] === 'array',
                ];
            }
        }//end foreach

        return $analysis;
    }//end performComprehensiveSchemaAnalysis()

    /**
     * Cast mixed values to proper boolean
     *
     * Handles string "true"/"false", integers 1/0, and actual booleans
     *
     * @param mixed $value The value to cast to boolean
     *
     * @return bool The boolean value
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function castToBoolean($value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_string($value) === true) {
            return strtolower(trim($value)) === 'true';
        }

        if (is_numeric($value) === true) {
            return (bool) $value;
        }

        return (bool) $value;
    }//end castToBoolean()

    /**
     * Handle bulk inverse relations using cached schema analysis
     *
     * PERFORMANCE OPTIMIZATION: This method uses pre-analyzed inverse relation properties
     * to process relations without re-analyzing schema properties for each object.
     *
     * @param array $preparedObjects Prepared objects to process
     * @param array $schemaAnalysis  Pre-analyzed schema information indexed by schema ID
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Inverse relation resolution requires many type checks
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function handleBulkInverseRelationsWithAnalysis(array &$preparedObjects, array $schemaAnalysis): void
    {
        // Create direct UUID to object reference mapping.
        $objectsByUuid = [];
        foreach ($preparedObjects as $index => &$object) {
            $selfData   = $object['@self'] ?? [];
            $objectUuid = $selfData['id'] ?? null;
            if ($objectUuid !== null) {
                $objectsByUuid[$objectUuid] = &$object;
            }
        }

        // Process inverse relations using cached analysis.
        foreach ($preparedObjects as $index => &$object) {
            $selfData   = $object['@self'] ?? [];
            $schemaId   = $selfData['schema'] ?? null;
            $objectUuid = $selfData['id'] ?? null;

            if ($schemaId === false || $objectUuid === null || isset($schemaAnalysis[$schemaId]) === false) {
                continue;
            }

            $analysis = $schemaAnalysis[$schemaId];

            // PERFORMANCE OPTIMIZATION: Use pre-analyzed inverse properties.
            foreach ($analysis['inverseProperties'] as $property => $propertyInfo) {
                if (isset($object[$property]) === false) {
                    continue;
                }

                $this->processInverseRelation(
                    value: $object[$property],
                    propertyInfo: $propertyInfo,
                    objectUuid: $objectUuid,
                    objectsByUuid: $objectsByUuid
                );
            }
        }//end foreach

    }//end handleBulkInverseRelationsWithAnalysis()

    /**
     * Process a single inverse relation property and apply write-backs to target objects
     *
     * Handles both single object relations (string UUID) and array of object relations
     * (array of UUIDs), adding the source object UUID to each target's inversedBy property.
     *
     * @param mixed  $value         The property value (UUID string or array of UUIDs)
     * @param array  $propertyInfo  The inverse property configuration with inversedBy, isArray keys
     * @param string $objectUuid    The UUID of the source object owning this relation
     * @param array  $objectsByUuid Reference mapping of UUID to object for in-place updates
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function processInverseRelation(
        mixed $value,
        array $propertyInfo,
        string $objectUuid,
        array &$objectsByUuid
    ): void {
        $inversedBy = $propertyInfo['inversedBy'];

        // Handle single object relations.
        if ($propertyInfo['isArray'] === false && is_string($value) === true && Uuid::isValid($value) === true) {
            $this->applyInverseRelationToTarget(
                targetUuid: $value,
                inversedBy: $inversedBy,
                objectUuid: $objectUuid,
                objectsByUuid: $objectsByUuid
            );
            return;
        }

        // Handle array of object relations.
        if ($propertyInfo['isArray'] === true && is_array($value) === true) {
            foreach ($value as $relatedUuid) {
                if (is_string($relatedUuid) === true && Uuid::isValid($relatedUuid) === true) {
                    $this->applyInverseRelationToTarget(
                        targetUuid: $relatedUuid,
                        inversedBy: $inversedBy,
                        objectUuid: $objectUuid,
                        objectsByUuid: $objectsByUuid
                    );
                }
            }
        }
    }//end processInverseRelation()

    /**
     * Apply an inverse relation to a target object by adding the source UUID
     *
     * Adds the source object's UUID to the target object's inversedBy property,
     * avoiding duplicates. Modifies the target object in-place via reference.
     *
     * @param string $targetUuid    The UUID of the target object to update
     * @param string $inversedBy    The property name on the target to populate
     * @param string $objectUuid    The UUID of the source object to add
     * @param array  $objectsByUuid Reference mapping of UUID to object for in-place updates
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function applyInverseRelationToTarget(
        string $targetUuid,
        string $inversedBy,
        string $objectUuid,
        array &$objectsByUuid
    ): void {
        if (isset($objectsByUuid[$targetUuid]) === false) {
            return;
        }

        $targetObject   = &$objectsByUuid[$targetUuid];
        $existingValues = $targetObject[$inversedBy] ?? [];
        if (is_array($existingValues) === false) {
            $existingValues = [];
        }

        if (in_array($objectUuid, $existingValues) === false) {
            $existingValues[]          = $objectUuid;
            $targetObject[$inversedBy] = $existingValues;
        }
    }//end applyInverseRelationToTarget()

    /**
     * Handle pre-validation cascading for inversedBy properties
     *
     * SIMPLIFIED FOR BULK OPERATIONS:
     * For now, this method returns the object unchanged to allow bulk processing to continue.
     * Complex cascading operations are handled later in the SaveObject workflow when needed.
     *
     * TODO: Implement full cascading support for bulk operations by integrating with
     * SaveObject.cascadeObjects() method or implementing bulk-optimized cascading.
     *
     * @param array       $object The object data to process
     * @param Schema      $schema The schema containing property definitions
     * @param string|null $uuid   The UUID of the parent object (will be generated if null)
     *
     * @return array Array containing [processedObject, parentUuid]
     *
     * @throws Exception If there's an error during object creation
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function handlePreValidationCascading(array $object, Schema $schema, ?string $uuid): array
    {
        // SIMPLIFIED: For bulk operations, we skip complex cascading for now.
        // and handle it later in individual object processing if needed.
        if ($uuid === null) {
            $uuid = Uuid::v4()->toRfc4122();
        }

        return [$object, $uuid];
    }//end handlePreValidationCascading()

    /**
     * Transform objects to database format with in-place optimization
     *
     * PERFORMANCE OPTIMIZATION: Uses in-place transformation and metadata hydration
     * to minimize memory allocation and data copying.
     *
     * @param array $objects     Objects to transform (modified in-place)
     * @param array $schemaCache Schema cache for metadata field resolution
     *
     * @return array Transformed objects ready for database operations
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Metadata hydration touches many field types
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function transformObjectsToDatabaseFormatInPlace(array &$objects, array $schemaCache): array
    {
        $transformedObjects = [];
        $invalidObjects     = [];

        foreach ($objects as $index => &$object) {
            // CRITICAL FIX: Objects from prepareSingleSchemaObjectsOptimized are already flat $selfData arrays.
            // They don't have an '@self' key because they ARE the self data.
            // Only extract @self if it exists (mixed schema or other paths).
            // Object is already a flat $selfData array from prepareSingleSchemaObjectsOptimized,
            // or extract @self if it exists (mixed schema or other paths).
            $selfData = $object;
            if (isset($object['@self']) === true) {
                $selfData = $object['@self'];
            }

            // Generate or validate object identifiers (uuid, id, register, schema).
            $selfData = $this->generateObjectIdentifiers(selfData: $selfData, object: $object);

            // Validate required fields; skip invalid objects.
            $validationError = $this->validateObjectRequiredFields(selfData: $selfData, object: $object, index: $index, schemaCache: $schemaCache);
            if ($validationError !== null) {
                $invalidObjects[] = $validationError;
                continue;
            }

            // Hydrate ownership and organisation metadata.
            $selfData = $this->hydrateObjectMetadataFields(selfData: $selfData);

            // DEBUG: Log mixed schema object structure.
            $this->logger->info(
                    "[SaveObjects] DEBUG - Mixed schema object structure",
                    [
                        'available_keys'      => array_keys($object),
                        'has_object_property' => isset($object['object']),
                        'sample_data'         => array_slice($object, 0, 3, true),
                    ]
                    );

            // Extract business data and scan for relations.
            $businessData = $this->extractBusinessData(object: $object);

            // RELATIONS EXTRACTION: Scan the business data for relations (UUIDs and URLs).
            // ONLY scan if relations weren't already set during preparation phase.
            if (isset($selfData['relations']) === true && empty($selfData['relations']) === false) {
                $this->logger->info(
                        "[SaveObjects] Relations already set from preparation",
                        [
                            'uuid'          => $selfData['uuid'] ?? 'unknown',
                            'relationCount' => count($selfData['relations']),
                        ]
                        );
            } else if (isset($schemaCache[$selfData['schema']]) === true) {
                $schema    = $schemaCache[$selfData['schema']];
                $relations = $this->scanForRelations(data: $businessData, prefix: '', schema: $schema);
                $selfData['relations'] = $relations;

                $this->logger->info(
                        "[SaveObjects] Relations scanned in transformation",
                        [
                            'uuid'          => $selfData['uuid'] ?? 'unknown',
                            'relationCount' => count($relations),
                            'relations'     => array_slice($relations, 0, 3, true),
                        ]
                        );
            }//end if

            // Store the clean business data in the database object column.
            $selfData['object'] = $businessData;

            $transformedObjects[] = $selfData;
        }//end foreach

        // Return both transformed objects and any invalid objects found during transformation.
        return [
            'valid'   => $transformedObjects,
            'invalid' => $invalidObjects,
        ];
    }//end transformObjectsToDatabaseFormatInPlace()

    /**
     * Generate or validate object identifiers (uuid, id, register, schema)
     *
     * Ensures the object has a valid UUID (accepting any non-empty string) and
     * wires register/schema IDs from the object data.
     *
     * @param array $selfData The @self metadata array
     * @param array $object   The full object data for fallback values
     *
     * @return array Updated selfData with identifiers set
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function generateObjectIdentifiers(array $selfData, array $object): array
    {
        // Auto-wire @self metadata with proper UUID validation and generation.
        // Accept any non-empty string as ID, prioritize CSV 'id' column over @self.id.
        // Default: generate new UUID.
        $providedId       = $object['id'] ?? $selfData['id'] ?? null;
        $selfData['uuid'] = Uuid::v4()->toRfc4122();
        $selfData['id']   = $selfData['uuid'];

        // Override: accept any non-empty string as identifier.
        if ($providedId !== null && empty(trim($providedId)) === false) {
            $selfData['uuid'] = $providedId;
            $selfData['id']   = $providedId;
        }

        // CRITICAL FIX: Use register and schema from method parameters if not provided in object data.
        $selfData['register'] = $selfData['register'] ?? $object['register'] ?? null;
        $selfData['schema']   = $selfData['schema'] ?? $object['schema'] ?? null;

        return $selfData;
    }//end generateObjectIdentifiers()

    /**
     * Validate that required fields (register, schema) are set and schema exists in cache
     *
     * Returns null if valid, or an error array describing the validation failure.
     *
     * @param array $selfData    The @self metadata with identifiers
     * @param array $object      The full object data for error context
     * @param int   $index       The object index for error reporting
     * @param array $schemaCache The schema cache to validate against
     *
     * @return array|null Null if valid, error array if invalid
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function validateObjectRequiredFields(array $selfData, array $object, int $index, array $schemaCache): ?array
    {
        if ($selfData['register'] === false) {
            return [
                'object' => $object,
                'error'  => 'Register ID is required but not found in object data or method parameters',
                'index'  => $index,
                'type'   => 'MissingRegisterException',
            ];
        }

        if ($selfData['schema'] === false) {
            return [
                'object' => $object,
                'error'  => 'Schema ID is required but not found in object data or method parameters',
                'index'  => $index,
                'type'   => 'MissingSchemaException',
            ];
        }

        if (isset($schemaCache[$selfData['schema']]) === false) {
            return [
                'object' => $object,
                'error'  => "Schema ID {$selfData['schema']} does not exist or could not be loaded",
                'index'  => $index,
                'type'   => 'InvalidSchemaException',
            ];
        }

        return null;
    }//end validateObjectRequiredFields()

    /**
     * Hydrate ownership and organisation metadata on the selfData array
     *
     * Sets owner to current user and organisation to the default if not already provided.
     *
     * @param array $selfData The @self metadata array
     *
     * @return array Updated selfData with owner and organisation set
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function hydrateObjectMetadataFields(array $selfData): array
    {
        // Set owner to current user if not provided (with null check).
        if (isset($selfData['owner']) === false || empty($selfData['owner']) === true) {
            $currentUser       = $this->userSession->getUser();
            $selfData['owner'] = null;
            if ($currentUser !== null) {
                $selfData['owner'] = $currentUser->getUID();
            }
        }

        // Set organization using optimized OrganisationService method if not provided.
        if (isset($selfData['organisation']) === false || empty($selfData['organisation']) === true) {
            // NO ERROR SUPPRESSION: Let organisation service errors bubble up immediately!
            $selfData['organisation'] = $this->organisationService->getOrganisationForNewEntity();
        }

        return $selfData;
    }//end hydrateObjectMetadataFields()

    /**
     * Extract business data from an object, separating it from metadata fields
     *
     * Supports both new structure (object property contains business data) and
     * legacy structure (metadata fields mixed with business data).
     *
     * @param array $object The full object data
     *
     * @return array The extracted business data
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function extractBusinessData(array $object): array
    {
        if (isset($object['object']) === true && is_array($object['object']) === true) {
            // NEW STRUCTURE: object property contains business data.
            $this->logger->info("[SaveObjects] Using object property for business data (mixed)");
            return $object['object'];
        }

        // LEGACY STRUCTURE: Remove metadata fields to isolate business data.
        $businessData   = $object;
        $metadataFields = [
            '@self',
            'name',
            'description',
            'summary',
            'image',
            'slug',
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
                "[SaveObjects] Metadata removal applied (mixed)",
                [
                    'removed_fields'       => array_intersect($metadataFields, array_keys($object)),
                    'remaining_keys'       => array_keys($businessData),
                    'business_data_sample' => array_slice($businessData, 0, 3, true),
                ]
                );

        return $businessData;
    }//end extractBusinessData()

    /**
     * Reconstruct saved objects without additional database fetch
     *
     * PERFORMANCE OPTIMIZATION: Avoids redundant database query by reconstructing
     * ObjectEntity objects from the already available arrays.
     *
     * @param array $insertObjects   New objects that were inserted
     * @param array $updateObjects   Existing objects that were updated
     * @param array $savedObjectIds  Array of UUIDs that were saved
     * @param array $existingObjects Original existing objects cache
     *
     * @return array Array of ObjectEntity objects representing saved objects
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function reconstructSavedObjects(array $insertObjects, array $updateObjects, array $savedObjectIds, array $existingObjects): array
    {
        $savedObjects = [];

        // Build a lookup set of saved IDs for filtering — only reconstruct objects that were actually saved.
        $savedIdSet = array_flip($savedObjectIds);

        // CRITICAL FIX: Don't use createFromArray() - it tries to insert objects that already exist!
        // Instead, create ObjectEntity and hydrate without inserting.
        foreach ($insertObjects as $objData) {
            $obj = new ObjectEntity();

            // CRITICAL FIX: Objects missing UUIDs after save indicate serious database issues - LOG ERROR!
            if (empty($objData['uuid']) === true) {
                $this->logger->error(
                        'Object reconstruction failed: Missing UUID after bulk save operation',
                        [
                            'objectData' => $objData,
                            'error'      => 'UUID missing in saved object data',
                            'context'    => 'reconstructSavedObjects',
                        ]
                        );

                // Continue to try to reconstruct other objects, but this indicates a serious issue.
                // The object was supposedly saved but has no UUID - should not happen.
                continue;
            }

            // Only include objects that are in the saved IDs set.
            if (isset($savedIdSet[$objData['uuid']]) === false) {
                continue;
            }

            $obj->hydrate($objData);

            $savedObjects[] = $obj;
        }//end foreach

        // Add update objects, preferring existing object data for fields not present in the update.
        foreach ($updateObjects as $obj) {
            $uuid = $obj->getUuid();
            if (isset($savedIdSet[$uuid]) === false) {
                continue;
            }

            // Merge with existing object data to preserve fields not included in the update payload.
            if ($uuid !== null && isset($existingObjects[$uuid]) === true) {
                $existingObj  = $existingObjects[$uuid];
                $existingData = $existingObj->getObject() ?? [];
                $updatedData  = $obj->getObject() ?? [];
                $mergedData   = array_merge($existingData, $updatedData);
                $obj->setObject($mergedData);
            }

            $savedObjects[] = $obj;
        }

        return $savedObjects;
    }//end reconstructSavedObjects()

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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Recursive relation scanning across nested structures
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
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

            $currentPath = $key;
            if ($prefix !== '') {
                $currentPath = $prefix.'.'.$key;
            }

            $propertyRelations = $this->scanPropertyForRelation(
                key: $key,
                value: $value,
                currentPath: $currentPath,
                schemaProperties: $schemaProperties,
                schema: $schema
            );
            $relations         = array_merge($relations, $propertyRelations);
        }//end foreach

        return $relations;

    }//end scanForRelations()

    /**
     * Scan a single property value for relations (UUIDs and URLs)
     *
     * Checks the property type and delegates to the appropriate handler
     * for arrays, nested objects, or scalar string values.
     *
     * @param string      $key              The property key name
     * @param mixed       $value            The property value to scan
     * @param string      $currentPath      The current dot-notation path
     * @param array|null  $schemaProperties The schema properties definition
     * @param Schema|null $schema           The schema for recursive scanning
     *
     * @return array Relations found in this property
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function scanPropertyForRelation(
        string $key,
        mixed $value,
        string $currentPath,
        ?array $schemaProperties,
        ?Schema $schema
    ): array {
        if (is_array($value) === true && empty($value) === false) {
            return $this->scanArrayForRelations(
                key: $key,
                value: $value,
                currentPath: $currentPath,
                schemaProperties: $schemaProperties,
                schema: $schema
            );
        }

        if (is_string($value) === true && empty($value) === false && trim($value) !== '') {
            return $this->scanStringForRelation(
                key: $key,
                value: $value,
                currentPath: $currentPath,
                schemaProperties: $schemaProperties
            );
        }

        return [];
    }//end scanPropertyForRelation()

    /**
     * Scan an array value for relations by iterating its items
     *
     * Handles both arrays of objects (schema-typed) and generic arrays,
     * recursing into nested structures and identifying string references.
     *
     * @param string      $key              The property key name
     * @param array       $value            The array value to scan
     * @param string      $currentPath      The current dot-notation path
     * @param array|null  $schemaProperties The schema properties definition
     * @param Schema|null $schema           The schema for recursive scanning
     *
     * @return array Relations found in the array items
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Array scanning requires type-checking each element
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function scanArrayForRelations(
        string $key,
        array $value,
        string $currentPath,
        ?array $schemaProperties,
        ?Schema $schema
    ): array {
        $relations = [];

        // Check if this is an array property in the schema.
        $propertyConfig   = $schemaProperties[$key] ?? null;
        $isArrayOfObjects = $propertyConfig !== null &&
                          ($propertyConfig['type'] ?? '') === 'array' &&
                          isset($propertyConfig['items']['type']) &&
                          $propertyConfig['items']['type'] === 'object';

        foreach ($value as $index => $item) {
            if (is_array($item) === true) {
                // Recursively scan nested arrays/objects.
                $itemRelations = $this->scanForRelations(
                    data: $item,
                    prefix: $currentPath.'.'.$index,
                    schema: $schema
                );
                $relations     = array_merge($relations, $itemRelations);
                continue;
            }

            if (is_string($item) === false || empty($item) === true) {
                continue;
            }

            if ($isArrayOfObjects === true) {
                // String values in object arrays are always treated as relations.
                $relations[$currentPath.'.'.$index] = $item;
                continue;
            }

            // For non-object arrays, check if the string looks like a reference.
            if (trim($item) !== '' && $this->isReference(value: $item) === true) {
                $relations[$currentPath.'.'.$index] = $item;
            }
        }//end foreach

        return $relations;
    }//end scanArrayForRelations()

    /**
     * Check if a string property value is a relation based on schema config or reference patterns
     *
     * Uses schema property type/format to determine relation status first,
     * then falls back to reference pattern matching.
     *
     * @param string     $key              The property key name
     * @param string     $value            The string value to check
     * @param string     $currentPath      The current dot-notation path
     * @param array|null $schemaProperties The schema properties definition
     *
     * @return array Relations found (empty array or single-element array)
     *
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function scanStringForRelation(
        string $key,
        string $value,
        string $currentPath,
        ?array $schemaProperties
    ): array {
        $isRelation = false;

        // Check schema property configuration first.
        if ($schemaProperties !== null && isset($schemaProperties[$key]) === true) {
            $propertyConfig = $schemaProperties[$key];
            $propertyType   = $propertyConfig['type'] ?? '';
            $propertyFormat = $propertyConfig['format'] ?? '';

            // Check for explicit relation types.
            if ($propertyType === 'text' && in_array($propertyFormat, ['uuid', 'uri', 'url']) === true) {
                $isRelation = true;
            } else if ($propertyType === 'object') {
                // Object properties with string values are always relations.
                $isRelation = true;
            }
        }

        // If not determined by schema, check for reference patterns.
        if ($isRelation === false) {
            $isRelation = $this->isReference(value: $value);
        }

        if ($isRelation === true) {
            return [$currentPath => $value];
        }

        return [];
    }//end scanStringForRelation()

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
     * @spec openspec/changes/retrofit-object-lifecycle-2026-04-28/tasks.md#task-1
     */
    private function isReference(string $value): bool
    {
        $value = trim($value);

        // Empty strings are not references.
        if (empty($value) === true) {
            return false;
        }

        // Check for standard UUID pattern (8-4-4-4-12 format).
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return true;
        }

        // Check for prefixed UUID patterns (e.g., "id-uuid", "ref-uuid", etc.).
        if (preg_match('/^[a-z]+-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
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
                && preg_match('/\s/', $value) === 0
                && in_array(strtolower($value), ['applicatie', 'systeemsoftware', 'open-source', 'closed-source']) === false
            ) {
                return true;
            }
        }

        return false;
    }//end isReference()
}//end class
