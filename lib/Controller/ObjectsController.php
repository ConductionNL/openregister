<?php
declare(strict_types=1);
/*
 * ObjectsController
 *
 * Controller for managing object operations in the OpenRegister app.
 * Provides CRUD functionality for objects within registers and schemas.
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookInterceptorService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCP\AppFramework\Http\DataDownloadResponse;

/**
 */
/**
 * @psalm-suppress UnusedClass
 */

class ObjectsController extends Controller
{

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
     * Constructor for the ObjectsController
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param IAppConfig         $config             The app configuration object
     * @param IAppManager        $appManager         The app manager
     * @param ContainerInterface $container          The DI container
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param RegisterMapper     $registerMapper     The register mapper
     * @param SchemaMapper       $schemaMapper       The schema mapper
     * @param AuditTrailMapper   $auditTrailMapper   The audit trail mapper
     * @param ObjectService      $objectService      The object service
     * @param IUserSession       $userSession        The user session
     * @param IGroupManager      $groupManager       The group manager
     * @param ExportService      $exportService      The export service
     * @param ImportService      $importService      The import service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        ExportService $exportService,
        ImportService $importService,
        private readonly ?WebhookInterceptorService $webhookInterceptor=null,
        private readonly ?LoggerInterface $logger=null
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->exportService = $exportService;
        $this->importService = $importService;

    }//end __construct()


    /**
     * Check if the current user is in the admin group.
     *
     * This helper method determines if the current logged-in user belongs to the 'admin' group,
     * which allows bypassing RBAC and multitenancy restrictions.
     *
     * @return bool True if user is admin, false otherwise
     *
     * @psalm-return   bool
     * @phpstan-return bool
     */
    private function isCurrentUserAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $userGroups = $this->groupManager->getUserGroupIds($user);
        return in_array('admin', $userGroups);

    }//end isCurrentUserAdmin()


    /**
     * Private helper method to handle pagination of results.
     *
     * This method paginates the given results array based on the provided total, limit, offset, and page parameters.
     * It calculates the number of pages, sets the appropriate offset and page values, and returns the paginated results
     * along with metadata such as total items, current page, total pages, limit, and offset.
     *
     * @param array    $results The array of objects to paginate.
     * @param int|null $total   The total number of items (before pagination). Defaults to 0.
     * @param int|null $limit   The number of items per page. Defaults to 20.
     * @param int|null $offset  The offset of items. Defaults to 0.
     * @param int|null $page    The current page number. Defaults to 1.
     *
     * @return array The paginated results with metadata.
     *
     * @phpstan-param  array<int, mixed> $results
     * @phpstan-return array<string, mixed>
     * @psalm-param    array<int, mixed> $results
     * @psalm-return   array<string, mixed>
     */
    private function paginate(array $results, ?int $total=0, ?int $limit=20, ?int $offset=0, ?int $page=1): array
    {
        // Ensure we have valid values (never null).
        $total = max(0, $total ?? 0);
        $limit = max(1, $limit ?? 20);
        // Minimum limit of 1.
        $offset = max(0, $offset ?? 0);
        $page   = max(1, $page ?? 1);
        // Minimum page of 1        // Calculate the number of pages (minimum 1 page).
        $pages = max(1, ceil($total / $limit));

        // If we have a page but no offset, calculate the offset.
        if ($offset === 0) {
            $offset = ($page - 1) * $limit;
        }

        // If we have an offset but page is 1, calculate the page.
        if ($page === 1 && $offset > 0) {
            $page = floor($offset / $limit) + 1;
        }

        // If total is smaller than the number of results, set total to the number of results.
        // @todo: this is a hack to ensure the pagination is correct when the total is not known. That sugjest that the underlaying count service has a problem that needs to be fixed instead.
        if ($total < count($results)) {
            $total = count($results);
            $pages = max(1, ceil($total / $limit));
        }

        // Initialize the results array with pagination information.
        $paginatedResults = [
            'results' => $results,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset,
        ];

        // Add next/prev page URLs if applicable.
        $currentUrl = $_SERVER['REQUEST_URI'];

        // Add next page link if there are more pages.
        if ($page < $pages) {
            $nextPage = $page + 1;
            $nextUrl  = preg_replace('/([?&])page=\d+/', '$1page='.$nextPage, $currentUrl);
            if (strpos($nextUrl, 'page=') === false) {
                if (strpos($nextUrl, '?') === false) {
                    $nextUrl .= '?page='.$nextPage;
                } else {
                    $nextUrl .= '&page='.$nextPage;
                }
            }

            $paginatedResults['next'] = $nextUrl;
        }

        // Add previous page link if not on first page.
        if ($page > 1) {
            $prevPage = $page - 1;
            $prevUrl  = preg_replace('/([?&])page=\d+/', '$1page='.$prevPage, $currentUrl);
            if (strpos($prevUrl, 'page=') === false) {
                if (strpos($prevUrl, '?') === false) {
                    $prevUrl .= '?page='.$prevPage;
                } else {
                    $prevUrl .= '&page='.$prevPage;
                }
            }

            $paginatedResults['prev'] = $prevUrl;
        }

        return $paginatedResults;

    }//end paginate()


    /**
     * Helper method to get configuration array from the current request (LEGACY)
     *
     * @param string|null $register Optional register identifier
     * @param string|null $schema   Optional schema identifier
     * @param array|null  $ids      Optional array of specific IDs to filter
     *
     * @return array Configuration array containing:
     *
     * @deprecated Use buildSearchQuery() instead for faceting-enabled endpoints
     *               - limit: (int) Maximum number of items per page
     *               - offset: (int|null) Number of items to skip
     *               - page: (int|null) Current page number
     *               - filters: (array) Filter parameters
     *               - sort: (array) Sort parameters
     *               - search: (string|null) Search term
     *               - extend: (array|null) Properties to extend
     *               - fields: (array|null) Fields to include
     *               - unset: (array|null) Fields to exclude
     *               - register: (string|null) Register identifier
     *               - schema: (string|null) Schema identifier
     *               - ids: (array|null) Specific IDs to filter
     */
    private function getConfig(?string $register=null, ?string $schema=null, ?array $ids=null): array
    {
        $params = $this->request->getParams();

        unset($params['id']);
        unset($params['_route']);

        // Extract and normalize parameters.
        $limit = (int) ($params['limit'] ?? $params['_limit'] ?? 20);
        if (($params['offset'] ?? null) !== null) {
            $offset = (int) $params['offset'];
        } else if (($params['_offset'] ?? null) !== null) {
            $offset = (int) $params['_offset'];
        } else {
            $offset = null;
        }

        if (($params['page'] ?? null) !== null) {
            $page = (int) $params['page'];
        } else if (($params['_page'] ?? null) !== null) {
            $page = (int) $params['_page'];
        } else {
            $page = null;
        }

        // If we have a page but no offset, calculate the offset.
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        return [
            'limit'   => $limit,
            'offset'  => $offset,
            'page'    => $page,
            'filters' => $params,
            'sort'    => ($params['order'] ?? $params['_order'] ?? []),
            'search'  => ($params['_search'] ?? null),
            'extend'  => ($params['extend'] ?? $params['_extend'] ?? null),
            'fields'  => ($params['fields'] ?? $params['_fields'] ?? null),
            'unset'   => ($params['unset'] ?? $params['_unset'] ?? null),
            'ids'     => $ids,
        ];

    }//end getConfig()


    /**
     * Helper method to resolve register and schema slugs to numeric IDs
     *
     * This ensures consistent slug-to-ID conversion across all controller methods
     * and prevents the discrepancy between slug-based and ID-based API calls.
     *
     * @param string        $register      Register slug or ID
     * @param string        $schema        Schema slug or ID
     * @param ObjectService $objectService Object service instance
     *
     * @return array Array with resolved register and schema IDs: ['register' => int, 'schema' => int]
     *
     * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException
     * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException
     *
     * @psalm-return   array{register: int, schema: int}
     * @phpstan-return array{register: int, schema: int}
     */
    private function resolveRegisterSchemaIds(string $register, string $schema, ObjectService $objectService): array
    {
        try {
            // STEP 1: Initial resolution - convert slugs/IDs to numeric IDs.
            $objectService->setRegister(register: $register);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // If register not found, throw custom exception.
            throw new \OCA\OpenRegister\Exception\RegisterNotFoundException(registerSlugOrId: $register, code: 404, previous: $e);
        }

        try {
            $objectService->setSchema(schema: $schema);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // If schema not found, throw custom exception.
            throw new \OCA\OpenRegister\Exception\SchemaNotFoundException(schemaSlugOrId: $schema, code: 404, previous: $e);
        }

        // STEP 2: Get resolved numeric IDs.
        $resolvedRegisterId = $objectService->getRegister();
        $resolvedSchemaId   = $objectService->getSchema();
        // STEP 3: Reset ObjectService with resolved numeric IDs.
        // This ensures the entire pipeline works with IDs consistently.
        $objectService->setRegister(register: (string) $resolvedRegisterId)->setSchema(schema: (string) $resolvedSchemaId);
        return [
            'register' => $resolvedRegisterId,
            'schema'   => $resolvedSchemaId,
        ];

    }//end resolveRegisterSchemaIds()


    /**
     * Retrieves a list of all objects for a specific register and schema
     *
     * This method returns a paginated list of objects that match the specified register and schema.
     * It supports filtering, sorting, pagination, faceting, and facetable field discovery through query parameters.
     *
     * Supported parameters:
     * - Standard filters: Any object field (e.g., name, status, etc.)
     * - Metadata filters: register, schema, uuid, created, updated, published, etc.
     * - Pagination: _limit, _offset, _page
     * - Search: _search
     * - Rendering: _extend, _fields, _filter/_unset
     * - Faceting: _facets (facet configuration), _facetable (facetable field discovery)
     * - Aggregations: _aggregations (enable aggregations in response - SOLR only)
     * - Debug: _debug (enable debug information in response - SOLR only)
     * - Source: _source (force search source: 'database' or 'index'/'solr')
     * - Sorting: _order
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the list of objects with optional facets and facetable fields
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function index(string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        try {
            // Resolve slugs to numeric IDs consistently (validation only).
            $resolved = $this->resolveRegisterSchemaIds(register: $register, schema: $schema, objectService: $objectService);
        } catch (\OCA\OpenRegister\Exception\RegisterNotFoundException | \OCA\OpenRegister\Exception\SchemaNotFoundException $e) {
            // Return 404 with clear error message if register or schema not found.
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
        }

        // Build search query with resolved numeric IDs.
        $query = $objectService->buildSearchQuery(requestParams: $this->request->getParams(), register: $resolved['register'], schema: $resolved['schema']);

        // Extract filtering parameters from request.
        $params    = $this->request->getParams();
        $rbac      = filter_var($params['rbac'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $multi     = filter_var($params['multi'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $published = filter_var($params['_published'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $deleted   = filter_var($params['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // **INTELLIGENT SOURCE SELECTION**: ObjectService automatically chooses optimal source.
        $result = $objectService->searchObjectsPaginated(query: $query, rbac: $rbac, multi: $multi, published: $published, deleted: $deleted);

        // **SUB-SECOND OPTIMIZATION**: Enable response compression for large payloads.
        $response = new JSONResponse(data: $result);

        // Enable gzip compression for responses > 1KB.
        if (($result['results'] ?? null) !== null && (count($result['results']) > 10) === true) {
            $response->addHeader('Content-Encoding', 'gzip');
            $response->addHeader('Vary', 'Accept-Encoding');
        }

        return $response;

    }//end index()


    /**
     * Retrieves a list of all objects across all registers and schemas
     *
     * This method returns a paginated list of objects that the current user has access to,
     * regardless of register or schema boundaries. It supports filtering, sorting, pagination,
     * faceting, and facetable field discovery through query parameters.
     *
     * This endpoint respects both RBAC (Role-Based Access Control) and multitenancy settings:
     * - Regular users see only objects they have read permission for in their organization
     * - Admin users can see all objects system-wide (overrides RBAC and multitenancy)
     *
     * Supported parameters:
     * - Standard filters: Any object field (e.g., name, status, etc.)
     * - Metadata filters: register, schema, uuid, created, updated, published, etc.
     * - Pagination: _limit, _offset, _page
     * - Search: _search
     * - Rendering: _extend, _fields, _filter/_unset
     * - Faceting: _facets (facet configuration), _facetable (facetable field discovery)
     * - Aggregations: _aggregations (enable aggregations in response - SOLR only)
     * - Debug: _debug (enable debug information in response - SOLR only)
     * - Source: _source (force search source: 'database' or 'index'/'solr')
     * - Sorting: _order
     *
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the list of objects with optional facets and facetable fields
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function objects(ObjectService $objectService): JSONResponse
    {
        // Build search query without register/schema constraints.
        $query = $objectService->buildSearchQuery($this->request->getParams());

        // **INTELLIGENT SOURCE SELECTION**: ObjectService automatically chooses optimal source.
        $result = $objectService->searchObjectsPaginated($query);

        return new JSONResponse(data: $result);

    }//end objects()


    /**
     * Shows a specific object from a register and schema
     *
     * Retrieves and returns a single object from the specified register and schema, statusCode: * with support for field filtering and related object extension.
     *
     * @param string        $id            The object ID
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        try {
            // Resolve slugs to numeric IDs consistently (validation only).
            $this->resolveRegisterSchemaIds(register: $register, schema: $schema, objectService: $objectService);
        } catch (\OCA\OpenRegister\Exception\RegisterNotFoundException | \OCA\OpenRegister\Exception\SchemaNotFoundException $e) {
            // Return 404 with clear error message if register or schema not found.
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
        }

        // Get request parameters for filtering and searching.
        $requestParams = $this->request->getParams();

        // Extract parameters for rendering.
        $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? null);
        $filter = ($requestParams['filter'] ?? $requestParams['_filter'] ?? null);
        $fields = ($requestParams['fields'] ?? $requestParams['_fields'] ?? null);
        $unset  = ($requestParams['unset'] ?? $requestParams['_unset'] ?? null);

        // Convert extend to array if it's a string.
        if (is_string($extend) === true) {
            $extend = explode(',', $extend);
        }

        // Convert fields to array if it's a string.
        if (is_string($fields) === true) {
            $fields = explode(',', $fields);
        }

        // Convert filter to array if it's a string.
        if (is_string($filter) === true) {
            $filter = explode(',', $filter);
        }

        // Convert unset to array if it's a string.
        if (is_string($unset) === true) {
            $unset = explode(',', $unset);
        }

        // Determine RBAC and multitenancy settings based on admin status.
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC.
        $multi = !$isAdmin;
        // If admin, disable multitenancy.
        // Find and validate the object.
        try {
            $objectEntity = $this->objectService->find(id: $id, extend: $extend, files: false, register: null, schema: null, rbac: $rbac, multi: $multi);

            // Render the object with requested extensions, filters, fields, and unset parameters.
            $renderedObject = $this->objectService->renderEntity(
                entity: $objectEntity,
                extend: $extend,
                depth: 0,
                filter: $filter,
                fields: $fields,
                unset: $unset,
                rbac: $rbac,
                multi: $multi
            );

            return new JSONResponse(data: $renderedObject);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
        }//end try

    }//end show()


    /**
     * Creates a new object in the specified register and schema
     *
     * Takes the request data, validates it against the schema, and creates a new object
     * in the database. Handles validation errors appropriately.
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the created object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function create(
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        try {
            // Resolve slugs to numeric IDs consistently (validation only).
            $this->resolveRegisterSchemaIds(register: $register, schema: $schema, objectService: $objectService);
        } catch (\OCA\OpenRegister\Exception\RegisterNotFoundException | \OCA\OpenRegister\Exception\SchemaNotFoundException $e) {
            // Return 404 with clear error message if register or schema not found.
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
        }

        // Intercept request and send to webhooks before processing.
        // This allows external systems to validate, transform, or enrich the request.
        $object = $this->request->getParams();
        if ($this->webhookInterceptor !== null) {
            try {
                $object = $this->webhookInterceptor->interceptRequest(
                    request: $this->request,
                    eventType: 'object.creating'
                );
            } catch (\Exception $e) {
                // Log error but continue with original request if webhook fails.
                // This ensures webhook failures don't break the API.
                if ($this->logger !== null) {
                    $this->logger->error(
                        'Webhook interception failed',
                        [
                            'error'    => $e->getMessage(),
                            'register' => $register,
                            'schema'   => $schema,
                        ]
                    );
                }
            }
        }//end if

        // Filter out special parameters and reserved fields.
        // @todo shouldn't this be part of the object service?
        // Allow @self metadata to pass through for organization activation.
        $object = array_filter(
            $object,
            fn ($key) => !str_starts_with($key, '_')
                && !($key !== '@self' && str_starts_with($key, '@'))
                && !in_array($key, ['uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Extract uploaded files from multipart/form-data.
        $uploadedFiles = [];
        foreach ($_FILES as $fieldName => $fileData) {
            // Check if this is an array upload (multiple files with same field name).
            // PHP converts field names like "images[]" to "images" and structures data as arrays.
            /*
             * @var array{name: array<int, string>|string, type: array<int, string>|string, tmp_name: array<int, string>|string, error: array<int, int>|int, size: array<int, int>|int} $fileData
             */
            /*
             * @var string|array<int, string> $nameValue
             */
            $nameValue = $fileData['name'];
            if (is_array($nameValue) === true) {
                // Handle array uploads: images[] becomes images with array values.
                // We need to preserve all files, so use indexed keys: images[0], images[1], etc.
                // In PHP $_FILES, when name is an array, all other fields are also arrays.
                $nameArray = $nameValue;
                // Extract values - in $_FILES structure, when name is array, others are arrays too.
                // Use mixed type and then check to help Psalm understand.
                /*
                 * @var mixed $typeRaw
                 */
                $typeRaw = $fileData['type'];
                /*
                 * @var mixed $tmpNameRaw
                 */
                $tmpNameRaw = $fileData['tmp_name'];
                /*
                 * @var mixed $errorRaw
                 */
                $errorRaw = $fileData['error'];
                /*
                 * @var mixed $sizeRaw
                 */
                $sizeRaw = $fileData['size'];
                // Convert to arrays, handling both array and scalar cases for safety.
                $typeArray    = is_array($typeRaw) ? $typeRaw : [];
                $tmpNameArray = is_array($tmpNameRaw) ? $tmpNameRaw : [];
                $errorArray   = is_array($errorRaw) ? $errorRaw : [];
                $sizeArray    = is_array($sizeRaw) ? $sizeRaw : [];
                $fileCount    = count($nameArray);
                for ($i = 0; $i < $fileCount; $i++) {
                    // Use indexed key to preserve all files: images[0], images[1], images[2].
                    $uploadedFiles[$fieldName.'['.$i.']'] = [
                        'name'     => $nameArray[$i],
                        'type'     => $typeArray[$i] ?? '',
                        'tmp_name' => $tmpNameArray[$i] ?? '',
                        'error'    => $errorArray[$i] ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $sizeArray[$i] ?? 0,
                    ];
                }
            } else {
                // Handle single file upload.
                $uploadedFile = $this->request->getUploadedFile($fieldName);
                if ($uploadedFile !== null) {
                    $uploadedFiles[$fieldName] = $uploadedFile;
                }
            }//end if
        }//end foreach

        // Determine RBAC and multitenancy settings based on admin status.
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC.
        // Note: multitenancy is disabled for admins via $rbac flag
        // Save the object.
        try {
            // Use the object service to validate and save the object.
            $objectToSave = $object;
            $objectEntity = $objectService->saveObject(
                    object: $objectToSave,
                    register: $register,
                    schema: $schema,
                    rbac: $rbac,
                    multi: true,
                    uuid: null,
                    uploadedFiles: !empty($uploadedFiles) === true ? $uploadedFiles : null
            );

            // Unlock the object after saving.
            try {
                $this->objectEntityMapper->unlockObject($objectEntity->getId());
            } catch (\Exception $e) {
                // Ignore unlock errors since the save was successful.
            }
        } catch (ValidationException | CustomValidationException $exception) {
            // Handle validation errors.
                       return new JSONResponse(data: $exception->getMessage(), statusCode: 400);
        } catch (\Exception $exception) {
            // Handle all other exceptions (including RBAC permission errors).
            return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
        }//end try

        // Return the created object.
        return new JSONResponse(data: $objectEntity->jsonSerialize(), statusCode: 201);

    }//end create()


    /**
     * Updates an existing object
     *
     * Takes the request data, persist: validates it against the schema, silent: and updates an existing object
     * in the database. Handles validation errors appropriately.
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The object ID or UUID
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the updated object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function update(
        string $register,
        string $schema,
        string $id,
        ObjectService $objectService
    ): JSONResponse {
        try {
            // Resolve slugs to numeric IDs consistently (validation only).
            $this->resolveRegisterSchemaIds(register: $register, schema: $schema, objectService: $objectService);
        } catch (\OCA\OpenRegister\Exception\RegisterNotFoundException | \OCA\OpenRegister\Exception\SchemaNotFoundException $e) {
            // Return 404 with clear error message if register or schema not found.
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
        }

        // Get object data from request parameters.
        $object = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        // @todo shouldn't this be part of the object service?
        // Allow @self metadata to pass through for organization activation.
        $object = array_filter(
            $object,
            fn ($key) => !str_starts_with($key, '_')
                && !($key !== '@self' && str_starts_with($key, '@'))
                && !in_array($key, ['uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Extract uploaded files from multipart/form-data.
        $uploadedFiles = [];
        foreach ($_FILES as $fieldName => $fileData) {
            // Check if this is an array upload (multiple files with same field name).
            // PHP converts field names like "images[]" to "images" and structures data as arrays.
            /*
             * @var array{name: array<int, string>|string, type: array<int, string>|string, tmp_name: array<int, string>|string, error: array<int, int>|int, size: array<int, int>|int} $fileData
             */
            /*
             * @var string|array<int, string> $nameValue
             */
            $nameValue = $fileData['name'];
            if (is_array($nameValue) === true) {
                // Handle array uploads: images[] becomes images with array values.
                // We need to preserve all files, so use indexed keys: images[0], images[1], etc.
                // In PHP $_FILES, when name is an array, all other fields are also arrays.
                $nameArray = $nameValue;
                // Extract values - in $_FILES structure, when name is array, others are arrays too.
                // Use mixed type and then check to help Psalm understand.
                /*
                 * @var mixed $typeRaw
                 */
                $typeRaw = $fileData['type'];
                /*
                 * @var mixed $tmpNameRaw
                 */
                $tmpNameRaw = $fileData['tmp_name'];
                /*
                 * @var mixed $errorRaw
                 */
                $errorRaw = $fileData['error'];
                /*
                 * @var mixed $sizeRaw
                 */
                $sizeRaw = $fileData['size'];
                // Convert to arrays, handling both array and scalar cases for safety.
                $typeArray    = is_array($typeRaw) ? $typeRaw : [];
                $tmpNameArray = is_array($tmpNameRaw) ? $tmpNameRaw : [];
                $errorArray   = is_array($errorRaw) ? $errorRaw : [];
                $sizeArray    = is_array($sizeRaw) ? $sizeRaw : [];
                $fileCount    = count($nameArray);
                for ($i = 0; $i < $fileCount; $i++) {
                    // Use indexed key to preserve all files: images[0], images[1], images[2].
                    $uploadedFiles[$fieldName.'['.$i.']'] = [
                        'name'     => $nameArray[$i],
                        'type'     => $typeArray[$i] ?? '',
                        'tmp_name' => $tmpNameArray[$i] ?? '',
                        'error'    => $errorArray[$i] ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $sizeArray[$i] ?? 0,
                    ];
                }
            } else {
                // Handle single file upload.
                $uploadedFile = $this->request->getUploadedFile($fieldName);
                if ($uploadedFile !== null) {
                    $uploadedFiles[$fieldName] = $uploadedFile;
                }
            }//end if
        }//end foreach

        // Determine RBAC and multitenancy settings based on admin status.
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC.
        $multi = !$isAdmin;
        // If admin, disable multitenancy.
        // Check if the object exists and can be updated (silent read - no audit trail).
        // @todo shouldn't this be part of the object service?
        try {
            $existingObject = $this->objectService->findSilent(id: $id, extend: [], files: false, register: null, schema: null, rbac: $rbac, multi: $multi);

            // Get the resolved register and schema IDs from the ObjectService.
            // This ensures proper handling of both numeric IDs and slug identifiers.
            $resolvedRegisterId = $objectService->getRegister();
            // Returns the current register ID.
            $resolvedSchemaId = $objectService->getSchema();
            // Returns the current schema ID.
            // Verify that the object belongs to the specified register and schema.
            if ((int) $existingObject->getRegister() !== $resolvedRegisterId
                || (int) $existingObject->getSchema() !== $resolvedSchemaId
            ) {
                return new JSONResponse(data: ['error' => 'Object not found in specified register/schema'], statusCode: 404);
            }

            // Check if the object is locked.
            if ($existingObject->isLocked() === true
                && $existingObject->getLockedBy() !== $this->container->get('userId')
            ) {
                // Return a "locked" error with the user who has the lock.
                return new JSONResponse(
                        data: [
                            'error'    => 'Object is locked by '.$existingObject->getLockedBy(),
                            'lockedBy' => $existingObject->getLockedBy(),
                        ],
                        statusCode: 423
                );
            }
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
        } catch (\Exception $exception) {
            // Handle RBAC permission errors and other exceptions.
            return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface $e) {
            // If there's an issue getting the user ID, continue without the lock check.
        }//end try

        // Update the object.
        try {
            // Use the object service to validate and update the object.
            $objectEntity = $objectService->saveObject(
                    register: $register,
                    schema: $schema,
                    object: $object,
                    rbac: $rbac,
                    multi: $multi,
                    uuid: $id,
                    uploadedFiles: !empty($uploadedFiles) === true ? $uploadedFiles : null
            );

            // Unlock the object after saving.
            try {
                $this->objectEntityMapper->unlockObject($objectEntity->getId());
            } catch (\Exception $e) {
                // Ignore unlock errors since the update was successful.
            }

            // Return the updated object as JSON.
            return new JSONResponse(data: $objectEntity->jsonSerialize());
        } catch (ValidationException | CustomValidationException $exception) {
            // Handle validation errors.
            return $objectService->handleValidationException(exception: $exception);
        } catch (\Exception $exception) {
            // Handle all other exceptions (including RBAC permission errors).
            return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
        }//end try

    }//end update()


    /**
     * Patches (partially updates) an existing object
     *
     * Takes the request data, multi: merges it with the existing object data, persist: validates it against
     * the schema, silent: and updates the object in the database. Only the provided fields are updated, validation: * while other fields remain unchanged. Handles validation errors appropriately.
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The object ID or UUID
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the updated object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function patch(
        string $register,
        string $schema,
        string $id,
        ObjectService $objectService
    ): JSONResponse {
        // Set the schema and register to the object service.
        $objectService->setSchema(schema: $schema);
        $objectService->setRegister(register: $register);

        // Get patch data from request parameters.
        $patchData = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        // @todo shouldn't this be part of the object service?
        // Allow @self metadata to pass through for organization activation.
        $patchData = array_filter(
            $patchData,
            fn ($key) => !str_starts_with($key, '_')
                && !($key !== '@self' && str_starts_with($key, '@'))
                && !in_array($key, ['uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Check if the object exists and can be updated.
        // @todo shouldn't this be part of the object service?
        try {
            $existingObject = $this->objectService->find(id: $id);

            // Get the resolved register and schema IDs from the ObjectService.
            // This ensures proper handling of both numeric IDs and slug identifiers.
            $resolvedRegisterId = $objectService->getRegister();
            // Returns the current register ID.
            $resolvedSchemaId = $objectService->getSchema();
            // Returns the current schema ID.
            // Verify that the object belongs to the specified register and schema.
            if ((int) $existingObject->getRegister() !== $resolvedRegisterId
                || (int) $existingObject->getSchema() !== $resolvedSchemaId
            ) {
                return new JSONResponse(data: ['error' => 'Object not found in specified register/schema'], statusCode: 404);
            }

            // Check if the object is locked.
            if ($existingObject->isLocked() === true
                && $existingObject->getLockedBy() !== $this->container->get('userId')
            ) {
                // Return a "locked" error with the user who has the lock.
                return new JSONResponse(
                        data: [
                            'error'    => 'Object is locked by '.$existingObject->getLockedBy(),
                            'lockedBy' => $existingObject->getLockedBy(),
                        ],
                        statusCode: 423
                );
            }

            // Get the existing object data and merge with patch data.
            $existingData = $existingObject->getObject();
            $mergedData   = array_merge($existingData, $patchData);
            $existingObject->setObject($mergedData);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface $e) {
            // If there's an issue getting the user ID, continue without the lock check.
        }//end try

        // Update the object with merged data.
        try {
            // Use the object service to validate and update the object.
            $objectEntity = $objectService->saveObject($existingObject);

            // Unlock the object after saving.
            try {
                $this->objectEntityMapper->unlockObject($objectEntity->getId());
            } catch (\Exception $e) {
                // Ignore unlock errors since the update was successful.
            }

            // Return the updated object as JSON.
            return new JSONResponse(data: $objectEntity->jsonSerialize());
        } catch (ValidationException | CustomValidationException $exception) {
            // Handle validation errors.
            return $objectService->handleValidationException(exception: $exception);
        } catch (\Exception $exception) {
            // Handle all other exceptions (including RBAC permission errors).
            return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
        }

    }//end patch()


    /**
     * Deletes an object
     *
     * This method deletes an object based on its ID.
     *
     * @param  string        $id            The ID/UUID of the object to delete
     * @param  ObjectService $objectService The object service
     * @throws Exception
     *
     * @return JSONResponse An empty JSON response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function destroy(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        try {
            // Set the register and schema context for ObjectService.
            $objectService->setRegister(register: $register);
            $objectService->setSchema(schema: $schema);

            // Determine RBAC and multitenancy settings based on admin status.
            $isAdmin = $this->isCurrentUserAdmin();
            $rbac    = !$isAdmin;
            // If admin, rbac: disable RBAC.
            $multi = !$isAdmin;
            // If admin, multi: disable multitenancy.
            // Use ObjectService to delete the object (includes RBAC permission checks, persist: audit trail, silent: and soft delete).
            $deleteResult = $objectService->deleteObject(uuid: $id, rbac: $rbac, multi: $multi);

            if ($deleteResult === false) {
                // If delete operation failed, return error.
                return new JSONResponse(data: ['error' => 'Failed to delete object'], statusCode: 500);
            }

            // Return 204 No Content for successful delete (REST convention).
            return new JSONResponse(data: null, statusCode: 204);
        } catch (\Exception $exception) {
            // Handle all exceptions (including RBAC permission errors and object not found).
            return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
        }//end try

    }//end destroy()


    /**
     * Retrieves call logs for a object
     *
     * This method returns all the call logs associated with a object based on its ID.
     *
     * @param string        $id            The ID/UUID of the object to retrieve logs for
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the call logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @todo Implement contract functionality to handle object contracts and their relationships
     */
    public function contracts(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the schema and register to the object service.
        $objectService->setSchema(schema: $schema);
        $objectService->setRegister(register: $register);

        // Note: $id is a route parameter for API consistency (/api/objects/{register}/{schema}/{id}/contracts)
        // Currently returns empty array as contract functionality is not yet implemented.
        $objectId = $id;
        // Reserved for future use when contract functionality is implemented.
        unset($objectId);

        // Get request parameters for filtering and searching.
        $requestParams = $this->request->getParams();

        // Extract specific parameters.
        $limit  = (int) ($requestParams['limit'] ?? $requestParams['_limit'] ?? 20);
        $offset = isset($requestParams['offset']) === true ? (int) $requestParams['offset'] : (isset($requestParams['_offset']) === true ? (int) $requestParams['_offset'] : null);
        $page   = isset($requestParams['page']) === true ? (int) $requestParams['page'] : (isset($requestParams['_page']) === true ? (int) $requestParams['_page'] : null);

        // Return empty paginated response.
        return new JSONResponse(
                data: $this->paginate(
                        results: [],
                        total: 0,
                        limit: $limit,
                        offset: $offset,
                        page: $page
                        )
        );

    }//end contracts()


    /**
     * Retrieves all objects that this object references
     *
     * This method returns all objects that this object uses/references. A -> B means that A (This object) references B (Another object).
     *
     * @param string        $id            The ID of the object to retrieve relations for
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the related objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function uses(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the register and schema context first.
        $objectService->setRegister(register: $register);
        $objectService->setSchema(schema: $schema);

        // Get the relations for the object.
        $relationsArray = $objectService->find(id: $id)->getRelations();
        $relations      = array_values($relationsArray);

        // Build search query using ObjectService searchObjectsPaginated directly.
        $queryParams = $this->request->getParams();
        $searchQuery = $queryParams;

        // Clean up unwanted parameters.
        unset($searchQuery['id'], $searchQuery['_route']);

        // Use ObjectService searchObjectsPaginated directly - pass ids as named parameter.
        $result = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            rbac: true,
            multi: true,
            published: true,
            deleted: false,
            ids: $relations
        );

        // Add relations being searched for debugging.
        $result['relations'] = $relations;

        // Return the result directly from ObjectService.
        return new JSONResponse(data: $result);

    }//end uses()


    /**
     * Retrieves all objects that use a object
     *
     * This method returns all objects that reference (use) this object. B -> A means that B (Another object) references A (This object).
     *
     * @param string        $id            The ID of the object to retrieve uses for
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the referenced objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function used(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the schema and register to the object service.
        $objectService->setSchema(schema: $schema);
        $objectService->setRegister(register: $register);

        // Build search query using ObjectService searchObjectsPaginated directly.
        $queryParams = $this->request->getParams();
        $searchQuery = $queryParams;

        // Clean up unwanted parameters.
        unset($searchQuery['id'], $searchQuery['_route']);

        // Use ObjectService searchObjectsPaginated directly - pass uses as named parameter.
        $result = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            rbac: true,
            multi: true,
            published: true,
            deleted: false,
            uses: $id
        );

        // Add what we're searching for in debugging.
        $result['uses'] = $id;

        // Return the result directly from ObjectService.
        return new JSONResponse(data: $result);

    }//end used()


    /**
     * Retrieves logs for an object
     *
     * This method returns a JSON response containing the logs for a specific object.
     *
     * @param string        $id            The ID of the object to retrieve logs for
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function logs(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the register and schema context first.
        $objectService->setRegister(register: $register);
        $objectService->setSchema(schema: $schema);

        // Try to fetch the object by ID/UUID only (no register/schema filter yet).
        try {
            $object = $objectService->find(id: $id);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['message' => 'Object not found'], statusCode: 404);
        }

        // Normalize and compare register.
        $objectRegister = $object->getRegister();
        // could be ID or slug.
        $objectSchema = $object->getSchema();
        // could be ID, schema: slug, extend: or array/object.
        // Normalize requested register.
        $requestedRegister = $register;
        $requestedSchema   = $schema;

        // If objectSchema is an array/object, files: get slug and id.
        $objectSchemaSlug = null;
        if (is_array($objectSchema) === true && (($objectSchema['id'] ?? null) !== null)) {
            $objectSchemaId   = (string) $objectSchema['id'];
            $objectSchemaSlug = isset($objectSchema['slug']) === true ? strtolower($objectSchema['slug']) : null;
        } else if (is_object($objectSchema) === true && (($objectSchema->id ?? null) !== null)) {
            $objectSchemaId   = (string) $objectSchema->id;
            $objectSchemaSlug = isset($objectSchema->slug) === true ? strtolower($objectSchema->slug) : null;
        } else {
            $objectSchemaId = (string) $objectSchema;
        }

        // Normalize requested schema.
        $requestedSchemaNorm = strtolower($requestedSchema);
        $objectSchemaIdNorm  = strtolower((string) $objectSchemaId);
        // $objectSchemaSlug is already lowercase from lines 1154/1157.
        $objectSchemaSlugNorm = $objectSchemaSlug;

        // Check schema match (by id or slug).
        $schemaMatch = (
            $requestedSchemaNorm === $objectSchemaIdNorm ||
            ($objectSchemaSlugNorm && $requestedSchemaNorm === $objectSchemaSlugNorm)
        );

        // Register normalization (string compare).
        $objectRegisterNorm    = strtolower((string) $objectRegister);
        $requestedRegisterNorm = strtolower($requestedRegister);
        $registerMatch         = ($objectRegisterNorm === $requestedRegisterNorm);

        if (!$schemaMatch || !$registerMatch) {
            return new JSONResponse(data: ['message' => 'Object does not belong to specified register/schema'], statusCode: 404);
        }

        // Get config and fetch logs.
        $config = $this->getConfig(register: $register, schema: $schema);
        $logs   = $objectService->getLogs(uuid: $id, filters: $config['filters']);

        // Get total count of logs.
        $total = count($logs);

        // Return paginated results.
        return new JSONResponse(data: $this->paginate(results: $logs, total: $total, limit: $config['limit'], offset: $config['offset'], page: $config['page']));

    }//end logs()


    /**
     * Lock an object
     *
     * @param string $id The ID/UUID of the object to lock
     *
     * @return JSONResponse A JSON response containing the locked object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function lock(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the schema and register to the object service.
        $objectService->setSchema(schema: $schema);
        $objectService->setRegister(register: $register);

        $data    = $this->request->getParams();
        $process = ($data['process'] ?? null);
        // Check if duration is set in the request data.
        $duration = null;
        if (($data['duration'] ?? null) !== null) {
            $duration = (int) $data['duration'];
        }

        $object = $this->objectEntityMapper->lockObject(
            identifier: $id,
            process: $process,
            duration: $duration
        );

        return new JSONResponse(data: $object);

    }//end lock()


    /**
     * Unlock an object
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to unlock
     *
     * @return JSONResponse A JSON response containing the unlocked object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function unlock(string $register, string $schema, string $id): JSONResponse
    {
        $this->objectService->setRegister(register: $register);
        $this->objectService->setSchema(schema: $schema);
        $this->objectService->unlockObject($id);
        return new JSONResponse(data: ['message' => 'Object unlocked successfully']);

    }//end unlock()


    /**
     * Export objects to specified format
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return DataDownloadResponse The exported file as a download response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function export(string $register, string $schema, ObjectService $objectService): DataDownloadResponse
    {
        // Set the register and schema context.
        $objectService->setRegister(register: $register);
        $objectService->setSchema(schema: $schema);

        // Get filters and type from request.
        $filters = $this->request->getParams();
        unset($filters['_route']);
        $type = $this->request->getParam(key: 'type', default: 'excel');

        // Get register and schema entities.
        $registerEntity = $this->registerMapper->find($register);
        $schemaEntity   = $this->schemaMapper->find($schema);

        // Handle different export types.
        switch ($type) {
            case 'csv':
                $csv = $this->exportService->exportToCsv(register: $registerEntity, schema: $schemaEntity, filters: $filters, currentUser: $this->userSession->getUser());

                // Generate filename.
                $filename = sprintf(
                    '%s_%s_%s.csv',
                        $registerEntity->getSlug(),
                    $schemaEntity->getSlug(),
                    (new \DateTime())->format('Y-m-d_His')
                );

                return new DataDownloadResponse(
                            $csv,
                            $filename,
                            'text/csv'
                );

            case 'excel':
            default:
                $spreadsheet = $this->exportService->exportToExcel(register: $registerEntity, schema: $schemaEntity, filters: $filters, currentUser: $this->userSession->getUser());

                // Create Excel writer.
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

                // Generate filename.
                $filename = sprintf(
                    '%s_%s_%s.xlsx',
                        $registerEntity->getSlug(),
                    $schemaEntity->getSlug(),
                    (new \DateTime())->format('Y-m-d_His')
                );

                // Get Excel content.
                ob_start();
                $writer->save('php://output');
                $content = ob_get_clean();

                return new DataDownloadResponse(
                            $content,
                            $filename,
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                );
        }//end switch

    }//end export()


    /**
     * Import objects into a register
     *
     * @param int $register The ID of the register to import into
     *
     * @return JSONResponse The result of the import operation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function import(int $register): JSONResponse
    {
        try {
            // Get the uploaded file.
            $uploadedFile = $this->request->getUploadedFile('file');
            if ($uploadedFile === null) {
                return new JSONResponse(data: ['error' => 'No file uploaded'], statusCode: 400);
            }

            // Find the register.
            $registerEntity = $this->registerMapper->find($register);

            // Determine file type from extension.
            $filename  = $uploadedFile['name'];
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Handle different file types.
            switch ($extension) {
                case 'xlsx':
                case 'xls':

                    // Get optional validation and events parameters.
                    $validation = filter_var($this->request->getParam('validation', false), FILTER_VALIDATE_BOOLEAN);
                    $events     = filter_var($this->request->getParam('events', false), FILTER_VALIDATE_BOOLEAN);

                    $summary = $this->importService->importFromExcel(
                        filePath: $uploadedFile['tmp_name'],
                        register: $registerEntity,
                        schema: null,
                        chunkSize: 5,
                        validation: $validation,
                        events: $events,
                        rbac: true,
                        multi: true,
                        publish: false,
                        currentUser: $this->userSession->getUser()
                    );
                    break;

                case 'csv':

                    // For CSV, schema can be specified in the request.
                    $schemaId = $this->request->getParam(key: 'schema');

                    if ($schemaId === null || $schemaId === '') {
                        // If no schema specified, get the first available schema from the register.
                        $schemas = $registerEntity->getSchemas();
                        if (empty($schemas) === true) {
                            return new JSONResponse(data: ['error' => 'No schema found for register'], statusCode: 400);
                        }

                        // $schemas is always an array from getSchemas(), but handle both cases for type safety.
                        $schemaId = reset($schemas);
                    }

                    $schema = $this->schemaMapper->find($schemaId);

                    // Get optional parameters with sensible defaults.
                    $validation = filter_var($this->request->getParam('validation', false), FILTER_VALIDATE_BOOLEAN);
                    $events     = filter_var($this->request->getParam('events', false), FILTER_VALIDATE_BOOLEAN);
                    $rbac       = filter_var($this->request->getParam('rbac', true), FILTER_VALIDATE_BOOLEAN);
                    $multi      = filter_var($this->request->getParam('multi', true), FILTER_VALIDATE_BOOLEAN);
                    $chunkSize  = (int) $this->request->getParam('chunkSize', 5);

                    $summary = $this->importService->importFromCsv(
                        filePath: $uploadedFile['tmp_name'],
                        register: $registerEntity,
                        schema: $schema,
                        chunkSize: $chunkSize,
                        validation: $validation,
                        events: $events,
                        rbac: $rbac,
                        multi: $multi
                    );
                    break;

                default:

                    return new JSONResponse(data: ['error' => "Unsupported file type: $extension"], statusCode: 400);
            }//end switch

            return new JSONResponse(
                    data: [
                        'message' => 'Import successful',
                        'summary' => $summary,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try

    }//end import()


    /**
     * Publish an object
     *
     * This method publishes an object by setting its publication date to now or a specified date.
     *
     * @param string        $id            The ID of the object to publish
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the published object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function publish(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        // Set the schema and register to the object service.
        $objectService->setSchema(schema: $schema);
        $objectService->setRegister(register: $register);

        // Determine RBAC and multitenancy settings based on admin status.
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC.
        $multi = !$isAdmin;
        // If admin, disable multitenancy.
        try {
            // Get the publication date from request if provided.
            $date = null;
            if ($this->request->getParam(key: 'date') !== null) {
                $date = new \DateTime($this->request->getParam(key: 'date'));
            }

            // Publish the object.
            $object = $objectService->publish(uuid: $id, date: $date, rbac: $rbac, multi: $multi);

            return new JSONResponse(data: $object->jsonSerialize());
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }

    }//end publish()


    /**
     * Depublish an object
     *
     * This method depublishes an object by setting its depublication date to now or a specified date.
     *
     * @param string        $id            The ID of the object to depublish
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the depublished object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function depublish(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        // Set the schema and register to the object service.
        $objectService->setSchema(schema: $schema);
        $objectService->setRegister(register: $register);

        // Determine RBAC and multitenancy settings based on admin status.
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC.
        $multi = !$isAdmin;
        // If admin, disable multitenancy.
        try {
            // Get the depublication date from request if provided.
            $date = null;
            if ($this->request->getParam(key: 'date') !== null) {
                $date = new \DateTime($this->request->getParam(key: 'date'));
            }

            // Depublish the object.
            $object = $objectService->depublish(uuid: $id, date: $date, rbac: $rbac, multi: $multi);

            return new JSONResponse(data: $object->jsonSerialize());
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }

    }//end depublish()


    /**
     * Merge two objects
     *
     * This method merges object A into object B within the same register and schema.
     * It handles merging of properties, files, and relations based on user preferences.
     *
     * @param string        $id            The ID of object A (source object to merge from)
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the merge result
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function merge(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        // Set the schema and register to the object service.
        $objectService->setRegister($register);
        $objectService->setSchema($schema);

        try {
            // Get merge data from request body.
            $requestParams = $this->request->getParams();

            // Validate required parameters.
            if (!isset($requestParams['target'])) {
                return new JSONResponse(data: ['error' => 'Target object ID is required'], statusCode: 400);
            }

            if (($requestParams['object'] ?? null) === null || empty($requestParams['object']) === true) {
                return new JSONResponse(data: ['error' => 'Object data is required'], statusCode: 400);
            }

            // Perform the merge operation with the new payload structure.
            $mergeResult = $objectService->mergeObjects(sourceObjectId: $id, mergeData: $requestParams);
            return new JSONResponse(data: $mergeResult);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 400);
        } catch (\Exception $exception) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to merge objects: '.$exception->getMessage(),
                ],
                statusCode: 500
            );
        }//end try

    }//end merge()


    /**
     * Migrate objects between registers and/or schemas
     *
     * This method migrates multiple objects from one register/schema combination
     * to another register/schema combination with property mapping.
     *
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the migration result
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function migrate(ObjectService $objectService): JSONResponse
    {
        try {
            // Get migration parameters from request.
            $requestParams  = $this->request->getParams();
            $sourceRegister = $requestParams['sourceRegister'] ?? null;
            $sourceSchema   = $requestParams['sourceSchema'] ?? null;
            $targetRegister = $requestParams['targetRegister'] ?? null;
            $targetSchema   = $requestParams['targetSchema'] ?? null;
            $objectIds      = $requestParams['objects'] ?? [];
            $mapping        = $requestParams['mapping'] ?? [];

            // Validate required parameters.
            if ($sourceRegister === null || $sourceSchema === null) {
                return new JSONResponse(data: ['error' => 'Source register and schema are required'], statusCode: 400);
            }

            if ($targetRegister === null || $targetSchema === null) {
                return new JSONResponse(data: ['error' => 'Target register and schema are required'], statusCode: 400);
            }

            if (empty($objectIds) === true) {
                return new JSONResponse(data: ['error' => 'At least one object ID is required'], statusCode: 400);
            }

            if (empty($mapping) === true) {
                return new JSONResponse(data: ['error' => 'Property mapping is required'], statusCode: 400);
            }

            // Perform the migration operation.
            $migrationResult = $objectService->migrateObjects(
                sourceRegister: $sourceRegister,
                sourceSchema: $sourceSchema,
                targetRegister: $targetRegister,
                targetSchema: $targetSchema,
                objectIds: $objectIds,
                mapping: $mapping
            );

            return new JSONResponse(data: $migrationResult);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => 'Register or schema not found'], statusCode: 404);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 400);
        } catch (\Exception $exception) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to migrate objects: '.$exception->getMessage(),
                ],
                statusCode: 500
            );
        }//end try

    }//end migrate()


    /**
     * Download all files of an object as a ZIP archive
     *
     * This method creates a ZIP file containing all files associated with a specific object
     * and returns it as a downloadable file. The ZIP file includes all files stored in the
     * object's folder with their original names.
     *
     * @param string        $id            The identifier of the object to download files for
     * @param string        $register      The register (identifier or slug) to search within
     * @param string        $schema        The schema (identifier or slug) to search within
     * @param ObjectService $objectService The object service for handling object operations
     *
     * @return DataDownloadResponse|JSONResponse ZIP file download response or error response
     *
     * @throws ContainerExceptionInterface If there's an issue with dependency injection
     * @throws NotFoundExceptionInterface If the FileService dependency is not found
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function downloadFiles(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): DataDownloadResponse | JSONResponse {
        try {
            // Set the context for the object service.
            $objectService->setRegister(register: $register);
            $objectService->setSchema(schema: $schema);

            // Get the object to ensure it exists and we have access.
            $object = $objectService->find(id: $id);

            // Get the FileService from the container.
            /*
             * @var FileService $fileService
             */
            $fileService = $this->container->get(FileService::class);

            // Optional: get custom filename from query parameters.
            $customFilename = $this->request->getParam(key: 'filename');

            // Create the ZIP archive.
            $zipInfo = $fileService->createObjectFilesZip($object, register: $customFilename);

            // Read the ZIP file content.
            $zipContent = file_get_contents($zipInfo['path']);
            if ($zipContent === false) {
                // Clean up temporary file.
                if (file_exists($zipInfo['path']) === true) {
                    unlink($zipInfo['path']);
                }

                throw new \Exception('Failed to read ZIP file content');
            }

            // Clean up temporary file after reading.
            if (file_exists($zipInfo['path']) === true) {
                unlink($zipInfo['path']);
            }

            // Return the ZIP file as a download response.
            return new DataDownloadResponse(
                    $zipContent,
                    $zipInfo['filename'],
                    $zipInfo['mimeType']
            );
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (\Exception $exception) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to create ZIP file: '.$exception->getMessage(),
                ],
                statusCode: 500
            );
        }//end try

    }//end downloadFiles()


    /**
     * Start batch vectorization of objects
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Batch vectorization status
     */
    public function vectorizeBatch(): JSONResponse
    {
        try {
            $data      = $this->request->getParams();
            $views     = $data['views'] ?? null;
            $batchSize = (int) ($data['batchSize'] ?? 25);

            // Get unified VectorizationService from container.
            $vectorizationService = $this->container->get(\OCA\OpenRegister\Service\VectorizationService::class);

            // Use unified vectorization service with 'object' entity type.
            $result = $vectorizationService->vectorizeBatch(
                    entityType: 'object',
                    options: [
                        'views'      => $views,
                        'batch_size' => $batchSize,
                        'mode'       => 'serial',
            // Objects use serial mode by default.
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'data'    => $result,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end vectorizeBatch()


    /**
     * Get object vectorization statistics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Vectorization statistics
     */
    public function getObjectVectorizationStats(): JSONResponse
    {
        try {
            // Get views parameter if provided.
            $views = $this->request->getParam('views');
            if (is_string($views) === true) {
                $views = json_decode($views, true);
            }

            // Get ObjectService from container for view-aware counting.
            $objectService = $this->container->get(\OCA\OpenRegister\Service\ObjectService::class);

            // Count objects with view filter support.
            $totalObjects = $objectService->searchObjects(
                query: [
                    '_count'  => true,
                    '_source' => 'database',
                ],
                rbac: false,
                multi: false,
                ids: null,
                uses: null,
                views: $views
            );

            return new JSONResponse(
                    data: [
                        'success'       => true,
                        'total_objects' => $totalObjects,
                        'views'         => $views,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end getObjectVectorizationStats()


    /**
     * Get count of objects for vectorization
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Object count
     */
    public function getObjectVectorizationCount(): JSONResponse
    {
        try {
            // TODO: Implement proper counting logic with schemas parameter.
            // $schemas = $this->request->getParam('schemas');
            // if (is_string($schemas)) {
            // $schemas = explode(',', $schemas);
            // }
            // For now, return a placeholder.
            $count = 0;

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'count'   => $count,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end getObjectVectorizationCount()


}//end class
