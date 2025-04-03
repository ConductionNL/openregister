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
     * Constructor for the DashboardController
     *
     * @param string   $appName The name of the app
     * @param IRequest $request The request object
     *
     * @return void
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct($appName, $request);

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
     * Retrieves dashboard data
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
            $results = ["results" => self::TEST_ARRAY];
            return new JSONResponse($results);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end index()


}//end class
