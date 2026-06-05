<?php

/**
 * SchemasController handles REST API endpoints for schema management
 *
 * Controller for managing schema operations in the OpenRegister app.
 * Provides endpoints for CRUD operations, schema exploration, caching,
 * import/export, and statistics.
<<<<<<< HEAD
=======
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
>>>>>>> origin/development
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use DateTime;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
<<<<<<< HEAD
use OCA\OpenRegister\Service\DownloadService;
=======
>>>>>>> origin/development
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\Exception as DBException;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCP\IAppConfig;
use OCP\IRequest;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\AuthorizationAuditService;
<<<<<<< HEAD
use OCA\OpenRegister\Service\Object\PermissionHandler;
=======
>>>>>>> origin/development
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SchemasController handles REST API endpoints for schema management
 *
 * Provides REST API endpoints for managing schemas including CRUD operations,
 * schema exploration, caching, import/export, and statistics.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 *
<<<<<<< HEAD
 * @suppressWarnings(PHPMD.ExcessiveClassLength)
 * @suppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @suppressWarnings(PHPMD.TooManyPublicMethods)
 * @suppressWarnings(PHPMD.CouplingBetweenObjects)
=======
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     REST controllers have many endpoints; extraction
 * into sub-controllers would break the route registration.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) REST controllers have many endpoints; extraction
 * into sub-controllers would break the route registration.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     REST controllers have many endpoints; extraction
 * into sub-controllers would break the route registration.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   NC AppFramework controller DI requires injecting
 * framework + RBAC + audit + domain services, each used in separate endpoint groups.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1
>>>>>>> origin/development
 */
class SchemasController extends Controller
{
    /**
     * Constructor
     *
     * Initializes controller with required dependencies for schema operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string              $appName             Application name
     * @param IRequest            $request             HTTP request object
     * @param IAppConfig          $config              App configuration for settings
     * @param SchemaMapper        $schemaMapper        Schema mapper for database operations
     * @param MagicMapper         $objectEntityMapper  Object entity mapper for object queries
<<<<<<< HEAD
     * @param DownloadService     $downloadService     Download service for file downloads
=======
>>>>>>> origin/development
     * @param UploadService       $uploadService       Upload service for file uploads
     * @param AuditTrailMapper    $auditTrailMapper    Audit trail mapper for log statistics
     * @param OrganisationService $organisationService Organisation service for multi-tenancy
     * @param SchemaCacheHandler  $schemaCacheService  Schema cache handler for caching operations
     * @param FacetCacheHandler   $facetCacheSvc       Schema facet cache service for facet caching
     * @param SchemaService       $schemaService       Schema service for exploration operations
     * @param LoggerInterface     $logger              Logger for error tracking
     * @param ContainerInterface  $container           Container for lazy loading services
     *
     * @return void
     *
<<<<<<< HEAD
     * @suppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
=======
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud AppFramework requires all services to be constructor-injected.
>>>>>>> origin/development
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly SchemaMapper $schemaMapper,
        private readonly MagicMapper $objectEntityMapper,
<<<<<<< HEAD
        private readonly DownloadService $downloadService,
=======
>>>>>>> origin/development
        private readonly UploadService $uploadService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly OrganisationService $organisationService,
        private readonly SchemaCacheHandler $schemaCacheService,
        private readonly FacetCacheHandler $facetCacheSvc,
        private readonly SchemaService $schemaService,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Retrieves a list of all schemas
     *
     * Returns a JSON response containing an array of all schemas in the system.
     * Supports pagination, filtering, and extended properties (stats, extendedBy).
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @return JSONResponse JSON response with array of schemas
     *
     * @psalm-return JSONResponse<200,
     *     array{results: array<array{id: int, uuid: null|string,
     *     uri: null|string, slug: null|string, title: null|string,
     *     description: null|string, version: null|string,
     *     summary: null|string, icon: null|string, required: array,
     *     properties: array, archive: array|null, source: null|string,
     *     hardValidation: bool, immutable: bool, searchable: bool,
     *     updated: null|string, created: null|string, maxDepth: int,
     *     owner: null|string, application: null|string,
     *     organisation: null|string,
     *     groups: array<string, list<string>>|null,
     *     authorization: array|null, deleted: null|string,
     *     published: null|string, depublished: null|string,
     *     configuration: array|null|string, allOf: array|null,
     *     oneOf: array|null, anyOf: array|null}>}, array<never, never>>
     *
<<<<<<< HEAD
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
     * @suppressWarnings(PHPMD.NPathComplexity)
=======
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple optional extend/pagination/filter parameters each add one branch.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple optional extend/pagination/filter parameters each add one branch.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Handling pagination + filtering + extend + stats
     * in one NC controller action is idiomatic; extracting helpers would obscure the flow.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
>>>>>>> origin/development
     */
    public function index(): JSONResponse
    {
        // Get request parameters for filtering and searching.
        $params = $this->request->getParams();

        // Extract pagination and search parameters.
        $limit = null;
        if (isset($params['_limit']) === true) {
            $limit = (int) $params['_limit'];
        }

        $offset = null;
        if (isset($params['_offset']) === true) {
            $offset = (int) $params['_offset'];
        }

        $page = null;
        if (isset($params['_page']) === true) {
            $page = (int) $params['_page'];
        }

        // Note: search parameter not currently used in this endpoint.
        // Extract extend parameter for additional properties.
        $extend = $params['_extend'] ?? [];

        // Normalize extend to array if string.
        if (is_string($extend) === true) {
            $extend = [$extend];
        }

        // Convert page to offset if provided (page-based pagination).
        if ($page !== null && $limit !== null) {
            $offset = ($page - 1) * $limit;
        }

        // Extract filters from request parameters.
        $filters = $params['filters'] ?? [];

        // Retrieve schemas using mapper with pagination and filters.
        $schemas = $this->schemaMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            searchConditions: [],
            searchParams: [],
            _extend: [],
            _multitenancy: false
        );

<<<<<<< HEAD
        // Serialize schemas to arrays.
        $schemasArr = array_map(
            function ($schema) {
                return $schema->jsonSerialize();
            },
            $schemas
        );

        // Batch-load all extendedBy relationships in a single query instead of N queries.
        $allExtendedBy = $this->schemaMapper->findAllExtendedBy();
        foreach ($schemasArr as &$schema) {
            // @psalm-suppress InvalidArrayOffset
            $schema['@self'] = $schema['@self'] ?? [];
            $schema['@self']['extendedBy'] = $allExtendedBy[$schema['id']] ?? [];
        }

=======
        // Read-visibility guard: this endpoint is @PublicPage so it stays
        // reachable when OpenRegister is restricted to a user group. Anonymous
        // callers may only see PUBLISHED schemas; authenticated users are
        // unaffected. Visibility is derived from server-side published/
        // depublished entity state, never from client-supplied parameters.
        if ($this->isAnonymousRequest() === true) {
            $schemas = array_values(
                array_filter(
                    $schemas,
                    fn($schema) => $this->isPublishedEntity(
                        published: $schema->getPublished(),
                        depublished: $schema->getDepublished()
                    )
                )
            );
        }

        // Serialize schemas to arrays.
        $schemasArr = array_map(
            function ($schema) {
                return $schema->jsonSerialize();
            },
            $schemas
        );

        // Batch-load all extendedBy relationships in a single query instead of N queries.
        $allExtendedBy = $this->schemaMapper->findAllExtendedBy();
        foreach ($schemasArr as &$schema) {
            // @psalm-suppress InvalidArrayOffset
            $schema['@self'] = $schema['@self'] ?? [];
            $schema['@self']['extendedBy'] = $allExtendedBy[$schema['id']] ?? [];
        }

>>>>>>> origin/development
        unset($schema);
        // Break the reference.
        // If '@self.stats' is requested, attach statistics to each schema.
        if (in_array('@self.stats', $extend, true) === true) {
            // Collect all schema IDs for batch queries.
            $schemaIds = array_map(fn($schema) => $schema['id'], $schemasArr);

            // Batch-load all statistics in 3 queries instead of N*2 queries.
            $registerCounts = $this->schemaMapper->getRegisterCountPerSchema();
            $objectStats    = $this->objectEntityMapper->getStatisticsGroupedBySchema(schemaIds: $schemaIds);
            $logStats       = $this->auditTrailMapper->getStatisticsGroupedBySchema(schemaIds: $schemaIds);

            foreach ($schemasArr as &$schema) {
                $schema['stats'] = [
                    'objects'   => $objectStats[$schema['id']] ?? [
                        'total'   => 0,
                        'size'    => 0,
                        'invalid' => 0,
                        'deleted' => 0,
                        'locked'  => 0,
                    ],
                    'logs'      => $logStats[$schema['id']] ?? ['total' => 0, 'size' => 0],
                    'files'     => [ 'total' => 0, 'size' => 0 ],
                    // Add the number of registers referencing this schema.
                    'registers' => $registerCounts[$schema['id']] ?? 0,
                ];
            }
        }//end if

        return new JSONResponse(data: ['results' => $schemasArr]);
    }//end index()

    /**
     * Retrieves a single schema by ID
     *
     * @param int|string $id The ID of the schema
     *
     * @return JSONResponse JSON response with schema data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
<<<<<<< HEAD
=======
     *
     * @SuppressWarnings(PHPMD.ShortVariable)        $id matches the {id} URL route parameter; renaming breaks route binding.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Handles _extend, _count, RBAC, 404, and several
     * response-shaping branches; each is a required rendering path that cannot be extracted without
     * splitting the HTTP contract.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
>>>>>>> origin/development
     */
    public function show($id): JSONResponse
    {
        try {
            $extend = $this->request->getParam(key: '_extend', default: []);
            if (is_string($extend) === true) {
                $extend = [$extend];
            }

<<<<<<< HEAD
            $schema    = $this->schemaMapper->find(id: $id, _extend: [], _multitenancy: false);
            $schemaArr = $schema->jsonSerialize();

            // Add extendedBy property showing UUIDs of schemas that extend this schema.
            // Note: @psalm-suppress InvalidArrayOffset used here for dynamic array access.
            $schemaArr['@self'] = $schemaArr['@self'] ?? [];
            $schemaArr['@self']['extendedBy'] = $this->schemaMapper->findExtendedBy($id);

=======
            $schema = $this->schemaMapper->find(id: $id, _extend: [], _multitenancy: false);

            // Read-visibility guard (@PublicPage): an anonymous caller may only
            // view a PUBLISHED schema. Derived from server-side published/
            // depublished entity state, never from client-supplied parameters.
            if ($this->isAnonymousRequest() === true
                && $this->isPublishedEntity(
                    published: $schema->getPublished(),
                    depublished: $schema->getDepublished()
                ) === false
            ) {
                return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
            }

            $schemaArr = $schema->jsonSerialize();

            // Add extendedBy property showing UUIDs of schemas that extend this schema.
            // Note: @psalm-suppress InvalidArrayOffset used here for dynamic array access.
            $schemaArr['@self'] = $schemaArr['@self'] ?? [];
            $schemaArr['@self']['extendedBy'] = $this->schemaMapper->findExtendedBy($id);

>>>>>>> origin/development
            // Add property source metadata to distinguish native vs inherited properties.
            // This is especially useful for schemas using allOf composition.
            if (($schema->getAllOf() ?? null) !== null && count($schema->getAllOf()) > 0) {
                $schemaArr['@self']['propertyMetadata'] = $this->schemaMapper->getPropertySourceMetadata($schema);
            }

            // If '@self.stats' is requested, attach statistics to the schema.
            if (in_array('@self.stats', $extend, true) === true) {
                // Get register counts for all schemas in one call.
                $registerCounts     = $this->schemaMapper->getRegisterCountPerSchema();
                $schemaArr['stats'] = [
                    'objects'   => $this->objectEntityMapper->getStatistics(registerId: null, schemaId: $schemaArr['id']),
                    'logs'      => $this->auditTrailMapper->getStatistics(registerId: null, schemaId: $schemaArr['id']),
                    'files'     => [ 'total' => 0, 'size' => 0 ],
                    // Add the number of registers referencing this schema.
                    'registers' => $registerCounts[$schemaArr['id']] ?? 0,
                ];
            }

            return new JSONResponse(data: $schemaArr);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
        } catch (\OCA\OpenRegister\Exception\ValidationException $e) {
            // ValidationException is thrown when schema is not found (includes debugging info).
            return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[SchemasController] Failed to retrieve schema',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'schema_id'     => $id,
                    'error_message' => $e->getMessage(),
                ]
            );
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end show()

    /**
     * Creates a new schema
     *
     * This method creates a new schema based on POST data.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
<<<<<<< HEAD
     * @suppressWarnings(PHPMD.StaticAccess)         DatabaseConstraintException factory method is standard pattern
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
=======
     * @SuppressWarnings(PHPMD.StaticAccess)          DatabaseConstraintException::fromDatabaseException is a named constructor — no DI alternative.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple message-substring checks for error classification; each adds one branch.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple message-substring checks for error classification; each adds one branch.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Error-classification block at the end is
     * repetitive but intentional; extracting it would not reduce cognitive load.
>>>>>>> origin/development
     *
     * @return JSONResponse JSON response with created schema or error
     *
     * @psalm-return JSONResponse<201, Schema,
<<<<<<< HEAD
     *     array<never, never>>|JSONResponse<int, array{error: string},
     *     array<never, never>>
=======
     *     array<never, never>>|JSONResponse<400|403|409|500, array{error: string},
     *     array<never, never>>
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
>>>>>>> origin/development
     */
    public function create(): JSONResponse
    {
        // Authorization: creating a schema defines a new data model and is
        // restricted to administrators. Reading schema metadata stays open so
        // frontends can build their UIs; only create/update/delete are gated.
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(
                data: ['error' => 'Only administrators may create schemas'],
                statusCode: 403
            );
        }

        // Get request parameters.
        $data = $this->request->getParams();

        // DEBUG: Log incoming request to track duplicate creation.
        $this->logger->info(
            message: '[SchemasController::create] Starting schema creation',
            context: [
                'file'             => __FILE__,
                'line'             => __LINE__,
                'title'            => $data['title'] ?? 'no title',
                'has_organisation' => isset($data['organisation']),
                'organisation'     => $data['organisation'] ?? 'not set',
            ]
        );

        // Remove internal parameters (starting with '_').
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to ensure a new record is created.
        if (($data['id'] ?? null) !== null) {
            unset($data['id']);
        }

        try {
            // Create a new schema from the data.
            $schema = $this->schemaMapper->createFromArray(object: $data);

            /*
             * NOTE: Organization should already be set from the request data.
             * The update() call below was causing duplicate schema creation with different timestamps.
             * Since createFromArray() already handles organization assignment, this is commented out.
                // Set organisation from active organisation for multi-tenancy (if not already set).
                if ($schema->getOrganisation() === null || $schema->getOrganisation() === '') {
                $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
                $schema->setOrganisation($organisationUuid);
                $schema = $this->schemaMapper->update($schema);
                }
             */

<<<<<<< HEAD
=======
            // **CACHE INVALIDATION (runtime-schema-api)**: Drop in-memory + persistent
            // schema cache for the freshly-created ID so any follow-up read in the same
            // PHP worker observes the new schema. This is the create-side counterpart
            // of the invalidations already wired into update() and destroy().
            $this->schemaCacheService->invalidate(schemaId: $schema->getId());

>>>>>>> origin/development
            return new JSONResponse(data: $schema, statusCode: 201);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(dbException: $e, entityType: 'schema');
            return new JSONResponse(
                data: ['error' => $constraintException->getMessage()],
                statusCode: $constraintException->getHttpStatusCode()
            );
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions.
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: $e->getHttpStatusCode()
            );
        } catch (Exception $e) {
            // Log the actual error for debugging.
            $this->logger->error(
                message: '[SchemasController] Schema creation failed',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'error_message' => $e->getMessage(),
                    'error_code'    => $e->getCode(),
                    'trace'         => $e->getTraceAsString(),
                ]
            );

            // Check if this is a validation error by examining the message.
            if (str_contains($e->getMessage(), 'Invalid') === true
                || str_contains($e->getMessage(), 'must be') === true
                || str_contains($e->getMessage(), 'required') === true
                || str_contains($e->getMessage(), 'format') === true
                || str_contains($e->getMessage(), 'Property at') === true
                || str_contains($e->getMessage(), 'authorization') === true
            ) {
                // Return 400 Bad Request for validation errors with actual error message.
                return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
            }

            // For database constraint violations, return 409 Conflict.
            if (str_contains($e->getMessage(), 'constraint') === true
                || str_contains($e->getMessage(), 'duplicate') === true
                || str_contains($e->getMessage(), 'unique') === true
            ) {
                return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
            }

            // Return 500 for other unexpected errors with actual error message.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end create()

    /**
     * Updates an existing schema
     *
     * This method updates an existing schema based on its ID.
     *
     * @param int $id The ID of the schema to update
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
<<<<<<< HEAD
     * @suppressWarnings(PHPMD.StaticAccess)         DatabaseConstraintException factory method is standard pattern
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
=======
     * @SuppressWarnings(PHPMD.StaticAccess)          DatabaseConstraintException::fromDatabaseException is a named constructor — no DI alternative.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple message-substring checks for error classification; each adds one branch.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple message-substring checks for error classification; each adds one branch.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Error-classification block is repetitive but
     * intentional; extracting it would not reduce cognitive load.
     * @SuppressWarnings(PHPMD.ShortVariable)         $id matches the {id} URL route parameter; renaming breaks route binding.
>>>>>>> origin/development
     *
     * @return JSONResponse JSON response with updated schema or error
     *
     * @psalm-return JSONResponse<200, Schema,
<<<<<<< HEAD
     *     array<never, never>>|JSONResponse<int, array{error: string},
     *     array<never, never>>
=======
     *     array<never, never>>|JSONResponse<400|403|404|409|500, array{error: string},
     *     array<never, never>>
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
>>>>>>> origin/development
     */
    public function update(int $id): JSONResponse
    {
        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove immutable fields to prevent tampering.
        unset($data['id']);
        unset($data['organisation']);
        unset($data['owner']);
        unset($data['created']);

<<<<<<< HEAD
        // Check manage permission if authorization field is being modified.
        $oldSchemaAuth = null;
        if (isset($data['authorization']) === true) {
            try {
                $existingSchema    = $this->schemaMapper->find($id);
                $oldSchemaAuth     = $existingSchema->getAuthorization();
                $permissionHandler = $this->container->get(PermissionHandler::class);
                if ($permissionHandler->hasPermission(
                    $existingSchema,
                    'manage'
                ) === false
                ) {
                    return new JSONResponse(
                        data: ['error' => 'User does not have permission to manage authorization for this schema'],
                        statusCode: 403
                    );
                }
            } catch (DoesNotExistException $e) {
                return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
            }
        }

=======
        // Authorization: modifying a schema's definition requires manage
        // permission. This gates ALL updates, not just authorization changes —
        // reading schema metadata stays open for frontends.
        try {
            $existingSchema = $this->schemaMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
        }

        if ($this->checkSchemaManagePermission(schema: $existingSchema) === false) {
            return new JSONResponse(
                data: ['error' => 'User does not have permission to manage this schema'],
                statusCode: 403
            );
        }

        // Capture prior authorization so a change can be audit-logged below.
        $oldSchemaAuth = $existingSchema->getAuthorization();

>>>>>>> origin/development
        try {
            // Update the schema with the provided data.
            $updatedSchema = $this->schemaMapper->updateFromArray(id: $id, object: $data);

            // Log authorization change if authorization was modified.
            if (isset($data['authorization']) === true) {
                try {
                    $auditService = $this->container->get(AuthorizationAuditService::class);
                    $auditService->logSchemaAuthorizationChange(
                        $updatedSchema->getId(),
                        $updatedSchema->getTitle() ?? '',
                        $oldSchemaAuth,
                        $updatedSchema->getAuthorization()
                    );
                } catch (\Throwable $e) {
                    // Audit logging should not break the update operation.
                }
            }

<<<<<<< HEAD
            // **CACHE INVALIDATION**: Clear all schema-related caches when schema is updated.
            $this->schemaCacheService->invalidateForSchemaChange(schemaId: $updatedSchema->getId(), operation: 'update');
=======
            // **CACHE INVALIDATION (runtime-schema-api)**: Clear all schema-related
            // caches when a schema is updated. `invalidate()` is the runtime-schema-api
            // entry point — it covers the legacy `invalidateForSchemaChange` cleanup AND
            // drops the request-scoped find cache on the mapper itself so reads in the
            // same worker observe the new state.
            $this->schemaCacheService->invalidate(schemaId: $updatedSchema->getId());
>>>>>>> origin/development
            $this->facetCacheSvc->invalidateForSchemaChange(
                schemaId: $updatedSchema->getId(),
                operation: 'update'
            );

            return new JSONResponse(data: $updatedSchema);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(
                dbException: $e,
                entityType: 'schema'
            );
            return new JSONResponse(
                data: ['error' => $constraintException->getMessage()],
                statusCode: $constraintException->getHttpStatusCode()
            );
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions.
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: $e->getHttpStatusCode()
            );
        } catch (Exception $e) {
            // Log the actual error for debugging.
            $this->logger->error(
                message: '[SchemasController] Schema update failed',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'schema_id'     => $id,
                    'error_message' => $e->getMessage(),
                    'error_code'    => $e->getCode(),
                    'trace'         => $e->getTraceAsString(),
                ]
            );

            // Check if this is a validation error by examining the message.
            if (str_contains($e->getMessage(), 'Invalid') === true
                || str_contains($e->getMessage(), 'must be') === true
                || str_contains($e->getMessage(), 'required') === true
                || str_contains($e->getMessage(), 'format') === true
                || str_contains($e->getMessage(), 'Property at') === true
                || str_contains($e->getMessage(), 'authorization') === true
            ) {
                // Return 400 Bad Request for validation errors with actual error message.
                return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
            }

            // For database constraint violations, return 409 Conflict.
            if (str_contains($e->getMessage(), 'constraint') === true
                || str_contains($e->getMessage(), 'duplicate') === true
                || str_contains($e->getMessage(), 'unique') === true
            ) {
                return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
            }

            // Return 500 for other unexpected errors with actual error message.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end update()

    /**
     * Patch (partially update) a schema
     *
     * @param int $id The ID of the schema to patch
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with patched schema or error
     *
     * @psalm-return JSONResponse<200, Schema,
<<<<<<< HEAD
     *     array<never, never>>|JSONResponse<int, array{error: string},
     *     array<never, never>>
=======
     *     array<never, never>>|JSONResponse<400|403|404|409|500, array{error: string},
     *     array<never, never>>
     *
     * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
>>>>>>> origin/development
     */
    public function patch(int $id): JSONResponse
    {
        return $this->update(id: $id);
    }//end patch()

    /**
     * Deletes a schema
     *
     * This method deletes a schema based on its ID.
     *
     * @param int $id The ID of the schema to delete
     *
     * @throws Exception If there is an error deleting the schema
     *
     * @return JSONResponse An empty JSON response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|409|500, array{error?: string}, array<never, never>>
<<<<<<< HEAD
=======
     *
     * @SuppressWarnings(PHPMD.ShortVariable)        $id matches the {id} URL route parameter; renaming breaks route binding.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Force-flag and orphan-count branches are inherent to a safe delete endpoint.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
>>>>>>> origin/development
     */
    public function destroy(int $id): JSONResponse
    {
        // **DELETE SAFETY (runtime-schema-api)**: Count attached objects FIRST.
        // If N > 0 and ?force=true is not set, refuse with HTTP 409 so the caller
        // gets a structured error containing the orphan count and can decide
        // whether to escalate. A bare DELETE on a schema with objects is the
        // canonical foot-gun that this guard closes.
        $forceParam = $this->request->getParam(key: 'force', default: null);
        $force      = (string) $forceParam === 'true' || $forceParam === true || $forceParam === '1';

        try {
<<<<<<< HEAD
            // Find the schema by ID, delete it, and invalidate caches.
=======
            // Find the schema first (also validates existence and access).
>>>>>>> origin/development
            $schemaToDelete = $this->schemaMapper->find(id: $id);

            // Authorization: deleting a schema requires manage permission.
            // Reading schema metadata stays open for frontends.
            if ($this->checkSchemaManagePermission(schema: $schemaToDelete) === false) {
                return new JSONResponse(
                    data: ['error' => 'User does not have permission to manage this schema'],
                    statusCode: 403
                );
            }

            // Count objects still referencing this schema across all registers.
            // Use getStatistics() (single-axis schemaId path) — countSearchObjects()
            // only returns a real count when BOTH register AND schema are present
            // in the @self filter, and silently returns 0 on single-axis queries,
            // which would let DELETE silently succeed on schemas with objects.
            $objectStats = $this->objectEntityMapper->getStatistics(registerId: null, schemaId: $schemaToDelete->getId());
            $objectCount = (int) $objectStats['total'];

            if ($objectCount > 0 && $force === false) {
                // Refuse: structured 409 with the orphan count for the caller.
                return new JSONResponse(
                    data: [
                        'error'       => 'schema-has-objects',
                        'objectCount' => $objectCount,
                    ],
                    statusCode: 409
                );
            }

            if ($objectCount > 0 && $force === true) {
                // Force-delete with audit trail at WARNING level: a misused force flag
                // orphans every object referencing this schema, so log who did it.
                $this->logger->warning(
                    message: '[SchemasController] Force-deleting schema with attached objects',
                    context: [
                        'file'        => __FILE__,
                        'line'        => __LINE__,
                        'schemaId'    => $schemaToDelete->getId(),
                        'schemaSlug'  => $schemaToDelete->getSlug(),
                        'objectCount' => $objectCount,
                    ]
                );
            }

            $this->schemaMapper->delete($schemaToDelete);

<<<<<<< HEAD
            // **CACHE INVALIDATION**: Clear all schema-related caches when schema is deleted.
            $this->schemaCacheService->invalidateForSchemaChange(
                schemaId: $schemaToDelete->getId(),
                operation: 'delete'
            );
=======
            // **CACHE INVALIDATION (runtime-schema-api)**: invalidate() is the
            // canonical entry point — covers in-memory, persistent cache table,
            // AND the request-scoped find cache on the mapper.
            $this->schemaCacheService->invalidate(schemaId: $schemaToDelete->getId());
>>>>>>> origin/development
            $this->facetCacheSvc->invalidateForSchemaChange(
                schemaId: $schemaToDelete->getId(),
                operation: 'delete'
            );

            // Return an empty response.
            return new JSONResponse(data: []);
        } catch (\OCA\OpenRegister\Exception\ValidationException $e) {
            // Return 409 Conflict for cascade protection (objects still attached).
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
        } catch (\Exception $e) {
            // Return 500 for other errors.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end destroy()

    /**
     * Updates an existing Schema object using a json text/string as input
     *
     * Uses 'file', 'url' or else 'json' from POST body.
     *
     * @param int|null $id The ID of the schema to update, or null for a new schema
     *
     * @throws Exception If there is a database error
     *
     * @throws GuzzleException If there is an HTTP request error
     *
     * @return JSONResponse The JSON response with the updated schema
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-4
     */
    public function uploadUpdate(?int $id=null): JSONResponse
    {
        return $this->upload(id: $id);
    }//end uploadUpdate()

    /**
     * Creates a new Schema object or updates an existing one
     *
     * Uses 'file', 'url' or else 'json' from POST body.
     *
     * @param int|null $id The ID of the schema to update, or null for a new schema
     *
     * @throws Exception If there is a database error
     *
     * @throws GuzzleException If there is an HTTP request error
     *
     * @return JSONResponse The JSON response with the created or updated schema
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
<<<<<<< HEAD
     * @suppressWarnings(PHPMD.StaticAccess)          Uuid::v4 and DatabaseConstraintException factory are standard patterns
     * @suppressWarnings(PHPMD.ExcessiveMethodLength)
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
     * @suppressWarnings(PHPMD.NPathComplexity)
=======
     * @SuppressWarnings(PHPMD.StaticAccess)          Uuid::v4 is a named constructor and
     * DatabaseConstraintException::fromDatabaseException is a factory — no DI alternatives.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) JSON-upload path merges insert + update branches;
     * splitting would duplicate error classification.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple message-substring checks for error classification; each adds one branch.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple message-substring checks for error classification; each adds one branch.
     * @SuppressWarnings(PHPMD.ShortVariable)         $id matches the {id} URL route parameter; renaming breaks route binding.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-4
>>>>>>> origin/development
     */
    public function upload(?int $id=null): JSONResponse
    {
        // Default: create a new schema.
        $schema = new Schema();
        $schema->setUuid(Uuid::v4()->toRfc4122());
        if ($id !== null) {
            // If ID is provided, find the existing schema.
            $schema = $this->schemaMapper->find($id);
<<<<<<< HEAD
=======
        }

        // SECURITY (H3): gate schema uploads on appropriate permissions.
        // Updating an existing schema requires manage-permission (same as update/destroy).
        // Creating a new schema requires admin (same as create).
        if ($id !== null && $this->checkSchemaManagePermission(schema: $schema) === false) {
            return new JSONResponse(
                data: ['error' => 'You do not have permission to update this schema'],
                statusCode: 403
            );
        }

        if ($id === null && $this->isCurrentUserAdmin() === false) {
            return new JSONResponse(
                data: ['error' => 'Admin privileges required to upload new schemas'],
                statusCode: 403
            );
>>>>>>> origin/development
        }

        // Get the uploaded JSON data.
        $phpArray = $this->uploadService->getUploadedJson($this->request->getParams());
        if ($phpArray instanceof JSONResponse) {
            // Return any error response from the upload service.
            return $phpArray;
        }

        // Set default title if not provided or empty.
        if (empty($phpArray['title']) === true) {
            $phpArray['title'] = 'New Schema';
        }

        try {
            // Update the schema with the data from the uploaded JSON.
            $schema->hydrate($phpArray);

            // Track whether this is a new schema before potential insert.
            $isNewSchema = ($schema->getId() === null);

            if ($isNewSchema === true) {
                // Insert a new schema if no ID is set.
                $schema = $this->schemaMapper->insert($schema);

                // Set organisation from active organisation for multi-tenancy (if not already set).
                if ($schema->getOrganisation() === null || $schema->getOrganisation() === '') {
                    $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
                    $schema->setOrganisation($organisationUuid);
                    $schema = $this->schemaMapper->update($schema);
                }

                // **CACHE INVALIDATION**: Clear all schema-related caches when schema is created.
                $this->schemaCacheService->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'create');
                $this->facetCacheSvc->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'create');
            }

            if ($isNewSchema === false) {
                // Update the existing schema.
                $schema = $this->schemaMapper->update($schema);

                // **CACHE INVALIDATION**: Clear all schema-related caches when schema is updated.
                $this->schemaCacheService->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'update');
                $this->facetCacheSvc->invalidateForSchemaChange(
                    schemaId: $schema->getId(),
                    operation: 'update'
                );
            }

            return new JSONResponse(data: $schema);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(
                dbException: $e,
                entityType: 'schema'
            );
            return new JSONResponse(
                data: ['error' => $constraintException->getMessage()],
                statusCode: $constraintException->getHttpStatusCode()
            );
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatusCode());
        } catch (Exception $e) {
            // Log the actual error for debugging.
            $this->logger->error(
                message: '[SchemasController] Schema upload failed',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'schema_id'     => $id,
                    'error_message' => $e->getMessage(),
                    'error_code'    => $e->getCode(),
                    'trace'         => $e->getTraceAsString(),
                ]
            );

            // Check if this is a validation error by examining the message.
            if (str_contains($e->getMessage(), 'Invalid') === true
                || str_contains($e->getMessage(), 'must be') === true
                || str_contains($e->getMessage(), 'required') === true
                || str_contains($e->getMessage(), 'format') === true
                || str_contains($e->getMessage(), 'Property at') === true
                || str_contains($e->getMessage(), 'authorization') === true
            ) {
                // Return 400 Bad Request for validation errors with actual error message.
                return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
            }

            // For database constraint violations, return 409 Conflict.
            if (str_contains($e->getMessage(), 'constraint') === true
                || str_contains($e->getMessage(), 'duplicate') === true
                || str_contains($e->getMessage(), 'unique') === true
            ) {
                return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
            }

            // Return 500 for other unexpected errors with actual error message.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end upload()

    /**
     * Creates and return a json file for a Schema
     *
     * @param int $id The ID of the schema to return json file for
     *
     * @throws Exception If there is an error retrieving the schema
     *
     * @return JSONResponse A JSON response containing the schema as JSON
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, Schema,
     *     array<never, never>>|JSONResponse<404,
     *     array{error: 'Schema not found'}, array<never, never>>
<<<<<<< HEAD
=======
     *
     * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-4
>>>>>>> origin/development
     */
    public function download(int $id): JSONResponse
    {
        // Note: Accept header not currently used - always returns JSON.
        try {
            // Metadata-read bypass per auth-system "Schema and register
            // METADATA-READ lookups MUST bypass multi-tenancy" — download
            // serves the schema definition for export/consumer use.
            $schema = $this->schemaMapper->find($id, _multitenancy: false);
        } catch (Exception $e) {
            // Return a 404 error if the schema doesn't exist.
            return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
        }

        // Return the schema as JSON.
        return new JSONResponse(data: $schema);
    }//end download()

    /**
     * Get schemas that have properties referencing the given schema
     *
     * This method finds schemas that contain properties with $ref values pointing
     * to the specified schema, indicating a relationship between schemas.
     *
     * @param int|string $id The ID, UUID, or slug of the schema to find relationships for
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with related schemas
<<<<<<< HEAD
=======
     *
     * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-4
>>>>>>> origin/development
     */
    public function related(int|string $id): JSONResponse
    {
        try {
            // Find related schemas using the SchemaMapper (incoming references).
            $incomingSchemas      = $this->schemaMapper->getRelated($id);
            $incomingSchemasArray = array_map(fn($schema) => $schema->jsonSerialize(), $incomingSchemas);

            // Find outgoing references: schemas that this schema refers to.
<<<<<<< HEAD
            $targetSchema    = $this->schemaMapper->find($id);
=======
            // Metadata-read bypass per auth-system "Schema and register
            // METADATA-READ lookups MUST bypass multi-tenancy" — related-schema
            // resolution is a catalog read (no object data exposed).
            $targetSchema    = $this->schemaMapper->find($id, _multitenancy: false);
>>>>>>> origin/development
            $properties      = $targetSchema->getProperties() ?? [];
            $allSchemas      = $this->schemaMapper->findAll(_multitenancy: false);
            $outgoingSchemas = [];
            foreach ($allSchemas as $schema) {
                // Skip self.
                if ($schema->getId() === $targetSchema->getId()) {
                    continue;
                }

                // Use the same reference logic as getRelated, but reversed.
                if ($this->schemaMapper->hasReferenceToSchema(
                        properties: $properties,
                        targetSchemaId: (string) $schema->getId(),
                        targetSchemaUuid: $schema->getUuid() ?? '',
                        targetSchemaSlug: $schema->getSlug() ?? ''
                    ) === true
                ) {
                    $outgoingSchemas[$schema->getId()] = $schema;
                }
            }

            $outgoingSchemasArray = array_map(fn($schema) => $schema->jsonSerialize(), array_values($outgoingSchemas));

            return new JSONResponse(
                data: [
                    'incoming' => $incomingSchemasArray,
                    'outgoing' => $outgoingSchemasArray,
                    'total'    => count($incomingSchemasArray) + count($outgoingSchemasArray),
                ]
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Return a 404 error if the target schema doesn't exist.
            return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
        } catch (Exception $e) {
            // Return a 500 error for other exceptions.
            return new JSONResponse(data: ['error' => 'Internal server error: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end related()

    /**
     * Get statistics for a specific schema
     *
     * @param int $id The schema ID
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the schema is not found
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with schema statistics
<<<<<<< HEAD
=======
     *
     * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-6
>>>>>>> origin/development
     */
    public function stats(int $id): JSONResponse
    {
        try {
<<<<<<< HEAD
            // Get the schema.
            $schema = $this->schemaMapper->find($id);
=======
            // Metadata-read bypass per auth-system "Schema and register
            // METADATA-READ lookups MUST bypass multi-tenancy" — stats over
            // a schema is a catalog read; the underlying object-row
            // statistics remain tenant-scoped via MultiTenancyTrait.
            $schema = $this->schemaMapper->find($id, _multitenancy: false);
>>>>>>> origin/development

            if ($schema === null) {
                return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
            }

            // Get detailed object statistics for this schema using the existing method.
            $objectStats = $this->objectEntityMapper->getStatistics(registerId: null, schemaId: $id);

            // Calculate comprehensive statistics for this schema.
            $stats = [
                'objectCount'   => $objectStats['total'],
            // Keep for backward compatibility.
                'objects_count' => $objectStats['total'],
            // Alternative field name for compatibility.
                'objects'       => [
                    'total'   => $objectStats['total'],
                    'invalid' => $objectStats['invalid'],
                    'deleted' => $objectStats['deleted'],
                    'locked'  => $objectStats['locked'],
                    'size'    => $objectStats['size'],
                ],
                'logs'          => $this->auditTrailMapper->getStatistics(registerId: null, schemaId: $id),
                'files'         => ['total' => 0, 'size' => 0],
                // Placeholder for future file statistics.
                'registers'     => $this->schemaMapper->getRegisterCountPerSchema()[$id] ?? 0,
            ];

            return new JSONResponse(data: $stats);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end stats()

    /**
     * Explore schema properties to discover new properties in objects
     *
     * Analyzes all objects belonging to a schema to discover properties that exist
     * in the object data but are not defined in the schema. This is useful for
     * identifying properties that were added during imports or when validation
     * was disabled.
     *
     * @param int $id The ID of the schema to explore
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with exploration results
<<<<<<< HEAD
=======
     *
     * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-4
>>>>>>> origin/development
     */
    public function explore(int $id): JSONResponse
    {
        try {
            $this->logger->info(
                message: '[SchemasController] Starting schema exploration for schema ID: '.$id,
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            $explorationResults = $this->schemaService->exploreSchemaProperties($id);

            $this->logger->info(
                message: '[SchemasController] Schema exploration completed successfully',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            return new JSONResponse(data: $explorationResults);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[SchemasController] Schema exploration failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end explore()

    /**
     * Update schema properties based on exploration results
     *
     * Applies user-confirmed property updates to a schema based on exploration
     * results. This allows schemas to be updated with newly discovered properties.
     *
     * @param int $id The ID of the schema to update
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated schema
<<<<<<< HEAD
=======
     *
     * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-4
>>>>>>> origin/development
     */
    public function updateFromExploration(int $id): JSONResponse
    {
        try {
            // Get property updates from request.
            $propertyUpdates = $this->request->getParam(key: 'properties', default: []);
<<<<<<< HEAD

            if (empty($propertyUpdates) === true) {
                return new JSONResponse(data: ['error' => 'No property updates provided'], statusCode: 400);
            }

            $updateCount = count($propertyUpdates);
            $this->logger->info(
                message: "[SchemasController] Updating schema {$id} with {$updateCount} property updates",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            $updatedSchema = $this->schemaService->updateSchemaFromExploration(
                schemaId: $id,
                propertyUpdates: $propertyUpdates
            );

            // Clear schema cache to ensure fresh data.
            $this->schemaCacheService->clearSchemaCache($id);

=======

            if (empty($propertyUpdates) === true) {
                return new JSONResponse(data: ['error' => 'No property updates provided'], statusCode: 400);
            }

            $updateCount = count($propertyUpdates);
            $this->logger->info(
                message: "[SchemasController] Updating schema {$id} with {$updateCount} property updates",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            $updatedSchema = $this->schemaService->updateSchemaFromExploration(
                schemaId: $id,
                propertyUpdates: $propertyUpdates
            );

            // Clear schema cache to ensure fresh data.
            $this->schemaCacheService->clearSchemaCache($id);

>>>>>>> origin/development
            $this->logger->info(
                message: '[SchemasController] Schema '.$id.' successfully updated with exploration results',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            return new JSONResponse(
                data: [
                    'success' => true,
                    'schema'  => $updatedSchema->jsonSerialize(),
                    'message' => 'Schema updated successfully with '.count($propertyUpdates).' properties',
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[SchemasController] Failed to update schema from exploration: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end updateFromExploration()

    /**
     * Whether the current request has no resolved Nextcloud user (anonymous).
     *
     * Read endpoints are @PublicPage so they survive an app-group restriction;
     * this lets the read-visibility guard distinguish anonymous callers (who may
     * only see published resources) from authenticated users (unaffected). The
     * user session is resolved lazily from the container.
     *
<<<<<<< HEAD
     * @param int $id The ID of the schema to publish
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with published schema
     *
     * @psalm-return JSONResponse<200|400|404,
     *     array{error?: string, id?: int, uuid?: null|string, uri?: null|string,
     *     slug?: null|string, title?: null|string, description?: null|string,
     *     version?: null|string, summary?: null|string, icon?: null|string,
     *     required?: array, properties?: array, archive?: array|null,
     *     source?: null|string, hardValidation?: bool, immutable?: bool,
     *     searchable?: bool, updated?: null|string, created?: null|string,
     *     maxDepth?: int, owner?: null|string, application?: null|string,
     *     organisation?: null|string,
     *     groups?: array<string, list<string>>|null,
     *     authorization?: array|null, deleted?: null|string,
     *     published?: null|string, depublished?: null|string,
     *     configuration?: array|null|string, allOf?: array|null,
     *     oneOf?: array|null, anyOf?: array|null}, array<never, never>>
=======
     * @return bool True if no user is signed in.
>>>>>>> origin/development
     */
    private function isAnonymousRequest(): bool
    {
        try {
<<<<<<< HEAD
            // Get the publication date from request if provided, otherwise use now.
            $date = new DateTime();
            if ($this->request->getParam('date') !== null) {
                $date = new DateTime($this->request->getParam('date'));
            }

            // Get the schema.
            $schema = $this->schemaMapper->find($id);

            // Set published date and clear depublished date if set.
            $schema->setPublished($date);
            $schema->setDepublished(null);

            // Update the schema.
            $updatedSchema = $this->schemaMapper->update($schema);

            // **CACHE INVALIDATION**: Clear schema cache when publication status changes
            $this->schemaCacheService->invalidateForSchemaChange(
                schemaId: $updatedSchema->getId(),
                operation: 'publish'
            );
            $this->facetCacheSvc->invalidateForSchemaChange(
                schemaId: $updatedSchema->getId(),
                operation: 'publish'
            );

            $this->logger->info(
                message: '[SchemasController] Schema published',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'schema_id'      => $id,
                    'published_date' => $date->format('Y-m-d H:i:s'),
                ]
            );

            return new JSONResponse($updatedSchema->jsonSerialize());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Schema not found'], 404);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[SchemasController] Failed to publish schema',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'schema_id' => $id,
                    'error'     => $e->getMessage(),
                ]
            );
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end publish()

=======
            return $this->container->get(\OCP\IUserSession::class)->getUser() === null;
        } catch (\Throwable $e) {
            // If the session service is unavailable, treat the caller as anonymous (fail closed).
            return true;
        }

    }//end isAnonymousRequest()

>>>>>>> origin/development
    /**
     * Whether an entity is currently published.
     *
     * A resource is published when its `published` timestamp is set and it has
     * not since been depublished. Both values come from persisted entity state.
     *
     * @param \DateTime|null $published   The published timestamp, or null.
     * @param \DateTime|null $depublished The depublished timestamp, or null.
     *
<<<<<<< HEAD
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with depublished schema
     *
     * @psalm-return JSONResponse<200|400|404,
     *     array{error?: string, id?: int, uuid?: null|string, uri?: null|string,
     *     slug?: null|string, title?: null|string, description?: null|string,
     *     version?: null|string, summary?: null|string, icon?: null|string,
     *     required?: array, properties?: array, archive?: array|null,
     *     source?: null|string, hardValidation?: bool, immutable?: bool,
     *     searchable?: bool, updated?: null|string, created?: null|string,
     *     maxDepth?: int, owner?: null|string, application?: null|string,
     *     organisation?: null|string,
     *     groups?: array<string, list<string>>|null,
     *     authorization?: array|null, deleted?: null|string,
     *     published?: null|string, depublished?: null|string,
     *     configuration?: array|null|string, allOf?: array|null,
     *     oneOf?: array|null, anyOf?: array|null}, array<never, never>>
=======
     * @return bool True if the entity is published and not depublished.
>>>>>>> origin/development
     */
    private function isPublishedEntity(?\DateTime $published, ?\DateTime $depublished): bool
    {
        return $published !== null && $depublished === null;

    }//end isPublishedEntity()

    /**
     * Check whether the currently authenticated user is a Nextcloud administrator.
     *
     * Used to gate schema creation, where there is no existing entity whose
     * manage-authorization block could be consulted. User session and group
     * manager are resolved lazily from the container to avoid widening the
     * constructor signature.
     *
     * @return bool True if a user is signed in and belongs to the admin group.
     */
    private function isCurrentUserAdmin(): bool
    {
        try {
<<<<<<< HEAD
            // Get the depublication date from request if provided, otherwise use now.
            $date = new DateTime();
            if ($this->request->getParam('date') !== null) {
                $date = new DateTime($this->request->getParam('date'));
            }

            // Get the schema.
            $schema = $this->schemaMapper->find($id);

            // Set depublished date.
            $schema->setDepublished($date);

            // Update the schema.
            $updatedSchema = $this->schemaMapper->update($schema);

            // **CACHE INVALIDATION**: Clear schema cache when publication status changes
            $this->schemaCacheService->invalidateForSchemaChange(
                schemaId: $updatedSchema->getId(),
                operation: 'depublish'
            );
            $this->facetCacheSvc->invalidateForSchemaChange(
                schemaId: $updatedSchema->getId(),
                operation: 'depublish'
            );

            $this->logger->info(
                message: '[SchemasController] Schema depublished',
                context: [
                    'file'             => __FILE__,
                    'line'             => __LINE__,
                    'schema_id'        => $id,
                    'depublished_date' => $date->format('Y-m-d H:i:s'),
                ]
            );

            return new JSONResponse($updatedSchema->jsonSerialize());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Schema not found'], 404);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[SchemasController] Failed to depublish schema',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'schema_id' => $id,
                    'error'     => $e->getMessage(),
                ]
            );
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end depublish()
=======
            $user = $this->container->get(\OCP\IUserSession::class)->getUser();
            if ($user === null) {
                return false;
            }

            return $this->container->get(\OCP\IGroupManager::class)->isAdmin($user->getUID());
        } catch (\Throwable $e) {
            return false;
        }

    }//end isCurrentUserAdmin()

    /**
     * Check if the current user has 'manage' permission on a schema.
     *
     * Default-SECURE: a schema with no `manage` authorization rule can only be
     * managed by administrators. When manage rules are present, membership of
     * one of the listed groups grants permission (admins always pass). This is
     * deliberately NOT PermissionHandler::hasPermission(), which is default-OPEN
     * for object data RBAC and therefore unsuitable for gating schema-definition
     * writes. Reading schema metadata is unaffected and stays open.
     *
     * @param Schema $schema The schema to check manage permission for.
     *
     * @return bool True if the current user may manage this schema.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function checkSchemaManagePermission(Schema $schema): bool
    {
        try {
            $user = $this->container->get(\OCP\IUserSession::class)->getUser();
            if ($user === null) {
                return false;
            }

            $groupManager = $this->container->get(\OCP\IGroupManager::class);

            // Admins always pass.
            if ($groupManager->isAdmin($user->getUID()) === true) {
                return true;
            }

            $authorization = $schema->getAuthorization();

            // Default-secure: no manage rule defined → admin-only (already failed above).
            if (empty($authorization) === true || isset($authorization['manage']) === false) {
                return false;
            }

            $userGroups  = $groupManager->getUserGroupIds($user);
            $manageRules = $authorization['manage'];
            foreach ($userGroups as $groupId) {
                foreach ($manageRules as $entry) {
                    if (is_string($entry) === true && $entry === $groupId) {
                        return true;
                    }

                    if (is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $groupId) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            return false;
        }//end try

        return false;

    }//end checkSchemaManagePermission()
>>>>>>> origin/development
}//end class
