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

use OCA\OpenRegister\Service\DashboardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Class DashboardController
 *
 * Controller for handling dashboard related operations in the application.
 * Provides functionality to display the dashboard page and retrieve dashboard data.
 *
 * @psalm-suppress UnusedClass - This controller is registered via routes.php and used by Nextcloud's routing system
 */
class DashboardController extends Controller
{

    /**
     * The dashboard service instance
     *
     * @var DashboardService
     */
    private DashboardService $dashboardService;

    /**
     * Logger instance
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor for the DashboardController
     *
     * @param string           $appName          The name of the app
     * @param IRequest         $request          The request object
     * @param DashboardService $dashboardService The dashboard service instance
     * @param LoggerInterface  $logger           Logger instance
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        DashboardService $dashboardService,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->dashboardService = $dashboardService;
        $this->logger           = $logger;

    }//end __construct()


    /**
     * Returns the template of the dashboard page
     *
     * This method renders the dashboard page of the application, adding any necessary data to the template.
     *
     * @return TemplateResponse The rendered template response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function page(): TemplateResponse
    {
        try {
            $response = new TemplateResponse(
                appName: $this->appName,
                templateName: 'index',
                params: []
            );

            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (\Exception $e) {
            return new TemplateResponse(
                appName: $this->appName,
                templateName: 'error',
                params: ['error' => $e->getMessage()],
                renderAs: '500'
            );
        }

    }//end page()


    /**
     * Retrieves dashboard data including registers with their schemas
     *
     * This method returns a JSON response containing dashboard data.
     *
     * @return JSONResponse A JSON response containing the dashboard data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        try {
            $params = $this->request->getParams();

            unset($params['id'], $params['_route'], $params['limit'], $params['offset'], $params['page']);

            $registers = $this->dashboardService->getRegistersWithSchemas(
                registerId: $params['registerId'] ?? null,
                schemaId: $params['schemaId'] ?? null
            );

            return new JSONResponse(data: ['registers' => $registers]);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end index()


    /**
     * Calculate sizes for objects and logs
     *
     * @param int|null $registerId Optional register ID to filter by
     * @param int|null $schemaId   Optional schema ID to filter by
     *
     * @return JSONResponse The calculation results
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function calculate(?int $registerId=null, ?int $schemaId=null): JSONResponse
    {
        try {
            $result = $this->dashboardService->calculate(registerId: $registerId, schemaId: $schemaId);
            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'status'    => 'error',
                    'message'   => $e->getMessage(),
                    'timestamp' => (new \DateTime('now'))->format('c'),
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
     * @NoCSRFRequired
     */
    public function getAuditTrailActionChart(?string $from=null, ?string $till=null, ?int $registerId=null, ?int $schemaId=null): JSONResponse
    {
        try {
            if ($from !== null) {
                $fromDate = new \DateTime($from);
            } else {
                $fromDate = null;
            }

            if ($till !== null) {
                $tillDate = new \DateTime($till);
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
     * @NoCSRFRequired
     */
    public function getObjectsByRegisterChart(?int $registerId=null, ?int $schemaId=null): JSONResponse
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
     * @NoCSRFRequired
     */
    public function getObjectsBySchemaChart(?int $registerId=null, ?int $schemaId=null): JSONResponse
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
     * @NoCSRFRequired
     */
    public function getObjectsBySizeChart(?int $registerId=null, ?int $schemaId=null): JSONResponse
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
     * @NoCSRFRequired
     */
    public function getAuditTrailStatistics(?int $registerId=null, ?int $schemaId=null, ?int $hours=24): JSONResponse
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
     * @NoCSRFRequired
     */
    public function getAuditTrailActionDistribution(?int $registerId=null, ?int $schemaId=null, ?int $hours=24): JSONResponse
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
     * @NoCSRFRequired
     */
    public function getMostActiveObjects(?int $registerId=null, ?int $schemaId=null, ?int $limit=10, ?int $hours=24): JSONResponse
    {
        try {
            $data = $this->dashboardService->getMostActiveObjects(registerId: $registerId, schemaId: $schemaId, limit: $limit, hours: $hours);
            return new JSONResponse(data: $data);
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Error retrieving most active objects: '.$e->getMessage(),
                    context: [
                        'register_id' => $registerId,
                        'schema_id'   => $schemaId,
                        'limit'        => $limit,
                        'hours'        => $hours,
                        'trace'        => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve most active objects: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try

    }//end getMostActiveObjects()


}//end class
