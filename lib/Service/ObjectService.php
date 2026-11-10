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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Formats\UuidFormat;
use OCA\OpenRegister\Service\Rbac\MaskedWrite;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Archival\RecordState;
use Adbar\Dot;
use DateTime;
use Exception;
use stdClass;
use RuntimeException;
use ReflectionClass;
use InvalidArgumentException;
use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\FacetableAnalyzer;
use OCA\OpenRegister\Service\FileFolderServiceInterface;
use OCA\OpenRegister\Service\Hinge\LensResolver;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Object\DraftCaller;
use OCA\OpenRegister\Service\Object\DraftCallerRule;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\DeletionOptions;
use OCA\OpenRegister\Service\Object\DeletionRequest;
use OCA\OpenRegister\Service\Object\DeletionGraph\ReadableDeletionAnalysis;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\ObjectDeleteRequest;
use OCA\OpenRegister\Service\Object\ObjectFindRequest;
use OCA\OpenRegister\Service\Object\ObjectFindScope;
use OCA\OpenRegister\Service\Object\ObjectSaveRun;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\SchemaRevalidation;
use OCA\OpenRegister\Service\Object\BulkBatchDefaults;
use OCA\OpenRegister\Service\Object\BulkSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectPatch;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RenderRequest;
use OCA\OpenRegister\Service\Object\RenderSelection;
use OCA\OpenRegister\Service\Object\BatchOperationStatus;
use OCA\OpenRegister\Service\Object\SaveObjectInterface;
use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCA\OpenRegister\Service\Geo\GeoJsonGeometryValidator;
use OCA\OpenRegister\Service\Object\ResultNameCollector;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\RetentionDestruction;
use OCA\OpenRegister\Service\Object\ObjectDateValues;
use OCA\OpenRegister\Service\Object\PatchDataMerge;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RelationLookup;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\CrudHandler;
use OCA\OpenRegister\Service\Object\ObjectSourcePageReader;
use OCA\OpenRegister\Service\Object\ObjectSourceQuery;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Kernel\Contracts\ObjectSelection;
use OCA\OpenRegister\Kernel\Contracts\SearchOptions;
use OCA\OpenRegister\Service\Object\SaveObject\StreamingSave;
use OCA\OpenRegister\Service\Object\LockRequest;
use OCA\OpenRegister\Service\Object\UnlockRequest;
use OCA\OpenRegister\Service\Object\LockRunStep;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\NotSuppliedHandler;
use OCA\OpenRegister\Service\Object\RepeatingGroupValidator;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Exception\ReadOnlyTypeException;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Service\Object\AccessFlags;
use OCP\AppFramework\Db\DoesNotExistException as OcpDoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\OpenRegister\Service\Object\SaveAccessRequest;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\AppFramework\IAppContainer;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\OpenRegister\Service\Object\SearchTrailRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Service\Object\BulkCacheInvalidator;
use OCA\OpenRegister\Service\Object\ObjectCacheInvalidator;
use OCA\OpenRegister\Service\Object\SavedObjectScope;
use OCA\OpenRegister\Service\Object\SavedScopeIds;

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
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-partial-object-updates-are-protected-against-lost-updates
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declared-initial-lifecycle-state-applied-on-create
 */
class ObjectService implements ObjectOperationsInterface, ObjectServiceInterface, ObjectStreamingSaveInterface, ObjectHandlerAccessInterface
{

    /**
     * Why a transferred object refuses a write.
     */
    private const TRANSFERRED_MESSAGE = 'This object has been transferred to the e-Depot and is read-only.';

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
     * The raw schema identifier the last {@see setSchema()} call was given.
     *
     * Kept so a LATER {@see setRegister()} can re-resolve it inside the register
     * the caller named. Null whenever the current schema came from an entity or a
     * context restore, both of which are already resolved and must not be
     * re-matched.
     *
     * @var int|string|null
     */
    private int | string | null $currentSchemaRef = null;

    /**
     * The shared register-scoped schema resolver.
     *
     * Built here rather than injected: it is a stateless collaborator over the
     * `RegisterMapper` + `SchemaMapper` this class already holds, so constructing
     * it directly keeps every existing unit test — all of which mock those two
     * mappers — exercising the REAL resolution path instead of a mock of the very
     * thing under test.
     *
     * @var RegisterScopedSchemaResolver
     */
    private readonly RegisterScopedSchemaResolver $scopedSchemaResolver;

    /**
     * Clears the caches that outlive a save, constructed on first use.
     *
     * @var ObjectCacheInvalidator|null
     */
    private ?ObjectCacheInvalidator $cacheInvalidator = null;

    /**
     * Clears the collection caches after a bulk write, constructed on first use.
     *
     * @var BulkCacheInvalidator|null
     */
    private ?BulkCacheInvalidator $bulkCacheInvalidator = null;

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

    /**
     * The row-by-row repeating-group validator, constructed on first use.
     *
     * Not injected. This class takes forty-odd constructor arguments and a
     * long tail of unit tests build it positionally, so a new argument is a
     * bigger change than it looks. Both validators below are pure and have no
     * dependencies of their own, which is what makes constructing them here
     * safe: there is no wiring that can be missing, so there is no
     * configuration under which the enforcement quietly stops running.
     *
     * @var RepeatingGroupValidator|null
     */
    private ?RepeatingGroupValidator $repeatingGroupValidator = null;

    /**
     * The recorded-incompleteness handler, constructed on first use.
     *
     * @var NotSuppliedHandler|null
     */
    private ?NotSuppliedHandler $notSuppliedHandler = null;

    /**
     * Constructor for ObjectService.
     *
     * @param DataManipulationHandler        $dataManipHandler     Handler for data manipulation operations.
     * @param DeleteObject                   $deleteHandler        Handler for object deletion.
     * @param GetObject                      $getHandler           Handler for object retrieval.
     * @param PermissionHandlerInterface              $permissionHandler    Handler for permission checks.
     * @param RenderObject                   $renderHandler        Handler for object rendering.
     * @param SaveObjectInterface                     $saveHandler          Handler for individual object saving.
     * @param SaveObjects                    $saveObjectsHandler   Handler for bulk object saving operations.
     * @param SearchQueryHandler             $searchQueryHandler   Handler for search query operations.
     * @param ValidateObjectInterface                 $validateHandler      Handler for object validation.
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
     * @param RegisterMapperInterface                 $registerMapper       Mapper for register operations.
     * @param SchemaMapperInterface                   $schemaMapper         Mapper for schema operations.
     * @param ViewMapperInterface                     $viewMapper           Mapper for view operations.
     * @param MagicMapperInterface                    $objectMapper         Unified mapper for object
     *                                                             operations (routes to
     *                                                             magic tables).
     * @param FileFolderServiceInterface                    $fileService          Service for file operations.
     * @param IUserSession                   $userSession          User session for getting current user.
     * @param SearchTrailService             $searchTrailService   Service for search trail operations.
     * @param IGroupManager                  $groupManager         Group manager for checking user groups.
     * @param IUserManager                   $userManager          User manager for getting user objects.
     * @param OrganisationServiceInterface            $organisationService  Service for organisation operations.
     * @param LoggerInterface                $logger               Logger for performance monitoring.
     * @param CacheHandlerInterface                   $cacheHandler         Service for entity and query caching.
     * @param SettingsServiceInterface                $settingsService      Service for settings operations.
     * @param DateTimeNormalizer             $dateTimeNormalizer   Normaliser for user-supplied datetime input.
     * @param IAppContainer                  $container            Application container.
     * @param ObjectSourceRegistry           $objectSourceRegistry Registry of object-source providers (virtual schemas).
     * @param AutoTransitionPass|null        $autoTransitions      Request-scoped pass applying automatic lifecycle moves.
     * @param TokenGrantSource|null          $tokenGrantSource     The grant the request's token carries; suspended inside runAsAnonymous().
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     */
    public function __construct(
        // Legacy handlers - TESTING:..
        private readonly DataManipulationHandler $dataManipHandler,
        private readonly DeleteObject $deleteHandler,
        private readonly GetObject $getHandler,
        private readonly PermissionHandlerInterface $permissionHandler,
        private readonly RenderObject $renderHandler,
        private readonly SaveObjectInterface $saveHandler,
        private readonly SaveObjects $saveObjectsHandler,
        private readonly SearchQueryHandler $searchQueryHandler,
        private readonly ValidateObjectInterface $validateHandler,
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
        private readonly RegisterMapperInterface $registerMapper,
        private readonly SchemaMapperInterface $schemaMapper,
        private readonly ViewMapperInterface $viewMapper,
        private readonly MagicMapperInterface $objectMapper,
        private readonly FileFolderServiceInterface $fileService,
        private readonly IUserSession $userSession,
        private readonly SearchTrailService $searchTrailService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly OrganisationServiceInterface $organisationService,
        private readonly LoggerInterface $logger,
        private readonly CacheHandlerInterface $cacheHandler,
        private readonly SettingsServiceInterface $settingsService,
        private readonly DateTimeNormalizer $dateTimeNormalizer,
        private readonly IAppContainer $container,
        private readonly ObjectSourceRegistry $objectSourceRegistry,
        // The request-scoped automatic-transition pass. Nullable with a null
        // default so the many unit tests that build this service positionally
        // keep working; the container resolves the real, SHARED instance by
        // type in production, as it does for FlowRunController's attribution.
        private readonly ?AutoTransitionPass $autoTransitions = null,
        // The grant the request's API token carries, if it authenticated with
        // one. Read here for exactly one reason: runAsAnonymous() has to
        // suspend it. Nullable with a null default for the same reason as
        // above — the unit tests build this service positionally — and the
        // container resolves the real, SHARED instance by type in production.
        private readonly ?TokenGrantSource $tokenGrantSource = null
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
        $this->scopedSchemaResolver = new RegisterScopedSchemaResolver(
            registerMapper: $registerMapper,
            schemaMapper: $schemaMapper
        );

        $this->logger->debug(
            message: '[ObjectService] ObjectService constructor completed.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
    }//end __construct()

    /**
     * The id of the register this service is set to, or null when none is set.
     *
     * @return string|null The register id.
     */
    private function currentRegisterId(): ?string
    {
        $id = $this->currentRegister?->getId();
        if ($id === null) {
            return null;
        }

        return (string) $id;
    }//end currentRegisterId()

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
     * 🔴 REACHABILITY BOUNDARY (ADR-099). This is code-initiated only. It MUST
     * NOT be reachable from:
     *
     *  - a flow node,
     *  - a tool invoked by an agent,
     *  - the handling of an inbound request.
     *
     * The reason is not that those callers are untrusted in principle — it is
     * that all three carry user-authored definitions or user-supplied input, and
     * that this method is sitting right next to every place a missing identity
     * makes something fail. An escape hatch that turns a refusal into a success
     * gets taken. Where an identity cannot be resolved the correct outcome is a
     * refusal naming what is missing; escalating to a userless principal instead
     * answers a different question than the one that was asked.
     *
     * The legitimate callers are installation, migration, repair, and seeding of
     * the application's own shipped data — work that genuinely has no user to
     * act for, because a schema migration runs on nobody's behalf.
     *
     * Enforcement is structural first (the service is not injected into node,
     * tool or endpoint classes) and a gate second. `SystemOperationContextBoundaryTest`
     * pins the current call-site set so that adding one is a deliberate act with
     * a visible diff rather than an accident.
     *
     * @param callable $operation The trusted operation to execute.
     *
     * @return mixed Whatever the callable returns.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext::run is the canonical scoped-elevation API
     *
     * @spec openspec/specs/delegated-identity/spec.md
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    public function runAsSystem(callable $operation)
    {
        // NOSEMGREP: php.lang.security.exec-use.exec-use -- SystemOperationContext is a scope flag, no process is started.
        return SystemOperationContext::run($operation);
    }//end runAsSystem()

    /**
     * Run a callable as a NAMED user, with that user's RBAC and multitenancy.
     *
     * The opposite of runAsSystem(): this narrows rather than elevates. It
     * exists because the read side of the query layer has no acting-user
     * parameter at all — `findAll()` and everything under it resolve the
     * subject from the session, and the two handlers that build the RBAC and
     * organisation predicates (MagicRbacHandler, MagicOrganizationHandler) read
     * `IUserSession::getUser()` directly at roughly a dozen points. A caller who
     * has an explicit user in hand therefore has nowhere to put it.
     *
     * Threading a `?IUser` through would mean changing about twenty signatures
     * across six layers plus OrganisationService and its UID-keyed caches, and
     * missing any one of them yields a query that silently disagrees with the
     * others. Setting the session subject for the duration of the call moves
     * every one of those readers in lockstep instead, including the caches,
     * which are keyed by UID and so stay correct by construction.
     *
     * This is the pattern the background jobs already use — see
     * ActorForwardedJob, which sets the actor, runs, and restores in a
     * `finally` so a cron process never carries one job's identity into the
     * next. Restoring the PREVIOUS user rather than clearing means nesting
     * composes correctly.
     *
     * This does not grant anything. If the named user cannot see a row, neither
     * can the callable.
     *
     * 🔴 `setVolatileActiveUser()`, NOT `setUser()`. The two differ in one way
     * that matters here: `setUser()` also writes `user_id` into the PHP session,
     * and `lib/base.php` starts a real session on every request but
     * `status.php`. A `finally` covers a throw, but not a fatal and not an
     * `exit()` — so with `setUser()` a dying request leaves the acting identity
     * written into the caller's session, and the response carries a session
     * cookie for it. `setVolatileActiveUser()` (Nextcloud 29.0+; every fleet app
     * declares `min-version="32"`) sets the active user and nothing else, so
     * there is no persisted state to leak in the first place. The restore below
     * is then a correctness measure for the rest of THIS request rather than the
     * only thing standing between a scope and a hijacked session.
     *
     * This is a workaround for a missing parameter, not the end state. ADR-099
     * keeps threading an acting user through the query layer as the target and
     * records why it is not reachable in one change.
     *
     * @param IUser    $user      The user to act as.
     * @param callable $operation The operation to execute as that user.
     *
     * @return mixed Whatever the callable returns.
     *
     * @spec openspec/specs/delegated-identity/spec.md
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    public function runAs(IUser $user, callable $operation)
    {
        $previousUser = $this->userSession->getUser();
        $this->userSession->setVolatileActiveUser($user);

        try {
            return $operation();
        } finally {
            // ALWAYS restore, including on a throw, so a long-lived process
            // (cron worker, queue runner) never leaks this identity forward.
            // Restore the PREVIOUS user rather than clearing, so that a nested
            // scope returns control to its immediate caller's identity and not
            // to nobody.
            $this->userSession->setVolatileActiveUser($previousUser);
        }
    }//end runAs()


    /**
     * Run a callable AS AN ANONYMOUS CALLER, whatever the session holds.
     *
     * The narrowing counterpart of runAs(): the subject is cleared instead of
     * replaced. Every reader of `IUserSession::getUser()` in the RBAC and
     * organisation layers then sees no user — no admin bypass, no `_owner`
     * grant, no group rules, no `inheritFromPublic` widening — and only the
     * `public` group's rules decide what comes back. The permission caches
     * are keyed by UID and so stay correct by construction, as with runAs().
     *
     * Clearing the subject is not enough on its own. Two guards trust a call
     * WITHOUT a user: the CLI bypass in the RBAC filters and
     * {@see SystemOperationContext}. Under occ or PHPUnit an empty session
     * would therefore be judged as the system, which is the opposite of what
     * is asked. {@see AnonymousEvaluationContext} closes both doors for the
     * duration of the call.
     *
     * A THIRD thing decides access without living on the session: the grant
     * an API token carries ({@see TokenGrantSource}, bound by
     * AuthorizationService before it sets a user). PermissionHandlerInterface consults
     * it ahead of even the admin and owner bypasses, so a request that
     * authenticated with a scoped token would be judged as nobody INTERSECTED
     * WITH THAT TOKEN'S GRANT — narrower than the public answer, and narrower
     * by something the public caller has no way to reproduce. Fail-closed, so
     * never a leak; but this endpoint's contract is that every caller gets the
     * SAME answer, and "same" is broken by narrowing just as surely as by
     * widening. The grant is therefore suspended for the duration too. It is a
     * ceiling on what its holder may do, and inside this scope there is no
     * holder for it to apply to.
     *
     * This exists for public endpoints whose contract is uniform visibility —
     * OpenCatalogi's `/api/search` (SCH-PFTS-001, WOO-536) — where a signed-in
     * administrator must see exactly what an anonymous caller sees. It is a
     * server-side primitive only: nothing in the request can switch it on or
     * off (WOO-578). It restores the previous subject in a `finally`, so
     * nesting composes and a throw never leaks the cleared identity forward.
     *
     * @param callable $operation The operation to execute as an anonymous caller.
     *
     * @return mixed Whatever the callable returns.
     *
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    public function runAsAnonymous(callable $operation)
    {
        // INCOGNITO MODE, NOT setVolatileActiveUser(null).
        //
        // `setVolatileActiveUser(null)` looks like the obvious inverse of what
        // runAs() does, and it is wrong here. In `Session::getUser()`, null is not
        // "there is no user" — it is "not resolved yet":
        //
        //     if (is_null($this->activeUser)) {
        //         $uid = $this->session->get('user_id');   // still the signed-in user
        //         ...
        //         $this->activeUser = $this->manager->get($uid);
        //     }
        //
        // So on a real request the very next getUser() re-reads `user_id` from the
        // PHP session and hands back the same admin — the scope would be a no-op
        // exactly where it is supposed to bite. runAs() escapes this only because
        // it writes a NON-null user.
        //
        // `OC_User::isIncognitoMode()` is checked FIRST in getUser(), before the
        // activeUser fallback, and returns null unconditionally. It is what core
        // itself uses to serve a public link while a session exists — see
        // ShareController, PublicAuth and BearerAuth. The volatile clear stays as
        // well, so the memoised copy does not survive the scope either.
        $previousIncognito = \OC_User::isIncognitoMode();
        $previousUser      = $this->userSession->getUser();

        \OC_User::setIncognitoMode(true);
        $this->userSession->setVolatileActiveUser(null);

        try {
            // The token grant is per-request state on a DI service, not on the
            // session, so neither of the two clears above reaches it. Suspend it
            // around the same callable; TokenGrantSource restores it in its own
            // `finally`, so the two scopes unwind independently and a throw in
            // either one still leaves the request as it found it.
            // `?? null` rather than `=== null`: several unit tests build this
            // service with newInstanceWithoutConstructor(), which leaves every
            // promoted property UNINITIALISED — a parameter default is not a
            // property default. Reading one with `===` raises "must not be
            // accessed before initialization"; `??` and isset() answer without
            // throwing. Verified on PHP 8.3.
            $grantSource = ($this->tokenGrantSource ?? null);
            if ($grantSource === null) {
                return AnonymousEvaluationContext::run($operation);
            }

            return $grantSource->runWithoutGrant(
                static fn () => AnonymousEvaluationContext::run($operation)
            );
        } finally {
            // ALWAYS restore, including on a throw — see runAs(). Restore the
            // PREVIOUS incognito state rather than switching it off, so nesting
            // inside a genuinely incognito request composes.
            $this->userSession->setVolatileActiveUser($previousUser);
            \OC_User::setIncognitoMode($previousIncognito);
        }
    }//end runAsAnonymous()

    /**
     * Run a callable acting for one organisation, as a caller who is not a Nextcloud user.
     *
     * For code that serves callers outside Nextcloud's user base, such as a
     * portal account whose organisation the app verified from a signed session
     * or claim. Inside the callable:
     *
     * - the organisation is the caller's organisation for every tenancy
     *   decision: the organisation filter (with its parent chain and shared
     *   master data), the `$organisation` token in read rules, and the
     *   organisation stamped on new objects;
     * - the callable runs as {@see runAsAnonymous()}: the session user, an API
     *   token's grant and the system scope do not apply, so with RBAC on only
     *   `public` rules decide;
     * - the organisation filter cannot be switched off: `_multitenancy: false`,
     *   the admin bypass and rules marked `crossOrganisation` do not widen it.
     *
     * Only PHP code can open the context; nothing in a request can. It is
     * restored when the callable ends, also on a throw, and nested contexts
     * apply the innermost organisation.
     *
     * @param string   $organisationUuid The organisation to act for.
     * @param callable $operation        The operation to run.
     *
     * @return mixed Whatever the callable returns.
     *
     * @throws InvalidArgumentException When the organisation does not exist.
     *
     * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
     */
    public function runForOrganisation(string $organisationUuid, callable $operation)
    {
        if ($this->organisationService->findContextOrganisation(uuid: $organisationUuid) === null) {
            throw new InvalidArgumentException(
                message: 'Cannot act for organisation "'.$organisationUuid.'": it does not exist.'
            );
        }

        return OrganisationContext::run(
            $organisationUuid,
            fn () => $this->runAsAnonymous(operation: $operation)
        );
    }//end runForOrganisation()

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

        // ORDER-INDEPENDENCE. `setSchema()` can only scope when a register is
        // already set, so the chain `setSchema($s)->setRegister($r)` resolved the
        // schema against the WHOLE INSTANCE and the register never functioned as a
        // boundary — while reading exactly like the correct order. That inversion
        // is not rare: it is the shape of the copy-pasted `validateObject()` helper
        // in ~24 link controllers and of every resolution in FilesController, i.e.
        // most of the endpoints that take a `{register}/{schema}` path.
        //
        // Re-resolving the pending ref here makes the boundary hold whichever way
        // round the two setters are called, in ONE place, instead of requiring
        // every call site to remember an ordering nothing enforces.
        $this->consumePendingSchemaRef(register: $register);

        return $this;
    }//end setRegister()

    /**
     * Re-resolve a pending schema ref inside the register that was just set.
     *
     * @param Register $register The register now in context.
     *
     * @return void
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function consumePendingSchemaRef(Register $register): void
    {
        if ($this->currentSchemaRef === null) {
            return;
        }

        // SINGLE USE. The pending ref belongs to the setSchema() call that
        // created it, and is consumed by the FIRST setRegister() that
        // follows. Clearing it here is what keeps this instance-level state
        // from leaking across unrelated operations: ObjectService is reused
        // for many calls in one process, so a ref left behind by an
        // operation that set a schema and never set a register would be
        // re-resolved against the NEXT caller's register and refuse it.
        // Measured on the shared instance after this scoping landed: a
        // pipelinq repair step seeding `trustConfiguration` was told
        // `posJournalEntryOutbound` is not carried by its register — a slug
        // from an entirely different operation.
        $pendingRef             = $this->currentSchemaRef;
        $this->currentSchemaRef = null;

        // A REF THAT DOES NOT BELONG HERE MUST NOT TAKE THIS CALLER DOWN.
        //
        // Single use bounded the damage to ONE victim. It did not stop the
        // victim being an app that never touched the ref: the pending slug
        // is handed to the first setRegister() that follows, whoever that
        // is, and re-resolving it inside a register that has never heard of
        // it THREW — out of a method whose caller only asked to name its own
        // register.
        //
        // Measured on the development instance 2026-08-27: every public
        // read of a portaliq portal failed with `Schema slug "application"
        // is not carried by register "portaliq"`. The portal served its
        // shell and answered 404 for its own site, menus, pages and
        // glossary — a whole app's public surface down, over a slug it does
        // not own and never asked for. The same failure is recorded above
        // for openconnector and for a pipelinq repair step, so this is the
        // third app to be taken out by a fourth app's leftovers.
        //
        // So a ref that cannot be resolved inside THIS register is dropped
        // rather than thrown on. What is NOT dropped is the consequence: the
        // schema context is cleared with it, so a caller that really did
        // chain `setSchema('typo')->setRegister($r)` still fails — at its
        // operation, with "no schema context", instead of silently reading
        // whichever table a stale context last pointed at. Substituting a
        // wrong-but-plausible schema is the one outcome worse than throwing.
        try {
            $this->currentSchema = $this->scopedSchemaResolver->resolveSchemaWithin(register: $register, schemaRef: $pendingRef);
        } catch (\Throwable $e) {
            $this->currentSchema = null;

            // `$pendingRef` is `int|string` here — `$currentSchemaRef` is
            // `int|string|null` and the null is already excluded above — so
            // the scalar test could only ever be true and the gettype()
            // fallback was unreachable. A defaulted read that can never
            // default reads as a handled edge case that does not exist.
            $this->logDiscardedSchemaRef(pendingRef: (string) $pendingRef, reason: $e);
        }
    }//end consumePendingSchemaRef()

    /**
     * Log a pending schema ref that the current register does not carry.
     *
     * @param string     $pendingRef The discarded ref.
     * @param \Throwable $reason     Why it did not resolve.
     *
     * @return void
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function logDiscardedSchemaRef(string $pendingRef, \Throwable $reason): void
    {
        $this->logger->warning(
            message: '[ObjectService] Discarded a pending schema ref that this register does not carry',
            context: [
                'pendingSchemaRef' => $pendingRef,
                'register'         => ($this->currentRegister?->getSlug() ?? (string) $this->currentRegister?->getId()),
                'reason'           => $reason->getMessage(),
                'note'             => 'The ref was left on this shared service by an earlier, unrelated call. '
                    .'If YOUR call chained setSchema()->setRegister(), the schema is now unset and your '
                    .'operation will fail with a missing schema context rather than read the wrong table.',
            ]
        );
    }//end logDiscardedSchemaRef()


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
        // Remember the raw ref so a LATER setRegister() can re-resolve it inside
        // the register the caller names — see setRegister(). An entity argument is
        // already resolved and clears the pending ref.
        $this->currentSchemaRef = null;

        if (is_string($schema) === true || is_int($schema) === true) {
            $this->currentSchemaRef = $schema;
            $schema = $this->resolveSchemaRef(schemaRef: $schema);
        }

        $this->currentSchema = $schema;
        return $this;
    }//end setSchema()

    /**
     * Resolve a schema identifier inside the current register, or globally without one.
     *
     * @param string|int $schemaRef The schema's id, uuid or slug.
     *
     * @return Schema The resolved schema.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the schema does not exist.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function resolveSchemaRef(string|int $schemaRef): Schema
    {
        // REGISTER-SCOPED SLUG RESOLUTION.
        // SchemaMapper::find() resolves a slug by LOWER(slug) GLOBALLY across
        // every register and every app on the instance, returning whichever row
        // its tie-break orders first. Naming a register makes it a BOUNDARY: a
        // caller that supplied one must never be served a schema from outside
        // it, so a scoped miss throws here instead of falling through.
        //
        // This used to fall back, and the comment called that "transparent". It
        // was not. Measured 2026-08-16: register `document` (id 6) carried an
        // empty schemas list while nine docudesk-owned schemas shared the slug
        // `anonymizationLink`; the fallback resolved to id 5084, which has no
        // table under register 6 at all, so slug reads returned EMPTY while four
        // real rows sat in oc_openregister_table_6_9177. An empty result set is
        // indistinguishable from "this register has no objects", which is how
        // the defect survived unnoticed.
        //
        // The boundary now holds for EVERY identifier form, via the shared
        // RegisterScopedSchemaResolver. It used to apply to SLUGS only, letting
        // a numeric id or a uuid resolve globally — the same silent cross-app
        // read wearing a different identifier. Measured 2026-08-21 on the
        // aggregation endpoints: `TimeEntry` resolved to planix's schema 161
        // instead of hrmq's 9466. Callers with no register still resolve
        // globally, below.
        if ($this->currentRegister === null) {
            return $this->findSchemaGlobally(schemaRef: $schemaRef);
        }

        $schema = $this->scopedSchemaResolver->resolveSchemaWithin(
            register: $this->currentRegister,
            schemaRef: $schemaRef
        );

        // THE PENDING REF HAS ALREADY DONE ITS JOB — DROP IT HERE.
        //
        // It exists for exactly one case: `setSchema($s)` called before any
        // register is known, so that the LATER `setRegister($r)` can
        // re-resolve $s inside $r. On this branch a register was already
        // set, the resolution above was scoped by it, and there is nothing
        // left for a later setRegister() to consume.
        //
        // Leaving it set is what makes this instance-level state leak
        // across unrelated operations. ObjectService is shared for the
        // whole request, so the ref outlives the chain that created it and
        // the NEXT caller's setRegister() re-resolves a finished
        // operation's slug inside a register that has never heard of it —
        // and refuses that caller.
        //
        // Measured 2026-08-27 on a fresh instance: buildiq registers its
        // navigation entries from Application::boot(), which runs on EVERY
        // request and calls findAll() -> prepareFindAllConfig(), which calls
        // setRegister() and then setSchema('application'). Every request
        // therefore ended with `application` pending. Portaliq's
        // PortalResolver — typically the first caller afterwards to name its
        // own register — was told `Schema slug "application" is not carried
        // by register "portaliq"`. It fails closed by design, so every
        // public portal page 404'd with no error attributable to portaliq
        // at all. The same leak reached buildiq (`automation`) and hermiq
        // (`job_log`) on the cron path.
        //
        // #2803 cleared the ref on the save path. This is the read path,
        // which it did not cover.
        $this->currentSchemaRef = null;

        return $schema;
    }//end resolveSchemaRef()

    /**
     * Resolve a schema identifier across the whole instance.
     *
     * @param string|int $schemaRef The schema's id, uuid or slug.
     *
     * @return Schema The resolved schema.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the schema does not exist.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function findSchemaGlobally(string|int $schemaRef): Schema
    {
        // Resolve the identifier through the mapper. SchemaMapper::find()
        // already provides request-scoped caching (findCache) and supports
        // numeric IDs, UUIDs, and slugs via orX(id, uuid, slug).
        try {
            return $this->schemaMapper->find(
                id: $schemaRef,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            $this->logSchemaNotFound(schemaRef: $schemaRef, reason: $e);

            // Rethrow the DoesNotExistException so NC's framework dispatcher
            // converts it to a 404 response. Wrapping in ValidationException
            // causes a 500 instead because ValidationException is generic.
            throw $e;
        }//end try
    }//end findSchemaGlobally()

    /**
     * Log a schema identifier that resolves to nothing.
     *
     * @param string|int $schemaRef The schema's id, uuid or slug.
     * @param \Throwable $reason    The lookup failure.
     *
     * @return void
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function logSchemaNotFound(string|int $schemaRef, \Throwable $reason): void
    {
        $this->logger->error(
            message: '[ObjectService] Schema not found during setSchema()',
            context: [
                'file'              => __FILE__,
                'line'              => __LINE__,
                'schema_identifier' => $schemaRef,
                'error'             => $reason->getMessage(),
                'trace'             => $reason->getTraceAsString(),
            ]
        );
    }//end logSchemaNotFound()

    /**
     * Drop any schema ref still pending from an earlier, unrelated call.
     *
     * WHY EVERY OPERATION MUST DO THIS FIRST.
     *
     * `setSchema()` leaves a raw ref so a LATER `setRegister()` can re-resolve
     * it inside the register the caller names. That makes the two setters
     * order-independent, which is the point — but the ref is instance state on
     * a service reused for many calls in one process, so it outlives the chain
     * that created it.
     *
     * Making it SINGLE-USE (#2792) bounded the damage to one victim instead of
     * many; it did not remove the victim. The ref is still handed to the FIRST
     * `setRegister()` that follows, whoever that turns out to be. Measured on
     * development 2026-08-22: a leaked `synchronization_contract` ref was
     * re-resolved inside a flow's own target register — a register that had
     * never heard of it — and failed `object-write` mid-run.
     *
     * A pending ref belongs to the fluent chain that created it. An operation
     * that supplies its own scope through its arguments is by definition a new
     * chain, so it starts by discarding whatever was left behind. Callers that
     * really do chain (`setSchema($s)->setRegister($r)`) are unaffected: no
     * operation runs between the two setters.
     *
     * @return void
     *
     * @spec exclude Context hygiene shared by every entry point; no business rule of its own.
     */
    private function discardPendingSchemaRef(): void
    {
        $this->currentSchemaRef = null;
    }//end discardPendingSchemaRef()

    /**
     * Put back the scope an entry point found, after it resolved its own.
     *
     * THE CONTRACT THIS ESTABLISHES.
     *
     * `setRegister()` / `setSchema()` are the CALLER'S way of anchoring this
     * shared service, and they are meant to persist: the fluent pattern
     * `setRegister($r)->setSchema($s)` followed by `findAll()` is how a dozen
     * controllers scope a read, and nothing here changes that.
     *
     * An entry point that takes `register:` / `schema:` ARGUMENTS is a different
     * thing. It is scoping ITSELF, for the duration of one operation, and the
     * scope belongs to that operation — not to whoever calls next. Leaving it
     * behind turns a write into an invisible `setRegister()`/`setSchema()` on a
     * service instance that is reused for the whole request (and, under `occ`,
     * for a whole `upgrade` run).
     *
     * WHY THIS IS NOT HYPOTHETICAL. `find()` has restored its context in a
     * `finally` since BUG-OBJ-13 (openregister#1520). `saveObject()` never did,
     * and openregister#3408 is what that costs: `ImportCredentialBrokerRegister`
     * saved two example objects through
     * `saveObject(register: credential-broker, schema: brokeredcredential)`, and
     * four repair steps later `MigrateRegisterFlowsToTable` — whose own read was
     * unscoped — inherited that pair and copied two `brokeredcredential`
     * examples into `openregister_flows` as flows. Deterministically, on every
     * install and every `occ upgrade`, with no error anywhere.
     *
     * That defect was fixed at its call site. This is the same fix at the source,
     * so the next unscoped reader after a write inherits NOTHING rather than
     * inheriting the write's scope.
     *
     * WHERE THIS IS CALLED FROM. Every entry point that resolves a scope from
     * its own arguments, in a `finally` so a throw restores too: `saveObject()`,
     * `saveObjects()`, `saveObjectsStreaming()`, `patchObject()`,
     * `deleteObject()` and `findSilent()`. `find()` predates this helper and
     * keeps its own inline restore, documented at BUG-OBJ-13.
     *
     * @param Register|null $register The register context to put back.
     * @param Schema|null   $schema   The schema context to put back.
     *
     * @return void
     *
     * @spec exclude Context hygiene shared by every scoping entry point; no business rule of its own.
     */
    private function restoreScopeContext(?Register $register, ?Schema $schema): void
    {
        $this->currentRegister = $register;
        $this->currentSchema   = $schema;
        // The pending ref is CLEARED rather than restored, exactly as find()
        // does: the restored schema is an already-resolved ENTITY, so a
        // surviving raw ref would only give the next setRegister() something
        // unrelated to re-resolve.
        $this->currentSchemaRef = null;
    }//end restoreScopeContext()

    /**
     * Run an operation and put the caller's register and schema back afterwards.
     *
     * @param callable $operation The operation to run.
     *
     * @return mixed Whatever the operation returns.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function preservingScope(callable $operation): mixed
    {
        $previousRegister = $this->currentRegister;
        $previousSchema   = $this->currentSchema;

        try {
            return $operation();
        } finally {
            $this->restoreScopeContext(register: $previousRegister, schema: $previousSchema);
        }
    }//end preservingScope()

    /**
     * Set whichever half of the register and schema context the caller named.
     *
     * @param Register|string|int|null $register The register, if named.
     * @param Schema|string|int|null   $schema   The schema, if named.
     *
     * @return void
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function applyScope(Register | string | int | null $register, Schema | string | int | null $schema): void
    {
        if ($register !== null) {
            $this->setRegister(register: $register);
        }

        if ($schema !== null) {
            $this->setSchema(schema: $schema);
        }
    }//end applyScope()

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
     * @param bool                     $_audit        Whether this read is worth an audit-trail row (default: true).
     *                                                Pass false for machine-to-machine reads inside one
     *                                                operation; the instance setting is all-or-nothing.
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
        bool $_render=true,
        bool $_audit=true
    ): ?ObjectEntity {
        $request = new ObjectFindRequest(id: $id, extend: $_extend, files: $files, rbac: $_rbac, multitenancy: $_multitenancy, audit: $_audit);
        $target  = new ObjectFindTarget(register: $register, schema: $schema);
        if ($_render === false) {
            return $this->findUnrendered(request: $request, target: $target);
        }

        return $this->findRendered(request: $request, target: $target);
    }//end find()

    /**
     * Find one object, check the caller may read it, and render it.
     *
     * BUG-OBJ-13 (openregister#1520): a read MUST NOT leave the shared
     * `currentRegister` / `currentSchema` state mutated for the next caller, so
     * any re-anchoring done for this call stays local to it. A caller that omits
     * register/schema gets MagicMapper's cross-table search, never a previous
     * caller's leftover schema.
     *
     * @param ObjectFindRequest $request What to find and how.
     * @param ObjectFindTarget  $target  The register and schema to find it in.
     *
     * @return ObjectEntity|null The rendered object.
     *
     * @spec exclude Private step of find(); read/RBAC/render behavior owned by object-interactions / rbac-scopes.
     */
    private function findRendered(ObjectFindRequest $request, ObjectFindTarget $target): ?ObjectEntity
    {
        return $this->preservingScope(
            operation: function () use ($request, $target): ObjectEntity {
                $object = $this->loadForFind(request: $request, target: $target);
                $this->assertFindAllowed(object: $object, request: $request);

                return $this->renderFoundObject(object: $object, request: $request);
            }
        );
    }//end findRendered()

    /**
     * Find one object and check the caller may read it, leaving the render to the caller.
     *
     * The caller is the render site itself (ObjectsController::show()): the read
     * path still runs, but the expensive render pass runs once, in the caller.
     * Scope is kept local as in findRendered().
     *
     * @param ObjectFindRequest $request What to find and how.
     * @param ObjectFindTarget  $target  The register and schema to find it in.
     *
     * @return ObjectEntity|null The raw entity.
     *
     * @spec exclude Private step of find(); read/RBAC behavior owned by object-interactions / rbac-scopes.
     */
    private function findUnrendered(ObjectFindRequest $request, ObjectFindTarget $target): ?ObjectEntity
    {
        return $this->preservingScope(
            operation: function () use ($request, $target): ObjectEntity {
                $object = $this->loadForFind(request: $request, target: $target);
                $this->assertFindAllowed(object: $object, request: $request);
                // AVG / GDPR per-access read logging still applies to the read.
                $this->logProcessingRead(object: $object);

                return $object;
            }
        );
    }//end findUnrendered()

    /**
     * Loads the object for find() and anchors the context to it.
     *
     * The pending schema ref is the other half of find()'s isolation: setSchema()
     * remembers its raw ref so a later setRegister() can re-resolve it, and the next
     * thing this method does is call setRegister(). A ref left behind by an unrelated
     * earlier caller would be re-resolved inside THIS call's register and fail on a
     * schema this call never mentioned. find() supplies its own scope through its
     * arguments, so it never inherits a pending one.
     *
     * @param ObjectFindRequest $request What the find() call asked for.
     * @param ObjectFindTarget  $target  The register and schema the call names.
     *
     * @return ObjectEntity The object as read.
     *
     * @spec exclude Private step of find(); read behaviour owned by object-interactions.
     */
    private function loadForFind(ObjectFindRequest $request, ObjectFindTarget $target): ObjectEntity
    {
        $this->discardPendingSchemaRef();

        $scope = $this->cachedFindScope(
            id: $request->id,
            scope: $this->callFindScope(target: $target)
        );

        [$object, $scope] = $this->retrieveForFind(request: $request, scope: $scope);

        $this->anchorToFoundObject(object: $object, scope: $scope);
        $this->rememberUuidScope(id: $request->id);

        return $object;
    }//end loadForFind()

    /**
     * Checks that the user may read this specific object (includes the object owner check).
     *
     * Publication visibility is handled by RBAC conditional rules with the $now variable.
     *
     * @param ObjectEntity      $object  The object as read.
     * @param ObjectFindRequest $request What the find() call asked for.
     *
     * @return void
     *
     * @spec exclude Private step of find(); the read rule itself lives in checkPermission().
     */
    private function assertFindAllowed(ObjectEntity $object, ObjectFindRequest $request): void
    {
        $this->permissionHandler->checkPermission(
            schema: $this->currentSchema,
            action: 'read',
            userId: null,
            objectOwner: $object->getOwner(),
            _rbac: $request->rbac,
            object: $object
        );
    }//end assertFindAllowed()

    /**
     * The register and schema a find() call names, resolved through the setters.
     *
     * @param ObjectFindTarget $target The register and schema the call names.
     *
     * @return ObjectFindScope The call's scope; an omitted argument stays null.
     *
     * @spec exclude Part of the find() facade; read behaviour owned by object-interactions.
     */
    private function callFindScope(ObjectFindTarget $target): ObjectFindScope
    {
        $callRegister = null;
        $callSchema   = null;
        if ($target->register !== null) {
            $this->setRegister(register: $target->register);
            $callRegister = $this->currentRegister;
        }

        if ($target->schema !== null) {
            $this->setSchema(schema: $target->schema);
            $callSchema = $this->currentSchema;
        }

        return new ObjectFindScope(register: $callRegister, schema: $callSchema);
    }//end callFindScope()

    /**
     * The scope a uuid already resolved to earlier in this request, or the call's own scope.
     *
     * Targeting the uuid's true context directly avoids re-missing the scoped lookup (and
     * re-running the cross-table fallback) for every repeated relation resolution of the same
     * uuid under a stale URL scope.
     *
     * @param int|string      $id    The object ID or UUID.
     * @param ObjectFindScope $scope The call's own scope.
     *
     * @return ObjectFindScope The scope to retrieve in.
     *
     * @spec exclude Part of the find() facade; read behaviour owned by object-interactions.
     */
    private function cachedFindScope(int | string $id, ObjectFindScope $scope): ObjectFindScope
    {
        if (is_string($id) === false || isset($this->uuidScopeCache[$id]) === false) {
            return $scope;
        }

        $cachedScope  = $this->uuidScopeCache[$id];
        $callRegister = $scope->register;
        if ($cachedScope['register'] !== null) {
            $this->setRegister(register: $cachedScope['register']);
            $callRegister = $this->currentRegister;
        }

        $this->setSchema(schema: $cachedScope['schema']);

        return new ObjectFindScope(register: $callRegister, schema: $this->currentSchema);
    }//end cachedFindScope()

    /**
     * Retrieve the object, retrying without the schema when a uuid misses inside a stale or sibling schema.
     *
     * When the retry is taken, the returned scope is empty so the caller re-anchors to the
     * resolved object's own register and schema.
     *
     * @param ObjectFindRequest $request The find() call.
     * @param ObjectFindScope   $scope   The scope to retrieve in.
     *
     * @return array{0: ObjectEntity, 1: ObjectFindScope} The object and the scope it was found in.
     *
     * @throws OcpDoesNotExistException When the object is not found and no retry applies, or the retry misses too.
     *
     * @spec exclude Part of the find() facade; read behaviour owned by object-interactions.
     */
    private function retrieveForFind(ObjectFindRequest $request, ObjectFindScope $scope): array
    {
        try {
            return [$this->getForFind(request: $request, scope: $scope), $scope];
        } catch (OcpDoesNotExistException $e) {
            // A cached scope that no longer resolves (object deleted or moved
            // mid-request) must not pin future lookups to it.
            if (is_string($request->id) === true) {
                unset($this->uuidScopeCache[$request->id]);
            }

            if ($this->mayRetryWithoutSchema(request: $request, scope: $scope) === false) {
                throw $e;
            }

            return $this->retryWithoutSchema(request: $request, scope: $scope);
        }//end try
    }//end retrieveForFind()

    /**
     * Repeats the lookup without the schema, keeping the caller's register.
     *
     * Dropping the register too turns every legitimate miss into an instance-wide union
     * across all magic tables (measured 2026-07-29: ~1.9s of a ~3.0s create).
     *
     * @param ObjectFindRequest $request What the find() call asked for.
     * @param ObjectFindScope   $scope   The scope that missed.
     *
     * @return array{0: ObjectEntity, 1: ObjectFindScope} The object and an empty scope to anchor from.
     *
     * @throws OcpDoesNotExistException When the object does not exist in the register either.
     *
     * @spec exclude Private retry step of find(); read behaviour owned by object-interactions.
     */
    private function retryWithoutSchema(ObjectFindRequest $request, ObjectFindScope $scope): array
    {
        $object = $this->getForFind(
            request: $request,
            scope: new ObjectFindScope(register: $scope->register, schema: null)
        );

        return [$object, new ObjectFindScope(register: null, schema: null)];
    }//end retryWithoutSchema()

    /**
     * Whether a scoped miss may be retried without the schema.
     *
     * Relations reference their target by a globally-unique UUID while the URL or context
     * schema can be stale or a sibling, so only a scoped lookup for a UUID (collision-free,
     * unlike a slug or numeric id) is retried.
     *
     * @param ObjectFindRequest $request The find() call.
     * @param ObjectFindScope   $scope   The scope that missed.
     *
     * @return bool True when the retry applies.
     *
     * @spec exclude Part of the find() facade; read behaviour owned by object-interactions.
     */
    private function mayRetryWithoutSchema(ObjectFindRequest $request, ObjectFindScope $scope): bool
    {
        return (($scope->schema !== null || $scope->register !== null)
            && is_string($request->id) === true
            && $this->isUuidFormat(value: $request->id) === true);
    }//end mayRetryWithoutSchema()

    /**
     * One get-handler lookup for a find() call; both scope parts null means MagicMapper's cross-table search.
     *
     * @param ObjectFindRequest $request The find() call.
     * @param ObjectFindScope   $scope   The scope to look in.
     *
     * @return ObjectEntity The object.
     *
     * @throws OcpDoesNotExistException When the object is not found in that scope.
     *
     * @spec exclude Part of the find() facade; read behaviour owned by object-interactions.
     */
    private function getForFind(ObjectFindRequest $request, ObjectFindScope $scope): ObjectEntity
    {
        return $this->getHandler->find(
            id: $request->id,
            register: $scope->register,
            schema: $scope->schema,
            _extend: $request->extend,
            files: $request->files,
            _rbac: $request->rbac,
            _multitenancy: $request->multitenancy,
            _audit: $request->audit
        );
    }//end getForFind()

    /**
     * Point the current schema / register at the found object's own context where the scope left it open.
     *
     * The ids come off the OBJECT ROW, which is ground truth: the row physically lives in
     * `oc_openregister_table_<register>_<schema>`. They are therefore NOT re-scoped against
     * `register->getSchemas()` the way a caller-supplied ref is, because a stale linkage list
     * would make an object that demonstrably exists unreadable. Hence the direct assignment
     * instead of setSchema()/setRegister(). find() undoes this in its `finally`.
     *
     * @param ObjectEntity    $object The found object.
     * @param ObjectFindScope $scope  The scope it was found in.
     *
     * @return void
     *
     * @spec exclude Part of the find() facade; read behaviour owned by object-interactions.
     */
    private function anchorToFoundObject(ObjectEntity $object, ObjectFindScope $scope): void
    {
        if ($scope->schema === null) {
            $this->currentSchema    = $this->schemaMapper->find(
                id: $object->getSchema(),
                _rbac: false,
                _multitenancy: false
            );
            $this->currentSchemaRef = null;
        }

        $registerRef = $object->getRegister();
        if ($scope->register === null && $registerRef !== null && $registerRef !== '') {
            $this->currentRegister = $this->registerMapper->find(
                id: $registerRef,
                _rbac: false,
                _multitenancy: false
            );
        }
    }//end anchorToFoundObject()

    /**
     * Remember a uuid's resolved register and schema for the rest of the request.
     *
     * Only uuids are cached: they are globally unique, unlike slugs or numeric per-table ids.
     *
     * @param int|string $id The object ID or UUID.
     *
     * @return void
     *
     * @spec exclude Part of the find() facade; read behaviour owned by object-interactions.
     */
    private function rememberUuidScope(int | string $id): void
    {
        if (is_string($id) === true
            && $this->isUuidFormat(value: $id) === true
            && $this->currentSchema !== null
        ) {
            $this->uuidScopeCache[$id] = [
                'register' => $this->currentRegister,
                'schema'   => $this->currentSchema,
            ];
        }
    }//end rememberUuidScope()

    /**
     * Log the processing read and render the found object in its current register and schema.
     *
     * @param ObjectEntity      $object  The found object.
     * @param ObjectFindRequest $request The find() call.
     *
     * @return ObjectEntity The rendered object.
     *
     * @throws RuntimeException When no schema is set.
     *
     * @spec exclude Part of the find() facade; render behaviour owned by files-render-extension.
     */
    private function renderFoundObject(ObjectEntity $object, ObjectFindRequest $request): ObjectEntity
    {
        $registers = null;
        if ($this->currentRegister !== null) {
            $registers = [$this->currentRegister->getId() => $this->currentRegister];
        }

        if ($this->currentSchema === null) {
            throw new RuntimeException('Schema must be set before rendering entity.');
        }

        $schemas = [$this->currentSchema->getId() => $this->currentSchema];

        // AVG / GDPR per-access read logging (verwerkingenlogging). Fail-soft and
        // gated on the schema's `x-openregister-processing` opt-in.
        $this->logProcessingRead(object: $object);

        return $this->renderHandler->renderEntity(
            entity: $object,
            _extend: $request->extend,
            registers: $registers,
            schemas: $schemas,
            _rbac: $request->rbac,
            _multitenancy: $request->multitenancy
        );
    }//end renderFoundObject()

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
     * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
     */
    private function logProcessingRead(ObjectEntity $object): void
    {
        // Through the one read registration (read-history-on-audit-trail),
        // which delegates to ProcessingLogService unchanged: same opt-in,
        // same storage, same retention, same readers.
        try {
            $this->container->get(\OCA\OpenRegister\Service\Interaction\ReadHistoryService::class)
                ->registerProcessingRead(object: $object);
        } catch (\Throwable $e) {
            // Fail-soft: read logging never breaks or slows the read path.
            $this->logger->debug(
                message: '[AVG] processing-log read hook skipped',
                context: ['exception' => $e->getMessage()]
            );
        }

    }//end logProcessingRead()

    /**
     * AVG / GDPR read logging for a list or search result.
     *
     * @param mixed                $objects The listed objects; anything but an array is not a list and is skipped.
     * @param array<string, mixed> $query   The query parameters of the list or search.
     *
     * @return void
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
     */
    private function logProcessingReadList(mixed $objects, array $query): void
    {
        if (is_array($objects) === false) {
            return;
        }

        try {
            $this->container->get(\OCA\OpenRegister\Service\Interaction\ReadHistoryService::class)
                ->registerListRead(objects: $objects, read: new \OCA\OpenRegister\Service\Interaction\ProcessingListRead(query: $query));
        } catch (\Throwable $e) {
            // Fail-soft: read logging never breaks the list.
            $this->logger->debug(
                message: '[AVG] processing-log list read hook skipped',
                context: ['exception' => $e->getMessage()]
            );
        }
    }//end logProcessingReadList()

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
        $request = new ObjectFindRequest(id: $id, extend: $_extend, files: $files, rbac: $_rbac, multitenancy: $_multitenancy, audit: false);
        return $this->findWithoutAudit(request: $request, target: new ObjectFindTarget(register: $register, schema: $schema));
    }//end findSilent()

    /**
     * Find one object without writing an audit row.
     *
     * Same contract as find(), which has restored its context since
     * BUG-OBJ-13. findSilent() differs from find() only in skipping the
     * audit row; there is no reason for it to differ in what it leaves
     * behind. See restoreScopeContext().
     *
     * @param ObjectFindRequest $request What to find and how; its id is a string.
     * @param ObjectFindTarget  $target  The register and schema to find it in.
     *
     * @return ObjectEntity The object.
     *
     * @spec exclude Private step of findSilent(); read behavior owned by the GetObject handler.
     */
    private function findWithoutAudit(ObjectFindRequest $request, ObjectFindTarget $target): ObjectEntity
    {
        return $this->preservingScope(
            operation: function () use ($request, $target): ObjectEntity {
                $this->discardPendingSchemaRef();
                $this->applyScope(register: $target->register, schema: $target->schema);

                return $this->getHandler->findSilent(
                    id: (string) $request->id,
                    register: $this->currentRegister,
                    schema: $this->currentSchema,
                    _extend: $request->extend,
                    files: $request->files,
                    _rbac: $request->rbac,
                    _multitenancy: $request->multitenancy
                );
            }
        );
    }//end findWithoutAudit()

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
        return $this->findAllRendered(config: $config, access: new AccessFlags(rbac: $_rbac, multitenancy: $_multitenancy));
    }//end findAll()

    /**
     * Load the rows of one findAll() call and render them.
     *
     * @param array       $config The findAll() configuration (filters, limit, offset, extend, ...).
     * @param AccessFlags $access The caller's RBAC and multitenancy flags.
     *
     * @return array The rendered objects.
     *
     * @spec exclude Private step of findAll(); list/render behaviour owned by the render handler.
     */
    private function findAllRendered(array $config, AccessFlags $access): array
    {
        $this->discardPendingSchemaRef();
        // Prepare configuration and set context.
        $config = $this->prepareFindAllConfig(config: $config);

        // Render via renderEntities, which batch-preloads ALL related objects in
        // one query before the per-row render (optimize-object-render-hot-path).
        // A previous code path pre-resolved registers/schemas and looped
        // renderEntity() per row, causing an N+1 on any `?_extend=` list.
        // renderEntities() performs the identical per-row renderEntity() rendering,
        // only with the relation/file caches pre-warmed, so output is unchanged;
        // registers/schemas were a pre-resolution optimization renderEntity()
        // reproduces internally, not a correctness input.
        return $this->renderHandler->renderEntities(
            entities: $this->loadFindAllRows(config: $config, access: $access),
            _extend: ($config['extend'] ?? []),
            _filter: ($config['unset'] ?? null),
            _fields: ($config['fields'] ?? null),
            _rbac: $access->rbac,
            _multitenancy: $access->multitenancy
        );
    }//end findAllRendered()

    /**
     * Load the rows of a findAll() call through the handler.
     *
     * The resolved register and schema go along so MagicMapper::findAll does not
     * bail out with "called without register/schema context".
     *
     * @param array       $config The prepared findAll() configuration.
     * @param AccessFlags $access Whether RBAC and multitenancy apply.
     *
     * @return array The object entities.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function loadFindAllRows(array $config, AccessFlags $access): array
    {
        return $this->getHandler->findAll(
            ...$this->findAllSelection(config: $config),
            register: $this->currentRegister,
            schema: $this->currentSchema,
            _rbac: $access->rbac,
            _multitenancy: $access->multitenancy
        );
    }//end loadFindAllRows()

    /**
     * The selection arguments of a findAll() configuration, by handler parameter name.
     *
     * @param array $config The prepared findAll() configuration.
     *
     * @return array<string, mixed> The handler arguments.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function findAllSelection(array $config): array
    {
        return [
            'limit'   => ($config['limit'] ?? null),
            'offset'  => ($config['offset'] ?? null),
            'filters' => ($config['filters'] ?? []),
            'sort'    => ($config['sort'] ?? []),
            'search'  => ($config['search'] ?? null),
            'files'   => ($config['files'] ?? false),
            'uses'    => ($config['uses'] ?? null),
            'ids'     => ($config['ids'] ?? null),
        ];
    }//end findAllSelection()

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

        $this->applyScope(
            register: $this->scalarFilter(config: $config, key: 'register'),
            schema: $this->scalarFilter(config: $config, key: 'schema')
        );

        return $config;
    }//end prepareFindAllConfig()

    /**
     * Read a filter that names one register or schema, not a list and not empty.
     *
     * @param array  $config The findAll() configuration.
     * @param string $key    The filter name.
     *
     * @return mixed The filter value, or null when it names nothing.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function scalarFilter(array $config, string $key): mixed
    {
        $value = ($config['filters'][$key] ?? null);
        if (is_array($value) === true || empty($value) === true) {
            return null;
        }

        return $value;
    }//end scalarFilter()

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
        $this->discardPendingSchemaRef();
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
        return $this->getHandler->findLogs(object: $this->objectMapper->find($uuid), filters: $filters);
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
     * @throws Exception If there is an error during save
     * @throws \OCA\OpenRegister\Exception\ObjectExistsException When $failIfExists is true and the identifier is already taken.
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
     * @param bool                     $_validation   Whether to validate the object against its schema (default: true).
     *                                                Was hardcoded true here, so a bulk importer with pre-validated
     *                                                rows had no way to decline re-validating every one of them.
     * @param array|null               $uploadedFiles Uploaded files from multipart/form-data (optional)
     * @param IUser|null               $currentUser   Explicit acting user for `@self.folder` access checks
     * @param bool                     $failIfExists  Insert-only: throw ObjectExistsException rather than update when taken (default: false = upsert)
     * @param bool                     $_unowned      Stamp the SYSTEM identity as owner even when a user session
     *                                                exists. For a genuinely anonymous write — a citizen submitting
     *                                                a form on a public portal with no account — where the row must
     *                                                not be attributed to whoever happens to be signed into the
     *                                                instance in that browser. Defaults to false, so nothing changes
     *                                                for any existing caller, and no controller forwards it, so no
     *                                                REST request can ask to be anonymised.
     *                                                (forwarded to `ensureObjectFolder` → `assertObjectFolderAccessible`).
     *                                                Defaults to null → `IUserSession::getUser()` resolution.
     *                                                Non-HTTP callers (cron, import pipelines, event listeners)
     *                                                MUST pass an explicit user to avoid the
     *                                                default-deny fall-through on every folder-bound save.
     * @param bool                     $_dedupOverride Save through a blocking duplicate match, when the caller is
     *                                                entitled to. Read from the RAW request by ObjectsController,
     *                                                because the body filter there strips `_`-prefixed keys.
     * @param string|null              $_owner        An explicit owner for a NEW object (a draft's scope value such as
     *                                                `portal:<subjectRef>`, decision 195). Service-level only: no
     *                                                controller forwards it. An update keeps the stored owner.
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
     * @spec openspec/specs/object-lifecycle/spec.md
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
        bool $_validation=true,
        ?array $uploadedFiles=null,
        ?IUser $currentUser=null,
        bool $failIfExists=false,
        bool $_unowned=false,
        bool $_dedupOverride=false,
        ?string $_owner=null
    ): ObjectEntity {
        return $this->saveAtTransitionBoundary(
            object: $object,
            options: [
                'extend'         => $extend,
                'register'       => $register,
                'schema'         => $schema,
                'uuid'           => $uuid,
                '_rbac'          => $_rbac,
                '_multitenancy'  => $_multitenancy,
                'silent'         => $silent,
                '_validation'    => $_validation,
                'uploadedFiles'  => $uploadedFiles,
                'currentUser'    => $currentUser,
                'failIfExists'   => $failIfExists,
                '_unowned'       => $_unowned,
                '_dedupOverride' => $_dedupOverride,
                '_owner'         => $_owner,
            ]
        );
    }//end saveObject()

    /**
     * Runs one save inside the automatic-transition boundary, with its scope kept local.
     *
     * A SAVE SCOPES ITSELF; IT DOES NOT SCOPE THE NEXT CALLER. See restoreScopeContext()
     * for the contract and for openregister#3408, the defect this leak produced. A caller
     * that anchored the service with setRegister()/setSchema() and then saves WITHOUT
     * naming a scope is unaffected: the snapshot and the restore are the same context.
     *
     * Leaving the OUTERMOST boundary is the first moment at which this write is finished
     * in every sense that matters: the object row, its audit row, and the second update a
     * file-bearing save makes from an in-memory entity. A move applied any earlier is lost
     * to that second write. See AutoTransitionPass.
     *
     * @param array|ObjectEntity $object  The object to save.
     * @param array              $options The arguments of the saveObject() call.
     *
     * @phpstan-param array<array-key, mixed>|ObjectEntity $object
     * @phpstan-param array<string, mixed>                 $options
     * @psalm-param   array<array-key, mixed>|ObjectEntity $object
     * @psalm-param   array<string, mixed>                 $options
     *
     * @return ObjectEntity The rendered object, or the automatic move's result when one applied.
     *
     * @spec exclude Private step of saveObject(); write behaviour owned by the save handler.
     */
    private function saveAtTransitionBoundary(array | ObjectEntity $object, array $options): ObjectEntity
    {
        $this->autoTransitions?->enter();

        try {
            $result = $this->preservingScope(
                operation: fn (): ObjectEntity => $this->saveWithinScope(object: $object, options: $options)
            );
        } finally {
            // Always in a `finally`, never conditionally: an unbalanced enter
            // would leave the pass believing a boundary is still open, and
            // nothing in this request would ever drain again.
            $automatic = ($this->autoTransitions?->leave() ?? []);
        }

        if ($this->autoTransitions === null) {
            return $result;
        }

        return $this->autoTransitions->preferAutomatic(saved: $result, applied: $automatic);
    }//end saveAtTransitionBoundary()

    /**
     * Run one save inside the scope saveObject() opened: check, prepare, persist, invalidate, render.
     *
     * @param array|ObjectEntity $object  The object data or entity to save.
     * @param array              $options The saveObject() arguments other than the object, by name.
     *
     * @return ObjectEntity The saved and rendered object
     *
     * @throws Exception If there is an error during save
     *
     * @SuppressWarnings(PHPMD.StaticAccess) WritePhaseProbe marks are static timing probes
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function saveWithinScope(array | ObjectEntity $object, array $options): ObjectEntity
    {
        $run = $this->openSaveRun(object: $object, options: $options);
        $this->authoriseSaveTarget(run: $run);
        $this->cascadeAndPrepareSave(run: $run);

        return $this->renderSave(run: $run, saved: $this->persistSave(run: $run));
    }//end saveWithinScope()

    /**
     * Sets the context of one save and reads its identifier.
     *
     * The folder-access revalidation cache is bound to this single save call (not the
     * whole FileService/request lifetime), so a cascade save that moves or trashes a
     * folder mid-request can't be waved through on a stale "accessible" verdict from an
     * earlier write.
     *
     * @param array|ObjectEntity $object  The object to save.
     * @param array              $options The arguments of the saveObject() call.
     *
     * @phpstan-param array<array-key, mixed>|ObjectEntity $object
     * @phpstan-param array<string, mixed>                 $options
     * @psalm-param   array<array-key, mixed>|ObjectEntity $object
     * @psalm-param   array<string, mixed>                 $options
     *
     * @return ObjectSaveRun The save, with its normalised object and identifier.
     *
     * @spec exclude Private step of saveObject(); write behaviour owned by the save handler.
     */
    private function openSaveRun(array | ObjectEntity $object, array $options): ObjectSaveRun
    {
        $this->discardPendingSchemaRef();
        $this->fileService->resetFolderAccessRevalidationCache();

        \OCA\OpenRegister\Service\WritePhaseProbe::mark('start');

        $this->setContextFromParameters(register: $options['register'], schema: $options['schema']);
        [$object, $uuid] = $this->extractUuidAndNormalizeObject(object: $object, uuid: $options['uuid']);

        \OCA\OpenRegister\Service\WritePhaseProbe::mark('pc:context+uuid');

        return new ObjectSaveRun(object: $object, uuid: $uuid, options: $options);
    }//end openSaveRun()

    /**
     * Checks the caller's rights on the save target and settles what the write may do.
     *
     * The write continues with the row the permission check ran on. The save looks its
     * identifier up again without the caller's scope, where the same identifier can name
     * another row (an id that is another row's uuid, a slug that is another row's id).
     *
     * @param ObjectSaveRun $run The save.
     *
     * @return void
     *
     * @spec exclude Private step of saveObject(); the rules live in checkSavePermissions() and guardWriteTarget().
     */
    private function authoriseSaveTarget(ObjectSaveRun $run): void
    {
        $this->bindSaveToCheckedRow(run: $run);

        \OCA\OpenRegister\Service\WritePhaseProbe::mark('pc:permissions.check');

        $run->options['failIfExists'] = $this->guardWriteTarget(
            uuid: $run->uuid,
            failIfExists: $run->options['failIfExists']
        );

        // A null identifier here is what tells a create from a user-provided UUID.
        $run->isCreate = ($run->uuid === null);

        \OCA\OpenRegister\Service\WritePhaseProbe::mark('pc:permissions');
    }//end authoriseSaveTarget()

    /**
     * Runs the permission check and points the save at the row it ran on.
     *
     * @param ObjectSaveRun $run The save.
     *
     * @return void
     *
     * @spec exclude Private step of saveObject(); the rules live in checkSavePermissions().
     */
    private function bindSaveToCheckedRow(ObjectSaveRun $run): void
    {
        $checked = $this->checkSavePermissions(
            uuid: $run->uuid,
            _rbac: $run->options['_rbac'],
            object: $run->object,
            _multitenancy: $run->options['_multitenancy']
        );
        if ($checked !== null) {
            $run->uuid = $checked;
        } else if ($run->uuid !== null && $run->options['_rbac'] === true && $this->currentSchema !== null) {
            // Nothing the caller may see answers to this identifier: the write may only insert.
            $run->options['failIfExists'] = true;
        }
    }//end bindSaveToCheckedRow()

    /**
     * Runs the cascade, prepares the object and clears what the top-level save must not inherit.
     *
     * @param ObjectSaveRun $run The save.
     *
     * @return void
     *
     * @spec exclude Private step of saveObject(); write behaviour owned by the save handler.
     */
    private function cascadeAndPrepareSave(ObjectSaveRun $run): void
    {
        $this->cascadeSave(run: $run);

        $run->object = $this->prepareObjectForSave(
            object: $run->object,
            write: ['uuid' => $run->uuid, 'isCreate' => $run->isCreate]
        );

        $this->settleSaveFolder(run: $run);
    }//end cascadeAndPrepareSave()

    /**
     * Checks the object's folder and clears what the top-level save must not inherit.
     *
     * Request-scoped caches are cleared before a new top-level save operation, so cascade
     * operations benefit from caching while stale data is avoided.
     *
     * @param ObjectSaveRun $run The save.
     *
     * @return void
     *
     * @spec exclude Private step of saveObject(); the folder rule lives in ensureObjectFolder().
     */
    private function settleSaveFolder(ObjectSaveRun $run): void
    {
        \OCA\OpenRegister\Service\WritePhaseProbe::mark('folder.readonly');
        $this->ensureObjectFolder(
            uuid: $run->uuid,
            currentUser: $run->options['currentUser']
        );
        \OCA\OpenRegister\Service\WritePhaseProbe::mark('folder.ensure');

        $this->saveHandler->clearAllCaches();
        \OCA\OpenRegister\Service\WritePhaseProbe::mark('folder');
    }//end settleSaveFolder()

    /**
     * Saves the nested objects of this save.
     *
     * Nested objects the cascade creates are checked for create rights unless this save
     * runs with RBAC off.
     *
     * @param ObjectSaveRun $run The save.
     *
     * @return void
     *
     * @spec exclude Private step of saveObject(); the create rule lives in the save handler.
     */
    private function cascadeSave(ObjectSaveRun $run): void
    {
        $this->saveHandler->enterCascadeScope(_rbac: $run->options['_rbac']);
        try {
            [$run->object, $run->uuid] = $this->handleCascadingWithContextPreservation(
                object: $run->object,
                uuid: $run->uuid
            );
        } finally {
            $this->saveHandler->leaveCascadeScope();
        }
    }//end cascadeSave()

    /**
     * Writes the object and drops the caches it touches.
     *
     * Lazy folder creation: no file-storage folder is created here. The folder is created
     * on demand on the first upload, which keeps empty folders out of the Files tree and
     * system-created objects out of folders a later editor cannot access.
     *
     * @param ObjectSaveRun $run The save.
     *
     * @return ObjectEntity The saved object.
     *
     * @spec exclude Private step of saveObject(); write behaviour owned by the save handler.
     */
    private function persistSave(ObjectSaveRun $run): ObjectEntity
    {
        $savedObject = $this->persistObject(object: $run->object, options: ['uuid' => $run->uuid] + $run->options);
        $this->cacheInvalidator()->afterSave(
            saved: new SavedObjectScope(
                object: $savedObject,
                registerId: $this->currentRegister?->getId(),
                schemaId: $this->currentSchema?->getId()
            )
        );

        \OCA\OpenRegister\Service\WritePhaseProbe::mark('persist+events');

        return $savedObject;
    }//end persistSave()

    /**
     * Renders the saved object for the caller.
     *
     * @param ObjectSaveRun $run   The save.
     * @param ObjectEntity  $saved The saved object.
     *
     * @return ObjectEntity The rendered object.
     *
     * @spec exclude Private step of saveObject(); render behaviour owned by RenderObject.
     */
    private function renderSave(ObjectSaveRun $run, ObjectEntity $saved): ObjectEntity
    {
        $renderedObject = $this->renderHandler->renderEntity(
            entity: $saved,
            _extend: $run->options['extend'] ?? [],
            registers: null,
            schemas: null,
            _rbac: $run->options['_rbac'],
            _multitenancy: $run->options['_multitenancy']
        );
        \OCA\OpenRegister\Service\WritePhaseProbe::mark('render');
        \OCA\OpenRegister\Service\WritePhaseProbe::flush();

        return $renderedObject;
    }//end renderSave()

    /**
     * Refuse a write the target does not take, and say whether the write must insert only.
     *
     * A type backed by a saved view lists the view's rows and takes no write
     * (405, like append-only). A transferred object (archiefstatus overgebracht)
     * is read-only. On an append-only schema an existing object refuses the
     * update; a uuid that does not exist yet is an insert with a caller-chosen
     * id (xAPI stores a statement under its own id), made insert-only all the
     * way down so a concurrent insert of the same uuid loses at the `_uuid`
     * unique constraint with a 409 instead of being applied as an update.
     *
     * @param string|null $uuid         The uuid being written, or null for a create.
     * @param bool        $failIfExists Whether the caller asked for an insert-only write.
     *
     * @return bool Whether the write must insert only.
     *
     * @throws Exception When the target refuses the write.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function guardWriteTarget(?string $uuid, bool $failIfExists): bool
    {
        $viewWrite = 'update';
        if ($uuid === null) {
            $viewWrite = 'create';
        }

        $this->rejectIfViewBacked(operation: $viewWrite);
        if ($uuid === null) {
            return $failIfExists;
        }

        $this->rejectIfTransferred(uuid: $uuid);
        if ($this->currentSchema === null || $this->currentSchema->isAppendOnly() === false) {
            return $failIfExists;
        }

        if ($this->appendOnlyTargetExists(uuid: $uuid) === true) {
            throw new AppendOnlyException(
                schemaIdentifier: ($this->currentSchema->getSlug() ?? (string) $this->currentSchema->getId()),
                operation: 'update'
            );
        }

        return true;
    }//end guardWriteTarget()

    /**
     * Apply defaults and run every check that comes before the write.
     *
     * Defaults come before validation, so a computed or derived property can
     * override an invalid incoming value, and a property that is both required
     * and derived can be created. Expression defaults and the lifecycle seed
     * apply on CREATE only. Dates are normalised (a datetime for a date field is
     * cast to the date) so validation passes. An update that sends back the mask
     * of an explicit-reveal value keeps the stored value. The declared bounds
     * hold on every write, before the validator, so the caller reads that
     * sentence rather than a JSON-pointer complaint. After validation, readOnly
     * holds on UPDATE and a lens takes no value on any write.
     *
     * @param array $object The object data.
     * @param array $write  The uuid and whether the write is a create.
     *
     * @return array The object data ready to save.
     *
     * @throws ValidationException When the schema is not resolved or a check fails.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function prepareObjectForSave(array $object, array $write): array
    {
        // A schema that could not be resolved from the request is a structured
        // 400 here, not a raw TypeError 500 in the steps below.
        if ($this->currentSchema === null) {
            throw new ValidationException(
                message: 'Schema could not be resolved for this object; provide a valid register/schema.'
            );
        }

        $object = $this->applySaveDefaults(object: $object, isCreate: $write['isCreate']);

        \OCA\OpenRegister\Service\WritePhaseProbe::mark('prepare+cascade');
        if ($write['isCreate'] === false) {
            $object = $this->restoreMaskedReveals(object: $object, uuid: $write['uuid']);
        }

        $this->assertSaveIsValid(object: $object, write: $write);
        return $object;
    }//end prepareObjectForSave()

    /**
     * Fills the defaults of the current schema and normalises date values.
     *
     * @param array $object   The object data.
     * @param bool  $isCreate Whether this save creates the object.
     *
     * @phpstan-param array<array-key, mixed> $object
     * @psalm-param   array<array-key, mixed> $object
     *
     * @return array The object data with its defaults.
     *
     * @phpstan-return array<array-key, mixed>
     * @psalm-return   array<array-key, mixed>
     *
     * @spec exclude Private step of saveObject(); defaults owned by the save handler.
     */
    private function applySaveDefaults(array $object, bool $isCreate): array
    {
        $object = $this->saveHandler->applyAlwaysDefaults(schema: $this->currentSchema, data: $object);
        if ($isCreate === true) {
            $object = $this->saveHandler->applyExpressionDefaults(schema: $this->currentSchema, data: $object);
        }

        $object = $this->normalizeDateValues(object: $object);
        if ($isCreate === true) {
            $object = $this->saveHandler->seedLifecycleFieldOnCreate(schema: $this->currentSchema, data: $object);
        }

        return $object;
    }//end applySaveDefaults()

    /**
     * Refuses data the schema does not allow on this write.
     *
     * @param array $object The object data.
     * @param array $write  The `uuid` and `isCreate` of this write.
     *
     * @phpstan-param array<array-key, mixed>                $object
     * @phpstan-param array{uuid: ?string, isCreate: bool}   $write
     * @psalm-param   array<array-key, mixed>                $object
     * @psalm-param   array{uuid: ?string, isCreate: bool}   $write
     *
     * @return void
     *
     * @spec exclude Private step of saveObject(); the rules live in the enforce and validate units it calls.
     */
    private function assertSaveIsValid(array $object, array $write): void
    {
        $this->enforceDeclaredShapes(object: $object);

        $updateUuid = $write['uuid'];
        if ($write['isCreate'] === true) {
            $updateUuid = null;
        }

        $this->validateObjectIfRequired(object: $object, uuid: $updateUuid);
        \OCA\OpenRegister\Service\WritePhaseProbe::mark('validate');

        $this->enforceReadOnlyOnUpdate(object: $object, uuid: $write['uuid']);
        $this->enforceLensesAreReadOnly(object: $object);
    }//end assertSaveIsValid()

    /**
     * Hand the prepared object to the SaveObject handler.
     *
     * @param array $object  The prepared object data.
     * @param array $options The save options with the uuid and the folder id.
     *
     * @return ObjectEntity The saved object.
     *
     * @throws Exception If the handler cannot save the object.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function persistObject(array $object, array $options): ObjectEntity
    {
        return $this->saveHandler->saveObject(
            register: $this->currentRegister,
            schema: $this->currentSchema,
            data: $object,
            uuid: $options['uuid'],
            _rbac: $options['_rbac'],
            _multitenancy: $options['_multitenancy'],
            persist: true,
            silent: $options['silent'],
            _validation: $options['_validation'],
            uploadedFiles: $options['uploadedFiles'],
            currentUser: $options['currentUser'],
            failIfExists: $options['failIfExists'],
            _unowned: $options['_unowned'],
            _dedupOverride: $options['_dedupOverride'],
            _owner: $options['_owner']
        );
    }//end persistObject()

    /**
     * The cache invalidator of this service.
     *
     * @return ObjectCacheInvalidator
     */
    private function cacheInvalidator(): ObjectCacheInvalidator
    {
        $this->cacheInvalidator ??= new ObjectCacheInvalidator(
            container: $this->container,
            logger: $this->logger
        );

        return $this->cacheInvalidator;
    }//end cacheInvalidator()

    /**
     * The bulk cache invalidator of this service.
     *
     * @return BulkCacheInvalidator
     */
    private function bulkCacheInvalidator(): BulkCacheInvalidator
    {
        $this->bulkCacheInvalidator ??= new BulkCacheInvalidator(
            cacheHandler: $this->cacheHandler,
            logger: $this->logger
        );

        return $this->bulkCacheInvalidator;
    }//end bulkCacheInvalidator()

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
        // A CALLER THAT NAMES BOTH CANNOT INHERIT A PENDING REF FROM ANYWHERE.
        //
        // `setRegister()` re-resolves `$currentSchemaRef` so the chain
        // `setSchema($s)->setRegister($r)` still scopes. That ref is instance
        // state, and ObjectService is reused for many operations in one process,
        // so an operation that set a schema and never set a register leaves one
        // behind. The NEXT caller's `setRegister()` then consumes it — and is
        // refused with a slug it never asked for.
        //
        // #2792 made the ref single-use, which stops it being consumed twice. It
        // does not stop it being consumed ONCE by the wrong operation, and that
        // is the failure still live on `development`:
        //
        //   POST /apps/docudesk/api/templates
        //   Failed to create template: Schema slug "application" is not carried
        //   by register "docudesk" (id 19) …
        //
        // Docudesk asked for register 19 / schema 18 (`template`). `application`
        // is openbuild's schema, from an unrelated earlier call on the same
        // instance. Measured on buildiq E2E 2026-08-22 12:26 UTC, which is after
        // #2792 landed at 11:09.
        //
        // When BOTH are supplied there is nothing to inherit: the caller has
        // named the pair outright, so any pending ref is by definition stale.
        // Dropping it here leaves the setSchema()/setRegister() order-independence
        // intact for callers that genuinely use that chain, and keeps the register
        // boundary exactly as strict — the schema below is still resolved inside
        // the register named here.
        if ($register !== null && $schema !== null) {
            $this->clearPendingSchemaRef();
        }

        $this->applyScope(register: $register, schema: $schema);
    }//end setContextFromParameters()

    /**
     * Drop a pending schema ref left behind by an earlier operation.
     *
     * Named rather than inlined so the one place that owns this state is
     * greppable: `$currentSchemaRef` is written by setSchema(), consumed by
     * setRegister(), and discarded here.
     *
     * @return void
     */
    private function clearPendingSchemaRef(): void
    {
        $this->currentSchemaRef = null;

    }//end clearPendingSchemaRef()

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
     * @param string|null $uuid          Object UUID (null for CREATE, set for UPDATE)
     * @param bool        $_rbac         Whether to apply RBAC checks
     * @param array       $object        The incoming object data, which a create rule's match reads
     * @param bool        $_multitenancy Whether the write keeps to the caller's organisations
     *
     * @return string|null The uuid of the stored row the update check ran on, or null for a create
     *                     check or when there is no schema to check against.
     *
     * @throws Exception If permission check fails
     */
    private function checkSavePermissions(?string $uuid, bool $_rbac, array $object=[], bool $_multitenancy=true): ?string
    {
        if ($this->currentSchema === null) {
            return null;
        }

        // A create rule may carry a `match` on the object being created, so the
        // create question is asked about the incoming data (openregister#4094).
        return $this->authoriseSave(
            request: new SaveAccessRequest(
                uuid: $uuid,
                rbac: $_rbac,
                incoming: $this->buildIncomingObjectForCreateCheck(object: $object),
                multitenancy: $_multitenancy
            )
        );
    }//end checkSavePermissions()

    /**
     * Ask the permission a save needs: create without a uuid, otherwise by what the uuid resolves to.
     *
     * @param SaveAccessRequest $request What the save asks for.
     *
     * @return string|null The uuid of the stored row the write lands on, null for a create.
     *
     * @throws Exception If permission check fails
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function authoriseSave(SaveAccessRequest $request): ?string
    {
        // No UUID provided, this is a CREATE operation.
        if ($request->uuid === null) {
            $this->assertMayCreate(request: $request);
            return null;
        }

        // UUID provided - check if object exists to determine CREATE vs UPDATE.
        //
        // Scope the lookup to the register and schema being written to. Left
        // unscoped, this resolved the UUID by UNION-ing every magic table on the
        // instance. It was also the wrong question: the permission being checked
        // is "may I write THIS object in THIS schema", so an object of the same
        // UUID living in another register/schema must not supply the owner the
        // check is made against. Not finding it here means the same thing it has
        // always meant — treat the write as a create with a caller-chosen UUID.
        try {
            return $this->assertMayUpdateStored(request: $request);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return $this->assertMayWriteUnseen(request: $request);
        }
    }//end authoriseSave()

    /**
     * Ask the create permission, about the incoming data.
     *
     * @param SaveAccessRequest $request What the save asks for.
     *
     * @return void
     *
     * @throws Exception If permission check fails
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function assertMayCreate(SaveAccessRequest $request): void
    {
        $this->permissionHandler->checkPermission(
            schema: $this->currentSchema,
            action: 'create',
            userId: null,
            objectOwner: null,
            _rbac: $request->rbac,
            object: $request->incoming,
            register: $this->currentRegisterId()
        );
    }//end assertMayCreate()

    /**
     * Ask the update permission, about a stored row.
     *
     * @param ObjectEntity $stored The row the write lands on.
     * @param bool         $_rbac  Whether to apply RBAC checks.
     *
     * @return void
     *
     * @throws Exception If permission check fails
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function assertMayUpdate(ObjectEntity $stored, bool $_rbac): void
    {
        $this->permissionHandler->checkPermission(
            schema: $this->currentSchema,
            action: 'update',
            userId: null,
            objectOwner: $stored->getOwner(),
            _rbac: $_rbac,
            object: $stored
        );
    }//end assertMayUpdate()

    /**
     * Find the row a uuid names for this caller and ask the update permission on it.
     *
     * @param SaveAccessRequest $request What the save asks for.
     *
     * @return string|null The stored row's uuid.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the caller does not see such a row.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function assertMayUpdateStored(SaveAccessRequest $request): ?string
    {
        $existingObject = $this->objectMapper->find(
            identifier: $request->uuid,
            register: $this->currentRegister,
            schema: $this->currentSchema
        );

        $this->assertMayUpdate(stored: $existingObject, _rbac: $request->rbac);

        return $existingObject->getUuid();
    }//end assertMayUpdateStored()

    /**
     * Ask the permissions of a write to a uuid the caller does not see: new, or stored and hidden.
     *
     * @param SaveAccessRequest $request What the save asks for.
     *
     * @return string|null The hidden row's uuid, null for a create with a caller-chosen uuid.
     *
     * @throws Exception If permission check fails
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function assertMayWriteUnseen(SaveAccessRequest $request): ?string
    {
        $hidden = $this->refuseSomebodyElsesDraft(uuid: $request->uuid, _rbac: $request->rbac);

        $this->assertMayCreate(request: $request);
        if ($hidden === null) {
            return null;
        }

        // Stored but hidden: the write lands on that row, so it must keep to
        // the caller's organisations and pass the update rule against it.
        $this->refuseAWriteOntoAnAppendOnlyRow();
        $this->refuseAnotherOrganisationsRow(hidden: $hidden, _multitenancy: $request->multitenancy);
        $this->assertMayUpdate(stored: $hidden, _rbac: $request->rbac);

        return $hidden->getUuid();
    }//end assertMayWriteUnseen()

    /**
     * Refuse a write onto a stored row the caller cannot read when the schema is append-only.
     *
     * Every write onto a stored row of an append-only schema gets one answer from
     * guardWriteTarget(), visible or not. Answering a hidden row the same
     * way before the organisation refusal and the update rule are asked keeps
     * them from telling which rows belong to another organisation.
     *
     * @return void
     *
     * @throws AppendOnlyException When the schema is append-only.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function refuseAWriteOntoAnAppendOnlyRow(): void
    {
        if ($this->currentSchema?->isAppendOnly() !== true) {
            return;
        }

        throw new AppendOnlyException(
            schemaIdentifier: ($this->currentSchema->getSlug() ?? (string) $this->currentSchema->getId()),
            operation: 'update'
        );
    }//end refuseAWriteOntoAnAppendOnlyRow()

    /**
     * Refuse a write by uuid onto a stored row outside the caller's organisations.
     *
     * The caller's own lookup did not find the row, and the update rule it is
     * judged by next does not look at organisations. So the row's organisation
     * is compared with the caller's organisation scope directly, without the
     * read rules: a `public` read rule that lifts the organisation filter for
     * reading must not let a write land on another organisation's row. A row
     * the read rules hide inside the caller's organisations is left to the
     * update rule, as before. Skipped when the write does not keep to the
     * caller's organisations (an administrator, a job).
     *
     * @param ObjectEntity $hidden        The stored row the caller cannot read.
     * @param bool         $_multitenancy Whether the write keeps to the caller's organisations.
     *
     * @return void
     *
     * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the row belongs to another organisation.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function refuseAnotherOrganisationsRow(ObjectEntity $hidden, bool $_multitenancy): void
    {
        if ($_multitenancy === false || $this->objectMapper->callerOrganisationsHold(row: $hidden) === true) {
            return;
        }

        throw new NotAuthorizedException(message: 'You may not change this object.');
    }//end refuseAnotherOrganisationsRow()

    /**
     * Refuse a write to a stored draft the caller cannot read.
     *
     * A draft answers to its owner only (decision 180), and that rule lives in
     * the read query. The lookup above therefore does not find somebody else's
     * draft, and the write went on as a create under a chosen uuid, judged by
     * the schema's create rule, and was then stored over the draft. Anybody
     * who may create in the schema could change somebody else's draft through
     * PATCH and got its content back in the answer. Found on a live instance,
     * 12 October 2026.
     *
     * So the uuid is looked up once more, past the read rule, and only for
     * this question. Skipped with RBAC off: an administrator, a job and a
     * scope-value owner (decision 195) are judged by their caller.
     *
     * @param string $uuid  The uuid the caller's own lookup did not find.
     * @param bool   $_rbac Whether the write is judged for the signed-in caller.
     *
     * @return ObjectEntity|null The stored row the caller cannot read, null when there is none or RBAC is off.
     *
     * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the uuid is a draft kept from the caller.
     *
     * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
     */
    private function refuseSomebodyElsesDraft(string $uuid, bool $_rbac): ?ObjectEntity
    {
        if ($_rbac === false || $this->currentSchema === null) {
            return null;
        }

        try {
            // A soft-deleted row counts too: the write would land on it.
            $stored = $this->objectMapper->find(
                identifier: $uuid,
                register: $this->currentRegister,
                schema: $this->currentSchema,
                includeDeleted: true,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }

        $user = $this->userSession->getUser();
        $kept = (new DraftCallerRule())->keptFromCaller(
            object: $stored,
            schema: $this->currentSchema,
            caller: new DraftCaller(
                userId: $user?->getUID(),
                isAdmin: ($user !== null && in_array('admin', $this->groupManager->getUserGroupIds($user), true) === true)
            )
        );
        if ($kept === true) {
            throw new NotAuthorizedException(message: 'Only the person who started this draft can change it.');
        }

        return $stored;
    }//end refuseSomebodyElsesDraft()

    /**
     * Build the transient object a create rule's `match` is evaluated against.
     *
     * The data is the incoming request body without its `@self` block, so a
     * caller cannot supply the metadata a match reads. The organisation is the
     * caller's active organisation, which is the one the save assigns. The owner
     * stays empty: the permission handler grants an owner every action, so a
     * caller-chosen owner would bypass the rule.
     *
     * @param array $object The incoming object data.
     *
     * @return ObjectEntity The transient object, never persisted.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    private function buildIncomingObjectForCreateCheck(array $object): ObjectEntity
    {
        unset($object['@self']);
        // Underscore-prefixed keys name metadata, which the system sets: a body
        // key such as `_owner` must not answer a create rule's match.
        $object = array_filter($object, static fn ($key): bool => str_starts_with((string) $key, '_') === false, ARRAY_FILTER_USE_KEY);

        $incoming = new ObjectEntity();
        $incoming->setObject($object);
        $incoming->setOrganisation($this->permissionHandler->getActiveOrganisationForContext());

        return $incoming;
    }//end buildIncomingObjectForCreateCheck()

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
        // Entities, already resolved — clear the pending ref for the same reason
        // as the restore in find().
        $this->currentRegister  = $parentRegister;
        $this->currentSchema    = $parentSchema;
        $this->currentSchemaRef = null;

        return [$object, $uuid];
    }//end handleCascadingWithContextPreservation()

    /**
     * Validate an object when its schema has hard validation on, or when the write is a draft.
     *
     * A draft (decision 180) is validated whatever the hard-validation flag:
     * it may miss required properties, so `required` is excused, but a wrong
     * type, format or enum value is always refused.
     *
     * @param array       $object Object data to validate
     * @param string|null $uuid   The object's uuid on an update, null on a create
     *
     * @return void
     *
     * @throws ValidationException If validation fails
     *
     * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
     */
    private function validateObjectIfRequired(array $object, ?string $uuid=null): void
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

        $draftPolicy = new DraftStatusPolicy();
        $isDraft     = $draftPolicy->isDraftWrite(object: $object, existing: $this->existingForDraftCheck(object: $object, uuid: $uuid));

        if ($this->currentSchema->getHardValidation() !== true && $isDraft === false) {
            return;
        }

        // A property recorded as not supplied carries no value and is
        // excused from the required rule, so the validator sees neither
        // the record nor the properties it names. The record itself stays
        // on the body that gets stored, which is how it reads back.
        $notSupplied = $this->notSupplied()->declared(object: $object);
        if ($isDraft === true) {
            $notSupplied = array_merge($draftPolicy->requiredExcusals(schema: $this->currentSchema), $notSupplied);
        }

        $result = $this->validateHandler->validateObject(
            object: $this->notSupplied()->stripForValidation(object: $object),
            schema: $this->currentSchema,
            notSupplied: $notSupplied
        );

        if ($result->isValid() === false) {
            $meaningfulMessage = $this->validateHandler->generateErrorMessage(result: $result);
            throw new ValidationException(message: $meaningfulMessage, errors: $result->error());
        }
    }//end validateObjectIfRequired()

    /**
     * The stored object, for deciding whether an update keeps a draft; null when the payload says.
     *
     * Only looked up when the payload names no status and the write is an
     * update, so an ordinary create costs nothing.
     *
     * @param array       $object The payload.
     * @param string|null $uuid   The uuid on an update.
     *
     * @return ObjectEntity|null The stored object, or null.
     */
    private function existingForDraftCheck(array $object, ?string $uuid): ?ObjectEntity
    {
        if ($uuid === null || is_string($object['@self']['status'] ?? null) === true) {
            return null;
        }

        try {
            return $this->objectMapper->find(
                $uuid,
                register: $this->currentRegister,
                schema: $this->currentSchema,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable) {
            return null;
        }
    }//end existingForDraftCheck()

    /**
     * Put stored explicit-reveal values back where an update sends their mask.
     *
     * @param array       $object The update payload.
     * @param string|null $uuid   The object's uuid.
     *
     * @return array The payload.
     *
     * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
     */
    private function restoreMaskedReveals(array $object, ?string $uuid): array
    {
        if ($uuid === null || $this->currentSchema === null || ExplicitReveal::explicitProperties(schema: $this->currentSchema) === []) {
            return $object;
        }

        try {
            $existing = $this->objectMapper->find(
                $uuid,
                register: $this->currentRegister,
                schema: $this->currentSchema,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable) {
            return $object;
        }

        return ExplicitReveal::restoreMasked(write: new MaskedWrite(schema: $this->currentSchema, incoming: $object, stored: ($existing->getObject() ?? [])));
    }//end restoreMaskedReveals()

    /**
     * The repeating-group validator, constructed on first use.
     *
     * @return RepeatingGroupValidator The validator.
     *
     * @spec openspec/specs/runtime-schema-api/spec.md
     */
    private function repeatingGroups(): RepeatingGroupValidator
    {
        if ($this->repeatingGroupValidator === null) {
            $this->repeatingGroupValidator = new RepeatingGroupValidator();
        }

        return $this->repeatingGroupValidator;
    }//end repeatingGroups()

    /**
     * The recorded-incompleteness handler, constructed on first use.
     *
     * @return NotSuppliedHandler The handler.
     *
     * @spec openspec/specs/runtime-schema-api/spec.md
     */
    private function notSupplied(): NotSuppliedHandler
    {
        if ($this->notSuppliedHandler === null) {
            $this->notSuppliedHandler = new NotSuppliedHandler();
        }

        return $this->notSuppliedHandler;
    }//end notSupplied()

    /**
     * Enforce the declared repeating groups and the not-supplied record.
     *
     * Runs on CREATE as well as UPDATE, and whether or not the schema has hard
     * validation switched on. Both are declarations about what the register
     * holds rather than opt-in conveniences: a group with a maximum of three
     * that accepts four rows on the schemas whose administrator left hard
     * validation off is a bound that does not bind.
     *
     * The refusal names the row and the member, which is the whole difference
     * between an authorable repeating group and an array (D-2).
     *
     * @param array $object The candidate object body.
     *
     * @return void
     *
     * @throws ValidationException When a group or the not-supplied record is refused.
     *
     * @spec openspec/specs/runtime-schema-api/spec.md
     */
    private function enforceDeclaredShapes(array $object): void
    {
        if ($this->currentSchema === null) {
            return;
        }

        $messages = [];

        $incompleteness = $this->notSupplied()->validate(object: $object, schema: $this->currentSchema);
        foreach ($incompleteness as $violation) {
            $messages[] = $violation['message'];
        }

        $groups = $this->repeatingGroups()->validate(object: $object, schema: $this->currentSchema);
        foreach ($groups as $violation) {
            $messages[] = $violation['message'];
        }

        // A geometry is checked on every write, like the bounds above: a
        // polygon that does not close cannot be searched or drawn, and the
        // schema's hard-validation switch was never meant to allow that.
        $geometries = (new GeoJsonGeometryValidator())->validateObject(
            object: $object,
            schema: $this->currentSchema
        );
        foreach ($geometries as $property => $errors) {
            $messages[] = sprintf("Property '%s' is not a valid geometry: %s.", $property, implode('; ', $errors));
        }

        if ($messages === []) {
            return;
        }

        $this->logger->info(
            message: '[ObjectService] repeating-group / not-supplied / geometry enforcement rejected the write',
            context: [
                'file'           => __FILE__,
                'line'           => __LINE__,
                'schemaId'       => $this->currentSchema->getId(),
                'groups'         => $groups,
                'incompleteness' => $incompleteness,
                'geometries'     => $geometries,
            ]
        );

        throw new ValidationException(message: implode(' ', $messages));
    }//end enforceDeclaredShapes()

    /**
     * Enforce JSON-Schema `readOnly: true` and `immutable: true` on the
     * UPDATE write path.
     *
     * Both rules need the same previously-stored record, so they share one
     * load here. They are not the same rule: `readOnly` refuses every value
     * that differs from what is stored, `immutable` accepts the first value
     * and refuses every later change.
     *
     * No-op on CREATE (uuid === null). `readOnly` is not enforced inside a
     * SystemOperationContext; `immutable` is. On UPDATE:
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
     *
     * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-system-operation-may-stamp-a-readonly-property
     */
    private function enforceReadOnlyOnUpdate(array $object, ?string $uuid): void
    {
        $existingData = $this->writeRuleBaseline(object: $object, uuid: $uuid);
        if ($existingData === null) {
            return;
        }

        // Strip @self from the incoming payload before comparing — the rules
        // are for business properties only.
        $candidate = array_diff_key($object, ['@self' => true]);

        $readOnly  = $this->readOnlyViolations(candidate: $candidate, existingData: $existingData);
        $immutable = $this->immutableViolations(candidate: $candidate, existingData: $existingData);
        if ($readOnly === [] && $immutable === []) {
            return;
        }

        $this->logWriteRuleRefusal(uuid: $uuid, violations: array_merge($readOnly, $immutable));

        // ValidationException's `$errors` argument is typed `?ValidationError`
        // (Opis), not an arbitrary array — pass null and let the message +
        // log entry carry the violation detail.
        throw new ValidationException(message: $this->describeWriteRuleRefusal(readOnly: $readOnly, immutable: $immutable));
    }//end enforceReadOnlyOnUpdate()

    /**
     * The stored data an update's write rules compare against, null when no rule applies.
     *
     * @param array       $object The incoming object.
     * @param string|null $uuid   The object uuid, null for a create.
     *
     * @return array|null The stored business properties.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function writeRuleBaseline(array $object, ?string $uuid): ?array
    {
        if ($uuid === null || $this->currentSchema === null) {
            return null;
        }

        return $this->storedDataForWriteRules(object: $object, uuid: $uuid);
    }//end writeRuleBaseline()

    /**
     * The readOnly properties an update would change.
     *
     * @param array $candidate    The incoming business properties.
     * @param array $existingData The stored business properties.
     *
     * @return array The violations.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function readOnlyViolations(array $candidate, array $existingData): array
    {
        // `readOnly` means a client may not write the field; a trusted system
        // operation is what stamps it, so it is not refused there. `immutable`
        // below still holds for everyone.
        if (SystemOperationContext::isActive() === true) {
            return [];
        }

        return $this->validateHandler->validateReadOnlyConstraints(
            incomingObject: $candidate,
            existingObject: $existingData,
            schema: $this->currentSchema
        );
    }//end readOnlyViolations()

    /**
     * The immutable properties an update would change.
     *
     * `immutable: true` is checked here rather than in a second private
     * method so it reuses the record this one already loaded. Two methods
     * would mean two `find()` calls on every update, and the second would
     * eventually be the one somebody forgot to call.
     *
     * @param array $candidate    The incoming business properties.
     * @param array $existingData The stored business properties.
     *
     * @return array The violations.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function immutableViolations(array $candidate, array $existingData): array
    {
        return $this->validateHandler->validateImmutableConstraints(
            incomingObject: $candidate,
            existingObject: $existingData,
            schema: $this->currentSchema
        );
    }//end immutableViolations()

    /**
     * Log an update refused by readOnly or immutable rules.
     *
     * @param string $uuid       The object uuid.
     * @param array  $violations The readOnly violations, then the immutable ones.
     *
     * @return void
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function logWriteRuleRefusal(string $uuid, array $violations): void
    {
        $this->logger->info(
            message: '[ObjectService] readOnly / immutable enforcement rejected UPDATE',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'uuid'       => $uuid,
                'schemaId'   => $this->currentSchema->getId(),
                'violations' => $violations,
            ]
        );
    }//end logWriteRuleRefusal()

    /**
     * The message of an update refused by readOnly or immutable rules.
     *
     * @param array $readOnly  The readOnly violations.
     * @param array $immutable The immutable violations.
     *
     * @return string The message.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function describeWriteRuleRefusal(array $readOnly, array $immutable): string
    {
        $parts = [];
        if ($readOnly !== []) {
            $parts[] = $this->describeViolations(violations: $readOnly, verb: 'modify readOnly');
        }

        if ($immutable !== []) {
            $parts[] = $this->describeViolations(violations: $immutable, verb: 'change immutable');
        }

        return implode('. ', $parts);
    }//end describeWriteRuleRefusal()

    /**
     * Load the stored business data the write rules compare against.
     *
     * Returns null when this is not a true UPDATE. Anything that prevents the
     * load (not found, RBAC reject, multitenancy filter) means the engine's
     * normal CREATE path will run, and neither `readOnly` nor `immutable`
     * applies to a record that does not exist yet.
     *
     * Passes the already-resolved register and schema so `find()` takes the
     * scoped register/schema-table path directly. Omitting them leaves it to
     * rely on the request's URL scope; under a stale scope it falls back to the
     * deliberate cross-table search (openregister#1520). We are on the save
     * path with both resolved, so there is no reason to risk that fallback.
     *
     * @param array       $object The incoming payload, read only for its `id` key.
     * @param string      $uuid   The object being updated.
     *
     * @return array|null The stored business data, or null when this is not an update.
     *
     * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
     */
    private function storedDataForWriteRules(array $object, string $uuid): ?array
    {
        try {
            $existing = $this->objectMapper->find(
                $uuid,
                register: $this->currentRegister,
                schema: $this->currentSchema,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            return null;
        }

        $existingData = $existing->getObject();

        // Drop the synthesised `id` key getObject() prepends — the rules apply
        // to schema properties, not to the engine-stamped identifier.
        if (isset($existingData['id']) === true && isset($object['id']) === false) {
            unset($existingData['id']);
        }

        return $existingData;
    }//end storedDataForWriteRules()

    /**
     * Name the properties a write rule refused, in one sentence.
     *
     * Extracted so `enforceReadOnlyOnUpdate()` reads as two rules and a
     * message rather than two rules and two copies of the same pluralisation.
     * The copies were also what pushed that method's NPath complexity past the
     * threshold when the second rule arrived.
     *
     * @param array $violations The violation rows, each carrying a `property`.
     * @param string $verb What the caller tried to do, for the message.
     *
     * @return string The sentence.
     *
     * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
     */
    private function describeViolations(array $violations, string $verb): string
    {
        $properties = array_map(static fn (array $v): string => $v['property'], $violations);

        $suffix = 'ies';
        if (count($violations) === 1) {
            $suffix = 'y';
        }

        return 'Cannot '.$verb.' propert'.$suffix.': '.implode(', ', $properties);
    }//end describeViolations()

    /**
     * Refuse a write that names a lens property.
     *
     * A lens holds the path, not the value: it is resolved on read against the
     * referenced record and stored nowhere. Accepting a value for one would
     * write a second copy of somebody else's field, which is the disagreement
     * the lens exists to end. The refusal names the property, because a client
     * that sent one cannot otherwise tell which of its fields is a lens.
     *
     * @param array $object Incoming object payload (top-level keys = property names).
     *
     * @return void
     *
     * @throws ValidationException When the payload names one or more lens properties.
     *
     * @spec openspec/specs/linked-entity-types/spec.md
     */
    private function enforceLensesAreReadOnly(array $object): void
    {
        if ($this->currentSchema === null) {
            return;
        }

        $named = LensResolver::refusedLensWrites(schema: $this->currentSchema, object: $object);
        if ($named === []) {
            return;
        }

        $suffix = 'ies';
        if (count($named) === 1) {
            $suffix = 'y';
        }

        $message = 'Cannot write lens propert'.$suffix.': '.implode(', ', $named)
            .'. A lens reads a referenced record and is never stored.';

        $this->logger->info(
            message: '[ObjectService] lens write refused',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'schemaId'   => $this->currentSchema->getId(),
                'properties' => $named,
            ]
        );

        throw new ValidationException(message: $message);
    }//end enforceLensesAreReadOnly()

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
     */
    private function normalizeDateValues(array $object): array
    {
        if ($this->currentSchema === null) {
            return $object;
        }

        return (new ObjectDateValues(dates: $this->dateTimeNormalizer))->normalize(
            object: $object,
            properties: ($this->currentSchema->getProperties() ?? [])
        );
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
     * @return void
     */
    private function ensureObjectFolder(?string $uuid, ?IUser $currentUser=null): void
    {
        if ($uuid === null) {
            return;
        }

        $this->revalidateFolderBinding(uuid: $uuid, currentUser: $currentUser);

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
    }//end ensureObjectFolder()

    /**
     * Re-validate the folder binding of a stored object for the acting user.
     *
     * @param string     $uuid        The object uuid.
     * @param IUser|null $currentUser The acting user, null to use the session.
     *
     * @return void
     *
     * @throws \OCA\OpenRegister\Exception\FolderAccessDeniedException When the user cannot access the bound folder.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function revalidateFolderBinding(string $uuid, ?IUser $currentUser): void
    {
        try {
            $this->assertStoredFolderBinding(uuid: $uuid, currentUser: $currentUser);
        } catch (\OCA\OpenRegister\Exception\FolderAccessDeniedException $e) {
            // Propagate folder-access denials up to the controller.
            throw $e;
        } catch (Exception $e) {
            // A missing object or any other error: let SaveObject handle the creation.
            $this->logger->debug(
                message: '[ObjectService] Folder binding not re-validated, object not loadable',
                context: [
                    'uuid' => $uuid,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end revalidateFolderBinding()

    /**
     * Look up a stored object and check its folder binding, when it has one.
     *
     * @param string     $uuid        The object uuid.
     * @param IUser|null $currentUser The acting user, null to use the session.
     *
     * @return void
     *
     * @throws \OCA\OpenRegister\Exception\FolderAccessDeniedException When the user cannot access the bound folder.
     * @throws Exception When the object lookup fails.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function assertStoredFolderBinding(string $uuid, ?IUser $currentUser): void
    {
        // Scoped for the same reason enforceReadOnlyOnUpdate() above is:
        // we are on the save path with both register and schema already
        // resolved, so there is no reason to fall back to the
        // cross-table search (openregister#1520). Unscoped, this was the
        // third full-instance UUID resolution in a single update.
        $existingObject = $this->objectMapper->find(
            $uuid,
            register: $this->currentRegister,
            schema: $this->currentSchema
        );

        if ($this->hasFolderBinding(folder: $existingObject->getFolder()) === true) {
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
        }
    }//end assertStoredFolderBinding()

    /**
     * Whether an object's folder column holds a binding to a folder id.
     *
     * The `_folder` column is `varchar(255)` — every populated
     * value is a string. The earlier `is_string($folder) === true`
     * clause matched ANY non-empty string and so triggered an
     * auto-create on every update, overwriting valid folder
     * bindings with freshly-generated auto-folders under the
     * register's storage tree. The intent of the string branch
     * was to handle LEGACY non-numeric string paths that
     * pre-date the integer-id storage convention; restrict the
     * check to that case.
     *
     * @param mixed $folder The stored folder value.
     *
     * @return bool True for a numeric folder id.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function hasFolderBinding(mixed $folder): bool
    {
        return $folder !== null && (is_string($folder) === false || is_numeric($folder) === true);
    }//end hasFolderBinding()

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
     * @param IUser|null               $currentUser     Explicit acting user for the RBAC `delete`
     *                                                  check. Defaults to null → the permission
     *                                                  subject is resolved from `IUserSession`,
     *                                                  which is today's behaviour for every HTTP
     *                                                  caller. A sessionless caller (flow run, cron,
     *                                                  import pipeline) MUST pass one: anonymous is
     *                                                  default-deny, so without it the delete is
     *                                                  neither attributable nor permitted.
     * @param bool                     $permanent       Destroy the row rather than tombstone it, so
     *                                                  its identifier becomes reusable. Defaults to
     *                                                  false — the soft delete every existing caller
     *                                                  already gets, and the thing that makes a
     *                                                  mistaken delete recoverable. Reserve it for
     *                                                  rows that are a CLAIM rather than a record;
     *                                                  see the parameter's full note on
     *                                                  `DeleteObject::deleteObject()` and
     *                                                  openregister#2459.
     *
     * @return bool Whether the deletion was successful
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If `$register` and `$schema` are both supplied
     *                                                    and the UUID is not present in that scope (even
     *                                                    if it exists in another magic table).
     * @throws \Exception If user does not have delete permission.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
     */
    public function deleteObject(
        string $uuid,
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $_retentionSweep=false,
        ?IUser $currentUser=null,
        bool $permanent=false
    ): bool {
        $request = new ObjectDeleteRequest(
            uuid: $uuid,
            rbac: $_rbac,
            multitenancy: $_multitenancy,
            retentionSweep: $_retentionSweep,
            currentUser: $currentUser,
            scoped: ($register !== null && $schema !== null)
        );
        return $this->preservingScope(operation: function () use ($request, $register, $schema, $permanent): bool {
            $this->enterDeleteScope(register: $register, schema: $schema);
            return $this->deleteRequested(request: $request, permanent: $permanent);
        });
    }//end deleteObject()

    /**
     * Open the scope of one delete: drop a pending schema reference and apply the caller's register and schema.
     *
     * A DELETE SCOPES ITSELF; IT DOES NOT SCOPE THE NEXT CALLER. deleteObject() runs this inside
     * preservingScope(), the same contract as find() and saveObject(); see restoreScopeContext().
     *
     * @param Register|string|int|null $register The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
     *
     * @return void
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function enterDeleteScope(Register | string | int | null $register, Schema | string | int | null $schema): void
    {
        $this->discardPendingSchemaRef();
        $this->applyDeleteScope(register: $register, schema: $schema);
    }//end enterDeleteScope()

    /**
     * Run one delete in the scope deleteObject() opened, under the retention sweep's destruction marker when it calls.
     *
     * @param ObjectDeleteRequest $request   The delete and its policy flags.
     * @param bool                $permanent Whether to remove the row for good.
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws \Exception If the delete is refused or fails.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function deleteRequested(ObjectDeleteRequest $request, bool $permanent): bool
    {
        if ($request->retentionSweep === true) {
            return (new RetentionDestruction())->run(fn (): bool => $this->deleteWithinScope(request: $request, permanent: $permanent));
        }

        return $this->deleteWithinScope(request: $request, permanent: $permanent);
    }//end deleteRequested()

    /**
     * Run one delete inside the scope deleteObject() opened: refuse, dispatch to an object
     * source, or resolve the permitted target and delete it.
     *
     * @param ObjectDeleteRequest $request   The delete and its policy flags.
     * @param bool                $permanent Whether to remove the row for good; a draft always is.
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws \Exception If the delete is refused or fails.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors deleteObject()'s own permanent flag.
     */
    private function deleteWithinScope(ObjectDeleteRequest $request, bool $permanent): bool
    {
        // A schema served by an object source keeps no rows in a magic table,
        // so the MagicMapper lookups below can only miss, and a scoped miss
        // would be rethrown as "not found" before the delete handler's
        // provider dispatch runs.
        $sourcedSchema = null;
        if ($this->currentSchema !== null && $this->currentSchema->getObjectSource() !== null) {
            $sourcedSchema = $this->currentSchema;
        }

        $this->rejectRefusedDelete(request: $request, sourcedSchema: $sourcedSchema);

        if ($sourcedSchema !== null) {
            return $this->deleteFromObjectSource(schema: $sourcedSchema, request: $request);
        }

        $objectToDelete = $this->resolvePermittedDeleteTarget(request: $request);

        // A draft is removed for good, with its files: a tombstone of
        // unsent input serves nobody, and on an archival schema the
        // purge would refuse it for ever (decisions 180 and 216).
        if ($objectToDelete !== null && $objectToDelete->isDraft() === true) {
            $permanent = true;
        }

        $this->refuseDeletingSomebodyElsesDraft(request: $request, object: $objectToDelete);

        return $this->deleteHandler->deleteObject(
            request: new DeletionRequest(
                register: $this->currentRegister,
                schema: $this->currentSchema,
                uuid: $request->uuid,
                options: new DeletionOptions(scoped: $request->scoped, permanent: $permanent)
            ),
            _rbac: $request->rbac,
            _multitenancy: $request->multitenancy
        );
    }//end deleteWithinScope()

    /**
     * Resolve a delete call's register and schema onto the current context.
     *
     * Downstream context (permission checks, audit-trail recording) then sees the
     * API-supplied scope, not a stale leftover from a previous call on this service.
     *
     * @param Register|string|int|null $register The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
     *
     * @return void
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function applyDeleteScope(
        Register | string | int | null $register,
        Schema | string | int | null $schema
    ): void {
        if ($register !== null) {
            $this->setRegister(register: $register);
        }

        if ($schema !== null) {
            $this->setSchema(schema: $schema);
        }

        \OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.scope');
    }//end applyDeleteScope()

    /**
     * Refuse a delete the object or its schema does not allow, before anything is looked up for it.
     *
     * In order: a transferred object (looked up with the caller's flags; an external row has
     * no retention block, so a sourced schema skips it), a view-backed type, an append-only
     * schema, and an archival schema unless the retention sweep calls or the object is a
     * stored draft (a draft is not yet a record, decisions 180 and 216).
     *
     * @param ObjectDeleteRequest $request       The delete call.
     * @param Schema|null         $sourcedSchema The current schema when an object source serves it.
     *
     * @return void
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the object is transferred.
     * @throws AppendOnlyException When the schema is append-only.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function rejectRefusedDelete(ObjectDeleteRequest $request, ?Schema $sourcedSchema): void
    {
        $this->rejectDeleteOfLeftObject(request: $request, sourced: $sourcedSchema !== null);

        $this->rejectIfViewBacked(operation: 'delete');

        if ($this->currentSchema !== null) {
            $this->rejectDeleteOnSchema(request: $request, schema: $this->currentSchema);
        }
    }//end rejectRefusedDelete()

    /**
     * Refuse deleting shared master data, and an object that was transferred away.
     *
     * @param ObjectDeleteRequest $request The delete call.
     * @param bool                $sourced Whether an object source serves the current schema.
     *
     * @return void
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the object is transferred.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag says where the rows live.
     */
    private function rejectDeleteOfLeftObject(ObjectDeleteRequest $request, bool $sourced): void
    {
        if ($request->scoped === true || $sourced === true) {
            $this->rejectSharedDelete(request: $request);
        }

        if ($sourced === false) {
            $this->rejectIfTransferred(uuid: $request->uuid, _rbac: $request->rbac, _multitenancy: $request->multitenancy);
        }

        \OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.transferred');
    }//end rejectDeleteOfLeftObject()

    /**
     * Refuse deleting shared master data when the delete answers to the caller's organisation.
     *
     * @param ObjectDeleteRequest $request The delete call.
     *
     * @return void
     */
    private function rejectSharedDelete(ObjectDeleteRequest $request): void
    {
        if ($this->deleteAnswersToOrganisation(rbac: $request->rbac, multitenancy: $request->multitenancy) === true) {
            $this->rejectSharedMasterDataDelete(registerId: $this->currentRegister?->getId(), schemaId: $this->currentSchema?->getId());
        }
    }//end rejectSharedDelete()

    /**
     * Refuse a delete its schema does not allow: append-only, or archival unless the object is a deletable draft.
     *
     * @param ObjectDeleteRequest $request The delete call.
     * @param Schema              $schema  The current schema.
     *
     * @return void
     *
     * @throws AppendOnlyException When the schema is append-only.
     */
    private function rejectDeleteOnSchema(ObjectDeleteRequest $request, Schema $schema): void
    {
        if ($schema->isAppendOnly() === true) {
            throw $this->appendOnlyDeleteRefusal(schema: $schema);
        }

        $draft = $this->isDeletableDraft(
            uuid: $request->uuid,
            retentionSweep: $request->retentionSweep,
            _rbac: $request->rbac,
            _multitenancy: $request->multitenancy
        );
        if ($draft === false) {
            $this->rejectIfArchivalImmutable(schema: $schema, retentionSweep: $request->retentionSweep);
        }
    }//end rejectDeleteOnSchema()

    /**
     * The refusal of a delete on an append-only schema.
     *
     * @param Schema $schema The append-only schema.
     *
     * @return AppendOnlyException
     */
    private function appendOnlyDeleteRefusal(Schema $schema): AppendOnlyException
    {
        return new AppendOnlyException(
            schemaIdentifier: ($schema->getSlug() ?? (string) $schema->getId()),
            operation: 'delete'
        );
    }//end appendOnlyDeleteRefusal()

    /**
     * Whether a delete with these flags is bound by the caller's organisation.
     *
     * An organisation context binds it whatever the flags say, as for a save.
     *
     * @param bool $rbac         The caller's RBAC flag.
     * @param bool $multitenancy The caller's multitenancy flag.
     *
     * @return bool
     */
    private function deleteAnswersToOrganisation(bool $rbac, bool $multitenancy): bool
    {
        return $rbac === true || OrganisationContext::multitenancy(requested: $multitenancy) === true;
    }//end deleteAnswersToOrganisation()

    /**
     * Refuse a delete in a register or schema the caller's organisation only consumes.
     *
     * The organisation boundary answers a read question and lets a consumer of shared
     * master data see the holder's rows. A delete is a write, as for a save.
     *
     * @param int|null $registerId The register deleted from.
     * @param int|null $schemaId   The schema deleted from.
     *
     * @return void
     *
     * @throws SharedMasterDataWriteException When the caller's organisation only consumes either.
     *
     * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
     */
    private function rejectSharedMasterDataDelete(?int $registerId, ?int $schemaId): void
    {
        try {
            $shared = $this->container->get(SharedMasterDataService::class);
            $organisations = $this->organisationService->getUserActiveOrganisations();
        } catch (\Throwable $e) {
            // No resolvable organisation means no share can be consumed, as for a save.
            return;
        }

        if ($shared instanceof SharedMasterDataService === false) {
            return;
        }

        $shared->assertWritable(row: new DeclaredRow(table: SharedMasterDataService::REGISTERS, id: $registerId), activeOrgUuids: $organisations);
        $shared->assertWritable(row: new DeclaredRow(table: SharedMasterDataService::SCHEMAS, id: $schemaId), activeOrgUuids: $organisations);
    }//end rejectSharedMasterDataDelete()

    /**
     * Put a delete without a full scope on the row it found, and ask the share question of that row.
     *
     * Such a call looks across every table, so the register and schema on the service may
     * be none, partial or left over from an earlier call: only the row says what is deleted.
     *
     * @param ObjectDeleteRequest $request The delete call.
     * @param ObjectEntity        $row     The row the lookup found.
     *
     * @return void
     *
     * @throws SharedMasterDataWriteException When the caller's organisation only consumes the row's register or schema.
     * @throws RuntimeException When the row's schema cannot be resolved, so no right can be asked of it.
     *
     * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
     */
    private function scopeUnscopedDeleteToRow(ObjectDeleteRequest $request, ObjectEntity $row): void
    {
        $schemaId = $row->getSchema();
        if ($request->scoped === true || is_numeric($schemaId) === false) {
            return;
        }

        if ((int) $this->currentSchema->getId() !== (int) $schemaId) {
            $schemaCache = [];
            $this->currentSchema = $this->loadRowSchema(schemaId: $schemaId, schemaCache: $schemaCache);
            if ($this->currentSchema === null) {
                throw new RuntimeException('The schema of the object to delete could not be resolved.');
            }
        }

        if ($this->deleteAnswersToOrganisation(rbac: $request->rbac, multitenancy: $request->multitenancy) === true) {
            $this->rejectSharedMasterDataDelete(registerId: $this->rowRegisterId(row: $row), schemaId: (int) $schemaId);
        }
    }//end scopeUnscopedDeleteToRow()

    /**
     * The register a row says it lives in.
     *
     * @param ObjectEntity|null $row The row.
     *
     * @return int|null The register id, or null when the row names none.
     */
    private function rowRegisterId(?ObjectEntity $row): ?int
    {
        $registerId = $row?->getRegister();
        if (is_numeric($registerId) === false) {
            return null;
        }

        return (int) $registerId;
    }//end rowRegisterId()

    /**
     * Find the object to delete (soft-deleted rows included) and check the acting user may delete it.
     *
     * The lookup uses the caller's flags, which the delete handler honours too. When the caller
     * supplied register and schema the lookup is scoped to that one table and a miss is
     * authoritative (#1638). An unscoped miss checks the schema's delete right without an owner
     * and leaves the not-found answer to the delete handler.
     *
     * @param ObjectDeleteRequest $request The delete call.
     *
     * @return ObjectEntity|null The object, or null after an unscoped miss.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When a scoped lookup misses.
     * @throws \Exception When the acting user may not delete.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function resolvePermittedDeleteTarget(ObjectDeleteRequest $request): ?ObjectEntity
    {
        try {
            $objectToDelete = $this->findDeleteTarget(request: $request);
            $this->assertMayDeleteRow(request: $request, row: $objectToDelete);

            return $objectToDelete;
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            if ($request->scoped === true) {
                throw $e;
            }

            $this->assertMayDeleteFromSchema(request: $request);

            return null;
        }
    }//end resolvePermittedDeleteTarget()

    /**
     * Check the acting user may delete a found row.
     *
     * @param ObjectDeleteRequest $request The delete call.
     * @param ObjectEntity        $row     The row.
     *
     * @return void
     *
     * @throws \Exception When the acting user may not delete.
     */
    private function assertMayDeleteRow(ObjectDeleteRequest $request, ObjectEntity $row): void
    {
        $this->permissionHandler->checkPermission(
            schema: $this->currentSchema,
            action: 'delete',
            userId: $request->actingUserId(),
            objectOwner: $row->getOwner(),
            _rbac: $request->rbac,
            object: $row
        );

        \OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.permitted');
    }//end assertMayDeleteRow()

    /**
     * Find the row to delete and settle the scope from it.
     *
     * @param ObjectDeleteRequest $request The delete call.
     *
     * @return ObjectEntity The object.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the lookup misses.
     */
    private function findDeleteTarget(ObjectDeleteRequest $request): ObjectEntity
    {
        $objectToDelete = $this->objectMapper->find(
            identifier: $request->uuid,
            register: $this->scopedDeleteRegister(request: $request),
            schema: $this->scopedDeleteSchema(request: $request),
            includeDeleted: true,
            _rbac: $request->rbac,
            _multitenancy: $request->multitenancy
        );

        $this->adoptDeleteRowScope(request: $request, row: $objectToDelete);

        return $objectToDelete;
    }//end findDeleteTarget()

    /**
     * Settle the scope from the row found: its schema when none was provided, then its register.
     *
     * @param ObjectDeleteRequest $request The delete call.
     * @param ObjectEntity        $row     The row.
     *
     * @return void
     */
    private function adoptDeleteRowScope(ObjectDeleteRequest $request, ObjectEntity $row): void
    {
        if ($this->currentSchema === null) {
            $this->setSchema(schema: $row->getSchema());
        }

        $this->scopeUnscopedDeleteToRow(request: $request, row: $row);

        \OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.found');
    }//end adoptDeleteRowScope()

    /**
     * The register a delete lookup is scoped to.
     *
     * @param ObjectDeleteRequest $request The delete call.
     *
     * @return Register|null The register, null for an unscoped call.
     */
    private function scopedDeleteRegister(ObjectDeleteRequest $request): ?Register
    {
        if ($request->scoped === true) {
            return $this->currentRegister;
        }

        return null;
    }//end scopedDeleteRegister()

    /**
     * The schema a delete lookup is scoped to.
     *
     * @param ObjectDeleteRequest $request The delete call.
     *
     * @return Schema|null The schema, null for an unscoped call.
     */
    private function scopedDeleteSchema(ObjectDeleteRequest $request): ?Schema
    {
        if ($request->scoped === true) {
            return $this->currentSchema;
        }

        return null;
    }//end scopedDeleteSchema()

    /**
     * Check the schema's delete right, without an owner, after an unscoped lookup missed.
     *
     * @param ObjectDeleteRequest $request The delete call.
     *
     * @return void
     *
     * @throws \Exception When the acting user may not delete.
     */
    private function assertMayDeleteFromSchema(ObjectDeleteRequest $request): void
    {
        if ($this->currentSchema === null) {
            return;
        }

        $this->permissionHandler->checkPermission(
            schema: $this->currentSchema,
            action: 'delete',
            userId: $request->actingUserId(),
            objectOwner: null,
            _rbac: $request->rbac,
            register: $this->currentRegisterId()
        );
    }//end assertMayDeleteFromSchema()

    /**
     * Answer "not found" when the object is somebody else's draft, whatever the lookup let through.
     *
     * Like the save path (refuseSomebodyElsesDraft) it is not announced. The deleter is the
     * explicit acting user, or else the session user.
     *
     * @param ObjectDeleteRequest $request The delete call.
     * @param ObjectEntity|null   $object  The object to delete, or null after an unscoped miss.
     *
     * @return void
     *
     * @throws OcpDoesNotExistException When the draft is kept from the deleter.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function refuseDeletingSomebodyElsesDraft(ObjectDeleteRequest $request, ?ObjectEntity $object): void
    {
        $deleter = ($request->currentUser ?? $this->userSession->getUser());
        if ($request->rbac === true && $object !== null && $this->currentSchema !== null
            && $this->draftKeptFromCaller(object: $object, schema: $this->currentSchema, user: $deleter) === true
        ) {
            throw new OcpDoesNotExistException('Object not found');
        }
    }//end refuseDeletingSomebodyElsesDraft()

    /**
     * Hand a delete on an object-source schema to the delete handler's provider dispatch.
     *
     * Delete rights on the schema are checked first, so the external source is
     * never consulted for a caller who may not delete (no enumeration oracle).
     * There is no stored owner to check against: the row lives outside
     * OpenRegister. The handler then delegates to the writable provider, or
     * refuses the delete as a read-only projection.
     *
     * @param Schema              $schema  The schema served by the object source.
     * @param ObjectDeleteRequest $request The delete: id, acting user, policy flags and scope.
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws \Exception If the caller may not delete on this schema, or the source refuses.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function deleteFromObjectSource(Schema $schema, ObjectDeleteRequest $request): bool
    {
        $this->permissionHandler->checkPermission(
            schema: $schema,
            action: 'delete',
            userId: $request->actingUserId(),
            objectOwner: null,
            _rbac: $request->rbac,
            register: $this->currentRegisterId()
        );

        return $this->deleteHandler->deleteObject(
            request: new DeletionRequest(
                register: $this->currentRegister,
                schema: $schema,
                uuid: $request->uuid,
                options: new DeletionOptions(scoped: $request->scoped)
            ),
            _rbac: $request->rbac,
            _multitenancy: $request->multitenancy
        );
    }//end deleteFromObjectSource()

    /**
     * Check whether a schema declares an `x-openregister-archival` annotation.
     *
     * Used by the deleteObject() immutability gate to short-circuit
     * user-driven deletes before any DB work. Delegates to
     * {@see Schema::hasArchivalAnnotation()}, which is the single definition of
     * the rule: DeletedController's purge gate asks the same question of the
     * same method, so the two delete routes cannot drift apart.
     *
     * @param Schema $schema Schema to inspect.
     *
     * @return bool True when the schema carries a valid archival annotation.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function schemaHasArchivalAnnotation(Schema $schema): bool
    {
        return $schema->hasArchivalAnnotation();
    }//end schemaHasArchivalAnnotation()

    /**
     * Whether the delete targets a stored draft on a schema whose archival gate would refuse it.
     *
     * Only asked when the gate would otherwise refuse (an archival schema, not
     * the retention sweep), so a delete on any other schema costs no lookup.
     * Looked up with the caller's flags and in the register and schema in
     * context, like the transferred-object guard: an object the caller cannot
     * reach is not their draft, and the gate refuses as before.
     *
     * @param string $uuid           The object being deleted.
     * @param bool   $retentionSweep True for the archival retention sweep, which the gate lets through anyway.
     * @param bool   $_rbac          Apply RBAC to the lookup.
     * @param bool   $_multitenancy  Apply the tenant scope to the lookup.
     *
     * @return bool True when the object is stored in status `draft`.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors deleteObject()'s own flags.
     *
     * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-be-deletable-on-every-schema-and-its-delete-must-be-permanent
     */
    private function isDeletableDraft(string $uuid, bool $retentionSweep, bool $_rbac, bool $_multitenancy): bool
    {
        if ($retentionSweep === true || $this->currentSchema === null
            || $this->schemaHasArchivalAnnotation(schema: $this->currentSchema) === false
        ) {
            return false;
        }

        try {
            return $this->objectMapper->find(
                identifier: $uuid,
                register: $this->currentRegister,
                schema: $this->currentSchema,
                includeDeleted: true,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            )->isDraft();
        } catch (\Throwable) {
            return false;
        }
    }//end isDeletableDraft()

    /**
     * Whether a stored draft is kept from the caller (DraftStatusPolicy::keptFromCaller(), the one rule of every draft guard).
     *
     * One answer for the single delete and the bulk delete: another user's
     * draft is not theirs to remove. An administrator reads every draft, so
     * is not kept from one.
     *
     * @param ObjectEntity $object The stored object.
     * @param Schema       $schema Its schema.
     * @param IUser|null   $user   The caller.
     *
     * @return bool True when the object is a draft the caller may not touch.
     *
     * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
     */
    private function draftKeptFromCaller(ObjectEntity $object, Schema $schema, ?IUser $user): bool
    {
        return (new DraftCallerRule())->keptFromCaller(
            object: $object,
            schema: $schema,
            caller: new DraftCaller(
                userId: $user?->getUID(),
                isAdmin: ($user !== null && in_array('admin', $this->groupManager->getUserGroupIds($user), true) === true)
            )
        );
    }//end draftKeptFromCaller()

    /**
     * Refuse a delete on an archival-annotated schema.
     *
     * THE ONE PLACE THIS SERVICE DECIDES A DELETE IS NOT ALLOWED.
     *
     * `deleteObject()` and `deleteObjects()` destroy rows through different
     * routes — the single-object path resolves its own scope and calls the
     * delete handler once; the bulk loop resolves each row's scope itself and
     * calls the handler per row. Only the first one used to ask this question,
     * so `POST /api/bulk/{register}/{schema}/delete` was a third door onto the
     * destruction that `DELETE /api/objects/...` answers with a 403 and
     * `DELETE /api/deleted/{uuid}` has refused since openregister#3428. Both
     * callers now go through here, so the rule has one definition per door and
     * the doors read the same one.
     *
     * WHAT TO DO ABOUT AN UNRESOLVED SCHEMA IS THE CALLER'S DECISION, and the two
     * callers genuinely differ, so this method takes a resolved Schema and each
     * call site states its own policy. `deleteObject()` keeps skipping the gate
     * when it has no schema in scope — a bare `deleteObject($uuid)` never had
     * one and refusing those now would break every scopeless caller. The bulk
     * loop refuses instead, because it resolves each ROW's own schema: failing
     * to resolve one is not evidence the row is deletable, and falling back to
     * the route's schema would gate a cross-table row on the wrong annotation.
     *
     * @param Schema $schema         Schema the row belongs to.
     * @param bool   $retentionSweep True only for ArchivalRetentionTask, the sanctioned expiry path.
     *
     * @return void
     *
     * @throws ArchivalImmutableException When the delete is not permitted.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors deleteObject()'s $_retentionSweep parameter.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function rejectIfArchivalImmutable(Schema $schema, bool $retentionSweep): void
    {
        if ($retentionSweep === true) {
            return;
        }

        if ($this->schemaHasArchivalAnnotation(schema: $schema) === false) {
            return;
        }

        throw new ArchivalImmutableException(
            schemaIdentifier: ($schema->getSlug() ?? (string) $schema->getId()),
            operation: 'delete'
        );
    }//end rejectIfArchivalImmutable()

    /**
     * Refuse a write to a type whose objects are the rows of a saved view.
     *
     * @param string $operation The refused operation, for the message.
     *
     * @return void
     *
     * @throws ReadOnlyTypeException When the current schema is view-backed.
     *
     * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
     */
    private function rejectIfViewBacked(string $operation): void
    {
        if ($this->currentSchema === null || ($this->currentSchema->getObjectSource()['provider'] ?? null) !== 'view') {
            return;
        }

        throw new ReadOnlyTypeException(
            schemaIdentifier: ($this->currentSchema->getSlug() ?? (string) $this->currentSchema->getId()),
            operation: $operation
        );
    }//end rejectIfViewBacked()

    /**
     * Reject an operation if the object has been transferred to e-Depot.
     *
     * Objects with archiefstatus 'overgebracht' are read-only. The authoritative
     * copy resides in the e-Depot and this system copy MUST NOT be modified.
     *
     * @param string $uuid          The object UUID to check.
     * @param bool   $_rbac         Apply RBAC to the lookup (default: true, today's behaviour).
     * @param bool   $_multitenancy Apply the tenant scope to the lookup (default: true).
     *
     * @return void
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the object has been
     *         transferred to e-Depot and is therefore read-only.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function rejectIfTransferred(string $uuid, bool $_rbac=true, bool $_multitenancy=true): void
    {
        try {
            // Scoped to the register and schema currently in context: the only
            // object whose transfer status can block this write is the one being
            // written. Unscoped, this UNION-ed every magic table on the instance
            // — the second such lookup in the same phase, on the same UUID.
            $object = $this->objectMapper->find(
                identifier: $uuid,
                register: $this->currentRegister,
                schema: $this->currentSchema,
                includeDeleted: true,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );

            if ($this->isTransferred(object: $object) === true) {
                throw new OcpDoesNotExistException('OBJECT_TRANSFERRED: '.self::TRANSFERRED_MESSAGE);
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
     * Whether an object has been transferred to the e-Depot.
     *
     * Both vocabularies count: stored data is not migrated, so an install that
     * transferred a record before GAP A4 holds `overgebracht`.
     *
     * @param ObjectEntity $object The object.
     *
     * @return bool
     */
    private function isTransferred(ObjectEntity $object): bool
    {
        $retention = ($object->getRetention() ?? []);

        return in_array(($retention['archiefstatus'] ?? ''), RecordState::TRANSFERRED_ALIASES, true);
    }//end isTransferred()

    /**
     * Whether a write with this uuid on an append-only schema would touch a stored object.
     *
     * Asked with RBAC and multitenancy OFF and soft-deleted rows included: the
     * save handler resolves the uuid the same unfiltered way, so an object the
     * caller cannot see (another tenant's) would otherwise be the one it
     * updates. The refusal the caller gets is the same whether the stored row
     * is visible to them or not, so it says no more than the `_uuid` unique
     * constraint would. Scoped to the register and schema being written to,
     * like the permission lookup.
     *
     * @param string $uuid The uuid the write carries.
     *
     * @return bool True when an object with this identifier is stored.
     *
     * @spec exclude bug fix: append-only refused every insert carrying a caller-chosen uuid
     */
    private function appendOnlyTargetExists(string $uuid): bool
    {
        try {
            $this->objectMapper->find(
                identifier: $uuid,
                register: $this->currentRegister,
                schema: $this->currentSchema,
                includeDeleted: true,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return false;
        }

        return true;
    }//end appendOnlyTargetExists()

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
     * A reference that names no register or schema is REFUSED here rather than
     * answered with an empty page, which is what the int-cast used to do
     * (openregister#3990). The published contract in lib/Contract/ is mirrored
     * in hydra-gates and is left untouched on purpose: changing it means
     * changing both copies in one change (ADR-084).
     *
     * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException When the register reference names no register.
     * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException When the schema reference names no schema.
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
     * @param bool          $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool          $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param SearchOptions $options       The id and uses selection, the view ids, and whether the
     *                                     view filter is this caller's ONLY bound. A caller that
     *                                     switches `$_rbac` and `$_multitenancy` off because it
     *                                     carries its own authorization (a published access link
     *                                     naming one view) MUST set `viewScopeRequired`: it makes an
     *                                     unresolvable or non-narrowing view throw instead of
     *                                     silently yielding an unbounded search.
     *
     * @psalm-param array<string, mixed> $query
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]|int
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \Exception If `viewScopeRequired` is set and the view cannot be applied
     *
     * @psalm-return int<0, max>|list<\OCA\OpenRegister\Db\ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the `_rbac` / `_multitenancy` convention
     *
     * @spec exclude One-line delegation to QueryHandler::searchObjects(); search behavior owned by zoeken-filteren.
     */
    public function searchObjects(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        SearchOptions $options=new SearchOptions()
    ): array|int {
        // ARCHITECTURAL DELEGATION: Delegate to QueryHandler for all search operations.
        return $this->queryHandler->searchObjects(
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            options: $options
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
     * @param RegisterSchemaRef $target        The register and schema slugs (both must exist in caller's org).
     * @param array             $filters       Additional filters merged into the @self block.
     *                                         Direct keys like 'status' are merged at the top
     *                                         level.
     * @param bool              $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool              $_multitenancy Whether to apply multi-tenancy filter (default: true).
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
        RegisterSchemaRef $target,
        array $filters=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array|int {
        $access   = new AccessFlags(rbac: $_rbac, multitenancy: $_multitenancy);
        $register = $this->registerForSlugSearch(slug: (string) $target->register, access: $access);
        $schema   = $this->schemaWithinRegister(slug: (string) $target->schema, register: $register);

        // Merge resolved numeric IDs into the @self block of the filters.
        // Direct keys (status, etc.) stay at the top level so they hit the
        // object JSON filter path, not the metadata filter path.
        $resolved         = ['register' => $register->getId(), 'schema' => $schema->getId()];
        $filters['@self'] = array_merge(($filters['@self'] ?? []), $resolved);

        // Delegate to the numeric-ID searchObjects on the documented fast path.
        return $this->searchObjects(query: $filters, _rbac: $_rbac, _multitenancy: $_multitenancy);
    }//end searchObjectsBySlug()

    /**
     * Find the register of a slug search.
     *
     * @param string      $slug   The register slug.
     * @param AccessFlags $access Whether RBAC and multitenancy apply.
     *
     * @return Register The register.
     *
     * @throws OcpDoesNotExistException When the slug is missing or belongs to a foreign organisation.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md
     */
    private function registerForSlugSearch(string $slug, AccessFlags $access): Register
    {
        // Resolve register slug → numeric ID via the existing mapper find()
        // which already accepts slug strings and applies the standard
        // organisation filter. Throws DoesNotExistException if the slug is
        // missing or belongs to a foreign organisation — same contract as
        // every other lookup in OR.
        try {
            return $this->registerMapper->find(
                id: $slug,
                _rbac: $access->rbac,
                _multitenancy: $access->multitenancy
            );
        } catch (OcpDoesNotExistException $e) {
            throw new OcpDoesNotExistException(
                'searchObjectsBySlug: register slug not found in caller organisation: '.$slug
            );
        }
    }//end registerForSlugSearch()

    /**
     * Find the schema of a slug search inside its register.
     *
     * @param string   $slug     The schema slug.
     * @param Register $register The register that bounds the lookup.
     *
     * @return Schema The schema.
     *
     * @throws SchemaNotInRegisterException When the register does not carry the slug.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md
     */
    private function schemaWithinRegister(string $slug, Register $register): Schema
    {
        // Resolve schema slug → numeric ID, REGISTER-SCOPED and nothing else. The
        // caller named a register, so the register is the boundary.
        //
        // This previously fell back to an organisation-scoped global find(). That
        // guard is not sufficient: the nine same-slug `anonymizationLink` schemas
        // measured on 2026-08-16 are owned by the SAME application, so an
        // organisation filter still selects the wrong one. Only the register
        // disambiguates them.
        $schemaIds = ($register->getSchemas() ?? []);
        $schema    = $this->schemaMapper->findBySlugInIds(slug: $slug, schemaIds: $schemaIds);
        if ($schema !== null) {
            return $schema;
        }

        throw new SchemaNotInRegisterException(
            schemaSlug: $slug,
            registerId: $register->getId(),
            registerSlug: $register->getSlug(),
            candidatesElsewhere: $this->schemaMapper->countBySlug(slug: $slug),
            registerSchemaCount: count($schemaIds)
        );
    }//end schemaWithinRegister()

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
     * @param ObjectSelection      $selection     The object ids and the uses filter the count is restricted to
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
        ObjectSelection $selection=new ObjectSelection()
    ): int {
        return $this->queryHandler->countSearchObjects(
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            selection: $selection
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
     * **SEARCH ENGINE**: This method searches the database (magic tables).
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
     *                                   legacy facets - _content_search: opt-in bool
     *                                   (default false) to widen `_search` matches to
     *                                   attached-file/object chunk body text via
     *                                   `ChunkMapper::searchByKeyword()`; absent/false is
     *                                   byte-identical to pre-change behaviour (see
     *                                   openspec/specs/zoeken-filteren/spec.md)
     * @param bool          $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool          $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param SearchOptions $options       The id and uses selection, the view ids whose filters
     *                                     apply (refused when `viewScopeRequired` is set and no view
     *                                     narrows the query) and the deleted flag echoed as
     *                                     `@self.deleted`
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
     * @throws \Exception If the search fails and cannot be recovered
     *
     * @spec exclude Facade routing the unified paginated/faceted search to the query + facet handlers;
     *   behavior owned by zoeken-filteren / faceting-configuration.
     */
    public function searchObjectsPaginated(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        SearchOptions $options=new SearchOptions()
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

        $query = $this->scopeSearchQuery(query: $query, options: $options);

        $result = $this->queryHandler->searchObjectsPaginatedDatabase(
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $this->searchMultitenancy(multitenancy: $_multitenancy),
            selection: $options->selection
        );
        // Preserve source from result (e.g., magic_mapper for multi-schema), only default to database if not set.
        $result['@self']['source']  = $result['@self']['source'] ?? 'database';
        $result['@self']['query']   = $query;
        $result['@self']['rbac']    = $_rbac;
        $result['@self']['multi']   = $_multitenancy;
        $result['@self']['deleted'] = $options->deleted;

        $result = $this->extendSearchResult(result: $result, query: $query);

        (new SearchTrailRecorder(
            searchQueryHandler: $this->searchQueryHandler,
            logger: $this->logger,
            startTime: $searchStartTime
        ))->record(query: $query, result: $result);
        $this->logProcessingReadList(objects: ($result['results'] ?? []), query: $query);

        return $result;
    }//end searchObjectsPaginated()

    /**
     * Scope a paginated search query to the service context, apply views and drop `_source`.
     *
     * @param array<string, mixed> $query   The search query.
     * @param SearchOptions        $options The view ids whose filters apply, and whether they must.
     *
     * @return array<string, mixed> The query the database search runs with.
     *
     * @throws \Exception When `viewScopeRequired` is set and no view can scope the query.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md
     */
    private function scopeSearchQuery(array $query, SearchOptions $options): array
    {
        $query = $this->scopeQueryToContext(query: $query);

        if (empty($options->views) === false) {
            $query = $this->searchQueryHandler->applyViewsToQuery(
                query: $query,
                viewIds: $options->views,
                _viewScopeRequired: $options->viewScopeRequired
            );
        } else if ($options->viewScopeRequired === true) {
            throw new \Exception('Refusing a view-scoped search without a view to scope it by.');
        }

        // Strip deprecated _source parameter (silently ignore for backward compatibility).
        unset($query['_source']);

        return $query;
    }//end scopeSearchQuery()

    /**
     * Add the current register and schema to a query that does not name its own.
     *
     * Array keys are checked with array_key_exists, so an explicit null disables the
     * default. A cross-schema search never gets the current register, because each
     * schema resolves to its own owning register downstream.
     *
     * @param array<string, mixed> $query The search query.
     *
     * @return array<string, mixed> The query with `_register` and `_schema` defaults.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md
     */
    private function scopeQueryToContext(array $query): array
    {
        if ($this->mayScopeToRegister(query: $query) === true) {
            $query['_register'] = $this->currentRegister->getId();
        }

        if ($this->mayScopeToSchema(query: $query) === true) {
            $query['_schema'] = $this->currentSchema->getId();
        }

        return $query;
    }//end scopeQueryToContext()

    /**
     * Whether a search takes the context's register: one is set and the query names none.
     *
     * @param array $query The search query.
     *
     * @return bool True when the register applies.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md
     */
    private function mayScopeToRegister(array $query): bool
    {
        return $this->currentRegister !== null
            && array_key_exists('_register', $query) === false
            && $this->isMultiSchemaSearch(query: $query) === false;
    }//end mayScopeToRegister()

    /**
     * Whether a search takes the context's schema: one is set and the query names none.
     *
     * @param array $query The search query.
     *
     * @return bool True when the schema applies.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md
     */
    private function mayScopeToSchema(array $query): bool
    {
        return $this->currentSchema !== null
            && array_key_exists('_schema', $query) === false
            && array_key_exists('_schemas', $query) === false;
    }//end mayScopeToSchema()

    /**
     * Whether a query searches many schemas: a non-empty `@self.schema` array, `_schemas` or `@self.schemas`.
     *
     * @param array<string, mixed> $query The search query.
     *
     * @return bool True for a cross-schema search.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md
     */
    private function isMultiSchemaSearch(array $query): bool
    {
        $selfSchema = ($query['@self']['schema'] ?? null);

        return (is_array($selfSchema) === true && count($selfSchema) > 0)
            || array_key_exists('_schemas', $query) === true
            || (isset($query['@self']['schemas']) === true);
    }//end isMultiSchemaSearch()

    /**
     * The multitenancy flag a paginated search runs with.
     *
     * A current schema whose read rule lists `public` is visible to every
     * organisation for an anonymous caller and an admin, so multitenancy is
     * switched off for them. A signed-in caller keeps it and reads other
     * organisations only through rules marked `crossOrganisation: true`.
     *
     * @param bool $multitenancy The caller's multitenancy flag.
     *
     * @return bool The effective flag.
     *
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    private function searchMultitenancy(bool $multitenancy): bool
    {
        if ($multitenancy === false || $this->currentSchema === null) {
            return $multitenancy;
        }

        $schemaAuth = $this->currentSchema->getAuthorization();
        $readGroups = $schemaAuth['read'] ?? [];

        if (in_array('public', $readGroups, true) === false) {
            return true;
        }

        return $this->objectMapper->publicReadLiftsOrganisationFilter(schema: $this->currentSchema) === false;
    }//end searchMultitenancy()

    /**
     * Add the `_extend` objects and the `_names` map to a paginated search result.
     *
     * @param array<string, mixed> $result The search result.
     * @param array<string, mixed> $query  The query the search ran with.
     *
     * @return array<string, mixed> The result with its extensions.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md
     */
    private function extendSearchResult(array $result, array $query): array
    {
        // Normalize _extend to array (handles comma-separated string from URL).
        $extend = $query['_extend'] ?? [];
        if (is_string($extend) === true) {
            $extend = array_filter(array_map('trim', explode(',', $extend)));
        }

        if (empty($extend) === false) {
            $result['@self']['objects'] = $this->getExtendedObjects();
        }

        if (is_array($extend) === true && in_array('_names', $extend, true) === true) {
            $result = $this->attachResultNames(result: $result);
        }

        return $result;
    }//end extendSearchResult()

    /**
     * Add the UUID-to-name map of a search result's related objects as `@self.names`.
     *
     * A failed lookup leaves an empty map and records the message in `@self.names_error`.
     *
     * @param array<string, mixed> $result The search result.
     *
     * @return array<string, mixed> The result with `@self.names`.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md
     */
    private function attachResultNames(array $result): array
    {
        $resultsToProcess = $result['results'] ?? [];

        $result['@self']['names'] = [];
        if (is_array($resultsToProcess) === false || empty($resultsToProcess) === true) {
            return $result;
        }

        try {
            $result['@self']['names'] = (new ResultNameCollector(cacheHandler: $this->cacheHandler))->namesFor(
                results: $resultsToProcess
            );
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

        return $result;
    }//end attachResultNames()

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
        $this->permissionHandler->checkPermission(
            schema: $schema,
            action: 'read',
            userId: null,
            objectOwner: null,
            _rbac: $_rbac
        );

        $read = new ObjectSourceRead(
            schema: $schema,
            query: (new ObjectSourceQuery())->forProvider(query: $query),
            config: ($source['config'] ?? []),
            provider: $this->objectSourceRegistry->get($source['provider'])
        );

        return (new ObjectSourcePageReader(logger: $this->logger))->read(read: $read, register: $this->currentRegister);
    }//end paginateObjectSource()

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
        $result = [];

        foreach ($this->registerMapper->findAll(_multitenancy: false) as $register) {
            $registerArr            = $register->jsonSerialize();
            $registerArr['schemas'] = $this->serializedSchemasOf(register: $register);
            $result[] = $registerArr;
        }

        return $result;
    }//end getRegisters()

    /**
     * Serialize the schemas of a register, skipping ids that no longer resolve.
     *
     * @param Register $register The register.
     *
     * @return array<int, array<string, mixed>> The serialized schemas.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function serializedSchemasOf(Register $register): array
    {
        $schemas = [];

        foreach (($register->getSchemas() ?? []) as $schemaId) {
            try {
                $schema    = $this->schemaMapper->find(id: (int) $schemaId, _multitenancy: false);
                $schemas[] = $schema->jsonSerialize();
            } catch (\Exception $e) {
                // Skip schemas that cannot be found.
                $this->logger->debug(
                    message: '[ObjectService] Skipping a register schema that cannot be loaded',
                    context: [
                        'schemaId' => $schemaId,
                        'exception' => $e->getMessage(),
                    ]
                );
            }
        }

        return $schemas;
    }//end serializedSchemasOf()

    /**
     * Renders the rendered object.
     *
     * A top-level render: depth 0, nothing preloaded.
     *
     * @param ObjectEntity         $entity        The entity to be rendered
     * @param RenderSelection|null $selection     The extend, filter, fields and unset lists; null renders the plain object
     * @param bool                 $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool                 $_multitenancy Whether to apply multitenancy filtering (default: true)
     *
     * @return array Rendered entity data
     *
     * @spec exclude Facade delegating object rendering to the render handler;
     *   render contract owned by files-render-extension / schema-driven-read-coercion.
     */
    public function renderEntity(ObjectEntity $entity, ?RenderSelection $selection=null, bool $_rbac=true, bool $_multitenancy=true): array
    {
        $selection ??= new RenderSelection(extend: [], filter: [], fields: [], unset: []);
        return $this->renderHandler->render(
            entity: $entity,
            request: RenderRequest::page(selection: $selection),
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
     * @return CacheHandlerInterface The cache handler instance.
     */
    public function getCacheHandler(): CacheHandlerInterface
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
     * A deletion analysis without the objects the signed-in caller cannot read.
     *
     * @param DeletionAnalysis $analysis The analysis of the unscoped integrity walk.
     *
     * @return DeletionAnalysis The analysis with unreadable objects counted per schema.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function readableDeletionAnalysis(DeletionAnalysis $analysis): DeletionAnalysis
    {
        return (new ReadableDeletionAnalysis(mapper: $this->objectMapper))->analysisFor(analysis: $analysis);
    }//end readableDeletionAnalysis()

    /**
     * Get the permission handler.
     *
     * Used by controllers that report on a decision rather than make one: the
     * actions a reader may take on the record they just read, and the rules
     * behind them. The verdict itself stays inside this service.
     *
     * @return PermissionHandlerInterface The permission handler.
     *
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    public function getPermissionHandler(): PermissionHandlerInterface
    {
        return $this->permissionHandler;
    }//end getPermissionHandler()

    /**
     * Check if a string is in UUID format.
     *
     * @param string $value The value to check.
     *
     * @return bool True if the value matches UUID format.
     */
    private function isUuidFormat(string $value): bool
    {
        return UuidFormat::isCanonical(value: $value);
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
     * @param string|null $runUuid    Flow run taking the lock, for a run-scoped lock
     *                                that refuses every other caller including the
     *                                run's own runAs user
     * @param string|null $nodeId     Flow node that took it, recorded for the sweep
     *
     * @return array Lock information
     *
     * @throws \Exception If lock operation fails
     *
     * @spec exclude One-line delegation to lock handler; lock behavior owned by object-lifecycle.
     */
    public function lockObject(
        string $identifier,
        ?string $process=null,
        ?int $duration=null,
        bool $advisory=false,
        ?string $runUuid=null,
        ?string $nodeId=null
    ): array {
        $step = new LockRunStep(runUuid: $runUuid, nodeId: $nodeId);
        $request = new LockRequest(identifier: $identifier, process: $process, duration: $duration, runStep: $step);
        return $this->lock(request: $request, advisory: $advisory);
    }//end lockObject()

    /**
     * Lock an object, as one lock request.
     *
     * @param LockRequest $request  The object, process, duration and flow run step of the lock.
     * @param bool        $advisory When true, treat the identifier as a synthetic
     *                              pre-creation key and take the appConfig-backed
     *                              advisory lock without scanning object tables
     *
     * @return array Lock information
     *
     * @throws \Exception If lock operation fails
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The advisory flag mirrors lockObject() and the lock handler.
     *
     * @spec exclude One-line delegation to lock handler; lock behavior owned by object-lifecycle.
     */
    public function lock(LockRequest $request, bool $advisory=false): array
    {
        return $this->lockHandler->lock(request: $request, advisory: $advisory);
    }//end lock()

    /**
     * Unlock an object
     *
     * Removes the lock from an object, allowing other processes to modify it.
     *
     * @param string|int $identifier The object to unlock
     * @param bool       $advisory   When true, release the appConfig-backed advisory
     *                               lock for this synthetic key without scanning tables
     * @param string|null $runUuid   Flow run releasing the lock, for a run-scoped lock
     *
     * @return bool True if unlocked successfully
     *
     * @throws \Exception If unlock operation fails
     *
     * @spec exclude One-line delegation to lock handler; unlock behavior owned by object-lifecycle.
     */
    public function unlockObject(string|int $identifier, bool $advisory=false, ?string $runUuid=null): bool
    {
        return $this->lockHandler->unlock(
            request: new UnlockRequest(identifier: (string) $identifier, advisory: $advisory, runUuid: $runUuid, break: false)
        );
    }//end unlockObject()

    /**
     * Break a lock as an administrator, recording the displacement.
     *
     * @param string $identifier The object whose lock is to be broken
     *
     * @return bool True when a lock was broken
     *
     * @throws \Exception If the caller is not an administrator
     *
     * @spec exclude One-line delegation to lock handler; break behavior owned by the lock handler.
     */
    public function breakObjectLock(string $identifier): bool
    {
        return $this->lockHandler->breakLock(identifier: $identifier);
    }//end breakObjectLock()

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
     * @param bool                     $_audit         Whether to write audit trail rows (bulk mirror of `silent`)
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
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every argument after $schema is a per-call
     *   policy switch that the single-object saveObject() also takes, and the two paths have to
     *   offer the same switches or a caller cannot move between them — which is exactly what the
     *   `$_audit` flag exists for. Collapsing them into an options object is worth doing, but it
     *   is a change to BOTH save paths and all their callers, not to this signature alone.
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
        bool $enrich=true,
        bool $_audit=true
    ): array {
        // A BULK SAVE SCOPES ITSELF; IT DOES NOT SCOPE THE NEXT CALLER.
        // Same contract as saveObject(); see restoreScopeContext().
        $options = [
            'register'       => $register,
            'schema'         => $schema,
            '_rbac'          => $_rbac,
            '_multitenancy'  => $_multitenancy,
            '_validation'    => $validation,
            '_events'        => $events,
            'deduplicateIds' => $deduplicateIds,
            'enrich'         => $enrich,
            '_audit'         => $_audit,
        ];

        return $this->preservingScope(
            operation: fn (): array => $this->saveBulkWithinScope(objects: $objects, options: $options)
        );
    }//end saveObjects()

    /**
     * Runs one bulk save inside the context saveObjects() keeps.
     *
     * @param array $objects The objects to save.
     * @param array $options The arguments of the saveObjects() call.
     *
     * @phpstan-param array<int, array<string, mixed>> $objects
     * @phpstan-param array<string, mixed>             $options
     * @psalm-param   array<int, array<string, mixed>> $objects
     * @psalm-param   array<string, mixed>             $options
     *
     * @return array The bulk result of the SaveObjects handler.
     *
     * @spec exclude Private step of saveObjects(); write behaviour owned by the SaveObjects handler.
     */
    private function saveBulkWithinScope(array $objects, array $options): array
    {
        $this->openBulkSave(register: $options['register'], schema: $options['schema']);

        $bulkOptions = new BulkSaveOptions(
            defaults: new BulkBatchDefaults(register: $this->currentRegister, schema: $this->currentSchema),
            validation: $options['_validation'],
            events: $options['_events'],
            audit: $options['_audit']
        );
        $bulkResult  = $this->saveObjectsHandler->saveObjects(
            objects: $objects,
            options: $bulkOptions,
            _rbac: $options['_rbac'],
            _multitenancy: $options['_multitenancy']
        );

        $this->invalidateAfterBulkSave(bulkResult: $bulkResult);
        return $bulkResult;
    }//end saveBulkWithinScope()

    /**
     * Sets the context of one bulk save.
     *
     * The folder-access revalidation cache is bound to this bulk-save call (see saveObject)
     * so mid-request folder mutations are re-validated.
     *
     * @param Register|string|int|null $register The register, when the call names one.
     * @param Schema|string|int|null   $schema   The schema, when the call names one.
     *
     * @return void
     *
     * @spec exclude Private step of saveObjects(); write behaviour owned by the SaveObjects handler.
     */
    private function openBulkSave(Register | string | int | null $register, Schema | string | int | null $schema): void
    {
        $this->discardPendingSchemaRef();
        $this->fileService->resetFolderAccessRevalidationCache();
        $this->applyScope(register: $register, schema: $schema);
    }//end openBulkSave()

    /**
     * Drops the caches a bulk save touched.
     *
     * @param array $bulkResult The bulk result of the SaveObjects handler.
     *
     * @phpstan-param array<string, mixed> $bulkResult
     * @psalm-param   array<string, mixed> $bulkResult
     *
     * @return void
     *
     * @spec exclude Private step of saveObjects(); invalidation owned by BulkCacheInvalidator.
     */
    private function invalidateAfterBulkSave(array $bulkResult): void
    {
        $this->bulkCacheInvalidator()->afterBulkSave(
            bulkResult: $bulkResult,
            scope: new SavedScopeIds(
                registerId: $this->currentRegister?->getId(),
                schemaId: $this->currentSchema?->getId()
            )
        );
    }//end invalidateAfterBulkSave()

    /**
     * Save many objects one row at a time, keeping reference checks O(1).
     *
     * The counterpart to {@see saveObjects()}, and the choice between them is a
     * real trade-off rather than a preference:
     *
     *   saveObjects()          — MagicMapper's ultraFastBulkSave. Fastest
     *                            writes, but it never consults the
     *                            reference-validation cache, so a payload whose
     *                            rows reference each other pays N×M database
     *                            round-trips resolving them.
     *   saveObjectsStreaming() — each row goes through the standard saveObject()
     *                            path, which engages the request-scoped
     *                            reference cache. Second and later occurrences
     *                            of any target UUID resolve from memory, and the
     *                            iterable is consumed lazily so the whole
     *                            payload never has to be resident.
     *
     * So streaming wins on heavily cross-referenced or very large imports and
     * loses on flat ones. Neither is universally correct, which is why the
     * caller picks.
     *
     * Failure isolation differs too: a row that throws is recorded on the
     * returned status and the batch continues, rather than failing the call.
     *
     * @param array                    $objects  Rows in the shape saveObject() accepts.
     * @param Register|string|int|null $register Optional register context.
     * @param Schema|string|int|null   $schema   Optional schema context.
     *
     * @return BatchOperationStatus Per-row outcomes plus reference-cache counters.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function saveObjectsStreaming(
        array $objects,
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null
    ): BatchOperationStatus {
        $target = new ObjectFindTarget(register: $register, schema: $schema);
        return $this->preservingScope(operation: fn (): BatchOperationStatus => $this->streamIntoTarget(objects: $objects, target: $target));
    }//end saveObjectsStreaming()

    /**
     * Apply the streaming save's register and schema, then stream the rows into them.
     *
     * A STREAMING SAVE SCOPES ITSELF; IT DOES NOT SCOPE THE NEXT CALLER. saveObjectsStreaming()
     * runs this inside preservingScope(), the same contract as saveObject(); see restoreScopeContext().
     *
     * @param array            $objects The rows to stream.
     * @param ObjectFindTarget $target  The register and schema the caller named.
     *
     * @return BatchOperationStatus The status of the streamed batch.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function streamIntoTarget(array $objects, ObjectFindTarget $target): BatchOperationStatus
    {
        $this->applyScope(register: $target->register, schema: $target->schema);
        return $this->streamIntoContext(objects: $objects);
    }//end streamIntoTarget()

    /**
     * Stream rows into the current register and schema.
     *
     * @param array $objects The rows to save.
     *
     * @return BatchOperationStatus The status of the run.
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function streamIntoContext(array $objects): BatchOperationStatus
    {
        // The reference cache is request-scoped and this call is a logical
        // boundary: verdicts from an earlier batch must not decide this one.
        $this->saveHandler->clearReferenceValidationCache();

        return $this->saveHandler->saveObjectsStreaming(
            save: new StreamingSave(register: $this->currentRegister, schema: $this->currentSchema, rows: $objects)
        );
    }//end streamIntoContext()


    /**
     * Append rows to a register+schema table with every safeguard switched OFF.
     *
     * THIS IS THE FAST PATH FOR HIGH-VOLUME, LOW-VALUE WRITERS such as traffic
     * events and telemetry: chunked multi-row INSERT ... ON DUPLICATE KEY UPDATE
     * statements straight into the magic table. It exists because the normal
     * save path costs several queries and side effects PER ROW, and a collector
     * receiving thousands of events a minute can afford neither.
     *
     * WHAT IS SKIPPED, on purpose and without exception:
     *   - RBAC and multitenancy: nobody's permissions are checked. Rows carry
     *     whatever `owner` / `organisation` the caller passes, or NULL, so they
     *     may be invisible to tenant-scoped reads.
     *   - Schema validation: property values are written as given. A value that
     *     does not fit its column fails at the database; a property the table
     *     has no column for is dropped.
     *   - Audit trail: no create/update entry is written, so these rows have no
     *     history, no revert and no hash chain.
     *   - Lifecycle events: no ObjectCreated/ObjectUpdated events, so webhooks,
     *     flows and the search index are not notified.
     *   - Relations, files, computed fields, slugs, cascading and enrichment.
     *
     * THE CALLER TAKES THE AUTHORISATION RESPONSIBILITY. Calling this method is
     * the same declaration as `_rbac: false` on the normal path (ADR-022): the
     * caller has decided who may write here. Use saveObjects() for anything a
     * person will edit, audit or revert.
     *
     * Each `$objects` entry is a plain associative array of property values,
     * plus the optional metadata keys `uuid` (a v4 is generated when absent),
     * `expires` (ISO 8601 string or DateTimeInterface; lands in the indexed
     * `_expires` column that purgeExpiredObjectsRaw() sweeps), `owner` and
     * `organisation`. `_created` and `_updated` are set to now. The service's
     * current register/schema context is left untouched.
     *
     * @param array               $objects  Rows to append, as plain arrays.
     * @param Register|string|int $register The register entity, id, uuid or slug.
     * @param Schema|string|int   $schema   The schema entity, id, uuid or slug, resolved WITHIN the register.
     *
     * @return int The number of rows written.
     *
     * @throws OcpDoesNotExistException     When the register does not exist.
     * @throws SchemaNotInRegisterException When the register does not carry the schema.
     * @throws \OCP\DB\Exception            When the insert fails.
     *
     * @psalm-param   array<int, array<string, mixed>> $objects
     * @phpstan-param array<int, array<string, mixed>> $objects
     *
     * @spec openspec/specs/data-import-export/spec.md#requirement-raw-append-for-high-volume-writers
     */
    public function appendObjectsRaw(array $objects, Register|string|int $register, Schema|string|int $schema): int
    {
        if ($objects === []) {
            return 0;
        }

        [$registerEntity, $schemaEntity] = $this->resolveRawTarget(register: $register, schema: $schema);
        return $this->appendRawRows(table: new RegisterSchema(register: $registerEntity, schema: $schemaEntity), objects: $objects);
    }//end appendObjectsRaw()

    /**
     * Append rows to a resolved table without the per-object save pipeline.
     *
     * @param RegisterSchema $table   The register and schema whose table receives the rows.
     * @param array          $objects The objects, in the shape appendObjectsRaw() takes.
     *
     * @return int The number of rows written.
     *
     * @spec exclude Private step of appendObjectsRaw(); the contract is documented there.
     */
    private function appendRawRows(RegisterSchema $table, array $objects): int
    {
        $rows = array_map(
            fn (array $object): array => $this->prepareRawRow(object: $object),
            array_values($objects)
        );

        // The bulk upsert creates a missing table only after a failed statement;
        // creating it up front keeps the first append on the same path as
        // every later one.
        $this->objectMapper->ensureTableForRegisterSchema(register: $table->register, schema: $table->schema);
        $tableName = $this->objectMapper->getTableNameForRegisterSchema(register: $table->register, schema: $table->schema);

        $written = $this->objectMapper->bulkUpsert(
            objects: $rows,
            register: $table->register,
            schema: $table->schema,
            tableName: $tableName,
            needsPreUpdateState: false
        );

        return count($written);
    }//end appendRawRows()


    /**
     * Hard-delete the rows of a register+schema table whose expiry has passed.
     *
     * The sweep for appendObjectsRaw(). Rows with a NULL `_expires` are never
     * touched. This BYPASSES soft-delete and the audit trail on purpose: raw
     * rows never had an audit trail, so there is nothing to tombstone, and
     * keeping expired telemetry as soft-deleted rows would defeat the
     * retention the expiry expresses. Same responsibility model as the append:
     * the caller has decided that this register+schema holds expiring raw rows.
     *
     * @param Register|string|int $register The register entity, id, uuid or slug.
     * @param Schema|string|int   $schema   The schema entity, id, uuid or slug, resolved WITHIN the register.
     *
     * @return int The number of rows removed; 0 when the table does not exist yet.
     *
     * @throws OcpDoesNotExistException     When the register does not exist.
     * @throws SchemaNotInRegisterException When the register does not carry the schema.
     * @throws \OCP\DB\Exception            When the delete fails.
     *
     * @orphaned-write-capability exclude Declared on ObjectServiceInterface, so
     *          its callers are OTHER APPS and no in-repo call site can exist.
     *          gate-57 counts `->purgeExpiredObjectsRaw(` across lib/ only. Do
     *          NOT satisfy it by adding a route: this bypasses soft-delete and
     *          the audit trail by design, and exposing it over HTTP would hand
     *          any caller an unauditable hard delete of a whole register+schema.
     *          The sweep belongs to the app that owns the expiring rows.
     *
     * @spec openspec/specs/data-import-export/spec.md#requirement-raw-append-for-high-volume-writers
     */
    public function purgeExpiredObjectsRaw(Register|string|int $register, Schema|string|int $schema): int
    {
        [$registerEntity, $schemaEntity] = $this->resolveRawTarget(register: $register, schema: $schema);

        return $this->objectMapper->purgeExpired(register: $registerEntity, schema: $schemaEntity);
    }//end purgeExpiredObjectsRaw()


    /**
     * Resolve a register and a schema for a raw operation without touching the service context.
     *
     * The register is the boundary: the schema is resolved among the schemas the
     * register carries, never instance-wide, exactly as setRegister()/setSchema()
     * do. Unlike those setters nothing is stored on `$this`, so a raw write from
     * a background job cannot leak a register or a pending schema ref into the
     * next caller's chain.
     *
     * @param Register|string|int $register The register entity, id, uuid or slug.
     * @param Schema|string|int   $schema   The schema entity, id, uuid or slug.
     *
     * @return array{0: Register, 1: Schema} The resolved pair.
     *
     * @throws OcpDoesNotExistException     When the register does not exist.
     * @throws SchemaNotInRegisterException When the register does not carry the schema.
     *
     * @spec openspec/specs/data-import-export/spec.md#requirement-raw-append-for-high-volume-writers
     */
    private function resolveRawTarget(Register|string|int $register, Schema|string|int $schema): array
    {
        if (($register instanceof Register) === false) {
            $register = $this->registerMapper->find(id: $register, _rbac: false, _multitenancy: false);
        }

        if (($schema instanceof Schema) === false) {
            $schema = $this->scopedSchemaResolver->resolveSchemaWithin(register: $register, schemaRef: $schema);
        }

        return [$register, $schema];
    }//end resolveRawTarget()


    /**
     * Shape one caller-supplied row for the bulk handler.
     *
     * Metadata moves into `@self`, which is where the handler reads it from;
     * everything else stays a property. Keys the caller did not pass are left
     * to the handler's defaults: NULL owner and organisation, no expiry.
     *
     * @param array<string, mixed> $object A plain row as passed to appendObjectsRaw().
     *
     * @return array<string, mixed> The row in the handler's `@self` + properties shape.
     *
     * @spec openspec/specs/data-import-export/spec.md#requirement-raw-append-for-high-volume-writers
     */
    private function prepareRawRow(array $object): array
    {
        $self = ['uuid' => (string) ($object['uuid'] ?? Uuid::v4()->toRfc4122())];

        foreach (['expires', 'owner', 'organisation'] as $metadataKey) {
            if (array_key_exists($metadataKey, $object) === true) {
                $self[$metadataKey] = $object[$metadataKey];
            }
        }

        unset(
            $object['uuid'],
            $object['expires'],
            $object['owner'],
            $object['organisation'],
            $object['@self'],
            $object['object']
        );

        return ['@self' => $self] + $object;
    }//end prepareRawRow()

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
     * Migrate objects between registers and/or schemas.
     *
     * The caller needs create permission on the target schema. Only the source
     * objects the caller may read (RBAC and multitenancy of the source table) and
     * update are moved; any other requested id is reported as not found.
     *
     * @param ObjectMigration $migration Which objects move, from where to where, and how.
     *
     * @return array Migration report with success status, statistics, details, warnings, and errors.
     *
     * @throws OcpDoesNotExistException If register or schema not found.
     * @throws NotAuthorizedException If the caller may not create objects in the target schema.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function migrate(ObjectMigration $migration): array
    {
        $source       = $this->migrationSource(ref: $migration->source);
        $targetSchema = $this->utilityHandler->normalizeEntity(entity: $migration->target->schema, type: 'schema');
        if ($source === null || ($targetSchema instanceof Schema) === false) {
            throw new OcpDoesNotExistException('One or more registers/schemas not found');
        }

        $this->permissionHandler->checkPermission(schema: $targetSchema, action: 'create');

        $permitted = $this->migratableObjectIds(table: $source, objectIds: $migration->objectIds);
        $report    = $this->migrationHandler->migrateObjects(
            migration: new ObjectMigration(
                source: $migration->source,
                target: $migration->target,
                objectIds: $permitted,
                mapping: $migration->mapping
            )
        );

        return $this->reportUnmigratedAsNotFound(
            report: $report,
            objectIds: array_values(array_diff(array_map('strval', $migration->objectIds), $permitted))
        );
    }//end migrate()

    /**
     * The source table of a migration, or null when its register or schema does not resolve.
     *
     * @param RegisterSchemaRef $ref The source register and schema.
     *
     * @return RegisterSchema|null The resolved table.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function migrationSource(RegisterSchemaRef $ref): ?RegisterSchema
    {
        $register = $this->utilityHandler->normalizeEntity(entity: $ref->register, type: 'register');
        $schema   = $this->utilityHandler->normalizeEntity(entity: $ref->schema, type: 'schema');
        if (($register instanceof Register) === false || ($schema instanceof Schema) === false) {
            return null;
        }

        return new RegisterSchema(register: $register, schema: $schema);
    }//end migrationSource()

    /**
     * The requested source objects the caller may read and update.
     *
     * @param RegisterSchema $table     The source register and schema.
     * @param array          $objectIds The requested object uuids.
     *
     * @return array<int, string> The uuids that may be migrated.
     */
    private function migratableObjectIds(RegisterSchema $table, array $objectIds): array
    {
        $readable = $this->objectMapper->filterReadableUuids(
            registerId: (int) $table->register->getId(),
            schemaId: (int) $table->schema->getId(),
            uuids: array_values(array_map('strval', $objectIds))
        );

        $permitted = [];
        foreach ($this->objectMapper->findMultiple($readable) as $object) {
            $mayUpdate = $this->permissionHandler->hasPermission(
                schema: $table->schema,
                action: 'update',
                objectOwner: $object->getOwner(),
                object: $object
            );
            if ($mayUpdate === true) {
                $permitted[] = (string) $object->getUuid();
            }
        }

        return $permitted;
    }//end migratableObjectIds()

    /**
     * Add requested objects that were not migrated to a migration report as not found.
     *
     * @param array              $report    The migration report.
     * @param array<int, string> $objectIds The ids to report.
     *
     * @return array The report.
     */
    private function reportUnmigratedAsNotFound(array $report, array $objectIds): array
    {
        foreach ($objectIds as $objectId) {
            $report['details'][] = [
                'objectId'    => $objectId,
                'objectTitle' => null,
                'success'     => false,
                'error'       => "Object with ID {$objectId} not found",
            ];
            $report['statistics']['objectsFailed'] = (($report['statistics']['objectsFailed'] ?? 0) + 1);
            $report['errors'][] = "Failed to migrate object {$objectId}: Object not found";
        }

        $warning = 'Some objects failed to migrate. Check details for specific errors.';
        if ($objectIds !== [] && in_array($warning, ($report['warnings'] ?? []), true) === false) {
            $report['warnings'][] = $warning;
        }

        return $report;
    }//end reportUnmigratedAsNotFound()

    /**
     * Perform bulk delete operations on objects by UUID
     *
     * EVERY DELETE HERE IS A SOFT DELETE. This docblock used to claim the loop
     * escalated to a hard delete for a row already carrying a deleted timestamp;
     * it does not, and never did — it calls `DeleteObject::deleteObject()` without
     * `permanent: true`, which is the only thing that destroys a row. The claim
     * was unfalsifiable while the loop ran zero iterations. Deleting a trashed row
     * again re-tombstones it and reports success. Destroying a row for good is
     * `DELETE /api/deleted/{uuid}` or `occ openregister:objects:purge`, both of
     * which refuse an archival record (openregister#3428).
     *
     * EVERY REQUESTED UUID LANDS IN EXACTLY ONE OUTCOME BUCKET: the union of
     * 'deleted_uuids' and 'skipped_uuids' is the submitted set. It used not to be
     * — a UUID the permission filter removed, and a UUID whose handler answered
     * false rather than throwing, left the loop in neither — so a caller could be
     * told `requested 1, deleted 0, skipped 0` about a row that is still there.
     *
     * @param array $uuids         Array of object UUIDs to delete
     * @param bool  $_rbac         Whether to apply RBAC filtering
     * @param bool  $_multitenancy Whether to apply multi-organization filtering
     *
     * @return array Associative array with 'deleted_uuids', 'skipped_uuids',
     *               'skipped_reasons' and 'cascade_count' keys.
     *               'deleted_uuids' contains UUIDs of successfully deleted objects.
     *               'skipped_uuids' contains UUIDs skipped by a gate, a RESTRICT
     *               constraint or an error; it is the complement of 'deleted_uuids'.
     *               'skipped_reasons' maps each skipped UUID to a structured
     *               {error, message} pair, so an archival refusal is legible as
     *               something other than a RESTRICT block.
     *               'cascade_count' contains total count of objects affected by cascade operations.
     *
     * @phpstan-param  array<int, string> $uuids
     * @psalm-param    array<int, string> $uuids
     * @phpstan-return array{
     *     deleted_uuids: array<int, string>,
     *     skipped_uuids: array<int, string>,
     *     skipped_reasons: array<string, array<string, mixed>>,
     *     cascade_count: int
     * }
     * @psalm-return array{
     *     deleted_uuids: array<int, string>,
     *     skipped_uuids: array<int, string>,
     *     skipped_reasons: array<string, array<string, mixed>>,
     *     cascade_count: int
     * }
     *
     * @spec exclude Bulk-delete loop over deleteHandler->deleteObject(); per-object RESTRICT/CASCADE behavior owned by referential-integrity.
     */
    public function deleteObjects(array $uuids=[], bool $_rbac=true, bool $_multitenancy=true): array
    {
        return $this->deleteUuids(uuids: $uuids, flags: new AccessFlags(rbac: $_rbac, multitenancy: $_multitenancy));
    }//end deleteObjects()

    /**
     * Delete each permitted object of a bulk delete on its own, and report what was deleted and skipped.
     *
     * @param array       $uuids The UUIDs of the objects to delete.
     * @param AccessFlags $flags The caller's RBAC and multitenancy flags.
     *
     * @phpstan-param array<int, string> $uuids
     * @psalm-param   array<int, string> $uuids
     *
     * @return array The bulk delete report (see deleteObjects()).
     *
     * @spec exclude Private step of deleteObjects(); per-object RESTRICT/CASCADE behavior owned by referential-integrity.
     */
    private function deleteUuids(array $uuids, AccessFlags $flags): array
    {
        $this->discardPendingSchemaRef();
        if (empty($uuids) === true) {
            return $this->bulkDeleteReport(run: ['deleted' => [], 'skipped' => [], 'skippedReasons' => [], 'cascadeCount' => 0]);
        }

        $filteredUuids = $this->uuidsPermittedForDelete(uuids: $uuids, flags: $flags);
        $run           = $this->openBulkDelete(uuids: $filteredUuids, flags: $flags);
        $this->skipRefusedUuids(refused: array_diff($uuids, $filteredUuids), run: $run);

        // Each object goes through the delete handler on its own, so referential
        // integrity rules (CASCADE, SET_NULL, SET_DEFAULT, RESTRICT) are enforced
        // per object, and a refused object does not abort the rest.
        foreach ($filteredUuids as $uuid) {
            $this->deleteBulkRow(uuid: $uuid, run: $run);
        }

        $this->bulkCacheInvalidator()->afterBulkDelete(run: $run);

        return $this->bulkDeleteReport(run: $run);
    }//end deleteUuids()

    /**
     * Keeps the uuids this caller may delete, when RBAC or multi-organization filtering is on.
     *
     * @param array       $uuids The requested uuids.
     * @param AccessFlags $flags The caller's RBAC and multitenancy switches.
     *
     * @phpstan-param array<int, mixed> $uuids
     * @psalm-param   array<int, mixed> $uuids
     *
     * @return array The uuids that stay in the delete.
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function uuidsPermittedForDelete(array $uuids, AccessFlags $flags): array
    {
        if ($flags->rbac === false && OrganisationContext::multitenancy(requested: $flags->multitenancy) === false) {
            return $uuids;
        }

        return $this->permissionHandler->filterUuidsForPermissions(
            uuids: $uuids,
            _rbac: $flags->rbac,
            _multitenancy: $flags->multitenancy
        );
    }//end uuidsPermittedForDelete()

    /**
     * The state of one bulk delete.
     *
     * PERF: the scope AND the entity of every UUID resolve with ONE batched cross-table
     * lookup instead of a full magic-table scan per UUID. The register and schema caches
     * materialise each distinct pair at most once.
     *
     * @param array       $uuids The uuids the filter kept.
     * @param AccessFlags $flags The caller's RBAC and multitenancy switches.
     *
     * @phpstan-param array<int, mixed> $uuids
     * @psalm-param   array<int, mixed> $uuids
     *
     * @return array The run the delete steps share.
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function openBulkDelete(array $uuids, AccessFlags $flags): array
    {
        return [
            '_rbac'          => $flags->rbac,
            '_multitenancy'  => $flags->multitenancy,
            'preResolved'    => $this->batchResolveDeleteScopes(uuids: $uuids),
            'registerCache'  => [],
            'schemaCache'    => [],
            'deleted'        => [],
            'skipped'        => [],
            'skippedReasons' => [],
            'cascadeCount'   => 0,
            'pairs'          => [],
        ];
    }//end openBulkDelete()

    /**
     * Reports the uuids the filter removed as skipped.
     *
     * EVERY REQUESTED UUID MUST LAND IN EXACTLY ONE OUTCOME BUCKET. A UUID the filter
     * removes is a row the caller asked to delete and still believes it deleted: it is
     * reported with the reason, so `requested = deleted + skipped` holds.
     *
     * @param array $refused The uuids the filter removed.
     * @param array $run     The run the delete steps share.
     *
     * @phpstan-param array<int, mixed> $refused
     * @psalm-param   array<int, mixed> $refused
     *
     * @return void
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function skipRefusedUuids(array $refused, array &$run): void
    {
        foreach ($refused as $refusedUuid) {
            $run['skippedReasons'][$refusedUuid] = [
                'error'   => 'OBJECT_NOT_PERMITTED',
                'message' => 'Object is not visible to this user, or does not exist.',
            ];
        }

        $run['skipped'] = array_keys($run['skippedReasons']);
    }//end skipRefusedUuids()

    /**
     * The answer of one bulk delete.
     *
     * @param array $run The run the delete steps share.
     *
     * @return array{deleted_uuids: array, skipped_uuids: array, skipped_reasons: array, cascade_count: int}
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function bulkDeleteReport(array $run): array
    {
        return [
            'deleted_uuids'   => $run['deleted'],
            'skipped_uuids'   => $run['skipped'],
            'skipped_reasons' => $run['skippedReasons'],
            'cascade_count'   => $run['cascadeCount'],
        ];
    }//end bulkDeleteReport()

    /**
     * Delete one row of a bulk delete, recording it as deleted or skipped with a reason.
     *
     * @param mixed $uuid The requested identifier, as the caller passed it.
     * @param array      $run  The bulk delete run: flags, pre-resolved rows, caches and outcome buckets.
     *
     * @return void
     *
     * @spec exclude Bulk-delete loop over deleteHandler->deleteObject(); per-object RESTRICT/CASCADE behavior owned by referential-integrity.
     */
    private function deleteBulkRow(mixed $uuid, array &$run): void
    {
        $reason = $this->bulkDeleteOutcome(uuid: $uuid, run: $run);
        if ($reason !== null) {
            $run['skipped'][]             = $uuid;
            $run['skippedReasons'][$uuid] = $reason;
        }
    }//end deleteBulkRow()

    /**
     * Delete one row of a bulk delete and say why it was skipped, if it was.
     *
     * @param mixed $uuid The requested identifier, as the caller passed it.
     * @param array      $run  The bulk delete run.
     *
     * @return array|null The skip reason, or null when the row was deleted.
     *
     * @spec exclude Bulk-delete loop over deleteHandler->deleteObject(); per-object RESTRICT/CASCADE behavior owned by referential-integrity.
     */
    private function bulkDeleteOutcome(mixed $uuid, array &$run): ?array
    {
        try {
            return $this->deleteBulkTarget(uuid: $uuid, run: $run);
        } catch (\OCA\OpenRegister\Exception\ReferentialIntegrityException $e) {
            // RESTRICT blocks should not abort the entire bulk operation.
            $this->logger->info(
                message: '[ObjectService] Bulk delete skipped object due to RESTRICT constraint',
                context: [
                    'uuid'     => $uuid,
                    'blockers' => count($e->getAnalysis()->blockers),
                ]
            );
            return ['error' => 'REFERENTIAL_INTEGRITY_RESTRICT', 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            // Other failures (transaction rollback, etc.) are logged and skipped.
            $this->logger->warning(
                message: '[ObjectService] Bulk delete failed for object',
                context: ['uuid' => $uuid, 'error' => $e->getMessage()]
            );
            return ['error' => 'DELETE_FAILED', 'message' => $e->getMessage()];
        }//end try
    }//end bulkDeleteOutcome()

    /**
     * Asks the refusals about one row, then deletes it.
     *
     * The handler reports some refusals by RETURN VALUE rather than by throwing: a
     * read-only object source declines this way, and so does its own catch when the
     * inner delete fails.
     *
     * @param mixed $uuid The uuid to delete.
     * @param array $run  The run the delete steps share.
     *
     * @return array|null The reason this row was skipped, or null when it was deleted.
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function deleteBulkTarget(mixed $uuid, array &$run): ?array
    {
        $scope   = $this->resolveBulkDeleteScope(uuid: $uuid, run: $run);
        $refusal = $this->bulkDeleteRefusal(scope: $scope, run: $run);
        if ($refusal !== null) {
            return $refusal;
        }

        if ($this->callDeleteHandler(scope: $scope, run: $run) === true) {
            $this->recordBulkDeleted(scope: $scope, run: $run);
            return null;
        }

        $this->logger->warning(
            message: '[ObjectService] Bulk delete handler refused object',
            context: ['uuid' => $uuid]
        );
        return ['error' => 'DELETE_REFUSED', 'message' => 'The delete handler did not delete this object.'];
    }//end deleteBulkTarget()

    /**
     * Hands one row to the delete handler.
     *
     * @param array $scope The row and its register and schema.
     * @param array $run   The run the delete steps share.
     *
     * @return bool Whether the handler deleted the row.
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function callDeleteHandler(array $scope, array $run): bool
    {
        return $this->deleteHandler->deleteObject(
            request: new DeletionRequest(
                register: $scope['register'],
                schema: $scope['schema'],
                uuid: $scope['uuid'],
                options: new DeletionOptions(preResolved: $scope['preResolved'], permanent: $scope['draft'])
            ),
            _rbac: $run['_rbac'],
            _multitenancy: $run['_multitenancy']
        ) === true;
    }//end callDeleteHandler()

    /**
     * Resolve a bulk-delete row's register, schema and entity before deleting it.
     *
     * The delete handler returns only a bool, so the scope is read first: from
     * the batch resolution, or else from a per-uuid mapper find (no read audit
     * trail) that also matches non-uuid identifier shapes. Both include
     * soft-deleted rows. A batch-resolved row hands its entity and concrete
     * scope to the handler, so it skips its own cross-table re-find; when the
     * scope entities cannot be materialised the current scope is used.
     *
     * @param mixed $uuid The requested identifier, as the caller passed it.
     * @param array      $run  The bulk delete run.
     *
     * @return array The scope: uuid, registerId, schemaId, row, the register and schema for the
     *               handler, the pre-resolved entity it may reuse, and whether the row is a draft.
     *
     * @spec exclude Bulk-delete loop over deleteHandler->deleteObject(); per-object RESTRICT/CASCADE behavior owned by referential-integrity.
     */
    private function resolveBulkDeleteScope(mixed $uuid, array &$run): array
    {
        $scope = [
            'uuid'        => $uuid,
            'registerId'  => null,
            'schemaId'    => null,
            'row'         => ($run['preResolved'][$uuid] ?? null),
            'register'    => $this->currentRegister,
            'schema'      => $this->currentSchema,
            'preResolved' => null,
        ];
        if ($scope['row'] === null) {
            $scope = $this->findBulkDeleteRow(scope: $scope, run: $run);
        }

        if ($scope['row'] !== null) {
            $scope['registerId'] = $scope['row']->getRegister();
            $scope['schemaId']   = $scope['row']->getSchema();
        }

        if (isset($run['preResolved'][$uuid]) === true) {
            $scope = $this->anchorBulkDeleteScope(scope: $scope, run: $run);
        }

        $scope['draft'] = ($scope['row'] !== null && $scope['row']->isDraft() === true);
        return $scope;
    }//end resolveBulkDeleteScope()

    /**
     * Points a pre-resolved row at its own register and schema, when both load.
     *
     * @param array $scope The row and its register and schema.
     * @param array $run   The run the delete steps share.
     *
     * @return array The scope.
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function anchorBulkDeleteScope(array $scope, array &$run): array
    {
        $scopeEntities = $this->loadDeleteScopeEntities(scope: $scope, run: $run);
        if ($scopeEntities !== null) {
            [$scope['register'], $scope['schema']] = $scopeEntities;
            $scope['preResolved'] = $scope['row'];
        }

        return $scope;
    }//end anchorBulkDeleteScope()

    /**
     * Find a bulk-delete row the batch resolution missed, with the caller's flags.
     *
     * A row that cannot be found (already gone or not visible) is logged and
     * keeps no scope.
     *
     * @param array $scope The row's scope so far.
     * @param array $run   The bulk delete run.
     *
     * @return array The scope with the found row, if any.
     *
     * @spec exclude Bulk-delete loop over deleteHandler->deleteObject(); per-object RESTRICT/CASCADE behavior owned by referential-integrity.
     */
    private function findBulkDeleteRow(array $scope, array $run): array
    {
        try {
            $scope['row'] = $this->objectMapper->find(
                identifier: $scope['uuid'],
                register: $this->currentRegister,
                schema: $this->currentSchema,
                includeDeleted: true,
                _rbac: $run['_rbac'],
                _multitenancy: $run['_multitenancy']
            );
        } catch (\Throwable $resolveError) {
            $this->logger->warning(
                message: '[ObjectService] Bulk delete could not resolve register/schema scope for cache invalidation',
                context: [
                    'uuid'  => $scope['uuid'],
                    'error' => $resolveError->getMessage(),
                ]
            );
        }

        return $scope;
    }//end findBulkDeleteRow()

    /**
     * Why a bulk-delete row may not be deleted, or null when it may.
     *
     * The bulk loop calls the delete handler directly, so it asks deleteObject()'s
     * questions itself, of the ROW's OWN schema (a bulk delete spans magic tables):
     * a row whose schema cannot be resolved is refused, another user's draft is
     * refused (decisions 180 and 216), and a non-draft row on an archival schema
     * is refused. A draft is removed for good and is not an archival record.
     *
     * @param array $scope The row's scope.
     * @param array $run   The bulk delete run.
     *
     * @return array|null The skip reason, or null when the row may be deleted.
     *
     * @spec exclude Bulk-delete loop over deleteHandler->deleteObject(); per-object RESTRICT/CASCADE behavior owned by referential-integrity.
     */
    private function bulkDeleteRefusal(array $scope, array &$run): ?array
    {
        $scope['gateSchema'] = $this->loadRowSchema(schemaId: $scope['schemaId'], schemaCache: $run['schemaCache']);
        if ($scope['gateSchema'] === null) {
            return [
                'error'   => 'SCHEMA_UNRESOLVED',
                'message' => 'The schema of this object could not be resolved, '
                    .'so its archival status could not be established.',
            ];
        }

        return ($this->draftDeleteRefusal(scope: $scope, run: $run)
            ?? $this->sharedDataDeleteRefusal(scope: $scope, run: $run)
            ?? $this->bulkDeleteStateRefusal(row: $scope['row'], schema: $scope['gateSchema'])
            ?? $this->archivalDeleteRefusal(scope: $scope));
    }//end bulkDeleteRefusal()

    /**
     * Refuses a draft the caller may not see.
     *
     * @param array $scope The row, its schema and whether it is a draft.
     * @param array $run   The run the delete steps share.
     *
     * @return array|null The refusal, or null.
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function draftDeleteRefusal(array $scope, array $run): ?array
    {
        if ($scope['draft'] === true && $run['_rbac'] === true
            && $this->draftKeptFromCaller(object: $scope['row'], schema: $scope['gateSchema'], user: $this->userSession->getUser()) === true
        ) {
            return [
                'error'   => 'OBJECT_NOT_PERMITTED',
                'message' => 'Object is not visible to this user, or does not exist.',
            ];
        }

        return null;
    }//end draftDeleteRefusal()

    /**
     * Refuses a row of shared master data this caller's organisation does not own.
     *
     * @param array $scope The row, its schema and its register.
     * @param array $run   The run the delete steps share.
     *
     * @return array|null The refusal, or null.
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function sharedDataDeleteRefusal(array $scope, array $run): ?array
    {
        if ($this->deleteAnswersToOrganisation(rbac: $run['_rbac'], multitenancy: $run['_multitenancy']) === false) {
            return null;
        }

        try {
            $this->rejectSharedMasterDataDelete(
                registerId: ($this->rowRegisterId(row: $scope['row']) ?? $scope['register']?->getId()),
                schemaId: $scope['gateSchema']->getId()
            );
        } catch (SharedMasterDataWriteException $sharedRefusal) {
            return ['error' => 'OBJECT_NOT_PERMITTED', 'message' => $sharedRefusal->getMessage()];
        }

        return null;
    }//end sharedDataDeleteRefusal()

    /**
     * Refuses a row the archival rules keep, unless it is a draft.
     *
     * @param array $scope The row, its schema and whether it is a draft.
     *
     * @return array|null The refusal, or null.
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function archivalDeleteRefusal(array $scope): ?array
    {
        if ($scope['draft'] === true) {
            return null;
        }

        try {
            $this->rejectIfArchivalImmutable(schema: $scope['gateSchema'], retentionSweep: false);
        } catch (ArchivalImmutableException $archivalRefusal) {
            return $archivalRefusal->toResponseBody();
        }

        return null;
    }//end archivalDeleteRefusal()

    /**
     * Why a bulk-delete row may not go whatever its draft status, or null.
     *
     * The same two refusals deleteObject() gives, asked of the row and its own schema.
     *
     * @param ObjectEntity $row    The stored row.
     * @param Schema       $schema The row's schema.
     *
     * @return array|null The skip reason, or null.
     */
    private function bulkDeleteStateRefusal(ObjectEntity $row, Schema $schema): ?array
    {
        if ($this->isTransferred(object: $row) === true) {
            return ['error' => 'OBJECT_TRANSFERRED', 'message' => self::TRANSFERRED_MESSAGE];
        }

        if ($schema->isAppendOnly() === true) {
            return $this->appendOnlyDeleteRefusal(schema: $schema)->toResponseBody();
        }

        return null;
    }//end bulkDeleteStateRefusal()

    /**
     * Record a deleted bulk-delete row, its cascade count and its scope pair for cache invalidation.
     *
     * The handler resets its cascade count at the start of every root delete,
     * so it is read exactly once per successful delete. CacheHandler expects
     * int ids; entity getters return string ids.
     *
     * @param array $scope The deleted row's scope.
     * @param array $run   The bulk delete run.
     *
     * @return void
     *
     * @spec exclude Bulk-delete loop over deleteHandler->deleteObject(); per-object RESTRICT/CASCADE behavior owned by referential-integrity.
     */
    private function recordBulkDeleted(array $scope, array &$run): void
    {
        $run['deleted'][]     = $scope['uuid'];
        $run['cascadeCount'] += $this->deleteHandler->getLastCascadeCount();
        if ($scope['schemaId'] === null || $scope['schemaId'] === '') {
            return;
        }

        $registerIdInt = null;
        if ($scope['registerId'] !== null && $scope['registerId'] !== '') {
            $registerIdInt = (int) $scope['registerId'];
        }

        $schemaIdInt = (int) $scope['schemaId'];
        $run['pairs'][($registerIdInt ?? 'null').':'.$schemaIdInt] = [
            'registerId' => $registerIdInt,
            'schemaId'   => $schemaIdInt,
        ];
    }//end recordBulkDeleted()

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
            $entities = $this->objectMapper->findStoredAcrossAllMagicTables(
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
     * @param array $scope The row, with the register and schema ids it names.
     * @param array $run   The run the delete steps share, holding the caches.
     *
     * @return array{0: \OCA\OpenRegister\Db\Register, 1: \OCA\OpenRegister\Db\Schema}|null
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function loadDeleteScopeEntities(array $scope, array &$run): ?array
    {
        if (is_numeric($scope['registerId']) === false || is_numeric($scope['schemaId']) === false) {
            return null;
        }

        $registerKey = (int) $scope['registerId'];
        $schemaKey   = (int) $scope['schemaId'];

        try {
            return [
                $this->cachedDeleteRegister(registerKey: $registerKey, registerCache: $run['registerCache']),
                $this->cachedDeleteSchema(schemaKey: $schemaKey, schemaCache: $run['schemaCache']),
            ];
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
    }//end loadDeleteScopeEntities()

    /**
     * The register of a key, read once per bulk delete.
     *
     * @param int   $registerKey   The register id.
     * @param array $registerCache Cache of Register entities keyed by int id.
     *
     * @return Register The register.
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function cachedDeleteRegister(int $registerKey, array &$registerCache): Register
    {
        $registerCache[$registerKey] ??= $this->registerMapper->find(
            $registerKey,
            _rbac: false,
            _multitenancy: false
        );

        return $registerCache[$registerKey];
    }//end cachedDeleteRegister()

    /**
     * The schema of a key, read once per bulk delete.
     *
     * @param int   $schemaKey   The schema id.
     * @param array $schemaCache Cache of Schema entities keyed by int id.
     *
     * @return Schema The schema.
     *
     * @spec exclude Private step of deleteObjects(); the delete rules live in the units it calls.
     */
    private function cachedDeleteSchema(int $schemaKey, array &$schemaCache): Schema
    {
        $schemaCache[$schemaKey] ??= $this->schemaMapper->find(
            $schemaKey,
            _rbac: false,
            _multitenancy: false
        );

        return $schemaCache[$schemaKey];
    }//end cachedDeleteSchema()

    /**
     * Materialise the Schema a bulk-delete candidate actually belongs to.
     *
     * Separate from {@see loadDeleteScopeEntities()} because the archival gate
     * needs the row's schema on BOTH delete paths, including the legacy one
     * that hands the handler no pre-resolved entity — there the register may be
     * unknown while the schema is not, and refusing the delete because the
     * register did not materialise would be a refusal for the wrong reason.
     *
     * Shares the caller's per-operation schema cache, so a batch of rows on one
     * schema costs one lookup. Returns null when the id is absent or does not
     * resolve; the caller decides what that means.
     *
     * @param string|int|null $schemaId    The schema id carried by the resolved row.
     * @param array           $schemaCache Cache of Schema entities keyed by int id.
     *
     * @return Schema|null The schema, or null when it cannot be established.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md
     */
    private function loadRowSchema(string | int | null $schemaId, array &$schemaCache): ?Schema
    {
        if (is_numeric($schemaId) === false) {
            return null;
        }

        $schemaKey = (int) $schemaId;

        try {
            return $this->cachedDeleteSchema(schemaKey: $schemaKey, schemaCache: $schemaCache);
        } catch (\Throwable $schemaError) {
            $this->logger->warning(
                message: '[ObjectService] Bulk delete could not resolve the schema of a candidate row',
                context: [
                    'schemaId' => $schemaKey,
                    'error'    => $schemaError->getMessage(),
                ]
            );
            return null;
        }
    }//end loadRowSchema()


    /**
     * Delete all objects of one register and schema.
     *
     * @param RegisterSchemaRef $table      The register and schema ids whose objects are deleted.
     * @param bool              $hardDelete Whether to force hard delete (default: false)
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
    public function deleteObjectsOfTable(RegisterSchemaRef $table, bool $hardDelete=false): array
    {
        $schemaDeletionService = $this->container->get(\OCA\OpenRegister\Service\SchemaDeletionService::class);

        return $schemaDeletionService->deleteObjectsBySchema(
            table: new RegisterSchemaRef(register: (int) $table->register, schema: (int) $table->schema),
            options: new TableDeletionOptions(hardDelete: $hardDelete, triggeredBy: \OCA\OpenRegister\Service\SchemaDeletionService::TRIGGER_BULK_DELETE)
        );
    }//end deleteObjectsOfTable()

    /**
     * Delete all objects belonging to a specific register
     *
     * Empties every magic table of every schema the register lists, in one
     * transaction, snapshotting each object to the audit trail first. Refuses the
     * whole request with ArchivalImmutableException, before touching any row, when
     * any of those schemas is archival. The work lives in SchemaDeletionService,
     * resolved lazily for the reason deleteObjectsOfTable() gives.
     *
     * @param int  $registerId The ID of the register whose objects should be deleted
     * @param bool $hardDelete Whether to force hard delete (default: false)
     *
     * @return (int|string[])[]
     *
     * @throws \OCA\OpenRegister\Exception\ArchivalImmutableException If any schema of the register is archival
     * @throws \Exception If the deletion operation fails (nothing is deleted)
     *
     * @phpstan-return array{deleted_count: int, deleted_uuids: array<int, string>, register_id: int}
     *
     * @psalm-return array{deleted_count: int<min, max>, deleted_uuids: array<int, string>, register_id: int}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The hard/soft toggle mirrors the mapper primitive it wraps.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-schema-wide-object-deletion-is-refused-on-an-archival-schema
     */
    public function deleteObjectsByRegister(int $registerId, bool $hardDelete=false): array
    {
        $register = $this->registerMapper->find(id: $registerId);
        $schemaDeletionService = $this->container->get(\OCA\OpenRegister\Service\SchemaDeletionService::class);

        return $schemaDeletionService->deleteObjectsByRegister(
            register: $register,
            options: new TableDeletionOptions(hardDelete: $hardDelete)
        );
    }//end deleteObjectsByRegister()

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
        $lookup = (new RelationLookup(objectId: $objectId, query: $query))
            ->filteredBy(rbac: $_rbac, multitenancy: $_multitenancy)
            ->pinnedTo(registerId: $this->currentRegister?->getId(), schemaId: $this->currentSchema?->getId());
        return $this->relationHandler->getUses(lookup: $lookup);
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
        $lookup = (new RelationLookup(objectId: $objectId, query: $query))
            ->filteredBy(rbac: $_rbac, multitenancy: $_multitenancy)
            ->pinnedTo(registerId: $this->currentRegister?->getId(), schemaId: $this->currentSchema?->getId());
        return $this->relationHandler->getUsedBy(lookup: $lookup);
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

    // =========================================================================
    // CRUD HANDLER DELEGATION METHODS
    // =========================================================================

    /**
     * List objects with filtering and pagination
     *
     * @param array $query         Search query parameters
     * @param bool  $_rbac         Apply RBAC filters
     * @param bool  $_multitenancy Apply multitenancy filters
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity[]|int
     *
     * @throws \Exception If listing fails
     *
     * @psalm-return int<0, max>|list<\OCA\OpenRegister\Db\ObjectEntity>
     *
     * @spec exclude Facade alias delegating to searchObjects(); listing behavior owned by zoeken-filteren.
     */
    public function listObjects(array $query=[], bool $_rbac=true, bool $_multitenancy=true): array|int
    {
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
     * REPLACE an existing object's data wholesale. This does NOT merge.
     *
     * PUT semantics, inherited from `saveObject()`: `$data` becomes the object's
     * data in full, so a stored property absent from `$data` is written away
     * rather than left alone. There is no read of the existing object here — the
     * commented-out `find()` below is the whole history of that idea — so a
     * one-key payload stores a one-key object and reports success.
     *
     * Callers holding a PARTIAL payload want `patchObject()`, which reads,
     * merges and then saves. Both are published on `ObjectServiceInterface`;
     * the contract's docblock for this method used to say "apply a partial
     * update", which is the opposite of the behaviour and is what sent at least
     * one consuming app down the erasing path.
     *
     * Replace semantics are deliberate and must not change: existing callers
     * pass a complete object and rely on an omitted property being cleared.
     *
     * @param string $objectId      Object ID or UUID
     * @param array  $data          The object's COMPLETE new data. Anything omitted is dropped.
     * @param bool   $_rbac         Apply RBAC checks
     * @param bool   $_multitenancy Apply multitenancy filtering
     *
     * @return ObjectEntity Replaced object entity
     *
     * @throws \Exception If update fails
     *
     * @see self::patchObject() for the merging counterpart.
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
     * Patch an existing object (PATCH-semantic partial update).
     *
     * This is the fleet's supported PATCH-semantic write path. `saveObject()` is
     * PUT-semantic — a property absent from the payload is written as null — so
     * every partial-write caller either merges first or silently nulls live
     * data. That merge belongs here, once, rather than in each caller.
     *
     * MERGE RULES (RFC 7386 shaped):
     *  - a key present with a non-null value overwrites the stored value;
     *  - a key absent from the payload leaves the stored value untouched;
     *  - a key present with an explicit `null` clears the stored value, so
     *    "unset this" is expressible and distinguishable from "not mentioned";
     *  - two associative arrays merge recursively on the same three rules;
     *  - lists (JSON arrays) are replaced wholesale, never element-merged —
     *    there is no stable identity to merge elements on and a positional
     *    merge corrupts any reordered list.
     *
     * The identifier is NOT cast to int. It is resolved as a uuid, a slug or a
     * numeric id, scoped to exactly one magic table when both `$register` and
     * `$schema` are supplied (the #1638 defect class).
     *
     * The merged result goes through `saveObject()`, so schema validation, the
     * audit trail and event dispatch all apply — a patch is a save of a
     * different shape, not a privileged shortcut around one.
     *
     * @param string                   $objectId      Object id, uuid or slug.
     * @param array                    $data          Partial object data to merge.
     * @param Register|string|int|null $register      Optional register scope (object, id, uuid or slug).
     * @param Schema|string|int|null   $schema        Optional schema scope (object, id, uuid or slug).
     * @param bool                     $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool                     $_multitenancy Whether to apply multitenancy filtering (default: true).
     * @param IUser|null               $currentUser   Explicit acting user, forwarded to `saveObject()`.
     *                                                Non-HTTP callers (cron, flow runs, import pipelines)
     *                                                MUST pass one to avoid the default-deny fall-through.
     *
     * @return ObjectEntity Patched object entity
     *
     * @throws \Exception If patch fails
     *
     * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
     */
    public function patchObject(
        string $objectId,
        array $data,
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?IUser $currentUser=null
    ): ObjectEntity {
        $target = new ObjectFindTarget(register: $register, schema: $schema);
        $patch  = new ObjectPatch(objectId: $objectId, data: $data, target: $target, currentUser: $currentUser);
        $access = new AccessFlags(rbac: $_rbac, multitenancy: $_multitenancy);
        return $this->preservingScope(operation: fn (): ObjectEntity => $this->patchWithinScope(patch: $patch, access: $access));
    }//end patchObject()

    /**
     * Apply one patch in the scope patchObject() keeps: resolve the target, merge the change and save it.
     *
     * A PATCH SCOPES ITSELF; IT DOES NOT SCOPE THE NEXT CALLER. The saveObject() it delegates
     * to restores too, so without preservingScope() the only context left behind would be the
     * one this patch set itself. See restoreScopeContext().
     *
     * @param ObjectPatch $patch  The object, the change, where to find it and who acts.
     * @param AccessFlags $access The caller's RBAC and multitenancy flags.
     *
     * @return ObjectEntity The saved object.
     *
     * @throws \Exception If the object is not found or the save fails.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function patchWithinScope(ObjectPatch $patch, AccessFlags $access): ObjectEntity
    {
        // Resolve the caller's scope onto the service context first, so the
        // lookup below and the save that follows both see the same one.
        $this->setContextFromParameters(register: $patch->target->register, schema: $patch->target->schema);

        $existing = $this->findPatchTarget(objectId: $patch->objectId, target: $patch->target);

        // Address the save at the object we actually resolved, not at whatever
        // form of the identifier the caller happened to hold.
        $merged       = $this->patchedBody(existing: $existing, data: $patch->data);
        $merged['id'] = ($existing->getUuid() ?? $patch->objectId);

        return $this->saveObject(
            object: $merged,
            register: $patch->target->register,
            schema: $patch->target->schema,
            _rbac: $access->rbac,
            _multitenancy: $access->multitenancy,
            currentUser: $patch->currentUser
        );
    }//end patchWithinScope()

    /**
     * Finds the stored object a patch applies to.
     *
     * A scoped lookup when both halves of the scope are known: that targets exactly one
     * magic table. Otherwise the legacy cross-table resolve, which every existing caller
     * relies on. The identifier is passed through unchanged: casting a uuid to int
     * resolves to an unrelated row.
     *
     * @param string           $objectId The identifier the caller holds.
     * @param ObjectFindTarget $target   The register and schema the call names.
     *
     * @return ObjectEntity The stored object.
     *
     * @spec exclude Private step of patchObject(); the rights are checked by the saveObject() it ends in.
     */
    private function findPatchTarget(string $objectId, ObjectFindTarget $target): ObjectEntity
    {
        $scopedRegister = null;
        $scopedSchema   = null;
        if ($target->register !== null && $target->schema !== null) {
            $scopedRegister = $this->currentRegister;
            $scopedSchema   = $this->currentSchema;
        }

        return $this->objectMapper->find(
            identifier: $objectId,
            register: $scopedRegister,
            schema: $scopedSchema
        );
    }//end findPatchTarget()

    /**
     * Merges a patch over the stored data and re-encodes what the read decoded.
     *
     * A `type: string` property whose stored value looks like JSON comes back from
     * getObject() as an ARRAY (SchemaTypeConverter::convertString), and feeding that array
     * back in makes validation refuse a patch that never mentioned the property. Only keys
     * the caller did NOT supply are restored: a value the caller sent is theirs, and the
     * write has to judge it as sent.
     *
     * @param ObjectEntity $existing The stored object.
     * @param array        $data     The fields the caller supplied.
     *
     * @phpstan-param array<string, mixed> $data
     * @psalm-param   array<string, mixed> $data
     *
     * @return array The body to save.
     *
     * @phpstan-return array<string, mixed>
     * @psalm-return   array<string, mixed>
     *
     * @spec exclude Private step of patchObject(); merge behaviour owned by PatchDataMerge.
     */
    private function patchedBody(ObjectEntity $existing, array $data): array
    {
        $merged = (new PatchDataMerge())->merge(stored: (array) $existing->getObject(), patch: $data);

        $restorer = $this->stringTypeRestorer(existing: $existing);
        if ($restorer === null) {
            return $merged;
        }

        return $restorer['converter']->restoreStringTypedValues(
            data: $merged,
            properties: ($restorer['schema']->getProperties() ?? []),
            suppliedKeys: array_keys($data)
        );
    }//end patchedBody()

    /**
     * The schema and converter that restore string-typed values, when both can be had.
     *
     * `SchemaTypeConverter::restoreStringTypedValues()` owns the rule. All this does is
     * find the schema whose property declarations say which keys are string-typed.
     *
     * The schema lookup runs with RBAC and multitenancy off: it reads type declarations
     * to persist an object correctly, not data on the caller's behalf, and `saveObject()`
     * still applies every check to the write itself. Leaving them on would make the repair
     * depend on the caller's schema permissions, so the defect would come back for exactly
     * the callers least able to diagnose it.
     *
     * If the schema or the converter cannot be resolved the data stays unchanged, which
     * leaves the pre-existing loud validation refusal in place. That is the intended
     * failure direction: never swallow, never silently rewrite. A container may also answer
     * with something other than a converter, or with nothing at all; calling a method on
     * null would turn a patch into a fatal.
     *
     * @param ObjectEntity $existing The object as it was read.
     *
     * @return array{schema: Schema, converter: SchemaTypeConverter}|null The pair, or null.
     *
     * @spec openspec/specs/schema-driven-read-coercion/spec.md
     */
    private function stringTypeRestorer(ObjectEntity $existing): ?array
    {
        $schemaId = $existing->getSchema();
        if ($schemaId === null) {
            return null;
        }

        try {
            $schema    = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
            $converter = $this->container->get(SchemaTypeConverter::class);
        } catch (\Throwable $restoreError) {
            $this->logStringRestoreFailure(schemaId: $schemaId, error: $restoreError);
            return null;
        }

        if (($converter instanceof SchemaTypeConverter) === false) {
            return null;
        }

        return ['schema' => $schema, 'converter' => $converter];
    }//end stringTypeRestorer()

    /**
     * Records that string-typed values could not be restored before a save.
     *
     * @param int|string $schemaId The schema of the object.
     * @param \Throwable $error    What went wrong.
     *
     * @return void
     *
     * @spec exclude Logging step of patchObject(); no business rule.
     */
    private function logStringRestoreFailure(int | string $schemaId, \Throwable $error): void
    {
        $this->logger->warning(
            message: '[ObjectService] Could not restore string-typed values before the save',
            context: [
                'file' => __FILE__,
                'line' => __LINE__,
                'schema' => $schemaId,
                'exception' => $error->getMessage(),
            ]
        );
    }//end logStringRestoreFailure()

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
     * Validate and save one page of a register and schema's objects; an unbounded query processes them all.
     *
     * @param RegisterSchemaRef $table The register and schema ids.
     * @param ListQuery         $query The page: its limit (or unbounded) and offset.
     *
     * @return array{processed: int, updated: int, failed: int, total: int, errors: array} Validation statistics
     *
     * @spec exclude Delegation to ValidationHandler::validateAndSaveObjectsBySchema() with a saveObject callback; handler owns the loop.
     */
    public function validateAndSaveTable(RegisterSchemaRef $table, ListQuery $query): array
    {
        return $this->validationHandler->validateAndSaveObjectsBySchema(
            revalidation: new SchemaRevalidation(table: $table, query: $query, saveCallback: [$this, 'saveObject'])
        );
    }//end validateAndSaveTable()

    /**
     * Reset the current register, schema, and object context.
     *
     * Called by external apps (e.g. OpenConnector) before performing a fresh
     * lookup to prevent stale context from a previous request bleeding through.
     *
     * @return void
     *
     * @spec exclude Context-reset setter nulling the current register/schema/object fields; no business rule.
     *
     * @orphaned-write-capability exclude Live, called only from a sibling repo.
     *   openconnector's EndpointService calls it from six sites (lines 1480,
     *   2741, 2750, 3227, 3229 and 3684) before each fresh lookup. CI checks
     *   out this repo alone, so the caller index cannot see them and the method
     *   reads as dead. This is the exact case hydra#106 recorded against this
     *   very method: acting on that verdict would have broken endpoint
     *   rendering.
     */
    public function clearCurrents(): void
    {
        $this->currentRegister  = null;
        $this->currentSchema    = null;
        $this->currentSchemaRef = null;
        $this->currentObject    = null;
    }//end clearCurrents()

    /**
     * Return the internal object-validation handler.
     *
     * Exposed so adapters and external services can run validation without
     * depending on ObjectService internals directly.
     *
     * @return ValidateObjectInterface
     */
    public function getValidateHandler(): ValidateObjectInterface
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
