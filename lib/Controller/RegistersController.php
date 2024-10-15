<?php

namespace OCA\OpenRegister\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IRequest;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Uid\Uuid;

class RegistersController extends Controller
{
    /**
     * Constructor for the RegistersController
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration object
     * @param Client $client The client
     * @param SchemaMapper $schemaMapper The schema mapper
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param RegisterMapper $registerMapper The register mapper
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly RegisterMapper $registerMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly SchemaMapper $schemaMapper,
        private Client $client
    )
    {
        parent::__construct($appName, $request);
        $this->client = new Client([]);
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
     * @param string $id The ID of the register to update
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
     * @param string $id The ID of the register to delete
     * @return JSONResponse An empty JSON response
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
     * @param string $register The ID of the register
     * @param string $schema The ID of the schema
     *
     * @return JSONResponse An empty JSON response
     */
    public function objects(int $register, int $schema): JSONResponse
    {
        return new JSONResponse($this->objectEntityMapper->findByRegisterAndSchema(register: $register, schema: $schema));
    }

	/**
	 * Creates a new Register object using a json text/string as input. Uses 'json' from POST body.
	 * @todo Or a .json file can be uploaded using key 'file'.
	 * @todo move most of this code to a (new?) UploadService and make it even more abstract and reusable?
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
        }

		$data = $this->request->getParams();

		foreach ($data as $key => $value) {
			if (str_starts_with($key, '_')) {
				unset($data[$key]);
			}
		}

		// Define the allowed keys
		$allowedKeys = ['file', 'url', 'json'];

		// Find which of the allowed keys are in the array
		$matchingKeys = array_intersect_key($data, array_flip($allowedKeys));

		// Check if there is exactly one matching key
		if (count($matchingKeys) === 0) {
			return new JSONResponse(data: ['error' => 'Missing one of these keys in your POST body: file, url or json.'], statusCode: 400);
		}

		if (empty($data['file']) === false) {
			// @todo use .json file content from POST as $json
			//$data['json'] = [];
		}

		if (empty($data['url']) === false) {
			// @todo move to function (cleanup)
			try {
				$response = $this->client->request('GET', $data['url']);
			} catch (GuzzleHttp\Exception\BadResponseException $e) {
				return new JSONResponse(data: ['error' => 'Failed to do a GET api-call on url: '.$data['url']], statusCode: 400);
			}

			$responseBody = $response->getBody()->getContents();

			// Use Content-Type header to determine the format
			$contentType = $response->getHeaderLine('Content-Type');
			switch ($contentType) {
				case 'application/json':
					$phpArray = json_decode(json: $responseBody, associative: true);
					break;
				case 'application/yaml':
					$phpArray = Yaml::parse(input: $responseBody);
					break;
				default:
					// If Content-Type is not specified or not recognized, try to parse as JSON first, then YAML
					$phpArray = json_decode(json: $responseBody, associative: true);
					if ($phpArray === null) {
						$phpArray = Yaml::parse(input: $responseBody);
					}
					break;
			}

			if ($phpArray === null || $phpArray === false) {
				return new JSONResponse(data: ['error' => 'Failed to parse response body as JSON or YAML'], statusCode: 400);
			}
		} else {
            $array = json_decode($data['json'], associative: true);
        }

		// Validate that the jsonArray is a valid OAS3 object containing schemas
		if (isset($array['openapi']) === false || isset($array['components']['schemas']) === false) {
			return new JSONResponse(data: ['error' => 'Invalid OAS3 object. Must contain openapi version and components.schemas.'], statusCode: 400);
		}

		// Set default title if not provided or empty
		if (empty($array['info']['title']) === true) {
			$jsonArray['info']['title'] = 'New Register';
		}

		$register->hydrate($array);
        if ($register->getId() === null){
            $register = $this->registerMapper->insert($register);
        }
        else{
            $register = $this->registerMapper->update($register);
        }

		// Process and save schemas
		foreach ($array['components']['schemas'] as $schemaName => $schemaData) {
            // Check if a schema with this title already exists
            $schema = $this->registerMapper->hasSchemaWithTitle($register->getId(), $schemaName);
            if ($schema === false) {
                // Check if a schema with this title already exists for this register
                try{
                    $schemas = $this->schemaMapper->findAll(null, null, ['title' => $schemaName]);
                    if (count($schemas) > 0){
                        $schema = $schemas[0];
                    }
                    else{
                        // None found so, Create a new schema
                        $schema = new Schema();
                        $schema->setTitle($schemaName);
                        $schema->setUuid(Uuid::v4());
                        $this->schemaMapper->insert($schema);
                    }
                }
                catch(DoesNotExistException $e){
                    // None found so, Create a new schema
                    $schema = new Schema();
                    $schema->setTitle($schemaName);
                    $schema->setUuid(Uuid::v4());
                    $this->schemaMapper->insert($schema);
                }
            }

			$schema->hydrate($schemaData);
			$this->schemaMapper->update($schema);
            // Add the schema to the register
            $schemas = $register->getSchemas();
            $schemas[] = $schema->getId();
            $register->setSchemas($schemas);
            // Lets save the updated register
            $register = $this->registerMapper->update($register);
        }


		return new JSONResponse($register);
	}
}
