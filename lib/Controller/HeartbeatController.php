<?php
/**
 * OpenRegister Heartbeat Controller
 *
 * This file contains the controller class for handling heartbeat requests in the OpenRegister application.
 * Used to keep connections alive during long-running operations to prevent gateway timeouts.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for handling heartbeat requests to prevent connection timeouts.
 *
 */
class HeartbeatController extends Controller
{


    /**
     * HeartbeatController constructor.
     *
     * @param string   $appName The name of the app
     * @param IRequest $request The request object
     */
    public function __construct(
        string $appName,
        IRequest $request,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Heartbeat endpoint to keep connections alive during long operations
     *
     * This lightweight endpoint is called periodically during long-running operations
     * (like imports) to prevent nginx gateway timeouts. It simply returns a success
     * response with minimal server processing.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Simple success response
     *
     * @psalm-return JSONResponse<200,
     *     array{status: 'alive', timestamp: int<1, max>,
     *     message: 'Heartbeat successful - connection kept alive'},
     *     array<never, never>>
     */
    public function heartbeat(): JSONResponse
    {
        return new JSONResponse(
          data: [
              'status'    => 'alive',
              'timestamp' => time(),
              'message'   => 'Heartbeat successful - connection kept alive',
          ]
          );

    }//end heartbeat()


}//end class
