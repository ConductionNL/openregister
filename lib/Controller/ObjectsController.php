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
     * Private helper method to handle pagination of results
     *
     * @param array       $results The array of objects to paginate
     * @param int        $total   The total number of items (before pagination)
     * @param int        $limit   The number of items per page
     * @param int|null   $offset  The offset of items
     * @param int|null   $page    The current page number
     *
     * @return array The paginated results with metadata
     */
    private function paginate(array $results, int $total, int $limit, ?int $offset = null, ?int $page = null): array
    {
        // Calculate the number of pages
        $pages = ceil($total / $limit);

        // If we have a page but no offset, calculate the offset
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        // If we have an offset but no page, calculate the page
        if ($offset !== null && $page === null) {
            $page = floor($offset / $limit) + 1;
        }

        // Default to page 1 if neither offset nor page is set
        if ($page === null) {
            $page = 1;
        }

        // Initialize the results array with pagination information
        $paginatedResults = [
            'results' => $results,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset
        ];

        // Add next/prev page URLs if applicable
        $currentUrl = $_SERVER['REQUEST_URI'];

        // Add next page link if there are more pages
        if ($page < $pages) {
            $nextPage = $page + 1;
            $nextUrl = preg_replace('/([?&])page=\d+/', '$1page=' . $nextPage, $currentUrl);
            if (strpos($nextUrl, 'page=') === false) {
                $nextUrl .= (strpos($nextUrl, '?') === false ? '?' : '&') . 'page=' . $nextPage;
            }
            $paginatedResults['next'] = $nextUrl;
        }

        // Add previous page link if not on first page
        if ($page > 1) {
            $prevPage = $page - 1;
            $prevUrl = preg_replace('/([?&])page=\d+/', '$1page=' . $prevPage, $currentUrl);
            if (strpos($prevUrl, 'page=') === false) {
                $prevUrl .= (strpos($prevUrl, '?') === false ? '?' : '&') . 'page=' . $prevPage;
            }
            $paginatedResults['prev'] = $prevUrl;
        }

        return $paginatedResults;
    }


    /**
     * Helper method to get configuration array from the current request
     *
     * @param string|null $register Optional register identifier
     * @param string|null $schema   Optional schema identifier
     * @param array|null  $ids      Optional array of specific IDs to filter
     *
     * @return array Configuration array containing:
     *               - limit: (int) Maximum number of items per page
     *               - offset: (int|null) Number of items to skip
     *               - page: (int|null) Current page number
     *               - filters: (array) Filter parameters
     *               - sort: (array) Sort parameters
     *               - search: (string|null) Search term
     *               - extend: (array|null) Properties to extend
     *               - fields: (array|null) Fields to include
     *               - unset: (array|null) Fields to exclude
     *               - register: (string|null) Register identifier
     *               - schema: (string|null) Schema identifier
     *               - ids: (array|null) Specific IDs to filter
     */
    private function getConfig(?string $register = null, ?string $schema = null, ?array $ids = null): array
    {
        $params = $this->request->getParams();

        // Extract and normalize parameters
        $limit = (int)($params['limit'] ?? $params['_limit'] ?? 20);
        $offset = isset($params['offset']) 
            ? (int)$params['offset'] 
            : (isset($params['_offset']) ? (int)$params['_offset'] : null);
        $page = isset($params['page'])
            ? (int)$params['page']
            : (isset($params['_page']) ? (int)$params['_page'] : null);

        // If we have a page but no offset, calculate the offset
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        return [
            'limit' => $limit,
            'offset' => $offset,
            'page' => $page,
            'filters' => $params,
            'sort' => ($params['order'] ?? $params['_order'] ?? []),
            'search' => ($params['_search'] ?? null),
            'extend' => ($params['extend'] ?? $params['_extend'] ?? null),
            'fields' => ($params['fields'] ?? $params['_fields'] ?? null),
            'unset' => ($params['unset'] ?? $params['_unset'] ?? null),
            'register' => $register,
            'schema' => $schema,
            'ids' => $ids
        ];
    }


    /**
     * Retrieves a list of all objects for a specific register and schema
     *
     * This method returns a paginated list of objects that match the specified register and schema.
     * It supports filtering, sorting, and pagination through query parameters.
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the list of objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function index(string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Get config and fetch objects
        $config = $this->getConfig($register, $schema);
        $objects = $objectService->findAll($config);

        // Get total count for pagination
        $total = $objectService->count($config['filters'], $config['search']);

        // Return paginated results
        return new JSONResponse($this->paginate($objects, $total, $config['limit'], $config['offset'], $config['page']));
    }


    /**
     * Shows a specific object from a register and schema
     *
     * Retrieves and returns a single object from the specified register and schema,
     * with support for field filtering and related object extension.
     *
     * @param string $id       The object ID
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier      
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get request parameters for filtering and searching.
        $requestParams = $this->request->getParams();

        // Extract parameters for rendering.
        $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? null);
        $filter = ($requestParams['filter'] ?? $requestParams['_filter'] ?? null);
        $fields = ($requestParams['fields'] ?? $requestParams['_fields'] ?? null);      

        // Find and validate the object.
        try {
            $object = $this->objectEntityMapper->find($id);

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
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get object data from request parameters.
        $object = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        // @todo shouldn't this be part of the object service?
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
                data: $object
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
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get object data from request parameters.
        $object = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        // @todo shouldn't this be part of the object service?
        $object = array_filter(
            $object,
            fn ($key) => !str_starts_with($key, '_')
                && !str_starts_with($key, '@')
                && !in_array($key, ['id', 'uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Check if the object exists and can be updated.
        // @todo shouldn't this be part of the object service?
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
            // Use the object service to validate and save the object.
            $objectEntity = $objectService->saveObject(
                data: $object
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
     * Retrieves call logs for a object
     *
     * This method returns all the call logs associated with a object based on its ID.
     *
     * @param int $id The ID of the object to retrieve logs for
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier      
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the call logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function contracts(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get request parameters for filtering and searching.
        $requestParams = $this->request->getParams();

        // Extract specific parameters.
        $limit  = (int)($requestParams['limit'] ?? $requestParams['_limit'] ?? 20);
        $offset = isset($requestParams['offset']) ? (int)$requestParams['offset'] : (isset($requestParams['_offset']) ? (int)$requestParams['_offset'] : null);
        $page   = isset($requestParams['page']) ? (int)$requestParams['page'] : (isset($requestParams['_page']) ? (int)$requestParams['_page'] : null);
        $order  = ($requestParams['order'] ?? $requestParams['_order'] ?? []);
        $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? null);
        $filter = ($requestParams['filter'] ?? $requestParams['_filter'] ?? null);
        $fields = ($requestParams['fields'] ?? $requestParams['_fields'] ?? null);
        $unset  = ($requestParams['unset'] ?? $requestParams['_unset'] ?? null);
        $search = ($requestParams['_search'] ?? null);

        // If we have a page but no offset, calculate the offset based on page and limit.
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        $filters = $requestParams;

        return new JSONResponse(['error' => 'Not yet implemented'], 501);

    }//end contracts()


    /**
     * Retrieves all objects that this object references
     *
     * This method returns all objects that this object uses/references. A -> B means that A (This object) references B (Another object).
     *
     * @param string $id The ID of the object to retrieve relations for
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier      
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the related objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function uses(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Get the relations for the object
        $relationsArray = $objectService->find($id)->getRelations();
        $relations = array_values($relationsArray);

        // Get config and fetch objects
        $config = $this->getConfig($register, $schema, $relations);
        $objects = $objectService->findAll($config);

        // Get total count for pagination
        $total = $objectService->count($config['filters']);

        // Return paginated results
        return new JSONResponse($this->paginate($objects, $total, $config['limit'], $config['offset'], $config['page']));
    }


    /**
     * Retrieves all objects that use a object
     *
     * This method returns all objects that reference (use) this object. B -> A means that B (Another object) references A (This object).
     *
     * @param string $id The ID of the object to retrieve uses for
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier      
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the referenced objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function used(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Get the relations for the object
        $relationsArray = $objectService->findByRelations($id);
        $relations = array_map(static fn($relation) => $relation->getId(), $relationsArray);

        // Get config and fetch objects
        $config = $this->getConfig($register, $schema, $relations);
        $objects = $objectService->findAll($config);

        // Get total count for pagination
        $total = $objectService->count($config['filters']);

        // Return paginated results
        return new JSONResponse($this->paginate($objects, $total, $config['limit'], $config['offset'], $config['page']));
    }


    /**
     * Retrieves logs for an object
     *
     * This method returns a JSON response containing the logs for a specific object.
     *
     * @param string $id The ID of the object to retrieve logs for
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier      
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function logs(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {        
        // Get config and fetch logs
        $config = $this->getConfig($register, $schema);
        $objects = $objectService->getLogs($id, $config['filters']);
        $total = $objectService->count($config['filters']);

        // Return paginated results
        return new JSONResponse($this->paginate($objects, $total, $config['limit'], $config['offset'], $config['page']));
    }


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
    public function lock(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
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
    public function unlock(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
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
    public function revert(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
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


}//end class
