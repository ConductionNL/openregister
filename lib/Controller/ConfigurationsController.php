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
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Symfony\Component\Uid\Uuid;

/**
 * Class ConfigurationController
 *
 * @package OCA\OpenRegister\Controller
 */

/**
 * Controller for managing configurations
 *
 * @psalm-suppress UnusedClass
 */
class ConfigurationsController extends Controller
{


    /**
     * Constructor for ConfigurationController.
     *
     * @param string               $appName              The name of the app
     * @param IRequest             $request              The request object
     * @param ConfigurationMapper  $configurationMapper  The configuration mapper instance
     * @param ConfigurationService $configurationService The configuration service instance
     * @param UploadService        $uploadService        The upload service instance
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ConfigurationMapper $configurationMapper,
        private readonly ConfigurationService $configurationService,
        private readonly UploadService $uploadService
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * List all configurations
     *
     * @return JSONResponse List of configurations.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, array{results: array<\OCA\OpenRegister\Db\Configuration>}, array<never, never>>
     */
    public function index(): JSONResponse
    {
        // Get request parameters for filtering and searching.
        $filters = $this->request->getParams();

        unset($filters['_route']);

        $searchParams     = [];
        $searchConditions = [];
        $filters          = $filters;

        // Return all configurations that match the search conditions.
        return new JSONResponse(
                data: [
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Configuration, array<never, never>>|JSONResponse<404, array{error: 'Configuration not found'}, array<never, never>>
     */
    public function show(int $id): JSONResponse
    {
        try {
            return new JSONResponse(data: $this->configurationMapper->find($id));
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Configuration not found'], statusCode: 404);
        }

    }//end show()


    /**
     * Create a new configuration
     *
     * @return JSONResponse The created configuration.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Configuration, array<never, never>>|JSONResponse<400, array{error: string}, array<never, never>>
     */
    public function create(): JSONResponse
    {
        $data = $this->request->getParams();

        // Remove internal parameters and data attribute.
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true || $key === 'data') {
                unset($data[$key]);
            }
        }

        // Ensure we have a UUID.
        if (isset($data['uuid']) === false) {
            $data['uuid'] = Uuid::v4();
        }

        // Set default values for new local configurations.
        // If sourceType is not provided, assume it's a local configuration.
        if (isset($data['sourceType']) === false || $data['sourceType'] === null || $data['sourceType'] === '') {
            $data['sourceType'] = 'local';
        }

        // Set isLocal based on sourceType (enforce consistency).
        // Local configurations: sourceType === 'local' or 'manual' → isLocal = true.
        // External configurations: sourceType === 'github', 'gitlab', or 'url' → isLocal = false.
        if (in_array($data['sourceType'], ['local', 'manual'], true) === true) {
            $data['isLocal'] = true;
        } else if (in_array($data['sourceType'], ['github', 'gitlab', 'url'], true) === true) {
            $data['isLocal'] = false;
        } else if (isset($data['isLocal']) === false) {
            // Fallback: if sourceType is something else and isLocal not set, default to true.
            $data['isLocal'] = true;
        }

        try {
            return new JSONResponse(
                    data: $this->configurationMapper->createFromArray($data)
            );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Failed to create configuration: '.$e->getMessage()], statusCode: 400);
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Configuration, array<never, never>>|JSONResponse<400, array{error: string}, array<never, never>>
     */
    public function update(int $id): JSONResponse
    {
        $data = $this->request->getParams();

        // Remove internal parameters and data attribute.
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true || $key === 'data') {
                unset($data[$key]);
            }
        }

        // Remove immutable fields to prevent tampering.
        unset($data['id']);
        unset($data['organisation']);
        unset($data['owner']);
        unset($data['created']);

        // Enforce consistency between sourceType and isLocal.
        if (($data['sourceType'] ?? null) !== null) {
            if (in_array($data['sourceType'], ['local', 'manual'], true) === true) {
                $data['isLocal'] = true;
            } else if (in_array($data['sourceType'], ['github', 'gitlab', 'url'], true) === true) {
                $data['isLocal'] = false;
            }
        }

        try {
            return new JSONResponse(
                    data: $this->configurationMapper->updateFromArray(id: $id, data: $data)
            );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Failed to update configuration: '.$e->getMessage()], statusCode: 400);
        }

    }//end update()


    /**
     * Patch (partially update) a configuration.
     *
     * @param int $id The ID of the configuration to patch
     *
     * @return JSONResponse The updated configuration data
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function patch(int $id): JSONResponse
    {
        return $this->update($id);

    }//end patch()


    /**
     * Delete a configuration
     *
     * @param int $id Configuration ID
     *
     * @return JSONResponse Empty response on success
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<204, null, array<never, never>>|JSONResponse<400, array{error: string}, array<never, never>>
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $this->configurationMapper->delete($configuration);
            return new JSONResponse(data: null, statusCode: 204);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Failed to delete configuration: '.$e->getMessage()], statusCode: 400);
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return DataDownloadResponse<200, 'application/json', array<never, never>>|JSONResponse<400, array{error: string}, array<never, never>>
     */
    public function export(int $id, bool $includeObjects=false): JSONResponse|DataDownloadResponse
    {
        try {
            // Find the configuration.
            $configuration = $this->configurationMapper->find($id);

            // Export the configuration and its related data.
            $exportData = $this->configurationService->exportConfig(input: $configuration, includeObjects: $includeObjects);

            // Convert to JSON.
            $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonContent === false) {
                throw new Exception('Failed to encode configuration data to JSON');
            }

            // Generate filename.
            $filename = sprintf(
                'configuration_%s_%s.json',
                    $configuration->getTitle() ?? 'unknown',
                (new DateTime())->format('Y-m-d_His')
            );

            // Return as downloadable file.
            return new DataDownloadResponse(
                    $jsonContent,
                    $filename,
                    'application/json'
            );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Failed to export configuration: '.$e->getMessage()], statusCode: 400);
        }//end try

    }//end export()


    /**
     * Import a configuration
     *
     * @return JSONResponse The import result.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function import(): JSONResponse
    {
        try {
            // Initialize uploadedFiles array.
            $uploadedFiles = [];

            // Get the uploaded file from the request if a single file has been uploaded.
            $uploadedFile = $this->request->getUploadedFile(key: 'file');
            if (empty($uploadedFile) === false) {
                $uploadedFiles[] = $uploadedFile;
            }

            // Get the uploaded JSON data.
            $jsonData = $this->configurationService->getUploadedJson(data: $this->request->getParams(), uploadedFiles: $uploadedFiles);
            if ($jsonData instanceof JSONResponse) {
                return $jsonData;
            }

            // Import the data.
            $force  = $this->request->getParam('force') === 'true' || $this->request->getParam('force') === true;
            $result = $this->configurationService->importFromJson(
                data: $jsonData,
                configuration: null,
                owner: $this->request->getParam('owner'),
                appId: $this->request->getParam('appId'),
                version: $this->request->getParam('version'),
                force: $force
            );

            return new JSONResponse(
                    data: [
                        'message'  => 'Import successful',
                        'imported' => $result,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Failed to import configuration: '.$e->getMessage()], statusCode: 400);
        }//end try

    }//end import()


}//end class
