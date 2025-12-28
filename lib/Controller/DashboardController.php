<?php

/**
 * OpenConnector Dashboard Controller
 *
 * This file contains the controller for handling dashboard related operations
 * in the OpenRegister application.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Service\DashboardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * DashboardController handles dashboard related operations
 *
 * Controller for handling dashboard related operations in the application.
 * Provides functionality to display the dashboard page and retrieve dashboard data
 * including registers, schemas, and statistics.
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
 */
class DashboardController extends Controller
{
    /**
     * The dashboard service instance
     *
     * Handles business logic for dashboard data retrieval and aggregation.
     *
     * @var DashboardService Dashboard service instance
     */
    private readonly DashboardService $dashboardService;

    /**
     * Logger instance
     *
     * Used for error tracking and debugging dashboard operations.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor for the DashboardController
     *
     * Initializes controller with required dependencies for dashboard operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string           $appName          The name of the app
     * @param IRequest         $request          The HTTP request object
     * @param DashboardService $dashboardService The dashboard service instance
     * @param LoggerInterface  $logger           Logger instance for error tracking
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        DashboardService $dashboardService,
        LoggerInterface $logger
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);

        // Store dependencies for use in controller methods.
        $this->dashboardService = $dashboardService;
        $this->logger           = $logger;
    }//end __construct()

    /**
     * Returns the template of the dashboard page
     *
     * Renders the dashboard page template with Content Security Policy configured
     * to allow API connections. Returns error template if rendering fails.
     *
     * @return TemplateResponse The rendered template response (or error template on failure)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return TemplateResponse<200, array<never, never>>
     */
    public function page(): TemplateResponse
    {
        try {
            // Create template response for dashboard page.
            $response = new TemplateResponse(
                appName: $this->appName,
                templateName: 'index',
                params: []
            );

            // Configure Content Security Policy to allow API connections.
            // This is necessary for the frontend to make API calls.
            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            // Return successful template response.
            return $response;
        } catch (\Exception $e) {
            // Return error template if rendering fails.
            return new TemplateResponse(
                appName: $this->appName,
                templateName: 'error',
                params: ['error' => $e->getMessage()],
                renderAs: '500'
            );
        }//end try
    }//end page()

    /**
     * Retrieves dashboard data including registers with their schemas
     *
     * Returns JSON response containing dashboard data with registers and schemas.
     * Supports optional filtering by registerId and schemaId query parameters.
     * Removes pagination and routing parameters before processing.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with dashboard data
     *
     * @psalm-return JSONResponse<200|500, array{error?: string, registers?: list{0: array{id: 'orphaned'|'totals'|int, title: null|string, description: null|string, stats: array{objects: array{total: int, size: int, invalid: int, deleted: int, locked: int, published: int}, logs: array{total: int|mixed, size: int|mixed}, files: array{total: int, size: int}, webhookLogs?: array{total: int, size: int}}, schemas: list<mixed>, uuid?: null|string, slug?: null|string, version?: null|string, source?: null|string, tablePrefix?: null|string, folder?: null|string, updated?: null|string, created?: null|string, owner?: null|string, application?: null|string, organisation?: null|string, authorization?: array|null, groups?: array<string, list<string>>, quota?: array{storage: null, bandwidth: null, requests: null, users: null, groups: null}, usage?: array{storage: 0, bandwidth: 0, requests: 0, users: 0, groups: int<0, max>}, deleted?: null|string, published?: null|string, depublished?: null|string}, 1?: array{id: 'orphaned'|'totals'|int, uuid: null|string, slug: null|string, title: null|string, version: null|string, description: null|string, schemas: list<mixed>, source: null|string, tablePrefix: null|string, folder: null|string, updated: null|string, created: null|string, owner: null|string, application: null|string, organisation: null|string, authorization: array|null, groups: array<string, list<string>>, quota: array{storage: null, bandwidth: null, requests: null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: 0, groups: int<0, max>}, deleted: null|string, published: null|string, depublished: null|string, stats: array{objects: array{total: int, size: int, invalid: int, deleted: int, locked: int, published: int}, logs: array{total: int|mixed, size: int|mixed}, files: array{total: int, size: int}, webhookLogs: array{total: int, size: int}}},...}}, array<never, never>>
     */
    public function index(): JSONResponse
    {
        try {
            // Get all request parameters.
            $params = $this->request->getParams();

            // Remove pagination and routing parameters that shouldn't be passed to service.
            // These are handled by the controller, not the business logic layer.
            unset($params['id'], $params['_route'], $params['limit'], $params['offset'], $params['page']);

            // Retrieve registers with schemas from dashboard service.
            // Optional filtering by registerId and schemaId if provided in query parameters.
            $registers = $this->dashboardService->getRegistersWithSchemas(
                registerId: $params['registerId'] ?? null,
                schemaId: $params['schemaId'] ?? null
            );

            // Return successful response with registers data.
            return new JSONResponse(data: ['registers' => $registers]);
        } catch (\Exception $e) {
            // Return error response if dashboard data retrieval fails.
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end index()

    /**
     * Calculate sizes for objects and logs
     *
     * Calculates storage sizes and statistics for objects and logs.
     * Supports optional filtering by registerId and schemaId to calculate
     * sizes for specific subsets of data.
     *
     * @param int|null $registerId Optional register ID to filter calculations by
     * @param int|null $schemaId   Optional schema ID to filter calculations by
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with calculation results
     *
     * @psalm-return JSONResponse<200|500, array{status: 'error'|'success', message?: string, timestamp: string, scope?: array{register: array{id: int, title: null|string}|null, schema: array{id: int, title: null|string}|null}, results?: array{objects: array, logs: array, total: array{processed: mixed, failed: mixed}}, summary?: array{total_processed: mixed, total_failed: mixed, success_rate: float}}, array<never, never>>
     */
    public function calculate(?int $registerId = null, ?int $schemaId = null): JSONResponse
    {
        try {
            // Calculate sizes and statistics using dashboard service.
            // Service handles aggregation of object and log sizes.
            $result = $this->dashboardService->calculate(registerId: $registerId, schemaId: $schemaId);

            // Return successful response with calculation results.
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            // Return error response with timestamp for debugging.
            return new JSONResponse(
                data: [
                    'status'    => 'error',
                    'message'   => $e->getMessage(),
                    'timestamp' => (new DateTime('now'))->format('c'),
                ],
                statusCode: 500
            );
        }
    }//end calculate()

    /**
     * Get chart data for audit trail actions
     *
     * @param string|null $from       Start date (Y-m-d format)
     * @param string|null $till       End date (Y-m-d format)
     * @param int|null    $registerId Optional register ID
     * @param int|null    $schemaId   Optional schema ID
     *
     * @return JSONResponse The chart data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getAuditTrailActionChart(?string $from = null, ?string $till = null, ?int $registerId = null, ?int $schemaId = null): JSONResponse
    {
        try {
            if ($from !== null) {
                $fromDate = new DateTime($from);
            } else {
                $fromDate = null;
            }

            if ($till !== null) {
                $tillDate = new DateTime($till);
            } else {
                $tillDate = null;
            }

            $data = $this->dashboardService->getAuditTrailActionChartData(from: $fromDate, till: $tillDate, registerId: $registerId, schemaId: $schemaId);
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getAuditTrailActionChart()

    /**
     * Get chart data for objects by register
     *
     * @param int|null $registerId Optional register ID
     * @param int|null $schemaId   Optional schema ID
     *
     * @return JSONResponse The chart data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getObjectsByRegisterChart(?int $registerId = null, ?int $schemaId = null): JSONResponse
    {
        try {
            $data = $this->dashboardService->getObjectsByRegisterChartData(registerId: $registerId, schemaId: $schemaId);
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getObjectsByRegisterChart()

    /**
     * Get chart data for objects by schema
     *
     * @param int|null $registerId Optional register ID
     * @param int|null $schemaId   Optional schema ID
     *
     * @return JSONResponse The chart data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getObjectsBySchemaChart(?int $registerId = null, ?int $schemaId = null): JSONResponse
    {
        try {
            $data = $this->dashboardService->getObjectsBySchemaChartData(registerId: $registerId, schemaId: $schemaId);
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getObjectsBySchemaChart()

    /**
     * Get chart data for objects by size distribution
     *
     * @param int|null $registerId Optional register ID
     * @param int|null $schemaId   Optional schema ID
     *
     * @return JSONResponse The chart data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getObjectsBySizeChart(?int $registerId = null, ?int $schemaId = null): JSONResponse
    {
        try {
            $data = $this->dashboardService->getObjectsBySizeChartData(registerId: $registerId, schemaId: $schemaId);
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getObjectsBySizeChart()

    /**
     * Get audit trail statistics for the dashboard sidebar
     *
     * @param int|null $registerId Optional register ID to filter by
     * @param int|null $schemaId   Optional schema ID to filter by
     * @param int|null $hours      Optional number of hours to look back for recent activity (default: 24)
     *
     * @return JSONResponse The statistics data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getAuditTrailStatistics(?int $registerId = null, ?int $schemaId = null, ?int $hours = 24): JSONResponse
    {
        try {
            $data = $this->dashboardService->getAuditTrailStatistics(registerId: $registerId, schemaId: $schemaId, hours: $hours);
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getAuditTrailStatistics()

    /**
     * Get action distribution data for audit trails
     *
     * @param int|null $registerId Optional register ID to filter by
     * @param int|null $schemaId   Optional schema ID to filter by
     * @param int|null $hours      Optional number of hours to look back (default: 24)
     *
     * @return JSONResponse The action distribution data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getAuditTrailActionDistribution(?int $registerId = null, ?int $schemaId = null, ?int $hours = 24): JSONResponse
    {
        try {
            $data = $this->dashboardService->getAuditTrailActionDistribution(registerId: $registerId, schemaId: $schemaId, hours: $hours);
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getAuditTrailActionDistribution()

    /**
     * Get most active objects based on audit trail activity
     *
     * @param int|null $registerId Optional register ID to filter by
     * @param int|null $schemaId   Optional schema ID to filter by
     * @param int|null $limit      Optional limit for number of results (default: 10)
     * @param int|null $hours      Optional number of hours to look back (default: 24)
     *
     * @return JSONResponse The most active objects data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getMostActiveObjects(?int $registerId = null, ?int $schemaId = null, ?int $limit = 10, ?int $hours = 24): JSONResponse
    {
        try {
            $data = $this->dashboardService->getMostActiveObjects(registerId: $registerId, schemaId: $schemaId, limit: $limit, hours: $hours);
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Error retrieving most active objects: ' . $e->getMessage(),
                context: [
                        'register_id' => $registerId,
                        'schema_id'   => $schemaId,
                        'limit'       => $limit,
                        'hours'       => $hours,
                        'trace'       => $e->getTraceAsString(),
                    ]
            );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve most active objects: ' . $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getMostActiveObjects()
}//end class
