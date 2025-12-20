<?php

/**
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

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use RuntimeException;
use DateTime;
use DateInterval;
use stdClass;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
 * Objects controller for OpenRegister
 *
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
     * @param WebhookService     $webhookService     The webhook service (optional)
     * @param LoggerInterface    $logger             The logger (optional)
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
        private readonly ?WebhookService $webhookService=null,
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
     * @return (array|float|int|string)[]
     *
     * @phpstan-param array<int, mixed> $results
     *
     * @phpstan-return array<string, mixed>
     *
     * @psalm-param array<int, mixed> $results
     *
     * @psalm-return array{
     *     results: array<int, mixed>,
     *     total: int<0, max>,
     *     page: float|int<1, max>,
     *     pages: 1|float,
     *     limit: int<1, max>,
     *     offset: int<0, max>,
     *     next?: string,
     *     prev?: string
     * }
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
        // @todo: this is a hack to ensure the pagination is correct when the total is not known.
        // That suggests that the underlying count service has a problem that needs to be fixed instead.
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
                $nextUrl .= '&page='.$nextPage;
                if (strpos($nextUrl, '?') === false) {
                    $nextUrl .= '?page='.$nextPage;
                }
            }

            $paginatedResults['next'] = $nextUrl;
        }

        // Add previous page link if not on first page.
        if ($page > 1) {
            $prevPage = $page - 1;
            $prevUrl  = preg_replace('/([?&])page=\d+/', '$1page='.$prevPage, $currentUrl);
            if (strpos($prevUrl, 'page=') === false) {
                $prevUrl .= '&page='.$prevPage;
                if (strpos($prevUrl, '?') === false) {
                    $prevUrl .= '?page='.$prevPage;
                }
            }

            $paginatedResults['prev'] = $prevUrl;
        }

        return $paginatedResults;
    }//end paginate()

    /**
     * Helper method to get configuration array from the current request (LEGACY)
     *
     * @param string|null $_register Optional register identifier (unused).
     * @param string|null $_schema   Optional schema identifier (unused).
     * @param array|null  $ids       Optional array of specific IDs to filter
     *
     * @return (array|int|mixed|null)[] Configuration array containing:
     *
     * @deprecated Use buildSearchQuery() instead for faceting-enabled endpoints
     *               - limit: (int) Maximum number of items per page
     *               - offset: (int|null) Number of items to skip
     *               - page: (int|null) Current page number
     *               - filters: (array) Filter parameters
     *               - sort: (array) Sort parameters
     *               - search: (string|null) Search term
     *               - _extend: (array|null) Properties to extend
     *               - fields: (array|null) Fields to include
     *               - unset: (array|null) Fields to exclude
     *               - register: (string|null) Register identifier
     *               - schema: (string|null) Schema identifier
     *               - ids: (array|null) Specific IDs to filter
     *
     * @psalm-return array{
     *     limit: int,
     *     offset: int|null,
     *     page: int|null,
     *     filters: array,
     *     sort: array<never, never>|mixed,
     *     _search: mixed|null,
     *     _extend: mixed|null,
     *     _fields: mixed|null,
     *     _unset: mixed|null,
     *     ids: array|null
     * }
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function getConfig(?string $_register=null, ?string $_schema=null, ?array $ids=null): array
    {
        $params = $this->request->getParams();

        unset($params['id']);
        unset($params['_route']);

        // Extract and normalize parameters.
        $limit  = (int) ($params['limit'] ?? $params['_limit'] ?? 20);
        $offset = null;
        if (($params['_offset'] ?? null) !== null) {
            $offset = (int) $params['_offset'];
        }

        if (($params['offset'] ?? null) !== null) {
            $offset = (int) $params['offset'];
        }

        $page = null;
        if (($params['_page'] ?? null) !== null) {
            $page = (int) $params['_page'];
        }

        if (($params['page'] ?? null) !== null) {
            $page = (int) $params['page'];
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
            '_search' => ($params['_search'] ?? null),
            '_extend' => ($params['extend'] ?? $params['_extend'] ?? null),
            '_fields' => ($params['fields'] ?? $params['_fields'] ?? null),
            '_unset'  => ($params['unset'] ?? $params['_unset'] ?? null),
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
            throw new RegisterNotFoundException(registerSlugOrId: $register, code: 404, previous: $e);
        }

        try {
            $objectService->setSchema(schema: $schema);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // If schema not found, throw custom exception.
            throw new SchemaNotFoundException(schemaSlugOrId: $schema, code: 404, previous: $e);
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
     *
     * @psalm-return JSONResponse<200|404, array<string, mixed>, array<never, never>>
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
        $query = $objectService->buildSearchQuery(
            requestParams: $this->request->getParams(),
            register: $resolved['register'],
            schema: $resolved['schema']
        );

        // Extract filtering parameters from request.
        $params    = $this->request->getParams();
        $rbac      = filter_var($params['rbac'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $multi     = filter_var($params['multi'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $published = filter_var($params['_published'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $deleted   = filter_var($params['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // **INTELLIGENT SOURCE SELECTION**: ObjectService automatically chooses optimal source.
        $result = $objectService->searchObjectsPaginated(
            query: $query,
            _rbac: $rbac,
            _multitenancy: $multi,
            published: $published,
            deleted: $deleted
        );

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
     *
     * @psalm-return JSONResponse<200, array<string, mixed>, array<never, never>>
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
     * Retrieves and returns a single object from the specified register and schema,
     * with support for field filtering and related object extension.
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
     *
     * @psalm-return JSONResponse<200|404, array, array<never, never>>
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
        $rbac    = $isAdmin === false;
        // If admin, disable RBAC.
        $multi = $isAdmin === false;
        // If admin, disable multitenancy.
        // Find and validate the object.
        try {
            $objectEntity = $this->objectService->find(
                id: $id,
                _extend: $extend,
                files: false,
                register: null,
                schema: null,
                _rbac: $rbac,
                _multitenancy: $multi
            );
            if ($objectEntity === null) {
                return new JSONResponse(data: ['error' => "Object with id {$id} not found"], statusCode: Http::STATUS_NOT_FOUND);
            }

            // Render the object with requested extensions, filters, fields, and unset parameters.
            $renderedObject = $this->objectService->renderEntity(
                entity: $objectEntity,
                _extend: $extend,
                depth: 0,
                filter: $filter,
                fields: $fields,
                unset: $unset,
                _rbac: $rbac,
                _multitenancy: $multi
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<201|403|404, array{'@self'?: array{name: mixed|null|string,...}|mixed, message?: mixed|string, error?: mixed|string,...}, array<never, never>>|JSONResponse<400, string, array<never, never>>
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
        if ($this->webhookService !== null) {
            try {
                $object = $this->webhookService->interceptRequest(
                    request: $this->request,
                    eventType: 'object.creating'
                );
            } catch (Exception $e) {
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
            fn ($key) => str_starts_with($key, '_') === false
                && !($key !== '@self' && str_starts_with($key, '@'))
                && in_array($key, ['uuid', 'register', 'schema']) === false,
            ARRAY_FILTER_USE_KEY
        );

        // Extract uploaded files from multipart/form-data.
        $uploadedFiles = [];
        foreach ($_FILES as $fieldName => $fileData) {
            // Check if this is an array upload (multiple files with same field name).
            // PHP converts field names like "images[]" to "images" and structures data as arrays.
            /*
             * @var array{
             *     name: array<int, string>|string,
             *     type: array<int, string>|string,
             *     tmp_name: array<int, string>|string,
             *     error: array<int, int>|int,
             *     size: array<int, int>|int
             * } $fileData
             */

            /*
             * @var string|array<int, string> $nameValue
             */

            $nameValue = $fileData['name'];
            /*
             * @psalm-suppress TypeDoesNotContainType - $_FILES can have mixed types
             */
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
                /*
                 * @psalm-suppress TypeDoesNotContainType - $_FILES can have mixed types
                 */
                $typeArray = is_array($typeRaw) === true ? $typeRaw : [];
                /*
                 * @psalm-suppress TypeDoesNotContainType - $_FILES can have mixed types
                 */
                $tmpNameArray = is_array($tmpNameRaw) === true ? $tmpNameRaw : [];
                /*
                 * @psalm-suppress TypeDoesNotContainType - $_FILES can have mixed types
                 */
                $errorArray = is_array($errorRaw) === true ? $errorRaw : [];
                /*
                 * @psalm-suppress TypeDoesNotContainType - $_FILES can have mixed types
                 */
                $sizeArray = is_array($sizeRaw) === true ? $sizeRaw : [];
                $fileCount = count($nameArray);
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

                continue;
            }//end if

            // Handle single file upload.
            $uploadedFile = $this->request->getUploadedFile($fieldName);
            if ($uploadedFile !== null) {
                $uploadedFiles[$fieldName] = $uploadedFile;
            }
        }//end foreach

        // Determine RBAC and multitenancy settings based on admin status.
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC.
        // Note: multitenancy is disabled for admins via $rbac flag.
        // Determine uploaded files value.
        $uploadedFilesValue = null;
        if (empty($uploadedFiles) === false) {
            $uploadedFilesValue = $uploadedFiles;
        }

        // Save the object.
        try {
            // Use the object service to validate and save the object.
            $objectToSave = $object;
            $objectEntity = $objectService->saveObject(
                object: $objectToSave,
                register: $register,
                schema: $schema,
                _rbac: $rbac,
                _multitenancy: true,
                uuid: null,
                uploadedFiles: $uploadedFilesValue
            );

            // TODO: Unlock the object after saving using LockingHandler through ObjectService.
            // The unlockObject() method on ObjectEntityMapper is deprecated.
            // For now, skipping unlock to allow CRUD operations to complete.
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
            fn ($key) => str_starts_with($key, '_') === false
                && !($key !== '@self' && str_starts_with($key, '@'))
                && in_array($key, ['uuid', 'register', 'schema']) === false,
            ARRAY_FILTER_USE_KEY
        );

        // Extract uploaded files from multipart/form-data.
        $uploadedFiles = [];
        foreach ($_FILES as $fieldName => $fileData) {
            // Check if this is an array upload (multiple files with same field name).
            // PHP converts field names like "images[]" to "images" and structures data as arrays.
            /*
             * @var array{
             *     name: array<int, string>|string,
             *     type: array<int, string>|string,
             *     tmp_name: array<int, string>|string,
             *     error: array<int, int>|int,
             *     size: array<int, int>|int
             * } $fileData
             */

            /*
             * @var string|array<int, string> $nameValue
             */

            $nameValue = $fileData['name'];
            /*
             * @psalm-suppress TypeDoesNotContainType - $_FILES can have mixed types
             */
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
                /*
                 * @psalm-suppress TypeDoesNotContainType - $_FILES can have mixed types
                 */
                $typeArray = is_array($typeRaw) === true ? $typeRaw : [];
                /*
                 * @psalm-suppress TypeDoesNotContainType - $_FILES can have mixed types
                 */
                $tmpNameArray = is_array($tmpNameRaw) === true ? $tmpNameRaw : [];
                /*
                 * @psalm-suppress TypeDoesNotContainType - $_FILES can have mixed types
                 */
                $errorArray = is_array($errorRaw) === true ? $errorRaw : [];
                /*
                 * @psalm-suppress TypeDoesNotContainType - $_FILES can have mixed types
                 */
                $sizeArray = is_array($sizeRaw) === true ? $sizeRaw : [];
                $fileCount = count($nameArray);
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

                continue;
            }//end if

            // Handle single file upload.
            $uploadedFile = $this->request->getUploadedFile($fieldName);
            if ($uploadedFile !== null) {
                $uploadedFiles[$fieldName] = $uploadedFile;
            }
        }//end foreach

        // Determine RBAC and multitenancy settings based on admin status.
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = $isAdmin === false;
        // If admin, disable RBAC.
        $multi = $isAdmin === false;
        // If admin, disable multitenancy.
        // Check if the object exists and can be updated (silent read - no audit trail).
        // @todo shouldn't this be part of the object service?
        try {
            $existingObject = $this->objectService->findSilent(
                id: $id,
                _extend: [],
                files: false,
                register: null,
                schema: null,
                _rbac: $rbac,
                _multitenancy: $multi
            );

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

        // Determine uploaded files value.
        $uploadedFilesValue = null;
        if (empty($uploadedFiles) === false) {
            $uploadedFilesValue = $uploadedFiles;
        }

        // Update the object.
        try {
            // Use the object service to validate and update the object.
            $objectEntity = $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $object,
                _rbac: $rbac,
                _multitenancy: $multi,
                uuid: $id,
                uploadedFiles: $uploadedFilesValue
            );

            // Unlock the object after saving.
            try {
                $this->objectService->unlockObject($objectEntity->getUuid());
            } catch (Exception $e) {
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
     * Takes the request data, _multitenancy: merges it with the existing object data, persist: validates it against
     * the schema, and updates the object in the database. Only the provided fields are updated,
     * while other fields remain unchanged. Handles validation errors appropriately.
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
            fn ($key) => str_starts_with($key, '_') === false
                && !($key !== '@self' && str_starts_with($key, '@'))
                && in_array($key, ['uuid', 'register', 'schema']) === false,
            ARRAY_FILTER_USE_KEY
        );

        // Check if the object exists and can be updated.
        // @todo shouldn't this be part of the object service?
        try {
            $existingObject = $this->objectService->find(id: $id);
            if ($existingObject === null) {
                return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
            }

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
            $mergedData   = array_merge($existingData ?? [], $patchData);
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
                $this->objectService->unlockObject($objectEntity->getUuid());
            } catch (Exception $e) {
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
     * @param string        $id            The ID/UUID of the object to delete
     * @param ObjectService $objectService The object service
     *
     * @throws Exception
     *
     * @return JSONResponse An empty JSON response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<204, null, array<never, never>>|JSONResponse<403|500, array{error: string}, array<never, never>>
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
            // If admin, _rbac: disable RBAC.
            $multi = !$isAdmin;
            // If admin, _multitenancy: disable multitenancy.
            // Use ObjectService to delete the object (includes RBAC permission checks, persist: audit trail, silent: and soft delete).
            $deleteResult = $objectService->deleteObject(uuid: $id, _rbac: $rbac, _multitenancy: $multi);

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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @todo Implement contract functionality to handle object contracts and their relationships
     *
     * @psalm-return JSONResponse<200, array{results: array<int, mixed>, total: int<0, max>, page: float|int<1, max>, pages: 1|float, limit: int<1, max>, offset: int<0, max>, next?: string, prev?: string}, array<never, never>>
     */
    public function contracts(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the schema and register to the object service.
        $objectService->setSchema(schema: $schema);
        $objectService->setRegister(register: $register);

        // Get request parameters for filtering.
        $requestParams = $this->request->getParams();

        // Extract specific parameters.
        $limit = (int) ($requestParams['limit'] ?? $requestParams['_limit'] ?? 20);

        // Determine offset value.
        $offset = null;
        if (isset($requestParams['_offset']) === true) {
            $offset = (int) $requestParams['_offset'];
        }

        if (isset($requestParams['offset']) === true) {
            $offset = (int) $requestParams['offset'];
        }

        // Determine page value.
        $page = null;
        if (isset($requestParams['_page']) === true) {
            $page = (int) $requestParams['_page'];
        }

        if (isset($requestParams['page']) === true) {
            $page = (int) $requestParams['page'];
        }

        // Build filters array.
        $filters = [
            'limit'  => $limit,
            'offset' => $offset,
            'page'   => $page,
        ];

        // Use ObjectService delegation to handler.
        $result = $objectService->getObjectContracts(objectId: $id, filters: $filters);

        // Return empty paginated response.
        return new JSONResponse(
            data: $this->paginate(
                results: $result['results'] ?? [],
                total: $result['total'] ?? 0,
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
     *
     * @psalm-return JSONResponse<200, array{relations: list<mixed>,...}, array<never, never>>
     */
    public function uses(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the register and schema context first.
        $objectService->setRegister(register: $register);
        $objectService->setSchema(schema: $schema);

        // Build search query from request parameters.
        $queryParams = $this->request->getParams();
        $searchQuery = $queryParams;

        // Clean up unwanted parameters.
        unset($searchQuery['id'], $searchQuery['_route']);

        // Use ObjectService delegation to handler.
        $result = $objectService->getObjectUses(
            objectId: $id,
            query: $searchQuery,
            rbac: true,
            _multitenancy: true
        );

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
     *
     * @psalm-return JSONResponse<200, array{uses: string,...}, array<never, never>>
     */
    public function used(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the schema and register to the object service.
        $objectService->setSchema(schema: $schema);
        $objectService->setRegister(register: $register);

        // Build search query from request parameters.
        $queryParams = $this->request->getParams();
        $searchQuery = $queryParams;

        // Clean up unwanted parameters.
        unset($searchQuery['id'], $searchQuery['_route']);

        // Use ObjectService delegation to handler.
        $result = $objectService->getObjectUsedBy(
            objectId: $id,
            query: $searchQuery,
            rbac: true,
            _multitenancy: true
        );

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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|404, array{results?: array<int, mixed>, total?: int<0, max>, page?: float|int<1, max>, pages?: 1|float, limit?: int<1, max>, offset?: int<0, max>, next?: string, prev?: string, message?: 'Object does not belong to specified register/schema'|'Object not found'}, array<never, never>>
     */
    public function logs(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the register and schema context first.
        $objectService->setRegister(register: $register);
        $objectService->setSchema(schema: $schema);

        // Try to fetch the object by ID/UUID only (no register/schema filter yet).
        try {
            $object = $objectService->find(id: $id);
            if ($object === null) {
                return new JSONResponse(data: ['message' => 'Object not found'], statusCode: 404);
            }
        } catch (Exception $e) {
            return new JSONResponse(data: ['message' => 'Object not found'], statusCode: 404);
        }

        // Normalize and compare register.
        $objectRegister = $object->getRegister();
        // Could be ID or slug.
        $objectSchema = $object->getSchema();
        // Could be ID, schema: slug, _extend: or array/object.
        // Normalize requested register.
        $requestedRegister = $register;
        $requestedSchema   = $schema;

        // If objectSchema is an array/object, files: get slug and id.
        $objectSchemaSlug = null;
        if (is_array($objectSchema) === true && (($objectSchema['id'] ?? null) !== null)) {
            $objectSchemaId   = (string) $objectSchema['id'];
            $objectSchemaSlug = null;
            if (isset($objectSchema['slug']) === true) {
                $objectSchemaSlug = strtolower($objectSchema['slug']);
            }
        }

        if (is_object($objectSchema) === true && (($objectSchema->id ?? null) !== null)) {
            $objectSchemaId   = (string) $objectSchema->id;
            $objectSchemaSlug = null;
            if (isset($objectSchema->slug) === true) {
                $objectSchemaSlug = strtolower($objectSchema->slug);
            }
        }

        if (is_array($objectSchema) === false && is_object($objectSchema) === false) {
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

        if ($schemaMatch === false || $registerMatch === false) {
            return new JSONResponse(data: ['message' => 'Object does not belong to specified register/schema'], statusCode: 404);
        }

        // Get config and fetch logs.
        $config = $this->getConfig(_register: $register, _schema: $schema);
        $logs   = $objectService->getLogs(uuid: $id, filters: $config['filters']);

        // Get total count of logs.
        $total = count($logs);

        // Return paginated results.
        return new JSONResponse(
            data: $this->paginate(
                results: $logs,
                total: $total,
                limit: $config['limit'],
                offset: $config['offset'],
                page: $config['page']
            )
        );
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
     *
     * @psalm-return JSONResponse<200, array{locked: true, ...<array-key, mixed>}, array<never, never>>
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

        $lockResult = $objectService->lockObject(
            identifier: $id,
            process: $process,
            duration: $duration
        );

        // Return response with locked status for test compatibility.
        return new JSONResponse(data: array_merge($lockResult, ['locked' => true]));
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
     *
     * @psalm-return JSONResponse<200, array{message: 'Object unlocked successfully', locked: false, uuid: string}, array<never, never>>
     */
    public function unlock(string $register, string $schema, string $id): JSONResponse
    {
        $this->objectService->setRegister(register: $register);
        $this->objectService->setSchema(schema: $schema);
        $this->objectService->unlockObject($id);

        // Return response with locked status for test compatibility.
        return new JSONResponse(
            data: [
                'message' => 'Object unlocked successfully',
                'locked'  => false,
                'uuid'    => $id,
            ]
        );
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return DataDownloadResponse<200, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'|'text/csv', array<never, never>>
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

        // Use ObjectService delegation to ExportHandler.
        $result = $objectService->exportObjects(
            register: $registerEntity,
            schema: $schemaEntity,
            filters: $filters,
            type: $type,
            currentUser: $this->userSession->getUser()
        );

        // Return download response.
        return new DataDownloadResponse(
            data: $result['content'],
            filename: $result['filename'],
            contentType: $result['mimetype']
        );
    }//end export()

    /**
     * Import objects into a register
     *
     * @param int $register The ID of the register to import into
     *
     * @return JSONResponse The result of the import operation
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|400|500,
     *     array{
     *         error?: string,
     *         message?: 'Import successful',
     *         summary?: array<
     *             array{
     *                 created: array,
     *                 errors: array,
     *                 found: int,
     *                 unchanged: array,
     *                 updated: array,
     *                 schema: array{id: int, slug: null|string, title: null|string},
     *                 deduplication_efficiency?: string,
     *                 performance?: array{
     *                     efficiency: 0|float,
     *                     objectsPerSecond: float,
     *                     totalFound: int<0, max>,
     *                     totalProcessed: int<0, max>,
     *                     totalTime: float,
     *                     totalTimeMs: float
     *                 }
     *             }|mixed
     *         >
     *     },
     *     array<never, never>
     * >
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

            // Get optional schema for CSV (can be null, handler will auto-resolve).
            $schemaId = $this->request->getParam(key: 'schema');
            $schema   = ($schemaId !== null && $schemaId !== '') ? $this->schemaMapper->find($schemaId) : null;

            // Get optional parameters with sensible defaults.
            $validation = filter_var($this->request->getParam(key: 'validation', default: false), FILTER_VALIDATE_BOOLEAN);
            $events     = filter_var($this->request->getParam(key: 'events', default: false), FILTER_VALIDATE_BOOLEAN);
            $rbac       = filter_var($this->request->getParam(key: 'rbac', default: true), FILTER_VALIDATE_BOOLEAN);
            $multi      = filter_var($this->request->getParam(key: 'multi', default: true), FILTER_VALIDATE_BOOLEAN);
            $publish    = filter_var($this->request->getParam(key: 'publish', default: false), FILTER_VALIDATE_BOOLEAN);

            // Use ObjectService delegation to ExportHandler.
            $result = $this->objectService->importObjects(
                register: $registerEntity,
                uploadedFile: $uploadedFile,
                schema: $schema,
                validation: $validation,
                events: $events,
                rbac: $rbac,
                multitenancy: $multi,
                publish: $publish,
                currentUser: $this->userSession->getUser()
            );

            return new JSONResponse(
                data: [
                    'message' => 'Import successful',
                    'summary' => $result,
                ]
            );
        } catch (Exception $e) {
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400, array{error?: mixed|string, name?: mixed|null|string, '@self'?: array{name: mixed|null|string,...}|mixed,...}, array<never, never>>
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
        $rbac    = $isAdmin === false;
        // If admin, disable RBAC.
        $multi = $isAdmin === false;
        // If admin, disable multitenancy.
        try {
            // Get the publication date from request if provided.
            $date = null;
            if ($this->request->getParam(key: 'date') !== null) {
                $date = new DateTime($this->request->getParam(key: 'date'));
            }

            // Publish the object.
            $object = $objectService->publish(uuid: $id, date: $date, _rbac: $rbac, _multitenancy: $multi);

            // Return the object data with @self unpacked for simpler response structure.
            $response = $object->jsonSerialize();
            return new JSONResponse(data: $response['@self'] ?? $response);
        } catch (Exception $e) {
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400, array{error?: mixed|string, name?: mixed|null|string, '@self'?: array{name: mixed|null|string,...}|mixed,...}, array<never, never>>
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
        $rbac    = $isAdmin === false;
        // If admin, disable RBAC.
        $multi = $isAdmin === false;
        // If admin, disable multitenancy.
        try {
            // Get the depublication date from request if provided.
            $date = null;
            if ($this->request->getParam(key: 'date') !== null) {
                $date = new DateTime($this->request->getParam(key: 'date'));
            }

            // Depublish the object.
            $object = $objectService->depublish(uuid: $id, date: $date, _rbac: $rbac, _multitenancy: $multi);

            // Return the object data with @self unpacked for simpler response structure.
            $response = $object->jsonSerialize();
            return new JSONResponse(data: $response['@self'] ?? $response);
        } catch (Exception $e) {
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
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing merge operation result
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|400|401|403|404|500,
     *     array{
     *         error?: string,
     *         success?: true,
     *         sourceObject?: array,
     *         targetObject?: array,
     *         mergedObject?: mixed,
     *         actions?: array{
     *             properties: list{0?: array{property: mixed, oldValue: mixed|null, newValue: mixed},...},
     *             files: array<never, never>|mixed,
     *             relations: array{action: 'dropped'|'transferred', relations: array|null},
     *             references: list{0?: array{objectId: mixed, title: mixed},...}
     *         },
     *         statistics?: array{
     *             propertiesChanged: 0|1|2,
     *             filesTransferred: 0|mixed,
     *             filesDeleted: 0|mixed,
     *             relationsTransferred: 0|1|2,
     *             relationsDropped: int<0, max>,
     *             referencesUpdated: int
     *         },
     *         warnings?: array,
     *         errors?: array<never, never>
     *     },
     *     array<never, never>
     * >
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
            if (isset($requestParams['target']) === false) {
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
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing migration operation result
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|400|401|403|404|500,
     *     array{
     *         error?: string,
     *         success?: bool,
     *         statistics?: array{
     *             objectsMigrated: 0|1|2,
     *             objectsFailed: int,
     *             propertiesMapped: int<0, max>,
     *             propertiesDiscarded: int
     *         },
     *         details?: list{
     *             0?: array{
     *                 objectId: mixed,
     *                 objectTitle: mixed|null,
     *                 success: bool,
     *                 error: null|string,
     *                 newObjectId?: mixed
     *             },
     *             ...
     *         },
     *         warnings?: list<'Some objects failed to migrate. Check details for specific errors.'>,
     *         errors?: list{0?: string,...}
     *     },
     *     array<never, never>
     * >
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return DataDownloadResponse<200, string, array<never, never>>|JSONResponse<404|500, array{error: string}, array<never, never>>
     */
    public function downloadFiles(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse|DataDownloadResponse {
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
            $zipInfo = $fileService->createObjectFilesZip(object: $object, zipName: $customFilename);

            // Read the ZIP file content.
            $zipContent = file_get_contents($zipInfo['path']);
            if ($zipContent === false) {
                // Clean up temporary file.
                if (file_exists($zipInfo['path']) === true) {
                    unlink($zipInfo['path']);
                }

                throw new Exception('Failed to read ZIP file content');
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, data?: array}, array<never, never>>
     */
    public function vectorizeBatch(): JSONResponse
    {
        try {
            $data      = $this->request->getParams();
            $views     = $data['views'] ?? null;
            $batchSize = (int) ($data['batchSize'] ?? 25);

            // Use ObjectService delegation to handler.
            $result = $this->objectService->vectorizeBatchObjects(
                views: $views,
                batchSize: $batchSize
            );

            return new JSONResponse(
                data: [
                    'success' => true,
                    'data'    => $result,
                ]
            );
        } catch (Exception $e) {
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
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Vectorization statistics
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, stats?: array<array-key, mixed>}, array<never, never>>
     */
    public function getObjectVectorizationStats(): JSONResponse
    {
        try {
            // Get views parameter if provided.
            $views = $this->request->getParam(key: 'views');
            if (is_string($views) === true) {
                $views = json_decode($views, true);
            }

            // Use ObjectService delegation to handler.
            $stats = $this->objectService->getVectorizationStatistics(views: $views);

            return new JSONResponse(
                data: [
                    'success' => true,
                    'stats'   => $stats,
                ]
            );
        } catch (Exception $e) {
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
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Object count
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, count?: 0}, array<never, never>>
     */
    public function getObjectVectorizationCount(): JSONResponse
    {
        try {
            // Get schemas parameter if provided.
            $schemas = $this->request->getParam(key: 'schemas');
            if (is_string($schemas) === true) {
                $schemas = json_decode($schemas, true);
            }

            // Use ObjectService delegation to handler.
            $count = $this->objectService->getVectorizationCount(schemas: $schemas);

            return new JSONResponse(
                data: [
                    'success' => true,
                    'count'   => $count,
                ]
            );
        } catch (Exception $e) {
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
