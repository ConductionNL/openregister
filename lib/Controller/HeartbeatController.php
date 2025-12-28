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
 * Controller for handling heartbeat requests to prevent connection timeouts
 *
 * Provides lightweight endpoint to keep HTTP connections alive during
 * long-running operations. Prevents gateway timeouts in nginx/proxy servers.
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
 *
 * @psalm-suppress UnusedClass
 */
class HeartbeatController extends Controller
{
    /**
     * HeartbeatController constructor
     *
     * Initializes controller with application name and request object.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string   $appName The name of the app
     * @param IRequest $request The HTTP request object
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Heartbeat endpoint to keep connections alive during long operations
     *
     * This lightweight endpoint is called periodically during long-running operations
     * (like imports, exports, bulk operations) to prevent nginx gateway timeouts.
     * It simply returns a success response with minimal server processing overhead.
     *
     * Usage: Frontend should call this endpoint every 30-60 seconds during
     * long operations to keep the HTTP connection alive and prevent timeout.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Simple success response with status, timestamp, and message
     *
     * @psalm-return JSONResponse<200,
     *     array{status: 'alive', timestamp: int<1, max>,
     *     message: 'Heartbeat successful - connection kept alive'},
     *     array<never, never>>
     */
    public function heartbeat(): JSONResponse
    {
        // Return lightweight success response to keep connection alive.
        // Minimal processing ensures fast response time.
        return new JSONResponse(
            data: [
              'status'    => 'alive',
              'timestamp' => time(),
              'message'   => 'Heartbeat successful - connection kept alive',
            ]
        );
    }//end heartbeat()
}//end class
