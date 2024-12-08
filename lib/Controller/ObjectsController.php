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
        private readonly ObjectAuditLogMapper $objectAuditLogMapper
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
        $filters = $this->request->getParams();
        $fieldsToSearch = ['uuid', 'register', 'schema'];
        $extend = ['schema', 'register'];

        $searchParams = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(filters: $filters, fieldsToSearch:  $fieldsToSearch);
        $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

        // @todo: figure out how to use extend here
        $results = $this->objectEntityMapper->findAll(filters: $filters);

        // We dont want to return the entity, but the object (and kant reley on the normal serilzier)
        foreach ($results as $key => $result) {
            $results[$key] = $result->getObjectArray();
        }

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
            return new JSONResponse($this->objectEntityMapper->find(idOrUuid: (int) $id)->getObjectArray());
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
    public function update(int $id): JSONResponse
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
        $oldObject = $this->objectEntityMapper->find($id);
        $objectEntity = $this->objectEntityMapper->updateFromArray(id: $id, object: $data);

        $this->auditTrailMapper->createAuditTrail(new: $objectEntity, old: $oldObject);

        return new JSONResponse($objectEntity->getOBjectArray());
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
	 * @return JSONResponse An empty JSON response
	 */
	public function auditTrails(int $id): JSONResponse
	{
		return new JSONResponse($this->auditTrailMapper->findAll(filters: ['object' => $id]));
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
     * This method returns all the call logs associated with a object based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the object to retrieve logs for
	 *
     * @return JSONResponse A JSON response containing the call logs
     */
    public function used(int $id): JSONResponse
    {
        try {
            // Lets grap the object to stablish an uri
            $object = $this->objectEntityMapper->find($id);
            $relations = $this->objectEntityMapper->findByRelationUri($object->getUri());
            return new JSONResponse($relations);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Relations not found'], 404);
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
}
