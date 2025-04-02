<?php
/**
 * Class SchemasController
 *
 * Controller for managing schema operations in the OpenRegister app.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\AppInfo
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DownloadService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IRequest;
use Symfony\Component\Uid\Uuid;

/**
 * Class SchemasController
 */
class SchemasController extends Controller
{
    /**
     * Constructor for the SchemasController
     *
     * @param string          $appName         The name of the app
     * @param IRequest        $request         The request object
     * @param IAppConfig      $config          The app configuration object
     * @param SchemaMapper    $schemaMapper    The schema mapper
     * @param DownloadService $downloadService The download service
     * @param UploadService   $uploadService   The upload service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly SchemaMapper $schemaMapper,
        private readonly DownloadService $downloadService,
        private readonly UploadService $uploadService
    ) {
        parent::__construct($appName, $request);

    }//end __construct()

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

    }//end page()

    /**
     * Retrieves a list of all schemas
     *
     * This method returns a JSON response containing an array of all schemas in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param ObjectService $objectService The object service
     * @param SearchService $searchService The search service
     *
     * @return JSONResponse A JSON response containing the list of schemas
     */
    public function index(
        ObjectService $objectService,
        SearchService $searchService
    ): JSONResponse {
        // Get request parameters for filtering and searching
        $filters = $this->request->getParams();
        $fieldsToSearch = ['title', 'description'];

        // Create search parameters and conditions for filtering
        $searchParams = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(
            filters: $filters,
            fieldsToSearch: $fieldsToSearch
        );
        $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

        // Return all schemas that match the search conditions
        return new JSONResponse(
            [
                'results' => $this->schemaMapper->findAll(
                    filters: $filters,
                    searchConditions: $searchConditions,
                    searchParams: $searchParams
                ),
            ]
        );

    }//end index()

    /**
     * Retrieves a single schema by its ID
     *
     * This method returns a JSON response containing the details of a specific schema.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the schema to retrieve
     *
     * @return JSONResponse A JSON response containing the schema details
     */
    public function show(string $id): JSONResponse
    {
        try {
            // Try to find the schema by ID
            return new JSONResponse($this->schemaMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            // Return a 404 error if the schema doesn't exist
            return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
        }

    }//end show()

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
        // Get request parameters
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_')
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to ensure a new record is created
        if (isset($data['id'])) {
            unset($data['id']);
        }

        // Create a new schema from the data
        return new JSONResponse($this->schemaMapper->createFromArray(object: $data));

    }//end create()

    /**
     * Updates an existing schema
     *
     * This method updates an existing schema based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the schema to update
     *
     * @return JSONResponse A JSON response containing the updated schema details
     */
    public function update(int $id): JSONResponse
    {
        // Get request parameters
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_')
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to prevent conflicts
        if (isset($data['id'])) {
            unset($data['id']);
        }

        // Update the schema with the provided data
        return new JSONResponse($this->schemaMapper->updateFromArray(id: $id, object: $data));

    }//end update()

    /**
     * Deletes a schema
     *
     * This method deletes a schema based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the schema to delete
     *
     * @return JSONResponse An empty JSON response
     * @throws Exception If there is an error deleting the schema
     */
    public function destroy(int $id): JSONResponse
    {
        // Find the schema by ID and delete it
        $this->schemaMapper->delete($this->schemaMapper->find(id: $id));

        // Return an empty response
        return new JSONResponse([]);

    }//end destroy()

    /**
     * Updates an existing Schema object using a json text/string as input
     *
     * Uses 'file', 'url' or else 'json' from POST body.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int|null $id The ID of the schema to update, or null for a new schema
     *
     * @return JSONResponse The JSON response with the updated schema
     * @throws Exception If there is a database error
     * @throws GuzzleException If there is an HTTP request error
     */
    public function uploadUpdate(?int $id = NULL): JSONResponse
    {
        return $this->upload($id);

    }//end uploadUpdate()

    /**
     * Creates a new Schema object or updates an existing one
     *
     * Uses 'file', 'url' or else 'json' from POST body.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int|null $id The ID of the schema to update, or null for a new schema
     *
     * @return JSONResponse The JSON response with the created or updated schema
     * @throws Exception If there is a database error
     * @throws GuzzleException If there is an HTTP request error
     */
    public function upload(?int $id = NULL): JSONResponse
    {
        if ($id !== NULL) {
            // If ID is provided, find the existing schema
            $schema = $this->schemaMapper->find($id);
        } else {
            // Otherwise, create a new schema
            $schema = new Schema();
            $schema->setUuid(Uuid::v4());
        }

        // Get the uploaded JSON data
        $phpArray = $this->uploadService->getUploadedJson($this->request->getParams());
        if ($phpArray instanceof JSONResponse) {
            // Return any error response from the upload service
            return $phpArray;
        }

        // Set default title if not provided or empty
        if (empty($phpArray['title']) === TRUE) {
            $phpArray['title'] = 'New Schema';
        }

        // Update the schema with the data from the uploaded JSON
        $schema->hydrate($phpArray);

        if ($schema->getId() === NULL) {
            // Insert a new schema if no ID is set
            $schema = $this->schemaMapper->insert($schema);
        } else {
            // Update the existing schema
            $schema = $this->schemaMapper->update($schema);
        }

        return new JSONResponse($schema);

    }//end upload()

    /**
     * Creates and return a json file for a Schema
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the schema to return json file for
     *
     * @return JSONResponse A JSON response containing the schema as JSON
     * @throws Exception If there is an error retrieving the schema
     */
    public function download(int $id): JSONResponse
    {
        // Get the Accept header to determine the response format
        $accept = $this->request->getHeader('Accept');

        try {
            // Find the schema by ID
            $schema = $this->schemaMapper->find($id);
        } catch (Exception $e) {
            // Return a 404 error if the schema doesn't exist
            return new JSONResponse(['error' => 'Schema not found'], 404);
        }

        // Return the schema as JSON
        return new JSONResponse($schema);

    }//end download()

}//end class
