<?php
/**
 * Class ObjectsController
 *
 * Controller for managing object operations in the OpenRegister app.
 * Provides CRUD functionality for objects within registers and schemas.
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Class ObjectsController
 */
class ObjectsController extends Controller
{


    /**
     * Constructor for the ObjectsController
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param IAppConfig         $config             The app configuration object
     * @param IAppManager        $appManager         The app manager
     * @param ContainerInterface $container          The DI container
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param RegisterMapper     $registerMapper     The register mapper
     * @param SchemaMapper       $schemaMapper       The schema mapper
     * @param AuditTrailMapper   $auditTrailMapper   The audit trail mapper
     * @param ObjectService      $objectService      The object service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ObjectService $objectService
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Returns the template of the main app's page
     *
     * This method renders the main page of the application, adding any necessary data to the template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return TemplateResponse The rendered template response
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            'openconnector',
            'index',
            []
        );

    }//end page()


    /**
     * Retrieves a list of all objects for a specific register and schema
     *
     * This method returns a paginated list of objects that match the specified register and schema.
     * It supports filtering, sorting, and pagination through query parameters.
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     * @param SearchService $searchService The search service
     *
     * @return JSONResponse A JSON response containing the list of objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function index(
        string $register,
        string $schema,
        ObjectService $objectService,
        SearchService $searchService
    ): JSONResponse {
        $requestParams = $this->request->getParams();

        // Extract specific parameters.
        $limit  = ($requestParams['limit'] ?? $requestParams['_limit'] ?? 20);
        $offset = ($requestParams['offset'] ?? $requestParams['_offset'] ?? null);
        $order  = ($requestParams['order'] ?? $requestParams['_order'] ?? []);
        $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? null);
        $filter = ($requestParams['filter'] ?? $requestParams['_filter'] ?? null);
        $fields = ($requestParams['fields'] ?? $requestParams['_fields'] ?? null);
        $page   = ($requestParams['page'] ?? $requestParams['_page'] ?? null);
        $search = ($requestParams['_search'] ?? null);

        // Initialize filters array.
        $filters = [];

        // Check if $register is not an integer and look it up.
        if (is_numeric($register) === false) {
            $registerEntity = $this->registerMapper->find($register);
            if ($registerEntity === null) {
                return new JSONResponse(['error' => 'Register not found'], Http::STATUS_NOT_FOUND);
            }

            $register            = $registerEntity->getId();
            $filters['register'] = $register;
        }

        // Check if $schema is not an integer and look it up.
        if (is_numeric($schema) === false) {
            $schemaEntity = $this->schemaMapper->find($schema);
            if ($schemaEntity === null) {
                return new JSONResponse(['error' => 'Schema not found'], Http::STATUS_NOT_FOUND);
            }

            $schema            = $schemaEntity->getId();
            $filters['schema'] = $schema;
        }

        // Calculate offset from page number if provided.
        if ($page !== null && isset($limit) === true) {
            $page   = (int) $page;
            $offset = ($limit * ($page - 1));
        }

        // Ensure order and extend are arrays.
        if (is_string($order) === true) {
            $order = array_map('trim', explode(',', $order));
        }

        if (is_string($extend) === true) {
            $extend = array_map('trim', explode(',', $extend));
        }

        // Remove unnecessary parameters from filters.
        $filters = $requestParams;
        unset($filters['_route']);
        // Remove route parameter.
        unset(
            $filters['_extend'],
            $filters['_limit'],
            $filters['_offset'],
            $filters['_order'],
            $filters['_page'],
            $filters['_search']
        );
        unset(
            $filters['extend'],
            $filters['limit'],
            $filters['offset'],
            $filters['order'],
            $filters['page']
        );

        // Fetch objects and count total.
        $objects = $this->objectEntityMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            sort: $order,
            search: $search
        );
        $total   = $this->objectEntityMapper->countAll($filters);
        // Calculate the number of pages based on total results and limit.
        if ($limit !== null) {
            $pages = ceil($total / $limit);
        } else {
            $pages = 1;
        }

        // Process each object through the object service.
        foreach ($objects as $key => $object) {
            $objects[$key] = $this->objectService->renderEntity(
                entity: $object->jsonSerialize(),
                extend: $extend,
                depth: 0,
                filter: $filter,
                fields: $fields
            );
        }

        // Build results array with pagination information.
        $results = [
            'results' => $objects,
            'total'   => $total,
            'page'    => ($page ?? 1),
            'pages'   => $pages,
        ];

        return new JSONResponse($results);

    }//end index()


    /**
     * Shows a specific object from a register and schema
     *
     * Retrieves and returns a single object from the specified register and schema,
     * with support for field filtering and related object extension.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The object ID
     *
     * @return JSONResponse A JSON response containing the object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show(string $register, string $schema, string $id): JSONResponse
    {
        $requestParams = $this->request->getParams();

        // Extract parameters for rendering.
        $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? null);
        $filter = ($requestParams['filter'] ?? $requestParams['_filter'] ?? null);
        $fields = ($requestParams['fields'] ?? $requestParams['_fields'] ?? null);

        // Check if $register is not an integer and look it up.
        if (is_numeric($register) === false) {
            $registerEntity = $this->registerMapper->find($register);
            if ($registerEntity === null) {
                return new JSONResponse(['error' => 'Register not found'], Http::STATUS_NOT_FOUND);
            }

            $register = $registerEntity->getId();
        }

        // Check if $schema is not an integer and look it up.
        if (is_numeric($schema) === false) {
            $schemaEntity = $this->schemaMapper->find($schema);
            if ($schemaEntity === null) {
                return new JSONResponse(['error' => 'Schema not found'], Http::STATUS_NOT_FOUND);
            }

            $schema = $schemaEntity->getId();
        }

        // Find and validate the object.
        try {
            $object = $this->objectEntityMapper->find($id);

            // Verify that the object belongs to the specified register and schema.
            if ((int) $object->getRegister() !== $register || (int) $object->getSchema() !== $schema) {
                return new JSONResponse(
                    ['error' => 'Object not found in specified register/schema'],
                    404
                );
            }

            // Render the object with requested extensions and filters.
            return new JSONResponse(
                $this->objectService->renderEntity(
                    entity: $object->jsonSerialize(),
                    extend: $extend,
                    depth: 0,
                    filter: $filter,
                    fields: $fields
                )
            );
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Not Found'], 404);
        }//end try

    }//end show()


    /**
     * Creates a new object in the specified register and schema
     *
     * Takes the request data, validates it against the schema, and creates a new object
     * in the database. Handles validation errors appropriately.
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the created object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function create(
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        // Get object data from request parameters.
        $object = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        $object = array_filter(
            $object,
            fn ($key) => !str_starts_with($key, '_')
                && !str_starts_with($key, '@')
                && !in_array($key, ['id', 'uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Save the object.
        try {
            // Use the object service to validate and save the object.
            $objectEntity = $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $object
            );

            // Unlock the object after saving.
            try {
                $this->objectEntityMapper->unlockObject($objectEntity->getId());
            } catch (\Exception $e) {
                // Ignore unlock errors since the save was successful.
            }
        } catch (ValidationException | CustomValidationException $exception) {
            // Handle validation errors.
            return $objectService->handleValidationException(exception: $exception);
        }

        // Return the created object.
        return new JSONResponse($objectEntity->jsonSerialize());

    }//end create()


    /**
     * Updates an existing object
     *
     * Takes the request data, validates it against the schema, and updates an existing object
     * in the database. Handles validation errors appropriately.
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The ID of the object to update
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the updated object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function update(
        string $register,
        string $schema,
        string $id,
        ObjectService $objectService
    ): JSONResponse {
        // Get object data from request parameters.
        $object = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        $object = array_filter(
            $object,
            fn ($key) => !str_starts_with($key, '_')
                && !str_starts_with($key, '@')
                && !in_array($key, ['id', 'uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Check if the object exists and can be updated.
        try {
            $existingObject = $this->objectEntityMapper->find($id);

            // Verify that the object belongs to the specified register and schema.
            if ((int) $existingObject->getRegister() !== (int) $register
                || (int) $existingObject->getSchema() !== (int) $schema
            ) {
                return new JSONResponse(
                    ['error' => 'Object not found in specified register/schema'],
                    404
                );
            }

            // Check if the object is locked.
            if ($existingObject->isLocked() === true
                && $existingObject->getLockedBy() !== $this->container->get('userId')
            ) {
                // Return a "locked" error with the user who has the lock.
                return new JSONResponse(
                    [
                        'error'    => 'Object is locked by '.$existingObject->getLockedBy(),
                        'lockedBy' => $existingObject->getLockedBy(),
                    ],
                    423
                );
            }
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Not Found'], 404);
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface $e) {
            // If there's an issue getting the user ID, continue without the lock check.
        }//end try

        // Update the object.
        try {
            // Use the object service to validate and update the object.
            $objectEntity = $objectService->updateObject(
                id: $id,
                register: $register,
                schema: $schema,
                object: $object
            );

            // Unlock the object after saving.
            try {
                $this->objectEntityMapper->unlockObject($objectEntity->getId());
            } catch (\Exception $e) {
                // Ignore unlock errors since the update was successful.
            }
        } catch (ValidationException | CustomValidationException $exception) {
            // Handle validation errors.
            return $objectService->handleValidationException(exception: $exception);
        }

        // Return the updated object.
        return new JSONResponse($objectEntity->jsonSerialize());

    }//end update()


    /**
     * Deletes an object
     *
     * This method deletes an object based on its ID.
     *
     * @param int $id The ID of the object to delete
     *
     * @throws Exception
     *
     * @return JSONResponse An empty JSON response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function destroy(string $id): JSONResponse
    {
        // Create a log entry.
        $oldObject = $this->objectEntityMapper->find($id);

        // Clone the object to pass as the new state.
        $newObject = clone $oldObject;
        $newObject->delete();

        // Update the object in the mapper instead of deleting.
        $this->objectEntityMapper->update($newObject);

        // Create an audit trail with both old and new states.
        $this->auditTrailMapper->createAuditTrail(old: $oldObject, new: $newObject);

        // Return the deleted object.
        return new JSONResponse($newObject->jsonSerialize());

    }//end destroy()


    /**
     * Retrieves a list of logs for an object
     *
     * This method returns a JSON response containing the logs for a specific object.
     *
     * @param int $id The ID of the object to get AuditTrails for
     *
     * @return JSONResponse A JSON response containing the audit trail entries
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function auditTrails(string $id): JSONResponse
    {
        try {
            $requestParams = $this->request->getParams();
            return new JSONResponse($this->objectService->getPaginatedAuditTrail($id, null, null, $requestParams));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end auditTrails()


    /**
     * Retrieves call logs for a object
     *
     * This method returns all the call logs associated with a object based on its ID.
     *
     * @param int $id The ID of the object to retrieve logs for
     *
     * @return JSONResponse A JSON response containing the call logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function contracts(string $id): JSONResponse
    {
        // Create a log entry.
        $oldObject = $this->objectEntityMapper->find($id);
        $this->auditTrailMapper->createAuditTrail(old: $oldObject);

        return new JSONResponse(['error' => 'Not yet implemented'], 501);

    }//end contracts()


    /**
     * Retrieves all objects that use a object
     *
     * This method returns all objects that reference this object.
     *
     * @param int $id The ID of the object to retrieve relations for
     *
     * @return JSONResponse A JSON response containing the related objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function relations(string $id): JSONResponse
    {
        try {
            $requestParams = $this->request->getParams();
            return new JSONResponse($this->objectService->getPaginatedRelations($id, null, null, $requestParams));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end relations()


    /**
     * Retrieves all objects that this object references
     *
     * This method returns all objects that this object uses/references.
     *
     * @param int $id The ID of the object to retrieve uses for
     *
     * @return JSONResponse A JSON response containing the referenced objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function uses(string $id): JSONResponse
    {
        try {
            $requestParams = $this->request->getParams();
            unset($requestParams['id']);
            return new JSONResponse($this->objectService->getPaginatedUses($id, null, null, $requestParams));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['ERROR' => $e->getMessage(), 'trace' => $e->getTrace()], 500);
        }

    }//end uses()


    /**
     * Retrieves call logs for an object
     *
     * This method returns a JSON response containing the logs for a specific object.
     *
     * @param int $id The ID of the object to retrieve logs for
     *
     * @return JSONResponse A JSON response containing the call logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function logs(string $id): JSONResponse
    {
        try {
            $jobLogs = $this->auditTrailMapper->findAll(null, null, ['object_id' => $id]);
            return new JSONResponse($jobLogs);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Logs not found'], 404);
        }

    }//end logs()


    /**
     * Lock an object
     *
     * @param int $id The ID of the object to lock
     *
     * @return JSONResponse A JSON response containing the locked object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function lock(string $id): JSONResponse
    {
        try {
            $data    = $this->request->getParams();
            $process = ($data['process'] ?? null);
            // Check if duration is set in the request data.
            $duration = null;
            if (isset($data['duration']) === true) {
                $duration = (int) $data['duration'];
            }

            $object = $this->objectEntityMapper->lockObject(
                $id,
                $process,
                $duration
            );

            return new JSONResponse($object->getObjectArray());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 403);
        } catch (LockedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 423);
            // 423 Locked.
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try

    }//end lock()


    /**
     * Unlock an object
     *
     * @param int $id The ID of the object to unlock
     *
     * @return JSONResponse A JSON response containing the unlocked object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function unlock(string $id): JSONResponse
    {
        try {
            $object = $this->objectEntityMapper->unlockObject($id);
            return new JSONResponse($object->getObjectArray());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 403);
        } catch (LockedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 423);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end unlock()


    /**
     * Revert an object to a previous state
     *
     * This endpoint allows reverting an object to a previous state based on different criteria:
     * 1. DateTime - Revert to the state at a specific point in time
     * 2. Audit Trail ID - Revert to the state after a specific audit trail entry
     * 3. Semantic Version - Revert to a specific version of the object
     *
     * Request body should contain one of:
     * - datetime: ISO 8601 datetime string (e.g., "2024-03-01T12:00:00Z")
     * - auditTrailId: UUID of an audit trail entry
     * - version: Semantic version string (e.g., "1.0.0")
     *
     * Optional parameters:
     * - overwriteVersion: boolean (default: false) - If true, keeps the version number,
     *                     if false, increments the patch version
     *
     * Example requests:
     * ```json
     * {
     *     "datetime": "2024-03-01T12:00:00Z"
     * }
     * ```
     * ```json
     * {
     *     "auditTrailId": "550e8400-e29b-41d4-a716-446655440000"
     * }
     * ```
     * ```json
     * {
     *     "version": "1.0.0",
     *     "overwriteVersion": true
     * }
     * ```
     *
     * @param int $id The ID of the object to revert
     *
     * @throws DoesNotExistException If object not found
     * @throws NotAuthorizedException If user not authorized
     * @throws \Exception If no valid reversion point specified
     * @throws LockedException If object is locked
     *
     * @return JSONResponse A JSON response containing the reverted object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function revert(string $id): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Parse the revert point.
            $until = null;
            if (isset($data['datetime']) === true) {
                $until = new \DateTime($data['datetime']);
            } else if (isset($data['auditTrailId']) === true) {
                $until = $data['auditTrailId'];
            } else if (isset($data['version']) === true) {
                $until = $data['version'];
            }

            if ($until === null) {
                return new JSONResponse(
                    ['error' => 'Must specify either datetime, auditTrailId, or version'],
                    400
                );
            }

            // Determine if we should overwrite the version based on the input data.
            $overwriteVersion = false;
            if (isset($data['overwriteVersion']) === true) {
                $overwriteVersion = (bool) $data['overwriteVersion'];
            }

            // Get the reverted object using AuditTrailMapper instead.
            $revertedObject = $this->auditTrailMapper->revertObject(
                $id,
                $until,
                $overwriteVersion
            );

            // Save the reverted object.
            $savedObject = $this->objectEntityMapper->update($revertedObject);

            return new JSONResponse($savedObject->getObjectArray());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try

    }//end revert()


    /**
     * Retrieves files associated with an object
     *
     * @param string        $id            The ID of the object to get files for
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the object's files
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function files(string $id, ObjectService $objectService): JSONResponse
    {
        try {
            // Get the object with files included.
            $object = $this->objectEntityMapper->find((int) $id);
            $files  = $objectService->getFiles($object);

            // Format files with pagination support.
            $requestParams  = $this->request->getParams();
            $formattedFiles = $objectService->formatFiles($files, $requestParams);

            return new JSONResponse($formattedFiles);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end files()


}//end class
