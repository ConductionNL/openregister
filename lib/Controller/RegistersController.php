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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
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
     * Configuration service for handling import/export operations
     *
     * @var ConfigurationService
     */
    private readonly ConfigurationService $configurationService;

    /**
     * Audit trail mapper for fetching log statistics
     *
     * @var AuditTrailMapper
     */
    private readonly AuditTrailMapper $auditTrailMapper;

    /**
     * Export service for handling data exports
     *
     * @var ExportService
     */
    private readonly ExportService $exportService;

    /**
     * Import service for handling data imports
     *
     * @var ImportService
     */
    private readonly ImportService $importService;

    /**
     * Constructor for the RegistersController
     *
     * @param string               $appName              The name of the app
     * @param IRequest             $request              The request object
     * @param RegisterMapper       $registerMapper       The register mapper
     * @param ObjectEntityMapper   $objectEntityMapper   The object entity mapper
     * @param UploadService        $uploadService        The upload service
     * @param ConfigurationService $configurationService The configuration service
     * @param AuditTrailMapper     $auditTrailMapper     The audit trail mapper
     * @param ExportService        $exportService        The export service
     * @param ImportService        $importService        The import service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly RegisterMapper $registerMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly UploadService $uploadService,
        ConfigurationService $configurationService,
        AuditTrailMapper $auditTrailMapper,
        ExportService $exportService,
        ImportService $importService
    ) {
        parent::__construct($appName, $request);
        $this->configurationService = $configurationService;
        $this->auditTrailMapper     = $auditTrailMapper;
        $this->exportService        = $exportService;
        $this->importService        = $importService;
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
        $filters = $this->request->getParam('filters', []);
        $search  = $this->request->getParam('_search', '');
        $extend  = $this->request->getParam('_extend', []);
        if (is_string($extend)) {
            $extend = [$extend];
        }

        $registers    = $this->registerMapper->findAll(null, null, $filters, [], [], []);
        $registersArr = array_map(fn($register) => $register->jsonSerialize(), $registers);
        // If '@self.stats' is requested, attach statistics to each register
        if (in_array('@self.stats', $extend, true)) {
            foreach ($registersArr as &$register) {
                $register['stats'] = [
                    'objects' => $this->objectEntityMapper->getStatistics($register['id'], null),
                    'logs'    => $this->auditTrailMapper->getStatistics($register['id'], null),
                    'files'   => [ 'total' => 0, 'size' => 0 ],
                ];
            }
        }

        return new JSONResponse(['results' => $registersArr]);

    }//end index()


    /**
     * Retrieves a single register by ID
     *
     * @param  int|string $id The ID of the register
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

        $register    = $this->registerMapper->find($id, []);
        $registerArr = $register->jsonSerialize();
        // If '@self.stats' is requested, attach statistics to the register
        if (in_array('@self.stats', $extend, true)) {
            $registerArr['stats'] = [
                'objects' => $this->objectEntityMapper->getStatistics($registerArr['id'], null),
                'logs'    => $this->auditTrailMapper->getStatistics($registerArr['id'], null),
                'files'   => [ 'total' => 0, 'size' => 0 ],
            ];
        }

        return new JSONResponse($registerArr);

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
     * Export a register and its related data
     *
     * This method exports a register, its schemas, and optionally its objects
     * in the specified format.
     *
     * @param int $id The ID of the register to export
     *
     * @return DataDownloadResponse|JSONResponse The exported register data as a downloadable file or error response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function export(int $id): DataDownloadResponse | JSONResponse
    {
        try {
            // Get export format from query parameter
            $format = $this->request->getParam('format', 'configuration');
            $includeObjects = filter_var($this->request->getParam('includeObjects', false), FILTER_VALIDATE_BOOLEAN);
            $register = $this->registerMapper->find($id);

            switch ($format) {
                case 'excel':
                    $spreadsheet = $this->exportService->exportToExcel($register);
                    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                    $filename = sprintf('%s_%s.xlsx', $register->getSlug(), (new \DateTime())->format('Y-m-d_His'));
                    ob_start();
                    $writer->save('php://output');
                    $content = ob_get_clean();
                    return new DataDownloadResponse($content, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                case 'csv':
                    $csv = $this->exportService->exportToCsv($register);
                    $filename = sprintf('%s_%s.csv', $register->getSlug(), (new \DateTime())->format('Y-m-d_His'));
                    return new DataDownloadResponse($csv, $filename, 'text/csv');
                case 'configuration':
                default:
                    $exportData = $this->configurationService->exportConfig($register, $includeObjects);
                    $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    if ($jsonContent === false) {
                        throw new Exception('Failed to encode register data to JSON');
                    }
                    $filename = sprintf('%s_%s.json', $register->getSlug(), (new \DateTime())->format('Y-m-d_His'));
                    return new DataDownloadResponse($jsonContent, $filename, 'application/json');
            }
        } catch (Exception $e) {
            return new JSONResponse(['error' => 'Failed to export register: '.$e->getMessage()], 400);
        }
    }

    /**
     * Import data into a register
     *
     * This method imports data into a register in the specified format.
     *
     * @param int $id The ID of the register to import into
     *
     * @return JSONResponse The result of the import operation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function import(int $id): JSONResponse
    {
        try {
            // Get the uploaded file
            $uploadedFile = $this->request->getUploadedFile('file');
            if ($uploadedFile === null) {
                return new JSONResponse(['error' => 'No file uploaded'], 400);
            }

            // Dynamically determine import type if not provided
            $type = $this->request->getParam('type');
            if (!$type) {
                $mimeType = $uploadedFile['type'] ?? '';
                $filename = $uploadedFile['name'] ?? '';
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($extension, ['xlsx', 'xls'])) {
                    $type = 'excel';
                } elseif ($extension === 'csv') {
                    $type = 'csv';
                } else {
                    $type = 'configuration';
                }
            }

            // Get includeObjects parameter for all types
            $includeObjects = filter_var($this->request->getParam('includeObjects', false), FILTER_VALIDATE_BOOLEAN);
            // Find the register
            $register = $this->registerMapper->find($id);
            // Handle different import types
            switch ($type) {
                case 'excel':
                    $importedIds = $this->importService->importFromExcel(
                        $uploadedFile['tmp_name'],
                        $register,
                        null,
                        $includeObjects
                    );
                    break;
                case 'csv':
                    $importedIds = $this->importService->importFromCsv(
                        $uploadedFile['tmp_name'],
                        $register,
                        null,
                        $includeObjects
                    );
                    break;
                case 'configuration':
                default:
                    // Initialize the uploaded files array
                    $uploadedFiles = [$uploadedFile];
                    // Get the uploaded JSON data
                    $jsonData = $this->configurationService->getUploadedJson($this->request->getParams(), $uploadedFiles);
                    if ($jsonData instanceof JSONResponse) {
                        return $jsonData;
                    }
                    // Import the data
                    $result = $this->configurationService->importFromJson(
                        $jsonData,
                        $includeObjects,
                        $this->request->getParam('owner')
                    );
                    return new JSONResponse([
                        'message' => 'Import successful',
                        'imported' => $result
                    ]);
            }
            return new JSONResponse([
                'message' => 'Import successful',
                'imported' => count($importedIds),
                'ids' => $importedIds
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

}//end class
