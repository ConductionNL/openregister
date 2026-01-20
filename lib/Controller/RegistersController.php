<?php

/**
 * RegistersController handles REST API endpoints for register management
 *
 * Controller for managing register operations in the OpenRegister app.
 * Provides endpoints for CRUD operations, import/export, GitHub publishing,
 * and OpenAPI specification generation.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
use Exception;
use RuntimeException;
use DateTime;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
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

/**
 * RegistersController handles REST API endpoints for register management
 *
 * Provides REST API endpoints for managing registers including CRUD operations,
 * import/export functionality, GitHub publishing, and OpenAPI specification generation.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
     * @var GitHubHandler
     */
    private readonly GitHubHandler $githubService;

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
     * Constructor
     *
     * Initializes controller with required dependencies for register operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string               $appName              Application name
     * @param IRequest             $request              HTTP request object
     * @param RegisterService      $registerService      Register service for business logic
     * @param ObjectEntityMapper   $objectEntityMapper   Object entity mapper for database operations
     * @param UploadService        $uploadService        Upload service for file uploads
     * @param LoggerInterface      $logger               Logger for error tracking
     * @param IUserSession         $userSession          User session service
     * @param ConfigurationService $configurationService Configuration service for import/export
     * @param AuditTrailMapper     $auditTrailMapper     Audit trail mapper for log statistics
     * @param ExportService        $exportService        Export service for data exports
     * @param ImportService        $importService        Import service for data imports
     * @param SchemaMapper         $schemaMapper         Schema mapper for schema operations
     * @param RegisterMapper       $registerMapper       Register mapper for database operations
     * @param GitHubHandler        $githubService        GitHub service for publishing
     * @param IAppManager          $appManager           App manager for app version
     * @param OasService           $oasService           OAS service for OpenAPI generation
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
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
        GitHubHandler $githubService,
        IAppManager $appManager,
        OasService $oasService
    ) {
        $this->logger->debug('RegistersController constructor started.');
        parent::__construct(appName: $appName, request: $request);
        $this->logger->debug('Parent constructor called.');
        $this->configurationService = $configurationService;
        $this->logger->debug('ConfigurationService assigned.');
        $this->auditTrailMapper = $auditTrailMapper;
        $this->exportService    = $exportService;
        $this->importService    = $importService;
        $this->schemaMapper     = $schemaMapper;
        $this->registerMapper   = $registerMapper;
        $this->githubService    = $githubService;
        $this->appManager       = $appManager;
        $this->oasService       = $oasService;
        $this->logger->debug('RegistersController constructor completed.');
    }//end __construct()

    /**
     * Retrieves a list of all registers
     *
     * This method returns a JSON response containing an array of all registers in the system.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The JSON response containing the list of registers
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)      Complex request parameter handling for flexible API
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function index(): JSONResponse
    {
        // Get request parameters for filtering and searching.
        $params = $this->request->getParams();

        // Extract pagination and search parameters.
        $limit = null;
        if (isset($params['_limit']) === true) {
            $limit = (int) $params['_limit'];
        }

        $offset = null;
        if (isset($params['_offset']) === true) {
            $offset = (int) $params['_offset'];
        }

        $page = null;
        if (isset($params['_page']) === true) {
            $page = (int) $params['_page'];
        }

        // Note: search parameter not currently used in this endpoint.
        $extend = $params['_extend'] ?? [];
        if (is_string($extend) === true) {
            $extend = [$extend];
        }

        // Convert page to offset if provided.
        if ($page !== null && $limit !== null) {
            $offset = ($page - 1) * $limit;
        }

        // Extract filters.
        $filters = $params['filters'] ?? [];

        $registers    = $this->registerService->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            searchConditions: [],
            searchParams: []
        );
        $registersArr = array_map(fn($register) => $register->jsonSerialize(), $registers);

        // If 'schemas' is requested in _extend, expand schema IDs to full schema objects.
        if (in_array('schemas', $extend, true) === true) {
            foreach ($registersArr as &$register) {
                if (($register['schemas'] ?? null) !== null && is_array($register['schemas']) === true) {
                    $expandedSchemas = [];
                    foreach ($register['schemas'] as $schemaId) {
                        try {
                            $schema            = $this->schemaMapper->find($schemaId);
                            $expandedSchemas[] = $schema->jsonSerialize();
                        } catch (DoesNotExistException $e) {
                            // Schema not found, skip it.
                            $ctx = ['schemaId' => $schemaId];
                            $this->logger->warning(message: 'Schema not found for expansion', context: $ctx);
                        }
                    }

                    $register['schemas'] = $expandedSchemas;

                    // If schemas were expanded and stats are requested, add schema-level stats
                    if (in_array('@self.stats', $extend, true) === true && empty($expandedSchemas) === false) {
                        // Get object counts per schema using optimized query
                        $schemaCounts = $this->registerService->getSchemaObjectCounts(
                            registerId: $register['id'],
                            schemas: $expandedSchemas
                        );

                        $this->logger->debug('RegistersController: Schema counts for register '.$register['id'].': '.json_encode($schemaCounts));

                        // Add stats to each expanded schema
                        foreach ($register['schemas'] as &$schema) {
                            $schemaId = $schema['id'] ?? null;
                            $this->logger->debug("RegistersController: Processing schema {$schemaId}, has count: ".(isset($schemaCounts[$schemaId]) ? 'yes' : 'no'));
                            if ($schemaId !== null && isset($schemaCounts[$schemaId]) === true) {
                                $schema['stats'] = [
                                    'objects' => $schemaCounts[$schemaId],
                                ];
                                $this->logger->debug("RegistersController: Set stats for schema {$schemaId}: ".json_encode($schema['stats']));
                            } else {
                                // No objects found for this schema
                                $schema['stats'] = [
                                    'objects' => ['total' => 0],
                                ];
                                $this->logger->debug("RegistersController: No count for schema {$schemaId}, set to 0");
                            }
                        }
                    }
                }
            }
        }

        // If '@self.stats' is requested, attach statistics to each register.
        if (in_array('@self.stats', $extend, true) === true) {
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
     * @return JSONResponse JSON response with register details
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show($id): JSONResponse
    {
        $extend = $this->request->getParam(key: '_extend', default: []);
        if (is_string($extend) === true) {
            $extend = [$extend];
        }

        $register    = $this->registerService->find(id: $id, _extend: []);
        $registerArr = $register->jsonSerialize();
        // If '@self.stats' is requested, attach statistics to the register.
        if (in_array('@self.stats', $extend, true) === true) {
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.StaticAccess) DatabaseConstraintException factory method is standard pattern
     *
     * @return JSONResponse JSON response with created register or error
     *
     * @psalm-return JSONResponse<201, Register,
     *     array<never, never>>|JSONResponse<int, array{error: string},
     *     array<never, never>>
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
        if (($data['id'] ?? null) !== null) {
            unset($data['id']);
        }

        try {
            // Create a new register from the data.
            return new JSONResponse(data: $this->registerService->createFromArray($data), statusCode: 201);
        } catch (DBException $e) {
            // Handle database constraint violations with user-friendly messages.
            $constraintException = DatabaseConstraintException::fromDatabaseException(
                dbException: $e,
                entityType: 'register'
            );
            return new JSONResponse(
                data: ['error' => $constraintException->getMessage()],
                statusCode: $constraintException->getHttpStatusCode()
            );
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions.
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: $e->getHttpStatusCode()
            );
        }
    }//end create()

    /**
     * Updates an existing register
     *
     * This method updates an existing register based on its ID.
     *
     * @param int $id The ID of the register to update
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.StaticAccess) DatabaseConstraintException factory method is standard pattern
     *
     * @return JSONResponse JSON response with updated register or error
     *
     * @psalm-return JSONResponse<200, Register,
     *     array<never, never>>|JSONResponse<int, array{error: string},
     *     array<never, never>>
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
            $constraintException = DatabaseConstraintException::fromDatabaseException(
                dbException: $e,
                entityType: 'register'
            );
            return new JSONResponse(
                data: ['error' => $constraintException->getMessage()],
                statusCode: $constraintException->getHttpStatusCode()
            );
        } catch (DatabaseConstraintException $e) {
            // Handle our custom database constraint exceptions.
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: $e->getHttpStatusCode()
            );
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with patched register or error
     *
     * @psalm-return JSONResponse<200, Register,
     *     array<never, never>>|JSONResponse<int, array{error: string},
     *     array<never, never>>
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response on success or error
     *
     * @psalm-return JSONResponse<int, array{error?: string},
     *     array<never, never>>
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            // Find the register by ID and delete it.
            $register = $this->registerService->find($id);
            $this->registerService->delete($register);

            // Return an empty response.
            return new JSONResponse(data: []);
        } catch (DoesNotExistException $e) {
            // Return 404 Not Found when register doesn't exist or is not accessible.
            return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
        } catch (\OCA\OpenRegister\Exception\ValidationException $e) {
            // Return 409 Conflict for cascade protection (objects still attached).
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 409);
        } catch (Exception $e) {
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with schemas or error
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
        } catch (Exception $e) {
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with objects
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
     * @return DataDownloadResponse|JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function export(int $id): JSONResponse|DataDownloadResponse
    {
        try {
            // Get export format from query parameter.
            $format          = $this->request->getParam(key: 'format', default: 'configuration');
            $includeObjParam = $this->request->getParam(key: 'includeObjects', default: false);
            $includeObjects  = filter_var($includeObjParam, FILTER_VALIDATE_BOOLEAN);
            $register        = $this->registerService->find($id);

            switch ($format) {
                case 'excel':
                    $spreadsheet = $this->exportService->exportToExcel(
                        register: $register,
                        schema: null,
                        filters: [],
                        currentUser: $this->userSession->getUser()
                    );
                    $writer      = new Xlsx($spreadsheet);
                    $slug        = $register->getSlug() ?? 'register';
                    $date        = (new DateTime())->format('Y-m-d_His');
                    $filename    = sprintf('%s_%s.xlsx', $slug, $date);
                    ob_start();
                    $writer->save('php://output');
                    $content = ob_get_clean();
                    $mime    = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    return new DataDownloadResponse($content, $filename, $mime);
                case 'csv':
                    // CSV exports require a specific schema.
                    $schemaId = $this->request->getParam('schema');

                    if ($schemaId === null || $schemaId === '') {
                        // If no schema specified, return error (CSV cannot handle multiple schemas).
                        $errMsg = 'CSV export requires a specific schema to be selected';
                        return new JSONResponse(data: ['error' => $errMsg], statusCode: 400);
                    }

                    $schema   = $this->schemaMapper->find($schemaId);
                    $csv      = $this->exportService->exportToCsv(
                        register: $register,
                        schema: $schema,
                        filters: [],
                        currentUser: $this->userSession->getUser()
                    );
                    $filename = sprintf(
                        '%s_%s_%s.csv',
                        $register->getSlug() ?? 'register',
                        $schema->getSlug() ?? 'schema',
                        (new DateTime())->format('Y-m-d_His')
                    );
                    return new DataDownloadResponse($csv, $filename, 'text/csv');
                case 'configuration':
                default:
                    $exportData  = $this->configurationService->exportConfig(
                        input: $register,
                        includeObjects: $includeObjects
                    );
                    $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    if ($jsonContent === false) {
                        throw new Exception('Failed to encode register data to JSON');
                    }

                    $slug     = $register->getSlug() ?? 'register';
                    $date     = (new DateTime())->format('Y-m-d_His');
                    $filename = sprintf('%s_%s.json', $slug, $date);
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with publish result or error
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)       GitHub publishing requires many conditional checks
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
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

            if (empty($owner) === true || empty($repo) === true) {
                return new JSONResponse(data: ['error' => 'Owner and repo parameters are required'], statusCode: 400);
            }

            // Strip leading slash from path.
            $path = ltrim($path, '/');

            // If path is empty, use a default filename based on register slug.
            if (empty($path) === true) {
                $slug = $register->getSlug() ?? 'register';
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
            } catch (Exception $e) {
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
            } catch (Exception $e) {
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
            if (($defaultBranch !== null && $defaultBranch !== '') === true && $branch !== $defaultBranch) {
                $searchNote = 'GitHub Code Search primarily indexes the default branch.';
                $delayNote  = 'This may not appear in search results immediately.';
                $branchNote = "Note: Published to branch '{$branch}' (default is '{$defaultBranch}').";
                $message   .= ". {$branchNote} {$searchNote} {$delayNote}";
            }

            if (($defaultBranch === null || $defaultBranch === '') === true || $branch === $defaultBranch) {
                $message .= ". Note: GitHub Code Search may take a few minutes to index new files.";
            }

            // Determine indexing note.
            $indexingNote = "File published successfully. GitHub Code Search indexing may take a few minutes.";
            if (($defaultBranch !== null) === true && $branch !== $defaultBranch) {
                $indexingNote = "Published to non-default branch. For discovery, publish to '{$defaultBranch}' branch.";
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
                    'indexing_note'  => $indexingNote,
                ],
                statusCode: 200
            );
        } catch (DoesNotExistException $e) {
            $this->logger->error('Register not found for publishing', ['register_id' => $id]);
            return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
        } catch (Exception $e) {
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
     * @return JSONResponse JSON response with import result or error
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Force flag to override version checks
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
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
                if (in_array($extension, ['xlsx', 'xls']) === true) {
                    $type = 'excel';
                } else if ($extension === 'csv') {
                    $type = 'csv';
                }

                if (in_array($extension, ['xlsx', 'xls', 'csv']) === false) {
                    $type = 'configuration';
                }
            }

            // Get import options for all types - support both boolean and string values.
            $includeObjects = $this->parseBooleanParam(paramName: 'includeObjects', default: false);
            $validation     = $this->parseBooleanParam(paramName: 'validation', default: false);
            $events         = $this->parseBooleanParam(paramName: 'events', default: false);
            $publish        = $this->parseBooleanParam(paramName: 'publish', default: false);
            $enrich         = $this->parseBooleanParam(paramName: 'enrich', default: true);

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
                    $rbac  = $this->parseBooleanParam(paramName: 'rbac', default: true);
                    $multi = $this->parseBooleanParam(paramName: 'multi', default: true);
                    // Use optimized default.
                    $summary = $this->importService->importFromExcel(
                        filePath: $uploadedFile['tmp_name'],
                        register: $register,
                        schema: null,
                        validation: $validation,
                        events: $events,
                        _rbac: $rbac,
                        _multitenancy: $multi,
                        publish: $publish,
                        currentUser: $this->userSession->getUser(),
                        enrich: $enrich
                    );
                    break;
                case 'csv':
                    // Import from CSV and get summary (now returns sheet-based format).
                    // For CSV, schema MUST be specified in the request.
                    $schemaId = $this->request->getParam('schema');

                    if ($schemaId === null || $schemaId === '') {
                        return new JSONResponse(
                            data: ['error' => 'Schema parameter is required for CSV imports.'],
                            statusCode: 400
                        );
                    }

                    $schema = $this->schemaMapper->find($schemaId);

                    // Get additional performance parameters with enhanced boolean parsing.
                    $rbac  = $this->parseBooleanParam(paramName: 'rbac', default: true);
                    $multi = $this->parseBooleanParam(paramName: 'multi', default: true);
                    // Use optimized default.
                    $summary = $this->importService->importFromCsv(
                        filePath: $uploadedFile['tmp_name'],
                        register: $register,
                        schema: $schema,
                        validation: $validation,
                        events: $events,
                        _rbac: $rbac,
                        _multitenancy: $multi,
                        publish: $publish,
                        currentUser: $this->userSession->getUser(),
                        enrich: $enrich
                    );
                    break;
                case 'configuration':
                default:
                    // Initialize the uploaded files array.
                    $uploadedFiles = [$uploadedFile];
                    // Get the uploaded JSON data.
                    $jsonData = $this->configurationService->getUploadedJson(
                        data: $this->request->getParams(),
                        uploadedFiles: $uploadedFiles
                    );
                    if ($jsonData instanceof JSONResponse) {
                        return $jsonData;
                    }

                    // Import the data and get the result.
                    // ImportFromJson requires a Configuration entity as second parameter.
                    // For now, pass null and let the service handle it (will throw if required).
                    $configuration = null;
                    // TODO: Get or create Configuration entity if needed.
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
                    if (($result['objects'] ?? null) !== null && is_array($result['objects']) === true) {
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

                    // If no registers in oas, update the register given through query with created schemas.
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
                        $mergedSchemaArray = array_merge($registerSchemas ?? [], $createdSchemas);
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
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try
    }//end import()

    /**
     * Get statistics for a specific register
     *
     * @param int $id The register ID
     *
     * @throws DoesNotExistException When the register is not found
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The JSON response containing register statistics
     *
     * @psalm-return JSONResponse<
     *     200|404|500,
     *     array{
     *         error?: string,
     *         register?: array{
     *             id: int,
     *             uuid: null|string,
     *             slug: null|string,
     *             title: null|string,
     *             version: null|string,
     *             description: null|string,
     *             schemas: array<int|string>,
     *             source: null|string,
     *             tablePrefix: null|string,
     *             folder: null|string,
     *             updated: null|string,
     *             created: null|string,
     *             owner: null|string,
     *             application: null|string,
     *             organisation: null|string,
     *             authorization: array|null,
     *             groups: array<string, list<string>>,
     *             configuration: array|null,
     *             quota: array{
     *                 storage: null,
     *                 bandwidth: null,
     *                 requests: null,
     *                 users: null,
     *                 groups: null
     *             },
     *             usage: array{
     *                 storage: 0,
     *                 bandwidth: 0,
     *                 requests: 0,
     *                 users: 0,
     *                 groups: int<0, max>
     *             },
     *             deleted: null|string,
     *             published: null|string,
     *             depublished: null|string
     *         },
     *         message?: 'Stats calculation not yet implemented'
     *     },
     *     array<never, never>
     * >
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
        } catch (Exception $e) {
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
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Default value is needed for parameter parsing
     */
    private function parseBooleanParam(string $paramName, bool $default=false): bool
    {
        $value = $this->request->getParam(key: $paramName, default: $default);

        // If already boolean, return as-is.
        if (is_bool($value) === true) {
            return $value;
        }

        // Handle string values.
        if (is_string($value) === true) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', '1', 'on', 'yes'], true);
        }

        // Handle numeric values.
        if (is_numeric($value) === true) {
            return (bool) $value;
        }

        // Fallback to default.
        return $default;
    }//end parseBooleanParam()

    /**
     * Publish a register
     *
     * This method publishes a register by setting its publication date
     * to now or a specified date.
     *
     * @param int $id The ID of the register to publish
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The JSON response containing the published register
     *
     * @psalm-return JSONResponse<
     *     200|400|404,
     *     array{
     *         error?: string,
     *         id?: int,
     *         uuid?: null|string,
     *         slug?: null|string,
     *         title?: null|string,
     *         version?: null|string,
     *         description?: null|string,
     *         schemas?: array<int|string>,
     *         source?: null|string,
     *         tablePrefix?: null|string,
     *         folder?: null|string,
     *         updated?: null|string,
     *         created?: null|string,
     *         owner?: null|string,
     *         application?: null|string,
     *         organisation?: null|string,
     *         authorization?: array|null,
     *         groups?: array<string, list<string>>,
     *         configuration?: array|null,
     *         quota?: array{
     *             storage: null,
     *             bandwidth: null,
     *             requests: null,
     *             users: null,
     *             groups: null
     *         },
     *         usage?: array{
     *             storage: 0,
     *             bandwidth: 0,
     *             requests: 0,
     *             users: 0,
     *             groups: int<0, max>
     *         },
     *         deleted?: null|string,
     *         published?: null|string,
     *         depublished?: null|string
     *     },
     *     array<never, never>
     * >
     */
    public function publish(int $id): JSONResponse
    {
        try {
            // Get the publication date from request if provided, otherwise use now.
            $date = new DateTime();
            if ($this->request->getParam('date') !== null) {
                $date = new DateTime($this->request->getParam('date'));
            }

            // Get the register.
            $register = $this->registerMapper->find($id);

            // Set published date and clear depublished date if set.
            $register->setPublished($date);
            $register->setDepublished(null);

            // Update the register.
            $updatedRegister = $this->registerMapper->update($register);

            $this->logger->info(
                'Register published',
                [
                    'register_id'    => $id,
                    'published_date' => $date->format('Y-m-d H:i:s'),
                ]
            );

            return new JSONResponse($updatedRegister->jsonSerialize());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Register not found'], 404);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to publish register',
                [
                    'register_id' => $id,
                    'error'       => $e->getMessage(),
                ]
            );
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end publish()

    /**
     * Depublish a register
     *
     * This method depublishes a register by setting its depublication date to now or a specified date.
     *
     * @param int $id The ID of the register to depublish
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The JSON response containing the depublished register
     *
     * @psalm-return JSONResponse<
     *     200|400|404,
     *     array{
     *         error?: string,
     *         id?: int,
     *         uuid?: null|string,
     *         slug?: null|string,
     *         title?: null|string,
     *         version?: null|string,
     *         description?: null|string,
     *         schemas?: array<int|string>,
     *         source?: null|string,
     *         tablePrefix?: null|string,
     *         folder?: null|string,
     *         updated?: null|string,
     *         created?: null|string,
     *         owner?: null|string,
     *         application?: null|string,
     *         organisation?: null|string,
     *         authorization?: array|null,
     *         groups?: array<string, list<string>>,
     *         configuration?: array|null,
     *         quota?: array{
     *             storage: null,
     *             bandwidth: null,
     *             requests: null,
     *             users: null,
     *             groups: null
     *         },
     *         usage?: array{
     *             storage: 0,
     *             bandwidth: 0,
     *             requests: 0,
     *             users: 0,
     *             groups: int<0, max>
     *         },
     *         deleted?: null|string,
     *         published?: null|string,
     *         depublished?: null|string
     *     },
     *     array<never, never>
     * >
     */
    public function depublish(int $id): JSONResponse
    {
        try {
            // Get the depublication date from request if provided, otherwise use now.
            $date = new DateTime();
            if ($this->request->getParam('date') !== null) {
                $date = new DateTime($this->request->getParam('date'));
            }

            // Get the register.
            $register = $this->registerMapper->find($id);

            // Set depublished date.
            $register->setDepublished($date);

            // Update the register.
            $updatedRegister = $this->registerMapper->update($register);

            $this->logger->info(
                'Register depublished',
                [
                    'register_id'      => $id,
                    'depublished_date' => $date->format('Y-m-d H:i:s'),
                ]
            );

            return new JSONResponse($updatedRegister->jsonSerialize());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Register not found'], 404);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to depublish register',
                [
                    'register_id' => $id,
                    'error'       => $e->getMessage(),
                ]
            );
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end depublish()
}//end class
