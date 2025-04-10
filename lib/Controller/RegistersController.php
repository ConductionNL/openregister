<?php
/**
 * Class RegistersController
 *
 * Controller for managing register operations in the OpenRegister app.
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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Service\UploadService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\DB\Exception;
use OCP\IRequest;
use Symfony\Component\Uid\Uuid;

/**
 * Class RegistersController
 */
class RegistersController extends Controller
{


    /**
     * Constructor for the RegistersController
     *
     * @param string              $appName             The name of the app
     * @param IRequest            $request             The request object
     * @param RegisterMapper      $registerMapper      The register mapper
     * @param ObjectEntityMapper  $objectEntityMapper  The object entity mapper
     * @param UploadService       $uploadService       The upload service
     * @param ConfigurationService $configurationService The configuration service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly RegisterMapper $registerMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly UploadService $uploadService,
        ConfigurationService $configurationService
    ) {
        parent::__construct($appName, $request);
        $this->configurationService = $configurationService;
    }


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
     * Retrieves a list of all registers
     *
     * This method returns a JSON response containing an array of all registers in the system.
     *
     * @param ObjectService $objectService The object service
     * @param SearchService $searchService The search service
     *
     * @return JSONResponse A JSON response containing the list of registers
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
        $filters        = $this->request->getParams();
        $fieldsToSearch = ['title', 'description'];

        // Create search parameters and conditions for filtering.
        $searchParams     = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(
            filters: $filters,
            fieldsToSearch: $fieldsToSearch
        );
        $filters          = $searchService->unsetSpecialQueryParams(filters: $filters);

        // Return all registers that match the search conditions.
        return new JSONResponse(
            [
                'results' => $this->registerMapper->findAll(
                    limit: null,
                    offset: null,
                    filters: $filters,
                    searchConditions: $searchConditions,
                    searchParams: $searchParams
                ),
            ]
        );

    }//end index()


    /**
     * Retrieves a single register by its ID
     *
     * This method returns a JSON response containing the details of a specific register.
     *
     * @param string $id The ID of the register to retrieve
     *
     * @return JSONResponse A JSON response containing the register details
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show(string $id): JSONResponse
    {
        try {
            // Try to find the register by ID.
            return new JSONResponse($this->registerMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            // Return a 404 error if the register doesn't exist.
            return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
        }

    }//end show()


    /**
     * Creates a new register
     *
     * This method creates a new register based on POST data.
     *
     * @return JSONResponse A JSON response containing the created register
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

        // Create a new register from the data.
        return new JSONResponse($this->registerMapper->createFromArray(object: $data));

    }//end create()


    /**
     * Updates an existing register
     *
     * This method updates an existing register based on its ID.
     *
     * @param int $id The ID of the register to update
     *
     * @return JSONResponse A JSON response containing the updated register details
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

        // Update the register with the provided data.
        return new JSONResponse($this->registerMapper->updateFromArray(id: (int) $id, object: $data));

    }//end update()


    /**
     * Deletes a register
     *
     * This method deletes a register based on its ID.
     *
     * @param int $id The ID of the register to delete
     *
     * @throws Exception If there is an error deleting the register
     *
     * @return JSONResponse An empty JSON response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function destroy(int $id): JSONResponse
    {
        // Find the register by ID and delete it.
        $this->registerMapper->delete($this->registerMapper->find((int) $id));

        // Return an empty response.
        return new JSONResponse([]);

    }//end destroy()


    /**
     * Get objects
     *
     * Get all the objects for a register and schema
     *
     * @param int $register The ID of the register
     * @param int $schema   The ID of the schema
     *
     * @return JSONResponse A JSON response containing the objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function objects(int $register, int $schema): JSONResponse
    {
        // Find objects by register and schema IDs.
        return new JSONResponse(
            $this->objectEntityMapper->findByRegisterAndSchema(register: $register, schema: $schema)
        );

    }//end objects()


    /**
     * Updates an existing Register object using a json text/string as input
     *
     * Uses 'file', 'url' or else 'json' from POST body.     *
     *
     * @param int|null $id The ID of the register to update, or null for a new register
     *
     * @throws Exception If there is a database error
     * @throws GuzzleException If there is an HTTP request error
     *
     * @return JSONResponse The JSON response with the updated register
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
     * Creates a new Register object or updates an existing one
     *
     * Uses 'file', 'url' or else 'json' from POST body.
     *
     * @param int|null $id The ID of the register to update, or null for a new register
     *
     * @throws GuzzleException If there is an HTTP request error
     * @throws Exception If there is a database error
     *
     * @return JSONResponse The JSON response with the created or updated register
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function upload(?int $id=null): JSONResponse
    {
        if ($id !== null) {
            // If ID is provided, find the existing register.
            $register = $this->registerMapper->find($id);
        } else {
            // Otherwise, create a new register.
            $register = new Register();
            $register->setUuid(Uuid::v4());
        }

        // Get the uploaded JSON data.
        $phpArray = $this->uploadService->getUploadedJson($this->request->getParams());
        if ($phpArray instanceof JSONResponse) {
            // Return any error response from the upload service.
            return $phpArray;
        }

        // Validate that the jsonArray is a valid OAS3 object containing schemas.
        if (isset($phpArray['openapi']) === false || isset($phpArray['components']['schemas']) === false) {
            return new JSONResponse(
                data: ['error' => 'Invalid OAS3 object. Must contain openapi version and components.schemas.'],
                statusCode: 400
            );
        }

        // Set default title if not provided or empty.
        if (empty($phpArray['info']['title']) === true) {
            $phpArray['info']['title'] = 'New Register';
        }

        // Update the register with the data from the uploaded JSON.
        $register->hydrate($phpArray);

        if ($register->getId() === null) {
            // Insert a new register if no ID is set.
            $register = $this->registerMapper->insert($register);
        } else {
            // Update the existing register.
            $register = $this->registerMapper->update($register);
        }

        return new JSONResponse($register);

    }//end upload()


    /**
     * Export a register and its related data
     *
     * This method exports a register, its schemas, and optionally its objects
     * in OpenAPI format.
     *
     * @param int  $id            The ID of the register to export
     * @param bool $includeObjects Whether to include objects in the export
     *
     * @return DataDownloadResponse|JSONResponse The exported register data as a downloadable file or error response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function export(int $id, bool $includeObjects = false): DataDownloadResponse|JSONResponse
    {
        try {
            // Find the register
            $register = $this->registerMapper->find($id);

            // Export the register and its related data
            $exportData = $this->configurationService->exportConfig($register, $includeObjects);

            // Convert to JSON
            $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonContent === false) {
                throw new Exception('Failed to encode register data to JSON');
            }

            // Generate filename based on register slug and current date
            $filename = sprintf(
                '%s_%s.json',
                $register->getSlug(),
                (new \DateTime())->format('Y-m-d_His')
            );

            // Return as downloadable file
            return new DataDownloadResponse(
                $jsonContent,
                $filename,
                'application/json'
            );
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to export register: ' . $e->getMessage()],
                400
            );
        }
    }


    /**
     * Import data into a register
     *
     * This method imports schemas and optionally objects into an existing register
     * from an OpenAPI format file.
     *
     * @param int  $id            The ID of the register to import into
     * @param bool $includeObjects Whether to include objects in the import
     *
     * @return JSONResponse The result of the import operation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function import(int $id, bool $includeObjects = false): JSONResponse
    {
        try {
            // Get the uploaded JSON data
            $jsonData = $this->uploadService->getUploadedJson($this->request->getParams());
            if ($jsonData instanceof JSONResponse) {
                return $jsonData;
            }

            // Convert array to JSON string
            $jsonContent = json_encode($jsonData);
            if ($jsonContent === false) {
                throw new Exception('Failed to encode upload data to JSON');
            }

            // Find the register to get the owner
            $register = $this->registerMapper->find($id);
            
            // Import the data
            $result = $this->configurationService->importFromJson(
                $jsonContent,
                $includeObjects,
                $register->getOwner()
            );

            return new JSONResponse([
                'message' => 'Import successful',
                'imported' => $result
            ]);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to import data: ' . $e->getMessage()],
                400
            );
        }
    }

}//end class
