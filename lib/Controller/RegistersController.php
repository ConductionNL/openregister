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
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\UploadService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\GitHubService;
use OCA\OpenRegister\Service\OasService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\DB\Exception as DBException;
use OCP\IUserSession;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Exception;

/**
 * Class RegistersController
 *
 * @psalm-suppress UnusedClass - This controller is registered via routes.php and used by Nextcloud's routing system
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
     * Schema mapper for handling schema operations
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Register mapper for handling register operations
     *
     * @var RegisterMapper
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * GitHub service for publishing to GitHub
     *
     * @var GitHubService
     */
    private readonly GitHubService $githubService;

    /**
     * App manager for getting app version
     *
     * @var IAppManager
     */
    private readonly IAppManager $appManager;

    /**
     * OAS service for generating OpenAPI specifications
     *
     * @var OasService
     */
    private readonly OasService $oasService;


    /**
     * Constructor for the RegistersController
     *
     * @param string               $appName              The name of the app
     * @param IRequest             $request              The request object
     * @param RegisterService      $registerService      The register service
     * @param ObjectEntityMapper   $objectEntityMapper   The object entity mapper
     * @param UploadService        $uploadService        The upload service
     * @param LoggerInterface      $logger               The logger interface
     * @param ConfigurationService $configurationService The configuration service
     * @param AuditTrailMapper     $auditTrailMapper     The audit trail mapper
     * @param ExportService        $exportService        The export service
     * @param ImportService        $importService        The import service
     * @param SchemaMapper         $schemaMapper         The schema mapper
     * @param RegisterMapper       $registerMapper       The register mapper
     * @param GitHubService        $githubService        GitHub service
     * @param IAppManager          $appManager           App manager
     * @param OasService           $oasService           OAS service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly RegisterService $registerService,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly UploadService $uploadService,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        ConfigurationService $configurationService,
        AuditTrailMapper $auditTrailMapper,
        ExportService $exportService,
        ImportService $importService,
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        GitHubService $githubService,
        IAppManager $appManager,
        OasService $oasService
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->configurationService = $configurationService;
        $this->auditTrailMapper     = $auditTrailMapper;
        $this->exportService        = $exportService;
        $this->importService        = $importService;
        $this->schemaMapper         = $schemaMapper;
        $this->registerMapper       = $registerMapper;
        $this->githubService        = $githubService;
        $this->appManager           = $appManager;
        $this->oasService           = $oasService;

    }//end __construct()


    /**
     * Retrieves a list of all registers
     *
     * This method returns a JSON response containing an array of all registers in the system.
     *
     * @return JSONResponse A JSON response containing the list of registers
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        // Get request parameters for filtering and searching.
        $params = $this->request->getParams();

        // Extract pagination and search parameters.
        $limit  = isset($params['_limit']) ? (int) $params['_limit'] : null;
        $offset = isset($params['_offset']) ? (int) $params['_offset'] : null;
        $page   = isset($params['_page']) ? (int) $params['_page'] : null;
        // Note: search parameter not currently used in this endpoint
        $extend = $params['_extend'] ?? [];
        if (is_string($extend)) {
            $extend = [$extend];
        }

        // Convert page to offset if provided.
        if ($page !== null && $limit !== null) {
            $offset = ($page - 1) * $limit;
        }

        // Extract filters.
        $filters = $params['filters'] ?? [];

        $registers    = $this->registerService->findAll(limit: $limit, offset: $offset, filters: $filters, order: [], searchConditions: [], searchParams: []);
        $registersArr = array_map(fn($register) => $register->jsonSerialize(), $registers);

        // If 'schemas' is requested in _extend, expand schema IDs to full schema objects.
        if (in_array('schemas', $extend, true)) {
            foreach ($registersArr as &$register) {
                if (isset($register['schemas']) && is_array($register['schemas'])) {
                    $expandedSchemas = [];
                    foreach ($register['schemas'] as $schemaId) {
                        try {
                            $schema            = $this->schemaMapper->find($schemaId);
                            $expandedSchemas[] = $schema->jsonSerialize();
                        } catch (DoesNotExistException $e) {
                            // Schema not found, skip it.
                            $this->logger->warning(message: 'Schema not found for expansion', context: ['schemaId' => $schemaId]);
                        }
                    }

                    $register['schemas'] = $expandedSchemas;
                }
            }
        }

        // If '@self.stats' is requested, attach statistics to each register.
        if (in_array('@self.stats', $extend, true)) {
            foreach ($registersArr as &$register) {
                $register['stats'] = [
                    'objects' => $this->objectEntityMapper->getStatistics(registerId: $register['id'], schemaId: null),
                    'logs'    => $this->auditTrailMapper->getStatistics(registerId: $register['id'], schemaId: null),
                    'files'   => [ 'total' => 0, 'size' => 0 ],
                ];
            }
        }

        return new JSONResponse(data: ['results' => $registersArr]);

    }//end index()


    /**
     * Retrieves a single register by ID
     *
     * @param int|string $id The ID of the register
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show($id): JSONResponse
    {
        $extend = $this->request->getParam(key: '_extend', default: []);
        if (is_string($extend)) {
            $extend = [$extend];
        }

        $register    = $this->registerService->find($id, []);
        $registerArr = $register->jsonSerialize();
        // If '@self.stats' is requested, attach statistics to the register.
        if (in_array('@self.stats', $extend, true)) {
            $registerArr['stats'] = [
                'objects' => $this->objectEntityMapper->getStatistics(registerId: $registerArr['id'], schemaId: null),
                'logs'    => $this->auditTrailMapper->getStatistics(registerId: $registerArr['id'], schemaId: null),
                'files'   => [ 'total' => 0, 'size' => 0 ],
            ];
        }

        return new JSONResponse(data: $registerArr);

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
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to ensure a new record is created.
        if (isset($data['id']) === true) {
            unset($data['id']);
        }

        try {
            // Create a new register from the data.
            return new JSONResponse(data: $this->registerService->createFromArray($data), statusCode: 201);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(dbException: $e, entityType: 'register');
            return new JSONResponse(data: ['error' => $constraintException->getMessage()], statusCode: $constraintException->getHttpStatusCode());
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatusCode());
        }

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
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove immutable fields to prevent tampering.
        unset($data['id']);
        unset($data['organisation']);
        unset($data['owner']);
        unset($data['created']);

        try {
            // Update the register with the provided data.
            return new JSONResponse(data: $this->registerService->updateFromArray(id: $id, data: $data));
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(dbException: $e, entityType: 'register');
            return new JSONResponse(data: ['error' => $constraintException->getMessage()], statusCode: $constraintException->getHttpStatusCode());
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatusCode());
        }

    }//end update()


    /**
     * Patch (partially update) a register
     *
     * This method handles partial updates (PATCH requests) by updating only
     * the fields provided in the request body. This is different from PUT
     * which typically requires all fields to be provided.
     *
     * @param int $id The ID of the register to patch
     *
     * @return JSONResponse The updated register data
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function patch(int $id): JSONResponse
    {
        // PATCH works the same as PUT for this resource.
        // The service layer handles partial updates automatically.
        return $this->update($id);

    }//end patch()


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
        try {
            // Find the register by ID and delete it.
            $register = $this->registerService->find($id);
            $this->registerService->delete($register);

            // Return an empty response.
            return new JSONResponse(data: []);
        } catch (\OCA\OpenRegister\Exception\ValidationException $e) {
            // Return 409 Conflict for cascade protection (objects still attached).
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
        } catch (\Exception $e) {
            // Return 500 for other errors.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end destroy()


    /**
     * Get schemas associated with a register
     *
     * This method returns all schemas that are associated with the specified register.
     *
     * @param int|string $id The ID, UUID, or slug of the register
     *
     * @return JSONResponse A JSON response containing the schemas associated with the register
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function schemas(int|string $id): JSONResponse
    {
        try {
            // Find the register first to validate it exists and get its ID.
            $register   = $this->registerService->find($id);
            $registerId = $register->getId();

            // Get the schemas associated with this register.
            $schemas = $this->registerMapper->getSchemasByRegisterId($registerId);

            // Convert schemas to array format for JSON response.
            $schemasArray = array_map(fn($schema) => $schema->jsonSerialize(), $schemas);

            return new JSONResponse(
                    data: [
                        'results' => $schemasArray,
                        'total'   => count($schemasArray),
                    ]
                    );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Return a 404 error if the register doesn't exist.
            return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
        } catch (\Exception $e) {
            // Return a 500 error for other exceptions.
            return new JSONResponse(data: ['error' => 'Internal server error: '.$e->getMessage()], statusCode: 500);
        }//end try

    }//end schemas()


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
        $query = [
            '@self' => [
                'register' => $register,
                'schema'   => $schema,
            ],
        ];
        return new JSONResponse(
                data: $this->objectEntityMapper->searchObjects(query: $query)
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
            // Get export format from query parameter.
            $format         = $this->request->getParam(key: 'format', default: 'configuration');
            $includeObjects = filter_var($this->request->getParam(key: 'includeObjects', default: false), FILTER_VALIDATE_BOOLEAN);
            $register       = $this->registerService->find($id);

            switch ($format) {
                case 'excel':
                    $spreadsheet = $this->exportService->exportToExcel(register: $register, schema: null, filters: [], currentUser: $this->userSession->getUser());
                    $writer      = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                    $filename    = sprintf('%s_%s.xlsx', $register->getSlug(), (new \DateTime())->format('Y-m-d_His'));
                    ob_start();
                    $writer->save('php://output');
                    $content = ob_get_clean();
                    return new DataDownloadResponse($content, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                case 'csv':
                    // CSV exports require a specific schema.
                    $schemaId = $this->request->getParam('schema');

                    if ($schemaId === null || $schemaId === '') {
                        // If no schema specified, return error (CSV cannot handle multiple schemas).
                        return new JSONResponse(data: ['error' => 'CSV export requires a specific schema to be selected'], statusCode: 400);
                    }

                    $schema   = $this->schemaMapper->find($schemaId);
                    $csv      = $this->exportService->exportToCsv(register: $register, schema: $schema, filters: [], currentUser: $this->userSession->getUser());
                    $filename = sprintf('%s_%s_%s.csv', $register->getSlug(), $schema->getSlug(), (new \DateTime())->format('Y-m-d_His'));
                    return new DataDownloadResponse($csv, $filename, 'text/csv');
                case 'configuration':
                default:
                    $exportData  = $this->configurationService->exportConfig(input: $register, includeObjects: $includeObjects);
                    $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    if ($jsonContent === false) {
                        throw new Exception('Failed to encode register data to JSON');
                    }

                    $filename = sprintf('%s_%s.json', $register->getSlug(), (new \DateTime())->format('Y-m-d_His'));
                    return new DataDownloadResponse($jsonContent, $filename, 'application/json');
            }//end switch
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => 'Failed to export register: '.$e->getMessage()], statusCode: 400);
        }//end try

    }//end export()


    /**
     * Publish register OAS specification to GitHub
     *
     * Exports the register as OpenAPI Specification and publishes it to a GitHub repository.
     *
     * @param int $id The ID of the register to publish
     *
     * @return JSONResponse Publish result with commit info
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function publishToGitHub(int $id): JSONResponse
    {
        try {
            $register = $this->registerMapper->find($id);

            $data          = $this->request->getParams();
            $owner         = $data['owner'] ?? '';
            $repo          = $data['repo'] ?? '';
            $path          = $data['path'] ?? '';
            $branch        = $data['branch'] ?? 'main';
            $commitMessage = $data['commitMessage'] ?? "Update register OAS: {$register->getTitle()}";

            if (empty($owner) || empty($repo)) {
                return new JSONResponse(data: ['error' => 'Owner and repo parameters are required'], statusCode: 400);
            }

            // Strip leading slash from path.
            $path = ltrim($path, '/');

            // If path is empty, use a default filename based on register slug.
            if (empty($path)) {
                $slug = $register->getSlug();
                $path = $slug.'_openregister.json';
            }

            $this->logger->info(
                    'Publishing register OAS to GitHub',
                    [
                        'register_id'   => $id,
                        'register_slug' => $register->getSlug(),
                        'owner'         => $owner,
                        'repo'          => $repo,
                        'path'          => $path,
                        'branch'        => $branch,
                    ]
                    );

            // Generate real OAS (OpenAPI Specification) for the register.
            // Do NOT add x-openregister metadata - this is a pure OAS file, not a configuration file.
            $oasData = $this->oasService->createOas((string) $register->getId());

            $jsonContent = json_encode($oasData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Check if file already exists (for updates).
            $fileSha = null;
            try {
                $fileSha = $this->githubService->getFileSha(owner: $owner, repo: $repo, path: $path, branch: $branch);
            } catch (\Exception $e) {
                // File doesn't exist, which is fine for new files.
                $this->logger->debug('File does not exist, will create new file', ['path' => $path]);
            }

            // Publish to GitHub.
            $result = $this->githubService->publishConfiguration(
                owner: $owner,
                repo: $repo,
                path: $path,
                branch: $branch,
                content: $jsonContent,
                commitMessage: $commitMessage,
                fileSha: $fileSha
            );

            $this->logger->info(
                    "Successfully published register OAS {$register->getTitle()} to GitHub",
                    [
                        'owner'    => $owner,
                        'repo'     => $repo,
                        'branch'   => $branch,
                        'path'     => $path,
                        'file_url' => $result['file_url'] ?? null,
                    ]
                    );

            // Check if published to default branch (required for Code Search indexing).
            $defaultBranch = null;
            try {
                $repoInfo      = $this->githubService->getRepositoryInfo(owner: $owner, repo: $repo);
                $defaultBranch = $repoInfo['default_branch'] ?? 'main';
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Could not fetch repository default branch',
                        [
                            'owner' => $owner,
                            'repo'  => $repo,
                            'error' => $e->getMessage(),
                        ]
                        );
            }

            $message = 'Register OAS published successfully to GitHub';
            if ($defaultBranch && $branch !== $defaultBranch) {
                $message .= ". Note: Published to branch '{$branch}' (default is '{$defaultBranch}'). "."GitHub Code Search primarily indexes the default branch, so this may not appear in search results immediately.";
            } else {
                $message .= ". Note: GitHub Code Search may take a few minutes to index new files.";
            }

            return new JSONResponse(
                data: [
                    'success'        => true,
                    'message'        => $message,
                    'registerId'     => $register->getId(),
                    'commit_sha'     => $result['commit_sha'],
                    'commit_url'     => $result['commit_url'],
                    'file_url'       => $result['file_url'],
                    'branch'         => $branch,
                    'default_branch' => $defaultBranch,
                    'indexing_note'  => $defaultBranch && $branch !== $defaultBranch ? "Published to non-default branch. For discovery, publish to '{$defaultBranch}' branch." : "File published successfully. GitHub Code Search indexing may take a few minutes.",
                ],
                    statusCode: 200
                );
        } catch (DoesNotExistException $e) {
            $this->logger->error('Register not found for publishing', ['register_id' => $id]);
            return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
        } catch (\Exception $e) {
            $this->logger->error('Failed to publish register OAS to GitHub: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to publish register OAS: '.$e->getMessage()], statusCode: 500);
        }//end try

    }//end publishToGitHub()


    /**
     * Import data into a register
     *
     * This method imports data into a register in the specified format and returns a detailed summary.
     *
     * @param int  $id    The ID of the register to import into
     * @param bool $force Force import even if the same or newer version already exists
     *
     * @return         JSONResponse The result of the import operation with summary
     * @phpstan-return JSONResponse
     * @psalm-return   JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function import(int $id, bool $force=false): JSONResponse
    {
        try {
            // Get the uploaded file.
            $uploadedFile = $this->request->getUploadedFile('file');
            if ($uploadedFile === null) {
                return new JSONResponse(data: ['error' => 'No file uploaded'], statusCode: 400);
            }

            // Dynamically determine import type if not provided.
            $type = $this->request->getParam('type');
            if ($type === null || $type === '') {
                $filename  = $uploadedFile['name'] ?? '';
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($extension, ['xlsx', 'xls'])) {
                    $type = 'excel';
                } else if ($extension === 'csv') {
                    $type = 'csv';
                } else {
                    $type = 'configuration';
                }
            }

            // Get import options for all types - support both boolean and string values.
            $includeObjects = $this->parseBooleanParam(paramName: 'includeObjects', default: false);
            $validation     = $this->parseBooleanParam(paramName: 'validation', default: false);
            $events         = $this->parseBooleanParam(paramName: 'events', default: false);
            $publish        = $this->parseBooleanParam(paramName: 'publish', default: false);

            // Log import parameters for debugging.
            $this->logger->debug(
                    'Import parameters received',
                    [
                        'includeObjects' => $includeObjects,
                        'validation'     => $validation,
                        'events'         => $events,
                        'publish'        => $publish,
                        'registerId'     => $id,
                    ]
                    );
            // Find the register.
            $register = $this->registerService->find($id);
            // Handle different import types.
            switch ($type) {
                case 'excel':
                    // Import from Excel and get summary (now returns sheet-based format).
                    // Get additional performance parameters with enhanced boolean parsing.
                    $rbac      = $this->parseBooleanParam(paramName: 'rbac', default: true);
                    $multi     = $this->parseBooleanParam(paramName: 'multi', default: true);
                    $chunkSize = (int) $this->request->getParam('chunkSize', 5);
                    // Use optimized default.
                    $summary = $this->importService->importFromExcel(
                        filePath: $uploadedFile['tmp_name'],
                        register: $register,
                        schema: null,
                        chunkSize: $chunkSize,
                        validation: $validation,
                        events: $events,
                        rbac: $rbac,
                        multi: $multi,
                        publish: $publish,
                        currentUser: $this->userSession->getUser()
                    );
                    break;
                case 'csv':
                    // Import from CSV and get summary (now returns sheet-based format).
                    // For CSV, schema MUST be specified in the request.
                    $schemaId = $this->request->getParam('schema');

                    if ($schemaId === null || $schemaId === '') {
                        return new JSONResponse(data: ['error' => 'Schema parameter is required for CSV imports. Please specify ?schema=105 in your request.'], statusCode: 400);
                    }

                    $schema = $this->schemaMapper->find($schemaId);

                    // Get additional performance parameters with enhanced boolean parsing.
                    $rbac      = $this->parseBooleanParam(paramName: 'rbac', default: true);
                    $multi     = $this->parseBooleanParam(paramName: 'multi', default: true);
                    $chunkSize = (int) $this->request->getParam('chunkSize', 5);
                    // Use optimized default.
                    $summary = $this->importService->importFromCsv(
                        filePath: $uploadedFile['tmp_name'],
                        register: $register,
                        schema: $schema,
                        chunkSize: $chunkSize,
                        validation: $validation,
                        events: $events,
                        rbac: $rbac,
                        multi: $multi,
                        publish: $publish,
                        currentUser: $this->userSession->getUser()
                    );
                    break;
                case 'configuration':
                default:
                    // Initialize the uploaded files array.
                    $uploadedFiles = [$uploadedFile];
                    // Get the uploaded JSON data.
                    $jsonData = $this->configurationService->getUploadedJson(data: $this->request->getParams(), uploadedFiles: $uploadedFiles);
                    if ($jsonData instanceof JSONResponse) {
                        return $jsonData;
                    }

                    // Import the data and get the result.
                    // importFromJson requires a Configuration entity as second parameter.
                    // For now, pass null and let the service handle it (will throw if required).
                    $configuration = null;
                    // TODO: Get or create Configuration entity if needed
                    $result = $this->configurationService->importFromJson(
                        data: $jsonData,
                        configuration: $configuration,
                        owner: $this->request->getParam('owner'),
                        appId: $this->request->getParam('appId'),
                        version: $this->request->getParam('version'),
                        force: $force
                    );
                    // Build a summary for objects if present in sheet-based format.
                    $summary = [
                        'configuration' => [
                            'created'   => [],
                            'updated'   => [],
                            'unchanged' => [],
                            'errors'    => [],
                        ],
                    ];
                    if (isset($result['objects']) && is_array($result['objects'])) {
                        foreach ($result['objects'] as $object) {
                            // For now, treat all as 'created' (improve if possible).
                            $summary['configuration']['created'][] = [
                                'id'       => $object->getId(),
                                'uuid'     => $object->getUuid(),
                                'sheet'    => 'configuration',
                                'register' => [
                                    'id'   => $register->getId(),
                                    'name' => $register->getTitle(),
                                ],
                                'schema'   => null,
                                // Schema info not available in configuration import.
                            ];
                        }
                    }

                    // If no registers defined in oas, update the register that was given through query with created schema's.
                    if (empty($result['registers']) === true) {
                        // Get created schema ids.
                        $createdSchemas = [];
                        foreach ($result['schemas'] as $schema) {
                            $createdSchemas[] = $schema->getId();
                        }

                        // Get existing schemas.
                        $register        = $this->registerService->find($id);
                        $registerSchemas = $register->getSchemas();

                        // Merge new with existing.
                        $mergedSchemaArray = array_merge($registerSchemas, $createdSchemas);
                        $mergedSchemaArray = array_keys(array_flip($mergedSchemaArray));

                        $register->setSchemas($mergedSchemaArray);
                        // Update through service instead of direct mapper call.
                        $this->registerService->updateFromArray(id: $id, data: $register->jsonSerialize());
                    }
                    break;
            }//end switch

            return new JSONResponse(
                    data: [
                        'message' => 'Import successful',
                        'summary' => $summary,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try

    }//end import()


    /**
     * Get statistics for a specific register
     *
     * @param int $id The register ID
     *
     * @return JSONResponse The register statistics
     *
     * @throws DoesNotExistException When the register is not found
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function stats(int $id): JSONResponse
    {
        try {
            // Get the register with stats.
            $register = $this->registerService->find($id);

            // Calculate statistics for this register.
            // Note: calculateStats method doesn't exist, using getStats or similar if available.
            // For now, return basic register info.
            $stats = [
                'register' => $register->jsonSerialize(),
                'message'  => 'Stats calculation not yet implemented',
            ];

            return new JSONResponse(data: $stats);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end stats()


    /**
     * Parse boolean parameter from request with enhanced support for string values
     *
     * Supports both actual booleans and string representations:
     * - true, "true", "1", "on", "yes" -> true
     * - false, "false", "0", "off", "no", "" -> false
     *
     * @param string $paramName The parameter name to retrieve
     * @param bool   $default   Default value if parameter is not present
     *
     * @return bool The parsed boolean value
     */
    private function parseBooleanParam(string $paramName, bool $default=false): bool
    {
        $value = $this->request->getParam($paramName, $default);

        // If already boolean, return as-is.
        if (is_bool($value)) {
            return $value;
        }

        // Handle string values.
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', '1', 'on', 'yes'], true);
        }

        // Handle numeric values.
        if (is_numeric($value)) {
            return (bool) $value;
        }

        // Fallback to default.
        return $default;

    }//end parseBooleanParam()


}//end class
