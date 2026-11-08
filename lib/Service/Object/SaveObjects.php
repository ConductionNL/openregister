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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\Object\SaveObject\BulkObjectSlugs;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkChunkRun;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationRun;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationScanner;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectDefaults;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectOwnership;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkInverseRelations;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRowPropertyAuthorization;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkEventCandidate;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkSchemaAnalysis;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkSaveRun;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;
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
 * @spec openspec/specs/object-lifecycle/spec.md
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
class SaveObjects {
	/**
	 * Maximum number of audit entries materialised into AuditTrail entities
	 * and persisted per insertAuditTrails() call. Bounds peak memory: a save
	 * chunk can hold up to 20k objects, and each audit entity embeds the
	 * object's old+new payload diff.
	 *
	 * @var int
	 */
	private const AUDIT_INSERT_CHUNK_SIZE = 100;

	/**
	 * The safeguard action of a row whose stored row could not be resolved.
	 *
	 * @var string
	 */
	private const UNRESOLVED_ACTION = 'unresolved';

	/**
	 * Envelope keys that are never business data, whatever a schema declares.
	 *
	 * These identify or locate the object rather than describe it, so a schema
	 * property of the same name would be a collision with the storage envelope
	 * itself and is not something this method can honour.
	 *
	 * @var string[]
	 */
	private const STRUCTURAL_METADATA_FIELDS = [
		'@self',
		'register',
		'schema',
		'organisation',
		'uuid',
		'owner',
		'created',
		'updated',
		'id',
	];

	/**
	 * Magic-table display columns that are ALSO legal schema property names.
	 *
	 * `name`, `description`, `summary`, `image` and `slug` each have a column on
	 * the magic table, and are among the most ordinary property names a schema
	 * can declare. When the schema declares one, the incoming value is business
	 * data and must survive; only an undeclared one is metadata.
	 *
	 * @var string[]
	 */
	private const OVERLAPPING_METADATA_FIELDS = [
		'name',
		'description',
		'summary',
		'image',
		'slug',
	];

	/**
	 * The metadata fields a hydrated entity carries back into a bulk row, with their getters.
	 *
	 * @var array<string, string>
	 */
	private const HYDRATED_METADATA_GETTERS = [
		'name' => 'getName',
		'description' => 'getDescription',
		'summary' => 'getSummary',
		'image' => 'getImage',
		'slug' => 'getSlug',
	];

	/**
	 * The result bucket of each database-computed row status.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_BUCKETS = ['created' => 'saved', 'updated' => 'updated', 'unchanged' => 'unchanged'];

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
	 * The bulk slug pass (#4037), built on first use.
	 *
	 * @var BulkObjectSlugs|null
	 */
	private ?BulkObjectSlugs $bulkSlugs = null;

	/**
	 * Mapper for object entity database operations
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objectEntityMapper;

	/**
	 * Mapper for schema operations
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Mapper for register operations
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Handler for individual object operations
	 *
	 * @var SaveObjectInterface
	 */
	private readonly SaveObjectInterface $saveHandler;

	/**
	 * User session for getting current user
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Service for organisation operations
	 *
	 * @var OrganisationServiceInterface
	 */
	private readonly OrganisationServiceInterface $organisationService;

	/**
	 * Logger for error and debug logging
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Group manager for admin-bypass detection; also backs the
	 * wave-12 `_rbac` / `_validation` reserved-keys policy
	 *
	 * @var IGroupManager|null
	 */
	private readonly ?IGroupManager $groupManager;

	/**
	 * Permission handler for per-object RBAC enforcement;
	 * wave-12 Fix 3 per-row RBAC + appendOnly gate
	 *
	 * @var PermissionHandlerInterface|null
	 */
	private readonly ?PermissionHandlerInterface $permissionHandler;

	/**
	 * Validation handler for per-object schema validation;
	 * wave-12 Fix 3 per-row JSON-Schema validation
	 *
	 * @var ValidateObjectInterface|null
	 */
	private readonly ?ValidateObjectInterface $validateHandler;

	/**
	 * Event dispatcher for object lifecycle events
	 *
	 * @var IEventDispatcher|null
	 */
	private readonly ?IEventDispatcher $eventDispatcher;

	/**
	 * Audit trail mapper for logging bulk changes
	 *
	 * @var AuditTrailMapperInterface|null
	 */
	private readonly ?AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * The decision-table rules, property authorization and save events a
	 * bulk row goes through. Null only in legacy fixtures.
	 *
	 * @var BulkRowRules|null
	 */
	private readonly ?BulkRowRules $rowRules;

	/**
	 * Constructor for SaveObjects handler
	 *
	 * @param BulkSaveStores    $stores    Reads the registers and schemas and writes the rows.
	 * @param BulkSaveSession   $session   The user, organisation and groups the save runs for.
	 * @param BulkSaveReporting $reporting Logs, dispatches the lifecycle events and records the audit trail.
	 * @param BulkRowChecks     $checks    The per-row access, validation and rule checks.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(BulkSaveStores $stores, BulkSaveSession $session, BulkSaveReporting $reporting, BulkRowChecks $checks) {
		$this->objectEntityMapper = $stores->objectEntityMapper;
		$this->schemaMapper = $stores->schemaMapper;
		$this->registerMapper = $stores->registerMapper;
		$this->saveHandler = $stores->saveHandler;
		$this->userSession = $session->userSession;
		$this->organisationService = $session->organisationService;
		$this->groupManager = $session->groupManager;
		$this->logger = $reporting->logger;
		$this->eventDispatcher = $reporting->eventDispatcher;
		$this->auditTrailMapper = $reporting->auditTrailMapper;
		$this->permissionHandler = $checks->permissionHandler;
		$this->validateHandler = $checks->validateHandler;
		$this->rowRules = $checks->rowRules;
	}//end __construct()

	/**
	 * Load schema from cache or database with performance optimization
	 *
	 * @param int|string $schemaId Schema ID to load
	 *
	 * @return Schema The loaded schema
	 * @throws Exception If schema cannot be found
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function loadSchemaWithCache(int|string $schemaId): Schema {
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
	 * The slug rules for a prepared batch (#4037), built once.
	 *
	 * @return BulkObjectSlugs The bulk slug pass.
	 */
	private function bulkSlugs(): BulkObjectSlugs {
		$this->bulkSlugs ??= new BulkObjectSlugs(
			slugHandler: $this->saveHandler->getSlugHandler(),
			registerMapper: $this->registerMapper,
			logger: $this->logger,
			schemaOf: fn (string $schemaId): Schema => $this->loadSchemaWithCache(schemaId: $schemaId)
		);

		return $this->bulkSlugs;
	}//end bulkSlugs()





	/**
	 * Get comprehensive schema analysis from cache or generate new analysis
	 *
	 * @param Schema $schema Schema to analyze
	 *
	 * @return array Comprehensive schema analysis
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function getSchemaAnalysisWithCache(Schema $schema): array {
		$schemaId = $schema->getId();

		// Check static cache first.
		if (isset(self::$schemaAnalysisCache[$schemaId]) === true) {
			return self::$schemaAnalysisCache[$schemaId];
		}

		// Generate analysis and cache.
		$analysis = (new BulkSchemaAnalysis())->analyse(schema: $schema);
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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function loadRegisterWithCache(int|string $registerId): Register {
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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function clearSchemaCache(): void {
		self::$schemaCache = [];
		self::$schemaAnalysisCache = [];
		self::$registerCache = [];
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
	 * @param array $objects Array of objects in serialized format
	 * @param Register|string|int|null $register Optional register context
	 * @param Schema|string|int|null $schema Optional schema context
	 * @param bool $_rbac Whether to apply RBAC filtering
	 * @param bool $_multitenancy Whether to apply multi-organization filtering
	 * @param bool $_validation Whether to validate objects against schema definitions
	 * @param bool $_events Whether to dispatch object lifecycle events
	 * @param bool $deduplicateIds Whether to deduplicate by IDs
	 * @param bool $enrich Whether to enrich objects
	 * @param bool $_audit Whether to write audit trail rows for the persisted objects
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
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every argument after $schema is a per-call
	 *   policy switch that the single-object saveObject() also takes, and the two paths have to
	 *   offer the same switches or a caller cannot move between them — which is exactly what the
	 *   `$_audit` flag exists for. Collapsing them into an options object is worth doing, but it
	 *   is a change to BOTH save paths and all their callers, not to this signature alone.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function saveObjects(
		array $objects,
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_validation = false,
		bool $_events = false,
		bool $deduplicateIds = false,
		bool $enrich = false,
		bool $_audit = true,
	): array {
		$run = $this->openBulkSaveRun(totalObjects: count($objects));
		if (empty($objects) === true) {
			return $run->result;
		}

		$run->register     = $register;
		$run->schema       = $schema;
		$run->rbac         = $_rbac;
		$run->multitenancy = $_multitenancy;
		$run->validation   = $_validation;
		$run->events       = $_events;
		$run->audit        = $_audit;

		return $this->runBulkSave(run: $run, objects: $objects);
	}//end saveObjects()

	/**
	 * A bulk save call, started now, with its empty result.
	 *
	 * @param int $totalObjects The number of rows the caller passed.
	 *
	 * @return BulkSaveRun
	 */
	private function openBulkSaveRun(int $totalObjects): BulkSaveRun {
		$run = new BulkSaveRun();
		$run->startTime    = microtime(true);
		$run->totalObjects = $totalObjects;
		$run->result       = $this->initializeSaveResult(totalObjects: $totalObjects);

		return $run;
	}//end openBulkSaveRun()

	/**
	 * Safeguard, prepare, slug and save the rows of a bulk save call in chunks.
	 *
	 * @param BulkSaveRun $run     The bulk save call.
	 * @param array       $objects The rows the caller passed (not empty).
	 *
	 * @return array The result.
	 */
	private function runBulkSave(BulkSaveRun $run, array $objects): array {
		$objects = $this->safeguardBulkObjects(run: $run, objects: $objects);
		if (empty($objects) === true) {
			return $this->allRejectedResult(run: $run);
		}

		$this->logBulkSaveStart(run: $run, count: count($objects));
		$processedObjects = $this->prepareBulkObjects(run: $run, objects: $objects);
		if (empty($processedObjects) === true) {
			return $this->nothingPreparedResult(run: $run);
		}

		// Update statistics to reflect actual processed objects.
		$run->result['statistics']['totalProcessed'] = count($processedObjects);
		$this->saveBulkChunks(run: $run, processedObjects: $processedObjects);

		return $this->finishBulkResult(run: $run, processedCount: count($processedObjects));
	}//end runBulkSave()

	/**
	 * The result of a run none of whose rows could be prepared.
	 *
	 * @param BulkSaveRun $run The bulk save call.
	 *
	 * @return array The result with the error added.
	 */
	private function nothingPreparedResult(BulkSaveRun $run): array {
		$run->result['errors'][] = [
			'error' => 'No objects were successfully prepared for bulk save',
			'type' => 'NoObjectsPreparedException',
		];

		return $run->result;
	}//end nothingPreparedResult()

	/**
	 * Decide the object slugs, then save the prepared rows chunk by chunk.
	 *
	 * Slugs are decided per register+schema group, in batch order, before
	 * anything is written: the same rules as a single save (#4037).
	 *
	 * @param BulkSaveRun $run              The bulk save call.
	 * @param array       $processedObjects The prepared rows.
	 *
	 * @return void
	 */
	private function saveBulkChunks(BulkSaveRun $run, array $processedObjects): void {
		$chunks = array_chunk(
			$this->bulkSlugs()->apply(objects: $processedObjects, register: $run->register, schema: $run->schema),
			$this->calculateOptimalChunkSize(totalObjects: count($processedObjects))
		);
		foreach ($chunks as $chunkIndex => $objectsChunk) {
			$run->chunkIndex = $chunkIndex;
			$this->saveBulkChunk(run: $run, chunk: $objectsChunk);
		}
	}//end saveBulkChunks()

	/**
	 * Apply the bulk-path safeguards to every row before the transform/upsert pipeline.
	 *
	 * Wave-12 Fix 3: per row, RBAC (default-secure), the `@self` strip of
	 * `owner`/`organisation`/`authorization` for non-admin callers, appendOnly
	 * on UPDATE rows, and the reserved `_rbac:false` / `_validation:false` keys
	 * honoured only for an admin. Rows with an invalid decision table are
	 * refused first. Refused rows land in the run's result.
	 *
	 * @param BulkSaveRun $run     The bulk save call.
	 * @param array       $objects The rows the caller passed.
	 *
	 * @return array The rows that passed.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function safeguardBulkObjects(BulkSaveRun $run, array $objects): array {
		$safeguardStart = microtime(true);
		$objects = $this->refuseInvalidDecisionTables(objects: $objects, register: $run->register, schema: $run->schema, result: $run->result);
		$flags   = ['rbac' => $run->rbac, 'validation' => $run->validation, 'multitenancy' => $run->multitenancy];
		$objects = $this->applyBulkSafeguards(objects: $objects, register: $run->register, schema: $run->schema, flags: $flags, result: $run->result);
		$run->safeguardMs = round((microtime(true) - $safeguardStart) * 1000, 2);

		return $objects;
	}//end safeguardBulkObjects()

	/**
	 * The result of a run whose every input row was rejected by the safeguards.
	 *
	 * @param BulkSaveRun $run The bulk save call.
	 *
	 * @return array The result with its performance block.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function allRejectedResult(BulkSaveRun $run): array {
		$totalTime = microtime(true) - $run->startTime;
		$run->result['performance'] = [
			'totalTime' => round($totalTime, 3),
			'totalTimeMs' => round($totalTime * 1000, 2),
			'objectsPerSecond' => 0.0,
			'totalProcessed' => 0,
			'totalRequested' => $run->totalObjects,
			'efficiency' => 0,
		];

		return $run->result;
	}//end allRejectedResult()

	/**
	 * Log the start of a large bulk save; small ones are not logged, to keep bulk overhead down.
	 *
	 * @param BulkSaveRun $run   The bulk save call.
	 * @param int         $count The number of rows that passed the safeguards.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function logBulkSaveStart(BulkSaveRun $run, int $count): void {
		$isMixedSchema = ($run->schema === null);
		if ($count <= 10000 && ($isMixedSchema === false || $count <= 1000)) {
			return;
		}

		$onLabel = 'Starting single-schema bulk save operation';
		$onType = 'single-schema';
		if ($isMixedSchema === true) {
			$onLabel = 'Starting mixed-schema bulk save operation';
			$onType = 'mixed-schema';
		}

		$this->logger->debug($onLabel, ['totalObjects' => $count, 'operation' => $onType]);
	}//end logBulkSaveStart()

	/**
	 * Prepare the rows for the bulk save, by the single-schema fast path or the mixed-schema path.
	 *
	 * Rows that fail preparation are recorded as invalid in the run's result.
	 *
	 * @param BulkSaveRun $run     The bulk save call.
	 * @param array       $objects The rows that passed the safeguards.
	 *
	 * @return array The prepared rows.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function prepareBulkObjects(BulkSaveRun $run, array $objects): array {
		$prepareStart = microtime(true);
		[$processedObjects, $run->schemaCache, $prepInvalidObjs] = $this->prepareByPath(run: $run, objects: $objects);

		$run->prepareMs = round((microtime(true) - $prepareStart) * 1000, 2);

		foreach ($prepInvalidObjs as $invalidObj) {
			$run->result['invalid'][] = $invalidObj;
			$run->result['statistics']['invalid']++;
			$run->result['statistics']['errors']++;
		}

		return $processedObjects;
	}//end prepareBulkObjects()

	/**
	 * Prepare rows by the single-schema fast path when the call names a schema, else by the mixed-schema path.
	 *
	 * @param BulkSaveRun $run     The bulk save call.
	 * @param array       $objects The rows that passed the safeguards.
	 *
	 * @return array The prepared rows, the schema cache and the rows that failed preparation.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function prepareByPath(BulkSaveRun $run, array $objects): array {
		if ($run->schema === null) {
			return $this->prepareObjectsForBulkSave(objects: $objects);
		}

		return $this->prepareSingleSchemaObjectsOptimized(
			objects: $objects,
			register: $run->register,
			schema: $run->schema
		);
	}//end prepareByPath()

	/**
	 * Save one chunk and merge its outcome, counts and timings into the run's result.
	 *
	 * The caller-supplied register and schema are forwarded so the chunk does
	 * not fall back to per-row `@self.register`/`@self.schema`, which may carry
	 * stale ids from the source instance when re-importing exported data.
	 *
	 * @param BulkSaveRun $run   The bulk save call.
	 * @param array       $chunk The chunk's objects.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function saveBulkChunk(BulkSaveRun $run, array $chunk): void {
		$chunkStart = microtime(true);
		$chunkResult = $this->processObjectsChunk(run: $run, objects: $chunk);
		$this->mergeChunkResult(run: $run, chunkResult: $chunkResult);
		$chunkTime = microtime(true) - $chunkStart;
		$run->result['chunkStatistics'][] = [
			'chunkIndex' => $run->chunkIndex,
			'count' => count($chunk),
			...self::chunkCounts(statistics: ($chunkResult['statistics'] ?? [])),
			// Milliseconds; objects per second; the per-phase breakdown in milliseconds.
			'processingTime' => round($chunkTime * 1000, 2),
			'speed' => round(count($chunk) / max($chunkTime, 0.001), 2),
			'phaseMs' => ($chunkResult['statistics']['phaseMs'] ?? []),
		];
	}//end saveBulkChunk()

	/**
	 * Append a chunk's rows to the run result and add its counters to the run statistics.
	 *
	 * @param BulkSaveRun $run         The run, whose result is changed.
	 * @param array       $chunkResult The chunk outcome from processObjectsChunk().
	 *
	 * @return void
	 */
	private function mergeChunkResult(BulkSaveRun $run, array $chunkResult): void {
		foreach (['saved', 'updated', 'invalid', 'errors', 'unchanged'] as $category) {
			$run->result[$category] = array_merge($run->result[$category], $chunkResult[$category]);
			$run->result['statistics'][$category] += $chunkResult['statistics'][$category] ?? 0;
		}
	}//end mergeChunkResult()

	/**
	 * The saved, updated, unchanged and invalid counts of one chunk, zero where absent.
	 *
	 * @param array $statistics The chunk statistics.
	 *
	 * @return array<string, int>
	 */
	private static function chunkCounts(array $statistics): array {
		$counts = [];
		foreach (['saved', 'updated', 'unchanged', 'invalid'] as $counter) {
			$counts[$counter] = ($statistics[$counter] ?? 0);
		}

		return $counts;
	}//end chunkCounts()

	/**
	 * Add the run's timing, speed and deduplication metrics to its result.
	 *
	 * @param BulkSaveRun $run            The bulk save call.
	 * @param int         $processedCount The number of prepared rows.
	 *
	 * @return array The finished result.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function finishBulkResult(BulkSaveRun $run, int $processedCount): array {
		$run->result['performance'] = $this->bulkPerformance(run: $run, processedCount: $processedCount);

		// Add deduplication efficiency if we have unchanged objects.
		$deduplication = self::deduplicationEfficiency(result: $run->result);
		if ($deduplication !== null) {
			$run->result['performance']['deduplicationEfficiency'] = $deduplication;
		}

		return $run->result;
	}//end finishBulkResult()

	/**
	 * The timing, speed and efficiency of a finished run.
	 *
	 * @param BulkSaveRun $run            The bulk save call.
	 * @param int         $processedCount The number of prepared rows.
	 *
	 * @return array<string, int|float>
	 */
	private function bulkPerformance(BulkSaveRun $run, int $processedCount): array {
		$totalTime = microtime(true) - $run->startTime;

		return [
			'totalTime' => round($totalTime, 3),
			'totalTimeMs' => round($totalTime * 1000, 2),
			'objectsPerSecond' => round($processedCount / max($totalTime, 0.001), 2),
			'totalProcessed' => $processedCount,
			'totalRequested' => $run->totalObjects,
			'efficiency' => self::preparedShare(processedCount: $processedCount, totalObjects: $run->totalObjects),
			'safeguardsMs' => $run->safeguardMs,
			'prepareMs' => $run->prepareMs,
		];
	}//end bulkPerformance()

	/**
	 * The percentage of the requested rows that were prepared; 0 when none were.
	 *
	 * @param int $processedCount The number of prepared rows.
	 * @param int $totalObjects   The number of requested rows.
	 *
	 * @return int|float
	 */
	private static function preparedShare(int $processedCount, int $totalObjects): int|float {
		if ($processedCount > 0) {
			return round(($processedCount / $totalObjects) * 100, 1);
		}

		return 0;
	}//end preparedShare()

	/**
	 * The share of operations the unchanged rows avoided, or null when no row was unchanged.
	 *
	 * @param array $result The run's result.
	 *
	 * @return string|null
	 */
	private static function deduplicationEfficiency(array $result): ?string {
		$unchangedCount = count($result['unchanged']);
		if ($unchangedCount === 0) {
			return null;
		}

		$totalProcessed = count($result['saved']) + count($result['updated']) + $unchangedCount;

		return round(($unchangedCount / $totalProcessed) * 100, 1) . '% operations avoided';
	}//end deduplicationEfficiency()

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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function initializeSaveResult(int $totalObjects): array {
		$result = $this->emptyChunkResult();
		$result['statistics'] = ['totalProcessed' => $totalObjects, ...$result['statistics'], 'processingTimeMs' => 0];

		return $result;
	}//end initializeSaveResult()

	/**
	 * Apply per-row bulk-path safeguards (Wave-12 Fix 3).
	 *
	 * Centralised gate that catches the bulk-path bypasses documented in
	 * `/tmp/wave11-or-engine-primitives.md` Section D + SB2 in
	 * `/tmp/wave11-openregister-report.md`. For each candidate object:
	 *
	 *  1. **@self stripping for non-admin callers.** Drop client-supplied
	 *     `owner`, `organisation`, `authorization` from both `@self` and the
	 *     top-level flat fields (the wave-9 single-object fix only stripped
	 *     `owner`/`authorization` on the SaveObject path; the bulk pipeline
	 *     skipped this entirely AND organisation was never stripped on either
	 *     path → cross-tenant injection vector). Admin callers may still set
	 *     these (e.g. import path attributing rows to original owner).
	 *
	 *  2. **Reserved-keys policy.** `_rbac: false` and `_validation: false`
	 *     are honoured only when the caller is admin. Non-admin attempts to
	 *     bypass RBAC or validation are silently ignored (flags reset to the
	 *     defaults that DO enforce).
	 *
	 *  3. **Per-row PermissionHandlerInterface check.** Resolve the row's schema +
	 *     register, derive the action (CREATE vs UPDATE by UUID presence),
	 *     and call `PermissionHandlerInterface::hasPermission`. Rows that fail are
	 *     moved to the `invalid` bucket with a structured error and excluded
	 *     from the downstream pipeline.
	 *
	 *  4. **appendOnly enforcement.** If a row targets an `appendOnly: true`
	 *     schema and resolves to an UPDATE (existing UUID), reject — mirrors
	 *     `ObjectService::saveObject:1154` for the single-object path.
	 *
	 *  5. **Opis JSON-Schema validation.** When the caller passes
	 *     `_validation: true`, run `ValidateObject::validateObject` per row;
	 *     failures go to the `invalid` bucket.
	 *
	 * The returned array contains only the rows that passed all gates.
	 * Rejected rows are accumulated in `$result['invalid']` and reflected in
	 * `$result['statistics']`.
	 *
	 * BC: when any of `PermissionHandlerInterface`, `ValidateObject`, or `IGroupManager`
	 * are NOT injected (legacy 7-arg constructor), each absent dependency
	 * silently skips that gate's enforcement. The default service container
	 * wiring DOES inject all three, so production callers get the full
	 * pipeline.
	 *
	 * @param array<int, array<string, mixed>> $objects Raw input objects.
	 * @param Register|string|int|null $register Default register context.
	 * @param Schema|string|int|null $schema Default schema context (null = mixed-schema).
	 * @param array{rbac: bool, validation: bool, multitenancy?: bool} $flags The caller-requested
	 *                                   RBAC, validation and multitenancy flags (admin may set rbac and multitenancy false).
	 * @param array $result Result accumulator (mutated in place: `invalid` + `statistics`).
	 *
	 * @return array<int, array<string, mixed>> Objects that passed every gate.
	 */
	private function applyBulkSafeguards(
		array $objects,
		Register|string|int|null $register,
		Schema|string|int|null $schema,
		array $flags,
		array &$result,
	): array {
		// No-op when dependencies are not wired (legacy constructor signature).
		if ($this->permissionHandler === null) {
			return $objects;
		}

		$run = $this->startBulkSafeguardRun(batch: ['objects' => $objects, 'register' => $register, 'schema' => $schema], flags: $flags);

		return $this->screenSafeguardBatch(objects: $objects, run: $run, result: $result);
	}//end applyBulkSafeguards()

	/**
	 * Every row of a batch through the gates, in order; a refused row lands in the result.
	 *
	 * @param array<int|string, array<string, mixed>> $objects The rows.
	 * @param BulkSafeguardRun                        $run     The safeguard pass.
	 * @param array                                   $result  Result accumulator (mutated in place).
	 *
	 * @return array<int, array<string, mixed>> The rows that passed.
	 */
	private function screenSafeguardBatch(array $objects, BulkSafeguardRun $run, array &$result): array {
		$passed = [];
		foreach ($objects as $index => $object) {
			[$row, $refusal] = $this->screenSafeguardRow(object: $object, run: $run);
			if ($refusal === null) {
				$passed[] = $row;
				continue;
			}

			$this->recordSafeguardRejection(object: $row, reason: $refusal, result: $result, index: self::batchPosition(index: $index));
		}

		return $passed;
	}//end screenSafeguardBatch()

	/**
	 * The caller's own array position of a row, which travels with every rejection (issue #2778).
	 *
	 * @param int|string $index The row's key in the submitted batch.
	 *
	 * @return int|null The position, or null for a string key.
	 */
	private static function batchPosition(int|string $index): ?int {
		if (is_int($index) === true) {
			return $index;
		}

		return null;
	}//end batchPosition()

	/**
	 * Start a safeguard pass: the caller, the effective flags, the batch defaults and the
	 * stored rows the batch's uuids name.
	 *
	 * `_rbac:false` is honoured only for an administrator (a trusted import, for instance);
	 * for anyone else RBAC stays on, whatever the payload says. `_validation` is honoured as
	 * passed: it is a defence-in-depth check authors opt in to.
	 *
	 * The stored rows are fetched for the whole batch in one query per register and schema.
	 * A group whose probe failed is absent from the map, and the row lookup falls back to
	 * its own query: an empty map means "not prefetched", never "nothing exists".
	 *
	 * @param array{objects: array, register: Register|string|int|null, schema: Schema|string|int|null} $batch The batch
	 * @param array{rbac: bool, validation: bool, multitenancy?: bool}                                 $flags The caller's flags
	 *
	 * @return BulkSafeguardRun
	 */
	private function startBulkSafeguardRun(array $batch, array $flags): BulkSafeguardRun {
		$run             = new BulkSafeguardRun(currentUser: $this->userSession->getUser());
		$run->isAdmin    = $this->safeguardCallerIsAdmin(run: $run);
		$run->rbac       = ($flags['rbac'] === true || $run->isAdmin === false);
		$run->validation = $flags['validation'];
		$run->multitenancy = (($flags['multitenancy'] ?? true) === true || $run->isAdmin === false);
		$this->resolveSafeguardDefaults(run: $run, defaults: ['register' => $batch['register'], 'schema' => $batch['schema']]);
		$run->existingByUuid = $this->prefetchExistingRows(objects: $batch['objects'], run: $run);

		return $run;
	}//end startBulkSafeguardRun()

	/**
	 * Whether the bulk caller is an administrator; false when the group lookup fails.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return bool
	 */
	private function safeguardCallerIsAdmin(BulkSafeguardRun $run): bool {
		if ($run->currentUser === null || $this->groupManager === null) {
			return false;
		}

		try {
			return $this->groupManager->isAdmin($run->currentUser->getUID());
		} catch (\Throwable $e) {
			return false;
		}
	}//end safeguardCallerIsAdmin()

	/**
	 * Resolve the batch's default schema and register once, before the row loop.
	 *
	 * "The caller named no schema" (a mixed-schema batch) and "the caller named a schema
	 * that could not be loaded" are kept apart. Both used to reach the loop as a bare
	 * null, and a row without a schema passes unchecked, so a transient failure in
	 * loadSchemaWithCache() skipped the RBAC gate for every row while the write went
	 * ahead: the fail-open shape of CWE-863.
	 *
	 * The register bounds the create-or-update lookup to one magic table. Without it
	 * every row searched every magic table (measured: 757 ms per row, 15 s of a 20-row
	 * save). A register that cannot be loaded only costs that bound:
	 * resolveSafeguardActionForRow() then falls back to the instance-wide lookup.
	 *
	 * @param BulkSafeguardRun                                                    $run      The safeguard pass
	 * @param array{register: Register|string|int|null, schema: Schema|string|int|null} $defaults The batch arguments
	 *
	 * @return void
	 */
	private function resolveSafeguardDefaults(BulkSafeguardRun $run, array $defaults): void {
		$this->resolveSafeguardDefaultSchema(run: $run, schema: $defaults['schema']);
		$this->resolveSafeguardDefaultRegister(run: $run, register: $defaults['register']);
	}//end resolveSafeguardDefaults()

	/**
	 * Load the batch's default schema; a schema that was named but cannot be loaded marks the pass unresolved.
	 *
	 * @param BulkSafeguardRun         $run    The safeguard pass
	 * @param Schema|string|int|null   $schema The bulk-call schema argument
	 *
	 * @return void
	 */
	private function resolveSafeguardDefaultSchema(BulkSafeguardRun $run, Schema|string|int|null $schema): void {
		if (self::namesBatchDefault(argument: $schema) === false) {
			return;
		}

		try {
			$run->defaultSchema = $this->loadSafeguardSchema(schema: $schema);
		} catch (\Throwable $e) {
			$run->schemaUnresolved = true;
		}
	}//end resolveSafeguardDefaultSchema()

	/**
	 * Load the batch's default register; one that cannot be loaded only loses the lookup bound.
	 *
	 * @param BulkSafeguardRun           $run      The safeguard pass
	 * @param Register|string|int|null   $register The bulk-call register argument
	 *
	 * @return void
	 */
	private function resolveSafeguardDefaultRegister(BulkSafeguardRun $run, Register|string|int|null $register): void {
		if (self::namesBatchDefault(argument: $register) === false) {
			return;
		}

		try {
			$run->defaultRegister = $this->loadSafeguardRegister(register: $register);
		} catch (\Throwable $e) {
			$run->defaultRegister = null;
		}
	}//end resolveSafeguardDefaultRegister()

	/**
	 * Whether a bulk-call schema or register argument names one: null, 0 and '0' name none.
	 *
	 * @param Register|Schema|string|int|null $argument The argument
	 *
	 * @return bool
	 */
	private static function namesBatchDefault(Register|Schema|string|int|null $argument): bool {
		return $argument !== null && $argument !== 0 && $argument !== '0';
	}//end namesBatchDefault()

	/**
	 * One row through the gates: the row as it stands and the refusal, or null when it passes.
	 *
	 * @param array            $object The row
	 * @param BulkSafeguardRun $run    The safeguard pass
	 *
	 * @return array{0: array, 1: string|null}
	 */
	private function screenSafeguardRow(array $object, BulkSafeguardRun $run): array {
		$row = $this->stripSelfInjectionFields(object: $object, isAdmin: $run->isAdmin);

		// A row naming two different identities is refused: the guard resolves "which row
		// is this" once, the writers with their own precedence, so two candidates would let
		// the guard see a create while the writer overwrites another user's draft.
		if ($this->rowIdentityIsAmbiguous(object: $row) === true) {
			return [$row, 'The row names more than one identity (id, @self.id, @self.uuid differ); send one.'];
		}

		$run->rowSchema = $this->resolveSafeguardRowSchema(object: $row, defaultSchema: $run->defaultSchema);
		if ($run->rowSchema === null) {
			return [$row, $this->schemalessRowRefusal(run: $run)];
		}

		$this->resolveSafeguardRowTarget(row: $row, run: $run);

		return $this->screenResolvedRow(row: $this->restoreRevealedValues(row: $row, run: $run), run: $run);
	}//end screenSafeguardRow()

	/**
	 * Resolve the register, identity, action and stored row of the current row onto the pass.
	 *
	 * @param array            $row The row, its schema already resolved onto the pass
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return void
	 */
	private function resolveSafeguardRowTarget(array $row, BulkSafeguardRun $run): void {
		$run->rowRegister = $this->resolveSafeguardRowRegister(object: $row, defaultRegister: $run->defaultRegister);
		$target           = $this->resolveSafeguardActionForRow(object: $row, run: $run);
		$run->rowUuid     = $target[0];
		$run->rowAction   = $target[1];
		$run->rowExisting = $target[2];
		$this->setAsideDeletedRow(run: $run);
	}//end resolveSafeguardRowTarget()

	/**
	 * The row with every explicit-reveal value it sends back as its mask set to the stored value.
	 *
	 * Done before validation, which would otherwise refuse the mask's shape.
	 *
	 * @param array            $row The row
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return array
	 */
	private function restoreRevealedValues(array $row, BulkSafeguardRun $run): array {
		if ($run->rowExisting === null) {
			return $row;
		}

		return ExplicitReveal::restoreMasked(schema: $run->rowSchema, incoming: $row, stored: ($run->rowExisting->getObject() ?? []));
	}//end restoreRevealedValues()

	/**
	 * The refusal for a row without a schema, or null to let it through.
	 *
	 * A row that names no schema in a mixed-schema batch is let through, so the downstream
	 * preparation records it as invalid with the proper error. A row without a schema
	 * because the batch schema could not be loaded is refused while RBAC applies: the RBAC
	 * gate is the check, and skipping it would be a decision to write without one.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return string|null
	 */
	private function schemalessRowRefusal(BulkSafeguardRun $run): ?string {
		if ($run->rbac === true && $run->schemaUnresolved === true) {
			return 'Schema could not be resolved; refusing to write a row whose permissions cannot be checked.';
		}

		return null;
	}//end schemalessRowRefusal()

	/**
	 * A row with a resolved schema and action through the remaining gates, in order: the
	 * draft rule, appendOnly, the permission, property authorization, validation, then
	 * the pre-save event where the bulk-write events are on.
	 *
	 * @param array            $row The row
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return array{0: array, 1: string|null}
	 */
	private function screenResolvedRow(array $row, BulkSafeguardRun $run): array {
		$refusal = $this->storedRowRefusal(run: $run);
		if ($refusal !== null) {
			return [$row, $refusal];
		}

		[$row, $refusal] = $this->rowPropertyAuthorization(row: $row, run: $run);
		if ($refusal !== null) {
			return [$row, $refusal];
		}

		return $this->screenValidatedRow(row: $row, run: $run);
	}//end screenResolvedRow()

	/**
	 * The first refusal of the gates that judge the row by its stored row and the caller, or null.
	 *
	 * In order: unresolved, organisation, deleted row, draft, appendOnly, permission, stored state.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return string|null
	 */
	private function storedRowRefusal(BulkSafeguardRun $run): ?string {
		return ($this->unresolvedRowRefusal(run: $run)
			?? $this->organisationRefusal(run: $run)
			?? $this->deletedRowRefusal(run: $run)
			?? $this->draftRefusal(run: $run)
			?? $this->appendOnlyRefusal(run: $run)
			?? $this->permissionRefusal(run: $run)
			?? $this->storedStateRefusal(run: $run));
	}//end storedRowRefusal()

	/**
	 * Property authorization per row while RBAC applies, as the single-object save applies it.
	 *
	 * @param array            $row The row
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return array{0: array, 1: string|null} The row to write, and a refusal or null.
	 */
	private function rowPropertyAuthorization(array $row, BulkSafeguardRun $run): array {
		if ($run->rbac === false) {
			return [$row, null];
		}

		return $this->applyPropertyAuthorization(
			object: $row,
			schema: $run->rowSchema,
			existing: $run->rowExisting,
			isCreate: ($run->rowAction === 'create')
		);
	}//end rowPropertyAuthorization()

	/**
	 * The row through validation, then the pre-save event where the bulk-write events are on.
	 *
	 * @param array            $row The row
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return array{0: array, 1: string|null}
	 */
	private function screenValidatedRow(array $row, BulkSafeguardRun $run): array {
		$refusal = $this->validationRefusal(row: $row, run: $run);
		$events  = $this->rowRules?->bulkWriteEvents;
		if ($refusal !== null || $events?->appliesTo(schema: $run->rowSchema, register: $run->rowRegister) !== true) {
			return [$row, $refusal];
		}

		return $events->screen(row: $row, run: $run);
	}//end screenValidatedRow()

	/**
	 * The refusal for a row that overwrites somebody else's draft, or null.
	 *
	 * Asked before the appendOnly gate, whose message would otherwise tell a non-owner
	 * that the uuid names an existing row. The stored row was found past the read RBAC,
	 * so without this a user who cannot read another user's draft could overwrite it by
	 * sending its uuid. The answer is the one a refused permission gives, so the draft's
	 * existence stays unannounced (same rule as ObjectService::refuseSomebodyElsesDraft).
	 * An administrator reads every draft, so is not kept from one.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return string|null
	 */
	private function draftRefusal(BulkSafeguardRun $run): ?string {
		if ($run->rbac === false || $run->rowExisting === null) {
			return null;
		}

		$draftKept = (new DraftCallerRule())->keptFromCaller(
			object: $run->rowExisting,
			schema: $run->rowSchema,
			userId: $run->userId(),
			isAdmin: $run->isAdmin
		);
		if ($draftKept === false) {
			return null;
		}

		return 'Permission denied for action ' . $run->rowAction . ' on schema ' . $run->rowSchema->getSlug();
	}//end draftRefusal()

	/**
	 * The refusal for a row whose stored row could not be resolved, or null.
	 *
	 * The writer upserts on the row's identity, so a lookup that was ambiguous
	 * or failed does not make the row a create. Answered as a refused create,
	 * like the organisation refusal, so the cause stays unannounced.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return string|null
	 */
	private function unresolvedRowRefusal(BulkSafeguardRun $run): ?string {
		if ($run->rowAction !== self::UNRESOLVED_ACTION) {
			return null;
		}

		return 'Permission denied for action create on schema ' . $run->rowSchema->getSlug();
	}//end unresolvedRowRefusal()

	/**
	 * The refusal for a row that would overwrite another organisation's row, or null.
	 *
	 * The stored row was found past the read filters, and the update rule it is
	 * judged by next does not look at organisations, while the writer upserts the
	 * row and stamps the caller as its owner and organisation. So a row outside the
	 * caller's organisation scope is refused unless the caller can read it, the
	 * same boundary ObjectService::checkSavePermissions keeps for a single write. A
	 * row the caller reads (a rule that reaches across organisations) is left to the
	 * update rule. Asked before the appendOnly gate, and answered as a refused
	 * create, so the row's existence stays unannounced. Skipped when the write does
	 * not keep to the caller's organisations (an administrator).
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return string|null
	 */
	private function organisationRefusal(BulkSafeguardRun $run): ?string {
		if ($this->keepsToOrganisations(run: $run) === false || $run->rowExisting === null) {
			return null;
		}

		if ($this->callerMayOverwriteStoredRow(run: $run) === true) {
			return null;
		}

		return 'Permission denied for action create on schema ' . $run->rowSchema->getSlug();
	}//end organisationRefusal()

	/**
	 * Whether the write keeps to the caller's organisations: RBAC and multitenancy both apply.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return bool
	 */
	private function keepsToOrganisations(BulkSafeguardRun $run): bool {
		return $run->rbac === true && $run->multitenancy === true;
	}//end keepsToOrganisations()

	/**
	 * Whether the stored row lies inside the caller's organisations, or else the caller reads it.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass, its stored row set
	 *
	 * @return bool
	 */
	private function callerMayOverwriteStoredRow(BulkSafeguardRun $run): bool {
		return $this->objectEntityMapper->callerOrganisationsHold(row: $run->rowExisting) === true
			|| $this->callerReadsStoredRow(run: $run) === true;
	}//end callerMayOverwriteStoredRow()

	/**
	 * Whether the caller's own read finds the row the current bulk row updates.
	 *
	 * The read matches an identifier on the id, the slug and the uri as well,
	 * so it counts only when it found the stored row itself, by its uuid.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return bool False when the read does not find the stored row or fails.
	 */
	private function callerReadsStoredRow(BulkSafeguardRun $run): bool {
		$uuid = (string) $run->rowExisting->getUuid();
		try {
			$read = $this->objectEntityMapper->find(
				identifier: $uuid,
				register: $run->rowRegister,
				schema: $run->rowSchema
			);
		} catch (\Throwable $e) {
			return false;
		}

		return $read->getUuid() === $uuid;
	}//end callerReadsStoredRow()

	/**
	 * Keep a soft-deleted stored row apart from the row the current bulk row updates.
	 *
	 * The stored rows are looked up with the deleted ones, so the organisation of a
	 * deleted row the writer would upsert onto is known. The row itself still counts
	 * as a create, as it did when deleted rows were not looked up.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return void
	 */
	private function setAsideDeletedRow(BulkSafeguardRun $run): void {
		$run->rowDeleted = null;
		if ($run->rowExisting === null || $run->rowExisting->isSoftDeleted() === false) {
			return;
		}

		$run->rowDeleted  = $run->rowExisting;
		$run->rowExisting = null;
		$run->rowAction   = 'create';
	}//end setAsideDeletedRow()

	/**
	 * The refusal for a row that would overwrite another organisation's soft-deleted row, or null.
	 *
	 * The writer upserts on the uuid with no deleted filter and stamps the caller as
	 * the row's owner and organisation, which would move another organisation's
	 * deleted row into the caller's trash. Answered as a refused create, like a live
	 * row of another organisation. Skipped when the write does not keep to the
	 * caller's organisations (an administrator).
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return string|null
	 */
	private function deletedRowRefusal(BulkSafeguardRun $run): ?string {
		if ($this->keepsToOrganisations(run: $run) === false || $run->rowDeleted === null) {
			return null;
		}

		if ($this->objectEntityMapper->callerOrganisationsHold(row: $run->rowDeleted) === true) {
			return null;
		}

		return 'Permission denied for action create on schema ' . $run->rowSchema->getSlug();
	}//end deletedRowRefusal()

	/**
	 * The refusal for an update to an appendOnly schema, or null.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return string|null
	 */
	private function appendOnlyRefusal(BulkSafeguardRun $run): ?string {
		if ($run->rowAction !== 'update' || $run->rowSchema->isAppendOnly() === false) {
			return null;
		}

		return 'Schema is appendOnly; UPDATE rejected for row UUID ' . ((string)($run->rowUuid ?? '?'));
	}//end appendOnlyRefusal()

	/**
	 * The refusal when PermissionHandlerInterface does not grant the row's action, or null.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return string|null
	 */
	private function permissionRefusal(BulkSafeguardRun $run): ?string {
		if ($run->rbac === false || $this->grantsRowAction(run: $run) === true) {
			return null;
		}

		return 'Permission denied for action ' . $run->rowAction . ' on schema ' . $run->rowSchema->getSlug();
	}//end permissionRefusal()

	/**
	 * Whether PermissionHandlerInterface grants the current row's action on its schema.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return bool
	 */
	private function grantsRowAction(BulkSafeguardRun $run): bool {
		return $this->permissionHandler->hasPermission(
			schema: $run->rowSchema,
			action: $run->rowAction,
			userId: $run->userId(),
			objectOwner: $run->rowExisting?->getOwner(),
			_rbac: true,
			object: $run->rowExisting
		) === true;
	}//end grantsRowAction()

	/**
	 * The refusal for an update to a stored row that is locked, archived or frozen, or null.
	 *
	 * The bulk writer skips SaveObject::findAndValidateExistingObject, so the same three
	 * states are refused here, with the same message and for every caller. Asked after the
	 * permission, so a caller without the update right learns nothing about the lock.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return string|null
	 */
	private function storedStateRefusal(BulkSafeguardRun $run): ?string {
		$stored = $run->rowExisting;
		if ($stored === null) {
			return null;
		}

		$writingRun = $this->rowRules?->runContext?->currentRunUuid();
		if ($stored->isLockedBySomeoneElse(userId: $run->userId(), runUuid: $writingRun) === true) {
			return LockedException::forObject($stored)->getMessage();
		}

		return self::writeStateRefusal(stored: $stored);
	}//end storedStateRefusal()

	/**
	 * The refusal for a stored row that is archived or frozen, or null.
	 *
	 * @param ObjectEntity $stored The stored row
	 *
	 * @return string|null
	 */
	private static function writeStateRefusal(ObjectEntity $stored): ?string {
		if ($stored->isArchived() === true) {
			return ObjectStateWriteException::archived($stored)->getMessage();
		}

		if ($stored->isFrozen() === true) {
			return ObjectStateWriteException::frozen($stored)->getMessage();
		}

		return null;
	}//end writeStateRefusal()

	/**
	 * The refusal when the row fails Opis JSON-Schema validation, or null.
	 *
	 * Only when the caller asked for validation and a validator is wired.
	 *
	 * @param array            $row The row
	 * @param BulkSafeguardRun $run The safeguard pass
	 *
	 * @return string|null
	 */
	private function validationRefusal(array $row, BulkSafeguardRun $run): ?string {
		$validator = $this->validateHandler;
		if ($run->validation === false || $validator === null) {
			return null;
		}

		try {
			return self::validationFailure(validator: $validator, row: $row, schema: $run->rowSchema);
		} catch (\Throwable $e) {
			return 'Schema validation threw: ' . $e->getMessage();
		}
	}//end validationRefusal()

	/**
	 * The validation failure of a row's business data, or null when it is valid.
	 *
	 * @param ValidateObjectInterface $validator The validator
	 * @param array                   $row       The row
	 * @param Schema                  $schema    The row's schema
	 *
	 * @return string|null
	 */
	private static function validationFailure(ValidateObjectInterface $validator, array $row, Schema $schema): ?string {
		unset($row['@self']);
		$validationResult = $validator->validateObject(object: $row, schema: $schema);
		if ($validationResult->isValid() === true) {
			return null;
		}

		return 'Schema validation failed: ' . $validator->generateErrorMessage(result: $validationResult);
	}//end validationFailure()

	/**
	 * Apply property authorization to one row, the way SaveObject does for a single save.
	 *
	 * A value the caller may not write is refused (unchanged values pass, as in a PATCH). On an
	 * update, a stored property the row omits and the caller may not read or update is carried
	 * forward, so leaving it out cannot clear it. A system operation is not narrowed. Without the
	 * property handler a row of a schema that has property rules is refused, never written unchecked.
	 *
	 * @param array<string, mixed> $object   The row, business fields at the top and `@self` beside them.
	 * @param Schema               $schema   The row's schema.
	 * @param ObjectEntity|null    $existing The stored row on an update, null on a create.
	 * @param bool                 $isCreate Whether the row creates.
	 *
	 * @return array{0: array<string, mixed>, 1: string|null} The row to write, and a refusal or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-bulk-save-applies-property-authorization-per-row
	 */
	private function applyPropertyAuthorization(array $object, Schema $schema, ?ObjectEntity $existing, bool $isCreate): array {
		// A system operation (cron, runAsSystem, the example-set installer) is trusted here as
		// PermissionHandlerInterface::hasPermission trusts it for the object rule.
		if (SystemOperationContext::isActive() === true) {
			return [$object, null];
		}

		$propertyRbac = $this->rowRules?->propertyRbac;
		if ($propertyRbac === null) {
			return [$object, self::unguardedPropertyRefusal(schema: $schema)];
		}

		$check   = new BulkRowPropertyAuthorization(propertyRbac: $propertyRbac, schema: $schema, existing: $existing);
		$refusal = $check->refusal(object: $object, isCreate: $isCreate);
		if ($refusal !== null) {
			return [$object, $refusal];
		}

		return [$check->carriedForward(object: $object), null];
	}//end applyPropertyAuthorization()

	/**
	 * The refusal of a row whose schema has property rules while no property handler is wired, or null.
	 *
	 * @param Schema $schema The row's schema.
	 *
	 * @return string|null
	 */
	private static function unguardedPropertyRefusal(Schema $schema): ?string {
		if ($schema->hasPropertyAuthorization() === true) {
			return 'Property authorization is unavailable, so a row of a schema with property rules cannot be checked';
		}

		return null;
	}//end unguardedPropertyRefusal()

	/**
	 * Strip dangerous client-supplied @self / top-level fields.
	 *
	 * For non-admin callers, removes `owner`, `organisation`, `authorization`
	 * from both `@self` and the flat top-level form. This is the bulk-path
	 * mirror of the wave-9 single-object @self strip + the missing
	 * `organisation` strip flagged in `/tmp/wave11-openregister-report.md`
	 * SB1. Admins may still set these (e.g. import-as-original-owner).
	 *
	 * `_owner`/`_organisation`/`_authorization` underscore-prefixed forms are
	 * also stripped for non-admins because MagicBulkHandler reads both flat
	 * and underscored forms.
	 *
	 * @param array $object Raw input object.
	 * @param bool $isAdmin Whether the caller is in the admin group.
	 *
	 * @return array Sanitised object.
	 */
	private function stripSelfInjectionFields(array $object, bool $isAdmin): array {
		if ($isAdmin === true) {
			return $object;
		}

		$dangerousKeys = ['owner', 'organisation', 'authorization'];
		foreach ($dangerousKeys as $key) {
			unset($object[$key], $object['_' . $key]);
			if (isset($object['@self']) === true && is_array($object['@self']) === true) {
				unset($object['@self'][$key]);
			}
		}

		return $object;
	}//end stripSelfInjectionFields()

	/**
	 * Load the schema entity to use as the per-row default.
	 *
	 * Deliberately does NOT catch. Its predecessor turned every failure into a
	 * null, and the caller's null branch meant "no schema-bound rules to
	 * enforce, let the row through" — so a load failure and a mixed-schema
	 * batch were indistinguishable, and the first one silently disabled the
	 * per-row RBAC check. The caller now catches and REFUSES those rows, which
	 * it can only do if the failure reaches it.
	 *
	 * `resolveSafeguardRegister()` used to sit beside this one with the same
	 * catch-and-null shape. It is gone rather than fixed: its result was
	 * assigned to `$defaultRegister` and never read, so it enforced nothing
	 * either way.
	 *
	 * @param Schema|string|int $schema The bulk-call schema argument.
	 *
	 * @return Schema The resolved schema.
	 *
	 * @throws \Throwable When the schema cannot be loaded.
	 */
	private function loadSafeguardSchema(Schema|string|int $schema): Schema {
		if ($schema instanceof Schema === true) {
			return $schema;
		}

		return $this->loadSchemaWithCache(schemaId: $schema);
	}//end loadSafeguardSchema()

	/**
	 * Resolve the bulk-call register argument to a Register entity.
	 *
	 * The register counterpart of {@see loadSafeguardSchema()}: together they
	 * give the create-vs-update probe enough context to look in one magic table
	 * instead of every one on the instance.
	 *
	 * @param Register|string|int $register The bulk-call register argument.
	 *
	 * @return Register The resolved register.
	 *
	 * @throws \Throwable When the register cannot be loaded.
	 */
	private function loadSafeguardRegister(Register|string|int $register): Register {
		if ($register instanceof Register === true) {
			return $register;
		}

		return $this->loadRegisterWithCache(registerId: $register);
	}//end loadSafeguardRegister()

	/**
	 * Determine which register applies to a single bulk row.
	 *
	 * The call-level register wins when the caller named one, because the writer
	 * stores every row of such a call in that register's table
	 * (MagicMapper::ultraFastBulkSave()). Screening a row against the register its
	 * `@self.register` names would judge another table than the one written.
	 * Only without a call-level register does `@self.register` decide.
	 *
	 * @param array $object Raw row data.
	 * @param Register|null $defaultRegister Call-level default register.
	 *
	 * @return Register|null Resolved register, or null when unresolvable.
	 */
	private function resolveSafeguardRowRegister(array $object, ?Register $defaultRegister): ?Register {
		if ($defaultRegister !== null) {
			return $defaultRegister;
		}

		return $this->registerNamedByRow(selfRegister: ($object['@self']['register'] ?? null));
	}//end resolveSafeguardRowRegister()

	/**
	 * The register a row's `@self.register` names, or null when it names none or it cannot be loaded.
	 *
	 * @param mixed $selfRegister The row's `@self.register`.
	 *
	 * @return Register|null
	 */
	private function registerNamedByRow(mixed $selfRegister): ?Register {
		if ($selfRegister === null || $selfRegister === '') {
			return null;
		}

		try {
			return $this->loadRegisterWithCache(registerId: $selfRegister);
		} catch (\Throwable $e) {
			// Unresolvable: no register.
			return null;
		}
	}//end registerNamedByRow()

	/**
	 * Determine which schema applies to a single bulk row.
	 *
	 * The call-level schema wins when the caller named one, for the same reason as
	 * the register: the writer stores the call's rows in that schema's table.
	 * `@self.schema` decides only in mixed-schema mode.
	 *
	 * @param array $object Raw row data.
	 * @param Schema|null $defaultSchema Call-level default schema.
	 *
	 * @return Schema|null Resolved schema, or null when unresolvable.
	 */
	private function resolveSafeguardRowSchema(array $object, ?Schema $defaultSchema): ?Schema {
		if ($defaultSchema !== null) {
			return $defaultSchema;
		}

		return $this->schemaNamedByRow(selfSchema: ($object['@self']['schema'] ?? null));
	}//end resolveSafeguardRowSchema()

	/**
	 * The schema a row's `@self.schema` names, or null when it names none or it cannot be loaded.
	 *
	 * @param mixed $selfSchema The row's `@self.schema`.
	 *
	 * @return Schema|null
	 */
	private function schemaNamedByRow(mixed $selfSchema): ?Schema {
		if ($selfSchema === null || $selfSchema === '') {
			return null;
		}

		try {
			return $this->loadSchemaWithCache(schemaId: $selfSchema);
		} catch (\Throwable $e) {
			// Unresolvable: no schema.
			return null;
		}
	}//end schemaNamedByRow()

	/**
	 * The identity candidates a row carries: `id`, `@self.id` and `@self.uuid`.
	 *
	 * Only non-empty strings count; the writers accept nothing else as an id.
	 *
	 * @param array $object Raw row data.
	 *
	 * @return string[] The distinct candidates.
	 */
	private function rowIdentityCandidates(array $object): array {
		$self = [];
		if (is_array($object['@self'] ?? null) === true) {
			$self = $object['@self'];
		}

		$candidates = [];
		foreach ([($object['id'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				$candidates[$candidate] = $candidate;
			}
		}

		return array_values($candidates);
	}//end rowIdentityCandidates()

	/**
	 * The one identity of a row, shared by the guard, the prefetch and the writers.
	 *
	 * @param array $object Raw row data.
	 *
	 * @return string|null The identity, or null when the row names none (or names several: see rowIdentityIsAmbiguous()).
	 */
	private function rowIdentity(array $object): ?string {
		$candidates = $this->rowIdentityCandidates(object: $object);
		if (count($candidates) !== 1) {
			return null;
		}

		return $candidates[0];
	}//end rowIdentity()

	/**
	 * Whether a row names more than one distinct identity.
	 *
	 * @param array $object Raw row data.
	 *
	 * @return bool True when the guard and a writer could pick different rows.
	 */
	private function rowIdentityIsAmbiguous(array $object): bool {
		return count($this->rowIdentityCandidates(object: $object)) > 1;
	}//end rowIdentityIsAmbiguous()

	/**
	 * Resolve the CRUD action + existing entity for a bulk row.
	 *
	 * UPDATE = the row carries a UUID that matches an existing object in the
	 * register/schema's magic table. CREATE = otherwise.
	 *
	 * @param array            $object Raw row data.
	 * @param BulkSafeguardRun $run    The safeguard pass: the row's schema and register (with the
	 *                                 schema it bounds the lookup to a single magic table) and the
	 *                                 batch prefetch, keyed by "registerId/schemaId" then uuid.
	 *
	 * @return array{0: ?string, 1: string, 2: ?ObjectEntity} Tuple of [uuid, action, existingEntity].
	 */
	private function resolveSafeguardActionForRow(array $object, BulkSafeguardRun $run): array {
		$uuid = $this->rowIdentity(object: $object);
		if ($uuid === null) {
			return [null, 'create', null];
		}

		return ($this->prefetchedActionForRow(uuid: $uuid, run: $run) ?? $this->lookUpActionForRow(uuid: $uuid, run: $run));
	}//end resolveSafeguardActionForRow()

	/**
	 * The action the batch prefetch answers for a row, or null when its group was not probed.
	 *
	 * The group key must be checked with array_key_exists, not by looking the
	 * uuid up directly: "this group was probed and the uuid was not in it"
	 * is a real answer (create), while "this group was never probed" is not
	 * an answer at all and has to fall through to the single lookup.
	 *
	 * @param string           $uuid The row's identity.
	 * @param BulkSafeguardRun $run  The safeguard pass.
	 *
	 * @return array{0: string, 1: string, 2: ?ObjectEntity}|null
	 */
	private function prefetchedActionForRow(string $uuid, BulkSafeguardRun $run): ?array {
		if ($run->rowRegister === null) {
			return null;
		}

		$groupKey = $this->safeguardGroupKey(register: $run->rowRegister, schema: $run->rowSchema);
		if (array_key_exists($groupKey, $run->existingByUuid) === false) {
			return null;
		}

		$existing = ($run->existingByUuid[$groupKey][$uuid] ?? null);
		if ($existing !== null) {
			return [$uuid, 'update', $existing];
		}

		return [$uuid, 'create', null];
	}//end prefetchedActionForRow()

	/**
	 * The action one bounded lookup answers for a row.
	 *
	 * @param string           $uuid The row's identity.
	 * @param BulkSafeguardRun $run  The safeguard pass.
	 *
	 * @return array{0: string, 1: string, 2: ?ObjectEntity}
	 */
	private function lookUpActionForRow(string $uuid, BulkSafeguardRun $run): array {
		try {
			return [$uuid, 'update', $this->findStoredRow(uuid: $uuid, run: $run)];
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return [$uuid, 'create', null];
		} catch (\Throwable $e) {
			// Ambiguous or failed: not known which row the writer would land on.
			return [$uuid, self::UNRESOLVED_ACTION, null];
		}
	}//end lookUpActionForRow()

	/**
	 * The stored row a uuid names in the row's own register and schema, deleted rows included.
	 *
	 * Passing the register AND schema takes MagicMapper::find()'s bounded
	 * branch — one indexed lookup in the row's own magic table. Omitting
	 * either drops it into findAcrossAllMagicTables(), an instance-wide
	 * union that costs hundreds of milliseconds per row and scales with
	 * how many tables exist rather than with the batch.
	 *
	 * The bounded table is also the RIGHT place to ask the question. This
	 * probe decides create-vs-update for a write that is going into the
	 * row's own register/schema table, and feeds the appendOnly gate and
	 * the per-row permission check. An object carrying the same uuid in
	 * some OTHER register's table does not make this write an update, and
	 * checking permissions against that unrelated object's owner is a
	 * judgement about the wrong record — which is what the unbounded
	 * lookup did whenever a uuid collided across registers.
	 *
	 * @param string           $uuid The row's identity.
	 * @param BulkSafeguardRun $run  The safeguard pass.
	 *
	 * @return ObjectEntity
	 *
	 * @throws \Throwable When no row, or no single row, is found.
	 */
	private function findStoredRow(string $uuid, BulkSafeguardRun $run): ObjectEntity {
		return $this->objectEntityMapper->find(
			identifier: $uuid,
			register: $run->rowRegister,
			schema: self::boundedSchema(run: $run),
			includeDeleted: true,
			_rbac: false,
			_multitenancy: false
		);
	}//end findStoredRow()

	/**
	 * The schema that bounds the single lookup: only with a register, since
	 * MagicMapper::find() takes its single-table branch on the PAIR.
	 *
	 * @param BulkSafeguardRun $run The safeguard pass.
	 *
	 * @return Schema|null
	 */
	private static function boundedSchema(BulkSafeguardRun $run): ?Schema {
		if ($run->rowRegister === null) {
			return null;
		}

		return $run->rowSchema;
	}//end boundedSchema()

	/**
	 * Build the group key a batch prefetch is bucketed under.
	 *
	 * @param Register $register The row's register.
	 * @param Schema $schema The row's schema.
	 *
	 * @return string The "registerId/schemaId" key.
	 */
	private function safeguardGroupKey(Register $register, Schema $schema): string {
		return ((string)$register->getId()) . '/' . ((string)$schema->getId());
	}//end safeguardGroupKey()

	/**
	 * Look up, in one query per register+schema, which of a batch's uuids exist.
	 *
	 * Replaces N single-row existence queries with one per distinct target
	 * table. Mixed-register and mixed-schema batches are handled by grouping,
	 * so a batch spanning three tables costs three queries rather than N.
	 *
	 * A group is only added to the returned map once its probe has SUCCEEDED.
	 * That distinction is the whole safety property here: the caller reads a
	 * present group as authoritative ("absent uuid means this row is a create")
	 * and an absent group as "unknown, go and ask". A failed probe recorded as
	 * an empty group would silently turn every row of that group into a create
	 * and duplicate the whole batch on the next run.
	 *
	 * @param array<int, array<string, mixed>> $objects The raw batch.
	 * @param BulkSafeguardRun                 $run     The safeguard pass, its batch defaults resolved.
	 *
	 * @return array<string, array<string, ObjectEntity>> Existing entities, keyed by
	 *         "registerId/schemaId" then uuid.
	 */
	private function prefetchExistingRows(array $objects, BulkSafeguardRun $run): array {
		$existing = [];
		foreach ($this->prefetchGroups(objects: $objects, run: $run) as $groupKey => $group) {
			$found = $this->prefetchGroup(groupKey: $groupKey, group: $group);
			if ($found !== null) {
				$existing[$groupKey] = $found;
			}
		}

		return $existing;
	}//end prefetchExistingRows()

	/**
	 * The batch's identified rows grouped by register and schema, in batch order.
	 *
	 * @param array<int, array<string, mixed>> $objects The raw batch.
	 * @param BulkSafeguardRun                 $run     The safeguard pass, its batch defaults resolved.
	 *
	 * @return array<string, array{register: Register, schema: Schema, uuids: list<string>}>
	 */
	private function prefetchGroups(array $objects, BulkSafeguardRun $run): array {
		$groups = [];
		foreach ($objects as $object) {
			$target = $this->prefetchTarget(object: $object, run: $run);
			if ($target === null) {
				continue;
			}

			$groupKey = $this->safeguardGroupKey(register: $target['register'], schema: $target['schema']);
			$groups[$groupKey] ??= ['register' => $target['register'], 'schema' => $target['schema'], 'uuids' => []];
			$groups[$groupKey]['uuids'][] = $target['uuid'];
		}

		return $groups;
	}//end prefetchGroups()

	/**
	 * The identity, schema and register a row is prefetched under, or null when it lacks one.
	 *
	 * @param array<string, mixed> $object The row.
	 * @param BulkSafeguardRun     $run    The safeguard pass, its batch defaults resolved.
	 *
	 * @return array{uuid: string, register: Register, schema: Schema}|null
	 */
	private function prefetchTarget(array $object, BulkSafeguardRun $run): ?array {
		$uuid = $this->rowIdentity(object: $object);
		if ($uuid === null) {
			return null;
		}

		$rowSchema = $this->resolveSafeguardRowSchema(object: $object, defaultSchema: $run->defaultSchema);
		if ($rowSchema === null) {
			return null;
		}

		$rowRegister = $this->resolveSafeguardRowRegister(object: $object, defaultRegister: $run->defaultRegister);
		if ($rowRegister === null) {
			return null;
		}

		return ['uuid' => $uuid, 'register' => $rowRegister, 'schema' => $rowSchema];
	}//end prefetchTarget()

	/**
	 * The stored rows of one group, or null when the probe failed.
	 *
	 * A failed group is left OUT of the map (see prefetchExistingRows()); the
	 * per-row lookup then answers for these rows, which is slower but correct.
	 *
	 * @param string                                                          $groupKey The "registerId/schemaId" key.
	 * @param array{register: Register, schema: Schema, uuids: list<string>} $group    The group.
	 *
	 * @return array<string, ObjectEntity>|null
	 */
	private function prefetchGroup(string $groupKey, array $group): ?array {
		try {
			return $this->findGroupRows(group: $group);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[SaveObjects] Batch existence prefetch failed; falling back to per-row lookup',
				context: ['group' => $groupKey, 'uuids' => count($group['uuids']), 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end prefetchGroup()

	/**
	 * The stored rows, deleted ones included, of a group's uuids in its register and schema.
	 *
	 * @param array{register: Register, schema: Schema, uuids: list<string>} $group The group.
	 *
	 * @return array<string, ObjectEntity>
	 */
	private function findGroupRows(array $group): array {
		return $this->objectEntityMapper->findObjectsByUuidsInRegisterSchema(
			register: $group['register'],
			schema: $group['schema'],
			uuids: $group['uuids'],
			includeDeleted: true
		);
	}//end findGroupRows()

	/**
	 * Record a rejection from `applyBulkSafeguards` into the result accumulator.
	 *
	 * The row's position in the submitted batch is recorded alongside the
	 * reason (issue #2778). Without it a caller reading the response cannot say
	 * WHICH of its objects was refused: a rejected row often carries no uuid
	 * yet (it is a create), and the reason alone does not identify it. The
	 * index is the caller's own array key, so it maps straight back onto the
	 * payload they sent.
	 *
	 * @param array $object Sanitised row (post-strip — safe to log shape).
	 * @param string $reason Human-readable rejection reason.
	 * @param array $result Result accumulator (mutated in place).
	 * @param int|null $index Position of the row in the submitted batch, when known.
	 *
	 * @return void
	 */
	private function recordSafeguardRejection(array $object, string $reason, array &$result, ?int $index = null): void {
		$result['invalid'][] = ['object' => $object, 'error' => $reason, 'index' => $index, 'type' => 'BulkSafeguardException'];
		$result['errors'][]  = ['error' => $reason, 'type' => 'BulkSafeguardException', 'index' => $index];
		$result['statistics']['invalid']++;
		$result['statistics']['errors']++;

		$this->logSafeguardRejection(reason: $reason, index: $index);
	}//end recordSafeguardRejection()

	/**
	 * Log that a safeguard REFUSED to write a row: exactly the kind of thing someone comes to the log to find.
	 *
	 * @param string   $reason The rejection reason.
	 * @param int|null $index  Position of the row in the submitted batch, when known.
	 *
	 * @return void
	 */
	private function logSafeguardRejection(string $reason, ?int $index): void {
		$this->logger->info(
			message: '[SaveObjects] Wave-12 bulk safeguard rejected row',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'reason' => $reason,
				'index' => $index,
			]
		);
	}//end logSafeguardRejection()

	/**
	 * Calculate optimal chunk size based on total objects for internal processing
	 *
	 * @param int $totalObjects Total number of objects to process
	 *
	 * @return int Optimal chunk size
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function calculateOptimalChunkSize(int $totalObjects): int {
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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function prepareObjectsForBulkSave(array $objects): array {
		// Early return for empty arrays.
		if (empty($objects) === true) {
			return [[], []];
		}

		// PERFORMANCE OPTIMIZATION: Build comprehensive schema analysis cache first.
		[$schemaCache, $schemaAnalysis] = $this->groupAndLoadSchemas(objects: $objects);

		// Pre-process objects using cached schema analysis.
		$preparedObjects = [];
		$invalidObjects = [];
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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function groupAndLoadSchemas(array $objects): array {
		$schemaCache = [];
		$schemaAnalysis = [];

		// NO ERROR SUPPRESSION: Let schema loading errors bubble up immediately!
		foreach ($this->distinctSchemaIds(objects: $objects) as $schemaId) {
			$schema = $this->loadSchemaWithCache(schemaId: $schemaId);
			$schemaCache[$schemaId] = $schema;
			$schemaAnalysis[$schemaId] = $this->getSchemaAnalysisWithCache(schema: $schema);
		}

		return [$schemaCache, $schemaAnalysis];
	}//end groupAndLoadSchemas()

	/**
	 * The schema ids the objects name in @self, once each (loosely compared), in first-seen order.
	 *
	 * @param array $objects The objects.
	 *
	 * @return array The schema ids; every value but null counts.
	 */
	private function distinctSchemaIds(array $objects): array {
		$schemaIds = [];
		foreach ($objects as $object) {
			$schemaId = ($object['@self']['schema'] ?? null);
			if ($schemaId !== null && in_array($schemaId, $schemaIds) === false) {
				$schemaIds[] = $schemaId;
			}
		}

		return $schemaIds;
	}//end distinctSchemaIds()

	/**
	 * Prepare a single object in a mixed-schema bulk save operation
	 *
	 * Handles ID generation, metadata hydration, relation scanning, and
	 * pre-validation cascading for an individual object within a mixed-schema batch.
	 * A row whose @self schema is false passes through unchanged, to be caught in the
	 * transformation; any other error bubbles up. Hydrated metadata goes to @self and
	 * the top level (for bulk SQL).
	 *
	 * @param array $object The raw object data with @self metadata
	 * @param array $schemaCache The schema cache indexed by schema ID
	 *
	 * @return array The prepared object with hydrated metadata
	 *
	 * @throws Exception If schema is not found in cache
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function prepareMixedSchemaObject(array $object, array $schemaCache): array {
		$schemaId = ($object['@self']['schema'] ?? null);
		if ($schemaId === false) {
			return $object;
		}

		$schema = $this->cachedPreparationSchema(schemaId: $schemaId, schemaCache: $schemaCache);
		$object = $this->withGeneratedSelfId(object: $object);
		$tempEntity = $this->hydratedBulkEntity(object: $object, schema: $schema);
		$selfData = $this->applyHydratedMetadata(selfData: ($object['@self'] ?? []), object: $object, tempEntity: $tempEntity);
		$selfData['relations'] = (new BulkRelationScanner())->scan(data: $tempEntity->getObject(), run: new BulkRelationRun(schema: $schema));
		$object['@self'] = $selfData;

		return $this->handlePreValidationCascading(object: $object, schema: $schema, uuid: $selfData['id'])[0];
	}//end prepareMixedSchemaObject()

	/**
	 * The schema a mixed-schema row names, from the preparation's schema cache.
	 *
	 * @param mixed $schemaId    The row's @self schema.
	 * @param array $schemaCache The loaded schemas by id.
	 *
	 * @return Schema
	 *
	 * @throws Exception When the schema is not in the cache.
	 */
	private function cachedPreparationSchema(mixed $schemaId, array $schemaCache): Schema {
		if (isset($schemaCache[$schemaId]) === false) {
			throw new Exception("Schema {$schemaId} not found in cache during preparation");
		}

		return $schemaCache[$schemaId];
	}//end cachedPreparationSchema()

	/**
	 * The object with a generated uuid as its @self id when it has none, or only whitespace, or "0".
	 *
	 * @param array $object The object.
	 *
	 * @return array The object.
	 */
	private function withGeneratedSelfId(array $object): array {
		$selfData = ($object['@self'] ?? []);
		$providedId = ($selfData['id'] ?? null);
		if ($providedId === null || empty(trim($providedId)) === true) {
			$selfData['id'] = Uuid::v4()->toRfc4122();
			$object['@self'] = $selfData;
		}

		return $object;
	}//end withGeneratedSelfId()

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
	 * @param array $objects Array of objects in serialized format
	 * @param Register|string|int $register Register context
	 * @param Schema|string|int $schema Schema context
	 *
	 * @return array Array containing [prepared objects, schema cache, invalid objects]
	 *
	 * @see website/docs/developers/import-flow.md for complete import flow documentation
	 * @see SaveObject::hydrateObjectMetadata() for metadata extraction details
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function prepareSingleSchemaObjectsOptimized(array $objects, Register|string|int $register, Schema|string|int $schema): array {
		$startTime = microtime(true);

		// PERFORMANCE OPTIMIZATION: Load and validate schema context once, pre-calculate metadata once.
		$context  = $this->loadAndValidateSchemaContext(register: $register, schema: $schema);
		$defaults = $this->singleSchemaDefaults(context: $context);

		// PERFORMANCE OPTIMIZATION: Process all objects with pre-calculated values.
		$preparedObjects = [];
		foreach ($objects as $object) {
			$preparedObjects[] = $this->prepareSingleSchemaObject(object: $object, defaults: $defaults);
		}

		// INVERSE RELATIONS PROCESSING - Handle bulk inverse relations.
		$this->handleBulkInverseRelationsWithAnalysis(preparedObjects: $preparedObjects, schemaAnalysis: $context[5]);
		$this->logSingleSchemaPreparation(objectCount: count($objects), preparedObjects: $preparedObjects, startTime: $startTime);

		return [$preparedObjects, $context[4], []];
	}//end prepareSingleSchemaObjectsOptimized()

	/**
	 * The register, schema, owner and organisation every row of a single-schema save is stamped with.
	 *
	 * The owner is the session user; organisation service errors bubble up (no error suppression).
	 *
	 * @param array $context The context loadAndValidateSchemaContext() returns.
	 *
	 * @return BulkObjectDefaults
	 */
	private function singleSchemaDefaults(array $context): BulkObjectDefaults {
		$ownership = new BulkObjectOwnership(
			owner: $this->userSession->getUser()?->getUID(),
			organisation: $this->organisationService->getOrganisationForNewEntity()
		);

		return new BulkObjectDefaults(registerId: $context[0], schemaId: $context[2], schema: $context[3], ownership: $ownership);
	}//end singleSchemaDefaults()

	/**
	 * Debug-log a large single-schema preparation; smaller ones are not logged, for performance.
	 *
	 * @param int   $objectCount     The number of rows passed.
	 * @param array $preparedObjects The prepared rows.
	 * @param float $startTime       When the preparation started.
	 *
	 * @return void
	 */
	private function logSingleSchemaPreparation(int $objectCount, array $preparedObjects, float $startTime): void {
		$elapsed = microtime(true) - $startTime;
		if ($objectCount <= 10000) {
			return;
		}

		$this->logger->debug(
			'Single-schema preparation completed',
			[
				'objectsProcessed' => count($preparedObjects),
				'timeMs' => round($elapsed * 1000, 2),
				'speed' => round(count($preparedObjects) / max($elapsed, 0.001), 2),
			]
		);
	}//end logSingleSchemaPreparation()

	/**
	 * Load and validate register+schema context for single-schema operations
	 *
	 * Resolves register and schema from IDs or objects, caches them, and returns
	 * all context needed for batch preparation.
	 *
	 * @param Register|string|int $register Register context (object or ID)
	 * @param Schema|string|int $schema Schema context (object or ID)
	 *
	 * @return array [registerId, registerObj, schemaId, schemaObj, schemaCache, schemaAnalysis]
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function loadAndValidateSchemaContext(
		Register|string|int $register,
		Schema|string|int $schema,
	): array {
		$registerId = self::contextId(entity: $register);
		$schemaId = self::contextId(entity: $schema);
		$register = $this->contextRegister(register: $register);
		$schemaObj = $this->contextSchema(schema: $schema);
		$schemaAnalysis = [$schemaId => $this->getSchemaAnalysisWithCache(schema: $schemaObj)];

		return [$registerId, $register, $schemaId, $schemaObj, [$schemaId => $schemaObj], $schemaAnalysis];
	}//end loadAndValidateSchemaContext()

	/**
	 * The id a caller's register or schema argument stands for: the entity's id, or the argument itself.
	 *
	 * @param Register|Schema|string|int $entity The argument.
	 *
	 * @return string|int
	 */
	private static function contextId(Register|Schema|string|int $entity): string|int {
		if ($entity instanceof Register || $entity instanceof Schema) {
			return $entity->getId();
		}

		return $entity;
	}//end contextId()

	/**
	 * The caller's register: a given entity is cached under its id, an id is loaded through the cache.
	 *
	 * @param Register|string|int $register The argument.
	 *
	 * @return Register
	 */
	private function contextRegister(Register|string|int $register): Register {
		if ($register instanceof Register) {
			self::$registerCache[$register->getId()] = $register;
			return $register;
		}

		return $this->loadRegisterWithCache(registerId: $register);
	}//end contextRegister()

	/**
	 * The caller's schema: a given entity is cached under its id, an id is loaded through the cache.
	 *
	 * @param Schema|string|int $schema The argument.
	 *
	 * @return Schema
	 */
	private function contextSchema(Schema|string|int $schema): Schema {
		if ($schema instanceof Schema) {
			self::$schemaCache[$schema->getId()] = $schema;
			return $schema;
		}

		return $this->loadSchemaWithCache(schemaId: $schema);
	}//end contextSchema()

	/**
	 * Prepare a single object for single-schema bulk save
	 *
	 * Applies pre-calculated defaults, hydrates metadata, extracts business data,
	 * and scans for relations. Returns the prepared selfData array.
	 *
	 * @param array              $object   The raw object data
	 * @param BulkObjectDefaults $defaults Register, schema, owner and organisation of this save
	 *
	 * @return array The prepared selfData array ready for database operations
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function prepareSingleSchemaObject(array $object, BulkObjectDefaults $defaults): array {
		// NO ERROR SUPPRESSION: Let single-schema preparation errors bubble up immediately!
		$object['@self'] = $this->stampBulkSelfData(object: $object, defaults: $defaults);
		$selfData = $this->hydrateBulkMetadata(object: $object, schema: $defaults->schema);
		$this->logBulkObjectStructure(object: $object);

		// The schema is passed so a declared `name` / `description` / `summary` /
		// `image` / `slug` property is not mistaken for the magic-table column of
		// the same name (openregister#2781).
		$businessData = $this->extractBusinessData(object: $object, schema: $defaults->schema);
		$selfData['relations'] = (new BulkRelationScanner())->scan(data: $businessData, run: new BulkRelationRun(schema: $defaults->schema));
		$this->logBulkRelationScan(selfData: $selfData, businessData: $businessData);

		// Store the clean business data in the database object column.
		$selfData['object'] = $businessData;

		return $selfData;
	}//end prepareSingleSchemaObject()

	/**
	 * The @self data of one bulk object with register, schema, id, owner and
	 * organisation stamped from the save's defaults.
	 *
	 * Owner and organisation are never taken from client-supplied @self data:
	 * they always come from the session and the active organisation.
	 *
	 * @param array              $object   The raw object data
	 * @param BulkObjectDefaults $defaults Register, schema, owner and organisation of this save
	 *
	 * @return array The stamped @self data
	 */
	private function stampBulkSelfData(array $object, BulkObjectDefaults $defaults): array {
		$selfData = $object['@self'] ?? [];

		// The call to extractBusinessData() drops an undeclared top-level `slug`.
		// Keep it for the slug decision, which removes this key again (#4037).
		$selfData[BulkObjectSlugs::CLIENT_SLUG_KEY] = $this->bulkSlugs()->clientSlugToCarry(selfData: $selfData, object: $object);

		$selfData['register'] = $selfData['register'] ?? $defaults->registerId;
		$selfData['schema'] = $selfData['schema'] ?? $defaults->schemaId;
		$selfData = $this->withBulkObjectId(selfData: $selfData, object: $object);
		$selfData['owner'] = $defaults->ownership->owner;
		$selfData['organisation'] = $defaults->ownership->organisation;

		return $selfData;
	}//end stampBulkSelfData()

	/**
	 * Set uuid and id: the provided id (CSV `id` column first, then @self.id)
	 * when it is a non-blank string, otherwise a new v4 uuid.
	 *
	 * @param array $selfData The @self data
	 * @param array $object   The raw object data
	 *
	 * @return array The @self data with uuid and id set
	 */
	private function withBulkObjectId(array $selfData, array $object): array {
		$providedId = $object['id'] ?? $selfData['id'] ?? null;
		$selfData['uuid'] = Uuid::v4()->toRfc4122();
		$selfData['id'] = $selfData['uuid'];

		if ($providedId !== null && empty(trim($providedId)) === false) {
			$selfData['uuid'] = $providedId;
			$selfData['id'] = $providedId;
		}

		return $selfData;
	}//end withBulkObjectId()

	/**
	 * Hydrate schema-driven metadata (name, description, summary, ...) of one
	 * bulk object and copy it back into its @self data.
	 *
	 * @param array  $object The raw object data with its stamped @self data
	 * @param Schema $schema The schema for metadata hydration
	 *
	 * @return array The @self data with the hydrated metadata
	 */
	private function hydrateBulkMetadata(array $object, Schema $schema): array {
		$tempEntity = $this->hydratedBulkEntity(object: $object, schema: $schema);

		return $this->applyHydratedMetadata(selfData: $object['@self'], object: $object, tempEntity: $tempEntity);
	}//end hydrateBulkMetadata()

	/**
	 * A temporary entity holding one bulk object, its @self data and the
	 * metadata the schema derives from it.
	 *
	 * @param array  $object The raw object data
	 * @param Schema $schema The schema for metadata hydration
	 *
	 * @return ObjectEntity The hydrated temporary entity
	 */
	private function hydratedBulkEntity(array $object, Schema $schema): ObjectEntity {
		$tempEntity = new ObjectEntity();
		$tempEntity->setObject($object);
		if (isset($object['@self']) === true && is_array($object['@self']) === true) {
			$tempEntity->hydrate($object['@self']);
		}

		$this->saveHandler->hydrateObjectMetadata($tempEntity, $schema);

		return $tempEntity;
	}//end hydratedBulkEntity()

	/**
	 * Debug-log the structure of one bulk object.
	 *
	 * @param array $object The raw object data
	 *
	 * @return void
	 */
	private function logBulkObjectStructure(array $object): void {
		$selfKeys = 'none';
		if (isset($object['@self']) === true) {
			$selfKeys = array_keys($object['@self']);
		}

		$this->logger->debug(
			'[SaveObjects] DEBUG - Single schema object structure',
			[
				'available_keys' => array_keys($object),
				'has_@self' => isset($object['@self']),
				'@self_keys' => $selfKeys,
				'has_object_property' => isset($object['object']),
			]
		);
	}//end logBulkObjectStructure()

	/**
	 * Debug-log the relations scanned for one bulk object.
	 *
	 * @param array $selfData     The @self data carrying uuid and relations
	 * @param array $businessData The object's business data
	 *
	 * @return void
	 */
	private function logBulkRelationScan(array $selfData, array $businessData): void {
		$this->logger->debug(
			'[SaveObjects] Relations scanned in preparation (single schema)',
			[
				'uuid' => $selfData['uuid'] ?? 'unknown',
				'relationCount' => count($selfData['relations']),
				'businessDataKeys' => array_keys($businessData),
			]
		);
	}//end logBulkRelationScan()

	/**
	 * Apply hydrated metadata from a temporary entity back to selfData and object arrays
	 *
	 * Extracts name, description, summary, image, and slug from the hydrated entity
	 * and sets them in both the @self data and top-level object (for bulk SQL).
	 *
	 * @param array $selfData The @self metadata array
	 * @param array $object The full object data (modified in-place for top-level)
	 * @param ObjectEntity $tempEntity The hydrated temporary entity
	 *
	 * @return array Updated selfData with metadata fields
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applyHydratedMetadata(array $selfData, array &$object, ObjectEntity $tempEntity): array {
		foreach (self::HYDRATED_METADATA_GETTERS as $field => $getter) {
			$value = $tempEntity->$getter();
			if ($value !== null) {
				$selfData[$field] = $value;
				// TOP LEVEL for bulk SQL.
				$object[$field] = $value;
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
	 * Per-phase timings are reported. A bulk save that is slower than the single-object
	 * path it replaces is indistinguishable from one that is faster unless the phases
	 * are reported separately — measured on this instance, the whole cost of a chunk
	 * sat in ONE of the steps.
	 *
	 * @param BulkSaveRun $run     The bulk save call: its schema cache, switches and caller-supplied
	 *                             register and schema (forwarded to bulk save).
	 * @param array       $objects Array of pre-processed objects ready for database operations
	 *
	 * @return array Processing result for this chunk with bulk operation statistics
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function processObjectsChunk(BulkSaveRun $run, array $objects): array {
		$chunk = new BulkChunkRun(result: $this->emptyChunkResult(), run: $run);
		$rows  = $this->guardedChunkRows(chunk: $chunk, objects: $objects);
		if ($rows !== []) {
			$this->persistChunkRows(chunk: $chunk, rows: $rows);
		}

		$chunk->result['statistics']['phaseMs'] = $chunk->phaseMs;

		return $chunk->result;
	}//end processObjectsChunk()

	/**
	 * STEP 1 and 1b: the chunk's rows in database format that pass the guards.
	 *
	 * Rows that cannot be transformed, and (BUG-OBJ-1) rows refused per-object RBAC or
	 * schema validation, mirroring the single-object save path, are moved into the
	 * chunk's invalid rows BEFORE anything is persisted.
	 *
	 * @param BulkChunkRun $chunk   The chunk's bulk save call, result and timings.
	 * @param array        $objects The chunk's prepared objects.
	 *
	 * @return array The rows to persist; empty when none are left.
	 */
	private function guardedChunkRows(BulkChunkRun $chunk, array $objects): array {
		$run = $chunk->run;
		$phaseStart = microtime(true);
		$rows = $this->transformChunk(objects: $objects, schemaCache: $run->schemaCache, result: $chunk->result);
		$chunk->finishPhase(phase: 'transform', start: $phaseStart);
		if (empty($rows) === true) {
			return [];
		}

		$phaseStart = microtime(true);
		[$rows, $refused] = $this->enforceChunkGuards(run: $run, transformedObjects: $rows);
		$chunk->result = $this->withRefusedRows(result: $chunk->result, refused: $refused);
		$chunk->finishPhase(phase: 'guards', start: $phaseStart);

		return $rows;
	}//end guardedChunkRows()

	/**
	 * STEP 2, 3 and 3b: persist the rows, classify the outcome, and write the audit trail and events.
	 *
	 * @param BulkChunkRun $chunk The chunk's bulk save call, result and timings.
	 * @param array        $rows  The rows that passed the guards (not empty).
	 *
	 * @return void
	 */
	private function persistChunkRows(BulkChunkRun $chunk, array $rows): void {
		$run = $chunk->run;
		$chunk->eventTargets = $this->bulkEventTargets(
			transformedObjects: $rows,
			context: ['events' => $run->events, 'schemas' => $run->schemaCache, 'register' => $run->register, 'schema' => $run->schema]
		);
		$bulkResult = $this->timedPersist(chunk: $chunk, rows: $rows);

		// Build and classify results from bulk operation output. Returns the
		// hydrated entities (with pre-update state for updates) for side effects.
		$phaseStart  = microtime(true);
		$sideEffects = $this->buildChunkResults(bulkResult: $bulkResult, transformedObjects: $rows, result: $chunk->result);
		$chunk->finishPhase(phase: 'buildResults', start: $phaseStart);

		$this->timedSideEffects(chunk: $chunk, sideEffects: $sideEffects);
		// Processing time in the result for transparency and performance monitoring.
		$chunk->result['statistics']['processingTimeMs'] = round((microtime(true) - $chunk->startTime) * 1000, 2);
	}//end persistChunkRows()

	/**
	 * STEP 2: persist the rows to the database, timed.
	 *
	 * The pre-update rows the persist step fetches exist for ONE purpose:
	 * giving an audit entry or an update event its old-vs-new changeset. This
	 * is the only layer that knows whether either will happen, so it is the
	 * only layer that can say the wide read is not needed — hence the flag
	 * travelling down rather than each layer guessing.
	 *
	 * With both off, the probe selects `_uuid` alone and is answered from the
	 * index without touching the heap. Measured on this instance: Seq Scan
	 * 1.574 ms vs Index Only Scan 0.537 ms per 100 rows, on 2.3 KB rows —
	 * and real tables here run to 4.2 KB a row, where the gap is wider.
	 *
	 * @param BulkChunkRun $chunk The chunk's bulk save call, timings and event targets.
	 * @param array        $rows  The rows to persist.
	 *
	 * @return mixed The bulk operation result from the mapper.
	 */
	private function timedPersist(BulkChunkRun $chunk, array $rows): mixed {
		$run = $chunk->run;
		$phaseStart = microtime(true);
		$bulkResult = $this->persistChunk(
			transformedObjects: $rows,
			register: $run->register,
			schema: $run->schema,
			needsPreUpdateState: ($run->events === true || $run->audit === true || $chunk->eventTargets !== [])
		);
		$chunk->finishPhase(phase: 'persist', start: $phaseStart);

		return $bulkResult;
	}//end timedPersist()

	/**
	 * STEP 3b (BUG-OBJ-1): the audit trail and, when requested, the lifecycle events, timed.
	 *
	 * The ultraFastBulkSave mapper path bypasses the per-row event/audit hooks that the
	 * single-object insert/update apply, so the bulk path must replay them here to stay at
	 * parity with single-object save. Audit rows are written with streamed batched multi-row
	 * INSERTs (100 rows per statement) instead of one INSERT (plus hash-chain queries) per object.
	 *
	 * @param BulkChunkRun $chunk       The chunk's bulk save call, timings and event targets.
	 * @param array        $sideEffects As buildChunkResults() returns them.
	 *
	 * @return void
	 */
	private function timedSideEffects(BulkChunkRun $chunk, array $sideEffects): void {
		$run = $chunk->run;
		$phaseStart = microtime(true);
		$this->emitChunkSideEffects(sideEffects: $sideEffects, _events: $run->events, _audit: $run->audit);
		$this->emitTargetedEvents(sideEffects: $sideEffects, targets: $chunk->eventTargets);
		$chunk->finishPhase(phase: 'sideEffects', start: $phaseStart);
	}//end timedSideEffects()

	/**
	 * The "registerId/schemaId" pairs of a chunk whose bulk-write events are on.
	 *
	 * The pair is the table the row is written to: the caller's register and schema
	 * first, the row's own only where the caller named none. That is the rule the row
	 * screen follows, and the pair the stored objects carry afterwards.
	 *
	 * @param array $transformedObjects The chunk, after the guards.
	 * @param array $context            `events`: whether the caller asked for events on every object
	 *                                  (then no targets are needed); `schemas`: the schema cache;
	 *                                  `register` and `schema`: what the caller passed.
	 *
	 * @return array<string, true>
	 *
	 * @spec openspec/changes/bulk-writes-fire-save-events/specs/object-lifecycle/spec.md#requirement-a-bulk-write-with-events-on-runs-each-rows-pre-save-event-and-refuses-only-the-stopped-row
	 */
	private function bulkEventTargets(array $transformedObjects, array $context): array {
		if ($this->rowRules?->bulkWriteEvents === null || $context['events'] === true) {
			return [];
		}

		$context = $this->withCallEntities(context: $context);
		$targets = [];
		foreach ($this->eventCandidates(rows: $transformedObjects, context: $context) as $key => $candidate) {
			if ($this->rowRules->bulkWriteEvents->appliesTo(schema: $candidate->schema, register: $candidate->register) === true) {
				$targets[$key] = true;
			}
		}

		return $targets;
	}//end bulkEventTargets()

	/**
	 * The bulkEventTargets context with the register and schema the caller named loaded.
	 *
	 * @param array $context The bulkEventTargets context.
	 *
	 * @return array The context, `register` and `schema` now an entity or null.
	 */
	private function withCallEntities(array $context): array {
		$context['register'] = $this->callEntity(target: $context['register'], kind: Register::class);
		$context['schema']   = $this->callEntity(target: $context['schema'], kind: Schema::class);
		return $context;
	}//end withCallEntities()

	/**
	 * The first candidate of every "registerId/schemaId" key in the chunk that has a schema, in row order.
	 *
	 * @param array $rows    The chunk, after the guards.
	 * @param array $context The bulkEventTargets context, with the caller's register and schema loaded.
	 *
	 * @return \Generator<string, BulkEventCandidate>
	 */
	private function eventCandidates(array $rows, array $context): \Generator {
		$asked = [];
		foreach ($rows as $objData) {
			$candidate = $this->eventCandidate(objData: $objData, context: $context);
			$key       = $candidate->key();
			if (isset($asked[$key]) === true || $candidate->schema instanceof Schema === false) {
				continue;
			}

			$asked[$key] = true;
			yield $key => $candidate;
		}
	}//end eventCandidates()

	/**
	 * The schema and register one chunk row is written to: the caller's first, the row's own where the caller named none.
	 *
	 * @param array $objData The chunk row.
	 * @param array $context The bulkEventTargets context, with the caller's register and schema loaded.
	 *
	 * @return BulkEventCandidate
	 */
	private function eventCandidate(array $objData, array $context): BulkEventCandidate {
		return new BulkEventCandidate(
			schema: $this->eventSchema(objData: $objData, context: $context),
			register: ($context['register'] ?? $this->eventRegister(registerId: ($objData['register'] ?? null))),
			rowRegisterId: ($objData['register'] ?? null)
		);
	}//end eventCandidate()

	/**
	 * The schema one chunk row is written to: the caller's, else the row's own from the schema cache.
	 *
	 * @param array $objData The chunk row.
	 * @param array $context The bulkEventTargets context, with the caller's schema loaded.
	 *
	 * @return mixed The schema; null when neither names one.
	 */
	private function eventSchema(array $objData, array $context): mixed {
		return ($context['schema'] ?? ($context['schemas'][$objData['schema'] ?? ''] ?? null));
	}//end eventSchema()

	/**
	 * The register or schema the caller named, loaded; null when it named none or it does not load.
	 *
	 * @param Register|Schema|string|int|null $target What the caller passed.
	 * @param class-string                    $kind   Register::class or Schema::class.
	 *
	 * @return Register|Schema|null
	 */
	private function callEntity(Register|Schema|string|int|null $target, string $kind): Register|Schema|null {
		if ($target instanceof $kind) {
			return $target;
		}

		if (in_array($target, [null, 0, '0', ''], true) === true || is_object($target) === true) {
			return null;
		}

		try {
			return $this->loadCallEntity(target: $target, kind: $kind);
		} catch (\Throwable $e) {
			return null;
		}
	}//end callEntity()

	/**
	 * Load the register or schema a caller named by id.
	 *
	 * @param string|int   $target The id.
	 * @param class-string $kind   Register::class or Schema::class.
	 *
	 * @return Register|Schema
	 */
	private function loadCallEntity(string|int $target, string $kind): Register|Schema {
		if ($kind === Register::class) {
			return $this->loadRegisterWithCache(registerId: $target);
		}

		return $this->loadSchemaWithCache(schemaId: $target);
	}//end loadCallEntity()

	/**
	 * The register a chunk row names, from the register cache the preparation step filled.
	 *
	 * @param mixed $registerId The row's register id.
	 *
	 * @return Register|null Null when it does not load.
	 */
	private function eventRegister(mixed $registerId): ?Register {
		try {
			return $this->loadRegisterWithCache(registerId: $registerId);
		} catch (\Throwable $e) {
			return null;
		}
	}//end eventRegister()

	/**
	 * Dispatch the post-save events of the objects whose register and schema have the bulk-write events on.
	 *
	 * The audit trail was already written for the whole chunk, so this pass dispatches only.
	 *
	 * @param array               $sideEffects As buildChunkResults() returns them.
	 * @param array<string, true> $targets     The "registerId/schemaId" pairs.
	 *
	 * @return void
	 */
	private function emitTargetedEvents(array $sideEffects, array $targets): void {
		if ($targets === []) {
			return;
		}

		$this->emitChunkSideEffects(
			sideEffects: $this->sideEffectsWithin(sideEffects: $sideEffects, targets: $targets),
			_events: true,
			_audit: false
		);
	}//end emitTargetedEvents()

	/**
	 * A chunk's result before anything is processed.
	 *
	 * @return array
	 */
	private function emptyChunkResult(): array {
		$counters = ['saved' => 0, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0, 'errors' => 0];

		return ['saved' => [], 'updated' => [], 'unchanged' => [], 'invalid' => [], 'errors' => [], 'statistics' => $counters];
	}//end emptyChunkResult()

	/**
	 * The side effects of the objects whose register and schema are among the targets.
	 *
	 * @param array               $sideEffects As buildChunkResults() returns them.
	 * @param array<string, true> $targets     The "registerId/schemaId" pairs.
	 *
	 * @return array{created: list<ObjectEntity>, updated: list<array{old: ObjectEntity|null, new: ObjectEntity}>}
	 */
	private function sideEffectsWithin(array $sideEffects, array $targets): array {
		$inTargets = static fn (ObjectEntity $entity): bool => isset($targets[$entity->getRegister() . '/' . $entity->getSchema()]);

		return [
			'created' => array_values(array_filter(($sideEffects['created'] ?? []), $inTargets)),
			'updated' => array_values(
				array_filter(($sideEffects['updated'] ?? []), static fn (array $pair): bool => $inTargets($pair['new']))
			),
		];
	}//end sideEffectsWithin()

	/**
	 * Enforce per-object RBAC and schema validation before persistence (BUG-OBJ-1).
	 *
	 * Mirrors the guarantees of the single-object save path for the bulk path. Each
	 * transformed object is checked for create permission (when the run's rbac is on) and
	 * validated against its resolved schema (when the run's validation is on). Objects that
	 * fail either check are refused, so they are never persisted.
	 *
	 * @param BulkSaveRun $run                The bulk save call: its schema cache and switches.
	 * @param array       $transformedObjects Flat transformed objects ready for persistence
	 *
	 * @return array{0: array, 1: list<array{object: array, error: string, type: string}>}
	 *               The objects that passed all enabled guards, and the refused ones.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function enforceChunkGuards(BulkSaveRun $run, array $transformedObjects): array {
		// Nothing to enforce — return untouched.
		if ($run->rbac === false && $run->validation === false) {
			return [$transformedObjects, []];
		}

		$run->userId = $this->userSession->getUser()?->getUID();

		return $this->screenChunkRows(run: $run, rows: $transformedObjects);
	}//end enforceChunkGuards()

	/**
	 * Every chunk row through the guards, in order.
	 *
	 * @param BulkSaveRun $run  The bulk save call, its user resolved.
	 * @param array       $rows Flat transformed objects ready for persistence
	 *
	 * @return array{0: array, 1: list<array{object: array, error: string, type: string}>}
	 *               The objects that passed all enabled guards, and the refused ones.
	 */
	private function screenChunkRows(BulkSaveRun $run, array $rows): array {
		$allowedObjects = [];
		$refused = [];
		foreach ($rows as $objData) {
			$refusal = $this->chunkGuardRefusal(run: $run, objData: $objData);
			if ($refusal !== null) {
				$refused[] = ['object' => $objData] + $refusal;
				continue;
			}

			$allowedObjects[] = $objData;
		}

		return [$allowedObjects, $refused];
	}//end screenChunkRows()

	/**
	 * Why the guards refuse one chunk row, or null when they admit it.
	 *
	 * Without a resolvable schema a row cannot safely be authorized or validated, so it is refused.
	 *
	 * @param BulkSaveRun $run     The bulk save call.
	 * @param array       $objData The transformed row.
	 *
	 * @return array{error: string, type: string}|null The refusal.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function chunkGuardRefusal(BulkSaveRun $run, array $objData): ?array {
		if ($this->guardSchema(run: $run, objData: $objData) === null) {
			return [
				'error' => 'Schema could not be resolved for RBAC/validation enforcement',
				'type' => 'InvalidSchemaException',
			];
		}

		return $this->chunkPermissionRefusal(run: $run, objData: $objData)
			?? $this->chunkValidationRefusal(run: $run, objData: $objData);
	}//end chunkGuardRefusal()

	/**
	 * The schema a chunk row names, from the run's schema cache; null when it names none or the cache holds no schema.
	 *
	 * @param BulkSaveRun $run     The bulk save call.
	 * @param array       $objData The transformed row.
	 *
	 * @return Schema|null The schema.
	 */
	private function guardSchema(BulkSaveRun $run, array $objData): ?Schema {
		$schemaId = $objData['schema'] ?? null;
		if ($schemaId === null || isset($run->schemaCache[$schemaId]) === false) {
			return null;
		}

		$schema = $run->schemaCache[$schemaId];
		if ($schema instanceof Schema === false) {
			return null;
		}

		return $schema;
	}//end guardSchema()

	/**
	 * The refusal of a row the signed-in user may not create (BUG-OBJ-1); null when RBAC is off or the create is allowed.
	 *
	 * @param BulkSaveRun $run     The bulk save call.
	 * @param array       $objData The transformed row.
	 *
	 * @return array{error: string, type: string}|null The refusal.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function chunkPermissionRefusal(BulkSaveRun $run, array $objData): ?array {
		$schema  = $this->guardSchema(run: $run, objData: $objData);
		$handler = $this->permissionHandler;
		if ($run->rbac === false || $handler === null || $schema === null) {
			return null;
		}

		return $this->chunkCreateRefusal(handler: $handler, schema: $schema, run: $run, owner: ($objData['owner'] ?? null));
	}//end chunkPermissionRefusal()

	/**
	 * The refusal of a create the permission handler does not grant, or null.
	 *
	 * @param PermissionHandlerInterface $handler The permission handler.
	 * @param Schema                     $schema  The row's schema.
	 * @param BulkSaveRun                $run     The bulk save call.
	 * @param mixed                      $owner   The row's owner.
	 *
	 * @return array{error: string, type: string}|null The refusal.
	 */
	private function chunkCreateRefusal(PermissionHandlerInterface $handler, Schema $schema, BulkSaveRun $run, mixed $owner): ?array {
		$hasPermission = $handler->hasPermission(
			schema: $schema,
			action: 'create',
			userId: $run->userId,
			objectOwner: $owner,
			_rbac: true,
			register: $this->runRegisterId(run: $run)
		);
		if ($hasPermission === true) {
			return null;
		}

		return ['error' => 'You do not have permission to create objects in this schema', 'type' => 'PermissionDeniedException'];
	}//end chunkCreateRefusal()

	/**
	 * The id of the register a bulk save writes to, or null when the call names none.
	 *
	 * @param BulkSaveRun $run The bulk save call.
	 *
	 * @return string|null The register id.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function runRegisterId(BulkSaveRun $run): ?string {
		$register = $run->register;
		if ($register instanceof Register) {
			$register = $register->getId();
		}

		if ($register === null || $register === '') {
			return null;
		}

		return (string)$register;
	}//end runRegisterId()

	/**
	 * The refusal of a row whose business data fails its schema (BUG-OBJ-1); null when validation is off or it passes.
	 *
	 * @param BulkSaveRun $run     The bulk save call.
	 * @param array       $objData The transformed row.
	 *
	 * @return array{error: string, type: string}|null The refusal.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function chunkValidationRefusal(BulkSaveRun $run, array $objData): ?array {
		$schema    = $this->guardSchema(run: $run, objData: $objData);
		$validator = $this->validateHandler;
		if ($run->validation === false || $validator === null || $schema === null) {
			return null;
		}

		return self::chunkSchemaRefusal(validator: $validator, schema: $schema, data: ($objData['object'] ?? []));
	}//end chunkValidationRefusal()

	/**
	 * The refusal of business data that fails its schema, or null.
	 *
	 * @param ValidateObjectInterface $validator The validator.
	 * @param Schema                  $schema    The row's schema.
	 * @param array                   $data      The row's business data.
	 *
	 * @return array{error: string, type: string}|null The refusal.
	 */
	private static function chunkSchemaRefusal(ValidateObjectInterface $validator, Schema $schema, array $data): ?array {
		$validationResult = $validator->validateObject(object: $data, schema: $schema);
		if ($validationResult->isValid() === true) {
			return null;
		}

		return ['error' => $validator->generateErrorMessage(result: $validationResult), 'type' => 'ValidationException'];
	}//end chunkSchemaRefusal()

	/**
	 * A chunk result with the refused rows added to its invalid rows and counts.
	 *
	 * @param array $result  The chunk result.
	 * @param array $refused The refused rows, as enforceChunkGuards() returns them.
	 *
	 * @return array The chunk result.
	 */
	private function withRefusedRows(array $result, array $refused): array {
		foreach ($refused as $entry) {
			$result['invalid'][] = $entry;
			$result['statistics']['invalid']++;
			$result['statistics']['errors']++;
		}

		return $result;
	}//end withRefusedRows()

	/**
	 * Dispatch lifecycle events and write audit trail for persisted objects (BUG-OBJ-1).
	 *
	 * The ultraFastBulkSave mapper path bypasses the per-row event/audit hooks the
	 * single-object insert/update apply, so the bulk path replays them here. Audit
	 * trail rows are written with batched multi-row INSERTs
	 * ({@see AuditTrailMapper::insertAuditTrails()}) instead of one INSERT per
	 * object, streamed in slices of {@see self::AUDIT_INSERT_CHUNK_SIZE} entries
	 * so peak memory never holds a whole 20k-object chunk's audit entities (each
	 * embeds an old+new payload diff). Update rows carry the REAL pre-update
	 * state so their changeset is an accurate old-vs-new diff.
	 * ObjectCreated/ObjectUpdatedEvent are only dispatched when $_events is true.
	 *
	 * @param array $sideEffects Side-effect payload from buildChunkResults():
	 *                           'created' => list<ObjectEntity>,
	 *                           'updated' => list<array{old: ObjectEntity|null, new: ObjectEntity}>
	 * @param bool $_events Whether to dispatch object lifecycle events
	 * @param bool $_audit Whether to write audit trail rows; the bulk mirror of
	 *                     the single-object path's `silent` flag
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function emitChunkSideEffects(array $sideEffects, bool $_events, bool $_audit = true): void {
		$createdEntities = ($sideEffects['created'] ?? []);
		$updatedEntities = ($sideEffects['updated'] ?? []);

		if ($this->writesChunkAudit(audit: $_audit) === true) {
			$this->insertAuditEntries(auditEntries: $this->chunkAuditEntries(created: $createdEntities, updated: $updatedEntities));
		}

		if ($this->dispatchesChunkEvents(events: $_events) === false) {
			return;
		}

		$this->dispatchCreatedEvents(createdEntities: $createdEntities);
		$this->dispatchUpdatedEvents(updatedEntities: $updatedEntities);
	}//end emitChunkSideEffects()

	/**
	 * Whether the chunk's audit trail is written: an audit mapper is wired and the caller did not opt out.
	 *
	 * `$audit === false` is the bulk mirror of the single-object path's `silent` flag: a caller
	 * that opts out of auditing per object keeps that choice when it moves to the bulk path.
	 *
	 * @param bool $audit Whether the caller asked for audit trail rows.
	 *
	 * @return bool
	 */
	private function writesChunkAudit(bool $audit): bool {
		return $this->auditTrailMapper !== null && $audit === true;
	}//end writesChunkAudit()

	/**
	 * Whether the chunk's lifecycle events are dispatched: asked for, a dispatcher wired, and no system operation.
	 *
	 * A system operation withholds lifecycle events, exactly as the single-object
	 * path does in MagicMapper::suppressLifecycleEvents().
	 *
	 * @param bool $events Whether the caller asked for lifecycle events.
	 *
	 * @return bool
	 */
	private function dispatchesChunkEvents(bool $events): bool {
		return $events === true && $this->eventDispatcher !== null && SystemOperationContext::isActive() === false;
	}//end dispatchesChunkEvents()

	/**
	 * The audit entries of one bulk chunk: a `create` entry per created object
	 * and an `update` entry per updated pair.
	 *
	 * An update without a reconstructed pre-update snapshot falls back to the
	 * persisted entity as `old`, so it is still recorded as an update.
	 *
	 * @param list<ObjectEntity>                                     $created The created entities
	 * @param list<array{old: ObjectEntity|null, new: ObjectEntity}> $updated The updated pairs
	 *
	 * @return list<array{old: ObjectEntity|null, new: ObjectEntity, action: string}>
	 */
	private function chunkAuditEntries(array $created, array $updated): array {
		$auditEntries = [];
		foreach ($created as $entity) {
			$auditEntries[] = ['old' => null, 'new' => $entity, 'action' => 'create'];
		}

		foreach ($updated as $pair) {
			$auditEntries[] = ['old' => ($pair['old'] ?? $pair['new']), 'new' => $pair['new'], 'action' => 'update'];
		}

		return $auditEntries;
	}//end chunkAuditEntries()

	/**
	 * Write audit entries in slices of AUDIT_INSERT_CHUNK_SIZE multi-row inserts.
	 *
	 * Every AuditTrail entity embeds an old+new payload diff, so the entries
	 * are consumed slice by slice and each slice is released once persisted.
	 * A failed slice is logged and the next slice is still written.
	 *
	 * @param array $auditEntries The entries from chunkAuditEntries()
	 *
	 * @return void
	 */
	private function insertAuditEntries(array $auditEntries): void {
		while ($auditEntries !== []) {
			$slice = array_splice($auditEntries, 0, self::AUDIT_INSERT_CHUNK_SIZE);
			try {
				$this->auditTrailMapper->insertAuditTrails(entries: $slice);
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[SaveObjects] Failed to write batched audit trail for bulk chunk',
					context: ['error' => $e->getMessage(), 'entries' => count($slice)]
				);
			}
		}
	}//end insertAuditEntries()

	/**
	 * Dispatch the creation event of every created object; a failing listener is logged.
	 *
	 * @param list<ObjectEntity> $createdEntities The created entities
	 *
	 * @return void
	 */
	private function dispatchCreatedEvents(array $createdEntities): void {
		foreach ($createdEntities as $entity) {
			try {
				(new ObjectEventGate(events: $this->eventDispatcher))->created(object: $entity);
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[SaveObjects] Failed to dispatch ObjectCreatedEvent for bulk object',
					context: ['error' => $e->getMessage(), 'uuid' => $entity->getUuid()]
				);
			}
		}
	}//end dispatchCreatedEvents()

	/**
	 * Dispatch the update event of every updated pair, with the real pre-update
	 * state when available; a failing listener is logged.
	 *
	 * @param list<array{old: ObjectEntity|null, new: ObjectEntity}> $updatedEntities The updated pairs
	 *
	 * @return void
	 */
	private function dispatchUpdatedEvents(array $updatedEntities): void {
		foreach ($updatedEntities as $pair) {
			$entity = $pair['new'];
			try {
				(new ObjectEventGate(events: $this->eventDispatcher))->updated(new: $entity, old: ($pair['old'] ?? $entity));
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[SaveObjects] Failed to dispatch ObjectUpdatedEvent for bulk object',
					context: ['error' => $e->getMessage(), 'uuid' => $entity->getUuid()]
				);
			}
		}
	}//end dispatchUpdatedEvents()

	/**
	 * Convert a raw magic-table result row into an ObjectEntity.
	 *
	 * The bulk mapper returns raw rows (underscore-prefixed metadata columns
	 * plus snake_case property columns). Conversion needs register + schema
	 * context, resolved through the static caches from the row's `_register`
	 * and `_schema` values.
	 *
	 * @param array $row The raw magic-table row
	 *
	 * @return ObjectEntity|null The converted entity, or null when it cannot be built
	 */
	private function convertBulkRowToEntity(array $row): ?ObjectEntity {
		if (($row['_register'] ?? null) === null || ($row['_schema'] ?? null) === null) {
			return null;
		}

		try {
			return $this->objectEntityMapper->convertRowToObjectEntity(
				row: $row,
				_register: $this->loadRegisterWithCache(registerId: $row['_register']),
				_schema: $this->loadSchemaWithCache(schemaId: $row['_schema'])
			);
		} catch (\Throwable $e) {
			$this->logRowConversionFailure(row: $row, error: $e);
			return null;
		}
	}//end convertBulkRowToEntity()

	/**
	 * Log a magic-table row that could not be converted into an entity.
	 *
	 * @param array      $row   The raw row, with its register and schema.
	 * @param \Throwable $error Why it could not be converted.
	 *
	 * @return void
	 */
	private function logRowConversionFailure(array $row, \Throwable $error): void {
		$this->logger->warning(
			message: '[SaveObjects] Failed to convert bulk result row to entity',
			context: [
				'error' => $error->getMessage(),
				'uuid' => ($row['_uuid'] ?? 'unknown'),
				'register' => $row['_register'],
				'schema' => $row['_schema'],
			]
		);
	}//end logRowConversionFailure()

	/**
	 * Transform a chunk of objects to database format and collect invalid objects
	 *
	 * Performs in-place transformation and moves any invalid objects into the result
	 * arrays. Returns only the valid transformed objects ready for persistence.
	 *
	 * @param array $objects The objects to transform
	 * @param array $schemaCache Pre-built schema cache for performance optimization
	 * @param array $result The result array to populate with invalid objects
	 *
	 * @return array Valid transformed objects ready for database operations
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function transformChunk(array $objects, array $schemaCache, array &$result): array {
		$transformationResult = $this->transformObjectsToDatabaseFormatInPlace(objects: $objects, schemaCache: $schemaCache);
		$transformedObjects = $transformationResult['valid'];

		// PERFORMANCE OPTIMIZATION: Batch error processing.
		if (empty($transformationResult['invalid']) === false) {
			$invalidCount = count($transformationResult['invalid']);
			$result['invalid'] = array_merge($result['invalid'], $transformationResult['invalid']);
			$result['statistics']['invalid'] += $invalidCount;
			$result['statistics']['errors'] += $invalidCount;
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
	 * @param Register|string|int|null $register Caller-supplied register context (forwarded to mapper)
	 * @param Schema|string|int|null $schema Caller-supplied schema context (forwarded to mapper)
	 * @param bool $needsPreUpdateState Whether the mapper must read each row's pre-update
	 *                                  state. Only events and audit need it, so the caller
	 *                                  passes false when neither is enabled — that skips a
	 *                                  read per row, which is the point of the bulk path.
	 *
	 * @return mixed The bulk operation result from the mapper
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function persistChunk(
		array $transformedObjects,
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		bool $needsPreUpdateState = true,
	): mixed {
		$this->logBulkPersist(count: count($transformedObjects));

		// MAXIMUM PERFORMANCE: Always use ultra-fast bulk operations for large imports.
		return $this->objectEntityMapper->ultraFastBulkSave(
			insertObjects: $transformedObjects,
			updateObjects: [],
			register: $this->persistRegister(register: $register),
			schema: $this->persistSchema(schema: $schema),
			needsPreUpdateState: $needsPreUpdateState
		);
	}//end persistChunk()

	/**
	 * Debug-log the single-call bulk write of a chunk.
	 *
	 * @param int $count The number of rows written.
	 *
	 * @return void
	 */
	private function logBulkPersist(int $count): void {
		$this->logger->debug(
			'[SaveObjects] Using single-call bulk processing (no pre-lookup needed)',
			[
				'objects_to_process' => $count,
				'approach' => 'INSERT...ON DUPLICATE KEY UPDATE with database-computed classification',
			]
		);
	}//end logBulkPersist()

	/**
	 * The register entity to hand the bulk writer; a register id is resolved, one that fails becomes null.
	 *
	 * The mapper's fallback resolution path goes through @self.* on each row,
	 * which is exactly what resolving the caller's id here avoids.
	 *
	 * @param Register|string|int|null $register The caller-supplied register.
	 *
	 * @return Register|null
	 */
	private function persistRegister(Register|string|int|null $register): ?Register {
		if ($register === null || $register instanceof Register) {
			return $register;
		}

		try {
			return $this->registerMapper->find($register);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[SaveObjects] Failed to resolve register for bulk save',
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $register]
			);
			return null;
		}
	}//end persistRegister()

	/**
	 * The schema entity to hand the bulk writer; a schema id is resolved, one that fails becomes null.
	 *
	 * @param Schema|string|int|null $schema The caller-supplied schema.
	 *
	 * @return Schema|null
	 */
	private function persistSchema(Schema|string|int|null $schema): ?Schema {
		if ($schema === null || $schema instanceof Schema) {
			return $schema;
		}

		try {
			return $this->schemaMapper->find($schema);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[SaveObjects] Failed to resolve schema for bulk save',
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $schema]
			);
			return null;
		}
	}//end persistSchema()

	/**
	 * Build chunk results by classifying bulk operation output into saved/updated/unchanged
	 *
	 * Processes the bulk result from the database, classifying each object by its
	 * database-computed status (created, updated, unchanged) and populating the
	 * result arrays accordingly.
	 *
	 * @param mixed $bulkResult The raw result from ultraFastBulkSave
	 * @param array $transformedObjects The original transformed objects (for fallback)
	 * @param array $result The result array to populate
	 *
	 * @return array Side-effect payload: 'created' => list<ObjectEntity>,
	 *               'updated' => list<array{old: ObjectEntity|null, new: ObjectEntity}>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function buildChunkResults(mixed $bulkResult, array $transformedObjects, array &$result): array {
		if (is_array($bulkResult) === false) {
			return $this->unexpectedChunkResults(transformedObjects: $transformedObjects, result: $result);
		}

		// Check if we got complete objects (new approach) or just UUIDs (fallback).
		// The mapper contract is raw magic-table rows carrying an `object_status`
		// field — the previous gate looked for `created`/`updated` keys that raw
		// rows never have (they are `_created`/`_updated`), which sent every bulk
		// result down the legacy path and silently dropped classification, audit
		// trails, and events.
		$firstItem = reset($bulkResult);
		if (is_array($firstItem) === true && isset($firstItem['object_status']) === true) {
			// NEW APPROACH: Complete objects with database-computed classification returned.
			return $this->classifyDatabaseComputedResults(bulkResult: $bulkResult, result: $result);
		}

		return $this->legacyChunkResults(bulkResult: $bulkResult, transformedObjects: $transformedObjects, result: $result);
	}//end buildChunkResults()

	/**
	 * Fallback for an unexpected bulk result format: every row counts as saved, without side effects.
	 *
	 * @param array $transformedObjects The chunk's rows.
	 * @param array $result             The result array to populate.
	 *
	 * @return array The (empty) side effects.
	 */
	private function unexpectedChunkResults(array $transformedObjects, array &$result): array {
		$this->logger->warning('[SaveObjects] Unexpected bulk result format, using fallback');
		foreach ($transformedObjects as $objData) {
			$result['saved'][] = $objData;
			$result['statistics']['saved']++;
		}

		return ['created' => [], 'updated' => []];
	}//end unexpectedChunkResults()

	/**
	 * FALLBACK: a UUID array returned (legacy behavior).
	 *
	 * This path cannot reconstruct entities, so it yields NO side effects: no audit
	 * rows are written and no lifecycle events are dispatched for these objects.
	 *
	 * @param array $bulkResult         The UUID array from bulk save.
	 * @param array $transformedObjects The chunk's rows.
	 * @param array $result             The result array to populate.
	 *
	 * @return array The (empty) side effects.
	 */
	private function legacyChunkResults(array $bulkResult, array $transformedObjects, array &$result): array {
		$this->logger->warning(
			message: '[SaveObjects] Legacy uuid-array bulk result — audit trail and lifecycle events are skipped for this chunk',
			context: ['objects' => count($bulkResult)]
		);
		$this->classifyLegacyResults(bulkResult: $bulkResult, transformedObjects: $transformedObjects, result: $result);

		return ['created' => [], 'updated' => []];
	}//end legacyChunkResults()

	/**
	 * Classify bulk results using database-computed object_status field
	 *
	 * Processes complete objects returned by the database with pre-computed status
	 * (created, updated, unchanged) and populates the result arrays.
	 *
	 * @param array $bulkResult The complete objects from bulk save with object_status
	 * @param array $result The result array to populate
	 *
	 * @return array Side-effect payload: 'created' => list<ObjectEntity>,
	 *               'updated' => list<array{old: ObjectEntity|null, new: ObjectEntity}>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function classifyDatabaseComputedResults(array $bulkResult, array &$result): array {
		$this->logger->debug('[SaveObjects] Processing complete objects with database-computed classification');

		$sideEffects = ['created' => [], 'updated' => []];
		$counts      = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
		foreach ($bulkResult as $completeObject) {
			$row = $this->splitBulkRow(completeObject: $completeObject);
			$row['status'] = $this->knownBulkStatus(row: $row);
			self::addClassifiedRow(row: $row, result: $result);
			$counts[$row['status']]++;
			$sideEffects = $this->withBulkSideEffect(sideEffects: $sideEffects, row: $row);
		}

		$this->logClassification(total: count($bulkResult), counts: $counts);

		return $sideEffects;
	}//end classifyDatabaseComputedResults()

	/**
	 * Add a classified row's response shape to the bucket of its status, and count it.
	 *
	 * @param array $row    The row as splitBulkRow() returns it, with its known status.
	 * @param array $result The result array to populate.
	 *
	 * @return void
	 */
	private static function addClassifiedRow(array $row, array &$result): void {
		$bucket = self::STATUS_BUCKETS[$row['status']];
		$result[$bucket][] = $row['serialized'];
		$result['statistics'][$bucket]++;
	}//end addClassifiedRow()

	/**
	 * Debug-log the counts of a database-computed classification.
	 *
	 * @param int   $total  The number of rows classified.
	 * @param array $counts The created, updated and unchanged counts.
	 *
	 * @return void
	 */
	private function logClassification(int $total, array $counts): void {
		$this->logger->debug(
			'[SaveObjects] Database-computed classification completed',
			[
				'total_processed' => $total,
				'created_objects' => $counts['created'],
				'updated_objects' => $counts['updated'],
				'unchanged_objects' => $counts['unchanged'],
				'classification_method' => 'database_computed_sql',
			]
		);
	}//end logClassification()

	/**
	 * Split one database-classified bulk row into its status, pre-update row, entity and response shape.
	 *
	 * The pre-update row (updates only) is kept for the audit diff, and internal bookkeeping
	 * fields are kept out of the raw row before conversion, so the response carries the same
	 * serialized shape as the single-object save path.
	 *
	 * @param array $completeObject The row with object_status as the database returned it.
	 *
	 * @return array{status: mixed, preUpdateRow: mixed, row: array, entity: ObjectEntity|null, serialized: array}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function splitBulkRow(array $completeObject): array {
		$objectStatus = $completeObject['object_status'] ?? 'unknown';
		$preUpdateRow = ($completeObject['_pre_update_row'] ?? null);
		unset($completeObject['_pre_update_row'], $completeObject['object_status'], $completeObject['operation_start_time']);
		$entity = $this->convertBulkRowToEntity(row: $completeObject);

		return [
			'status' => $objectStatus,
			'preUpdateRow' => $preUpdateRow,
			'row' => $completeObject,
			'entity' => $entity,
			'serialized' => ($entity?->jsonSerialize() ?? $completeObject),
		];
	}//end splitBulkRow()

	/**
	 * The row's status when the database gave a known one; otherwise a warning and 'unchanged'.
	 *
	 * @param array $row The row as splitBulkRow() returns it.
	 *
	 * @return string 'created', 'updated' or 'unchanged'.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function knownBulkStatus(array $row): string {
		$objectStatus = $row['status'];
		if (in_array($objectStatus, ['created', 'updated', 'unchanged'], true) === true) {
			return $objectStatus;
		}

		$this->logger->warning(
			"Unexpected object status: {$objectStatus}",
			[
				'uuid' => ($row['row']['_uuid'] ?? $row['row']['uuid'] ?? 'unknown'),
				'object_status' => $objectStatus,
			]
		);
		return 'unchanged';
	}//end knownBulkStatus()

	/**
	 * The side effects with a created or updated row's entity added; unchanged rows and rows without an entity add nothing.
	 *
	 * @param array $sideEffects The side effects so far.
	 * @param array $row         The row as splitBulkRow() returns it, with its known status.
	 *
	 * @return array The side effects.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function withBulkSideEffect(array $sideEffects, array $row): array {
		if ($row['entity'] === null || $row['status'] === 'unchanged') {
			return $sideEffects;
		}

		if ($row['status'] === 'created') {
			$sideEffects['created'][] = $row['entity'];
			return $sideEffects;
		}

		$sideEffects['updated'][] = ['old' => $this->preUpdateEntity(row: $row), 'new' => $row['entity']];

		return $sideEffects;
	}//end withBulkSideEffect()

	/**
	 * The entity of an updated row's pre-update state, or null when the database returned none.
	 *
	 * @param array $row The row as splitBulkRow() returns it.
	 *
	 * @return ObjectEntity|null
	 */
	private function preUpdateEntity(array $row): ?ObjectEntity {
		if (is_array($row['preUpdateRow']) === false) {
			return null;
		}

		return $this->convertBulkRowToEntity(row: $row['preUpdateRow']);
	}//end preUpdateEntity()

	/**
	 * Classify bulk results using legacy UUID-based approach
	 *
	 * Falls back to matching UUIDs against input objects when the database
	 * does not return complete objects with status classification.
	 *
	 * @param array $bulkResult The UUID array from bulk save
	 * @param array $transformedObjects The original transformed objects
	 * @param array $result The result array to populate
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function classifyLegacyResults(array $bulkResult, array $transformedObjects, array &$result): void {
		$this->logger->debug('[SaveObjects] Processing UUID array (legacy mode)');

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

		$this->logger->debug('[SaveObjects] Using fallback object reconstruction');
	}//end classifyLegacyResults()

	/**
	 * Handle bulk inverse relations using cached schema analysis
	 *
	 * PERFORMANCE OPTIMIZATION: This method uses pre-analyzed inverse relation properties
	 * to process relations without re-analyzing schema properties for each object.
	 *
	 * @param array $preparedObjects Prepared objects to process
	 * @param array $schemaAnalysis Pre-analyzed schema information indexed by schema ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function handleBulkInverseRelationsWithAnalysis(array &$preparedObjects, array $schemaAnalysis): void {
		$relations = new BulkInverseRelations(
			schemaAnalysis: $schemaAnalysis,
			isUuid: static fn (string $candidate): bool => Uuid::isValid($candidate)
		);
		$relations->apply(objects: $preparedObjects);
	}//end handleBulkInverseRelationsWithAnalysis()

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
	 * @param array $object The object data to process
	 * @param Schema $schema The schema containing property definitions
	 * @param string|null $uuid The UUID of the parent object (will be generated if null)
	 *
	 * @return array Array containing [processedObject, parentUuid]
	 *
	 * @throws Exception If there's an error during object creation
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function handlePreValidationCascading(array $object, Schema $schema, ?string $uuid): array {
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
	 * @param array $objects Objects to transform
	 * @param array $schemaCache Schema cache for metadata field resolution
	 *
	 * @return array Transformed objects ready for database operations, and the invalid ones
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function transformObjectsToDatabaseFormatInPlace(array &$objects, array $schemaCache): array {
		$transformedObjects = [];
		$invalidObjects     = [];
		foreach ($objects as $index => $object) {
			$selfData = $this->identifiedSelfData(object: $object);

			// Validate required fields; skip invalid objects.
			$validationError = $this->validateObjectRequiredFields(selfData: $selfData, object: $object, index: $index, schemaCache: $schemaCache);
			if ($validationError !== null) {
				$invalidObjects[] = $validationError;
				continue;
			}

			$transformedObjects[] = $this->databaseRow(selfData: $selfData, object: $object, schemaCache: $schemaCache);
		}

		return ['valid' => $transformedObjects, 'invalid' => $invalidObjects];
	}//end transformObjectsToDatabaseFormatInPlace()

	/**
	 * The @self data of an object with its identifiers (uuid, id, register, schema) generated or validated.
	 *
	 * Objects from prepareSingleSchemaObjectsOptimized are already flat $selfData arrays:
	 * they don't have an '@self' key because they ARE the self data. Only extract @self if
	 * it exists (mixed schema or other paths).
	 *
	 * @param array $object The object.
	 *
	 * @return array
	 */
	private function identifiedSelfData(array $object): array {
		$selfData = $object;
		if (isset($object['@self']) === true) {
			$selfData = $object['@self'];
		}

		return $this->generateObjectIdentifiers(selfData: $selfData, object: $object);
	}//end identifiedSelfData()

	/**
	 * The database row of a valid object: ownership and organisation metadata, business data and relations.
	 *
	 * The schema-cache entry is handed over as-is: extractBusinessData() decides what counts as a
	 * usable schema, so a cache miss or an unexpected value is judged in ONE place instead of at
	 * every call site (openregister#2781).
	 *
	 * @param array $selfData    The object's @self data with identifiers.
	 * @param array $object      The object.
	 * @param array $schemaCache The schema cache.
	 *
	 * @return array
	 */
	private function databaseRow(array $selfData, array $object, array $schemaCache): array {
		$selfData = $this->hydrateObjectMetadataFields(selfData: $selfData);
		$this->logger->debug(
			'[SaveObjects] DEBUG - Mixed schema object structure',
			['available_keys' => array_keys($object), 'has_object_property' => isset($object['object'])]
		);

		$businessData = $this->extractBusinessData(object: $object, schema: ($schemaCache[$selfData['schema']] ?? null));
		$selfData     = $this->withScannedRelations(selfData: $selfData, businessData: $businessData, schemaCache: $schemaCache);

		// Store the clean business data in the database object column.
		$selfData['object'] = $businessData;

		return $selfData;
	}//end databaseRow()

	/**
	 * The @self data with the relations scanned from the business data (UUIDs and URLs),
	 * unless the preparation phase already set them or the schema is not in the cache.
	 *
	 * @param array $selfData     The @self data.
	 * @param array $businessData The business data.
	 * @param array $schemaCache  The schema cache.
	 *
	 * @return array
	 */
	private function withScannedRelations(array $selfData, array $businessData, array $schemaCache): array {
		if (isset($selfData['relations']) === true && empty($selfData['relations']) === false) {
			$this->logRelationCount(message: '[SaveObjects] Relations already set from preparation', selfData: $selfData);
			return $selfData;
		}

		if (isset($schemaCache[$selfData['schema']]) === false) {
			return $selfData;
		}

		$run = new BulkRelationRun(schema: $schemaCache[$selfData['schema']]);
		$selfData['relations'] = (new BulkRelationScanner())->scan(data: $businessData, run: $run);
		$this->logRelationCount(message: '[SaveObjects] Relations scanned in transformation', selfData: $selfData);

		return $selfData;
	}//end withScannedRelations()

	/**
	 * Debug-log the relation count of an object.
	 *
	 * @param string $message  The message.
	 * @param array  $selfData The @self data carrying uuid and relations.
	 *
	 * @return void
	 */
	private function logRelationCount(string $message, array $selfData): void {
		$this->logger->debug($message, ['uuid' => $selfData['uuid'] ?? 'unknown', 'relationCount' => count($selfData['relations'])]);
	}//end logRelationCount()

	/**
	 * Generate or validate object identifiers (uuid, id, register, schema)
	 *
	 * Ensures the object has a valid UUID (accepting any non-empty string) and
	 * wires register/schema IDs from the object data.
	 *
	 * @param array $selfData The @self metadata array
	 * @param array $object The full object data for fallback values
	 *
	 * @return array Updated selfData with identifiers set
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function generateObjectIdentifiers(array $selfData, array $object): array {
		// The same id rule as the single-schema preparation: CSV 'id' column first, then @self.id.
		$selfData = $this->withBulkObjectId(selfData: $selfData, object: $object);

		// CRITICAL FIX: Use register and schema from method parameters if not provided in object data.
		$selfData['register'] = $selfData['register'] ?? $object['register'] ?? null;
		$selfData['schema'] = $selfData['schema'] ?? $object['schema'] ?? null;

		return $selfData;
	}//end generateObjectIdentifiers()

	/**
	 * Validate that required fields (register, schema) are set and schema exists in cache
	 *
	 * Returns null if valid, or an error array describing the validation failure.
	 *
	 * @param array $selfData The @self metadata with identifiers
	 * @param array $object The full object data for error context
	 * @param int $index The object index for error reporting
	 * @param array $schemaCache The schema cache to validate against
	 *
	 * @return array|null Null if valid, error array if invalid
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateObjectRequiredFields(array $selfData, array $object, int $index, array $schemaCache): ?array {
		$failure = self::requiredFieldFailure(selfData: $selfData, schemaCache: $schemaCache);
		if ($failure === null) {
			return null;
		}

		return ['object' => $object, 'error' => $failure[0], 'index' => $index, 'type' => $failure[1]];
	}//end validateObjectRequiredFields()

	/**
	 * The error and exception type of a missing register, a missing schema or a schema not in the cache; null when valid.
	 *
	 * @param array $selfData    The @self metadata with identifiers.
	 * @param array $schemaCache The schema cache to validate against.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function requiredFieldFailure(array $selfData, array $schemaCache): ?array {
		if ($selfData['register'] === false) {
			return ['Register ID is required but not found in object data or method parameters', 'MissingRegisterException'];
		}

		if ($selfData['schema'] === false) {
			return ['Schema ID is required but not found in object data or method parameters', 'MissingSchemaException'];
		}

		if (isset($schemaCache[$selfData['schema']]) === false) {
			return ["Schema ID {$selfData['schema']} does not exist or could not be loaded", 'InvalidSchemaException'];
		}

		return null;
	}//end requiredFieldFailure()

	/**
	 * Hydrate ownership and organisation metadata on the selfData array
	 *
	 * Sets owner to current user and organisation to the default if not already provided.
	 *
	 * @param array $selfData The @self metadata array
	 *
	 * @return array Updated selfData with owner and organisation set
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function hydrateObjectMetadataFields(array $selfData): array {
		$currentUser = $this->userSession->getUser();
		$selfData['owner'] = self::stampedOwner(selfData: $selfData, currentUser: $currentUser);

		// SECURITY (BUG-OBJ-15): a client-supplied @self.organisation is only honoured when the
		// caller is an admin OR is a verified member of that organisation. Otherwise we fall back
		// to the caller's active organisation via getOrganisationForNewEntity() — preventing
		// cross-tenant data injection through the bulk endpoint.
		if ($this->callerMayClaimOrganisation(requested: ($selfData['organisation'] ?? null), currentUser: $currentUser) === false) {
			// NO ERROR SUPPRESSION: Let organisation service errors bubble up immediately!
			$selfData['organisation'] = $this->organisationService->getOrganisationForNewEntity();
		}

		return $selfData;
	}//end hydrateObjectMetadataFields()

	/**
	 * The owner a bulk row is stamped with.
	 *
	 * SECURITY (BUG-OBJ-15): mirror single-save setSelfMetadata()/applyOwnerAttribution().
	 * Owner is ALWAYS stamped to the session user and a client-supplied @self.owner is
	 * ignored — accepting it would let any bulk caller forge object ownership. Only when
	 * there is no session user (background/system context) do we fall back to the
	 * (possibly client-supplied) value so system imports keep working; an empty one is null.
	 *
	 * @param array      $selfData    The @self metadata array.
	 * @param IUser|null $currentUser The session user.
	 *
	 * @return mixed
	 */
	private static function stampedOwner(array $selfData, ?IUser $currentUser): mixed {
		if ($currentUser !== null) {
			return $currentUser->getUID();
		}

		if (isset($selfData['owner']) === false || empty($selfData['owner']) === true) {
			return null;
		}

		return $selfData['owner'];
	}//end stampedOwner()

	/**
	 * Whether the caller may keep the organisation the row asks for: an administrator, or a verified member of it.
	 *
	 * @param mixed      $requested   The client-supplied @self.organisation.
	 * @param IUser|null $currentUser The session user.
	 *
	 * @return bool False when the row asks for none.
	 */
	private function callerMayClaimOrganisation(mixed $requested, ?IUser $currentUser): bool {
		if (empty($requested) === true) {
			return false;
		}

		return $this->sessionUserIsAdmin(currentUser: $currentUser) === true
			|| $this->organisationService->hasAccessToOrganisation((string) $requested) === true;
	}//end callerMayClaimOrganisation()

	/**
	 * Whether the session user is an administrator; false without a user or a group manager.
	 *
	 * @param IUser|null $currentUser The session user.
	 *
	 * @return bool
	 */
	private function sessionUserIsAdmin(?IUser $currentUser): bool {
		return $currentUser !== null
			&& $this->groupManager !== null
			&& $this->groupManager->isAdmin($currentUser->getUID()) === true;
	}//end sessionUserIsAdmin()

	/**
	 * Extract business data from an object, separating it from metadata fields
	 *
	 * Supports both new structure (object property contains business data) and
	 * legacy structure (metadata fields mixed with business data).
	 *
	 * @param array $object The full object data
	 * @param mixed $schema The schema this object is saved against, used to tell
	 *                      a declared property from a metadata column of the same
	 *                      name. Deliberately untyped: callers pass a schema-cache
	 *                      lookup straight through, and anything that is not a
	 *                      Schema — a miss, a null, a stale entry — means "no
	 *                      declaration information", which is the pre-existing
	 *                      behaviour. Judging that here keeps one rule in one
	 *                      place rather than an `instanceof` at every call site.
	 *
	 * @return array The extracted business data
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function extractBusinessData(array $object, mixed $schema = null): array {
		if (isset($object['object']) === true && is_array($object['object']) === true) {
			// NEW STRUCTURE: object property contains business data.
			$this->logger->debug('[SaveObjects] Using object property for business data (mixed)');
			return $object['object'];
		}

		// LEGACY STRUCTURE: Remove metadata fields to isolate business data.
		$kept         = self::declaredMetadataFields(schema: $schema);
		$businessData = self::withoutMetadataFields(object: $object, kept: $kept);
		$this->logMetadataRemoval(object: $object, kept: $kept, businessData: $businessData);

		return $businessData;
	}//end extractBusinessData()

	/**
	 * The OVERLAPPING metadata names the schema declares as properties; they stay business data.
	 *
	 * The five OVERLAPPING names are magic-table columns AND perfectly legal
	 * schema property names. Stripping them unconditionally silently threw
	 * away the caller's value, and when the property was `required` the
	 * object then failed validation for "the required property (name) is
	 * missing" -- refusing the whole batch over a field that WAS supplied.
	 * The single-object path never stripped them, so the same payload
	 * behaved differently depending on which endpoint received it
	 * (openregister#2781).
	 *
	 * A declared property wins: it stays in the business data. The metadata
	 * column is still populated separately from objectNameField and friends,
	 * so the value ends up in both places, which is what the magic table is
	 * for.
	 *
	 * @param mixed $schema The schema; anything else declares nothing.
	 *
	 * @return string[]
	 */
	private static function declaredMetadataFields(mixed $schema): array {
		if ($schema instanceof Schema === false) {
			return [];
		}

		$declared = array_keys($schema->getProperties() ?? []);

		return array_values(array_filter(self::OVERLAPPING_METADATA_FIELDS, static fn (string $field): bool => in_array($field, $declared, true)));
	}//end declaredMetadataFields()

	/**
	 * The object without its metadata fields, except the declared ones kept as business data.
	 *
	 * @param array    $object The object.
	 * @param string[] $kept   The metadata names kept.
	 *
	 * @return array
	 */
	private static function withoutMetadataFields(array $object, array $kept): array {
		foreach (array_diff(self::metadataFields(), $kept) as $field) {
			unset($object[$field]);
		}

		return $object;
	}//end withoutMetadataFields()

	/**
	 * Every metadata name: the structural ones, then the overlapping ones.
	 *
	 * @return string[]
	 */
	private static function metadataFields(): array {
		return array_merge(self::STRUCTURAL_METADATA_FIELDS, self::OVERLAPPING_METADATA_FIELDS);
	}//end metadataFields()

	/**
	 * Debug-log which metadata fields were removed, which were kept as schema properties, and what remains.
	 *
	 * @param array    $object       The object.
	 * @param string[] $kept         The metadata names kept.
	 * @param array    $businessData The business data that remains.
	 *
	 * @return void
	 */
	private function logMetadataRemoval(array $object, array $kept, array $businessData): void {
		$this->logger->debug(
			'[SaveObjects] Metadata removal applied (mixed)',
			[
				'removed_fields' => array_values(array_diff(array_intersect(self::metadataFields(), array_keys($object)), $kept)),
				'kept_as_schema_properties' => array_values(array_intersect($kept, array_keys($object))),
				'remaining_keys' => array_keys($businessData),
			]
		);
	}//end logMetadataRemoval()

	/**
	 * Reconstruct saved objects without additional database fetch
	 *
	 * PERFORMANCE OPTIMIZATION: Avoids redundant database query by reconstructing
	 * ObjectEntity objects from the already available arrays.
	 *
	 * @param array $insertObjects New objects that were inserted
	 * @param array $updateObjects Existing objects that were updated
	 * @param array $savedObjectIds Array of UUIDs that were saved
	 * @param array $existingObjects Original existing objects cache
	 *
	 * @return array Array of ObjectEntity objects representing saved objects
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function reconstructSavedObjects(array $insertObjects, array $updateObjects, array $savedObjectIds, array $existingObjects): array {
		// Build a lookup set of saved IDs for filtering — only reconstruct objects that were actually saved.
		// Restrict to scalar ids first: array_flip warns and skips any non-scalar
		// (e.g. a null id from a failed save), so filtering yields the same set cleanly.
		$savedIdSet = array_flip(array_filter($savedObjectIds, 'is_scalar'));

		return [
			...$this->reconstructInserted(insertObjects: $insertObjects, savedIdSet: $savedIdSet),
			...$this->reconstructUpdated(updateObjects: $updateObjects, savedIdSet: $savedIdSet, existingObjects: $existingObjects),
		];
	}//end reconstructSavedObjects()

	/**
	 * The inserted rows that were saved, as entities hydrated without inserting.
	 *
	 * @param array $insertObjects The inserted rows.
	 * @param array $savedIdSet    The saved uuids, as keys.
	 *
	 * @return list<ObjectEntity>
	 */
	private function reconstructInserted(array $insertObjects, array $savedIdSet): array {
		$savedObjects = [];
		foreach ($insertObjects as $objData) {
			$obj = $this->insertedEntity(objData: $objData, savedIdSet: $savedIdSet);
			if ($obj !== null) {
				$savedObjects[] = $obj;
			}
		}

		return $savedObjects;
	}//end reconstructInserted()

	/**
	 * One inserted row as an entity, or null when it was not saved.
	 *
	 * CRITICAL FIX: createFromArray() is not used — it tries to insert objects that already
	 * exist. The entity is hydrated without inserting.
	 *
	 * @param array $objData    The row.
	 * @param array $savedIdSet The saved uuids, as keys.
	 *
	 * @return ObjectEntity|null
	 */
	private function insertedEntity(array $objData, array $savedIdSet): ?ObjectEntity {
		// Objects missing UUIDs after save indicate serious database issues: logged, and the other objects go on.
		if (empty($objData['uuid']) === true) {
			$this->logMissingUuid(objData: $objData);
			return null;
		}

		if (isset($savedIdSet[$objData['uuid']]) === false) {
			return null;
		}

		$obj = new ObjectEntity();
		$obj->hydrate($objData);

		return $obj;
	}//end insertedEntity()

	/**
	 * Log a row the bulk save reports saved that has no uuid: it should not happen.
	 *
	 * @param array $objData The row.
	 *
	 * @return void
	 */
	private function logMissingUuid(array $objData): void {
		$this->logger->error(
			'Object reconstruction failed: Missing UUID after bulk save operation',
			[
				'objectKeys' => array_keys($objData),
				'error' => 'UUID missing in saved object data',
				'context' => 'reconstructSavedObjects',
			]
		);
	}//end logMissingUuid()

	/**
	 * The updated entities that were saved, preferring stored data for fields the update omits.
	 *
	 * @param array $updateObjects   The updated entities.
	 * @param array $savedIdSet      The saved uuids, as keys.
	 * @param array $existingObjects The stored entities by uuid.
	 *
	 * @return list<ObjectEntity>
	 */
	private function reconstructUpdated(array $updateObjects, array $savedIdSet, array $existingObjects): array {
		$savedObjects = [];
		foreach ($updateObjects as $obj) {
			if (isset($savedIdSet[$obj->getUuid()]) === true) {
				self::mergeStoredData(obj: $obj, existingObjects: $existingObjects);
				$savedObjects[] = $obj;
			}
		}

		return $savedObjects;
	}//end reconstructUpdated()

	/**
	 * Merge the stored object data under the update's, to preserve fields the update payload omits.
	 *
	 * @param ObjectEntity $obj             The updated entity.
	 * @param array        $existingObjects The stored entities by uuid.
	 *
	 * @return void
	 */
	private static function mergeStoredData(ObjectEntity $obj, array $existingObjects): void {
		$uuid = $obj->getUuid();
		if ($uuid === null || isset($existingObjects[$uuid]) === false) {
			return;
		}

		$obj->setObject(array_merge(($existingObjects[$uuid]->getObject() ?? []), ($obj->getObject() ?? [])));
	}//end mergeStoredData()

	/**
	 * Drop the decision-table rows the single save would have refused.
	 *
	 * The bulk writer dispatches no ObjectCreatingEvent, so the duplicate-key and executability checks of
	 * DecisionTableValidationListener never ran for a bulk save. Runs before the other safeguards, on the caller's
	 * own array positions, so a refusal names the row it is about.
	 *
	 * @param array<int|string, array<string, mixed>> $objects  The raw rows.
	 * @param Register|string|int|null                $register Default register context.
	 * @param Schema|string|int|null                  $schema   Default schema context.
	 * @param array                                   $result   Result accumulator (mutated in place).
	 *
	 * @return array<int|string, array<string, mixed>> The rows that may go on.
	 */
	private function refuseInvalidDecisionTables(
		array $objects,
		Register|string|int|null $register,
		Schema|string|int|null $schema,
		array &$result
	): array {
		$refusals = ($this->rowRules?->decisionTables?->refusals(objects: $objects, register: $register, schema: $schema) ?? []);
		foreach ($refusals as $position => $reason) {
			$this->recordSafeguardRejection(object: $objects[$position], reason: $reason, result: $result, index: self::positionIndex(position: $position));
			unset($objects[$position]);
		}

		return $objects;
	}//end refuseInvalidDecisionTables()

	/**
	 * The caller's own array position of a row as a rejection index; null for a string key.
	 *
	 * @param int|string $position The row's key in the submitted batch.
	 *
	 * @return int|null
	 */
	private static function positionIndex(int|string $position): ?int {
		if (is_int($position) === true) {
			return $position;
		}

		return null;
	}//end positionIndex()
}//end class
