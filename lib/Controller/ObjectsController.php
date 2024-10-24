<?php

namespace OCA\OpenRegister\Controller;

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
     */
    public function create(): JSONResponse
    {
        $data = $this->request->getParams();
        $object = $data['object'];

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }

        if (isset($data['id'])) {
            unset($data['id']);

        }

        // save it
        $objectEntity = $this->objectEntityMapper->createFromArray(object: $data);

       	$this->auditTrailMapper->createAuditTrail(new: $objectEntity);

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

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }
        if (isset($data['id'])) {
            unset($data['id']);
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

        $this->objectEntityMapper->delete($this->objectEntityMapper->find((int) $id));

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
       // @todo

		return new JSONResponse(['error' => 'Not yet implemented'], 501);
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
}
