<?php

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
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
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
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
     * Retrieves a list of all objects for a specific register and schema
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $register The register slug or identiefer
     * @param string $schema The schema slug or identiefer
     * @return JSONResponse A JSON response containing the list of objects
     */
    public function index(string $register, string $schema, ObjectService $objectService, SearchService $searchService): JSONResponse
    {
        $requestParams = $this->request->getParams();

        // Extract specific parameters
		$limit = $requestParams['limit'] ?? $requestParams['_limit'] ?? 20;
		$offset = $requestParams['offset'] ?? $requestParams['_offset'] ?? null;
		$order = $requestParams['order'] ?? $requestParams['_order'] ?? [];
		$extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
		$filter = $requestParams['filter'] ?? $requestParams['_filter'] ?? null;
		$fields = $requestParams['fields'] ?? $requestParams['_fields'] ?? null;
		$page = $requestParams['page'] ?? $requestParams['_page'] ?? null;
		$search = $requestParams['_search'] ?? null;

		// Check if $register is not an integer and look it up
		if (is_numeric($register) === false) {
			$registerEntity = $this->registerMapper->find($register);
			if ($registerEntity === null) {
				return new JSONResponse(['error' => 'Register not found'], Http::STATUS_NOT_FOUND);
			}
			$register = $registerEntity->getId();
            $filters['register'] = $register;
		}

		// Check if $schema is not an integer and look it up
		if (is_numeric($schema) === false) {
			$schemaEntity = $this->schemaMapper->find($schema);
			if ($schemaEntity === null) {
				return new JSONResponse(['error' => 'Schema not found'], Http::STATUS_NOT_FOUND);
			}
			$schema = $schemaEntity->getId();
            $filters['schema'] = $schema;
		}

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
            $objects[$key] = $this->objectService->renderEntity(entity: $object->jsonSerialize(), extend: $extend, depth: 0, filter: $filter, fields:  $fields);
        }

		$results =  [
			'results' => $objects,
			'total' => $total,
			'page' => $page ?? 1,
			'pages' => $pages,
		];


        return new JSONResponse($results);
    }

    /**
     * Shows a specific object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $register The register slug or identiefer
     * @param string $schema The schema slug or identiefer
     * @param string $id The object ID
     * @return JSONResponse A JSON response containing the object
     */
    public function show(string $register, string $schema, string $id): JSONResponse
    {
        $requestParams = $this->request->getParams();

        $extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
        $filter = $requestParams['filter'] ?? $requestParams['_filter'] ?? null;
        $fields = $requestParams['fields'] ?? $requestParams['_fields'] ?? null;

		// Check if $register is not an integer and look it up
		if (!is_numeric($register)) {
			$registerEntity = $this->registerMapper->find($register);
			if ($registerEntity === null) {
				return new JSONResponse(['error' => 'Register not found'], Http::STATUS_NOT_FOUND);
			}
			$register = $registerEntity->getId();
		}

		// Check if $schema is not an integer and look it up
		if (!is_numeric($schema)) {
			$schemaEntity = $this->schemaMapper->find($schema);
			if ($schemaEntity === null) {
				return new JSONResponse(['error' => 'Schema not found'], Http::STATUS_NOT_FOUND);
			}
			$schema = $schemaEntity->getId();
		}

        // Add validation that object belongs to specified register and schema
        try {
            $object = $this->objectEntityMapper->find($id);

            if ((int)$object->getRegister() !== $register || (int)$object->getSchema() !== $schema) {
                return new JSONResponse(['error' => 'Object not found in specified register/schema'], 404);
            }

            return new JSONResponse($this->objectService->renderEntity(entity: $object->jsonSerialize(), extend: $extend, depth: 0, filter: $filter, fields:  $fields));
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Not Found'], 404);
        }
    }

    /**
     * Creates a new object in the specified register and schema
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $register The register slug or identiefer
     * @param string $schema The schema slug or identiefer
     * @return JSONResponse A JSON response containing the created object
     */
    public function create(string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        $object = $this->request->getParams();

        $object = array_filter(
            $object,
            fn($key) => !str_starts_with($key, '_')
                && !str_starts_with($key, '@')
                && !in_array($key, ['id', 'uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

		// Save the object
		try {
            $objectEntity = $objectService->saveObject(register: $register, schema: $schema, object: $object);

			// Unlock the object after saving
			try {
				$this->objectEntityMapper->unlockObject($objectEntity->getId());
			} catch (\Exception $e) {
				// Ignore unlock errors since the save was successful
			}
		} catch (ValidationException|CustomValidationException $exception) {
            return $objectService->handleValidationException(exception: $exception);
		}

        return new JSONResponse($objectEntity->jsonSerialize());
    }

    /**
     * Updates an existing object
     *
     * This method updates an existing object based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int    $id       The ID of the object to update
     * @param string $register The register of the object
     * @param string $schema   The schema of the object
     * @param ObjectService $objectService The service to handle object operations
     *
     * @return JSONResponse A JSON response containing the updated object details
     */
    public function update(string $register, string $schema, string $id, ObjectService $objectService): JSONResponse
    {

        $object = $this->request->getParams();
        //$mapping = $data['mapping'] ?? null; @todo lets thin about how we want to use mapping, its currently unussed so lets depractice it for now

        // Filter out and remove properties that start with _ and @ (those are reserved for internal use)
        // Also remove properties called id, uuid, register, or schema
        // @todo lets add this to the documentation
        $object = array_filter(
            $object,
            fn($key) => !str_starts_with($key, '_')
                && !str_starts_with($key, '@')
                && !in_array($key, ['id', 'uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Lets us the id from the url
        $object['id'] = $id;


        // If mapping ID is provided, transform the object using the mapping
        //$mappingService = $this->getOpenConnectorMappingService();

        //if ($mapping !== null && $mappingService !== null) {
        //    $mapping = $mappingService->getMapping($mapping);
        //    $data = $mappingService->executeMapping($mapping, $object);
        //}

        // save it
        try {
            $objectEntity = $objectService->saveObject(register: $register, schema: $schema, object: $object);

            // Unlock the object after saving @todo this should be done in the saveObject method
            try {
                $this->objectEntityMapper->unlockObject($objectEntity->getId());
            } catch (\Exception $e) {
                // Ignore unlock errors since the save was successful
            }
        } catch (ValidationException|CustomValidationException $exception) {
            return $objectService->handleValidationException(exception: $exception);
        }

        return new JSONResponse($objectEntity->jsonSerialize());
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
    public function destroy(string $id): JSONResponse
    {
        // Create a log entry
        $oldObject = $this->objectEntityMapper->find($id);

        // Clone the object to pass as the new state
        $newObject = clone $oldObject;
        $newObject->delete();

        // Update the object in the mapper instead of deleting
        $this->objectEntityMapper->update($newObject);

        // Create an audit trail with both old and new states
        $this->auditTrailMapper->createAuditTrail(old: $oldObject, new: $newObject);


        // Return the deleted object
        return new JSONResponse($newObject->jsonSerialize());
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
    public function contracts(string $id): JSONResponse
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
    public function logs(string $id): JSONResponse
    {
        try {
            $jobLogs = $this->auditTrailMapper->findAll(null, null, ['object_id' => $id]);
            return new JSONResponse($jobLogs);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Logs not found'], 404);
        }
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
	public function lock(string $id): JSONResponse
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
	public function revert(string $id): JSONResponse
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
