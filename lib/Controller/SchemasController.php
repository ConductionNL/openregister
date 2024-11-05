<?php

namespace OCA\OpenRegister\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Service\DownloadService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IRequest;
use Symfony\Component\Uid\Uuid;

class SchemasController extends Controller
{
    /**
     * Constructor for the SchemasController
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration object
     * @param SchemaMapper $schemaMapper The schema mapper
     * @param DownloadService $downloadService The download service
     * @param UploadService $uploadService The upload service
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly SchemaMapper $schemaMapper,
		private readonly DownloadService $downloadService,
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
     * Retrieves a list of all schemas
     *
     * This method returns a JSON response containing an array of all schemas in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the list of schemas
     */
    public function index(ObjectService $objectService, SearchService $searchService): JSONResponse
    {
        $filters = $this->request->getParams();
        $fieldsToSearch = ['title', 'description'];

        $searchParams = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(filters: $filters, fieldsToSearch:  $fieldsToSearch);
        $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

        return new JSONResponse(['results' => $this->schemaMapper->findAll(filters: $filters, searchConditions: $searchConditions, searchParams: $searchParams)]);
    }

    /**
     * Retrieves a single schema by its ID
     *
     * This method returns a JSON response containing the details of a specific schema.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the schema to retrieve
     * @return JSONResponse A JSON response containing the schema details
     */
    public function show(string $id): JSONResponse
    {
        try {
            return new JSONResponse($this->schemaMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
        }
    }

    /**
     * Creates a new schema
     *
     * This method creates a new schema based on POST data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the created schema
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

        return new JSONResponse($this->schemaMapper->createFromArray(object: $data));
    }

    /**
     * Updates an existing schema
     *
     * This method updates an existing schema based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the schema to update
     * @return JSONResponse A JSON response containing the updated schema details
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

        return new JSONResponse($this->schemaMapper->updateFromArray(id: $id, object: $data));
    }

	/**
	 * Deletes a schema
	 *
	 * This method deletes a schema based on its ID.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $id The ID of the schema to delete
	 * @return JSONResponse An empty JSON response
	 * @throws Exception
	 */
    public function destroy(int $id): JSONResponse
    {
        $this->schemaMapper->delete($this->schemaMapper->find(id: $id));

        return new JSONResponse([]);
    }

	/**
	 * Updates an existing Schema object using a json text/string as input. Uses 'file', 'url' or else 'json' from POST body.
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
	 * Creates a new Schema object or updates an existing one using a json text/string as input. Uses 'file', 'url' or else 'json' from POST body.
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
	public function upload(?int $id = null): JSONResponse
	{
        if ($id !== null){
            $schema = $this->schemaMapper->find($id);
		} else {
            $schema = new Schema();
			$schema->setUuid(Uuid::v4());
        }

		$phpArray = $this->uploadService->getUploadedJson($this->request->getParams());
		if ($phpArray instanceof JSONResponse) {
			return $phpArray;
		}

		// Set default title if not provided or empty
		if (empty($phpArray['title']) === true) {
			$phpArray['title'] = 'New Schema';
		}

		$schema->hydrate($phpArray);
        if ($schema->getId() === null) {
            $schema = $this->schemaMapper->insert($schema);
        } else {
            $schema = $this->schemaMapper->update($schema);
        }

		return new JSONResponse($schema);
	}

	/**
	 * Creates and return a json file for a Schema.
	 * @todo move most of this code to DownloadService and make it even more Abstract using Entity->jsonSerialize instead of Schema->jsonSerialize, etc.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $id The ID of the schema to return json file for
	 * @return JSONResponse A json Response containing the json
	 */
	public function download(int $id): JSONResponse
	{
		try {
			$schema = $this->schemaMapper->find($id);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		}

		$contentType = $this->request->getHeader('Content-Type');

		if (empty($contentType) === true) {
			return new JSONResponse(data: ['error' => 'Request is missing header Content-Type'], statusCode: 400);
		}

		switch ($contentType) {
			case 'application/json':
				$type = 'json';
				$responseData = [
					'jsonArray' => $schema->jsonSerialize(),
					'jsonString' => json_encode($schema->jsonSerialize())
				];
				break;
			default:
				return new JSONResponse(data: ['error' => "The Content-Type $contentType is not supported."], statusCode: 400);
		}

		// @todo Create a downloadable json file and return it.
		$file = $this->downloadService->download(type: $type);

		return new JSONResponse($responseData);
	}
}
