<?php
/**
 * OpenRegister Configuration Controller
 *
 * This file contains the controller class for handling configuration operations
 * in the OpenRegister application.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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

use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Symfony\Component\Uid\Uuid;

/**
 * Class ConfigurationController
 *
 * @package OCA\OpenRegister\Controller
 */
class ConfigurationsController extends Controller
{


    /**
     * Constructor for ConfigurationController
     *
     * @param string               $appName              The name of the app
     * @param IRequest             $request              The request object
     * @param ConfigurationMapper  $configurationMapper  The configuration mapper
     * @param ConfigurationService $configurationService The configuration service
     * @param UploadService        $uploadService        The upload service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ConfigurationMapper $configurationMapper,
        private readonly ConfigurationService $configurationService,
        private readonly UploadService $uploadService
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * List all configurations
     *
     * @param SearchService $searchService The search service.
     *
     * @return JSONResponse List of configurations.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(SearchService $searchService): JSONResponse
    {
        // Get request parameters for filtering and searching.
        $filters        = $this->request->getParams();
        $fieldsToSearch = ['title', 'description'];

        // Create search parameters and conditions.
        $searchParams     = $searchService->createMySQLSearchParams($filters);
        $searchConditions = $searchService->createMySQLSearchConditions(
            $filters,
            $fieldsToSearch
        );
        $filters          = $searchService->unsetSpecialQueryParams($filters);

        // Return all configurations that match the search conditions.
        return new JSONResponse(
                [
                    'results' => $this->configurationMapper->findAll(
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
     * Show a specific configuration
     *
     * @param int $id Configuration ID
     *
     * @return JSONResponse Configuration details
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(int $id): JSONResponse
    {
        try {
            return new JSONResponse($this->configurationMapper->find($id));
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        }

    }//end show()


    /**
     * Create a new configuration
     *
     * @return JSONResponse The created configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        $data = $this->request->getParams();

        // Remove internal parameters and data attribute
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_') === true || $key === 'data') {
                unset($data[$key]);
            }
        }

        // Ensure we have a UUID.
        if (isset($data['uuid']) === false) {
            $data['uuid'] = Uuid::v4();
        }

        try {
            return new JSONResponse(
                $this->configurationMapper->createFromArray($data)
            );
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to create configuration: '.$e->getMessage()],
                400
            );
        }

    }//end create()


    /**
     * Update an existing configuration
     *
     * @param int $id Configuration ID
     *
     * @return JSONResponse The updated configuration
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(int $id): JSONResponse
    {
        $data = $this->request->getParams();

        // Remove internal parameters and data attribute
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_') === true || $key === 'data') {
                unset($data[$key]);
            }
        }

        try {
            return new JSONResponse(
                $this->configurationMapper->updateFromArray($id, $data)
            );
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to update configuration: '.$e->getMessage()],
                400
            );
        }

    }//end update()


    /**
     * Delete a configuration
     *
     * @param int $id Configuration ID
     *
     * @return JSONResponse Empty response on success
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $this->configurationMapper->delete($configuration);
            return new JSONResponse();
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to delete configuration: '.$e->getMessage()],
                400
            );
        }

    }//end destroy()


    /**
     * Export a configuration
     *
     * @param int  $id             Configuration ID.
     * @param bool $includeObjects Whether to include objects in the export.
     *
     * @return DataDownloadResponse|JSONResponse The exported configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function export(int $id, bool $includeObjects=false): DataDownloadResponse | JSONResponse
    {
        try {
            // Find the configuration.
            $configuration = $this->configurationMapper->find($id);

            // Export the configuration and its related data.
            $exportData = $this->configurationService->exportConfig($configuration, $includeObjects);

            // Convert to JSON.
            $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonContent === false) {
                throw new Exception('Failed to encode configuration data to JSON');
            }

            // Generate filename.
            $filename = sprintf(
                'configuration_%s_%s.json',
                $configuration->getTitle(),
                (new \DateTime())->format('Y-m-d_His')
            );

            // Return as downloadable file.
            return new DataDownloadResponse(
                $jsonContent,
                $filename,
                'application/json'
            );
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to export configuration: '.$e->getMessage()],
                400
            );
        }//end try

    }//end export()


    /**
     * Import a configuration
     *
     * @param bool $includeObjects Whether to include objects in the import.
     *
     * @return JSONResponse The import result.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function import(bool $includeObjects=false): JSONResponse
    {
        try {
            // Get the uploaded file from the request if a single file has been uploaded.
            $uploadedFile = $this->request->getUploadedFile(key: 'file');
            if (empty($uploadedFile) === false) {
                $uploadedFiles[] = $uploadedFile;
            }

            // Get the uploaded JSON data.
            $jsonData = $this->configurationService->getUploadedJson($this->request->getParams(), $uploadedFiles);
            if ($jsonData instanceof JSONResponse) {
                return $jsonData;
            }

            // Convert array to JSON string.
            $jsonContent = json_encode($jsonData);
            if ($jsonContent === false) {
                throw new Exception('Failed to encode upload data to JSON');
            }

            // Import the data.
            $result = $this->configurationService->importFromJson(
                $jsonContent,
                $includeObjects,
                $this->request->getParam('owner')
            );

            return new JSONResponse(
                    [
                        'message'  => 'Import successful',
                        'imported' => $result,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to import configuration: '.$e->getMessage()],
                400
            );
        }//end try

    }//end import()


}//end class
