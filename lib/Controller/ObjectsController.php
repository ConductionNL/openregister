<?php

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Db\ObjectAuditLogMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Symfony\Component\Uid\Uuid;
use Psr\Container\ContainerInterface;

class ObjectsController extends Controller
{


    /**
     * Constructor for the ObjectsController
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration object
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly ObjectEntityMapper $objectEntityMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ObjectAuditLogMapper $objectAuditLogMapper,
        private readonly ObjectService $objectService,

    )
    {
        parent::__construct($appName, $request);
    }

    /**
     * Returns the template of the main app's page
     *
     * This method renders the main page of the application, adding any necessary data to the template.
     *
     * @NoAdminRequired
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
    }

    /**
     * Retrieves a list of all objects
     *
     * This method returns a JSON response containing an array of all objects in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the list of objects
     */
    public function index(ObjectService $objectService, SearchService $searchService): JSONResponse
    {
        
        $requestParams = $this->request->getParams();

        // Extract specific parameters
		$limit = $requestParams['limit'] ?? $requestParams['_limit'] ?? 20;
		$offset = $requestParams['offset'] ?? $requestParams['_offset'] ?? null;
		$order = $requestParams['order'] ?? $requestParams['_order'] ?? [];
		$extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
		$page = $requestParams['page'] ?? $requestParams['_page'] ?? null;
		$search = $requestParams['_search'] ?? null;

		if ($page !== null && isset($limit)) {
			$page = (int) $page;
			$offset = $limit * ($page - 1);
		}

		// Ensure order and extend are arrays
		if (is_string($order) === true) {
			$order = array_map('trim', explode(',', $order));
		}
		if (is_string($extend) === true) {
			$extend = array_map('trim', explode(',', $extend));
		}

		// Remove unnecessary parameters from filters
		$filters = $requestParams;
		unset($filters['_route']); // TODO: Investigate why this is here and if it's needed
		unset($filters['_extend'], $filters['_limit'], $filters['_offset'], $filters['_order'], $filters['_page'], $filters['_search']);
		unset($filters['extend'], $filters['limit'], $filters['offset'], $filters['order'], $filters['page']);

        // Lets support extend
		$objects = $this->objectEntityMapper->findAll(limit: $limit, offset: $offset, filters: $filters, sort: $order, search: $search);
		$total   = $this->objectEntityMapper->countAll($filters);
		$pages   = $limit !== null ? ceil($total/$limit) : 1;

		

        // We dont want to return the entity, but the object (and kant reley on the normal serilzier)
        foreach ($objects as $key => $object) {
            $objects[$key] = $object->getObjectArray();
        }

		$results =  [
			'results' => $objects,
			'total' => $total,
			'page' => $page ?? 1,
			'pages' => $pages,
		];


        return new JSONResponse(['results' => $results]);
    }

    /**
     * Retrieves a single object by its ID
     *
     * This method returns a JSON response containing the details of a specific object.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the object to retrieve
	 *
     * @return JSONResponse A JSON response containing the object details
     */
    public function show(string $id): JSONResponse
    {
        try {
            return new JSONResponse($this->objectEntityMapper->find((int) $id)->getObjectArray());
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
        }
    }

	/**
	 * Creates a new object
	 *
	 * This method creates a new object based on POST data.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse A JSON response containing the created object
	 * @throws Exception
	 */
    public function create(ObjectService $objectService): JSONResponse
    {
        $data = $this->request->getParams();
        $object = $data['object'];
        $mapping = $data['mapping'] ?? null;
        $register = $data['register'];
        $schema = $data['schema'];

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }

        if (isset($data['id'])) {
            unset($data['id']);
        }

        // If mapping ID is provided, transform the object using the mapping
        $mappingService = $this->getOpenConnectorMappingService();

        if ($mapping !== null && $mappingService !== null) {
            $mapping = $mappingService->getMapping($mapping);

            $object = $mappingService->executeMapping($mapping, $object);
            $data['register'] = $register;
            $data['schema'] = $schema;
        }

		// Save the object
		try {
			$objectEntity = $objectService->saveObject(register: $data['register'], schema: $data['schema'], object: $object);

			// Unlock the object after saving
			try {
				$this->objectEntityMapper->unlockObject($objectEntity->getId());
			} catch (\Exception $e) {
				// Ignore unlock errors since the save was successful
			}
		} catch (ValidationException $exception) {
			$formatter = new ErrorFormatter();
			return new JSONResponse(['message' => $exception->getMessage(), 'validationErrors' => $formatter->format($exception->getErrors())], 400);
		}

        return new JSONResponse($objectEntity->getObjectArray());
    }

    /**
     * Updates an existing object
     *
     * This method updates an existing object based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int  $id The ID of the object to update
	 *
     * @return JSONResponse A JSON response containing the updated object details
     */
    public function update(int $id, ObjectService $objectService): JSONResponse
    {
        $data = $this->request->getParams();
        $object = $data['object'];
        $mapping = $data['mapping'] ?? null;

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }
        if (isset($data['id'])) {
            unset($data['id']);
        }

        // If mapping ID is provided, transform the object using the mapping
        $mappingService = $this->getOpenConnectorMappingService();

        if ($mapping !== null && $mappingService !== null) {
            $mapping = $mappingService->getMapping($mapping);
            $data = $mappingService->executeMapping($mapping, $object);
        }

        // save it
        try {
            $objectEntity = $objectService->saveObject(register: $data['register'], schema: $data['schema'], object: $data['object']);

            // Unlock the object after saving
            try {
                $this->objectEntityMapper->unlockObject($objectEntity->getId());
            } catch (\Exception $e) {
                // Ignore unlock errors since the save was successful
            }
        } catch (ValidationException $exception) {
            $formatter = new ErrorFormatter();
            return new JSONResponse(['message' => $exception->getMessage(), 'validationErrors' => $formatter->format($exception->getErrors())], 400);
        }

        return new JSONResponse($objectEntity->getObjectArray());
    }

	/**
	 * Deletes an object
	 *
	 * This method deletes an object based on its ID.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $id The ID of the object to delete
	 *
	 * @return JSONResponse An empty JSON response
	 * @throws Exception
	 */
    public function destroy(int $id): JSONResponse
    {
        // Create a log entry
        $oldObject = $this->objectEntityMapper->find($id);
        $this->auditTrailMapper->createAuditTrail(old: $oldObject);

        $this->objectEntityMapper->delete($this->objectEntityMapper->find($id));

        return new JSONResponse([]);
    }

	/**
	 * Retrieves a list of logs for an object
	 *
	 * This method returns a JSON response containing the logs for a specific object.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $id The ID of the object to get AuditTrails for
	 *
	 * @return JSONResponse A JSON response containing the audit trail entries
	 */
	public function auditTrails(int $id): JSONResponse
	{
		try {
			$requestParams = $this->request->getParams();
			return new JSONResponse($this->objectService->getPaginatedAuditTrail($id, null, null, $requestParams));
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

    /**
     * Retrieves call logs for a object
     *
     * This method returns all the call logs associated with a object based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the object to retrieve logs for
	 *
     * @return JSONResponse A JSON response containing the call logs
     */
    public function contracts(int $id): JSONResponse
    {
        // Create a log entry
        $oldObject = $this->objectEntityMapper->find($id);
        $this->auditTrailMapper->createAuditTrail(old: $oldObject);

		return new JSONResponse(['error' => 'Not yet implemented'], 501);
    }

    /**
     * Retrieves all objects that use a object
     *
     * This method returns all objects that reference this object.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the object to retrieve relations for
     *
     * @return JSONResponse A JSON response containing the related objects
     */
    public function relations(int $id): JSONResponse
    {
        try {
            $requestParams = $this->request->getParams();
            return new JSONResponse($this->objectService->getPaginatedRelations($id, null, null, $requestParams));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retrieves all objects that this object references
     *
     * This method returns all objects that this object uses/references.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the object to retrieve uses for
     *
     * @return JSONResponse A JSON response containing the referenced objects
     */
    public function uses(int $id): JSONResponse
    {
        try {
            $requestParams = $this->request->getParams();
            return new JSONResponse($this->objectService->getPaginatedUses($id, null, null, $requestParams));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retrieves call logs for an object
     *
     * This method returns a JSON response containing the logs for a specific object.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the object to retrieve logs for
	 *
     * @return JSONResponse A JSON response containing the call logs
     */
    public function logs(int $id): JSONResponse
    {
        try {
            $jobLogs = $this->objectAuditLogMapper->findAll(null, null, ['object_id' => $id]);
            return new JSONResponse($jobLogs);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Logs not found'], 404);
        }
    }

    /**
     * Retrieves all available mappings
     *
     * This method returns a JSON response containing all available mappings in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the list of mappings
     */
    public function mappings(): JSONResponse
    {
        // Get mapping service, which will return null based on implementation
        $mappingService = $this->getOpenConnectorMappingService();

        // Initialize results array
        $results = [];

        // If mapping service exists, get all mappings using find() method
        if ($mappingService !== null) {
            $results = $mappingService->getMappings();
        }

        // Return response with results array and total count
        return new JSONResponse([
            'results' => $results,
            'total' => count($results)
        ]);
    }

    	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return mixed|null The OpenRegister service if available, null otherwise.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	public function getOpenConnectorMappingService(): ?\OCA\OpenConnector\Service\MappingService
	{
		if (in_array(needle: 'openconnector', haystack: $this->appManager->getInstalledApps()) === true) {
			try {
				// Attempt to get the OpenRegister service from the container
				return $this->container->get('OCA\OpenConnector\Service\MappingService');
			} catch (Exception $e) {
				// If the service is not available, return null
				return null;
			}
		}

		return null;
	}

	/**
	 * Lock an object
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $id The ID of the object to lock
	 * @return JSONResponse A JSON response containing the locked object
	 */
	public function lock(int $id): JSONResponse
	{
		try {
			$data = $this->request->getParams();
			$process = $data['process'] ?? null;
			$duration = isset($data['duration']) ? (int)$data['duration'] : null;

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
			return new JSONResponse(['error' => $e->getMessage()], 423); // 423 Locked
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Unlock an object
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $id The ID of the object to unlock
	 * @return JSONResponse A JSON response containing the unlocked object
	 */
	public function unlock(int $id): JSONResponse
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
	}

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
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $id The ID of the object to revert
	 * @return JSONResponse A JSON response containing the reverted object
	 * @throws NotFoundException If object not found
	 * @throws NotAuthorizedException If user not authorized
	 * @throws BadRequestException If no valid reversion point specified
	 * @throws LockedException If object is locked
	 */
	public function revert(int $id): JSONResponse
	{
		try {
			$data = $this->request->getParams();

			// Parse the revert point
			$until = null;
			if (isset($data['datetime'])) {
				$until = new \DateTime($data['datetime']);
			} elseif (isset($data['auditTrailId'])) {
				$until = $data['auditTrailId'];
			} elseif (isset($data['version'])) {
				$until = $data['version'];
			}

			if ($until === null) {
				return new JSONResponse(
					['error' => 'Must specify either datetime, auditTrailId, or version'],
					400
				);
			}

			$overwriteVersion = $data['overwriteVersion'] ?? false;

			// Get the reverted object using AuditTrailMapper instead
			$revertedObject = $this->auditTrailMapper->revertObject(
				$id,
				$until,
				$overwriteVersion
			);

			// Save the reverted object
			$savedObject = $this->objectEntityMapper->update($revertedObject);

			return new JSONResponse($savedObject->getObjectArray());

		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 403);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

    /**
     * Retrieves files associated with an object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the object to get files for
     * @return JSONResponse A JSON response containing the object's files
     */
    public function files(string $id, ObjectService $objectService): JSONResponse
    {
        try {
            // Get the object with files included
            $object = $this->objectEntityMapper->find((int) $id);
            $files = $objectService->getFiles($object);
            
            // Format files with pagination support
            $requestParams = $this->request->getParams();
            $formattedFiles = $objectService->formatFiles($files, $requestParams);
            
            return new JSONResponse($formattedFiles);
            
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
