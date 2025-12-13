<?php
/**
 * OpenRegister Applications Controller
 *
 * This file contains the controller for managing applications.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ApplicationService;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * ApplicationsController handles REST API endpoints for application management
 *
 * Provides REST API endpoints for managing applications including CRUD operations,
 * pagination, filtering, and error handling.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git-id>
 *
 * @link     https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class ApplicationsController extends Controller
{

    /**
     * Application service for business logic
     *
     * Handles application business logic, validation, and service layer operations.
     *
     * @var ApplicationService Application service instance
     */
    private readonly ApplicationService $applicationService;

    /**
     * Application mapper for direct database operations
     *
     * Used for direct database queries when needed, bypassing service layer.
     *
     * @var ApplicationMapper Application mapper instance
     */
    private readonly ApplicationMapper $applicationMapper;

    /**
     * Logger for debugging and error tracking
     *
     * Used for logging errors, debug information, and operation tracking.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;


    /**
     * Constructor
     *
     * Initializes controller with required dependencies for application operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string             $appName            Application name
     * @param IRequest           $request            HTTP request object
     * @param ApplicationService $applicationService Application service for business logic
     * @param ApplicationMapper  $applicationMapper  Application mapper for database operations
     * @param LoggerInterface    $logger             Logger for error tracking
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ApplicationService $applicationService,
        ApplicationMapper $applicationMapper,
        LoggerInterface $logger
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);

        // Store dependencies for use in controller methods.
        $this->applicationService = $applicationService;
        $this->applicationMapper  = $applicationMapper;
        $this->logger            = $logger;
    }//end __construct()


    /**
     * Render the Applications page
     *
     * Returns the template for the main applications page.
     * All routing is handled client-side by the SPA.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return TemplateResponse Template response for applications SPA
     *
     * @psalm-return TemplateResponse<200, array<never, never>>
     */
    public function page(): TemplateResponse
    {
        // Return SPA template response (routing handled client-side).
        return new TemplateResponse(
            appName: 'openregister',
            templateName: 'index',
            params: []
        );
    }//end page()


    /**
     * Get all applications
     *
     * Retrieves a list of all applications with optional pagination and filtering.
     * Supports limit/offset pagination or page-based pagination.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array{error?: 'Failed to retrieve applications', results?: array<\OCA\OpenRegister\Db\Application>}, array<never, never>>
     */
    public function index(): JSONResponse
    {
        try {
            // Get all request parameters.
            $params = $this->request->getParams();

            // Extract pagination and search parameters.
            $limit  = $this->extractLimit($params);
            $offset = $this->extractOffset($params);
            $page   = $this->extractPage($params);

            // Convert page to offset if provided (page-based pagination).
            if ($page !== null && $limit !== null) {
                $offset = ($page - 1) * $limit;
            }

            // Remove special query params from filters (keep only application fields).
            $filters = $params;
            unset(
                $filters['_limit'],
                $filters['_offset'],
                $filters['_page'],
                $filters['_search'],
                $filters['_route']
            );

            // Retrieve applications using mapper with pagination and filters.
            $applications = $this->applicationMapper->findAll(
                limit: $limit,
                offset: $offset,
                filters: $filters
            );

            // Return successful response with applications list.
            return new JSONResponse(
                data: ['results' => $applications],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            // Log error with full context.
            $this->logger->error(
                message: 'Failed to get applications',
                context: [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response.
            return new JSONResponse(
                data: ['error' => 'Failed to retrieve applications'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()


    /**
     * Get a single application
     *
     * Retrieves a specific application by its database ID.
     * Returns application details or error if not found.
     *
     * @param int $id Application database ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Application, array<never, never>>|JSONResponse<404, array{error: 'Application not found'}, array<never, never>>
     */
    public function show(int $id): JSONResponse
    {
        try {
            // Retrieve application using service layer.
            $application = $this->applicationService->find($id);

            // Return successful response with application data.
            return new JSONResponse(
                data: $application,
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            // Log error with application ID.
            $this->logger->error(
                message: 'Failed to get application',
                context: [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            // Return not found error response.
            return new JSONResponse(
                data: ['error' => 'Application not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end show()


    /**
     * Create a new application
     *
     * Creates a new application entity from request data.
     * Validates input and returns created application or error.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<201, \OCA\OpenRegister\Db\Application, array<never, never>>|JSONResponse<400, array{error: string}, array<never, never>>
     */
    public function create(): JSONResponse
    {
        try {
            // Get request data and remove internal route parameter.
            $data = $this->request->getParams();
            unset($data['_route']);

            // Create application using service layer.
            $application = $this->applicationService->create($data);

            // Return successful response with created application.
            return new JSONResponse(
                data: $application,
                statusCode: Http::STATUS_CREATED
            );
        } catch (Exception $e) {
            // Log error with full context.
            $this->logger->error(
                message: 'Failed to create application',
                context: [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Return error response with message.
            return new JSONResponse(
                data: ['error' => 'Failed to create application: '.$e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()


    /**
     * Update an existing application
     *
     * Updates an existing application entity with new data.
     * Prevents modification of immutable fields (id, organisation, owner, created).
     *
     * @param int $id Application database ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Application, array<never, never>>|JSONResponse<400, array{error: string}, array<never, never>>
     */
    public function update(int $id): JSONResponse
    {
        try {
            // Get request data.
            $data = $this->request->getParams();

            // Remove internal parameters and immutable fields.
            unset($data['_route']);
            unset($data['id']);
            unset($data['organisation']);
            unset($data['owner']);
            unset($data['created']);

            $application = $this->applicationService->update(id: $id, data: $data);

            // Return successful response with updated application.
            return new JSONResponse(
                data: $application,
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            // Log error with application ID.
            $this->logger->error(
                message: 'Failed to update application',
                context: [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            // Return error response with message.
            return new JSONResponse(
                data: ['error' => 'Failed to update application: '.$e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end update()


    /**
     * Patch (partially update) an application
     *
     * Partially updates an application entity (PATCH method).
     * Delegates to update() method which handles partial updates.
     *
     * @param int $id The ID of the application to patch
     *
     * @return JSONResponse JSON response containing updated application or error message
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<mixed|array{error: string}>
     */
    public function patch(int $id): JSONResponse
    {
        // Delegate to update method (both handle partial updates).
        return $this->update($id);
    }//end patch()


    /**
     * Delete an application
     *
     * Deletes an application entity by ID.
     * Returns success message or error if deletion fails.
     *
     * @param int $id Application database ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400, array{error?: 'Failed to delete application', message?: 'Application deleted successfully'}, array<never, never>>
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            // Delete application using service layer.
            $this->applicationService->delete($id);

            // Return successful response.
            return new JSONResponse(
                data: ['message' => 'Application deleted successfully'],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            // Log error with application ID.
            $this->logger->error(
                message: 'Failed to delete application',
                context: [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            // Return error response.
            return new JSONResponse(
                data: ['error' => 'Failed to delete application'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end destroy()


    /**
     * Extract limit parameter from request params
     *
     * Extracts the _limit parameter from request parameters and converts to integer.
     * Returns null if parameter is not present.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return int|null Limit value or null if not provided
     *
     * @psalm-return int|null
     */
    private function extractLimit(array $params): ?int
    {
        // Check if _limit parameter exists and extract as integer.
        if (($params['_limit'] ?? null) !== null) {
            return (int) $params['_limit'];
        }

        // Return null if parameter not provided.
        return null;
    }//end extractLimit()

    /**
     * Extract offset parameter from request params
     *
     * Extracts the _offset parameter from request parameters and converts to integer.
     * Returns null if parameter is not present.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return int|null Offset value or null if not provided
     *
     * @psalm-return int|null
     */
    private function extractOffset(array $params): ?int
    {
        // Check if _offset parameter exists and extract as integer.
        if (($params['_offset'] ?? null) !== null) {
            return (int) $params['_offset'];
        }

        // Return null if parameter not provided.
        return null;
    }//end extractOffset()

    /**
     * Extract page parameter from request params
     *
     * Extracts the _page parameter from request parameters and converts to integer.
     * Returns null if parameter is not present.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return int|null Page value or null if not provided
     *
     * @psalm-return int|null
     */
    private function extractPage(array $params): ?int
    {
        // Check if _page parameter exists and extract as integer.
        if (($params['_page'] ?? null) !== null) {
            return (int) $params['_page'];
        }

        // Return null if parameter not provided.
        return null;
    }//end extractPage()


}//end class
