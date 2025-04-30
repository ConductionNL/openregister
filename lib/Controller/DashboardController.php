<?php
/**
 * OpenConnector Dashboard Controller
 *
 * This file contains the controller for handling dashboard related operations
 * in the OpenRegister application.
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

use OCA\OpenRegister\Service\DashboardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Class DashboardController
 *
 * Controller for handling dashboard related operations in the application.
 * Provides functionality to display the dashboard page and retrieve dashboard data.
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
     * Constructor for the DashboardController
     *
     * @param string           $appName          The name of the app
     * @param IRequest         $request          The request object
     * @param DashboardService $dashboardService The dashboard service instance
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        DashboardService $dashboardService
    ) {
        parent::__construct($appName, $request);
        $this->dashboardService = $dashboardService;

    }//end __construct()


    /**
     * Returns the template of the dashboard page
     *
     * This method renders the dashboard page of the application, adding any necessary data to the template.
     *
     * @param string|null $getParameter Optional parameter for the page request
     *
     * @return TemplateResponse The rendered template response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function page(?string $getParameter=null): TemplateResponse
    {
        try {
            $response = new TemplateResponse(
                $this->appName,
                'index',
                []
            );

            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (\Exception $e) {
            return new TemplateResponse(
                $this->appName,
                'error',
                ['error' => $e->getMessage()],
                '500'
            );
        }

    }//end page()


    /**
     * Retrieves dashboard data including registers with their schemas
     *
     * This method returns a JSON response containing dashboard data.
     *
     * @param int|null   $limit            Optional limit for the number of results
     * @param int|null   $offset           Optional offset for pagination
     * @param array|null $filters          Optional filters to apply
     * @param array|null $searchConditions Optional search conditions
     * @param array|null $searchParams     Optional search parameters
     *
     * @return JSONResponse A JSON response containing the dashboard data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function index(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[]
    ): JSONResponse {
        try {
            $registers = $this->dashboardService->getRegistersWithSchemas(
                limit: $limit,
                offset: $offset,
                filters: $filters,
                searchConditions: $searchConditions,
                searchParams: $searchParams
            );

            return new JSONResponse(['registers' => $registers]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            $result = $this->dashboardService->calculate($registerId, $schemaId);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(
                [
                    'status'    => 'error',
                    'message'   => $e->getMessage(),
                    'timestamp' => (new \DateTime())->format('c'),
                ],
                500
            );
        }

    }//end calculate()


}//end class
