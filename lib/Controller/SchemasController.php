<?php
/**
 * SchemasController handles REST API endpoints for schema management
 *
 * Controller for managing schema operations in the OpenRegister app.
 * Provides endpoints for CRUD operations, schema exploration, caching,
 * import/export, and statistics.
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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\DownloadService;
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
 */
class SchemasController extends Controller
{
    /**
     * Constructor
     *
     * Initializes controller with required dependencies for schema operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string              $appName                 Application name
     * @param IRequest            $request                 HTTP request object
     * @param IAppConfig          $config                  App configuration for settings
     * @param SchemaMapper        $schemaMapper            Schema mapper for database operations
     * @param ObjectEntityMapper  $objectEntityMapper      Object entity mapper for object queries
     * @param DownloadService     $downloadService         Download service for file downloads
     * @param UploadService       $uploadService           Upload service for file uploads
     * @param AuditTrailMapper    $auditTrailMapper        Audit trail mapper for log statistics
     * @param OrganisationService $organisationService     Organisation service for multi-tenancy
     * @param SchemaCacheHandler  $schemaCacheService      Schema cache handler for caching operations
     * @param FacetCacheHandler   $schemaFacetCacheService Schema facet cache service for facet caching
     * @param SchemaService       $schemaService           Schema service for exploration operations
     * @param LoggerInterface     $logger                  Logger for error tracking
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly DownloadService $downloadService,
        private readonly UploadService $uploadService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly OrganisationService $organisationService,
        private readonly SchemaCacheHandler $schemaCacheService,
        private readonly FacetCacheHandler $schemaFacetCacheService,
        private readonly SchemaService $schemaService,
        private readonly LoggerInterface $logger
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
     * @psalm-return JSONResponse<200, array{results: array<array{id: int, uuid: null|string, uri: null|string, slug: null|string, title: null|string, description: null|string, version: null|string, summary: null|string, icon: null|string, required: array, properties: array, archive: array|null, source: null|string, hardValidation: bool, immutable: bool, searchable: bool, updated: null|string, created: null|string, maxDepth: int, owner: null|string, application: null|string, organisation: null|string, groups: array<string, list<string>>|null, authorization: array|null, deleted: null|string, published: null|string, depublished: null|string, configuration: array|null|string, allOf: array|null, oneOf: array|null, anyOf: array|null}>}, array<never, never>>
     */
    public function index(): JSONResponse
    {
        // Get request parameters for filtering and searching.
        $params = $this->request->getParams();

        // Extract pagination and search parameters.
        if (isset($params['_limit']) === true) {
            $limit = (int) $params['_limit'];
        } else {
            $limit = null;
        }

        if (isset($params['_offset']) === true) {
            $offset = (int) $params['_offset'];
        } else {
            $offset = null;
        }

        if (isset($params['_page']) === true) {
            $page = (int) $params['_page'];
        } else {
            $page = null;
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
            _extend: []
        );

        // Serialize schemas to arrays.
        $schemasArr = array_map(
            function ($schema) {
                return $schema->jsonSerialize();
            },
            $schemas
        );

        // Add extendedBy property to each schema showing UUIDs of schemas that extend it.
        foreach ($schemasArr as &$schema) {
            $schema['@self'] = $schema['@self'] ?? [];
            $schema['@self']['extendedBy'] = $this->schemaMapper->findExtendedBy($schema['id']);
        }

        unset($schema);
        // Break the reference.
        // If '@self.stats' is requested, attach statistics to each schema.
        if (in_array('@self.stats', $extend, true) === true) {
            // Get register counts for all schemas in one call.
            $registerCounts = $this->schemaMapper->getRegisterCountPerSchema();
            foreach ($schemasArr as &$schema) {
                $schema['stats'] = [
                    'objects'   => $this->objectEntityMapper->getStatistics(registerId: null, schemaId: $schema['id']),
                    'logs'      => $this->auditTrailMapper->getStatistics(registerId: null, schemaId: $schema['id']),
                    'files'     => [ 'total' => 0, 'size' => 0 ],
                    // Add the number of registers referencing this schema.
                    'registers' => $registerCounts[$schema['id']] ?? 0,
                ];
            }
        }

        return new JSONResponse(data: ['results' => $schemasArr]);

    }//end index()

    /**
     * Retrieves a single schema by ID
     *
     * @param int|string $id The ID of the schema
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, array{id: int, uuid: null|string, uri: null|string, slug: null|string, title: null|string, description: null|string, version: null|string, summary: null|string, icon: null|string, required: array, properties: array, archive: array|null, source: null|string, hardValidation: bool, immutable: bool, searchable: bool, updated: null|string, created: null|string, maxDepth: int, owner: null|string, application: null|string, organisation: null|string, groups: array<string, list<string>>|null, authorization: array|null, deleted: null|string, published: null|string, depublished: null|string, configuration: array|null|string, allOf: array|null, oneOf: array|null, anyOf: array|null, '@self': array{extendedBy: list<mixed>}|mixed, stats?: array{objects: array, logs: array{total: int, size: int}, files: array{total: 0, size: 0}, registers: int}}, array<never, never>>
     */
    public function show($id): JSONResponse
    {
        $extend = $this->request->getParam(key: '_extend', default: []);
        if (is_string($extend) === true) {
            $extend = [$extend];
        }

            $schema    = $this->schemaMapper->find(id: $id, _extend: []);
            $schemaArr = $schema->jsonSerialize();

            // Add extendedBy property showing UUIDs of schemas that extend this schema.
            $schemaArr['@self'] = $schemaArr['@self'] ?? [];
            $schemaArr['@self']['extendedBy'] = $this->schemaMapper->findExtendedBy($id);

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
     * @psalm-return JSONResponse<201, Schema, array<never, never>>|JSONResponse<int, array{error: string}, array<never, never>>
     */
    public function create(): JSONResponse
    {
        // Get request parameters.
        $data = $this->request->getParams();

        // DEBUG: Log incoming request to track duplicate creation.
        $this->logger->info(
                '[SchemasController::create] Starting schema creation',
                [
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

            // NOTE: Organization should already be set from the request data.
            // The update() call below was causing duplicate schema creation with different timestamps.
            // Since createFromArray() already handles organization assignment, this is commented out.
            /*
                // Set organisation from active organisation for multi-tenancy (if not already set).
                if ($schema->getOrganisation() === null || $schema->getOrganisation() === '') {
                $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
                $schema->setOrganisation($organisationUuid);
                $schema = $this->schemaMapper->update($schema);
                }
            */

            return new JSONResponse(data: $schema, statusCode: 201);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(dbException: $e, entityType: 'schema');
            return new JSONResponse(data: ['error' => $constraintException->getMessage()], statusCode: $constraintException->getHttpStatusCode());
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatusCode());
        } catch (Exception $e) {
            // Log the actual error for debugging.
            $this->logger->error(
                    message: 'Schema creation failed',
                    context: [
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
     * @psalm-return JSONResponse<200, Schema, array<never, never>>|JSONResponse<int, array{error: string}, array<never, never>>
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

        try {
            // Update the schema with the provided data.
            $updatedSchema = $this->schemaMapper->updateFromArray(id: $id, object: $data);

            // **CACHE INVALIDATION**: Clear all schema-related caches when schema is updated.
            $this->schemaCacheService->invalidateForSchemaChange(schemaId: $updatedSchema->getId(), operation: 'update');
            $this->schemaFacetCacheService->invalidateForSchemaChange(schemaId: $updatedSchema->getId(), operation: 'update');

            return new JSONResponse(data: $updatedSchema);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(dbException: $e, entityType: 'schema');
            return new JSONResponse(data: ['error' => $constraintException->getMessage()], statusCode: $constraintException->getHttpStatusCode());
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatusCode());
        } catch (Exception $e) {
            // Log the actual error for debugging.
            $this->logger->error(
                    message: 'Schema update failed',
                    context: [
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
     * @return JSONResponse The updated schema data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, Schema, array<never, never>>|JSONResponse<int, array{error: string}, array<never, never>>
     */
    public function patch(int $id): JSONResponse
    {
        return $this->update($id);

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
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            // Find the schema by ID, delete it, and invalidate caches.
            $schemaToDelete = $this->schemaMapper->find(id: $id);
            $this->schemaMapper->delete($schemaToDelete);

            // **CACHE INVALIDATION**: Clear all schema-related caches when schema is deleted.
            $this->schemaCacheService->invalidateForSchemaChange(schemaId: $schemaToDelete->getId(), operation: 'delete');
            $this->schemaFacetCacheService->invalidateForSchemaChange(schemaId: $schemaToDelete->getId(), operation: 'delete');

            // Return an empty response.
            return new JSONResponse(data: []);
        } catch (\OCA\OpenRegister\Exception\ValidationException $e) {
            // Return 409 Conflict for cascade protection (objects still attached).
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
        } catch (\Exception $e) {
            // Return 500 for other errors.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

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
     */
    public function uploadUpdate(?int $id=null): JSONResponse
    {
        return $this->upload($id);

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
     */
    public function upload(?int $id=null): JSONResponse
    {
        if ($id !== null) {
            // If ID is provided, find the existing schema.
            $schema = $this->schemaMapper->find($id);
        } else {
            // Otherwise, create a new schema.
            $schema = new Schema();
            $schema->setUuid(Uuid::v4()->toRfc4122());
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

            if ($schema->getId() === null) {
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
                $this->schemaFacetCacheService->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'create');
            } else {
                // Update the existing schema.
                $schema = $this->schemaMapper->update($schema);

                // **CACHE INVALIDATION**: Clear all schema-related caches when schema is updated.
                $this->schemaCacheService->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'update');
                $this->schemaFacetCacheService->invalidateForSchemaChange(schemaId: $schema->getId(), operation: 'update');
            }//end if

            return new JSONResponse(data: $schema);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(dbException: $e, entityType: 'schema');
            return new JSONResponse(data: ['error' => $constraintException->getMessage()], statusCode: $constraintException->getHttpStatusCode());
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatusCode());
        } catch (Exception $e) {
            // Log the actual error for debugging.
            $this->logger->error(
                    'Schema upload failed',
                    [
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
     * @psalm-return JSONResponse<200, Schema, array<never, never>>|JSONResponse<404, array{error: 'Schema not found'}, array<never, never>>
     */
    public function download(int $id): JSONResponse
    {
        // Note: Accept header not currently used - always returns JSON.
        try {
            // Find the schema by ID.
            $schema = $this->schemaMapper->find($id);
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
     * @psalm-return JSONResponse<200|404|500, array{error?: string, incoming?: list<array{allOf: array|null, anyOf: array|null, application: null|string, archive: array|null, authorization: array|null, configuration: array|null|string, created: null|string, deleted: null|string, depublished: null|string, description: null|string, groups: array<string, list<string>>|null, hardValidation: bool, icon: null|string, id: int, immutable: bool, maxDepth: int, oneOf: array|null, organisation: null|string, owner: null|string, properties: array, published: null|string, required: array, searchable: bool, slug: null|string, source: null|string, summary: null|string, title: null|string, updated: null|string, uri: null|string, uuid: null|string, version: null|string}>, outgoing?: list<array{allOf: array|null, anyOf: array|null, application: null|string, archive: array|null, authorization: array|null, configuration: array|null|string, created: null|string, deleted: null|string, depublished: null|string, description: null|string, groups: array<string, list<string>>|null, hardValidation: bool, icon: null|string, id: int, immutable: bool, maxDepth: int, oneOf: array|null, organisation: null|string, owner: null|string, properties: array, published: null|string, required: array, searchable: bool, slug: null|string, source: null|string, summary: null|string, title: null|string, updated: null|string, uri: null|string, uuid: null|string, version: null|string}>, total?: int<0, max>}, array<never, never>>
     */
    public function related(int|string $id): JSONResponse
    {
        try {
            // Find related schemas using the SchemaMapper (incoming references).
            $incomingSchemas      = $this->schemaMapper->getRelated($id);
            $incomingSchemasArray = array_map(fn($schema) => $schema->jsonSerialize(), $incomingSchemas);

            // Find outgoing references: schemas that this schema refers to.
            $targetSchema    = $this->schemaMapper->find($id);
            $properties      = $targetSchema->getProperties() ?? [];
            $allSchemas      = $this->schemaMapper->findAll();
            $outgoingSchemas = [];
            foreach ($allSchemas as $schema) {
                // Skip self.
                if ($schema->getId() === $targetSchema->getId()) {
                    continue;
                }

                // Use the same reference logic as getRelated, but reversed.
                if ($this->schemaMapper->hasReferenceToSchema(properties: $properties, targetSchemaId: (string) $schema->getId(), targetSchemaUuid: $schema->getUuid() ?? '', targetSchemaSlug: $schema->getSlug() ?? '') === true) {
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
     * @return JSONResponse The schema statistics
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the schema is not found
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|404|500, array{error?: string, objectCount?: int, objects_count?: int, objects?: array{total: int, invalid: int, deleted: int, published: int, locked: int, size: int}, logs?: array, files?: array{total: 0, size: 0}, registers?: int}, array<never, never>>
     */
    public function stats(int $id): JSONResponse
    {
        try {
            // Get the schema.
            $schema = $this->schemaMapper->find($id);

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
                    'total'     => $objectStats['total'],
                    'invalid'   => $objectStats['invalid'],
                    'deleted'   => $objectStats['deleted'],
                    'published' => $objectStats['published'],
                    'locked'    => $objectStats['locked'],
                    'size'      => $objectStats['size'],
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
     * @psalm-return JSONResponse<200|500, array{error?: string, schema_id?: int, schema_title?: null|string, total_objects?: int<0, max>, discovered_properties?: array<never, never>|mixed, existing_properties?: array|null, property_usage_stats?: mixed, suggestions?: array, analysis_date?: string, data_types?: mixed, analysis_summary?: array{new_properties_count: int<0, max>, existing_properties_improvements: int<0, max>, total_recommendations: int<0, max>}, message?: 'No objects found for analysis'}, array<never, never>>
     */
    public function explore(int $id): JSONResponse
    {
        try {
            $this->logger->info('Starting schema exploration for schema ID: '.$id);

            $explorationResults = $this->schemaService->exploreSchemaProperties($id);

            $this->logger->info('Schema exploration completed successfully');

            return new JSONResponse(data: $explorationResults);
        } catch (\Exception $e) {
            $this->logger->error('Schema exploration failed: '.$e->getMessage());
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

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
     * @psalm-return JSONResponse<200|400|500, array{error?: string, success?: true, schema?: array{id: int, uuid: null|string, uri: null|string, slug: null|string, title: null|string, description: null|string, version: null|string, summary: null|string, icon: null|string, required: array, properties: array, archive: array|null, source: null|string, hardValidation: bool, immutable: bool, searchable: bool, updated: null|string, created: null|string, maxDepth: int, owner: null|string, application: null|string, organisation: null|string, groups: array<string, list<string>>|null, authorization: array|null, deleted: null|string, published: null|string, depublished: null|string, configuration: array|null|string, allOf: array|null, oneOf: array|null, anyOf: array|null}, message?: string}, array<never, never>>
     */
    public function updateFromExploration(int $id): JSONResponse
    {
        try {
            // Get property updates from request.
            $propertyUpdates = $this->request->getParam(key: 'properties', default: []);

            if (empty($propertyUpdates) === true) {
                return new JSONResponse(data: ['error' => 'No property updates provided'], statusCode: 400);
            }

            $this->logger->info('Updating schema '.$id.' with '.count($propertyUpdates).' property updates');

            $updatedSchema = $this->schemaService->updateSchemaFromExploration(schemaId: $id, propertyUpdates: $propertyUpdates);

            // Clear schema cache to ensure fresh data.
            $this->schemaCacheService->clearSchemaCache($id);

            $this->logger->info('Schema '.$id.' successfully updated with exploration results');

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'schema'  => $updatedSchema->jsonSerialize(),
                        'message' => 'Schema updated successfully with '.count($propertyUpdates).' properties',
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error('Failed to update schema from exploration: '.$e->getMessage());
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try

    }//end updateFromExploration()

    /**
     * Publish a schema
     *
     * This method publishes a schema by setting its publication date to now or a specified date.
     *
     * @param int $id The ID of the schema to publish
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404, array{error?: string, id?: int, uuid?: null|string, uri?: null|string, slug?: null|string, title?: null|string, description?: null|string, version?: null|string, summary?: null|string, icon?: null|string, required?: array, properties?: array, archive?: array|null, source?: null|string, hardValidation?: bool, immutable?: bool, searchable?: bool, updated?: null|string, created?: null|string, maxDepth?: int, owner?: null|string, application?: null|string, organisation?: null|string, groups?: array<string, list<string>>|null, authorization?: array|null, deleted?: null|string, published?: null|string, depublished?: null|string, configuration?: array|null|string, allOf?: array|null, oneOf?: array|null, anyOf?: array|null}, array<never, never>>
     */
    public function publish(int $id): JSONResponse
    {
        try {
            // Get the publication date from request if provided, otherwise use now.
            $date = null;
            if ($this->request->getParam('date') !== null) {
                $date = new DateTime($this->request->getParam('date'));
            } else {
                $date = new DateTime();
            }

            // Get the schema.
            $schema = $this->schemaMapper->find($id);

            // Set published date and clear depublished date if set.
            $schema->setPublished($date);
            $schema->setDepublished(null);

            // Update the schema.
            $updatedSchema = $this->schemaMapper->update($schema);

            // **CACHE INVALIDATION**: Clear schema cache when publication status changes
            $this->schemaCacheService->invalidateForSchemaChange(schemaId: $updatedSchema->getId(), operation: 'publish');
            $this->schemaFacetCacheService->invalidateForSchemaChange(schemaId: $updatedSchema->getId(), operation: 'publish');

            $this->logger->info(
                    'Schema published',
                    [
                        'schema_id'      => $id,
                        'published_date' => $date->format('Y-m-d H:i:s'),
                    ]
                    );

            return new JSONResponse($updatedSchema->jsonSerialize());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Schema not found'], 404);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to publish schema',
                    [
                        'schema_id' => $id,
                        'error'     => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try

    }//end publish()

    /**
     * Depublish a schema
     *
     * This method depublishes a schema by setting its depublication date to now or a specified date.
     *
     * @param int $id The ID of the schema to depublish
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|404, array{error?: string, id?: int, uuid?: null|string, uri?: null|string, slug?: null|string, title?: null|string, description?: null|string, version?: null|string, summary?: null|string, icon?: null|string, required?: array, properties?: array, archive?: array|null, source?: null|string, hardValidation?: bool, immutable?: bool, searchable?: bool, updated?: null|string, created?: null|string, maxDepth?: int, owner?: null|string, application?: null|string, organisation?: null|string, groups?: array<string, list<string>>|null, authorization?: array|null, deleted?: null|string, published?: null|string, depublished?: null|string, configuration?: array|null|string, allOf?: array|null, oneOf?: array|null, anyOf?: array|null}, array<never, never>>
     */
    public function depublish(int $id): JSONResponse
    {
        try {
            // Get the depublication date from request if provided, otherwise use now.
            $date = null;
            if ($this->request->getParam('date') !== null) {
                $date = new DateTime($this->request->getParam('date'));
            } else {
                $date = new DateTime();
            }

            // Get the schema.
            $schema = $this->schemaMapper->find($id);

            // Set depublished date.
            $schema->setDepublished($date);

            // Update the schema.
            $updatedSchema = $this->schemaMapper->update($schema);

            // **CACHE INVALIDATION**: Clear schema cache when publication status changes
            $this->schemaCacheService->invalidateForSchemaChange(schemaId: $updatedSchema->getId(), operation: 'depublish');
            $this->schemaFacetCacheService->invalidateForSchemaChange(schemaId: $updatedSchema->getId(), operation: 'depublish');

            $this->logger->info(
                    'Schema depublished',
                    [
                        'schema_id'        => $id,
                        'depublished_date' => $date->format('Y-m-d H:i:s'),
                    ]
                    );

            return new JSONResponse($updatedSchema->jsonSerialize());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Schema not found'], 404);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to depublish schema',
                    [
                        'schema_id' => $id,
                        'error'     => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try

    }//end depublish()
}//end class
