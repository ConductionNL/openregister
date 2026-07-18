<?php

/**
 * OpenRegister ObjectService
 *
 * Service class for managing objects in the OpenRegister application.
 *
 * This service acts as a facade for the various object handlers,
 * coordinating operations between them and maintaining state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Adbar\Dot;
use DateTime;
use Exception;
use stdClass;
use RuntimeException;
use ReflectionClass;
use InvalidArgumentException;
use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\FacetableAnalyzer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\ExportHandler;
use OCA\OpenRegister\Service\Object\VectorizationHandler;
use OCA\OpenRegister\Service\Object\CrudHandler;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCP\AppFramework\Db\DoesNotExistException as OcpDoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Primary Object Management Service for OpenRegister
 *
 * ARCHITECTURE OVERVIEW:
 * This is the main orchestration service that coordinates object operations across the application.
 * It acts as a high-level facade that delegates specific operations to specialized handlers while
 * managing application state, context, and cross-cutting concerns like RBAC, caching, and validation.
 *
 * KEY RESPONSIBILITIES:
 * - Object lifecycle management (find, create, update, delete operations)
 * - Bulk operations orchestration with performance optimizations
 * - Register and Schema context management
 * - RBAC and multi-tenancy enforcement
 * - Search, pagination, and faceting capabilities
 * - Event coordination and audit trail management
 *
 * HANDLER DELEGATION:
 * - Individual object CRUD → SaveObject handler
 * - Bulk operations → Internal optimized methods + SaveObject for complex cases
 * - Validation → ValidateObject handler
 * - Rendering → RenderObject handler
 * - File operations → FileService
 *
 * PERFORMANCE FEATURES:
 * - Comprehensive schema analysis and caching
 * - Memory-optimized bulk operations with pass-by-reference
 * - Single-pass inverse relation processing
 * - Batch database operations
 *
 * ⚠️ IMPORTANT: Do NOT confuse with SaveObject handler!
 * - ObjectService = High-level orchestration and bulk operations
 * - SaveObject = Individual object save/create/update logic with relations handling
 *
 * CODE METRICS JUSTIFICATION:
 * This service is intentionally larger (~2,500 lines) as it serves as the primary facade/coordinator
 * for 54+ public API methods. The size is appropriate because:
 * - It's a FACADE pattern - orchestrates calls to 17+ specialized handlers
 * - All business logic has been extracted to handlers (55% reduction from original)
 * - Remaining code is coordination logic, state management, and context handling
 * - Each public method is appropriately sized (<150 lines) for coordination
 * - Further reduction would require service splitting (architectural change vs refactoring)
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 1.0.0 Initial ObjectService implementation
 * @since 1.5.0 Added bulk operations and performance optimizations
 * @since 2.0.0 Added comprehensive schema analysis and memory optimization
 * @since 2.1.0 Refactored to handler architecture, extracted business logic (55% reduction)
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Facade pattern for object operations requires comprehensive coordination
 * @SuppressWarnings(PHPMD.TooManyMethods)           Many methods required to expose full object management API
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Public API facade requires many public entry points
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex object lifecycle management
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Requires coordination with many specialized handlers
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)     Public API requires many entry points
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)      Boolean flags for RBAC and multitenancy
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ObjectService
{

    /**
     * The current register context.
     *
     * @var Register|null
     */
    private ?Register $currentRegister = null;

    /**
     * The current schema context.
     *
     * @var Schema|null
     */
    private ?Schema $currentSchema = null;

    /**
     * The current object context.
     *
     * @var ObjectEntity|null
     */
    private ?ObjectEntity $currentObject = null;

    /**
     * Request-scoped cache of resolved uuid → (register, schema) contexts.
     *
     * When a uuid is read under a stale URL scope, the scoped lookup misses and
     * find() falls back to an expensive cross-table search (openregister#1520 —
     * that fallback is deliberate and must keep working). Within one request the
     * same uuid is often resolved repeatedly (relation resolution, inverse
     * rendering), and each resolution used to re-miss the scoped path and re-run
     * the cross-table scan. This cache remembers the object's true register and
     * schema after the first successful resolution so subsequent lookups go
     * straight to the right magic table. It is a plain array property, so it
     * lives exactly as long as this service instance (one request).
     *
     * @var array<string, array{register: Register|null, schema: Schema}>
     */
    private array $uuidScopeCache = [];

    // **REMOVED**: Distributed caching mechanisms removed since SOLR is now our index.
    // **REMOVED**: Cache TTL constants removed since SOLR is now our index.

    /**
     * Constructor for ObjectService.
     *
     * @param DataManipulationHandler        $dataManipHandler     Handler for data manipulation operations.
     * @param DeleteObject                   $deleteHandler        Handler for object deletion.
     * @param GetObject                      $getHandler           Handler for object retrieval.
     * @param PermissionHandler              $permissionHandler    Handler for permission checks.
     * @param RenderObject                   $renderHandler        Handler for object rendering.
     * @param SaveObject                     $saveHandler          Handler for individual object saving.
     * @param SaveObjects                    $saveObjectsHandler   Handler for bulk object saving operations.
     * @param SearchQueryHandler             $searchQueryHandler   Handler for search query operations.
     * @param ValidateObject                 $validateHandler      Handler for object validation.
     * @param LockHandler                    $lockHandler          Handler for object locking.
     * @param AuditHandler                   $auditHandler         Handler for audit trail operations.
     * @param RelationHandler                $relationHandler      Handler for object relationships.
     * @param MergeHandler                   $mergeHandler         Handler for merge and migration.
     * @param FacetHandler                   $facetHandler         Handler for facet operations.
     * @param MetadataHandler                $metadataHandler      Handler for metadata operations.
     * @param PerformanceOptimizationHandler $perfOptHandler       Handler for performance optimization.
     * @param QueryHandler                   $queryHandler         Handler for query operations.
     * @param RevertHandler                  $revertHandler        Handler for revert operations.
     * @param UtilityHandler                 $utilityHandler       Handler for utility operations.
     * @param ValidationHandler              $validationHandler    Handler for validation operations.
     * @param CascadingHandler               $cascadingHandler     Handler for cascading operations.
     * @param MigrationHandler               $migrationHandler     Handler for migration operations.
     * @param RegisterMapper                 $registerMapper       Mapper for register operations.
     * @param SchemaMapper                   $schemaMapper         Mapper for schema operations.
     * @param ViewMapper                     $viewMapper           Mapper for view operations.
     * @param MagicMapper                    $objectMapper         Unified mapper for object
     *                                                             operations (routes to
     *                                                             magic tables).
     * @param FileService                    $fileService          Service for file operations.
     * @param IUserSession                   $userSession          User session for getting current user.
     * @param SearchTrailService             $searchTrailService   Service for search trail operations.
     * @param IGroupManager                  $groupManager         Group manager for checking user groups.
     * @param IUserManager                   $userManager          User manager for getting user objects.
     * @param OrganisationService            $organisationService  Service for organisation operations.
     * @param LoggerInterface                $logger               Logger for performance monitoring.
     * @param CacheHandler                   $cacheHandler         Service for entity and query caching.
     * @param SettingsService                $settingsService      Service for settings operations.
     * @param DateTimeNormalizer             $dateTimeNormalizer   Normaliser for user-supplied datetime input.
     * @param IAppContainer                  $container            Application container.
     * @param ObjectSourceRegistry           $objectSourceRegistry Registry of object-source providers (virtual schemas).
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     */
    public function __construct(
        // Legacy handlers - TESTING:..
        private readonly DataManipulationHandler $dataManipHandler,
        private readonly DeleteObject $deleteHandler,
        private readonly GetObject $getHandler,
        private readonly PermissionHandler $permissionHandler,
        private readonly RenderObject $renderHandler,
        private readonly SaveObject $saveHandler,
        private readonly SaveObjects $saveObjectsHandler,
        private readonly SearchQueryHandler $searchQueryHandler,
        private readonly ValidateObject $validateHandler,
        // New handlers - TESTING FIRST 5:.
        private readonly LockHandler $lockHandler,
        private readonly AuditHandler $auditHandler,
        private readonly RelationHandler $relationHandler,
        private readonly MergeHandler $mergeHandler,
        // REFACTORED: CrudHandler removed - was unimplemented stub causing circular dependency.
        // REFACTORED: BulkOperationsHandler removed - retired with blob objects table.
        // TODO: CIRCULAR DEPENDENCY ISSUE - These handlers still cause timeouts.
        // Temporarily disabled until full architectural refactoring is complete.
        // See DEBUGGING_REGISTER_CREATION_TIMEOUT.md for details.
        // Private readonly ExportHandler $exportHandler,
        // Private readonly VectorizationHandler $vectorizationHandler,
        // STILL COMMENTED - Second half:
        // REFACTORED: Re-enabled legacy handlers - they have clean dependencies (no circular loops).
        private readonly FacetHandler $facetHandler,
        private readonly MetadataHandler $metadataHandler,
        private readonly PerformanceOptimizationHandler $perfOptHandler,
        private readonly QueryHandler $queryHandler,
        private readonly RevertHandler $revertHandler,
        private readonly UtilityHandler $utilityHandler,
        private readonly ValidationHandler $validationHandler,
        private readonly CascadingHandler $cascadingHandler,
        private readonly MigrationHandler $migrationHandler,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ViewMapper $viewMapper,
        private readonly MagicMapper $objectMapper,
        private readonly FileService $fileService,
        private readonly IUserSession $userSession,
        private readonly SearchTrailService $searchTrailService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly OrganisationService $organisationService,
        private readonly LoggerInterface $logger,
        private readonly CacheHandler $cacheHandler,
        private readonly SettingsService $settingsService,
        private readonly DateTimeNormalizer $dateTimeNormalizer,
        private readonly IAppContainer $container,
        private readonly ObjectSourceRegistry $objectSourceRegistry
        // TODO: CIRCULAR DEPENDENCY ISSUE - ExportService, ImportService, and VectorizationService
        // These services have deep circular dependencies:
        // - ExportService → uses SaveObjects → potentially loops back
        // - ImportService → SaveObject/SaveObjects → potentially loops back
        // - VectorizationService → strategies that may depend on ObjectService
        // Temporarily disabled until full architectural refactoring is complete.
        // See DEBUGGING_REGISTER_CREATION_TIMEOUT.md for details.
    ) {
        // REFACTORED: Removed ExportHandler and VectorizationHandler to break circular deps.
        // Handlers should not depend on services - using ExportService, ImportService, VectorizationService.
        // **REMOVED**: Cache initialization removed since SOLR is now our index.
        $this->logger->debug(
            message: '[ObjectService] ObjectService constructor completed.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
    }//end __construct()

    /**
     * Check if the current user has permission to perform a specific CRUD action on objects of a given schema

    /**
     * Check permission and throw exception if not granted
     *
     * @param Schema            $schema      Schema to check permissions for
     * @param string            $action      Action to check permission for
     * @param string|null       $userId      User ID to check permissions for
     * @param string|null       $objectOwner Object owner ID
     * @param bool              $_rbac       Whether to enforce RBAC checks
     * @param ObjectEntity|null $object      Optional object entity for conditional authorization matching
     *
     * @return void
     *
     * @throws \Exception If permission is not granted
     */
    private function checkPermission(
        Schema $schema,
        string $action,
        ?string $userId=null,
        ?string $objectOwner=null,
        bool $_rbac=true,
        ?ObjectEntity $object=null
    ): void {
        $this->permissionHandler->checkPermission(
            schema: $schema,
            action: $action,
            userId: $userId,
            objectOwner: $objectOwner,
            _rbac: $_rbac,
            object: $object
        );
    }//end checkPermission()

    /**
     * Run a callable as a trusted system operation.
     *
     * For app-initiated maintenance that legitimately runs without a user
     * session — repair steps triggered from web requests, event listeners
     * reacting to webcron-created objects, boot-time migrations. Inside the
     * callable, RBAC treats the caller as a trusted system principal
     * (mirroring the existing CLI-cron trust) instead of denying every write
     * as anonymous. The elevation is scoped strictly to the callable and is
     * released even when it throws.
     *
     * Only pass operations whose inputs originate from code or the app's own
     * shipped data — never wrap handling of user-supplied request data.
     *
     * @param callable $operation The trusted operation to execute.
     *
     * @return mixed Whatever the callable returns.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext is a static execution-context holder by design
     *
     * @spec openspec/specs/rbac-scopes/spec.md
     *
     * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext::run is the canonical scoped-elevation API
     */
    public function runAsSystem(callable $operation)
    {
        return SystemOperationContext::run($operation);
    }//end runAsSystem()

    /**
     * Set the current register context.
     *
     * @param Register|string|int $register The register object or its ID/UUID
     *
     * @return static Returns self for method chaining
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function setRegister(Register | string | int $register): static
    {
        if (is_string($register) === true || is_int($register) === true) {
            // Resolve the identifier through the mapper. RegisterMapper::find()
            // already provides request-scoped caching (findCache) and supports
            // numeric IDs, UUIDs, and slugs via orX(id, uuid, slug). RBAC and
            // multi-tenancy checks are bypassed: if the user has access to the
            // object, they should be able to access its register.
            $register = $this->registerMapper->find(
                id: $register,
                _rbac: false,
                _multitenancy: false
            );
        }

        $this->currentRegister = $register;
        return $this;
    }//end setRegister()

    /**
     * Set the current schema context.
     *
     * @param Schema|string|int $schema The schema object or its ID/UUID
     *
     * @return static Returns self for method chaining
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function setSchema(Schema | string | int $schema): static
    {
        if (is_string($schema) === true || is_int($schema) === true) {
            // Resolve the identifier through the mapper. SchemaMapper::find()
            // already provides request-scoped caching (findCache) and supports
            // numeric IDs, UUIDs, and slugs via orX(id, uuid, slug).
            try {
                $schema = $this->schemaMapper->find(
                    id: $schema,
                    _rbac: false,
                    _multitenancy: false
                );
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Debug logging to understand WHY schema lookup fails.
                $this->logger->error(
                    message: '[ObjectService] Schema not found during setSchema()',
                    context: [
                        'file'              => __FILE__,
                        'line'              => __LINE__,
                        'schema_identifier' => $schema,
                        'error'             => $e->getMessage(),
                        'trace'             => $e->getTraceAsString(),
                    ]
                );
                // Rethrow the DoesNotExistException so NC's framework dispatcher
                // converts it to a 404 response. Wrapping in ValidationException
                // causes a 500 instead because ValidationException is generic.
                throw $e;
            }//end try
        }//end if

        $this->currentSchema = $schema;
        return $this;
    }//end setSchema()

    /**
     * Get the register entity resolved by the last setRegister() call.
     *
     * Exposes the already-resolved entity so callers (e.g. controllers that
     * resolved slugs via setRegister()) can reuse it instead of re-fetching
     * the same register from the database.
     *
     * @return Register|null The current register entity, or null when no register context is set.
     *
     * @spec exclude Context getter exposing the entity resolved by setRegister(); no business rule.
     */
    public function getCurrentRegisterEntity(): ?Register
    {
        return $this->currentRegister;
    }//end getCurrentRegisterEntity()

    /**
     * Get the schema entity resolved by the last setSchema() call.
     *
     * Exposes the already-resolved entity so callers (e.g. controllers that
     * resolved slugs via setSchema()) can reuse it instead of re-fetching
     * the same schema from the database.
     *
     * @return Schema|null The current schema entity, or null when no schema context is set.
     *
     * @spec exclude Context getter exposing the entity resolved by setSchema(); no business rule.
     */
    public function getCurrentSchemaEntity(): ?Schema
    {
        return $this->currentSchema;
    }//end getCurrentSchemaEntity()

    /**
     * Set the current object context.
     *
     * @param ObjectEntity|string|int $object The object entity or its ID/UUID
     *
     * @return static Returns self for method chaining
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function setObject(ObjectEntity | string | int $object): static
    {
        if (is_string($object) === true || is_int($object) === true) {
            // Look up the object by ID or UUID.
            // Use MagicMapper when register and schema context are available
            // (routes to magic tables for better performance).
            // Fall back to MagicMapper without register/schema context.
            $hasContext = $this->currentRegister !== null && $this->currentSchema !== null;
            if ($hasContext === true) {
                $object = $this->objectMapper->find(
                    identifier: $object,
                    register: $this->currentRegister,
                    schema: $this->currentSchema
                );
            }

            if ($hasContext === false) {
                $object = $this->objectMapper->find($object);
            }
        }

        $this->currentObject = $object;
        return $this;
    }//end setObject()

    /**
     * Get the current object context.
     *
     * @return ObjectEntity|null The current object entity or null if not set.
     *
     * @spec exclude Context getter returning the current object field; no business rule.
     */
    public function getObject(): ?ObjectEntity
    {
        // Return the current object context.
        return $this->currentObject;
    }//end getObject()

    /**
     * Finds an object by ID or UUID and renders it.
     *
     * @param int|string               $id            The object ID or UUID.
     * @param array|null               $_extend       Properties to extend the object with (unused).
     * @param bool                     $files         Whether to include file information.
     * @param Register|string|int|null $register      The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema        The schema object or its ID/UUID.
     * @param bool                     $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool                     $_multitenancy Whether to apply multitenancy filtering (default: true).
     * @param bool                     $_render       Whether to render the entity before returning (default: true).
     *                                                Pass false when the caller performs its own single render
     *                                                (e.g. ObjectsController::show()) so the object is not
     *                                                rendered twice; permission checks and read logging still run.
     *
     * @return ObjectEntity|null The rendered object (or the raw entity when $_render is false) or null.
     *
     * @throws Exception If the object is not found.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex permission and context handling requires multiple branches
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple optional parameters create many execution paths
     *
     * @spec exclude Facade coordinating GetObject + permission check + RenderObject handlers;
     *   read/RBAC/render behavior owned by object-interactions / rbac-scopes / files-render-extension.
     */
    public function find(
        int | string $id,
        ?array $_extend=[],
        bool $files=false,
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $_render=true
    ): ?ObjectEntity {
        // Resolve the call's register / schema, isolating it from any
        // stale `currentRegister` / `currentSchema` state left over from
        // a previous call on this service instance. When the caller
        // omits either argument we want MagicMapper's cross-table search
        // to resolve the object — NOT a previous caller's leftover
        // schema (openbuild#75 / openregister#1520: TransitionController
        // was 500-ing because `objectService->find(id: $uuid)` from
        // `TransitionEngine::transition()` inherited a stale schema and
        // hit the wrong magic table).
        // BUG-OBJ-13 (openregister#1520): find() is a read operation and
        // MUST NOT leave the shared `currentRegister` / `currentSchema`
        // instance state mutated for the next caller. We snapshot the
        // previous context here and restore it in a `finally` below, so any
        // re-anchoring done for this call's rendering / RBAC is local to
        // this invocation only.
        $previousRegister = $this->currentRegister;
        $previousSchema   = $this->currentSchema;

        try {
            $callRegister = null;
            $callSchema   = null;
            if ($register !== null) {
                $this->setRegister(register: $register);
                $callRegister = $this->currentRegister;
            }

            if ($schema !== null) {
                $this->setSchema(schema: $schema);
                $callSchema = $this->currentSchema;
            }

            // Request-scoped uuid → (register, schema) cache: when this uuid was
            // already resolved earlier in this request, target its true context
            // directly. This avoids re-missing the scoped lookup (and re-running
            // the cross-table fallback below) for every repeated relation
            // resolution of the same uuid under a stale URL scope.
            if (is_string($id) === true && isset($this->uuidScopeCache[$id]) === true) {
                $cachedScope = $this->uuidScopeCache[$id];
                if ($cachedScope['register'] !== null) {
                    $this->setRegister(register: $cachedScope['register']);
                    $callRegister = $this->currentRegister;
                }

                $this->setSchema(schema: $cachedScope['schema']);
                $callSchema = $this->currentSchema;
            }

            // Retrieve the object — when both call args are null, MagicMapper
            // falls back to its `findAcrossAllMagicTables` path.
            try {
                $object = $this->getHandler->find(
                    id: $id,
                    register: $callRegister,
                    schema: $callSchema,
                    _extend: $_extend,
                    files: $files,
                    _rbac: $_rbac,
                    _multitenancy: $_multitenancy
                );
            } catch (OcpDoesNotExistException $e) {
                // Cross-schema fallback for relation name-resolution.
                //
                // Relations reference their target by a globally-unique UUID, but
                // the URL/context schema can be stale or point at a sibling schema
                // (e.g. objects/larpingapp/25/<uuid> where the object actually
                // lives in schema 1470). A schema-scoped lookup only inspects one
                // magic table, so it 404s even though the object exists in the
                // register. When the identifier is a UUID (collision-free, unlike a
                // slug or numeric id) we retry across all magic tables and, on a
                // hit, RE-ANCHOR the call's register/schema to the resolved object
                // so RBAC + rendering use the object's true context, not the stale
                // one supplied by the caller.
                // A cached scope that no longer resolves (object deleted or
                // moved mid-request) must not pin future lookups to it.
                if (is_string($id) === true) {
                    unset($this->uuidScopeCache[$id]);
                }

                $canFallBack = (($callSchema !== null || $callRegister !== null)
                    && is_string($id) === true
                    && $this->isUuidFormat(value: $id) === true);
                if ($canFallBack === false) {
                    throw $e;
                }

                $object = $this->getHandler->find(
                    id: $id,
                    register: null,
                    schema: null,
                    _extend: $_extend,
                    files: $files,
                    _rbac: $_rbac,
                    _multitenancy: $_multitenancy
                );

                // Force re-anchoring below to the resolved object's real context.
                $callSchema   = null;
                $callRegister = null;
            }//end try

            // If the object is not found, return null (@psalm-suppress TypeDoesNotContainNull).
            if ($object === null) {
                return null;
            }

            // When the caller did NOT specify register/schema we just did a
            // cross-table find. Re-anchor `currentSchema` / `currentRegister`
            // to the freshly-resolved object so the downstream rendering /
            // RBAC code points at the right context — never at the stale
            // leftover from a previous call. This mutation is undone by the
            // `finally` block before returning to the caller.
            if ($callSchema === null) {
                $this->setSchema(schema: $object->getSchema());
            }

            if ($callRegister === null) {
                $registerRef = $object->getRegister();
                if ($registerRef !== null && $registerRef !== '') {
                    $this->setRegister(register: $registerRef);
                }
            }

            // Remember this uuid's resolved (register, schema) for the rest of
            // the request so repeated lookups skip the scoped-miss + cross-table
            // fallback dance entirely. Only uuids are cached: they are globally
            // unique, unlike slugs or numeric per-table ids.
            if (is_string($id) === true
                && $this->isUuidFormat(value: $id) === true
                && $this->currentSchema !== null
            ) {
                $this->uuidScopeCache[$id] = [
                    'register' => $this->currentRegister,
                    'schema'   => $this->currentSchema,
                ];
            }

            // Check user has permission to read this specific object (includes object owner check).
            // Publication visibility is now handled by RBAC conditional rules with $now variable.
            $this->checkPermission(
                schema: $this->currentSchema,
                action: 'read',
                userId: null,
                objectOwner: $object->getOwner(),
                _rbac: $_rbac,
                object: $object
            );

            // Skip rendering when the caller is the render site itself. The read
            // path stays intact above — retrieval, cross-schema fallback,
            // permission check — but the (expensive) render pass with its
            // writeOnly redaction runs exactly once, in the caller, instead of
            // twice. Used by ObjectsController::show().
            if ($_render === false) {
                // AVG / GDPR per-access read logging still applies to the read.
                $this->logProcessingRead(object: $object);
                return $object;
            }

            // Render the object before returning.
            $registers = null;
            if ($this->currentRegister !== null) {
                $registers = [$this->currentRegister->getId() => $this->currentRegister];
            }

            // Always use the current schema (either provided or derived from object).
            if ($this->currentSchema === null) {
                throw new RuntimeException('Schema must be set before rendering entity.');
            }

            $schemas = [$this->currentSchema->getId() => $this->currentSchema];

            // AVG / GDPR per-access read logging (verwerkingenlogging).
            // Fail-soft and gated on the schema's `x-openregister-processing`
            // opt-in inside ProcessingLogService — never blocks the read.
            $this->logProcessingRead(object: $object);

            return $this->renderHandler->renderEntity(
                entity: $object,
                _extend: $_extend,
                registers: $registers,
                schemas: $schemas,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );
        } finally {
            // BUG-OBJ-13: restore the caller's context so find() has no
            // observable side-effect on shared instance state.
            $this->currentRegister = $previousRegister;
            $this->currentSchema   = $previousSchema;
        }//end try
    }//end find()

    /**
     * Record an AVG processing-log entry for a single object read.
     *
     * Lazily resolves ProcessingLogService from the container (mirrors
     * the audit-trail attribution resolver) so this stays an additive,
     * fail-soft hook with no new constructor dependency and no circular
     * risk. The service itself gates on the schema opt-in and swallows
     * its own errors; the wrapping try/catch is belt-and-braces so a
     * misconfigured container can never break a read.
     *
     * @param ObjectEntity $object The object that was read.
     *
     * @return void
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    private function logProcessingRead(ObjectEntity $object): void
    {
        try {
            $service = $this->container->get(\OCA\OpenRegister\Service\ProcessingLogService::class);
            $service->logRead(object: $object, action: 'read');
            $service->flush();
        } catch (\Throwable $e) {
            // Fail-soft: read logging never breaks or slows the read path.
            $this->logger->debug(
                message: '[AVG] processing-log read hook skipped',
                context: ['exception' => $e->getMessage()]
            );
        }

    }//end logProcessingRead()

    /**
     * Gets an object by its ID without creating an audit trail.
     *
     * This method is used internally by other operations (like UPDATE) that need to
     * retrieve an object without logging the read action.
     *
     * @param string                   $id            The ID of the object to get.
     * @param array|null               $_extend       Properties to extend the object with (unused).
     * @param bool                     $files         Include file information.
     * @param Register|string|int|null $register      The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema        The schema object or its ID/UUID.
     * @param bool                     $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool                     $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The retrieved object.
     *
     * @throws Exception If there is an error during retrieval.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec exclude Facade variant of find() that skips audit logging; read behavior owned by object-interactions / audit-trail-immutable.
     */
    public function findSilent(
        string $id,
        ?array $_extend=[],
        bool $files=false,
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        // Check if a register is provided and set the current register context.
        if ($register !== null) {
            $this->setRegister(register: $register);
        }

        // Check if a schema is provided and set the current schema context.
        if ($schema !== null) {
            $this->setSchema(schema: $schema);
        }

        // Use the silent find method from the GetObject handler.
        return $this->getHandler->findSilent(
            id: $id,
            register: $this->currentRegister,
            schema: $this->currentSchema,
            _extend: $_extend,
            files: $files,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end findSilent()

    /**
     * Find all objects matching the configuration.
     *
     * @param array $config        Configuration array containing:
     *                             - limit: Maximum number of
     *                             objects to return - offset:
     *                             Number of objects to skip -
     *                             filters: Filter criteria -
     *                             sort: Sort criteria - search:
     *                             Search term - extend:
     *                             Properties to extend - files:
     *                             Whether to include file
     *                             information - uses: Filter by
     *                             object usage - register:
     *                             Optional register to filter by
     *                             - schema: Optional schema to
     *                             filter by - unset: Fields to
     *                             unset from results - fields:
     *                             Fields to include in results -
     *                             ids: Array of IDs or UUIDs to
     *                             filter by
     * @param bool  $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool  $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return array Array of objects matching the configuration
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex configuration handling requires multiple branches
     * @SuppressWarnings(PHPMD.NPathComplexity)       Many configuration options create many execution paths
     *
     * @spec exclude Facade preparing config then delegating to the GetObject handler; list/search behavior owned by zoeken-filteren.
     */
    public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
    {
        // Prepare configuration and set context.
        $config = $this->prepareFindAllConfig(config: $config);

        // Delegate the findAll operation to the handler.
        // Pass the resolved register/schema context so MagicMapper::findAll
        // does not bail out with "called without register/schema context".
        $objects = $this->getHandler->findAll(
            limit: $config['limit'] ?? null,
            offset: $config['offset'] ?? null,
            filters: $config['filters'] ?? [],
            sort: $config['sort'] ?? [],
            search: $config['search'] ?? null,
            files: $config['files'] ?? false,
            uses: $config['uses'] ?? null,
            register: $this->currentRegister,
            schema: $this->currentSchema,
            ids: $config['ids'] ?? null,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );

        // Render via renderEntities, which batch-preloads ALL related objects in
        // one query before the per-row render (optimize-object-render-hot-path).
        // A previous code path pre-resolved registers/schemas and looped
        // renderEntity() per row, causing an N+1 on any `?_extend=` list.
        // renderEntities() performs the identical per-row renderEntity() rendering,
        // only with the relation/file caches pre-warmed, so output is unchanged;
        // registers/schemas were a pre-resolution optimization renderEntity()
        // reproduces internally, not a correctness input.
        return $this->renderHandler->renderEntities(
            entities: $objects,
            _extend: ($config['extend'] ?? []),
            _filter: ($config['unset'] ?? null),
            _fields: ($config['fields'] ?? null),
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end findAll()

    /**
     * Prepare findAll configuration and set context.
     *
     * @param array $config Configuration array
     *
     * @return array Prepared configuration
     */
    private function prepareFindAllConfig(array $config): array
    {
        // Convert extend to an array if it's a string.
        if (($config['extend'] ?? null) !== null && is_string($config['extend']) === true) {
            $config['extend'] = explode(',', $config['extend']);
        }

        // Set the current register context if a register is provided, it's not an array, and it's not empty.
        if (isset($config['filters']['register']) === true
            && is_array($config['filters']['register']) === false
            && empty($config['filters']['register']) === false
        ) {
            $this->setRegister(register: $config['filters']['register']);
        }

        // Set the current schema context if a schema is provided, it's not an array, and it's not empty.
        if (isset($config['filters']['schema']) === true
            && is_array($config['filters']['schema']) === false
            && empty($config['filters']['schema']) === false
        ) {
            $this->setSchema(schema: $config['filters']['schema']);
        }

        return $config;
    }//end prepareFindAllConfig()

    /**
     * Counts the number of objects matching the given criteria.
     *
     * @param array $config Configuration array containing:
     *                      - limit: Maximum number of objects to return
     *                      - offset: Number of objects to skip
     *                      - filters: Filter criteria
     *                      - sort: Sort criteria
     *                      - search: Search term
     *                      - extend: Properties to extend
     *                      - files: Whether to include file information
     *                      - uses: Filter by object usage
     *                      - register: Optional register to filter by
     *                      - schema: Optional schema to filter by
     *                      - unset: Fields to unset from results
     *                      - fields: Fields to include in results
     *                      - ids: Array of IDs or UUIDs to filter by
     *
     * @return int The number of matching objects.
     *
     * @throws \Exception If register or schema is not set
     *
     * @spec exclude Facade injecting register/schema context then delegating to ObjectMapper::countAll(); count behavior owned by zoeken-filteren.
     */
    public function count(
        array $config=[]
    ): int {
        // Scope the count to the current register/schema context (set via
        // setRegister()/setSchema()) by passing them to the mapper as typed
        // params. Without them MagicMapper::countAll() sums EVERY register/schema
        // table — i.e. the whole instance — so a context-scoped caller would get
        // the global object total instead of its own. Any extra ad-hoc filters
        // travel in $config['filters'] and are applied within the scoped table(s).
        unset($config['limit']);

        return $this->objectMapper->countAll(
            _filters: $config['filters'] ?? [],
            schema: $this->currentSchema,
            register: $this->currentRegister
        );
    }//end count()

    /**
     * Find objects by their relations.
     *
     * @param string $search       The URI or UUID to search for in relations
     * @param bool   $partialMatch Whether to search for partial matches (default: true)
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\ObjectEntity>
     *
     * @spec exclude One-line delegation to ObjectMapper::findByRelation(); relation lookup owned by nextcloud-entity-relations.
     */
    public function findByRelations(string $search, bool $partialMatch=true): array
    {
        // Use the findByRelation method from MagicMapper to find objects by their relations.
        return $this->objectMapper->findByRelation(uuid: $search, _search: $search, _partialMatch: $partialMatch);
    }//end findByRelations()

    /**
     * Get logs for an object.
     *
     * @param string $uuid          The UUID of the object
     * @param array  $filters       Optional filters to apply
     * @param bool   $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool   $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return \OCA\OpenRegister\Db\AuditTrail[] Array of log entries
     *
     * @psalm-return array<\OCA\OpenRegister\Db\AuditTrail>
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec exclude Find-then-delegate to GetObject::findLogs(); audit-log read owned by audit-trail-immutable.
     */
    public function getLogs(string $uuid, array $filters=[], bool $_rbac=true, bool $_multitenancy=true): array
    {
        // Get logs for the specified object.
        $object = $this->objectMapper->find($uuid);
        $logs   = $this->getHandler->findLogs(object: $object, filters: $filters);

        return $logs;
    }//end getLogs()

    /**
     * Saves an object from an array or ObjectEntity.
     *
     * @param array|ObjectEntity       $object   The object data to save or ObjectEntity instance.
     * @param array|null               $extend   Properties to extend the object with.
     * @param Register|string|int|null $register The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
     * @param string|null              $uuid     The UUID of the object to update (if updating).
     * @param bool                     $_rbac     Whether to apply RBAC checks (default: true).
     * @param bool                     $_multitenancy    Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The saved object.
     *
     * @throws Exception If there is an error during save.
     */

    /**
     * Save a single object (HIGH-LEVEL ORCHESTRATION METHOD)
     *
     * ARCHITECTURAL ROLE:
     * This is a high-level orchestration method that handles context management, permission checks,
     * and delegates the actual saving logic to the SaveObject handler. It manages the application
     * state and cross-cutting concerns before and after the save operation.
     *
     * RESPONSIBILITY SEPARATION:
     * - ObjectService.saveObject() = Context setup, RBAC, state management, rendering
     * - SaveObject.saveObject() = Actual saving logic, relations, validation, database operations
     *
     * WORKFLOW:
     * 1. Set register/schema context
     * 2. Handle ObjectEntity input conversion
     * 3. Perform RBAC permission checks
     * 4. Delegate to SaveObject handler for actual saving
     * 5. Render and return the result
     *
     * FOR BULK OPERATIONS: Use saveObjects() method for optimized bulk processing
     *
     * @param array|ObjectEntity       $object        The object data to save or ObjectEntity instance
     * @param array|null               $extend        Properties to extend the object with
     * @param Register|string|int|null $register      The register object or its ID/UUID
     * @param Schema|string|int|null   $schema        The schema object or its ID/UUID
     * @param string|null              $uuid          The UUID of the object to update (if updating)
     * @param bool                     $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool                     $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param bool                     $silent        Whether to skip audit trail creation and events (default: false)
     * @param array|null               $uploadedFiles Uploaded files from multipart/form-data (optional)
     * @param IUser|null               $currentUser   Explicit acting user for `@self.folder` access checks
     *                                                (forwarded to `ensureObjectFolder` → `assertObjectFolderAccessible`).
     *                                                Defaults to null → `IUserSession::getUser()` resolution.
     *                                                Non-HTTP callers (cron, import pipelines, event listeners)
     *                                                MUST pass an explicit user to avoid the
     *                                                default-deny fall-through on every folder-bound save.
     *
     * @return ObjectEntity The saved and rendered object
     *
     * @throws Exception If there is an error during save
     *
     * @TODO Add property-level RBAC validation here
     * Before saving object data, check if user has permission to create/update specific properties
     * based on property-level authorization arrays in the schema.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Save options are flag-driven; `$currentUser` was added for `@self.folder` access checks.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function saveObject(
        array | ObjectEntity $object,
        ?array $extend=[],
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        ?string $uuid=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $silent=false,
        ?array $uploadedFiles=null,
        ?IUser $currentUser=null
    ): ObjectEntity {
        // Bound the folder-access revalidation cache to this single save call
        // (not the whole FileService/request lifetime), so a cascade save that
        // moves or trashes a folder mid-request can't be waved through on a
        // stale "accessible" verdict from an earlier write.
        $this->fileService->resetFolderAccessRevalidationCache();

        // Set register/schema context.
        $this->setContextFromParameters(
            register: $register,
            schema: $schema
        );

        // Extract UUID and convert ObjectEntity to array if needed.
        [$object, $uuid] = $this->extractUuidAndNormalizeObject(
            object: $object,
            uuid: $uuid
        );

        // Check permissions for CREATE or UPDATE operation.
        $this->checkSavePermissions(
            uuid: $uuid,
            _rbac: $_rbac
        );

        // Reject updates to transferred objects (archiefstatus = overgebracht).
        if ($uuid !== null) {
            $this->rejectIfTransferred(uuid: $uuid);
        }

        // Reject UPDATE operations on append-only schemas (INSERT is still allowed).
        if ($uuid !== null && $this->currentSchema !== null && $this->currentSchema->isAppendOnly() === true) {
            $schemaSlug = $this->currentSchema->getSlug() ?? (string) $this->currentSchema->getId();
            throw new AppendOnlyException(
                schemaIdentifier: $schemaSlug,
                operation: 'update'
            );
        }

        // Track if UUID was originally null (to distinguish user-provided vs auto-generated UUIDs).
        $uuidWasNull = ($uuid === null);

        // Handle cascading relations while preserving context.
        [$object, $uuid] = $this->handleCascadingWithContextPreservation(
            object: $object,
            uuid: $uuid
        );

        // If UUID was null and is now set, mark it as auto-generated in object data.
        // This allows SaveObject to distinguish between user-provided UUIDs (UPDATE)
        // and auto-generated UUIDs (CREATE).
        if ($uuidWasNull === true && $uuid !== null && is_array($object) === true) {
            // Store flag in @self to indicate this is a CREATE operation.
            if (isset($object['@self']) === false || is_array($object['@self']) === false) {
                $object['@self'] = [];
            }

            $object['@self']['_autoGeneratedUuid'] = true;
            $this->logger->debug(
                message: '[ObjectService] UUID auto-generated by CascadingHandler, marking as CREATE operation',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'uuid'     => $uuid,
                    'register' => $this->currentRegister?->getId(),
                    'schema'   => $this->currentSchema?->getId(),
                ]
            );
        }

        // BUG-OBJ-4: applyAlwaysDefaults() and validateObjectIfRequired()
        // both dereference $this->currentSchema (non-nullable param /
        // ->getHardValidation()). If the schema could not be resolved from
        // the request we would otherwise emit a raw TypeError 500 here.
        // Throw a structured ValidationException instead, which the
        // controllers translate into a clean 400 via handleValidationException().
        if ($this->currentSchema === null) {
            throw new ValidationException(
                message: 'Schema could not be resolved for this object; provide a valid register/schema.'
            );
        }

        // Apply "always" defaults BEFORE validation.
        // This ensures computed/derived properties (e.g., dienstType from type) are set
        // before validation runs, allowing them to override invalid incoming values.
        $object = $this->saveHandler->applyAlwaysDefaults(
            schema: $this->currentSchema,
            data: $object
        );

        // Normalize date values BEFORE validation.
        // Accepts datetime input (e.g. "2024-01-15T10:30:00+02:00") for date fields
        // and casts it to date-only (e.g. "2024-01-15") so Opis validation passes.
        $object = $this->normalizeDateValues(object: $object);

        // Auto-seed a graph-lifecycle field from the parent on CREATE only,
        // BEFORE validation, so a required `$ref` lifecycle field passes on a
        // seeded create. $uuidWasNull is the create signal (updates always
        // carry a UUID); the seed itself is empty-field-only and fail-soft, so
        // it never overwrites a client-supplied value. See the object-lifecycle
        // spec: fk-graph-lifecycle-transitions.
        if ($uuidWasNull === true && is_array($object) === true) {
            $object = $this->saveHandler->seedLifecycleFieldOnCreate(
                schema: $this->currentSchema,
                data: $object
            );
        }

        // Validate if hard validation is enabled.
        $this->validateObjectIfRequired(object: $object);

        // Wave-12 Fix 1: enforce JSON-Schema `readOnly: true` on UPDATE.
        // Skipped on CREATE (no prior value to violate). Loads the existing
        // object exactly once so the check is data-driven, not metadata-only.
        $this->enforceReadOnlyOnUpdate(object: $object, uuid: $uuid);

        // Ensure folder exists for the object.
        $folderId = $this->ensureObjectFolder(uuid: $uuid, currentUser: $currentUser);

        // Clear request-scoped caches before starting a new top-level save operation.
        // This ensures cascade operations benefit from caching while avoiding stale data.
        $this->saveHandler->clearAllCaches();

        // Delegate to SaveObject handler for actual save operation.
        $savedObject = $this->saveHandler->saveObject(
            register: $this->currentRegister,
            schema: $this->currentSchema,
            data: $object,
            uuid: $uuid,
            folderId: $folderId,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            persist: true,
            silent: $silent,
            _validation: true,
            uploadedFiles: $uploadedFiles,
            currentUser: $currentUser
        );

        // Invalidate contact matching cache for objects with email properties.
        // BUG-OBJ-9: invalidate against the SAVED object's data (final UUID +
        // applied defaults), not the pre-save input array, so an email value
        // injected by a default/computed property is also invalidated.
        try {
            $container = \OC::$server;
            if ($container !== null) {
                $contactMatchingService = $container->get(
                    \OCA\OpenRegister\Service\ContactMatchingService::class
                );
                $contactMatchingService->invalidateCacheForObject($savedObject->getObject());
            }
        } catch (\Throwable $e) {
            // BUG-OBJ-9 / BUG-OBJ-14: contact-match cache invalidation is a
            // non-essential post-save side-effect and must NEVER fail the save.
            // Catch \Throwable (the invalidation path can raise a runtime Error,
            // e.g. an unavailable SystemTag subsystem, not just a container
            // exception) but log it with object context so the miss stays
            // visible instead of being silently swallowed.
            $this->logger->warning(
                message: '[ObjectService] Skipped contact-match cache invalidation: invalidation failed',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e->getMessage(),
                    'uuid'      => $savedObject->getUuid(),
                    'register'  => $this->currentRegister?->getId(),
                    'schema'    => $this->currentSchema?->getId(),
                ]
            );
        }//end try

        // Lazy folder creation: intentionally do NOT create a file-storage
        // folder here. An object only needs a folder once a file is attached;
        // the folder is created on demand on the first upload
        // (CreateFileHandler → getObjectFolder, which creates-if-missing). This
        // avoids cluttering the Files tree with an empty folder per object and
        // avoids binding system/seed-created objects to a folder a later editor
        // can't access (the folder_access_denied case).
        // Render and return the saved object.
        return $this->renderHandler->renderEntity(
            entity: $savedObject,
            _extend: $extend ?? [],
            registers: null,
            schemas: null,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end saveObject()

    /**
     * Set register and schema context from parameters.
     *
     * @param Register|string|int|null $register Register parameter
     * @param Schema|string|int|null   $schema   Schema parameter
     *
     * @return void
     */
    private function setContextFromParameters(
        Register | string | int | null $register,
        Schema | string | int | null $schema
    ): void {
        // Set the current register context if provided.
        if ($register !== null) {
            $this->setRegister(register: $register);
        }

        // Set the current schema context if provided.
        if ($schema !== null) {
            $this->setSchema(schema: $schema);
        }
    }//end setContextFromParameters()

    /**
     * Extract UUID and normalize object to array format.
     *
     * @param array|ObjectEntity $object Input object
     * @param string|null        $uuid   Provided UUID
     *
     * @return array{0: array, 1: string|null} [normalized object array, extracted UUID]
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function extractUuidAndNormalizeObject(array | ObjectEntity $object, ?string $uuid): array
    {
        // Handle ObjectEntity input - extract UUID and convert to array.
        if ($object instanceof ObjectEntity === true) {
            // If no UUID was passed, use the UUID from the existing object.
            if ($uuid === null) {
                $uuid = $object->getUuid();
            }

            $object = $object->getObject();
        }

        // Check if an ID is provided in the object data.
        if ($uuid === null && is_array($object) === true) {
            $providedId = $object['@self']['id'] ?? $object['id'] ?? null;
            if ($providedId !== null) {
                $providedIdTrimmed = trim($providedId);
                if (empty($providedIdTrimmed) === false) {
                    $uuid = $providedId;
                }
            }
        }

        return [$object, $uuid];
    }//end extractUuidAndNormalizeObject()

    /**
     * Check permissions for save operation (CREATE or UPDATE).
     *
     * @param string|null $uuid  Object UUID (null for CREATE, set for UPDATE)
     * @param bool        $_rbac Whether to apply RBAC checks
     *
     * @return void
     *
     * @throws Exception If permission check fails
     */
    private function checkSavePermissions(?string $uuid, bool $_rbac): void
    {
        if ($this->currentSchema === null) {
            return;
        }

        // No UUID provided, this is a CREATE operation.
        if ($uuid === null) {
            $this->checkPermission(
                schema: $this->currentSchema,
                action: 'create',
                userId: null,
                objectOwner: null,
                _rbac: $_rbac
            );
            return;
        }

        // UUID provided - check if object exists to determine CREATE vs UPDATE.
        try {
            $existingObject = $this->objectMapper->find($uuid);
            // This is an UPDATE operation.
            $this->checkPermission(
                schema: $this->currentSchema,
                action: 'update',
                userId: null,
                objectOwner: $existingObject->getOwner(),
                _rbac: $_rbac,
                object: $existingObject
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Object not found, this is a CREATE operation with specific UUID.
            $this->checkPermission(
                schema: $this->currentSchema,
                action: 'create',
                userId: null,
                objectOwner: null,
                _rbac: $_rbac
            );
        }//end try
    }//end checkSavePermissions()

    /**
     * Handle cascading relations while preserving context.
     *
     * @param array       $object Object data
     * @param string|null $uuid   Object UUID
     *
     * @return ((array|mixed|string)[]|null|string)[] [processed object, updated UUID]
     *
     * @psalm-return list{array<array|mixed|string>, null|string}
     */
    private function handleCascadingWithContextPreservation(array $object, ?string $uuid): array
    {
        // Store the parent object's register and schema context before cascading.
        // This prevents nested object creation from corrupting the main object's context.
        $parentRegister = $this->currentRegister;
        $parentSchema   = $this->currentSchema;

        // Pre-validation cascading: Handle inversedBy properties BEFORE validation.
        // This creates related objects and replaces them with UUIDs so validation sees UUIDs, not objects.
        // Note: If currentRegister is NULL (e.g., for seedData objects), pass NULL as registerId.
        $currentRegisterId = null;
        if ($this->currentRegister !== null) {
            $currentRegisterId = $this->currentRegister->getId();
        }

        $cascadeResult = $this->cascadingHandler->handlePreValidationCascading(
            object: $object,
            schema: $parentSchema,
            uuid: $uuid,
            currentRegister: $currentRegisterId
        );
        // The handler returns an [array $object, ?string $uuid] tuple; offset 0
        // always exists, offset 1 may be null — keep the current uuid then.
        $object = $cascadeResult[0];
        $uuid   = ($cascadeResult[1] ?? $uuid);

        // Restore the parent object's register and schema context after cascading.
        $this->currentRegister = $parentRegister;
        $this->currentSchema   = $parentSchema;

        return [$object, $uuid];
    }//end handleCascadingWithContextPreservation()

    /**
     * Validate object if hard validation is enabled.
     *
     * @param array $object Object data to validate
     *
     * @return void
     *
     * @throws ValidationException If validation fails
     */
    private function validateObjectIfRequired(array $object): void
    {
        // BUG-OBJ-4: guard against a null schema reaching the
        // ->getHardValidation() dereference (raw TypeError 500). Callers
        // should already have thrown the structured ValidationException in
        // saveObject(); this is a belt-and-suspenders 400 for any other path.
        if ($this->currentSchema === null) {
            throw new ValidationException(
                message: 'Schema could not be resolved for this object; provide a valid register/schema.'
            );
        }

        // Validate the object against the current schema only if hard validation is enabled.
        if ($this->currentSchema->getHardValidation() === true) {
            $result = $this->validateHandler->validateObject(
                object: $object,
                schema: $this->currentSchema
            );

            if ($result->isValid() === false) {
                $meaningfulMessage = $this->validateHandler->generateErrorMessage(result: $result);
                throw new ValidationException(message: $meaningfulMessage, errors: $result->error());
            }
        }
    }//end validateObjectIfRequired()

    /**
     * Enforce JSON-Schema `readOnly: true` on the UPDATE write path.
     *
     * No-op on CREATE (uuid === null). On UPDATE:
     *  1. Strip `@self` from the incoming payload (it is not a user-controllable
     *     business field — readOnly applies to schema properties, not metadata).
     *  2. Load the previously-stored business data via the magic mapper.
     *     `_rbac: false` is intentional here — readOnly enforcement is a
     *     schema-level invariant, not a permission check, and the actual write
     *     downstream still goes through the full RBAC pipeline.
     *  3. Delegate to {@see ValidateObject::validateReadOnlyConstraints()} for
     *     the per-property comparison.
     *  4. Throw a `ValidationException` carrying the violation list when any
     *     readOnly field was mutated.
     *
     * Wave-12 Fix 1. See `/tmp/wave11-or-engine-primitives.md` Section A.
     *
     * @param array       $object Incoming object payload (top-level keys = property names).
     * @param string|null $uuid   Object UUID — null indicates CREATE (no enforcement).
     *
     * @return void
     *
     * @throws ValidationException When one or more readOnly properties were mutated.
     */
    private function enforceReadOnlyOnUpdate(array $object, ?string $uuid): void
    {
        if ($uuid === null || $this->currentSchema === null) {
            return;
        }

        // Load the existing record. Anything that prevents load (not found,
        // RBAC reject, multitenancy filter) means we're not in a true UPDATE
        // and the engine's normal CREATE path will run — no readOnly check
        // applies.
        //
        // Pass the already-resolved register/schema so find() takes the scoped
        // register/schema-table path directly. Omitting them leaves find() to
        // rely on the request's URL scope; under a stale scope it falls back to
        // the deliberate cross-table search (see the resolution-cache note above,
        // openregister#1520). We are on the save path with both already resolved,
        // so there is no reason to risk that fallback here.
        try {
            $existing = $this->objectMapper->find(
                $uuid,
                register: $this->currentRegister,
                schema: $this->currentSchema,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            return;
        }

        $existingData = $existing->getObject();
        // Drop the synthesised `id` key getObject() prepends — readOnly applies
        // to schema properties, not the engine-stamped identifier.
        if (isset($existingData['id']) === true && isset($object['id']) === false) {
            unset($existingData['id']);
        }

        // Strip @self from the incoming payload before comparing — readOnly
        // is for business properties only.
        $candidate = $object;
        unset($candidate['@self']);

        $violations = $this->validateHandler->validateReadOnlyConstraints(
            incomingObject: $candidate,
            existingObject: $existingData,
            schema: $this->currentSchema
        );

        if ($violations === []) {
            return;
        }

        $properties = array_map(static fn (array $v): string => $v['property'], $violations);
        $suffix     = 'ies';
        if (count($violations) === 1) {
            $suffix = 'y';
        }

        $message = 'Cannot modify readOnly propert'.$suffix.': '.implode(', ', $properties);

        $this->logger->info(
            message: '[ObjectService] readOnly enforcement rejected UPDATE',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'uuid'       => $uuid,
                'schemaId'   => $this->currentSchema->getId(),
                'violations' => $violations,
            ]
        );

        // ValidationException's `$errors` argument is typed `?ValidationError`
        // (Opis), not an arbitrary array — pass null and let the message +
        // log entry carry the violation detail.
        throw new ValidationException(message: $message);
    }//end enforceReadOnlyOnUpdate()

    /**
     * Normalize date values in object data before validation.
     *
     * For properties with format "date", this accepts datetime strings
     * (e.g. "2024-01-15T10:30:00+02:00" or "2024-01-15 00:00:00") and
     * casts them to date-only strings (e.g. "2024-01-15").
     *
     * @param array $object The object data to normalize.
     *
     * @return array The normalized object data.
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function normalizeDateValues(array $object): array
    {
        if ($this->currentSchema === null) {
            return $object;
        }

        $properties = $this->currentSchema->getProperties() ?? [];

        foreach ($properties as $propertyName => $propertyDef) {
            if (isset($object[$propertyName]) === false || is_string($object[$propertyName]) === false) {
                continue;
            }

            $format = $propertyDef['format'] ?? null;
            if ($format !== 'date' && $format !== 'date-time') {
                continue;
            }

            // Empty / whitespace-only strings normalise to null instead of silently
            // becoming the current datetime via PHP's "new DateTime('')" footgun.
            if (trim($object[$propertyName]) === '') {
                $object[$propertyName] = null;
                continue;
            }

            if ($format === 'date') {
                // If already a valid date (Y-m-d), skip.
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $object[$propertyName]) === 1) {
                    continue;
                }

                $parsed = $this->dateTimeNormalizer->normalize($object[$propertyName]);
                if ($parsed !== null) {
                    $object[$propertyName] = $parsed->format('Y-m-d');
                }

                continue;
            }

            // For date-time: accept valid input; invalid/empty input → null.
            // Leave otherwise-valid strings untouched so downstream validation runs.
            $parsed = $this->dateTimeNormalizer->normalize($object[$propertyName]);
            if ($parsed === null) {
                $object[$propertyName] = null;
            }
        }//end foreach

        return $object;
    }//end normalizeDateValues()

    /**
     * Ensure object folder exists, create if needed.
     *
     * @param string|null $uuid        Object UUID
     * @param IUser|null  $currentUser Explicit acting user forwarded to the
     *                                 defense-in-depth re-validation; falls
     *                                 back to `IUserSession::getUser()`.
     *                                 Non-HTTP callers (cron, import
     *                                 pipelines) MUST pass an explicit user.
     *
     * @return int|null Folder ID if created/exists, null otherwise
     */
    private function ensureObjectFolder(?string $uuid, ?IUser $currentUser=null): ?int
    {
        // Handle folder creation for existing objects or new objects with UUIDs.
        $folderId = null;

        if ($uuid !== null) {
            // For existing objects or objects with specific UUIDs, check if folder needs to be created.
            try {
                $existingObject = $this->objectMapper->find($uuid);
                $folder         = $existingObject->getFolder();

                // The `_folder` column is `varchar(255)` — every populated
                // value is a string. The earlier `is_string($folder) === true`
                // clause matched ANY non-empty string and so triggered an
                // auto-create on every update, overwriting valid folder
                // bindings with freshly-generated auto-folders under the
                // register's storage tree. The intent of the string branch
                // was to handle LEGACY non-numeric string paths that
                // pre-date the integer-id storage convention; restrict the
                // check to that case.
                $needsAutoCreate = (
                    $folder === null
                    || $folder === ''
                    || (is_string($folder) === true && is_numeric($folder) === false)
                );

                if ($needsAutoCreate === false) {
                    // Defense in depth (PR #1431 review concern): the
                    // `setSelfMetadata` access check only fires when the
                    // write payload includes `@self.folder`. Pre-PR
                    // cross-tenant bindings (or any subsequent save that
                    // touches other fields) would otherwise pass through
                    // unchecked. Re-validate the existing binding on every
                    // save so the check applies uniformly. Throws
                    // `FolderAccessDeniedException` → HTTP 403 at the
                    // controller layer when the acting user cannot access
                    // the bound folder. The existing binding is kept, so no
                    // folder id is returned (auto-create is not needed).
                    $this->fileService->assertObjectFolderAccessible(
                        object: $existingObject,
                        currentUser: $currentUser
                    );
                    return null;
                }

                // Lazy folder creation: do NOT create a Files folder for an
                // object that has none. Most objects never get a file attached,
                // so eagerly creating a per-object folder on every save (a)
                // clutters the Files tree with thousands of empty folders and
                // (b) can bind the object to a folder created in a
                // no-session/system context (e.g. config-import seeding) that a
                // later editor cannot access — which then denies the edit with
                // folder_access_denied. The folder is created on demand the
                // first time a file is actually uploaded to the object
                // (CreateFileHandler → getObjectFolder, which creates-if-missing).
                // Leave $folderId null; the object functions fine without one.
                $folderId = null;
            } catch (\OCA\OpenRegister\Exception\FolderAccessDeniedException $e) {
                // Propagate folder-access denials up to the controller.
                throw $e;
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Object not found, will create new one with the specified UUID.
                // Let SaveObject handle the creation with the provided UUID.
            } catch (Exception $e) {
                // Other errors - let SaveObject handle the creation.
            }//end try
        }//end if

        return $folderId;
    }//end ensureObjectFolder()

    /**
     * Delete an object, optionally scoped to a (register, schema) magic table.
     *
     * When BOTH `$register` and `$schema` are supplied, the deletion is scoped to
     * exactly one magic table (`oc_openregister_table_{registerId}_{schemaId}`):
     * the lookup uses `MagicMapper::find($identifier, $register, $schema, includeDeleted: true)`
     * which targets a single table and throws `DoesNotExistException` if the
     * UUID is not present in that scope. A UUID that lives in a DIFFERENT
     * `(register, schema)` magic table MUST NOT be touched. See #1638.
     *
     * When EITHER `$register` or `$schema` is null, the legacy unscoped
     * cross-table lookup (`findAcrossAllSources`) is used — preserves backward
     * compatibility for the dozens of callers passing only `$uuid`. The
     * unscoped form is soft-deprecated: prefer the scoped signature for new
     * call sites so the storage layer can refuse cross-scope deletes by
     * construction.
     *
     * @param string                   $uuid            The UUID of the object to delete.
     * @param Register|string|int|null $register        Optional register scope (object, ID, UUID, or slug).
     *                                                  When non-null AND `$schema` is non-null, the lookup
     *                                                  targets exactly that magic table.
     * @param Schema|string|int|null   $schema          Optional schema scope (object, ID, UUID, or slug).
     *                                                  See `$register` — both must be supplied for the
     *                                                  scoped path.
     * @param bool                     $_rbac           Whether to apply RBAC checks (default: true).
     * @param bool                     $_multitenancy   Whether to apply multitenancy filtering (default: true).
     * @param bool                     $_retentionSweep Internal flag set by ArchivalRetentionTask
     *                                                  to bypass the archival-immutability gate.
     *                                                  Reachable only via PHP DI; no HTTP surface
     *                                                  exposes it. Defaults to false.
     *
     * @return bool Whether the deletion was successful
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If `$register` and `$schema` are both supplied
     *                                                    and the UUID is not present in that scope (even
     *                                                    if it exists in another magic table).
     * @throws \Exception If user does not have delete permission.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    public function deleteObject(
        string $uuid,
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $_retentionSweep=false
    ): bool {
        // Resolve the explicit scope (if any) onto the service's currentRegister
        // / currentSchema so downstream context (permission checks, audit-trail
        // recording) sees the API-supplied scope, not a stale leftover from a
        // previous call on this service instance.
        $hasScope = ($register !== null && $schema !== null);
        if ($register !== null) {
            $this->setRegister(register: $register);
        }

        if ($schema !== null) {
            $this->setSchema(schema: $schema);
        }

        // Reject deletion of transferred objects (archiefstatus = overgebracht).
        $this->rejectIfTransferred(uuid: $uuid);

        // Reject DELETE operations on append-only schemas.
        if ($this->currentSchema !== null && $this->currentSchema->isAppendOnly() === true) {
            $schemaSlug = $this->currentSchema->getSlug() ?? (string) $this->currentSchema->getId();
            throw new AppendOnlyException(
                schemaIdentifier: $schemaSlug,
                operation: 'delete'
            );
        }

        // Reject DELETE operations on archival-annotated schemas unless this
        // call originates from the retention sweep cron (which alone sets
        // $_retentionSweep true). User-driven deletes get a structured 403.
        if ($_retentionSweep === false
            && $this->currentSchema !== null
            && $this->schemaHasArchivalAnnotation(schema: $this->currentSchema) === true
        ) {
            $schemaSlug = $this->currentSchema->getSlug() ?? (string) $this->currentSchema->getId();
            throw new ArchivalImmutableException(
                schemaIdentifier: $schemaSlug,
                operation: 'delete'
            );
        }

        // Find the object to get its owner for permission check (include soft-deleted objects).
        // When the caller supplied both register + schema, the lookup is scoped
        // to a single magic table — a UUID in a different scope raises
        // DoesNotExistException and never reaches the delete handler.
        $scopedRegister = null;
        $scopedSchema   = null;
        if ($hasScope === true) {
            $scopedRegister = $this->currentRegister;
            $scopedSchema   = $this->currentSchema;
        }

        try {
            $objectToDelete = $this->objectMapper->find(
                identifier: $uuid,
                register: $scopedRegister,
                schema: $scopedSchema,
                includeDeleted: true
            );

            // If no schema was provided but we have an object, derive the schema from the object.
            if ($this->currentSchema === null) {
                $this->setSchema(schema: $objectToDelete->getSchema());
            }

            // Check user has permission to delete this specific object.
            $this->checkPermission(
                schema: $this->currentSchema,
                action: 'delete',
                userId: null,
                objectOwner: $objectToDelete->getOwner(),
                _rbac: $_rbac,
                object: $objectToDelete
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Scoped lookup is authoritative: if the caller asked for a
            // specific (register, schema) and the UUID is not in that scope,
            // re-throw so the failure mode is "404 not in scope" instead of
            // "silently look at another magic table" (the #1638 bug).
            if ($hasScope === true) {
                throw $e;
            }

            // Unscoped path: object doesn't exist anywhere, no permission check
            // needed but let deleteHandler raise its own consistent error path.
            if ($this->currentSchema !== null) {
                $this->checkPermission(
                    schema: $this->currentSchema,
                    action: 'delete',
                    userId: null,
                    objectOwner: null,
                    _rbac: $_rbac
                );
            }
        }//end try

        return $this->deleteHandler->deleteObject(
            register: $this->currentRegister,
            schema: $this->currentSchema,
            uuid: $uuid,
            originalObjectId: null,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            scoped: $hasScope
        );
    }//end deleteObject()

    /**
     * Check whether a schema declares an `x-openregister-archival` annotation.
     *
     * Used by the deleteObject() immutability gate to short-circuit
     * user-driven deletes before any DB work. Reads from the schema's
     * `configuration` array; absence of the key (or a non-array value)
     * means archival enforcement does NOT apply.
     *
     * @param Schema $schema Schema to inspect.
     *
     * @return bool True when the schema carries a valid archival annotation.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function schemaHasArchivalAnnotation(Schema $schema): bool
    {
        $configuration = ($schema->getConfiguration() ?? []);
        return is_array($configuration['x-openregister-archival'] ?? null);
    }//end schemaHasArchivalAnnotation()

    /**
     * Reject an operation if the object has been transferred to e-Depot.
     *
     * Objects with archiefstatus 'overgebracht' are read-only. The authoritative
     * copy resides in the e-Depot and this system copy MUST NOT be modified.
     *
     * @param string $uuid The object UUID to check.
     *
     * @return void
     *
     * @throws \OCP\AppFramework\Http\ContentSecurityPolicy
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function rejectIfTransferred(string $uuid): void
    {
        try {
            $object = $this->objectMapper->find(
                identifier: $uuid,
                register: null,
                schema: null,
                includeDeleted: true
            );

            $retention = ($object->getRetention() ?? []);
            if (isset($retention['archiefstatus']) === true && $retention['archiefstatus'] === 'overgebracht') {
                throw new OcpDoesNotExistException(
                    'OBJECT_TRANSFERRED: This object has been transferred to the e-Depot and is read-only.'
                );
            }
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Re-throw if it's our transfer exception.
            if (str_starts_with($e->getMessage(), 'OBJECT_TRANSFERRED:') === true) {
                throw $e;
            }

            // Object doesn't exist yet (new object), no check needed.
        }//end try
    }//end rejectIfTransferred()

        /**
         * Get the active organization for the current user
         *
         * This method determines the active organization using the same logic as SaveObject
         * to ensure consistency between save and retrieval operations.
         *
         * @return string|null The active organization UUID or null if none found
         */
    private function getActiveOrganisationForContext(): ?string
    {
        try {
            $activeOrganisation = $this->organisationService->getActiveOrganisation();

            if ($activeOrganisation !== null) {
                return $activeOrganisation->getUuid();
            }

            return null;
        } catch (Exception $e) {
            // Log error but continue without organization context.
            return null;
        }

        return null;
    }//end getActiveOrganisationForContext()

    /**
     * Build a search query from request parameters for faceting-enabled methods
     *
     * This method builds a query structure compatible with the searchObjectsPaginated method
     * which supports faceting, facetable field discovery, and all other search features.
     *
     * @param array           $requestParams Request parameters from the controller
     * @param int|string|null $register      Optional register identifier (should be resolved numeric ID)
     * @param int|string|null $schema        Optional schema identifier (should be resolved numeric ID)
     * @param array|null      $ids           Optional array of specific IDs to filter
     *
     * @psalm-param   array<string, mixed> $requestParams
     * @phpstan-param array<string, mixed> $requestParams
     *
     * @return array<string, mixed> Query array containing:
     *                               - @self: Metadata filters (register, schema, etc.)
     *                               - Direct keys: Object field filters
     *                               - _limit: Maximum number of items per page
     *                               - _offset: Number of items to skip
     *                               - _page: Current page number
     *                               - _order: Sort parameters
     *                               - _search: Search term
     *                               - _extend: Properties to extend
     *                               - _fields: Fields to include
     *                               - _filter/_unset: Fields to exclude
     *                               - _facets: Facet configuration
     *                               - _facetable: Include facetable field discovery
     *                               - _ids: Specific IDs to filter
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     *
     * @spec exclude One-line delegation to SearchQueryHandler::buildSearchQuery(); query-building owned by zoeken-filteren.
     */
    public function buildSearchQuery(
        array $requestParams,
        int | string | array | null $register=null,
        int | string | array | null $schema=null,
        ?array $ids=null
    ): array {
        return $this->searchQueryHandler->buildSearchQuery(
            requestParams: $requestParams,
            register: $register,
            schema: $schema,
            ids: $ids
        );
    }//end buildSearchQuery()

    /**
     * Apply view filters to a query
     *
     * Converts view definitions into query parameters by merging view->query into the base query.
     * Supports multiple views - their filters are combined (OR logic for same field, AND for different fields).
     *
     * @param array $query   Base query parameters
     * @param array $viewIds View IDs to apply
     *
     * @return array Query with view filters applied
     *
     * @psalm-return array<string, mixed>
     */
    private function applyViewsToQuery(array $query, array $viewIds): array
    {
        return $this->searchQueryHandler->applyViewsToQuery(query: $query, viewIds: $viewIds);
    }//end applyViewsToQuery()

    /**
     * Search objects using clean query structure
     *
     * This method provides a cleaner search interface that uses the searchObjects
     * method from MagicMapper with proper query structure. It automatically
     * handles metadata filters, object field searches, and search options.
     *
     * **Numeric-ID contract (runtime-schema-api):** `@self.register` and
     * `@self.schema` MUST be numeric IDs (int). Passing a slug string here
     * is the documented foot-gun the OpenBuild smoke test surfaced — it
     * silently returns zero results instead of resolving the slug.
     * Slug-aware callers MUST use {@see self::searchObjectsBySlug()},
     * which resolves the slugs via the mappers and then delegates here on
     * the fast path. Keeping this method strict means the next misuse
     * fails loudly at the call site rather than silently returning empty.
     *
     * @param array       $query         The search query array containing filters and options
     *                                   - @self: Metadata filters (register, schema, uuid,
     *                                   etc.) - Direct keys: Object field filters for JSON
     *                                   data - _limit: Maximum results to return - _offset:
     *                                   Results to skip (pagination) - _order: Sorting
     *                                   criteria - _search: Full-text search term -
     *                                   _includeDeleted: Include soft-deleted objects -
     *                                   _ids: Array of
     *                                   IDs/UUIDs to filter by - _count: Return count instead
     *                                   of objects (boolean)
     * @param bool        $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool        $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param array|null  $ids           Optional array of IDs to filter by
     * @param string|null $uses          Optional filter by object usage
     * @param array|null  $views         Optional view IDs to apply
     *
     * @psalm-param array<string, mixed> $query
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]|int
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return int<0, max>|list<\OCA\OpenRegister\Db\ObjectEntity>
     *
     * @spec exclude One-line delegation to QueryHandler::searchObjects(); search behavior owned by zoeken-filteren.
     */
    public function searchObjects(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null,
        ?array $views=null
    ): array|int {
        // ARCHITECTURAL DELEGATION: Delegate to QueryHandler for all search operations.
        return $this->queryHandler->searchObjects(
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            ids: $ids,
            uses: $uses,
            views: $views
        );
    }//end searchObjects()

    /**
     * Search objects by register and schema slugs
     *
     * Slug-aware bridge to {@see self::searchObjects()}. Resolves both slugs
     * to numeric IDs via the mappers (scoped to the active organisation by
     * the mappers' standard multi-tenancy filter) and delegates to the
     * numeric-ID search path.
     *
     * This helper exists to close the OpenBuild smoke-test foot-gun where
     * callers passed slugs in `@self.register` / `@self.schema` and got
     * zero results back. The strict numeric-ID contract on `searchObjects`
     * means any future misuse fails loudly at the call site; slug-aware
     * callers (the controller layer, OpenCatalogi, softwarecatalog) get a
     * one-method-call upgrade path.
     *
     * Multi-tenancy honours the same `_multitenancy` flag every other
     * lookup uses — when true (default), slug resolution and the
     * downstream object search are both scoped to the caller's
     * organisation. A slug that exists in another organisation but not
     * the caller's MUST throw `DoesNotExistException` (the mappers do
     * this via the standard organisation filter).
     *
     * @param string $registerSlug  The register slug (must exist in caller's org).
     * @param string $schemaSlug    The schema slug (must exist in caller's org).
     * @param array  $filters       Additional filters merged into the @self block.
     *                              Direct keys like 'status' are merged at the top
     *                              level.
     * @param bool   $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool   $_multitenancy Whether to apply multi-tenancy filter (default: true).
     *
     * @psalm-param array<string, mixed> $filters
     *
     * @phpstan-param array<string, mixed> $filters
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]|int Same shape as searchObjects.
     *
     * @throws OcpDoesNotExistException If either slug fails to resolve in the caller's
     *                                  organisation. The exception message identifies
     *                                  which slug (register vs schema) failed.
     * @throws \OCP\DB\Exception        If a database error occurs.
     *
     * @psalm-return int<0, max>|list<\OCA\OpenRegister\Db\ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags mirror searchObjects() upstream.
     *
     * @spec exclude Slug-resolution bridge delegating to searchObjects(); search behavior owned by zoeken-filteren.
     */
    public function searchObjectsBySlug(
        string $registerSlug,
        string $schemaSlug,
        array $filters=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array|int {
        // Resolve register slug → numeric ID via the existing mapper find()
        // which already accepts slug strings and applies the standard
        // organisation filter. Throws DoesNotExistException if the slug is
        // missing or belongs to a foreign organisation — same contract as
        // every other lookup in OR.
        try {
            $register = $this->registerMapper->find(
                id: $registerSlug,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );
        } catch (OcpDoesNotExistException $e) {
            throw new OcpDoesNotExistException(
                'searchObjectsBySlug: register slug not found in caller organisation: '.$registerSlug
            );
        }

        // Resolve schema slug → numeric ID, scoped to the same multi-tenancy
        // boundary. A schema that exists in another organisation MUST throw,
        // not return the foreign-org entity (principle of least surprise).
        try {
            $schema = $this->schemaMapper->find(
                id: $schemaSlug,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );
        } catch (OcpDoesNotExistException $e) {
            throw new OcpDoesNotExistException(
                'searchObjectsBySlug: schema slug not found in caller organisation: '.$schemaSlug
            );
        }

        // Merge resolved numeric IDs into the @self block of the filters.
        // Direct keys (status, etc.) stay at the top level so they hit the
        // object JSON filter path, not the metadata filter path.
        $selfBlock = $filters['@self'] ?? [];
        $selfBlock['register'] = $register->getId();
        $selfBlock['schema']   = $schema->getId();
        $filters['@self']      = $selfBlock;

        // Delegate to the numeric-ID searchObjects on the documented fast path.
        return $this->searchObjects(
            query: $filters,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end searchObjectsBySlug()

    /**
     * Count objects using clean query structure
     *
     * This method provides an optimized count interface that mirrors the searchObjects
     * functionality but returns only the count of matching objects. It uses the new
     * countSearchObjects method which is optimized for counting operations.
     *
     * @param array<string, mixed> $query         The search query array containing filters and options
     *                                            - @self: Metadata filters (register, schema, uuid,
     *                                            etc.) - Direct keys: Object field filters for JSON
     *                                            data - _includeDeleted: Include soft-deleted objects
     *                                            - _search: Full-text search term
     * @param bool                 $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool                 $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param array|null           $ids           Optional array of object IDs to filter by
     * @param string|null          $uses          Optional uses parameter for filtering
     *
     * @psalm-param   array<string, mixed> $query
     * @phpstan-param array<string, mixed> $query
     *
     * @return int The number of objects matching the criteria
     *
     * @psalm-return   int
     * @phpstan-return int
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @spec exclude Resolves org context then delegates to ObjectMapper::countSearchObjects(); count behavior owned by zoeken-filteren.
     */
    public function countSearchObjects(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): int {
        // Get active organization context for multi-tenancy (only if multi is enabled).
        $activeOrgUuid = null;
        if ($_multitenancy === true) {
            $activeOrgUuid = $this->getActiveOrganisationForContext();
        }

        // Use the optimized countSearchObjects method from MagicMapper with organization context.
        return $this->objectMapper->countSearchObjects(
            query: $query,
            _activeOrgUuid: $activeOrgUuid,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            ids: $ids,
            uses: $uses
        );
    }//end countSearchObjects()

    /**
     * Get facets for objects matching the given criteria
     *
     * This method provides comprehensive faceting capabilities for object data,
     * supporting both metadata facets (like register, schema, dates) and object
     * field facets (like status, category, priority). It uses the new facet
     * handlers for optimal performance and consistency.
     *
     * @param array $query The search query array containing filters and options
     *                     - @self: Metadata filters (register, schema, uuid, etc.)
     *                     - Direct keys: Object field filters for JSON data
     *                     - _search: Full-text search term
     *                     - _facets: Facet configuration (required)
     *
     * @psalm-param array<string, mixed> $query
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @return array The facets for objects matching the criteria
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return array<string, mixed>
     *
     * @spec exclude One-line delegation to FacetHandler::getFacetsForObjects(); faceting owned by faceting-configuration.
     */
    public function getFacetsForObjects(array $query=[]): array
    {
        // **ARCHITECTURAL IMPROVEMENT**: Delegate to FacetHandler.
        // This provides clean separation of concerns and centralized faceting logic.
        return $this->facetHandler->getFacetsForObjects($query);
    }//end getFacetsForObjects()

    /**
     * Get facetable fields for discovery (ULTRA-OPTIMIZED)
     *
     * CRITICAL PERFORMANCE OPTIMIZATION**: This method now uses pre-computed facet
     * configurations stored directly in schema entities instead of runtime analysis.
     * This eliminates the ~15ms overhead for _facetable=true requests.
     *
     * Benefits:
     * - ~15ms eliminated per request (from ~15ms to <1ms)
     * - Consistent facet configurations across requests
     * - No runtime schema analysis overhead
     * - Cached and reusable facet definitions
     *
     * @param array $baseQuery  Base query filters to apply for context
     * @param int   $sampleSize Unused parameter, kept for backward compatibility
     *
     * @psalm-param array<string, mixed> $baseQuery
     * @psalm-param int $sampleSize
     *
     * @phpstan-param array<string, mixed> $baseQuery
     * @phpstan-param int $sampleSize
     *
     * @return array[] Comprehensive facetable field information from schemas
     *
     * @throws \Exception If facetable field discovery fails
     *
     * @psalm-return array{'@self': array, object_fields: array}
     *
     * @spec exclude One-line delegation to FacetHandler::getFacetableFields(); facetable-field discovery owned by faceting-configuration.
     */
    public function getFacetableFields(array $baseQuery=[], int $sampleSize=100): array
    {
        // **ARCHITECTURAL IMPROVEMENT**: Delegate to FacetHandler.
        return $this->facetHandler->getFacetableFields(baseQuery: $baseQuery, _sampleSize: $sampleSize);
    }//end getFacetableFields()

    /**
     * Search objects with pagination and comprehensive faceting support
     *
     * **SEARCH ENGINE**: This method uses Solr as the primary search engine when available,
     * falling back to database search only when Solr is disabled or when using relation-based
     * searches (ids/uses parameters). If Solr fails, the method will throw an exception
     * rather than falling back to database search.
     *
     * **PERFORMANCE OPTIMIZATION**: This method intelligently determines which operations
     * are needed based on the query parameters and only executes the required operations.
     * For simple requests without faceting, it skips facet calculations entirely.
     *
     * This method provides a complete search interface with pagination, faceting,
     * and optional facetable field discovery. It supports all the features of the
     * searchObjects method while adding pagination and URL generation for navigation.
     *
     * **Performance Note**: For requests with facets + facetable discovery,
     * consider using `searchObjectsPaginatedAsync()` which runs operations concurrently.
     * For simple requests, this optimized version provides sub-500ms performance.
     *
     * ### Supported Query Parameters
     *
     * **Pagination:**
     * - `_limit`: Maximum results per page (default: 20)
     * - `_offset`: Number of results to skip
     * - `_page`: Page number (alternative to offset)
     *
     * **Search and Filtering:**
     * - `@self`: Metadata filters (register, schema, uuid, etc.)
     * - Direct keys: Object field filters for JSON data
     * - `_search`: Full-text search term
     * - `_includeDeleted`: Include soft-deleted objects
     * - `_ids`: Array of IDs/UUIDs to filter by
     *
     * **Faceting:**
     * - `_facets`: Facet configuration for aggregations (~10ms performance impact)
     * - `_facetable`: Include facetable field discovery (~15ms performance impact)
     *
     * **Rendering:**
     * - `_extend`: Properties to extend
     * - `_fields`: Fields to include
     * - `_filter/_unset`: Fields to exclude
     *
     * ### Facet Types
     *
     * - **terms**: Categorical data with enumerated values and counts
     * - **date_histogram**: Time-based data with configurable intervals (day, week, month, year)
     * - **range**: Numeric data with custom range buckets
     *
     * ### Disjunctive Faceting
     *
     * Facets use disjunctive logic, meaning each facet shows counts as if its own
     * filter were not applied. This prevents facet options from disappearing when
     * selected, providing a better user experience.
     *
     * ### Performance Impact
     *
     * - Simple queries (no facets): Target <500ms response time
     * - With `_facets`: Adds ~10ms to response time
     * - With `_facetable=true`: Adds ~15ms to response time
     * - Combined: Adds ~25ms total
     *
     * Use faceting and discovery strategically for optimal performance.
     *
     * @param array       $query         The search query array containing filters and options
     *                                   - @self: Metadata filters (register, schema, uuid,
     *                                   etc.) - Direct keys: Object field filters for JSON
     *                                   data - _limit: Maximum results to return - _offset:
     *                                   Results to skip (pagination) - _page: Page number
     *                                   (alternative to offset) - _order: Sorting criteria -
     *                                   _search: Full-text search term - _includeDeleted:
     *                                   Include soft-deleted objects - _ids: Array of IDs/UUIDs to
     *                                   filter by - _facets: Facet configuration for
     *                                   aggregations - _facetable: Include facetable field
     *                                   discovery (true/false) - _extend: Properties to
     *                                   extend - _fields: Fields to include - _filter/_unset:
     *                                   Fields to exclude - _queries: Specific fields for
     *                                   legacy facets
     * @param bool        $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool        $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param bool        $deleted       Whether to include deleted objects (default: false)
     * @param array|null  $ids           Optional array of object IDs to filter by
     * @param string|null $uses          Optional uses parameter for filtering
     * @param array|null  $views         Optional array of view IDs to apply filters from
     *
     * @psalm-param   array<string, mixed> $query
     * @phpstan-param array<string, mixed> $query
     *
     * @return array<string, mixed> Array containing:
     *                              - results: Array of rendered ObjectEntity objects
     *                              - total: Total number of matching objects
     *                              - page: Current page number
     *                              - pages: Total number of pages
     *                              - limit: Items per page
     *                              - offset: Current offset
     *                              - facets: Comprehensive facet data with counts and metadata (if _facets provided)
     *                              - facetable: Facetable field discovery (if _facetable=true)
     *                              - next: URL for next page (if available)
     *                              - prev: URL for previous page (if available)
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \Exception If Solr search fails and cannot be recovered
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex search routing requires multiple branches
     * @SuppressWarnings(PHPMD.NPathComplexity)      Many search options create many execution paths
     *
     * @spec exclude Facade routing the unified paginated/faceted search to the query + facet handlers;
     *   behavior owned by zoeken-filteren / faceting-configuration.
     */
    public function searchObjectsPaginated(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $deleted=false,
        ?array $ids=null,
        ?string $uses=null,
        ?array $views=null
    ): array {
        // Object-source delegation: a schema served from an external source
        // (x-openregister-object-source) lists live from its provider, never the
        // search index or magic table. Returns the standard paginated shape.
        $sourcePaginated = $this->paginateObjectSource(query: $query, _rbac: $_rbac);
        if ($sourcePaginated !== null) {
            return $sourcePaginated;
        }

        // Capture the start time so the search trail can record the real
        // response time regardless of which backend (index/database) runs.
        $searchStartTime = microtime(true);

        // Detect a cross-schema search: a `@self.schema` array, `_schemas`, or
        // `@self.schemas` means the caller wants to search MANY schemas (e.g. the
        // unified-search provider passes every searchable schema). Such a search
        // must NOT be scoped to a single register — each schema is resolved to
        // its own owning register downstream — so the ambient currentRegister
        // (often a default like the first register) must not leak in.
        $selfSchema       = ($query['@self']['schema'] ?? null);
        $isMultiSchemaCtx = (is_array($selfSchema) === true && count($selfSchema) > 0)
            || array_key_exists('_schemas', $query) === true
            || (isset($query['@self']['schemas']) === true);

        // Add register and schema context to query for magic mapper routing.
        // Use array_key_exists to allow explicit null values to disable auto-setting.
        if ($this->currentRegister !== null
            && array_key_exists('_register', $query) === false
            && $isMultiSchemaCtx === false
        ) {
            $query['_register'] = $this->currentRegister->getId();
        }

        // Don't auto-set _schema when _schemas is provided (multi-schema search).
        // Use array_key_exists to allow explicit null values to disable auto-setting.
        if ($this->currentSchema !== null
            && array_key_exists('_schema', $query) === false
            && array_key_exists('_schemas', $query) === false
        ) {
            $query['_schema'] = $this->currentSchema->getId();
        }

        // Apply view filters if provided.
        if ($views !== null && empty($views) === false) {
            $query = $this->applyViewsToQuery(query: $query, viewIds: $views);
        }

        // Strip deprecated _source parameter (silently ignore for backward compatibility).
        unset($query['_source']);

        // Bypass multitenancy for schemas with public read access.
        // Public schemas should be visible to all users regardless of organisation.
        $effectiveMt = $_multitenancy;
        if ($_multitenancy === true && $this->currentSchema !== null) {
            $schemaAuth = $this->currentSchema->getAuthorization();
            $readGroups = $schemaAuth['read'] ?? [];
            if (in_array('public', $readGroups, true) === true) {
                $effectiveMt = false;
            }
        }

        // Use database search.
        $result = $this->queryHandler->searchObjectsPaginatedDatabase(
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $effectiveMt,
            deleted: $deleted,
            ids: $ids,
            uses: $uses
        );
        // Preserve source from result (e.g., magic_mapper for multi-schema), only default to database if not set.
        $result['@self']['source']  = $result['@self']['source'] ?? 'database';
        $result['@self']['query']   = $query;
        $result['@self']['rbac']    = $_rbac;
        $result['@self']['multi']   = $_multitenancy;
        $result['@self']['deleted'] = $deleted;

        // Add extended objects only if _extend is requested.
        // Normalize _extend to array (handles comma-separated string from URL).
        $extend = $query['_extend'] ?? [];
        if (is_string($extend) === true) {
            $extend = array_filter(array_map('trim', explode(',', $extend)));
        }

        if (empty($extend) === false) {
            $result['@self']['objects'] = $this->getExtendedObjects();
        }

        // Add names mapping if _names is in _extend.
        // This provides UUID-to-name mappings for all related objects in the results,
        // reducing frontend calls to the names service.
        if (is_array($extend) === true && in_array('_names', $extend, true) === true) {
            $resultsToProcess = $result['results'] ?? [];

            // Only process if results exist and is an array.
            $result['@self']['names'] = [];

            if (is_array($resultsToProcess) === true && empty($resultsToProcess) === false) {
                try {
                    $result['@self']['names'] = $this->collectNamesForResults(results: $resultsToProcess);
                } catch (\Throwable $e) {
                    $errFile = $e->getFile();
                    $errLine = $e->getLine();
                    $this->logger->error(
                        message: '[ObjectService] _names extension failed: '.$e->getMessage()." at {$errFile}:{$errLine}",
                        context: ['file' => __FILE__, 'line' => __LINE__]
                    );
                    $result['@self']['names']       = [];
                    $result['@self']['names_error'] = $e->getMessage();
                }
            }
        }//end if

        $this->recordSearchTrail(query: $query, result: $result, startTime: $searchStartTime);

        return $result;
    }//end searchObjectsPaginated()

    /**
     * Build a paginated result from the current schema's object-source provider,
     * or null when the current schema is not served from an external source.
     *
     * Mirrors the `{ results, total, '@self' }` shape that the index/database
     * search backends return, so the controller renders virtual objects exactly
     * like native ones. When the schema declares a source whose provider is
     * missing/disabled, returns an empty paginated result (never the DB).
     *
     * @param array $query The search query (filters/limit/offset).
     * @param bool  $_rbac Whether to enforce RBAC checks.
     *
     * @return array|null The paginated result, or null when not source-backed.
     *
     * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the acting user lacks read access to the schema.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function paginateObjectSource(array $query, bool $_rbac=true): ?array
    {
        $schema = $this->currentSchema;
        if ($schema === null) {
            return null;
        }

        $source = $schema->getObjectSource();
        if ($source === null) {
            return null;
        }

        // Read-access parity (no enumeration oracle): enforce the schema-level
        // read authorization BEFORE the provider — and therefore the external
        // database — is consulted. A denied user receives the same
        // NotAuthorizedException a native schema raises, whether or not any
        // matching external rows exist.
        $this->checkPermission(
            schema: $schema,
            action: 'read',
            userId: null,
            objectOwner: null,
            _rbac: $_rbac
        );

        // The canonical search query keeps object-field filters as TOP-LEVEL
        // keys and paging as `_limit`/`_page`/`_offset`, while the provider
        // contract reads `filters`, `limit` and `offset`. Normalise additively
        // (existing keys are never overwritten) so providers written against
        // either shape keep working.
        $query = $this->normaliseObjectSourceQuery(query: $query);

        $results  = [];
        $config   = ($source['config'] ?? []);
        $provider = $this->objectSourceRegistry->get($source['provider']);
        $active   = ($provider !== null && $provider->isEnabled() === true && $this->currentRegister !== null);
        if ($active === false) {
            $this->logger->warning(
                sprintf(
                    '[ObjectSource] schema "%s" declares provider "%s" but it is missing/disabled or has no register context — returning empty list',
                    (string) $schema->getSlug(),
                    $source['provider']
                )
            );
        } else {
            $results = $provider->findAll(
                register: $this->currentRegister,
                schema: $schema,
                query: $query,
                config: $config
            );
        }

        $resultCount = count($results);
        $limit       = (int) ($query['limit'] ?? 0);
        $offset      = max(0, (int) ($query['offset'] ?? 0));

        // D4b: consult the provider's count() for the TRUE total and compute
        // page metadata, passing limit/offset through (findAll already received
        // them). A provider that cannot report a real total — count() throws or
        // returns a value inconsistent with the returned window — falls back to
        // the pre-existing in-memory behaviour so the native providers are
        // unaffected.
        $trueTotal = null;
        if ($active === true) {
            $trueTotal = $this->objectSourceTrueTotal(
                provider: $provider,
                schema: $schema,
                query: $query,
                config: $config,
                offset: $offset,
                resultCount: $resultCount
            );
        }

        $total = ($trueTotal ?? $resultCount);
        $self  = $this->objectSourcePageMetadata(
            total: $total,
            limit: $limit,
            offset: $offset,
            realCount: ($trueTotal !== null)
        );

        return [
            'results' => $results,
            'total'   => $total,
            '@self'   => $self,
        ];
    }//end paginateObjectSource()

    /**
     * Adapt a canonical search query to the object-source provider contract.
     *
     * The canonical shape (SearchQueryHandler::buildSearchQuery) carries object
     * field filters as top-level keys, paging as `_limit`/`_page`/`_offset`,
     * and sorting as `_order`. Providers read `filters`, `limit`, `offset` and
     * `sort`. Mapping is ADDITIVE: a key the caller already set is never
     * overwritten, so provider behaviour under the old shape is unchanged.
     *
     * @param array<string, mixed> $query The canonical search query.
     *
     * @return array<string, mixed> The query with provider-contract keys added.
     *
     * @SuppressWarnings(PHPMD.NPathComplexity) Additive key-by-key mapping; each guard is independent by design
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     *
     * @SuppressWarnings(PHPMD.NPathComplexity) Additive key-mapping requires one guard per provider key
     */
    private function normaliseObjectSourceQuery(array $query): array
    {
        if (isset($query['limit']) === false && isset($query['_limit']) === true) {
            $query['limit'] = (int) $query['_limit'];
        }

        if (isset($query['offset']) === false) {
            $offset = (int) ($query['_offset'] ?? 0);
            $page   = (int) ($query['_page'] ?? 0);
            $limit  = (int) ($query['limit'] ?? 0);
            if ($offset === 0 && $page > 1 && $limit > 0) {
                $offset = (($page - 1) * $limit);
            }

            if ($offset > 0) {
                $query['offset'] = $offset;
            }
        }

        if (isset($query['sort']) === false && isset($query['_order']) === true) {
            $query['sort'] = $query['_order'];
        }

        if (isset($query['filters']) === false) {
            $filters = [];
            foreach ($query as $key => $value) {
                $key = (string) $key;
                if ($key === '' || $key[0] === '_' || $key[0] === '@') {
                    continue;
                }

                if (in_array($key, ['limit', 'offset', 'sort', 'filters', 'extend', 'fields'], true) === true) {
                    continue;
                }

                if (is_scalar($value) === true) {
                    $filters[$key] = $value;
                }
            }

            if ($filters !== []) {
                $query['filters'] = $filters;
            }
        }//end if

        return $query;
    }//end normaliseObjectSourceQuery()

    /**
     * Consult an object-source provider's count() for the true total (D4b).
     *
     * Returns null — signalling the in-memory fallback — when count() throws or
     * reports a value inconsistent with the returned window (smaller than
     * offset + returned results), so providers without real count support keep
     * their pre-existing single-page behaviour.
     *
     * @param \OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider $provider    The resolved provider.
     * @param Schema                                                      $schema      The sourced schema.
     * @param array                                                       $query       The search query.
     * @param array                                                       $config      The object-source config block.
     * @param int                                                         $offset      The requested offset.
     * @param int                                                         $resultCount The returned window size.
     *
     * @return int|null The true total, or null when the provider has no real count.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function objectSourceTrueTotal(
        $provider,
        Schema $schema,
        array $query,
        array $config,
        int $offset,
        int $resultCount
    ): ?int {
        try {
            $counted = $provider->count(
                register: $this->currentRegister,
                schema: $schema,
                query: $query,
                config: $config
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[ObjectSource] provider "'.$provider->getId().'" count() unavailable — falling back to in-memory total: '.$e->getMessage()
            );
            return null;
        }

        if ($counted >= ($offset + $resultCount)) {
            return $counted;
        }

        return null;
    }//end objectSourceTrueTotal()

    /**
     * Compute the `@self` pagination block for an object-source result (D4b).
     *
     * @param int  $total     The (true or fallback) total.
     * @param int  $limit     The requested limit (0 = none).
     * @param int  $offset    The requested offset.
     * @param bool $realCount Whether the total came from a real provider count.
     *
     * @return array The `@self` pagination metadata.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function objectSourcePageMetadata(int $total, int $limit, int $offset, bool $realCount): array
    {
        $page  = 1;
        $pages = 1;
        $next  = null;
        $prev  = null;

        if ($realCount === true && $limit > 0) {
            $pages = max(1, (int) ceil($total / $limit));
            $page  = ((int) floor($offset / $limit) + 1);
            if ($page < $pages) {
                $next = ($page + 1);
            }

            if ($page > 1) {
                $prev = ($page - 1);
            }
        }

        $effectiveLimit = $total;
        if ($limit > 0) {
            $effectiveLimit = $limit;
        }

        return [
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'limit' => $effectiveLimit,
            'next'  => $next,
            'prev'  => $prev,
        ];
    }//end objectSourcePageMetadata()

    /**
     * Record a search-trail entry for a paginated search, honouring the
     * configured recording mode.
     *
     * Resolves the effective mode via SearchQueryHandler (memoized per
     * request): 'none' records nothing; '_search' records only when a
     * non-empty `_search` term is present; 'all' records every paginated
     * search. Derives the execution type from the result source (index vs
     * database) and the response time from the captured start time. The
     * entry is buffered and persisted after the response (deferred flush in
     * SearchQueryHandler), so recording adds no write latency to the search.
     * Never throws — a recording failure must not affect the search response.
     *
     * @param array $query     The post-view-merge search query.
     * @param array $result    The paginated search result (results, total, @self).
     * @param float $startTime The microtime(true) captured at search entry.
     *
     * @return void
     *
     * @spec openspec/specs/search-trail-recording/spec.md
     */
    private function recordSearchTrail(array $query, array $result, float $startTime): void
    {
        try {
            $mode = $this->searchQueryHandler->getEffectiveRecordingMode();
            if ($mode === 'none') {
                return;
            }

            $searchTerm = trim((string) ($query['_search'] ?? ''));
            if ($mode === '_search' && $searchTerm === '') {
                return;
            }

            $responseTime  = (float) ((microtime(true) - $startTime) * 1000);
            $source        = $result['@self']['source'] ?? 'database';
            $executionType = 'database';
            if ($source === 'index') {
                $executionType = 'index';
            }

            $this->searchQueryHandler->logSearchTrail(
                $query,
                count($result['results'] ?? []),
                (int) ($result['total'] ?? 0),
                $responseTime,
                $executionType
            );
        } catch (\Throwable $e) {
            // Recording is best-effort and must never fail the search.
            $this->logger->warning(
                message: '[ObjectService] recordSearchTrail failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try
    }//end recordSearchTrail()

    /**
     * Original database search logic - extracted to avoid code duplication.
     *
     * @param array<string, mixed> $query     The search query array
     * @param bool                 $_rbac      Whether to apply RBAC checks (default: true)
     * @param bool                 $_multitenancy     Whether to apply multitenancy filtering (default: true)
     * @param bool                 $deleted   Whether to include deleted objects (default: false)
     * @param array|null           $ids       Optional array of object IDs to filter by
     * @param string|null          $uses      Optional uses parameter for filtering
     *
     * @return array<string, mixed> Search results with pagination
     */

    // From this point on only deprecated functions for backwards compatibility with OpenConnector.
    // To remove after OpenConnector refactor.

    /**
     * Returns the current schema
     *
     * @deprecated
     *
     * @return int The current schema
     *
     * @spec exclude Deprecated context getter returning the current schema id; no business rule.
     */
    public function getSchema(): int
    {
        if ($this->currentSchema === null) {
            throw new RuntimeException('Schema not set in ObjectService.');
        }

        return $this->currentSchema->getId();
    }//end getSchema()

    /**
     * Returns the current register
     *
     * @deprecated
     *
     * @return int
     *
     * @spec exclude Deprecated context getter returning the current register id; no business rule.
     */
    public function getRegister(): int
    {
        if ($this->currentRegister === null) {
            throw new RuntimeException('Register not set in ObjectService.');
        }

        return $this->currentRegister->getId();
    }//end getRegister()

    /**
     * Returns all registers with their schemas expanded.
     *
     * @return array List of registers, each with a 'schemas' array of schema objects.
     *
     * @spec exclude Read-only lister returning registers with their schemas inlined; no business rule.
     */
    public function getRegisters(): array
    {
        $registers = $this->registerMapper->findAll(_multitenancy: false);
        $result    = [];

        foreach ($registers as $register) {
            $registerArr = $register->jsonSerialize();
            $schemaIds   = $register->getSchemas() ?? [];
            $schemas     = [];

            foreach ($schemaIds as $schemaId) {
                try {
                    $schema    = $this->schemaMapper->find(id: (int) $schemaId, _multitenancy: false);
                    $schemas[] = $schema->jsonSerialize();
                } catch (\Exception $e) {
                    // Skip schemas that cannot be found.
                }
            }

            $registerArr['schemas'] = $schemas;
            $result[] = $registerArr;
        }

        return $result;
    }//end getRegisters()

    /**
     * Renders the rendered object.
     *
     * @param ObjectEntity $entity        The entity to be rendered
     * @param array|null   $_extend       Optional array to extend the entity
     * @param int|null     $depth         Optional depth for rendering
     * @param array|null   $filter        Optional filters to apply
     * @param array|null   $fields        Optional fields to include
     * @param array|null   $unset         Optional fields to exclude
     * @param bool         $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool         $_multitenancy Whether to apply multitenancy filtering (default: true)
     *
     * @return array Rendered entity data
     *
     * @SuppressWarnings (PHPMD.UnusedFormalParameter)
     *
     * @spec exclude Facade delegating object rendering to the render handler;
     *   render contract owned by files-render-extension / schema-driven-read-coercion.
     */
    public function renderEntity(
        ObjectEntity $entity,
        ?array $_extend=[],
        ?int $depth=0,
        ?array $filter=[],
        ?array $fields=[],
        ?array $unset=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        return $this->renderHandler->renderEntity(
            entity: $entity,
            _extend: $_extend,
            depth: $depth,
            filter: $filter,
            fields: $fields,
            unset: $unset,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        )->jsonSerialize();
    }//end renderEntity()

    /**
     * Get the objects cache containing all extended/related objects indexed by UUID.
     *
     * This method returns all objects that were loaded during rendering (via _extend).
     * Objects are indexed by their UUID for easy lookup by the frontend.
     * Should be called after renderEntity() to get the extended objects.
     *
     * @return array<string, array> Objects indexed by UUID
     */
    public function getExtendedObjects(): array
    {
        return $this->renderHandler->getObjectsCache();
    }//end getExtendedObjects()

    /**
     * Get sub-objects created during the last save operation.
     *
     * Returns an array of sub-objects indexed by their UUID, suitable for
     * inclusion in the parent object's @self.objects property.
     *
     * @return array<string, array> Sub-objects indexed by UUID
     */
    public function getCreatedSubObjects(): array
    {
        return $this->saveHandler->getCreatedSubObjects();
    }//end getCreatedSubObjects()

    /**
     * Get the CacheHandler instance for name resolution.
     *
     * Used by controllers to resolve UUID-to-name mappings for _names extension.
     *
     * @return CacheHandler The cache handler instance.
     */
    public function getCacheHandler(): CacheHandler
    {
        return $this->cacheHandler;
    }//end getCacheHandler()

    /**
     * Get the delete handler.
     *
     * @return DeleteObject The delete handler.
     */
    public function getDeleteHandler(): DeleteObject
    {
        return $this->deleteHandler;
    }//end getDeleteHandler()

    /**
     * Collect UUID-to-name mappings for all related objects in search results.
     *
     * This method extracts all UUIDs from the search results (relations, object properties)
     * and resolves them to human-readable names using the CacheHandler.
     *
     * @param array $results Array of rendered objects or ObjectEntity instances from search.
     *
     * @return array<string, string> Map of UUID to name.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function collectNamesForResults(array $results): array
    {
        $uuids = [];

        $count = 0;
        foreach ($results as $result) {
            $count++;

            // For ObjectEntity instances, access relations directly without full serialization.
            // This avoids triggering expensive render operations.
            if ($result instanceof \OCA\OpenRegister\Db\ObjectEntity) {
                $this->collectUuidsFromEntity(entity: $result, uuids: $uuids);
                continue;
            }//end if

            // Handle already-serialized arrays.
            if (is_array($result) === false) {
                continue;
            }

            $this->collectUuidsFromArrayResult(resultData: $result, uuids: $uuids);
        }//end foreach

        // Remove duplicates.
        $uuids = array_unique($uuids);

        if (empty($uuids) === true) {
            return [];
        }

        // Resolve all UUIDs to names using CacheHandler.
        $names = $this->cacheHandler->getMultipleObjectNames($uuids);
        return $names;
    }//end collectNamesForResults()

    /**
     * Collect UUIDs from an ObjectEntity instance.
     *
     * Extracts UUIDs from the entity's relations, metadata fields (organisation, owner),
     * and object data without triggering full serialization.
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity $entity The object entity to extract UUIDs from.
     * @param array                             $uuids  Reference to array collecting UUIDs.
     *
     * @return void
     */
    private function collectUuidsFromEntity(\OCA\OpenRegister\Db\ObjectEntity $entity, array &$uuids): void
    {
        // Get relations directly from entity.
        $relations = $entity->getRelations();
        if (is_array($relations) === true) {
            $this->collectUuidsFromRelations(relations: $relations, uuids: $uuids);
        }

        // Collect from metadata fields (organisation, owner).
        // These are UUID references to related objects that the frontend needs names for.
        $organisation = $entity->getOrganisation();
        if (is_string($organisation) === true && $this->isUuidFormat(value: $organisation) === true) {
            $uuids[] = $organisation;
        }

        $owner = $entity->getOwner();
        if (is_string($owner) === true && $this->isUuidFormat(value: $owner) === true) {
            $uuids[] = $owner;
        }

        // Get object data directly without triggering full serialization.
        $objectData = $entity->getObject();
        if (is_array($objectData) === true) {
            $this->collectUuidsFromObjectData(data: $objectData, uuids: $uuids);
        }
    }//end collectUuidsFromEntity()

    /**
     * Collect UUIDs from an already-serialized array result.
     *
     * Handles the nested @self structure, extracting UUIDs from relations,
     * metadata fields, and object data properties.
     *
     * @param array $resultData The serialized result array.
     * @param array $uuids      Reference to array collecting UUIDs.
     *
     * @return void
     */
    private function collectUuidsFromArrayResult(array $resultData, array &$uuids): void
    {
        // Get the actual object data - handle nested @self structure.
        $objectData = $resultData;
        if (isset($resultData['@self']) === true && is_array($resultData['@self']) === true) {
            // Collect from relations in @self.
            $relations = $resultData['@self']['relations'] ?? [];
            if (is_array($relations) === true) {
                $this->collectUuidsFromRelations(relations: $relations, uuids: $uuids);
            }

            // Collect from metadata fields in @self (organisation, owner).
            // These are UUID references to related objects that the frontend needs names for.
            $metadataFields = ['organisation', 'owner'];
            foreach ($metadataFields as $field) {
                $value = $resultData['@self'][$field] ?? null;
                if (is_string($value) === true && $this->isUuidFormat(value: $value) === true) {
                    $uuids[] = $value;
                }
            }

            // Use the object data from @self if present.
            if (isset($resultData['@self']['object']) === true && is_array($resultData['@self']['object']) === true) {
                $objectData = $resultData['@self']['object'];
            }
        }//end if

        // Collect UUIDs from object properties.
        if (is_array($objectData) === true) {
            $this->collectUuidsFromObjectData(data: $objectData, uuids: $uuids);
        }
    }//end collectUuidsFromArrayResult()

    /**
     * Collect UUIDs from a relations array.
     *
     * Relations can be either direct UUID strings or arrays of UUID strings.
     * This method handles both formats and appends found UUIDs to the collection.
     *
     * @param array $relations The relations array to scan for UUIDs.
     * @param array $uuids     Reference to array collecting UUIDs.
     *
     * @return void
     */
    private function collectUuidsFromRelations(array $relations, array &$uuids): void
    {
        foreach ($relations as $relation) {
            if (is_string($relation) === true && $this->isUuidFormat(value: $relation) === true) {
                $uuids[] = $relation;
            } else if (is_array($relation) === true) {
                foreach ($relation as $uuid) {
                    if (is_string($uuid) === true && $this->isUuidFormat(value: $uuid) === true) {
                        $uuids[] = $uuid;
                    }
                }
            }
        }//end foreach
    }//end collectUuidsFromRelations()

    /**
     * Recursively collect UUIDs from object data.
     *
     * @param array $data  The object data to scan.
     * @param array $uuids Reference to array collecting UUIDs.
     * @param int   $depth Current recursion depth.
     *
     * @return void
     */
    private function collectUuidsFromObjectData(array $data, array &$uuids, int $depth=0): void
    {
        // Only process top-level to avoid recursion issues.
        if ($depth > 0) {
            return;
        }

        foreach ($data as $key => $value) {
            // Skip metadata keys.
            if ($key === '@self' || $key === 'id' || $key === '_id' || $key === 'object') {
                continue;
            }

            // Only look at top-level string UUIDs.
            if (is_string($value) === true && $this->isUuidFormat(value: $value) === true) {
                $uuids[] = $value;
            } else if (is_array($value) === true) {
                // Only look at arrays of UUIDs (not nested objects).
                foreach ($value as $item) {
                    if (is_string($item) === true && $this->isUuidFormat(value: $item) === true) {
                        $uuids[] = $item;
                    }

                    // Skip nested arrays completely.
                }
            }
        }
    }//end collectUuidsFromObjectData()

    /**
     * Check if a string is in UUID format.
     *
     * @param string $value The value to check.
     *
     * @return bool True if the value matches UUID format.
     */
    private function isUuidFormat(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }//end isUuidFormat()

    /**
     * Clear the created sub-objects cache.
     *
     * Should be called before processing a new parent object.
     *
     * @return void
     *
     * @spec exclude One-line delegation to SaveObject::clearCreatedSubObjects(); cache-reset plumbing.
     */
    public function clearCreatedSubObjects(): void
    {
        $this->saveHandler->clearCreatedSubObjects();
    }//end clearCreatedSubObjects()

    /**
     * Handle validation exceptions
     *
     * @param ValidationException|CustomValidationException $exception The exception to handle
     *
     * @return \OCP\AppFramework\Http\JSONResponse JSON error response
     *
     * @deprecated
     *
     * @spec exclude Deprecated one-line delegation to ValidateObject::handleValidationException(); error-shaping plumbing.
     */
    public function handleValidationException(
        ValidationException|CustomValidationException $exception
    ): \OCP\AppFramework\Http\JSONResponse {
        return $this->validateHandler->handleValidationException($exception);
    }//end handleValidationException()

    /**
     * Lock an object
     *
     * Locks an object to prevent concurrent modifications.
     *
     * @param string      $identifier Object ID or UUID
     * @param string|null $process    Process ID (for tracking who locked it)
     * @param int|null    $duration   Lock duration in seconds
     * @param bool        $advisory   When true, treat the identifier as a synthetic
     *                                pre-creation key and take the appConfig-backed
     *                                advisory lock without scanning object tables
     *
     * @return array Lock information
     *
     * @throws \Exception If lock operation fails
     *
     * @spec exclude One-line delegation to lock handler; lock behavior owned by object-lifecycle.
     */
    public function lockObject(string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false): array
    {
        return $this->lockHandler->lock(identifier: $identifier, process: $process, duration: $duration, advisory: $advisory);
    }//end lockObject()

    /**
     * Unlock an object
     *
     * Removes the lock from an object, allowing other processes to modify it.
     *
     * @param string|int $identifier The object to unlock
     * @param bool       $advisory   When true, release the appConfig-backed advisory
     *                               lock for this synthetic key without scanning tables
     *
     * @return true True if unlocked successfully
     *
     * @throws \Exception If unlock operation fails
     *
     * @spec exclude One-line delegation to lock handler; unlock behavior owned by object-lifecycle.
     */
    public function unlockObject(string|int $identifier, bool $advisory=false): bool
    {
        return $this->lockHandler->unlock(identifier: (string) $identifier, advisory: $advisory);
    }//end unlockObject()

    /**
     * Bulk Save Operations Orchestrator (HIGH-PERFORMANCE BULK PROCESSING)
     *
     * ARCHITECTURAL ROLE:
     * This is the primary bulk operations orchestrator that coordinates high-performance bulk saving
     * of multiple objects. It implements advanced performance optimizations including schema analysis
     * caching, memory optimization, single-pass processing, and batch database operations.
     *
     * PERFORMANCE OPTIMIZATIONS IMPLEMENTED:
     * 1. ✅ Eliminate redundant object fetch after save - reconstructed from existing data
     * 2. ✅ Consolidate schema cache - single persistent cache across operations
     * 3. ✅ Batch writeBack operations - bulk UPDATEs instead of individual calls
     * 4. ✅ Single-pass inverse relations - combined scanning and applying
     * 5. ✅ Optimize object transformation - in-place operations, minimal copying
     * 6. ✅ Comprehensive schema analysis - single pass for all requirements
     * 7. ✅ Memory optimization - pass-by-reference, selective updates
     *
     * RESPONSIBILITY SEPARATION:
     * - ObjectService.saveObjects() = Bulk orchestration, performance optimization, chunking
     * - SaveObject methods = Individual object complexities (cascading, writeBack)
     * - MagicMapper.saveObjects() = Actual database bulk operations
     *
     * WORKFLOW:
     * 1. Comprehensive schema analysis and caching
     * 2. Memory-optimized object preparation with relation processing
     * 3. Optional validation with minimal copying
     * 4. In-place format transformation
     * 5. Batch database operations
     * 6. Optimized inverse relation processing
     * 7. Bulk writeBack operations
     *
     * FOR INDIVIDUAL OBJECTS: Use saveObject() method for full feature set
     *
     * PERFORMANCE GAINS:
     * - Database calls: ~60-70% reduction
     * - Memory usage: ~40% reduction
     * - Time complexity: O(N*M*P) → O(N*M)
     * - Processing speed: 2-3x faster for large datasets
     *
     * @param array                    $objects        Array of objects in serialized format
     * @param Register|string|int|null $register       Optional register filter for validation
     * @param Schema|string|int|null   $schema         Optional schema filter for validation
     * @param bool                     $_rbac          Whether to apply RBAC filtering
     * @param bool                     $_multitenancy  Whether to apply multi-organization filtering
     * @param bool                     $validation     Whether to validate objects against schema definitions
     * @param bool                     $events         Whether to dispatch object lifecycle events
     * @param bool                     $deduplicateIds Whether to deduplicate objects with same ID
     * @param bool                     $enrich         Whether to enrich objects with metadata
     *
     * @throws \InvalidArgumentException If required fields are missing from any object
     * @throws \OCP\DB\Exception If a database error occurs during bulk operations
     *
     * @psalm-param   array<int, array<string, mixed>> $objects
     * @phpstan-param array<int, array<string, mixed>> $objects
     *
     * @return array Comprehensive bulk operation results with statistics and categorized objects
     *
     * @phpstan-return array<string, mixed>
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function saveObjects(
        array $objects,
        Register|string|int|null $register=null,
        Schema|string|int|null $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $validation=false,
        bool $events=false,
        bool $deduplicateIds=true,
        bool $enrich=true
    ): array {

        // Bound the folder-access revalidation cache to this bulk-save call
        // (see saveObject) so mid-request folder mutations are re-validated.
        $this->fileService->resetFolderAccessRevalidationCache();

        // Set register and schema context if provided.
        if ($register !== null) {
            $this->setRegister(register: $register);
        }

        if ($schema !== null) {
            $this->setSchema(schema: $schema);
        }

        // Delegate to SaveObjects handler for bulk save operations.
        $bulkResult = $this->saveObjectsHandler->saveObjects(
            objects: $objects,
            register: $this->currentRegister,
            schema: $this->currentSchema,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            _validation: $validation,
            _events: $events,
            deduplicateIds: $deduplicateIds,
            enrich: $enrich
        );

        // Invalidate collection caches after successful bulk operations.
        $createdCount  = (int) ($bulkResult['statistics']['objectsCreated'] ?? 0);
        $updatedCount  = (int) ($bulkResult['statistics']['objectsUpdated'] ?? 0);
        $totalAffected = $createdCount + $updatedCount;

        if ($totalAffected > 0) {
            try {
                $this->cacheHandler->invalidateForObjectChange(
                    object: null,
                    operation: 'bulk_save',
                    registerId: $this->currentRegister?->getId(),
                    schemaId: $this->currentSchema?->getId()
                );
            } catch (\Exception $e) {
                // BUG-OBJ-14: include register/schema context in the warning.
                $this->logger->warning(
                    message: '[ObjectService] Bulk save cache invalidation failed',
                    context: [
                        'error'         => $e->getMessage(),
                        'totalAffected' => $totalAffected,
                        'registerId'    => $this->currentRegister?->getId(),
                        'schemaId'      => $this->currentSchema?->getId(),
                    ]
                );
            }
        }//end if

        return $bulkResult;
    }//end saveObjects()

    /**
     * Transform objects from serialized format to database format
     *
     * Moves everything except '@self' into the 'object' property and moves
     * '@self' contents to the root level.
     *
     * @param array $objects Array of objects in serialized format
     *
     * @psalm-param   array<int, array<string, mixed>> $objects
     * @phpstan-param array<int, array<string, mixed>> $objects
     *
     * @return array Array of transformed objects in database format
     *
     * @psalm-return   array<int, array<string, mixed>>
     * @phpstan-return array<int, array<string, mixed>>
     */

    /**
     * Migrate objects between registers and/or schemas
     *
     * This method migrates multiple objects from one register/schema combination
     * to another register/schema combination with property mapping.
     *
     * @param string|int $sourceRegister The source register ID or slug
     * @param string|int $sourceSchema   The source schema ID or slug
     * @param string|int $targetRegister The target register ID or slug
     * @param string|int $targetSchema   The target schema ID or slug
     * @param array      $objectIds      Array of object IDs to migrate
     * @param array      $mapping        Mapping where keys are target properties, values are source properties
     *
     * @return array Migration report with success status, statistics, details, warnings, and errors.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If register or schema not found.
     * @throws \InvalidArgumentException If invalid parameters provided.
     *
     * @spec exclude One-line delegation to MigrationHandler::migrateObjects(); migration logic owned by the handler.
     */
    public function migrateObjects(
        string|int $sourceRegister,
        string|int $sourceSchema,
        string|int $targetRegister,
        string|int $targetSchema,
        array $objectIds,
        array $mapping
    ): array {
        // ARCHITECTURAL DELEGATION: Delegate to MigrationHandler for all migration logic.
        return $this->migrationHandler->migrateObjects(
            sourceRegister: $sourceRegister,
            sourceSchema: $sourceSchema,
            targetRegister: $targetRegister,
            targetSchema: $targetSchema,
            objectIds: $objectIds,
            mapping: $mapping
        );
    }//end migrateObjects()

    /**
     * Perform bulk delete operations on objects by UUID
     *
     * This method handles both soft delete and hard delete based on the current state
     * of the objects. If an object has no deleted value set, it performs a soft delete
     * by setting the deleted timestamp. If an object already has a deleted value set,
     * it performs a hard delete by removing the object from the database.
     *
     * @param array $uuids         Array of object UUIDs to delete
     * @param bool  $_rbac         Whether to apply RBAC filtering
     * @param bool  $_multitenancy Whether to apply multi-organization filtering
     *
     * @return array Associative array with 'deleted_uuids', 'skipped_uuids', and 'cascade_count' keys.
     *               'deleted_uuids' contains UUIDs of successfully deleted objects.
     *               'skipped_uuids' contains UUIDs skipped due to RESTRICT or errors.
     *               'cascade_count' contains total count of objects affected by cascade operations.
     *
     * @phpstan-param  array<int, string> $uuids
     * @psalm-param    array<int, string> $uuids
     * @phpstan-return array{deleted_uuids: array<int, string>, skipped_uuids: array<int, string>, cascade_count: int}
     * @psalm-return   array{deleted_uuids: array<int, string>, skipped_uuids: array<int, string>, cascade_count: int}
     *
     * @spec exclude Bulk-delete loop over deleteHandler->deleteObject(); per-object RESTRICT/CASCADE behavior owned by referential-integrity.
     */
    public function deleteObjects(array $uuids=[], bool $_rbac=true, bool $_multitenancy=true): array
    {
        if (empty($uuids) === true) {
            return ['deleted_uuids' => [], 'skipped_uuids' => [], 'cascade_count' => 0];
        }

        // Apply RBAC and multi-organization filtering if enabled.
        $filteredUuids = $uuids;
        if ($_rbac === true || $_multitenancy === true) {
            $filteredUuids = $this->permissionHandler->filterUuidsForPermissions(
                uuids: $uuids,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );
        }

        // PERF: resolve the scope AND the entity of every UUID with ONE batched
        // cross-table lookup instead of a full magic-table scan per UUID. The
        // batch matches on the uuid column only; identifiers in other shapes
        // (numeric ids, slugs, URIs) fall back to the legacy per-uuid lookup.
        $preResolvedObjects = $this->batchResolveDeleteScopes(uuids: $filteredUuids);

        // Request-local caches so each distinct (register, schema) pair is
        // materialised as entities at most once for the whole bulk operation.
        $registerEntityCache = [];
        $schemaEntityCache   = [];

        // Process each object individually through the delete handler so that
        // referential integrity rules (CASCADE, SET_NULL, SET_DEFAULT, RESTRICT)
        // are enforced per object. Skips objects that fail (e.g., RESTRICT blocks).
        $deletedObjectIds  = [];
        $skippedUuids      = [];
        $totalCascadeCount = 0;
        // BUG-OBJ-5: collect the distinct (registerId, schemaId) pairs of the
        // objects we actually delete so we can invalidate the per-schema query
        // cache for each of them. A bulk delete may span multiple registers /
        // schemas (cross-table), and CacheHandler::clearSchemaRelatedCaches()
        // only clears the distributed query cache when schemaId !== null —
        // passing null/null below left those caches stale.
        $invalidationPairs = [];
        foreach ($filteredUuids as $uuid) {
            try {
                // BUG-OBJ-5: resolve the object's register/schema BEFORE deleting
                // it (the delete handler returns only a bool). Prefer the batch
                // resolution above; fall back to a per-uuid mapper find (no read
                // audit trail) that also matches non-uuid identifier shapes. Both
                // include already-soft-deleted rows so a delete of a trashed
                // object still yields its scope.
                $deletedRegisterId = null;
                $deletedSchemaId   = null;
                $preResolved       = ($preResolvedObjects[$uuid] ?? null);
                if ($preResolved !== null) {
                    $deletedRegisterId = $preResolved->getRegister();
                    $deletedSchemaId   = $preResolved->getSchema();
                }

                if ($preResolved === null) {
                    try {
                        $preDeleteObject   = $this->objectMapper->find(
                            identifier: $uuid,
                            register: $this->currentRegister,
                            schema: $this->currentSchema,
                            includeDeleted: true,
                            _rbac: $_rbac,
                            _multitenancy: $_multitenancy
                        );
                        $deletedRegisterId = $preDeleteObject->getRegister();
                        $deletedSchemaId   = $preDeleteObject->getSchema();
                    } catch (\Throwable $resolveError) {
                        // BUG-OBJ-14: scope resolution failed (object already gone or
                        // not visible) — log and fall back to a broad invalidation.
                        $this->logger->warning(
                            message: '[ObjectService] Bulk delete could not resolve register/schema scope for cache invalidation',
                            context: [
                                'uuid'  => $uuid,
                                'error' => $resolveError->getMessage(),
                            ]
                        );
                    }//end try
                }//end if

                // PERF: hand the batch-resolved entity + its concrete scope to the
                // delete handler so it skips its own per-object cross-table re-find.
                // When scope entities cannot be materialised, keep the legacy call.
                $handlerRegister    = $this->currentRegister;
                $handlerSchema      = $this->currentSchema;
                $handlerPreResolved = null;
                if ($preResolved !== null) {
                    $scopeEntities = $this->loadDeleteScopeEntities(
                        registerId: $deletedRegisterId,
                        schemaId: $deletedSchemaId,
                        registerCache: $registerEntityCache,
                        schemaCache: $schemaEntityCache
                    );
                    if ($scopeEntities !== null) {
                        [$handlerRegister, $handlerSchema] = $scopeEntities;
                        $handlerPreResolved = $preResolved;
                    }
                }

                $result = $this->deleteHandler->deleteObject(
                    register: $handlerRegister,
                    schema: $handlerSchema,
                    uuid: $uuid,
                    originalObjectId: null,
                    _rbac: $_rbac,
                    _multitenancy: $_multitenancy,
                    preResolved: $handlerPreResolved
                );
                if ($result === true) {
                    $deletedObjectIds[] = $uuid;
                    // BUG-OBJ-10: read the cascade count EXACTLY ONCE per
                    // successful delete. The handler resets lastCascadeCount to 0
                    // at the start of every root delete (originalObjectId === null,
                    // which is always the case here), so each read reflects only
                    // this object's cascade and accumulates correctly.
                    $totalCascadeCount += $this->deleteHandler->getLastCascadeCount();

                    // BUG-OBJ-5: record the distinct scope pair for invalidation.
                    // CacheHandler expects int ids; entity getters return string ids.
                    if ($deletedSchemaId !== null && $deletedSchemaId !== '') {
                        $registerIdInt = null;
                        if ($deletedRegisterId !== null && $deletedRegisterId !== '') {
                            $registerIdInt = (int) $deletedRegisterId;
                        }

                        $schemaIdInt = (int) $deletedSchemaId;
                        $pairKey     = ($registerIdInt ?? 'null').':'.$schemaIdInt;
                        $invalidationPairs[$pairKey] = [
                            'registerId' => $registerIdInt,
                            'schemaId'   => $schemaIdInt,
                        ];
                    }
                }//end if
            } catch (\OCA\OpenRegister\Exception\ReferentialIntegrityException $e) {
                // RESTRICT blocks should not abort the entire bulk operation.
                // Log and skip this object, continue with the rest.
                $this->logger->info(
                    message: '[ObjectService] Bulk delete skipped object due to RESTRICT constraint',
                    context: [
                        'uuid'     => $uuid,
                        'blockers' => count($e->getAnalysis()->blockers),
                    ]
                );
                $skippedUuids[] = $uuid;
            } catch (\Exception $e) {
                // Other failures (transaction rollback, etc.) are logged and skipped.
                $this->logger->warning(
                    message: '[ObjectService] Bulk delete failed for object',
                    context: [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                    ]
                );
                $skippedUuids[] = $uuid;
            }//end try
        }//end foreach

        // Invalidate collection caches after bulk delete operations.
        // BUG-OBJ-5: invalidate per distinct (register, schema) pair gathered
        // from the deleted objects, so CacheHandler::clearSchemaRelatedCaches()
        // actually clears the per-schema distributed query cache (it no-ops when
        // schemaId is null). Falls back to a broad null/null invalidation only
        // when no scope could be resolved for any deleted object.
        if (empty($deletedObjectIds) === false) {
            $pairsToInvalidate = $invalidationPairs;
            if (empty($pairsToInvalidate) === true) {
                $pairsToInvalidate = [['registerId' => null, 'schemaId' => null]];
            }

            foreach ($pairsToInvalidate as $pair) {
                try {
                    $this->cacheHandler->invalidateForObjectChange(
                        object: null,
                        operation: 'bulk_delete',
                        registerId: $pair['registerId'],
                        schemaId: $pair['schemaId']
                    );
                } catch (\Exception $e) {
                    // BUG-OBJ-14: log with register/schema context instead of
                    // silently swallowing the invalidation failure.
                    $this->logger->warning(
                        message: '[ObjectService] Bulk delete cache invalidation failed',
                        context: [
                            'error'        => $e->getMessage(),
                            'deletedCount' => count($deletedObjectIds),
                            'registerId'   => $pair['registerId'],
                            'schemaId'     => $pair['schemaId'],
                        ]
                    );
                }
            }//end foreach
        }//end if

        return [
            'deleted_uuids' => $deletedObjectIds,
            'skipped_uuids' => $skippedUuids,
            'cascade_count' => $totalCascadeCount,
        ];
    }//end deleteObjects()

    /**
     * Batch-resolve the entities (and thus register/schema scopes) for a set of UUIDs.
     *
     * One cross-table lookup for the whole set (uuid-column matches only).
     * Includes soft-deleted rows so deleting a trashed object still resolves
     * its scope. A total lookup failure logs and returns an empty map — every
     * uuid then falls back to the legacy per-uuid resolution.
     *
     * @param array $uuids The UUIDs to resolve.
     *
     * @return array<string, \OCA\OpenRegister\Db\ObjectEntity> Resolved entities keyed by uuid.
     *
     * @phpstan-param array<int, string> $uuids
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function batchResolveDeleteScopes(array $uuids): array
    {
        $resolved = [];
        try {
            $entities = $this->objectMapper->findMultipleAcrossAllMagicTables(
                uuids: $uuids,
                includeDeleted: true
            );
            foreach ($entities as $entity) {
                $entityUuid = $entity->getUuid();
                if ($entityUuid !== null && $entityUuid !== '') {
                    $resolved[$entityUuid] = $entity;
                }
            }
        } catch (\Throwable $batchError) {
            $this->logger->warning(
                message: '[ObjectService] Bulk delete batch scope resolution failed; falling back to per-uuid lookups',
                context: ['error' => $batchError->getMessage()]
            );
        }

        return $resolved;
    }//end batchResolveDeleteScopes()

    /**
     * Materialise the Register + Schema entities for a resolved delete scope.
     *
     * Uses per-bulk-operation caches (passed by reference) so each distinct
     * pair is loaded at most once; RegisterMapper/SchemaMapper add their own
     * request-scoped caching underneath. Returns null when either entity
     * cannot be loaded — the caller then keeps the legacy delete call.
     *
     * @param string|int|null $registerId    The register id from the resolved entity.
     * @param string|int|null $schemaId      The schema id from the resolved entity.
     * @param array           $registerCache Cache of Register entities keyed by int id.
     * @param array           $schemaCache   Cache of Schema entities keyed by int id.
     *
     * @return array{0: \OCA\OpenRegister\Db\Register, 1: \OCA\OpenRegister\Db\Schema}|null
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function loadDeleteScopeEntities(
        string | int | null $registerId,
        string | int | null $schemaId,
        array &$registerCache,
        array &$schemaCache
    ): ?array {
        if (is_numeric($registerId) === false || is_numeric($schemaId) === false) {
            return null;
        }

        $registerKey = (int) $registerId;
        $schemaKey   = (int) $schemaId;

        try {
            if (isset($registerCache[$registerKey]) === false) {
                $registerCache[$registerKey] = $this->registerMapper->find(
                    $registerKey,
                    _rbac: false,
                    _multitenancy: false
                );
            }

            if (isset($schemaCache[$schemaKey]) === false) {
                $schemaCache[$schemaKey] = $this->schemaMapper->find(
                    $schemaKey,
                    _rbac: false,
                    _multitenancy: false
                );
            }
        } catch (\Throwable $scopeError) {
            $this->logger->warning(
                message: '[ObjectService] Bulk delete could not materialise scope entities',
                context: [
                    'registerId' => $registerKey,
                    'schemaId'   => $schemaKey,
                    'error'      => $scopeError->getMessage(),
                ]
            );
            return null;
        }//end try

        return [$registerCache[$registerKey], $schemaCache[$schemaKey]];
    }//end loadDeleteScopeEntities()

    /**
     * Delete all objects belonging to a specific schema
     *
     * Every object is snapshotted to the (hash-chained) audit trail before its row is
     * touched, then the rows are removed in one bulk statement via MagicMapper.
     *
     * The work itself lives in SchemaDeletionService, which is shared with the schema
     * cascade (`DELETE /api/schemas/{id}?deleteObjects=true`). It is resolved lazily
     * from the container rather than constructor-injected: ObjectService is
     * constructed on virtually every request, and this is the only method that needs
     * it.
     *
     * @param int  $registerId The ID of the register
     * @param int  $schemaId   The ID of the schema whose objects should be deleted
     * @param bool $hardDelete Whether to force hard delete (default: false)
     *
     * @return (int|string[])[]
     *
     * @throws \Exception If the deletion operation fails
     *
     * @phpstan-return array{deleted_count: int, deleted_uuids: array<int, string>, schema_id: int}
     *
     * @psalm-return array{deleted_count: int<min, max>, deleted_uuids: array<int, string>, schema_id: int}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The hard/soft toggle mirrors the mapper primitive it wraps.
     *
     * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
     */
    public function deleteObjectsBySchema(int $registerId, int $schemaId, bool $hardDelete=false): array
    {
        $schemaDeletionService = $this->container->get(\OCA\OpenRegister\Service\SchemaDeletionService::class);

        return $schemaDeletionService->deleteObjectsBySchema(
            registerId: $registerId,
            schemaId: $schemaId,
            hardDelete: $hardDelete,
            triggeredBy: \OCA\OpenRegister\Service\SchemaDeletionService::TRIGGER_BULK_DELETE
        );
    }//end deleteObjectsBySchema()

    /**
     * Delete all objects belonging to a specific register
     *
     * This method efficiently deletes all objects that belong to the specified register.
     * It uses bulk operations for optimal performance and maintains data integrity.
     *
     * @param int $registerId The ID of the register whose objects should be deleted
     *
     * @return (int|string[])[]
     *
     * @throws \Exception If the deletion operation fails
     *
     * @phpstan-return array{deleted_count: int, deleted_uuids: array<int, string>, register_id: int}
     *
     * @psalm-return array{deleted_count: int<min, max>, deleted_uuids: array<int, string>, register_id: int}
     *
     * @spec exclude Deprecated throwing stub; register-wide delete awaits MagicMapper reimplementation (blob table retired).
     */
    public function deleteObjectsByRegister(int $registerId): array
    {
        // TODO: Reimplement using MagicMapper for register-wide delete on magic tables.
        throw new RuntimeException(
            'deleteObjectsByRegister needs reimplementation using MagicMapper (blob objects table retired)'
        );
    }//end deleteObjectsByRegister()

    // **REMOVED**: clearResponseCache method removed since SOLR is now our index.
    // **REMOVED**: generateCacheKey method removed since SOLR is now our index.
    // =========================================================================
    // NEW HANDLER DELEGATION METHODS
    // =========================================================================

    /**
     * Get object contracts
     *
     * @param string $objectId Object ID or UUID
     * @param array  $filters  Filters for pagination
     *
     * @return (array|int|mixed)[] Contracts data
     *
     * @psalm-return array{results: array|mixed, total: int<0, max>, limit: 30|mixed, offset: 0|mixed}
     */
    public function getObjectContracts(string $objectId, array $filters=[]): array
    {
        return $this->relationHandler->getContracts(objectId: $objectId, filters: $filters);
    }//end getObjectContracts()

    /**
     * Get objects that this object uses (outgoing relations)
     *
     * @param string $objectId      Object ID or UUID
     * @param array  $query         Search query parameters
     * @param bool   $_rbac         Apply RBAC filters
     * @param bool   $_multitenancy Apply multitenancy filters
     *
     * @return array Results with object entities and pagination info.
     *
     * @throws \Exception If retrieval fails.
     *
     * @spec exclude One-line delegation to RelationHandler::getUses(); outgoing-relation behavior owned by nextcloud-entity-relations.
     */
    public function getObjectUses(
        string $objectId,
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        return $this->relationHandler->getUses(
            objectId: $objectId,
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            _registerId: $this->currentRegister?->getId(),
            _schemaId: $this->currentSchema?->getId()
        );
    }//end getObjectUses()

    /**
     * Get objects that use this object (incoming relations)
     *
     * @param string $objectId      Object ID or UUID
     * @param array  $query         Search query parameters
     * @param bool   $_rbac         Apply RBAC filters
     * @param bool   $_multitenancy Apply multitenancy filters
     *
     * @return array Paginated results with referencing objects
     *
     * @throws \Exception If retrieval fails
     *
     * @spec exclude One-line delegation to RelationHandler::getUsedBy(); incoming-relation behavior owned by nextcloud-entity-relations.
     */
    public function getObjectUsedBy(
        string $objectId,
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        return $this->relationHandler->getUsedBy(
            objectId: $objectId,
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            _registerId: $this->currentRegister?->getId(),
            _schemaId: $this->currentSchema?->getId()
        );
    }//end getObjectUsedBy()

    /**
     * Vectorize objects in batch
     *
     * @param array|null $_views     Optional view filters
     * @param int        $_batchSize Number of objects to process per batch
     *
     * @return never Vectorization results
     *
     * @throws \Exception If vectorization fails
     *
     * @spec exclude Deprecated throwing stub; disabled pending VectorizationService circular-dependency refactor.
     */
    public function vectorizeBatchObjects(?array $_views=null, int $_batchSize=25)
    {
        // TODO: TEMPORARILY DISABLED due to circular dependency with VectorizationService.
        // Requires architectural refactoring to fix. See DEBUGGING_REGISTER_CREATION_TIMEOUT.md.
        throw new Exception('Vectorization temporarily disabled due to circular dependency issues');
    }//end vectorizeBatchObjects()

    /**
     * Get vectorization statistics
     *
     * @param array|null $_views Optional view filters
     *
     * @return never Statistics data
     *
     * @throws \Exception If stats retrieval fails
     *
     * @spec exclude Deprecated throwing stub; disabled pending VectorizationService circular-dependency refactor.
     */
    public function getVectorizationStatistics(?array $_views=null)
    {
        // TODO: TEMPORARILY DISABLED due to circular dependency with VectorizationService.
        throw new Exception('Vectorization temporarily disabled due to circular dependency issues');
    }//end getVectorizationStatistics()

    /**
     * Get count of objects available for vectorization
     *
     * @param array|null $_schemas Optional schema filters
     *
     * @return never Object count
     *
     * @throws \Exception If count fails
     *
     * @spec exclude Deprecated throwing stub; disabled pending VectorizationService circular-dependency refactor.
     */
    public function getVectorizationCount(?array $_schemas=null)
    {
        // TODO: TEMPORARILY DISABLED due to circular dependency with VectorizationService.
        throw new Exception('Vectorization temporarily disabled due to circular dependency issues');
    }//end getVectorizationCount()

    // =========================================================================
    // CRUD HANDLER DELEGATION METHODS
    // =========================================================================

    /**
     * List objects with filtering and pagination
     *
     * @param array       $query         Search query parameters
     * @param bool        $_rbac         Apply RBAC filters
     * @param bool        $_multitenancy Apply multitenancy filters
     * @param bool        $_deleted      Include deleted objects
     * @param array|null  $_ids          Optional array of object IDs to filter
     * @param string|null $_uses         Optional object ID that results must use
     * @param array|null  $_views        Optional view filters
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]|int
     *
     * @throws \Exception If listing fails
     *
     * @psalm-return int<0, max>|list<\OCA\OpenRegister\Db\ObjectEntity>
     *
     * @spec exclude Facade alias delegating to searchObjects(); listing behavior owned by zoeken-filteren.
     */
    public function listObjects(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $_deleted=false,
        ?array $_ids=null,
        ?string $_uses=null,
        ?array $_views=null
    ): array|int {
        // REFACTORED: Removed CrudHandler (was unimplemented stub causing circular dependency).
        // Use searchObjects() for actual object listing.
        return $this->searchObjects(
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end listObjects()

    /**
     * Create new object
     *
     * @param array $data          Object data
     * @param bool  $_rbac         Apply RBAC checks
     * @param bool  $_multitenancy Apply multitenancy filtering
     *
     * @return ObjectEntity Created object entity
     *
     * @throws \Exception If creation fails
     *
     * @spec exclude Facade delegating to saveObject(); create behavior owned by object-interactions / object-lifecycle.
     */
    public function createObject(array $data, bool $_rbac=true, bool $_multitenancy=true): ObjectEntity
    {
        // REFACTORED: Removed CrudHandler (was unimplemented stub). Use saveObject() instead.
        return $this->saveObject(object: $data);
    }//end createObject()

    /**
     * Update existing object (full update)
     *
     * @param string $objectId      Object ID or UUID
     * @param array  $data          New object data
     * @param bool   $_rbac         Apply RBAC checks
     * @param bool   $_multitenancy Apply multitenancy filtering
     *
     * @return ObjectEntity Updated object entity
     *
     * @throws \Exception If update fails
     *
     * @spec exclude Facade delegating to saveObject() with id; update behavior owned by object-interactions / object-lifecycle.
     */
    public function updateObject(
        string $objectId,
        array $data,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        // REFACTORED: Removed CrudHandler (was unimplemented stub). Use saveObject() with ID.
        // Get existing object and merge with new data (currently unused but kept for reference).
        // $existing = $this->objectMapper->find((int) $objectId);.
        $data['id'] = $objectId;
        return $this->saveObject(object: $data);
    }//end updateObject()

    /**
     * Patch existing object (partial update)
     *
     * @param string $objectId      Object ID or UUID
     * @param array  $data          Partial object data
     * @param bool   $_rbac         Apply RBAC checks
     * @param bool   $_multitenancy Apply multitenancy filtering
     *
     * @return ObjectEntity Patched object entity
     *
     * @throws \Exception If patch fails
     *
     * @spec exclude Facade delegating to saveObject() with merged partial data; patch behavior owned by object-interactions.
     */
    public function patchObject(
        string $objectId,
        array $data,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        // REFACTORED: Removed CrudHandler (was unimplemented stub). Use saveObject() for patching.
        // Get existing object, merge partial data, and save.
        $existing     = $this->objectMapper->find((int) $objectId);
        $merged       = array_merge($existing->getObject(), $data);
        $merged['id'] = $objectId;
        return $this->saveObject(object: $merged);
    }//end patchObject()

    /**
     * Build search query from request parameters
     *
     * @param array $params Request parameters
     *
     * @return array Normalized search query
     *
     * @psalm-return array<string, mixed>
     *
     * @spec exclude Facade alias delegating to buildSearchQuery(); query-building owned by zoeken-filteren.
     */
    public function buildObjectSearchQuery(array $params): array
    {
        // REFACTORED: Removed CrudHandler (caused circular dependency - called back to ObjectService).
        // Call buildSearchQuery() directly (already exists in ObjectService).
        return $this->buildSearchQuery(requestParams: $params);
    }//end buildObjectSearchQuery()

    // =========================================================================
    // MERGE/MIGRATE HANDLER DELEGATION METHODS
    // =========================================================================

    /**
     * Merge objects using MergeHandler.
     *
     * @param string $sourceObjectId Source object ID
     * @param array  $mergeData      Merge data
     *
     * @return array Merge result with details
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex merge logic delegated to handler
     * @SuppressWarnings(PHPMD.NPathComplexity)       Many merge scenarios handled by handler
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Merge operations require comprehensive handling
     *
     * @spec exclude One-line delegation to MergeHandler::mergeObjects(); merge logic owned by the handler.
     */
    public function mergeObjects(string $sourceObjectId, array $mergeData): array
    {
        return $this->mergeHandler->mergeObjects(sourceObjectId: $sourceObjectId, mergeData: $mergeData);
    }//end mergeObjects()

    /**
     * Validate objects by schema using ValidationHandler (for testing - does not save).
     *
     * @param int $schemaId Schema ID
     *
     * @return array Validation result with valid and invalid objects
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function validateObjectsBySchema(int $schemaId): array
    {
        return $this->validationHandler->validateObjectsBySchema(schemaId: $schemaId, saveCallback: [$this, 'saveObject']);
    }//end validateObjectsBySchema()

    /**
     * Validate and save all objects by schema, updating metadata like _name.
     *
     * This method validates all objects belonging to the specified schema and saves them
     * to update metadata fields. This is useful for bulk updating object metadata after
     * schema changes or imports.
     *
     * @param int      $registerId Register ID
     * @param int      $schemaId   Schema ID
     * @param int|null $limit      Maximum number of objects to process (null = all)
     * @param int      $offset     Number of objects to skip before processing
     *
     * @return array{processed: int, updated: int, failed: int, total: int, errors: array} Validation statistics
     *
     * @spec exclude Delegation to ValidationHandler::validateAndSaveObjectsBySchema() with a saveObject callback; handler owns the loop.
     */
    public function validateAndSaveObjectsBySchema(int $registerId, int $schemaId, ?int $limit=null, int $offset=0): array
    {
        return $this->validationHandler->validateAndSaveObjectsBySchema(
            registerId: $registerId,
            schemaId: $schemaId,
            saveCallback: [$this, 'saveObject'],
            limit: $limit,
            offset: $offset
        );
    }//end validateAndSaveObjectsBySchema()

    /**
     * Reset the current register, schema, and object context.
     *
     * Called by external apps (e.g. OpenConnector) before performing a fresh
     * lookup to prevent stale context from a previous request bleeding through.
     *
     * @return void
     *
     * @spec exclude Context-reset setter nulling the current register/schema/object fields; no business rule.
     */
    public function clearCurrents(): void
    {
        $this->currentRegister = null;
        $this->currentSchema   = null;
        $this->currentObject   = null;
    }//end clearCurrents()

    /**
     * Return the internal object-validation handler.
     *
     * Exposed so adapters and external services can run validation without
     * depending on ObjectService internals directly.
     *
     * @return ValidateObject
     */
    public function getValidateHandler(): ValidateObject
    {
        return $this->validateHandler;
    }//end getValidateHandler()

    /**
     * Return a mapper-like adapter scoped to the given register and schema.
     *
     * Allows external apps to interact with OpenRegister objects through a
     * familiar mapper contract without depending on ObjectService internals.
     *
     * When $register is a non-numeric string (e.g. the type hint 'objectEntity'
     * passed by OpenConnector), it is treated as an unscoped request and both
     * register and schema are set to null so the adapter searches globally.
     *
     * @param int|string|null $register Register ID, or a type-hint string that is ignored.
     * @param int|string|null $schema   Schema ID.
     *
     * @return ObjectServiceMapperAdapter
     *
     * @spec exclude Factory accessor returning a register/schema-scoped mapper adapter for external callers; no business rule.
     */
    public function getMapper(int|string|null $register=null, int|string|null $schema=null): ObjectServiceMapperAdapter
    {
        // A non-numeric string (e.g. 'objectEntity') is a type-hint from the caller, not a register ID.
        // Return an unconstrained adapter so find() searches across all registers/schemas.
        if (is_string($register) === true && is_numeric($register) === false) {
            $register = null;
            $schema   = null;
        }

        return new ObjectServiceMapperAdapter(
            objectService: $this,
            register: $register,
            schema: $schema
        );
    }//end getMapper()
}//end class
