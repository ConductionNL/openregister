<?php
/**
 * Class SchemasController
 *
 * Controller for managing schema operations in the OpenRegister app.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\DownloadService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Controller;
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
 * Class SchemasController
 */
class SchemasController extends Controller
{


    /**
     * Constructor for the SchemasController
     *
     * @param string                  $appName                 The name of the app
     * @param IRequest                $request                 The request object
     * @param IAppConfig              $config                  The app configuration object
     * @param SchemaMapper            $schemaMapper            The schema mapper
     * @param ObjectEntityMapper      $objectEntityMapper      The object entity mapper
     * @param DownloadService         $downloadService         The download service
     * @param UploadService           $uploadService           The upload service
     * @param AuditTrailMapper        $auditTrailMapper        The audit trail mapper
     * @param OrganisationService     $organisationService     The organisation service
     * @param SchemaCacheService      $schemaCacheService      Schema cache service for schema operations
     * @param SchemaFacetCacheService $schemaFacetCacheService Schema facet cache service for facet operations
     * @param SchemaService           $schemaService           Schema service for exploration operations
     * @param LoggerInterface         $logger                  Logger for debugging
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
        private readonly SchemaCacheService $schemaCacheService,
        private readonly SchemaFacetCacheService $schemaFacetCacheService,
        private readonly SchemaService $schemaService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Retrieves a list of all schemas
     *
     * This method returns a JSON response containing an array of all schemas in the system.
     *
     * @return JSONResponse A JSON response containing the list of schemas
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        // Get request parameters for filtering and searching.
        $params = $this->request->getParams();

        // Extract pagination and search parameters
        $limit  = isset($params['_limit']) ? (int) $params['_limit'] : null;
        $offset = isset($params['_offset']) ? (int) $params['_offset'] : null;
        $page   = isset($params['_page']) ? (int) $params['_page'] : null;
        $search = $params['_search'] ?? '';
        $extend = $params['_extend'] ?? [];
        if (is_string($extend)) {
            $extend = [$extend];
        }

        // Convert page to offset if provided
        if ($page !== null && $limit !== null) {
            $offset = ($page - 1) * $limit;
        }

        // Extract filters
        $filters = $params['filters'] ?? [];

        $schemas    = $this->schemaMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            searchConditions: [],
            searchParams: [],
            extend: []
        );
        $schemasArr = array_map(fn($schema) => $schema->jsonSerialize(), $schemas);

        // Add extendedBy property to each schema showing UUIDs of schemas that extend it
        foreach ($schemasArr as &$schema) {
            $schema['@self'] = $schema['@self'] ?? [];
            $schema['@self']['extendedBy'] = $this->schemaMapper->findExtendedBy($schema['id']);
        }

        unset($schema);
        // Break the reference
        // If '@self.stats' is requested, attach statistics to each schema
        if (in_array('@self.stats', $extend, true)) {
            // Get register counts for all schemas in one call
            $registerCounts = $this->schemaMapper->getRegisterCountPerSchema();
            foreach ($schemasArr as &$schema) {
                $schema['stats'] = [
                    'objects'   => $this->objectEntityMapper->getStatistics(null, $schema['id']),
                    'logs'      => $this->auditTrailMapper->getStatistics(null, $schema['id']),
                    'files'     => [ 'total' => 0, 'size' => 0 ],
                    // Add the number of registers referencing this schema
                    'registers' => $registerCounts[$schema['id']] ?? 0,
                ];
            }
        }

        return new JSONResponse(['results' => $schemasArr]);

    }//end index()


    /**
     * Retrieves a single schema by ID
     *
     * @param  int|string $id The ID of the schema
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show($id): JSONResponse
    {
        $extend = $this->request->getParam(key: '_extend', default: []);
        if (is_string($extend)) {
            $extend = [$extend];
        }

        $schema    = $this->schemaMapper->find($id, []);
        $schemaArr = $schema->jsonSerialize();

        // Add extendedBy property showing UUIDs of schemas that extend this schema
        $schemaArr['@self'] = $schemaArr['@self'] ?? [];
        $schemaArr['@self']['extendedBy'] = $this->schemaMapper->findExtendedBy($id);

        // If '@self.stats' is requested, attach statistics to the schema
        if (in_array('@self.stats', $extend, true)) {
            // Get register counts for all schemas in one call
            $registerCounts     = $this->schemaMapper->getRegisterCountPerSchema();
            $schemaArr['stats'] = [
                'objects'   => $this->objectEntityMapper->getStatistics(null, $schemaArr['id']),
                'logs'      => $this->auditTrailMapper->getStatistics(null, $schemaArr['id']),
                'files'     => [ 'total' => 0, 'size' => 0 ],
                // Add the number of registers referencing this schema
                'registers' => $registerCounts[$schemaArr['id']] ?? 0,
            ];
        }

        return new JSONResponse($schemaArr);

    }//end show()


    /**
     * Creates a new schema
     *
     * This method creates a new schema based on POST data.
     *
     * @return JSONResponse A JSON response containing the created schema
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to ensure a new record is created.
        if (isset($data['id']) === true) {
            unset($data['id']);
        }

        try {
            // Create a new schema from the data.
            $schema = $this->schemaMapper->createFromArray(object: $data);

            // Set organisation from active organisation for multi-tenancy (if not already set)
            if ($schema->getOrganisation() === null || $schema->getOrganisation() === '') {
                $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
                $schema->setOrganisation($organisationUuid);
                $schema = $this->schemaMapper->update($schema);
            }

            return new JSONResponse($schema, 201);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages
            $constraintException = DatabaseConstraintException::fromDatabaseException($e, 'schema');
            return new JSONResponse(data: ['error' => $constraintException->getMessage()], statusCode: $constraintException->getHttpStatusCode());
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatusCode());
        } catch (Exception $e) {
            // Log the actual error for debugging
            $this->logger->error(
                    'Schema creation failed',
                    [
                        'error_message' => $e->getMessage(),
                        'error_code'    => $e->getCode(),
                        'trace'         => $e->getTraceAsString(),
                    ]
                    );

            // Check if this is a validation error by examining the message
            if (str_contains($e->getMessage(), 'Invalid')
                || str_contains($e->getMessage(), 'must be')
                || str_contains($e->getMessage(), 'required')
                || str_contains($e->getMessage(), 'format')
                || str_contains($e->getMessage(), 'Property at')
                || str_contains($e->getMessage(), 'authorization')
            ) {
                // Return 400 Bad Request for validation errors with actual error message
                return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
            }

            // For database constraint violations, return 409 Conflict
            if (str_contains($e->getMessage(), 'constraint')
                || str_contains($e->getMessage(), 'duplicate')
                || str_contains($e->getMessage(), 'unique')
            ) {
                return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
            }

            // Return 500 for other unexpected errors with actual error message
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
     * @return JSONResponse A JSON response containing the updated schema details
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function update(int $id): JSONResponse
    {
        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove immutable fields to prevent tampering
        unset($data['id']);
        unset($data['organisation']);
        unset($data['owner']);
        unset($data['created']);

        try {
            // Update the schema with the provided data.
            $updatedSchema = $this->schemaMapper->updateFromArray(id: $id, object: $data);

            // **CACHE INVALIDATION**: Clear all schema-related caches when schema is updated
            $this->schemaCacheService->invalidateForSchemaChange($updatedSchema->getId(), 'update');
            $this->schemaFacetCacheService->invalidateForSchemaChange($updatedSchema->getId(), 'update');

            return new JSONResponse($updatedSchema);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages
            $constraintException = DatabaseConstraintException::fromDatabaseException($e, 'schema');
            return new JSONResponse(['error' => $constraintException->getMessage()], $constraintException->getHttpStatusCode());
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatusCode());
        } catch (Exception $e) {
            // Log the actual error for debugging
            $this->logger->error(
                    'Schema update failed',
                    [
                        'schema_id'     => $id,
                        'error_message' => $e->getMessage(),
                        'error_code'    => $e->getCode(),
                        'trace'         => $e->getTraceAsString(),
                    ]
                    );

            // Check if this is a validation error by examining the message
            if (str_contains($e->getMessage(), 'Invalid')
                || str_contains($e->getMessage(), 'must be')
                || str_contains($e->getMessage(), 'required')
                || str_contains($e->getMessage(), 'format')
                || str_contains($e->getMessage(), 'Property at')
                || str_contains($e->getMessage(), 'authorization')
            ) {
                // Return 400 Bad Request for validation errors with actual error message
                return new JSONResponse(['error' => $e->getMessage()], 400);
            }

            // For database constraint violations, return 409 Conflict
            if (str_contains($e->getMessage(), 'constraint')
                || str_contains($e->getMessage(), 'duplicate')
                || str_contains($e->getMessage(), 'unique')
            ) {
                return new JSONResponse(['error' => $e->getMessage()], 409);
            }

            // Return 500 for other unexpected errors with actual error message
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try

    }//end update()


    /**
     * Patch (partially update) a schema
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the schema to patch
     *
     * @return JSONResponse The updated schema data
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
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            // Find the schema by ID, delete it, and invalidate caches
            $schemaToDelete = $this->schemaMapper->find(id: $id);
            $this->schemaMapper->delete($schemaToDelete);

            // **CACHE INVALIDATION**: Clear all schema-related caches when schema is deleted
            $this->schemaCacheService->invalidateForSchemaChange($schemaToDelete->getId(), 'delete');
            $this->schemaFacetCacheService->invalidateForSchemaChange($schemaToDelete->getId(), 'delete');

            // Return an empty response.
            return new JSONResponse([]);
        } catch (\OCA\OpenRegister\Exception\ValidationException $e) {
            // Return 409 Conflict for cascade protection (objects still attached)
            return new JSONResponse(['error' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            // Return 500 for other errors
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            $schema->setUuid(Uuid::v4());
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

                // Set organisation from active organisation for multi-tenancy (if not already set)
                if ($schema->getOrganisation() === null || $schema->getOrganisation() === '') {
                    $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
                    $schema->setOrganisation($organisationUuid);
                    $schema = $this->schemaMapper->update($schema);
                }

                // **CACHE INVALIDATION**: Clear all schema-related caches when schema is created
                $this->schemaCacheService->invalidateForSchemaChange($schema->getId(), 'create');
                $this->schemaFacetCacheService->invalidateForSchemaChange($schema->getId(), 'create');
            } else {
                // Update the existing schema.
                $schema = $this->schemaMapper->update($schema);

                // **CACHE INVALIDATION**: Clear all schema-related caches when schema is updated
                $this->schemaCacheService->invalidateForSchemaChange($schema->getId(), 'update');
                $this->schemaFacetCacheService->invalidateForSchemaChange($schema->getId(), 'update');
            }//end if

            return new JSONResponse($schema);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages
            $constraintException = DatabaseConstraintException::fromDatabaseException($e, 'schema');
            return new JSONResponse(['error' => $constraintException->getMessage()], $constraintException->getHttpStatusCode());
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatusCode());
        } catch (Exception $e) {
            // Log the actual error for debugging
            $this->logger->error(
                    'Schema upload failed',
                    [
                        'schema_id'     => $id,
                        'error_message' => $e->getMessage(),
                        'error_code'    => $e->getCode(),
                        'trace'         => $e->getTraceAsString(),
                    ]
                    );

            // Check if this is a validation error by examining the message
            if (str_contains($e->getMessage(), 'Invalid')
                || str_contains($e->getMessage(), 'must be')
                || str_contains($e->getMessage(), 'required')
                || str_contains($e->getMessage(), 'format')
                || str_contains($e->getMessage(), 'Property at')
                || str_contains($e->getMessage(), 'authorization')
            ) {
                // Return 400 Bad Request for validation errors with actual error message
                return new JSONResponse(['error' => $e->getMessage()], 400);
            }

            // For database constraint violations, return 409 Conflict
            if (str_contains($e->getMessage(), 'constraint')
                || str_contains($e->getMessage(), 'duplicate')
                || str_contains($e->getMessage(), 'unique')
            ) {
                return new JSONResponse(['error' => $e->getMessage()], 409);
            }

            // Return 500 for other unexpected errors with actual error message
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
     */
    public function download(int $id): JSONResponse
    {
        // Get the Accept header to determine the response format.
        $accept = $this->request->getHeader('Accept');

        try {
            // Find the schema by ID.
            $schema = $this->schemaMapper->find($id);
        } catch (Exception $e) {
            // Return a 404 error if the schema doesn't exist.
            return new JSONResponse(['error' => 'Schema not found'], 404);
        }

        // Return the schema as JSON.
        return new JSONResponse($schema);

    }//end download()


    /**
     * Get schemas that have properties referencing the given schema
     *
     * This method finds schemas that contain properties with $ref values pointing
     * to the specified schema, indicating a relationship between schemas.
     *
     * @param int|string $id The ID, UUID, or slug of the schema to find relationships for
     *
     * @return JSONResponse A JSON response containing related schemas
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function related(int|string $id): JSONResponse
    {
        try {
            // Find related schemas using the SchemaMapper (incoming references)
            $incomingSchemas      = $this->schemaMapper->getRelated($id);
            $incomingSchemasArray = array_map(fn($schema) => $schema->jsonSerialize(), $incomingSchemas);

            // Find outgoing references: schemas that this schema refers to
            $targetSchema    = $this->schemaMapper->find($id);
            $properties      = $targetSchema->getProperties() ?? [];
            $allSchemas      = $this->schemaMapper->findAll();
            $outgoingSchemas = [];
            foreach ($allSchemas as $schema) {
                // Skip self
                if ($schema->getId() === $targetSchema->getId()) {
                    continue;
                }

                // Use the same reference logic as getRelated, but reversed
                if ($this->schemaMapper->hasReferenceToSchema($properties, (string) $schema->getId(), $schema->getUuid(), $schema->getSlug())) {
                    $outgoingSchemas[$schema->getId()] = $schema;
                }
            }

            $outgoingSchemasArray = array_map(fn($schema) => $schema->jsonSerialize(), array_values($outgoingSchemas));

            return new JSONResponse(
                    [
                        'incoming' => $incomingSchemasArray,
                        'outgoing' => $outgoingSchemasArray,
                        'total'    => count($incomingSchemasArray) + count($outgoingSchemasArray),
                    ]
                    );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Return a 404 error if the target schema doesn't exist
            return new JSONResponse(['error' => 'Schema not found'], 404);
        } catch (Exception $e) {
            // Return a 500 error for other exceptions
            return new JSONResponse(['error' => 'Internal server error: '.$e->getMessage()], 500);
        }//end try

    }//end related()


    /**
     * Get statistics for a specific schema
     *
     * @param  int $id The schema ID
     * @return JSONResponse The schema statistics
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the schema is not found
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function stats(int $id): JSONResponse
    {
        try {
            // Get the schema
            $schema = $this->schemaMapper->find($id);

            if (!$schema) {
                return new JSONResponse(['error' => 'Schema not found'], 404);
            }

            // Get detailed object statistics for this schema using the existing method
            $objectStats = $this->objectEntityMapper->getStatistics(null, $id);

            // Calculate comprehensive statistics for this schema
            $stats = [
                'objectCount'   => $objectStats['total'],
            // Keep for backward compatibility
                'objects_count' => $objectStats['total'],
            // Alternative field name for compatibility
                'objects'       => [
                    'total'     => $objectStats['total'],
                    'invalid'   => $objectStats['invalid'],
                    'deleted'   => $objectStats['deleted'],
                    'published' => $objectStats['published'],
                    'locked'    => $objectStats['locked'],
                    'size'      => $objectStats['size'],
                ],
                'logs'          => $this->auditTrailMapper->getStatistics(null, $id),
                'files'         => ['total' => 0, 'size' => 0],
                // Placeholder for future file statistics
                'registers'     => $this->schemaMapper->getRegisterCountPerSchema()[$id] ?? 0,
            ];

            return new JSONResponse($stats);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Schema not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
     * @param int $schemaId The ID of the schema to explore
     *
     * @return JSONResponse Analysis results with discovered properties and suggestions
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function explore(int $id): JSONResponse
    {
        try {
            $this->logger->info('Starting schema exploration for schema ID: '.$id);

            $explorationResults = $this->schemaService->exploreSchemaProperties($id);

            $this->logger->info('Schema exploration completed successfully');

            return new JSONResponse($explorationResults);
        } catch (\Exception $e) {
            $this->logger->error('Schema exploration failed: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end explore()


    /**
     * Update schema properties based on exploration results
     *
     * Applies user-confirmed property updates to a schema based on exploration
     * results. This allows schemas to be updated with newly discovered properties.
     *
     * @param int $schemaId The ID of the schema to update
     *
     * @return JSONResponse Success confirmation with updated schema
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function updateFromExploration(int $id): JSONResponse
    {
        try {
            // Get property updates from request
            $propertyUpdates = $this->request->getParam('properties', []);

            if (empty($propertyUpdates)) {
                return new JSONResponse(['error' => 'No property updates provided'], 400);
            }

            $this->logger->info('Updating schema '.$id.' with '.count($propertyUpdates).' property updates');

            $updatedSchema = $this->schemaService->updateSchemaFromExploration($id, $propertyUpdates);

            // Clear schema cache to ensure fresh data
            $this->schemaCacheService->clearSchemaCache($id);

            $this->logger->info('Schema '.$id.' successfully updated with exploration results');

            return new JSONResponse(
                    [
                        'success' => true,
                        'schema'  => $updatedSchema->jsonSerialize(),
                        'message' => 'Schema updated successfully with '.count($propertyUpdates).' properties',
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error('Failed to update schema from exploration: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try

    }//end updateFromExploration()


}//end class
