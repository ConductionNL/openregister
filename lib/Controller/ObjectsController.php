<?php
/**
 * Class ObjectsController
 *
 * Controller for managing object operations in the OpenRegister app.
 * Provides CRUD functionality for objects within registers and schemas.
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
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
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCP\AppFramework\Http\DataDownloadResponse;
/**
 * Class ObjectsController
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
        ImportService $importService
    ) {
        parent::__construct($appName, $request);
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
        // @todo: this is a hack to ensure the pagination is correct when the total is not known. That sugjest that the underlaying count service has a problem that needs to be fixed instead
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
                $nextUrl .= (strpos($nextUrl, '?') === false ? '?' : '&').'page='.$nextPage;
            }

            $paginatedResults['next'] = $nextUrl;
        }

        // Add previous page link if not on first page.
        if ($page > 1) {
            $prevPage = $page - 1;
            $prevUrl  = preg_replace('/([?&])page=\d+/', '$1page='.$prevPage, $currentUrl);
            if (strpos($prevUrl, 'page=') === false) {
                $prevUrl .= (strpos($prevUrl, '?') === false ? '?' : '&').'page='.$prevPage;
            }

            $paginatedResults['prev'] = $prevUrl;
        }

        return $paginatedResults;

    }//end paginate()





    /**
     * Helper method to get configuration array from the current request (LEGACY)
     *
     * @deprecated Use buildSearchQuery() instead for faceting-enabled endpoints
     *
     * @param string|null $register Optional register identifier
     * @param string|null $schema   Optional schema identifier
     * @param array|null  $ids      Optional array of specific IDs to filter
     *
     * @return array Configuration array containing:
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
        $limit  = (int) ($params['limit'] ?? $params['_limit'] ?? 20);
        $offset = isset($params['offset']) ? (int) $params['offset'] : (isset($params['_offset']) ? (int) $params['_offset'] : null);
        $page   = isset($params['page']) ? (int) $params['page'] : (isset($params['_page']) ? (int) $params['_page'] : null);

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
     * @param  string        $register      Register slug or ID
     * @param  string        $schema        Schema slug or ID
     * @param  ObjectService $objectService Object service instance
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
            // STEP 1: Initial resolution - convert slugs/IDs to numeric IDs
            $objectService->setRegister($register);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // If register not found, throw custom exception
            throw new \OCA\OpenRegister\Exception\RegisterNotFoundException($register, 404, $e);
        }

        try {
            $objectService->setSchema($schema);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // If schema not found, throw custom exception
            throw new \OCA\OpenRegister\Exception\SchemaNotFoundException($schema, 404, $e);
        }

        // STEP 2: Get resolved numeric IDs
        $resolvedRegisterId = $objectService->getRegister();
        $resolvedSchemaId   = $objectService->getSchema();
        // STEP 3: Reset ObjectService with resolved numeric IDs
        // This ensures the entire pipeline works with IDs consistently
        $objectService->setRegister((string) $resolvedRegisterId)->setSchema((string) $resolvedSchemaId);
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
            // Resolve slugs to numeric IDs consistently
            $resolved = $this->resolveRegisterSchemaIds($register, $schema, $objectService);
        } catch (\OCA\OpenRegister\Exception\RegisterNotFoundException | \OCA\OpenRegister\Exception\SchemaNotFoundException $e) {
            // Return 404 with clear error message if register or schema not found
            return new JSONResponse(['message' => $e->getMessage()], 404);
        }

        // Build search query with resolved numeric IDs
        $query = $objectService->buildSearchQuery($this->request->getParams(), $resolved['register'], $resolved['schema']);
        
        // Extract filtering parameters from request
        $params = $this->request->getParams();
        $rbac = filter_var($params['rbac'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $multi = filter_var($params['multi'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $published = filter_var($params['_published'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $deleted = filter_var($params['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        // **INTELLIGENT SOURCE SELECTION**: ObjectService automatically chooses optimal source
        $result = $objectService->searchObjectsPaginated($query, $rbac, $multi, $published, $deleted);
        
        
        // **SUB-SECOND OPTIMIZATION**: Enable response compression for large payloads
        $response = new JSONResponse($result);
        
        // Enable gzip compression for responses > 1KB
        if (isset($result['results']) && count($result['results']) > 10) {
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
        // Build search query without register/schema constraints
        $query = $objectService->buildSearchQuery($this->request->getParams());

        // **INTELLIGENT SOURCE SELECTION**: ObjectService automatically chooses optimal source
        $result = $objectService->searchObjectsPaginated($query);

        return new JSONResponse($result);

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
     */
    public function show(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        try {
            // DEBUG: Add unique identifier to response
            $debugInfo = [
                'DEBUG_CONTROLLER' => 'OpenRegister_ObjectsController',
                'DEBUG_PARAMS' => ['register' => $register, 'schema' => $schema, 'id' => $id]
            ];
            
            // Resolve slugs to numeric IDs consistently
            $resolved = $this->resolveRegisterSchemaIds($register, $schema, $objectService);
        } catch (\OCA\OpenRegister\Exception\RegisterNotFoundException | \OCA\OpenRegister\Exception\SchemaNotFoundException $e) {
            // Return 404 with clear error message if register or schema not found
            return new JSONResponse(['message' => $e->getMessage()], 404);
        }

        // Get request parameters for filtering and searching.
        $requestParams = $this->request->getParams();

        // Extract parameters for rendering.
        $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? null);
        $filter = ($requestParams['filter'] ?? $requestParams['_filter'] ?? null);
        $fields = ($requestParams['fields'] ?? $requestParams['_fields'] ?? null);
        $unset = ($requestParams['unset'] ?? $requestParams['_unset'] ?? null);

        // Convert extend to array if it's a string
        if (is_string($extend)) {
            $extend = explode(',', $extend);
        }

        // Convert fields to array if it's a string
        if (is_string($fields)) {
            $fields = explode(',', $fields);
        }

        // Convert filter to array if it's a string 
        if (is_string($filter)) {
            $filter = explode(',', $filter);
        }

        // Convert unset to array if it's a string
        if (is_string($unset)) {
            $unset = explode(',', $unset);
        }

        // Determine RBAC and multitenancy settings based on admin status
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC
        $multi = !$isAdmin;
        // If admin, disable multitenancy
        // Find and validate the object.
        try {
            $objectEntity = $this->objectService->find($id, $extend, false, null, null, $rbac, $multi);

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

            // Add debug info to response
            $renderedObject['DEBUG_INFO'] = $debugInfo;

            return new JSONResponse($renderedObject);
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
            // Resolve slugs to numeric IDs consistently
            $resolved = $this->resolveRegisterSchemaIds($register, $schema, $objectService);
        } catch (\OCA\OpenRegister\Exception\RegisterNotFoundException | \OCA\OpenRegister\Exception\SchemaNotFoundException $e) {
            // Return 404 with clear error message if register or schema not found
            return new JSONResponse(['message' => $e->getMessage()], 404);
        }

        // Get object data from request parameters.
        $object = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        // @todo shouldn't this be part of the object service?
        // Allow @self metadata to pass through for organization activation
        $object = array_filter(
            $object,
            fn ($key) => !str_starts_with($key, '_')
                && !($key !== '@self' && str_starts_with($key, '@'))
                && !in_array($key, ['uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Determine RBAC and multitenancy settings based on admin status
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC
        $multi = !$isAdmin;
        // If admin, disable multitenancy
        // Save the object.
        try {
            // Use the object service to validate and save the object.
            $objectEntity = $objectService->saveObject(
                object: $object,
                rbac: $rbac,
                multi: $multi
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
            // Handle all other exceptions (including RBAC permission errors)
            return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
        }//end try

        // Return the created object.
        return new JSONResponse($objectEntity->jsonSerialize());

    }//end create()


    /**
     * Updates an existing object
     *
     * Takes the request data, validates it against the schema, and updates an existing object
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
            // Resolve slugs to numeric IDs consistently
            $resolved = $this->resolveRegisterSchemaIds($register, $schema, $objectService);
        } catch (\OCA\OpenRegister\Exception\RegisterNotFoundException | \OCA\OpenRegister\Exception\SchemaNotFoundException $e) {
            // Return 404 with clear error message if register or schema not found
            return new JSONResponse(['message' => $e->getMessage()], 404);
        }

        // Get object data from request parameters.
        $object = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        // @todo shouldn't this be part of the object service?
        // Allow @self metadata to pass through for organization activation
        $object = array_filter(
            $object,
            fn ($key) => !str_starts_with($key, '_')
                && !($key !== '@self' && str_starts_with($key, '@'))
                && !in_array($key, ['uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Determine RBAC and multitenancy settings based on admin status
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC
        $multi = !$isAdmin;
        // If admin, disable multitenancy
        // Check if the object exists and can be updated (silent read - no audit trail).
        // @todo shouldn't this be part of the object service?
        try {
            $existingObject = $this->objectService->findSilent($id, [], false, null, null, $rbac, $multi);

            // Get the resolved register and schema IDs from the ObjectService
            // This ensures proper handling of both numeric IDs and slug identifiers
            $resolvedRegisterId = $objectService->getRegister();
            // Returns the current register ID
            $resolvedSchemaId = $objectService->getSchema();
            // Returns the current schema ID
            // Verify that the object belongs to the specified register and schema.
            if ((int) $existingObject->getRegister() !== (int) $resolvedRegisterId
                || (int) $existingObject->getSchema() !== (int) $resolvedSchemaId
            ) {
                return new JSONResponse(
                    ['error' => 'Object not found in specified register/schema'],
                    404
                );
            }

            // Check if the object is locked.
            if ($existingObject->isLocked() === true
                && $existingObject->getLockedBy() !== $this->container->get('userId')
            ) {
                // Return a "locked" error with the user who has the lock.
                return new JSONResponse(
                    [
                        'error'    => 'Object is locked by '.$existingObject->getLockedBy(),
                        'lockedBy' => $existingObject->getLockedBy(),
                    ],
                    423
                );
            }
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Not Found'], 404);
        } catch (\Exception $exception) {
            // Handle RBAC permission errors and other exceptions
            return new JSONResponse(['error' => $exception->getMessage()], 403);
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface $e) {
            // If there's an issue getting the user ID, continue without the lock check.
        }//end try

        // Update the object.
        try {
            // Use the object service to validate and update the object.
            $objectEntity = $objectService->saveObject(
                object: $object,
                uuid: $id,
                rbac: $rbac,
                multi: $multi
            );

            // Unlock the object after saving.
            try {
                $this->objectEntityMapper->unlockObject($objectEntity->getId());
            } catch (\Exception $e) {
                // Ignore unlock errors since the update was successful.
            }

            // Return the updated object as JSON.
            return new JSONResponse($objectEntity->jsonSerialize());
        } catch (ValidationException | CustomValidationException $exception) {
            // Handle validation errors.
            return $objectService->handleValidationException(exception: $exception);
        } catch (\Exception $exception) {
            // Handle all other exceptions (including RBAC permission errors)
            return new JSONResponse(['error' => $exception->getMessage()], 403);
        }//end try

    }//end update()


    /**
     * Patches (partially updates) an existing object
     *
     * Takes the request data, merges it with the existing object data, validates it against
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
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get patch data from request parameters.
        $patchData = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        // @todo shouldn't this be part of the object service?
        // Allow @self metadata to pass through for organization activation
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
            $existingObject = $this->objectService->find($id);

            // Get the resolved register and schema IDs from the ObjectService
            // This ensures proper handling of both numeric IDs and slug identifiers
            $resolvedRegisterId = $objectService->getRegister();
            // Returns the current register ID
            $resolvedSchemaId = $objectService->getSchema();
            // Returns the current schema ID
            // Verify that the object belongs to the specified register and schema.
            if ((int) $existingObject->getRegister() !== (int) $resolvedRegisterId
                || (int) $existingObject->getSchema() !== (int) $resolvedSchemaId
            ) {
                return new JSONResponse(
                    ['error' => 'Object not found in specified register/schema'],
                    404
                );
            }

            // Check if the object is locked.
            if ($existingObject->isLocked() === true
                && $existingObject->getLockedBy() !== $this->container->get('userId')
            ) {
                // Return a "locked" error with the user who has the lock.
                return new JSONResponse(
                    [
                        'error'    => 'Object is locked by '.$existingObject->getLockedBy(),
                        'lockedBy' => $existingObject->getLockedBy(),
                    ],
                    423
                );
            }

            // Get the existing object data and merge with patch data
            $existingData = $existingObject->getObject();
            $mergedData   = array_merge($existingData, $patchData);
            $existingObject->setObject($mergedData);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Not Found'], 404);
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
            return new JSONResponse($objectEntity->jsonSerialize());
        } catch (ValidationException | CustomValidationException $exception) {
            // Handle validation errors.
            return $objectService->handleValidationException(exception: $exception);
        } catch (\Exception $exception) {
            // Handle all other exceptions (including RBAC permission errors)
            return new JSONResponse(['error' => $exception->getMessage()], 403);
        }

    }//end patch()


    /**
     * Deletes an object
     *
     * This method deletes an object based on its ID.
     *
     * @param  int           $id            The ID of the object to delete
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
            // Set the register and schema context for ObjectService
            $objectService->setRegister($register);
            $objectService->setSchema($schema);

            // Determine RBAC and multitenancy settings based on admin status
            $isAdmin = $this->isCurrentUserAdmin();
            $rbac    = !$isAdmin;
            // If admin, disable RBAC
            $multi = !$isAdmin;
            // If admin, disable multitenancy

            // Use ObjectService to delete the object (includes RBAC permission checks, audit trail, and soft delete)
            $deleteResult = $objectService->deleteObject($id, $rbac, $multi);

            if (!$deleteResult) {
                // If delete operation failed, return error
                return new JSONResponse(['error' => 'Failed to delete object'], 500);
            }

            // Return 204 No Content for successful delete (REST convention)
            return new JSONResponse(null, 204);
        } catch (\Exception $exception) {
            // Handle all exceptions (including RBAC permission errors and object not found)
            return new JSONResponse(['error' => $exception->getMessage()], 403);
        }//end try

    }//end destroy()


    /**
     * Retrieves call logs for a object
     *
     * This method returns all the call logs associated with a object based on its ID.
     *
     * @param int           $id            The ID of the object to retrieve logs for
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
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get request parameters for filtering and searching.
        $requestParams = $this->request->getParams();

        // Extract specific parameters.
        $limit  = (int) ($requestParams['limit'] ?? $requestParams['_limit'] ?? 20);
        $offset = isset($requestParams['offset']) ? (int) $requestParams['offset'] : (isset($requestParams['_offset']) ? (int) $requestParams['_offset'] : null);
        $page   = isset($requestParams['page']) ? (int) $requestParams['page'] : (isset($requestParams['_page']) ? (int) $requestParams['_page'] : null);

        // Return empty paginated response
        return new JSONResponse(
            $this->paginate(
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
        $objectService->setRegister($register);
        $objectService->setSchema($schema);

        // Get the relations for the object.
        $relationsArray = $objectService->find($id)->getRelations();
        $relations      = array_values($relationsArray);

        // Build search query using ObjectService searchObjectsPaginated directly
        $queryParams = $this->request->getParams();
        $searchQuery = $queryParams;
        
        // Clean up unwanted parameters
        unset($searchQuery['id'], $searchQuery['_route']);
        
        // Use ObjectService searchObjectsPaginated directly - pass ids as named parameter
        $result = $objectService->searchObjectsPaginated(
            query: $searchQuery, 
            rbac: true, 
            multi: true, 
            published: true, 
            deleted: false,
            ids: $relations
        );
        
        // Add relations being searched for debugging
        $result['relations'] = $relations;

        // Return the result directly from ObjectService
        return new JSONResponse($result);

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
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Build search query using ObjectService searchObjectsPaginated directly
        $queryParams = $this->request->getParams();
        $searchQuery = $queryParams;
        
        // Clean up unwanted parameters
        unset($searchQuery['id'], $searchQuery['_route']);
        
        // Use ObjectService searchObjectsPaginated directly - pass uses as named parameter
        $result = $objectService->searchObjectsPaginated(
            query: $searchQuery, 
            rbac: true, 
            multi: true, 
            published: true, 
            deleted: false,
            uses: $id
        );
        
        // Add what we're searching for in debugging
        $result['uses'] = $id;

        // Return the result directly from ObjectService
        return new JSONResponse($result);

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
        $objectService->setRegister($register);
        $objectService->setSchema($schema);

        // Try to fetch the object by ID/UUID only (no register/schema filter yet)
        try {
            $object = $objectService->find($id);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => 'Object not found'], 404);
        }

        // Normalize and compare register
        $objectRegister = $object->getRegister();
        // could be ID or slug
        $objectSchema = $object->getSchema();
        // could be ID, slug, or array/object
        // Normalize requested register
        $requestedRegister = $register;
        $requestedSchema   = $schema;

        // If objectSchema is an array/object, get slug and id
        $objectSchemaId   = null;
        $objectSchemaSlug = null;
        if (is_array($objectSchema) && isset($objectSchema['id'])) {
            $objectSchemaId   = (string) $objectSchema['id'];
            $objectSchemaSlug = isset($objectSchema['slug']) ? strtolower($objectSchema['slug']) : null;
        } else if (is_object($objectSchema) && isset($objectSchema->id)) {
            $objectSchemaId   = (string) $objectSchema->id;
            $objectSchemaSlug = isset($objectSchema->slug) ? strtolower($objectSchema->slug) : null;
        } else {
            $objectSchemaId = (string) $objectSchema;
        }

        // Normalize requested schema
        $requestedSchemaNorm  = strtolower((string) $requestedSchema);
        $objectSchemaIdNorm   = strtolower((string) $objectSchemaId);
        $objectSchemaSlugNorm = $objectSchemaSlug ? strtolower($objectSchemaSlug) : null;

        // Check schema match (by id or slug)
        $schemaMatch = (
            $requestedSchemaNorm === $objectSchemaIdNorm ||
            ($objectSchemaSlugNorm && $requestedSchemaNorm === $objectSchemaSlugNorm)
        );

        // Register normalization (string compare)
        $objectRegisterNorm    = strtolower((string) $objectRegister);
        $requestedRegisterNorm = strtolower((string) $requestedRegister);
        $registerMatch         = ($objectRegisterNorm === $requestedRegisterNorm);

        if (!$schemaMatch || !$registerMatch) {
            return new JSONResponse(['message' => 'Object does not belong to specified register/schema'], 404);
        }

        // Get config and fetch logs.
        $config = $this->getConfig($register, $schema);
        $logs   = $objectService->getLogs($id, $config['filters']);

        // Get total count of logs.
        $total = count($logs);

        // Return paginated results
        return new JSONResponse($this->paginate($logs, $total, $config['limit'], $config['offset'], $config['page']));

    }//end logs()


    /**
     * Lock an object
     *
     * @param int $id The ID of the object to lock
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
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        $data    = $this->request->getParams();
        $process = ($data['process'] ?? null);
        // Check if duration is set in the request data.
        $duration = null;
        if (isset($data['duration']) === true) {
            $duration = (int) $data['duration'];
        }

        $object = $this->objectEntityMapper->lockObject(
            $id,
            $process,
            $duration
        );

        return new JSONResponse($object);

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
        $this->objectService->setRegister($register);
        $this->objectService->setSchema($schema);
        $this->objectService->unlockObject($id);
        return new JSONResponse(['message' => 'Object unlocked successfully']);

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
        // Set the register and schema context
        $objectService->setRegister($register);
        $objectService->setSchema($schema);

        // Get filters and type from request
        $filters = $this->request->getParams();
        unset($filters['_route']);
        $type = $this->request->getParam(key: 'type', default: 'excel');

        // Get register and schema entities
        $registerEntity = $this->registerMapper->find($register);
        $schemaEntity   = $this->schemaMapper->find($schema);

        // Handle different export types
        switch ($type) {
            case 'csv':
                $csv = $this->exportService->exportToCsv($registerEntity, $schemaEntity, $filters, $this->userSession->getUser());

                // Generate filename
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
                $spreadsheet = $this->exportService->exportToExcel($registerEntity, $schemaEntity, $filters, $this->userSession->getUser());

                // Create Excel writer
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

                // Generate filename
                $filename = sprintf(
                    '%s_%s_%s.xlsx',
                    $registerEntity->getSlug(),
                    $schemaEntity->getSlug(),
                    (new \DateTime())->format('Y-m-d_His')
                );

                // Get Excel content
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
            // Get the uploaded file
            $uploadedFile = $this->request->getUploadedFile('file');
            if ($uploadedFile === null) {
                return new JSONResponse(['error' => 'No file uploaded'], 400);
            }

            // Find the register
            $registerEntity = $this->registerMapper->find($register);

            // Determine file type from extension
            $filename  = $uploadedFile['name'];
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Handle different file types
            switch ($extension) {
                case 'xlsx':
                case 'xls':

                    // Get optional validation and events parameters
                    $validation = filter_var($this->request->getParam('validation', false), FILTER_VALIDATE_BOOLEAN);
                    $events     = filter_var($this->request->getParam('events', false), FILTER_VALIDATE_BOOLEAN);

                    $summary = $this->importService->importFromExcel(
                        $uploadedFile['tmp_name'],
                        $registerEntity,
                        null, // Schema will be determined from sheet names
                        5, // Use default chunk size
                        $validation,
                        $events,
                        true, // rbac
                        true, // multi
                        false, // publish
                        $this->userSession->getUser()
                    );
                    break;

                case 'csv':

                    // For CSV, schema can be specified in the request
                    $schemaId = $this->request->getParam(key: 'schema');

                    if (!$schemaId) {
                        // If no schema specified, get the first available schema from the register
                        $schemas = $registerEntity->getSchemas();
                        if (empty($schemas)) {
                            return new JSONResponse(['error' => 'No schema found for register'], 400);
                        }

                        $schemaId = is_array($schemas) ? reset($schemas) : $schemas;
                    }

                    $schema = $this->schemaMapper->find($schemaId);

                    // Get optional parameters with sensible defaults
                    $validation = filter_var($this->request->getParam('validation', false), FILTER_VALIDATE_BOOLEAN);
                    $events     = filter_var($this->request->getParam('events', false), FILTER_VALIDATE_BOOLEAN);
                    $rbac       = filter_var($this->request->getParam('rbac', true), FILTER_VALIDATE_BOOLEAN);
                    $multi      = filter_var($this->request->getParam('multi', true), FILTER_VALIDATE_BOOLEAN);
                    $chunkSize  = (int) $this->request->getParam('chunkSize', 5);

                    $summary = $this->importService->importFromCsv(
                        $uploadedFile['tmp_name'],
                        $registerEntity,
                        $schema,
                        $chunkSize,
                        $validation,
                        $events,
                        $rbac,
                        $multi
                    );
                    break;

                default:

                    return new JSONResponse(['error' => "Unsupported file type: $extension"], 400);
            }//end switch

            return new JSONResponse(
                    [
                        'message' => 'Import successful',
                        'summary' => $summary,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
        // Set the schema and register to the object service
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Determine RBAC and multitenancy settings based on admin status
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC
        $multi = !$isAdmin;
        // If admin, disable multitenancy
        try {
            // Get the publication date from request if provided
            $date = null;
            if ($this->request->getParam(key: 'date') !== null) {
                $date = new \DateTime($this->request->getParam(key: 'date'));
            }

            // Publish the object
            $object = $objectService->publish($id, $date, $rbac, $multi);

            return new JSONResponse($object->jsonSerialize());
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
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
        // Set the schema and register to the object service
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Determine RBAC and multitenancy settings based on admin status
        $isAdmin = $this->isCurrentUserAdmin();
        $rbac    = !$isAdmin;
        // If admin, disable RBAC
        $multi = !$isAdmin;
        // If admin, disable multitenancy
        try {
            // Get the depublication date from request if provided
            $date = null;
            if ($this->request->getParam(key: 'date') !== null) {
                $date = new \DateTime($this->request->getParam(key: 'date'));
            }

            // Depublish the object
            $object = $objectService->depublish($id, $date, $rbac, $multi);

            return new JSONResponse($object->jsonSerialize());
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
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
        // Set the schema and register to the object service
        $objectService->setRegister($register);
        $objectService->setSchema($schema);

        try {
            // Get merge data from request body
            $requestParams = $this->request->getParams();

            // Validate required parameters
            if (!isset($requestParams['target'])) {
                return new JSONResponse(['error' => 'Target object ID is required'], 400);
            }

            if (!isset($requestParams['object']) || empty($requestParams['object'])) {
                return new JSONResponse(['error' => 'Object data is required'], 400);
            }

            // Perform the merge operation with the new payload structure
            $mergeResult = $objectService->mergeObjects($id, $requestParams);
            return new JSONResponse($mergeResult);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\Exception $exception) {
            return new JSONResponse(
                    [
                        'error' => 'Failed to merge objects: '.$exception->getMessage(),
                    ],
                    500
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
            // Get migration parameters from request
            $requestParams  = $this->request->getParams();
            $sourceRegister = $requestParams['sourceRegister'] ?? null;
            $sourceSchema   = $requestParams['sourceSchema'] ?? null;
            $targetRegister = $requestParams['targetRegister'] ?? null;
            $targetSchema   = $requestParams['targetSchema'] ?? null;
            $objectIds      = $requestParams['objects'] ?? [];
            $mapping        = $requestParams['mapping'] ?? [];

            // Validate required parameters
            if ($sourceRegister === null || $sourceSchema === null) {
                return new JSONResponse(['error' => 'Source register and schema are required'], 400);
            }

            if ($targetRegister === null || $targetSchema === null) {
                return new JSONResponse(['error' => 'Target register and schema are required'], 400);
            }

            if (empty($objectIds)) {
                return new JSONResponse(['error' => 'At least one object ID is required'], 400);
            }

            if (empty($mapping)) {
                return new JSONResponse(['error' => 'Property mapping is required'], 400);
            }

            // Perform the migration operation
            $migrationResult = $objectService->migrateObjects(
                sourceRegister: $sourceRegister,
                sourceSchema: $sourceSchema,
                targetRegister: $targetRegister,
                targetSchema: $targetSchema,
                objectIds: $objectIds,
                mapping: $mapping
            );

            return new JSONResponse($migrationResult);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Register or schema not found'], 404);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\Exception $exception) {
            return new JSONResponse(
                    [
                        'error' => 'Failed to migrate objects: '.$exception->getMessage(),
                    ],
                    500
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
            // Set the context for the object service
            $objectService->setRegister($register);
            $objectService->setSchema($schema);

            // Get the object to ensure it exists and we have access
            $object = $objectService->find($id);

            // Get the FileService from the container
            /*
             * @var FileService $fileService
             */
            $fileService = $this->container->get(FileService::class);

            // Optional: get custom filename from query parameters
            $customFilename = $this->request->getParam(key: 'filename');

            // Create the ZIP archive
            $zipInfo = $fileService->createObjectFilesZip($object, $customFilename);

            // Read the ZIP file content
            $zipContent = file_get_contents($zipInfo['path']);
            if ($zipContent === false) {
                // Clean up temporary file
                if (file_exists($zipInfo['path'])) {
                    unlink($zipInfo['path']);
                }

                throw new \Exception('Failed to read ZIP file content');
            }

            // Clean up temporary file after reading
            if (file_exists($zipInfo['path'])) {
                unlink($zipInfo['path']);
            }

            // Return the ZIP file as a download response
            return new DataDownloadResponse(
                $zipContent,
                $zipInfo['filename'],
                $zipInfo['mimeType']
            );
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\Exception $exception) {
            return new JSONResponse(
                    [
                        'error' => 'Failed to create ZIP file: '.$exception->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end downloadFiles()


}//end class
