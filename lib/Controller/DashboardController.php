<?php
/**
 * OpenConnector Consumers Controller
 *
 * This file contains the controller for handling consumer related operations
 * in the OpenRegister application.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\AppInfo
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\ContentSecurityPolicy;

/**
 * Class DashboardController
 */
class DashboardController extends Controller
{


    public function __construct($appName, IRequest $request)
    {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(?string $getParameter)
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
     * @NoAdminRequired
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
