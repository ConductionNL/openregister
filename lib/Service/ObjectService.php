<?php

/**
 * OpenRegister ObjectService
 *
 * Service class for managing objects in the OpenRegister application.
 *
 * This service acts as a facade for the various object handlers,
 * coordinating operations between them and maintaining state.
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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\FacetableAnalyzer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\Object\BulkOperationsHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\PublishHandler;
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
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCP\AppFramework\Db\DoesNotExistException as OcpDoesNotExistException;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Async;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;
use OCP\AppFramework\IAppContainer;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

use function React\Promise\all;

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

    // **REMOVED**: Distributed caching mechanisms removed since SOLR is now our index.
    // **REMOVED**: Cache TTL constants removed since SOLR is now our index.

    /**
     * Constructor for ObjectService.
     *
     * @param DataManipulationHandler        $dataManipHandler    Handler for data manipulation operations.
     * @param DeleteObject                   $deleteHandler       Handler for object deletion.
     * @param GetObject                      $getHandler          Handler for object retrieval.
     * @param PerformanceHandler             $performanceHandler  Handler for performance operations.
     * @param PermissionHandler              $permissionHandler   Handler for permission checks.
     * @param RenderObject                   $renderHandler       Handler for object rendering.
     * @param SaveObject                     $saveHandler         Handler for individual object saving.
     * @param SaveObjects                    $saveObjectsHandler  Handler for bulk object saving operations.
     * @param SearchQueryHandler             $searchQueryHandler  Handler for search query operations.
     * @param ValidateObject                 $validateHandler     Handler for object validation.
     * @param LockHandler                    $lockHandler         Handler for object locking.
     * @param AuditHandler                   $auditHandler        Handler for audit trail operations.
     * @param PublishHandler                 $publishHandler      Handler for publication workflow.
     * @param RelationHandler                $relationHandler     Handler for object relationships.
     * @param MergeHandler                   $mergeHandler        Handler for merge and migration.
     * @param BulkOperationsHandler          $bulkOpsHandler      Handler for bulk operations.
     * @param FacetHandler                   $facetHandler        Handler for facet operations.
     * @param MetadataHandler                $metadataHandler     Handler for metadata operations.
     * @param PerformanceOptimizationHandler $perfOptHandler      Handler for performance optimization.
     * @param QueryHandler                   $queryHandler        Handler for query operations.
     * @param RevertHandler                  $revertHandler       Handler for revert operations.
     * @param UtilityHandler                 $utilityHandler      Handler for utility operations.
     * @param ValidationHandler              $validationHandler   Handler for validation operations.
     * @param CascadingHandler               $cascadingHandler    Handler for cascading operations.
     * @param MigrationHandler               $migrationHandler    Handler for migration operations.
     * @param RegisterMapper                 $registerMapper      Mapper for register operations.
     * @param SchemaMapper                   $schemaMapper        Mapper for schema operations.
     * @param ViewMapper                     $viewMapper          Mapper for view operations.
     * @param ObjectEntityMapper             $objectEntityMapper  Mapper for object entity operations.
     * @param FileService                    $fileService         Service for file operations.
     * @param IUserSession                   $userSession         User session for getting current user.
     * @param SearchTrailService             $searchTrailService  Service for search trail operations.
     * @param IGroupManager                  $groupManager        Group manager for checking user groups.
     * @param IUserManager                   $userManager         User manager for getting user objects.
     * @param OrganisationService            $organisationService Service for organisation operations.
     * @param LoggerInterface                $logger              Logger for performance monitoring.
     * @param CacheHandler                   $cacheHandler        Service for entity and query caching.
     * @param SettingsService                $settingsService     Service for settings operations.
     * @param IAppContainer                  $container           Application container.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     */
    public function __construct(
        // Legacy handlers - TESTING:..
        private readonly DataManipulationHandler $dataManipHandler,
        private readonly DeleteObject $deleteHandler,
        private readonly GetObject $getHandler,
        private readonly PerformanceHandler $performanceHandler,
        private readonly PermissionHandler $permissionHandler,
        private readonly RenderObject $renderHandler,
        private readonly SaveObject $saveHandler,
        private readonly SaveObjects $saveObjectsHandler,
        private readonly SearchQueryHandler $searchQueryHandler,
        private readonly ValidateObject $validateHandler,
        // New handlers - TESTING FIRST 5:.
        private readonly LockHandler $lockHandler,
        private readonly AuditHandler $auditHandler,
        private readonly PublishHandler $publishHandler,
        private readonly RelationHandler $relationHandler,
        private readonly MergeHandler $mergeHandler,
        // REFACTORED: CrudHandler removed - was unimplemented stub causing circular dependency.
        // REFACTORED: BulkOperationsHandler re-enabled - has no circular dependencies (only uses handlers/mappers).
        private readonly BulkOperationsHandler $bulkOpsHandler,
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
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        private readonly IUserSession $userSession,
        private readonly SearchTrailService $searchTrailService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly OrganisationService $organisationService,
        private readonly LoggerInterface $logger,
        private readonly CacheHandler $cacheHandler,
        private readonly SettingsService $settingsService,
        private readonly IAppContainer $container
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
        $this->logger->debug('ObjectService constructor completed.');
    }//end __construct()

    /**
     * Check if the current user has permission to perform a specific CRUD action on objects of a given schema

    /**
     * Check permission and throw exception if not granted
     *
     * @param Schema      $schema      Schema to check permissions for
     * @param string      $action      Action to check permission for
     * @param string|null $userId      User ID to check permissions for
     * @param string|null $objectOwner Object owner ID
     * @param bool        $_rbac       Whether to enforce RBAC checks
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
        bool $_rbac=true
    ): void {
        $this->permissionHandler->checkPermission(
            schema: $schema,
            action: $action,
            userId: $userId,
            objectOwner: $objectOwner,
            rbac: $_rbac
        );
    }//end checkPermission()

    /**
     * Ensure folder exists for an ObjectEntity.
     *
     * This method checks if the object has a valid folder ID and creates one if needed.
     * It handles legacy cases where the folder property might be null, empty, or a string path.
     *
     * @param ObjectEntity $entity The object entity to ensure folder for
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @SuppressWarnings(PHPMD.ElseExpression) Else needed for null vs ID folder handling
     */
    public function ensureObjectFolderExists(ObjectEntity $entity): void
    {
        $folderProperty = $entity->getFolder();

        // Check if folder needs to be created (null, empty string, or legacy string path).
        $isString = is_string($folderProperty) === true;
        if ($folderProperty === null || $folderProperty === '' || $isString === true) {
            try {
                // Create folder and get the folder node.
                $folderNode = $this->fileService->createEntityFolder($entity);

                if ($folderNode !== null) {
                    // Update the entity with the folder ID.
                    $folderIdValue = $folderNode->getId();
                    if ($folderIdValue !== null) {
                        $entity->setFolder((string) $folderIdValue);
                    } else {
                        $entity->setFolder(null);
                    }

                    // Save the entity with the new folder ID.
                    $this->objectEntityMapper->update($entity);
                }
            } catch (Exception $e) {
                // Log the error but don't fail the object creation/update.
                // The object can still function without a folder.
            }
        }//end if
    }//end ensureObjectFolderExists()

    /**
     * Set the current register context.
     *
     * @param Register|string|int $register The register object or its ID/UUID
     *
     * @return static Returns self for method chaining
     *
     * @SuppressWarnings(PHPMD.ElseExpression) Else needed for numeric vs slug lookup paths
     */
    public function setRegister(Register | string | int $register): static
    {
        if (is_string($register) === true || is_int($register) === true) {
            // **PERFORMANCE OPTIMIZATION**: Use cached entity lookup for numeric IDs only.
            // When deriving register from object context, bypass RBAC and multi-tenancy checks.
            // If user has access to the object, they should be able to access its register.
            if (is_numeric($register) === true) {
                $registers          = $this->performanceHandler->getCachedEntities(
                    [$register],
                    function (array $ids): array {
                        return [$this->registerMapper->find(
                            id: $ids[0],
                            published: null,
                            _rbac: false,
                            _multitenancy: false
                        )
                        ];
                    }
                );
                $registerExists     = isset($registers[0]) === true;
                $isRegisterInstance = $registerExists
                    && $registers[0] instanceof Register;
                if ($isRegisterInstance === true) {
                    $register = $registers[0];
                } else {
                    // Fallback to direct database lookup if cache fails.
                    $register = $this->registerMapper->find(
                        id: $register,
                        published: null,
                        _rbac: false,
                        _multitenancy: false
                    );
                }
            } else {
                // It's a slug string - find() already supports slugs via orX(id, uuid, slug).
                $register = $this->registerMapper->find(
                    id: $register,
                    published: null,
                    _rbac: false,
                    _multitenancy: false
                );
            }//end if
        }//end if

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
     * @SuppressWarnings(PHPMD.ElseExpression) Else needed for numeric vs slug lookup paths
     */
    public function setSchema(Schema | string | int $schema): static
    {
        if (is_string($schema) === true || is_int($schema) === true) {
            // Try to find schema by ID first (if numeric) or by slug.
            try {
                if (is_numeric($schema) === true) {
                    // **PERFORMANCE OPTIMIZATION**: Use cached entity lookup for numeric IDs.
                    $schemas          = $this->performanceHandler->getCachedEntities(
                        [$schema],
                        function (array $ids): array {
                            return [$this->schemaMapper->find(
                                id: $ids[0],
                                published: null,
                                _rbac: false,
                                _multitenancy: false
                            )
                            ];
                        }
                    );
                    $schemaExists     = isset($schemas[0]) === true;
                    $isSchemaInstance = $schemaExists && $schemas[0] instanceof Schema;
                    if ($isSchemaInstance === true) {
                        $schema = $schemas[0];
                    } else {
                        // Fallback to direct database lookup if cache fails.
                        $schema = $this->schemaMapper->find(
                            id: $schema,
                            published: null,
                            _rbac: false,
                            _multitenancy: false
                        );
                    }
                } else {
                    // It's a slug string - find() supports slugs via orX(id, uuid, slug).
                    $schema = $this->schemaMapper->find(
                        id: $schema,
                        published: null,
                        _rbac: false,
                        _multitenancy: false
                    );
                }//end if
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Debug logging to understand WHY schema lookup fails.
                $this->logger->error(
                    '[ObjectService] Schema not found during setSchema()',
                    [
                        'schema_identifier' => $schema,
                        'error'             => $e->getMessage(),
                        'trace'             => $e->getTraceAsString(),
                    ]
                );
                throw new ValidationException('Schema not found');
            }//end try
        }//end if

        $this->currentSchema = $schema;
        return $this;
    }//end setSchema()

    /**
     * Set the current object context.
     *
     * @param ObjectEntity|string|int $object The object entity or its ID/UUID
     *
     * @return static Returns self for method chaining
     */
    public function setObject(ObjectEntity | string | int $object): static
    {
        if (is_string($object) === true || is_int($object) === true) {
            // Look up the object by ID or UUID.
            $object = $this->objectEntityMapper->find($object);
        }

        $this->currentObject = $object;
        return $this;
    }//end setObject()

    /**
     * Get the current object context.
     *
     * @return ObjectEntity|null The current object entity or null if not set.
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
     *
     * @return ObjectEntity|null The rendered object or null.
     *
     * @throws Exception If the object is not found.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex permission and context handling requires multiple branches
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple optional parameters create many execution paths
     */
    public function find(
        int | string $id,
        ?array $_extend=[],
        bool $files=false,
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ?ObjectEntity {
        // Check if a register is provided and set the current register context.
        if ($register !== null) {
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context.
        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Retrieve the object using the current register, schema, ID, extend properties, and file information.
        $object = $this->getHandler->find(
            id: $id,
            register: $this->currentRegister,
            schema: $this->currentSchema,
            _extend: $_extend,
            files: $files,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );

        // If the object is not found, return null (@psalm-suppress TypeDoesNotContainNull).
        if ($object === null) {
            return null;
        }

        // If no schema was provided but we have an object, derive the schema from the object.
        if ($this->currentSchema === null) {
            $this->setSchema($object->getSchema());
        }

        // If the object is not published, check the permissions.
        $now = new DateTime('now');
        if ($object->getPublished() === null
            || $now < $object->getPublished()
            || ($object->getDepublished() !== null && $object->getDepublished() <= $now)
        ) {
            // Check user has permission to read this specific object (includes object owner check).
            $this->checkPermission(
                schema: $this->currentSchema,
                action: 'read',
                userId: null,
                objectOwner: $object->getOwner(),
                _rbac: $_rbac
            );
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

        return $this->renderHandler->renderEntity(
            entity: $object,
            _extend: $_extend,
            registers: $registers,
            schemas: $schemas,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end find()

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
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context.
        if ($schema !== null) {
            $this->setSchema($schema);
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
     */
    public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
    {
        // Prepare configuration and set context.
        $config = $this->prepareFindAllConfig($config);

        // Delegate the findAll operation to the handler.
        $objects = $this->getHandler->findAll(
            limit: $config['limit'] ?? null,
            offset: $config['offset'] ?? null,
            filters: $config['filters'] ?? [],
            sort: $config['sort'] ?? [],
            search: $config['search'] ?? null,
            files: $config['files'] ?? false,
            uses: $config['uses'] ?? null,
            ids: $config['ids'] ?? null,
            published: $config['published'] ?? false,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );

        // Resolve register and schema entities for rendering.
        [$registers, $schemas] = $this->resolveRegisterAndSchema(
            config: $config,
            objects: $objects
        );

        // Render all objects asynchronously.
        return $this->renderObjectsAsync(
            objects: $objects,
            config: $config,
            registers: $registers,
            schemas: $schemas,
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
            $this->setRegister($config['filters']['register']);
        }

        // Set the current schema context if a schema is provided, it's not an array, and it's not empty.
        if (isset($config['filters']['schema']) === true
            && is_array($config['filters']['schema']) === false
            && empty($config['filters']['schema']) === false
        ) {
            $this->setSchema($config['filters']['schema']);
        }

        return $config;
    }//end prepareFindAllConfig()

    /**
     * Resolve register and schema entities for rendering.
     *
     * @param array $config  Configuration array
     * @param array $objects Retrieved objects
     *
     * @return ((Register|Schema|mixed)[]|null)[] [registers, schemas]
     *
     * @psalm-return list{array<Register|mixed>|null, array<Schema|mixed>|null}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple conditions for register/schema resolution
     */
    private function resolveRegisterAndSchema(array $config, array $objects): array
    {
        // Determine if register and schema should be passed to renderEntity.
        $registers = null;
        if ($this->currentRegister !== null && ($config['filters']['register'] ?? null) !== null) {
            $registers = [$this->currentRegister->getId() => $this->currentRegister];
        }

        $schemas = null;
        if ($this->currentSchema !== null && isset($config['filters']['schema']) === true) {
            $schemas = [$this->currentSchema->getId() => $this->currentSchema];
        }

        // Check if '@self.schema' or '@self.register' is in extend but not in filters.
        // This handles cases where we need to load schemas/registers for rendering.
        $hasExtend     = isset($config['extend']) === true;
        $extendArray   = (array) ($config['extend'] ?? []);
        $needsSchema   = $hasExtend
            && in_array('@self.schema', $extendArray, true) === true
            && $schemas === null;
        $needsRegister = $hasExtend
            && in_array('@self.register', $extendArray, true) === true
            && $registers === null;

        if ($needsSchema === true) {
            $schemaIds = array_unique(
                array_filter(array_map(fn($object) => $object->getSchema() ?? null, $objects))
            );
            $schemas   = $this->performanceHandler->getCachedEntities(
                ids: $schemaIds,
                fallbackFunc: [$this->schemaMapper, 'findMultiple']
            );
            $schemas   = array_combine(
                array_map(fn(Schema $schema): int => $schema->getId(), $schemas),
                $schemas
            );
        }

        if ($needsRegister === true) {
            $registerIds = array_unique(
                array_filter(array_map(fn($object) => $object->getRegister() ?? null, $objects))
            );
            $registers   = $this->performanceHandler->getCachedEntities(
                ids: $registerIds,
                fallbackFunc: [$this->registerMapper, 'findMultiple']
            );
            $registers   = array_combine(
                array_map(fn(Register $register): int => $register->getId(), $registers),
                $registers
            );
        }

        return [$registers, $schemas];
    }//end resolveRegisterAndSchema()

    /**
     * Render objects asynchronously using promises.
     *
     * @param array      $objects       Objects to render
     * @param array      $config        Configuration array
     * @param array|null $registers     Register entities
     * @param array|null $schemas       Schema entities
     * @param bool       $_rbac         Apply RBAC
     * @param bool       $_multitenancy Apply multitenancy
     *
     * @return array Rendered objects
     */
    private function renderObjectsAsync(
        array $objects,
        array $config,
        ?array $registers,
        ?array $schemas,
        bool $_rbac,
        bool $_multitenancy
    ): array {
        // Render each object through the render handler.
        $promises = [];
        foreach ($objects as $key => $object) {
            // @psalm-suppress InvalidArgument Promise resolve accepts mixed.
            $promises[$key] = new Promise(
                function ($resolve, $reject) use ($object, $config, $registers, $schemas, $_rbac, $_multitenancy) {
                    try {
                        $renderedObject = $this->renderHandler->renderEntity(
                            entity: $object,
                            _extend: $config['extend'] ?? [],
                            filter: $config['unset'] ?? null,
                            fields: $config['fields'] ?? null,
                            registers: $registers,
                            schemas: $schemas,
                            _rbac: $_rbac,
                            _multitenancy: $_multitenancy
                        );

                        $resolve($renderedObject);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }//end try
                }
            );
        }//end foreach

        // @psalm-suppress UndefinedFunction React\Async\await is from external library.
        return Async\await(all($promises));
    }//end renderObjectsAsync()

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
     */
    public function count(
        array $config=[]
    ): int {
        // Add register and schema IDs to filters.
        // Ensure we have both register and schema set.
        if ($this->currentRegister !== null && empty($config['filers']['register']) === true) {
            // Note: $_filters was intended for filter building but is currently unused.
            // Filters are applied directly to $config instead.
            // $_filters = ['register' => $this->currentRegister->getId()];.
        }

        if ($this->currentSchema !== null && empty($config['filers']['schema']) === true) {
            $config['filers']['schema'] = $this->currentSchema->getId();
        }

        // Remove limit from config as it's not needed for count.
        unset($config['limit']);

        return $this->objectEntityMapper->countAll(
            _filters: $config['filters'] ?? []
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
     */
    public function findByRelations(string $search, bool $partialMatch=true): array
    {
        // Use the findByRelation method from the ObjectEntityMapper to find objects by their relations.
        return $this->objectEntityMapper->findByRelation(search: $search, partialMatch: $partialMatch);
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
     */
    public function getLogs(string $uuid, array $filters=[], bool $_rbac=true, bool $_multitenancy=true): array
    {
        // Get logs for the specified object.
        $object = $this->objectEntityMapper->find($uuid);
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
     *
     * @return ObjectEntity The saved and rendered object
     *
     * @throws Exception If there is an error during save
     *
     * @TODO Add property-level RBAC validation here
     * Before saving object data, check if user has permission to create/update specific properties
     * based on property-level authorization arrays in the schema.
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
        ?array $uploadedFiles=null
    ): ObjectEntity {
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

        // Handle cascading relations while preserving context.
        [$object, $uuid] = $this->handleCascadingWithContextPreservation(
            object: $object,
            uuid: $uuid
        );

        // Validate if hard validation is enabled.
        $this->validateObjectIfRequired($object);

        // Ensure folder exists for the object.
        $folderId = $this->ensureObjectFolder($uuid);

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
            uploadedFiles: $uploadedFiles
        );

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
            $this->setRegister($register);
        }

        // Set the current schema context if provided.
        if ($schema !== null) {
            $this->setSchema($schema);
        }
    }//end setContextFromParameters()

    /**
     * Extract UUID and normalize object to array format.
     *
     * @param array|ObjectEntity $object Input object
     * @param string|null        $uuid   Provided UUID
     *
     * @return array{0: array, 1: string|null} [normalized object array, extracted UUID]
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
            $existingObject = $this->objectEntityMapper->find($uuid);
            // This is an UPDATE operation.
            $this->checkPermission(
                schema: $this->currentSchema,
                action: 'update',
                userId: null,
                objectOwner: $existingObject->getOwner(),
                _rbac: $_rbac
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
        }
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

        [$object, $uuid] = $this->cascadingHandler->handlePreValidationCascading(
            object: $object,
            schema: $parentSchema,
            uuid: $uuid,
            currentRegister: $currentRegisterId
        );

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
        // Validate the object against the current schema only if hard validation is enabled.
        if ($this->currentSchema->getHardValidation() === true) {
            $result = $this->validateHandler->validateObject(
                object: $object,
                schema: $this->currentSchema
            );

            if ($result->isValid() === false) {
                $meaningfulMessage = $this->validateHandler->generateErrorMessage(result: $result);
                throw new ValidationException($meaningfulMessage, errors: $result->error());
            }
        }
    }//end validateObjectIfRequired()

    /**
     * Ensure object folder exists, create if needed.
     *
     * @param string|null $uuid Object UUID
     *
     * @return int|null Folder ID if created/exists, null otherwise
     */
    private function ensureObjectFolder(?string $uuid): ?int
    {
        // Handle folder creation for existing objects or new objects with UUIDs.
        $folderId = null;

        if ($uuid !== null) {
            // For existing objects or objects with specific UUIDs, check if folder needs to be created.
            try {
                $existingObject = $this->objectEntityMapper->find($uuid);
                $folder         = $existingObject->getFolder();
                $isString       = is_string($folder) === true;

                if ($folder === null || $folder === '' || $isString === true) {
                    try {
                        $folderId = $this->fileService->createObjectFolderWithoutUpdate($existingObject);
                    } catch (Exception $e) {
                        // Log error but continue - object can function without folder.
                    }
                }
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Object not found, will create new one with the specified UUID.
                // Let SaveObject handle the creation with the provided UUID.
            } catch (Exception $e) {
                // Other errors - let SaveObject handle the creation.
            }
        }//end if

        return $folderId;
    }//end ensureObjectFolder()

    /**
     * Delete an object.
     *
     * @param string $uuid          The UUID of the object to delete
     * @param bool   $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool   $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return bool Whether the deletion was successful
     *
     * @throws \Exception If user does not have delete permission
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function deleteObject(string $uuid, bool $_rbac=true, bool $_multitenancy=true): bool
    {
        // Find the object to get its owner for permission check (include soft-deleted objects).
        try {
            $objectToDelete = $this->objectEntityMapper->find(
                identifier: $uuid,
                register: null,
                schema: null,
                includeDeleted: true
            );

            // If no schema was provided but we have an object, derive the schema from the object.
            if ($this->currentSchema === null) {
                $this->setSchema($objectToDelete->getSchema());
            }

            // Check user has permission to delete this specific object.
            $this->checkPermission(
                schema: $this->currentSchema,
                action: 'delete',
                userId: null,
                objectOwner: $objectToDelete->getOwner(),
                _rbac: $_rbac
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Object doesn't exist, no permission check needed but let deleteHandler handle.
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
            _multitenancy: $_multitenancy
        );
    }//end deleteObject()

        /**
         * Get the active organization for the current user
         *
         * This method determines the active organization using the same logic as SaveObject
         * to ensure consistency between save and retrieval operations.
         *
         * @return string|null The active organization UUID or null if none found
         *
         * @SuppressWarnings(PHPMD.ElseExpression) Else needed for null organization handling
         */
    private function getActiveOrganisationForContext(): ?string
    {
        try {
            $activeOrganisation = $this->organisationService->getActiveOrganisation();

            if ($activeOrganisation !== null) {
                return $activeOrganisation->getUuid();
            } else {
                return null;
            }
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
     * This method provides a cleaner search interface that uses the new searchObjects
     * method from ObjectEntityMapper with proper query structure. It automatically
     * handles metadata filters, object field searches, and search options.
     *
     * @param array       $query         The search query array containing filters and options
     *                                   - @self: Metadata filters (register, schema, uuid,
     *                                   etc.) - Direct keys: Object field filters for JSON
     *                                   data - _limit: Maximum results to return - _offset:
     *                                   Results to skip (pagination) - _order: Sorting
     *                                   criteria - _search: Full-text search term -
     *                                   _includeDeleted: Include soft-deleted objects -
     *                                   _published: Only published objects - _ids: Array of
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
     *                                            - _published: Only published objects - _search:
     *                                            Full-text search term
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

        // Use the new optimized countSearchObjects method from ObjectEntityMapper with organization context.
        return $this->objectEntityMapper->countSearchObjects(
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
     * - `_published`: Only published objects
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
     *                                   Include soft-deleted objects - _published: Only
     *                                   published objects - _ids: Array of IDs/UUIDs to
     *                                   filter by - _facets: Facet configuration for
     *                                   aggregations - _facetable: Include facetable field
     *                                   discovery (true/false) - _extend: Properties to
     *                                   extend - _fields: Fields to include - _filter/_unset:
     *                                   Fields to exclude - _queries: Specific fields for
     *                                   legacy facets
     * @param bool        $_rbac         Whether to apply RBAC checks (default: true)
     * @param bool        $_multitenancy Whether to apply multitenancy filtering (default: true)
     * @param bool        $published     Whether to filter by published status (default: false)
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
     */
    public function searchObjectsPaginated(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $published=false,
        bool $deleted=false,
        ?array $ids=null,
        ?string $uses=null,
        ?array $views=null
    ): array {
        // Add register and schema context to query for magic mapper routing.
        // Use array_key_exists to allow explicit null values to disable auto-setting.
        if ($this->currentRegister !== null && array_key_exists('_register', $query) === false) {
            $query['_register'] = $this->currentRegister->getId();
        }

        // Don't auto-set _schema when _schemas is provided (multi-schema search).
        // Use array_key_exists to allow explicit null values to disable auto-setting.
        if ($this->currentSchema !== null && array_key_exists('_schema', $query) === false && array_key_exists('_schemas', $query) === false) {
            $query['_schema'] = $this->currentSchema->getId();
        }

        // Apply view filters if provided.
        if ($views !== null && empty($views) === false) {
            $query = $this->applyViewsToQuery(query: $query, viewIds: $views);
        }

        // IDs and uses are passed as proper parameters, not added to query.
        $requestedSource = $query['_source'] ?? null;

        // Simple switch: Use SOLR if explicitly requested OR if SOLR is enabled in config.
        // BUT force database when ids or uses parameters are provided (relation-based searches).
        $hasIds          = isset($query['_ids']) === true;
        $hasUses         = isset($query['_uses']) === true;
        $hasIdsParam     = $ids !== null;
        $hasUsesParam    = $uses !== null;
        $isSolrRequested = ($requestedSource === 'index' || $requestedSource === 'solr');
        $isSolrEnabled   = $this->isSolrAvailable() === true;
        $isNotDatabase   = $requestedSource !== 'database';
        if ((            $isSolrRequested === true
            && $hasIdsParam === false && $hasUsesParam === false
            && $hasIds === false && $hasUses === false)
            || (            $requestedSource === null
            && $isSolrEnabled === true
            && $isNotDatabase === true
            && $hasIdsParam === false && $hasUsesParam === false
            && $hasIds === false && $hasUses === false)
        ) {
            // Forward to Index service - let it handle availability checks and error handling.
            $indexService = $this->container->get(IndexService::class);
            $result       = $indexService->searchObjects(
                query: $query,
                rbac: $_rbac,
                multitenancy: $_multitenancy,
                published: $published,
                deleted: $deleted
            );
            $result['@self']['source']    = 'index';
            $result['@self']['query']     = $query;
            $result['@self']['rbac']      = $_rbac;
            $result['@self']['multi']     = $_multitenancy;
            $result['@self']['published'] = $published;
            $result['@self']['deleted']   = $deleted;

            // Add extended objects only if _extend is requested.
            $extend = $query['_extend'] ?? [];
            if (empty($extend) === false) {
                $result['@self']['objects'] = $this->getExtendedObjects();
            }

            return $result;
        }//end if

        // Bypass multitenancy for schemas with public read access (unless _source=database is explicitly set).
        // Public schemas should be visible to all users regardless of organisation.
        $effectiveMultitenancy = $_multitenancy;
        if ($_multitenancy === true && $requestedSource !== 'database' && $this->currentSchema !== null) {
            $schemaAuth = $this->currentSchema->getAuthorization();
            $readGroups = $schemaAuth['read'] ?? [];
            if (in_array('public', $readGroups, true) === true) {
                $effectiveMultitenancy = false;
            }
        }

        // Use database search.
        $result = $this->queryHandler->searchObjectsPaginatedDatabase(
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $effectiveMultitenancy,
            published: $published,
            deleted: $deleted,
            ids: $ids,
            uses: $uses
        );
        $result['@self']['source']    = 'database';
        $result['@self']['query']     = $query;
        $result['@self']['rbac']      = $_rbac;
        $result['@self']['multi']     = $_multitenancy;
        $result['@self']['published'] = $published;
        $result['@self']['deleted']   = $deleted;

        // Add extended objects only if _extend is requested.
        $extend = $query['_extend'] ?? [];
        if (empty($extend) === false) {
            $result['@self']['objects'] = $this->getExtendedObjects();
        }

        // Add names mapping if _names is in _extend.
        // This provides UUID-to-name mappings for all related objects in the results,
        // reducing frontend calls to the names service.
        if (is_array($extend) === true && in_array('_names', $extend, true) === true) {
            $resultsToProcess = $result['results'] ?? [];

            // Only process if results exist and is an array.
            if (is_array($resultsToProcess) === false || empty($resultsToProcess) === true) {
                $result['@self']['names'] = [];
            } else {
                try {
                    $result['@self']['names'] = $this->collectNamesForResults($resultsToProcess);
                } catch (\Throwable $e) {
                    $this->logger->error('_names extension failed: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
                    $result['@self']['names'] = [];
                    $result['@self']['names_error'] = $e->getMessage();
                }
            }
        }

        return $result;
    }//end searchObjectsPaginated()

    /**
     * Check if Solr is available for use.
     *
     * @return bool True if Solr is enabled and available, false otherwise
     */
    private function isSolrAvailable(): bool
    {
        return $this->searchQueryHandler->isSolrAvailable();
    }//end isSolrAvailable()

    /**
     * Original database search logic - extracted to avoid code duplication.
     *
     * @param array<string, mixed> $query     The search query array
     * @param bool                 $_rbac      Whether to apply RBAC checks (default: true)
     * @param bool                 $_multitenancy     Whether to apply multitenancy filtering (default: true)
     * @param bool                 $published Whether to filter by published status (default: false)
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
     */
    public function getRegister(): int
    {
        if ($this->currentRegister === null) {
            throw new RuntimeException('Register not set in ObjectService.');
        }

        return $this->currentRegister->getId();
    }//end getRegister()

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
     * Collect UUID-to-name mappings for all related objects in search results.
     *
     * This method extracts all UUIDs from the search results (relations, object properties)
     * and resolves them to human-readable names using the CacheHandler.
     *
     * @param array $results Array of rendered objects or ObjectEntity instances from search.
     *
     * @return array<string, string> Map of UUID to name.
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
                // Get relations directly from entity.
                $relations = $result->getRelations();
                if (is_array($relations) === true) {
                    foreach ($relations as $relation) {
                        if (is_string($relation) === true && $this->isUuidFormat($relation) === true) {
                            $uuids[] = $relation;
                        } elseif (is_array($relation) === true) {
                            foreach ($relation as $uuid) {
                                if (is_string($uuid) === true && $this->isUuidFormat($uuid) === true) {
                                    $uuids[] = $uuid;
                                }
                            }
                        }
                    }
                }

                // Get object data directly without triggering full serialization.
                $objectData = $result->getObject();
                if (is_array($objectData) === true) {
                    $this->collectUuidsFromObjectData($objectData, $uuids);
                }
                continue;
            }

            // Handle already-serialized arrays.
            if (is_array($result) === false) {
                continue;
            }

            $resultData = $result;

            // Get the actual object data - handle nested @self structure.
            $objectData = $resultData;
            if (isset($resultData['@self']) === true && is_array($resultData['@self']) === true) {
                // Collect from relations in @self.
                $relations = $resultData['@self']['relations'] ?? [];
                if (is_array($relations) === true) {
                    foreach ($relations as $relation) {
                        if (is_string($relation) === true && $this->isUuidFormat($relation) === true) {
                            $uuids[] = $relation;
                        } elseif (is_array($relation) === true) {
                            foreach ($relation as $uuid) {
                                if (is_string($uuid) === true && $this->isUuidFormat($uuid) === true) {
                                    $uuids[] = $uuid;
                                }
                            }
                        }
                    }
                }

                // Use the object data from @self if present.
                if (isset($resultData['@self']['object']) === true && is_array($resultData['@self']['object']) === true) {
                    $objectData = $resultData['@self']['object'];
                }
            }

            // Collect UUIDs from object properties.
            if (is_array($objectData) === true) {
                $this->collectUuidsFromObjectData($objectData, $uuids);
            }
        }


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
     * Recursively collect UUIDs from object data.
     *
     * @param array $data   The object data to scan.
     * @param array &$uuids Reference to array collecting UUIDs.
     *
     * @return void
     */
    private function collectUuidsFromObjectData(array $data, array &$uuids, int $depth = 0): void
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
            if (is_string($value) === true && $this->isUuidFormat($value) === true) {
                $uuids[] = $value;
            } elseif (is_array($value) === true) {
                // Only look at arrays of UUIDs (not nested objects).
                foreach ($value as $item) {
                    if (is_string($item) === true && $this->isUuidFormat($item) === true) {
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
     */
    public function handleValidationException(
        ValidationException|CustomValidationException $exception
    ): \OCP\AppFramework\Http\JSONResponse {
        return $this->validateHandler->handleValidationException($exception);
    }//end handleValidationException()

    /**
     * Publish an object, setting its publication date to now or a specified date.
     *
     * @param string|null   $uuid          The UUID of the object to publish. If null, uses the current object.
     * @param DateTime|null $date          Optional publication date. If null, uses current date/time.
     * @param bool          $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool          $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The updated object entity.
     *
     * @throws \Exception If the object is not found or if there's an error during update.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function publish(
        string $uuid=null,
        ?\DateTime $date=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {

        // Use the publish handler to publish the object.
        return $this->publishHandler->publish(
            uuid: $uuid,
            date: $date,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end publish()

    /**
     * Depublish an object, setting its depublication date to now or a specified date.
     *
     * @param string|null   $uuid          The UUID of the object to depublish. If null, uses the current object.
     * @param DateTime|null $date          Optional depublication date. If null, uses current date/time.
     * @param bool          $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool          $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The updated object entity.
     *
     * @throws \Exception If the object is not found or if there's an error during update.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function depublish(
        string $uuid=null,
        ?\DateTime $date=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        // Use the publish handler to depublish the object.
        return $this->publishHandler->depublish(
            uuid: $uuid,
            date: $date,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end depublish()

    /**
     * Lock an object
     *
     * Locks an object to prevent concurrent modifications.
     *
     * @param string      $identifier Object ID or UUID
     * @param string|null $process    Process ID (for tracking who locked it)
     * @param int|null    $duration   Lock duration in seconds
     *
     * @return array Lock information
     *
     * @throws \Exception If lock operation fails
     */
    public function lockObject(string $identifier, ?string $process=null, ?int $duration=null): array
    {
        return $this->lockHandler->lock(identifier: $identifier, process: $process, duration: $duration);
    }//end lockObject()

    /**
     * Unlock an object
     *
     * Removes the lock from an object, allowing other processes to modify it.
     *
     * @param string|int $identifier The object to unlock
     *
     * @return true True if unlocked successfully
     *
     * @throws \Exception If unlock operation fails
     */
    public function unlockObject(string|int $identifier): bool
    {
        return $this->lockHandler->unlock(identifier: (string) $identifier);
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
     * - ObjectEntityMapper.saveObjects() = Actual database bulk operations
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

        // Set register and schema context if provided.
        if ($register !== null) {
            $this->setRegister($register);
        }

        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // ARCHITECTURAL DELEGATION: Delegate to BulkOperationsHandler which includes cache invalidation.
        return $this->bulkOpsHandler->saveObjects(
            objects: $objects,
            currentRegister: $this->currentRegister,
            currentSchema: $this->currentSchema,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            validation: $validation,
            events: $events,
            deduplicateIds: $deduplicateIds,
            enrich: $enrich
        );
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
     * @return array Array of IDs of deleted objects
     *
     * @phpstan-param  array<int, string> $uuids
     * @psalm-param    array<int, string> $uuids
     * @phpstan-return array<int, int>
     * @psalm-return   array<int, int>
     */
    public function deleteObjects(array $uuids=[], bool $_rbac=true, bool $_multitenancy=true): array
    {
        // ARCHITECTURAL DELEGATION: Delegate to BulkOperationsHandler for all bulk delete logic.
        // Pass register and schema context for magic mapper support.
        return $this->bulkOpsHandler->deleteObjects(
            uuids: $uuids,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            register: $this->currentRegister,
            schema: $this->currentSchema
        );
    }//end deleteObjects()

    /**
     * Perform bulk publish operations on objects by UUID
     *
     * This method sets the published timestamp for the specified objects.
     * If a datetime is provided, it uses that value; otherwise, it uses the current datetime.
     * If false is provided, it unsets the published timestamp.
     *
     * @param array         $uuids         Array of object UUIDs to publish
     * @param DateTime|bool $datetime      Optional datetime for publishing (false to unset)
     * @param bool          $_rbac         Whether to apply RBAC filtering
     * @param bool          $_multitenancy Whether to apply multi-organization filtering
     *
     * @psalm-param   array<int, string> $uuids
     * @phpstan-param array<int, string> $uuids
     *
     * @return array Array of UUIDs of published objects
     *
     * @psalm-return   array<int, string>
     * @phpstan-return array<int, string>
     */
    public function publishObjects(
        array $uuids=[],
        \DateTime|bool $datetime=true,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        // ARCHITECTURAL DELEGATION: Delegate to BulkOperationsHandler for all bulk publish logic.
        // Pass register and schema context for magic mapper support.
        return $this->bulkOpsHandler->publishObjects(
            uuids: $uuids,
            datetime: $datetime,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            register: $this->currentRegister,
            schema: $this->currentSchema
        );
    }//end publishObjects()

    /**
     * Perform bulk depublish operations on objects by UUID
     *
     * This method sets the depublished timestamp for the specified objects.
     * If a datetime is provided, it uses that value; otherwise, it uses the current datetime.
     * If false is provided, it unsets the depublished timestamp.
     *
     * @param array         $uuids         Array of object UUIDs to depublish
     * @param DateTime|bool $datetime      Optional datetime for depublishing (false to unset)
     * @param bool          $_rbac         Whether to apply RBAC filtering
     * @param bool          $_multitenancy Whether to apply multi-organization filtering
     *
     * @psalm-param   array<int, string> $uuids
     * @phpstan-param array<int, string> $uuids
     *
     * @return array Array of UUIDs of depublished objects
     *
     * @psalm-return   array<int, string>
     * @phpstan-return array<int, string>
     */
    public function depublishObjects(
        array $uuids=[],
        \DateTime|bool $datetime=true,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        // ARCHITECTURAL DELEGATION: Delegate to BulkOperationsHandler for all bulk depublish logic.
        // Pass register and schema context for magic mapper support.
        return $this->bulkOpsHandler->depublishObjects(
            uuids: $uuids,
            datetime: $datetime,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            register: $this->currentRegister,
            schema: $this->currentSchema
        );
    }//end depublishObjects()

    /**
     * Publish all objects belonging to a specific schema
     *
     * This method efficiently publishes all objects that belong to the specified schema.
     * It uses bulk operations for optimal performance and maintains data integrity.
     *
     * @param int  $schemaId   The ID of the schema whose objects should be published
     * @param bool $publishAll Whether to publish all objects (default: false)
     *
     * @return (int|string[])[]
     *
     * @throws \Exception If the publishing operation fails
     *
     * @phpstan-return array{published_count: int, published_uuids: array<int, string>, schema_id: int}
     *
     * @psalm-return array{published_count: int<min, max>, published_uuids: array<int, string>, schema_id: int}
     */
    public function publishObjectsBySchema(int $schemaId, bool $publishAll=false): array
    {
        // ARCHITECTURAL DELEGATION: Delegate to BulkOperationsHandler for schema-wide publish.
        return $this->bulkOpsHandler->publishObjectsBySchema(
            schemaId: $schemaId,
            publishAll: $publishAll
        );
    }//end publishObjectsBySchema()

    /**
     * Delete all objects belonging to a specific schema
     *
     * This method efficiently deletes all objects that belong to the specified schema.
     * It uses bulk operations for optimal performance and maintains data integrity.
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
     */
    public function deleteObjectsBySchema(int $registerId, int $schemaId, bool $hardDelete=false): array
    {
        // ARCHITECTURAL DELEGATION: Delegate to BulkOperationsHandler for schema-wide delete.
        return $this->bulkOpsHandler->deleteObjectsBySchema(
            registerId: $registerId,
            schemaId: $schemaId,
            hardDelete: $hardDelete
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
     */
    public function deleteObjectsByRegister(int $registerId): array
    {
        // ARCHITECTURAL DELEGATION: Delegate to BulkOperationsHandler for register-wide delete.
        return $this->bulkOpsHandler->deleteObjectsByRegister($registerId);
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
     * @param bool   $rbac          Apply RBAC filters
     * @param bool   $_multitenancy Apply multitenancy filters
     *
     * @return array Results with object entities and pagination info.
     *
     * @throws \Exception If retrieval fails.
     */
    public function getObjectUses(
        string $objectId,
        array $query=[],
        bool $rbac=true,
        bool $_multitenancy=true
    ): array {
        return $this->relationHandler->getUses(
            objectId: $objectId,
            query: $query,
            _rbac: $rbac,
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
     * @param bool   $rbac          Apply RBAC filters
     * @param bool   $_multitenancy Apply multitenancy filters
     *
     * @return array Paginated results with referencing objects
     *
     * @throws \Exception If retrieval fails
     */
    public function getObjectUsedBy(
        string $objectId,
        array $query=[],
        bool $rbac=true,
        bool $_multitenancy=true
    ): array {
        return $this->relationHandler->getUsedBy(
            objectId: $objectId,
            query: $query,
            _rbac: $rbac,
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
     * @param bool        $rbac          Apply RBAC filters
     * @param bool        $_multitenancy Apply multitenancy filters
     * @param bool        $_published    Only return published objects
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
     */
    public function listObjects(
        array $query=[],
        bool $rbac=true,
        bool $_multitenancy=true,
        bool $_published=false,
        bool $_deleted=false,
        ?array $_ids=null,
        ?string $_uses=null,
        ?array $_views=null
    ): array|int {
        // REFACTORED: Removed CrudHandler (was unimplemented stub causing circular dependency).
        // Use searchObjects() for actual object listing.
        return $this->searchObjects(
            query: $query,
            _rbac: $rbac,
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
     */
    public function updateObject(
        string $objectId,
        array $data,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        // REFACTORED: Removed CrudHandler (was unimplemented stub). Use saveObject() with ID.
        // Get existing object and merge with new data (currently unused but kept for reference).
        // $existing = $this->objectEntityMapper->find((int) $objectId);.
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
     */
    public function patchObject(
        string $objectId,
        array $data,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        // REFACTORED: Removed CrudHandler (was unimplemented stub). Use saveObject() for patching.
        // Get existing object, merge partial data, and save.
        $existing     = $this->objectEntityMapper->find((int) $objectId);
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
     */
    public function buildObjectSearchQuery(array $params): array
    {
        // REFACTORED: Removed CrudHandler (caused circular dependency - called back to ObjectService).
        // Call buildSearchQuery() directly (already exists in ObjectService).
        return $this->buildSearchQuery(requestParams: $params);
    }//end buildObjectSearchQuery()

    // =========================================================================
    // EXPORT/IMPORT HANDLER DELEGATION METHODS
    // =========================================================================

    /**
     * Export objects to specified format
     *
     * @param \OCA\OpenRegister\Db\Register $_register    Register entity
     * @param \OCA\OpenRegister\Db\Schema   $_schema      Schema entity
     * @param array                         $_filters     Optional filters
     * @param string                        $_type        Export type (csv, excel)
     * @param \OCP\IUser|null               $_currentUser Current user
     *
     * @return never Export result with content, filename, and mimetype
     *
     * @throws \Exception If export fails
     */
    public function exportObjects(
        \OCA\OpenRegister\Db\Register $_register,
        \OCA\OpenRegister\Db\Schema $_schema,
        array $_filters=[],
        string $_type='excel',
        ?\OCP\IUser $_currentUser=null
    ) {
        // TODO: TEMPORARILY DISABLED due to circular dependency with ExportService.
        // Requires architectural refactoring to fix. See DEBUGGING_REGISTER_CREATION_TIMEOUT.md.
        throw new Exception('Export temporarily disabled due to circular dependency issues');
    }//end exportObjects()

    /**
     * Import objects from file
     *
     * @param \OCA\OpenRegister\Db\Register    $_register     Register entity
     * @param array                            $_uploadedFile Uploaded file data
     * @param \OCA\OpenRegister\Db\Schema|null $_schema       Schema entity (optional)
     * @param bool                             $_validation   Enable validation
     * @param bool                             $_events       Enable events
     * @param bool                             $_rbac         Apply RBAC checks
     * @param bool                             $_multitenancy Apply multitenancy filtering
     * @param bool                             $_publish      Publish imported objects
     * @param \OCP\IUser|null                  $_currentUser  Current user
     *
     * @return never Import result with statistics
     *
     * @throws \Exception If import fails
     */
    public function importObjects(
        \OCA\OpenRegister\Db\Register $_register,
        array $_uploadedFile,
        ?\OCA\OpenRegister\Db\Schema $_schema=null,
        bool $_validation=false,
        bool $_events=false,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $_publish=false,
        ?\OCP\IUser $_currentUser=null
    ) {
        // TODO: TEMPORARILY DISABLED due to circular dependency with ImportService.
        // Requires architectural refactoring to fix. See DEBUGGING_REGISTER_CREATION_TIMEOUT.md.
        throw new Exception('Import temporarily disabled due to circular dependency issues');
    }//end importObjects()

    /**
     * Download files associated with an object
     *
     * @param string $objectId Object ID or UUID
     *
     * @return never Download result with file paths
     *
     * @throws \Exception If download fails
     */
    public function downloadObjectFiles(string $objectId)
    {
        // TODO: TEMPORARILY DISABLED - This is actually a file operation, not export.
        // Should be refactored to use FileService directly without going through ObjectService.
        throw new Exception('File download temporarily disabled - needs refactoring');
    }//end downloadObjectFiles()

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
     */
    public function validateAndSaveObjectsBySchema(int $registerId, int $schemaId, ?int $limit = null, int $offset = 0): array
    {
        return $this->validationHandler->validateAndSaveObjectsBySchema(
            registerId: $registerId,
            schemaId: $schemaId,
            saveCallback: [$this, 'saveObject'],
            limit: $limit,
            offset: $offset
        );
    }//end validateAndSaveObjectsBySchema()
}//end class
