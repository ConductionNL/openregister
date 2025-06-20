<?php
/**
 * Class SchemasController
 *
 * Controller for managing schema operations in the OpenRegister app.
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

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
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
use OCA\OpenRegister\Db\AuditTrailMapper;

/**
 * Class SchemasController
 */
class SchemasController extends Controller
{


    /**
     * Constructor for the SchemasController
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param IAppConfig         $config             The app configuration object
     * @param SchemaMapper       $schemaMapper       The schema mapper
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param DownloadService    $downloadService    The download service
     * @param UploadService      $uploadService      The upload service
     * @param AuditTrailMapper   $auditTrailMapper   The audit trail mapper
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly DownloadService $downloadService,
        private readonly UploadService $uploadService,
        private readonly AuditTrailMapper $auditTrailMapper
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
     * Retrieves a list of all schemas
     *
     * This method returns a JSON response containing an array of all schemas in the system.
     *
     * @param ObjectService $objectService The object service
     * @param SearchService $searchService The search service
     *
     * @return JSONResponse A JSON response containing the list of schemas
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function index(
        ObjectService $objectService,
        SearchService $searchService
    ): JSONResponse {
        // Get request parameters for filtering and searching.
        $filters = $this->request->getParam('filters', []);
        $search  = $this->request->getParam('_search', '');
        $extend  = $this->request->getParam('_extend', []);
        if (is_string($extend)) {
            $extend = [$extend];
        }

        $schemas    = $this->schemaMapper->findAll(null, null, $filters, [], [], []);
        $schemasArr = array_map(fn($schema) => $schema->jsonSerialize(), $schemas);
        // If '@self.stats' is requested, attach statistics to each schema
        if (in_array('@self.stats', $extend, true)) {
            // Get register counts for all schemas in one call
            $registerCounts = $this->schemaMapper->getRegisterCountPerSchema();
            foreach ($schemasArr as &$schema) {
                $schema['stats'] = [
                    'objects'   => $this->objectEntityMapper->getStatistics(null, $schema['id']),
                    'logs'      => $this->auditTrailMapper->getStatistics(null, $schema['id']),
                    'files'     => [ 'total' => 0, 'size' => 0 ],
                    // Add the number of registers referencing this schema
                    'registers' => $registerCounts[$schema['id']] ?? 0,
                ];
            }
        }

        return new JSONResponse(['results' => $schemasArr]);

    }//end index()


    /**
     * Retrieves a single schema by ID
     *
     * @param  int|string $id The ID of the schema
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show($id): JSONResponse
    {
        $extend = $this->request->getParam('_extend', []);
        if (is_string($extend)) {
            $extend = [$extend];
        }

        $schema    = $this->schemaMapper->find($id, []);
        $schemaArr = $schema->jsonSerialize();
        // If '@self.stats' is requested, attach statistics to the schema
        if (in_array('@self.stats', $extend, true)) {
            // Get register counts for all schemas in one call
            $registerCounts     = $this->schemaMapper->getRegisterCountPerSchema();
            $schemaArr['stats'] = [
                'objects'   => $this->objectEntityMapper->getStatistics(null, $schemaArr['id']),
                'logs'      => $this->auditTrailMapper->getStatistics(null, $schemaArr['id']),
                'files'     => [ 'total' => 0, 'size' => 0 ],
                // Add the number of registers referencing this schema
                'registers' => $registerCounts[$schemaArr['id']] ?? 0,
            ];
        }

        return new JSONResponse($schemaArr);

    }//end show()


    /**
     * Creates a new schema
     *
     * This method creates a new schema based on POST data.
     *
     * @return JSONResponse A JSON response containing the created schema
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to ensure a new record is created.
        if (isset($data['id']) === true) {
            unset($data['id']);
        }

        // Create a new schema from the data.
        return new JSONResponse($this->schemaMapper->createFromArray(object: $data));

    }//end create()


    /**
     * Updates an existing schema
     *
     * This method updates an existing schema based on its ID.
     *
     * @param int $id The ID of the schema to update
     *
     * @return JSONResponse A JSON response containing the updated schema details
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function update(int $id): JSONResponse
    {
        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to prevent conflicts.
        if (isset($data['id']) === true) {
            unset($data['id']);
        }

        // Update the schema with the provided data.
        return new JSONResponse($this->schemaMapper->updateFromArray(id: $id, object: $data));

    }//end update()


    /**
     * Deletes a schema
     *
     * This method deletes a schema based on its ID.
     *
     * @param int $id The ID of the schema to delete
     *
     * @throws Exception If there is an error deleting the schema
     *
     * @return JSONResponse An empty JSON response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function destroy(int $id): JSONResponse
    {
        // Find the schema by ID and delete it.
        $this->schemaMapper->delete($this->schemaMapper->find(id: $id));

        // Return an empty response.
        return new JSONResponse([]);

    }//end destroy()


    /**
     * Updates an existing Schema object using a json text/string as input
     *
     * Uses 'file', 'url' or else 'json' from POST body.
     *
     * @param int|null $id The ID of the schema to update, or null for a new schema
     *
     * @throws Exception If there is a database error
     *
     * @throws GuzzleException If there is an HTTP request error
     *
     * @return JSONResponse The JSON response with the updated schema
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function uploadUpdate(?int $id=null): JSONResponse
    {
        return $this->upload($id);

    }//end uploadUpdate()


    /**
     * Creates a new Schema object or updates an existing one
     *
     * Uses 'file', 'url' or else 'json' from POST body.
     *
     * @param int|null $id The ID of the schema to update, or null for a new schema
     *
     * @throws Exception If there is a database error
     *
     * @throws GuzzleException If there is an HTTP request error
     *
     * @return JSONResponse The JSON response with the created or updated schema
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function upload(?int $id=null): JSONResponse
    {
        if ($id !== null) {
            // If ID is provided, find the existing schema.
            $schema = $this->schemaMapper->find($id);
        } else {
            // Otherwise, create a new schema.
            $schema = new Schema();
            $schema->setUuid(Uuid::v4());
        }

        // Get the uploaded JSON data.
        $phpArray = $this->uploadService->getUploadedJson($this->request->getParams());
        if ($phpArray instanceof JSONResponse) {
            // Return any error response from the upload service.
            return $phpArray;
        }

        // Set default title if not provided or empty.
        if (empty($phpArray['title']) === true) {
            $phpArray['title'] = 'New Schema';
        }

        // Update the schema with the data from the uploaded JSON.
        $schema->hydrate($phpArray);

        if ($schema->getId() === null) {
            // Insert a new schema if no ID is set.
            $schema = $this->schemaMapper->insert($schema);
        } else {
            // Update the existing schema.
            $schema = $this->schemaMapper->update($schema);
        }

        return new JSONResponse($schema);

    }//end upload()


    /**
     * Creates and return a json file for a Schema
     *
     * @param int $id The ID of the schema to return json file for
     *
     * @throws Exception If there is an error retrieving the schema
     *
     * @return JSONResponse A JSON response containing the schema as JSON
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function download(int $id): JSONResponse
    {
        // Get the Accept header to determine the response format.
        $accept = $this->request->getHeader('Accept');

        try {
            // Find the schema by ID.
            $schema = $this->schemaMapper->find($id);
        } catch (Exception $e) {
            // Return a 404 error if the schema doesn't exist.
            return new JSONResponse(['error' => 'Schema not found'], 404);
        }

        // Return the schema as JSON.
        return new JSONResponse($schema);

    }//end download()


}//end class
