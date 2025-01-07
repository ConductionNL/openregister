<?php

namespace OCA\OpenRegister\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception;
use OCP\IRequest;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

class RegistersController extends Controller
{
    /**
     * Constructor for the RegistersController
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param RegisterMapper $registerMapper The register mapper
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly RegisterMapper $registerMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
		private readonly UploadService $uploadService
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
     * Retrieves a list of all registers
     *
     * This method returns a JSON response containing an array of all registers in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the list of registers
     */
    public function index(ObjectService $objectService, SearchService $searchService): JSONResponse
    {
        $filters = $this->request->getParams();
        $fieldsToSearch = ['title', 'description'];

        $searchParams = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(filters: $filters, fieldsToSearch:  $fieldsToSearch);
        $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

        return new JSONResponse(['results' => $this->registerMapper->findAll(limit: null, offset: null, filters: $filters, searchConditions: $searchConditions, searchParams: $searchParams)]);
    }

    /**
     * Retrieves a single register by its ID
     *
     * This method returns a JSON response containing the details of a specific register.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the register to retrieve
     * @return JSONResponse A JSON response containing the register details
     */
    public function show(string $id): JSONResponse
    {
        try {
            return new JSONResponse($this->registerMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
        }
    }

    /**
     * Creates a new register
     *
     * This method creates a new register based on POST data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the created register
     */
    public function create(): JSONResponse
    {
        $data = $this->request->getParams();

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }

        if (isset($data['id'])) {
            unset($data['id']);
        }

        return new JSONResponse($this->registerMapper->createFromArray(object: $data));
    }

    /**
     * Updates an existing register
     *
     * This method updates an existing register based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the register to update
     * @return JSONResponse A JSON response containing the updated register details
     */
    public function update(int $id): JSONResponse
    {
        $data = $this->request->getParams();

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }
        if (isset($data['id'])) {
            unset($data['id']);
        }
        return new JSONResponse($this->registerMapper->updateFromArray(id: (int) $id, object: $data));
    }

	/**
	 * Deletes a register
	 *
	 * This method deletes a register based on its ID.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $id The ID of the register to delete
	 * @return JSONResponse An empty JSON response
	 * @throws Exception
	 */
    public function destroy(int $id): JSONResponse
    {
        $this->registerMapper->delete($this->registerMapper->find((int) $id));

        return new JSONResponse([]);
    }

	/**
	 * Get objects
	 *
	 * Get all the objects for a register and schema
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $register The ID of the register
	 * @param int $schema The ID of the schema
	 *
	 * @return JSONResponse An empty JSON response
	 */
    public function objects(int $register, int $schema): JSONResponse
    {
        return new JSONResponse($this->objectEntityMapper->findByRegisterAndSchema(register: $register, schema: $schema));
    }

	/**
	 * Updates an existing Register object using a json text/string as input. Uses 'file', 'url' or else 'json' from POST body.
	 *
	 * @param int|null $id
	 *
	 * @return JSONResponse
	 * @throws Exception
	 * @throws GuzzleException
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function uploadUpdate(?int $id = null): JSONResponse
	{
		return $this->upload($id);
	}

	/**
	 * Creates a new Register object or updates an existing one using a json text/string as input. Uses 'file', 'url' or else 'json' from POST body.
	 *
	 * @param int|null $id
	 *
	 * @return JSONResponse
	 * @throws GuzzleException
	 * @throws Exception
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 */
	public function upload(?int $id = null): JSONResponse
	{
        if ($id !== null){
            $register = $this->registerMapper->find($id);
		}
        else {
            $register = new Register();
			$register->setUuid(Uuid::v4());
        }

		$phpArray = $this->uploadService->getUploadedJson($this->request->getParams());
		if ($phpArray instanceof JSONResponse) {
			return $phpArray;
		}

		// Validate that the jsonArray is a valid OAS3 object containing schemas
		if (isset($phpArray['openapi']) === false || isset($phpArray['components']['schemas']) === false) {
			return new JSONResponse(data: ['error' => 'Invalid OAS3 object. Must contain openapi version and components.schemas.'], statusCode: 400);
		}

		// Set default title if not provided or empty
		if (empty($phpArray['info']['title']) === true) {
			$phpArray['info']['title'] = 'New Register';
		}

		$register->hydrate($phpArray);
        if ($register->getId() === null) {
            $register = $this->registerMapper->insert($register);
        } else {
            $register = $this->registerMapper->update($register);
        }

		// Process and save schemas
		$register = $this->uploadService->handleRegisterSchemas(register: $register, phpArray: $phpArray);

		return new JSONResponse($register);
	}
}
