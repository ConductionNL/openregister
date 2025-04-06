<?php
/**
 * @file LogController.php
 * @description Controller for handling audit trail logs in the OpenRegister app
 * @package OCA\OpenRegister\Controller
 * @author Ruben Linde <ruben@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version 1.0.0
 * @link https://github.com/OpenCatalogi/OpenRegister
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\LogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Class LogController
 * Handles all audit trail log related operations
 */
class LogController extends Controller {
    /**
     * Constructor for LogController
     *
     * @param string     $appName     The name of the app
     * @param IRequest   $request     The request object
     * @param LogService $logService  The log service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly LogService $logService
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get logs for an object
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     * @param string $id       The object ID
     *
     * @return JSONResponse A JSON response containing the logs
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(string $register, string $schema, string $id): JSONResponse {
        // Get request parameters for filtering and pagination
        $params = $this->request->getParams();
        $limit = (int)($params['limit'] ?? $params['_limit'] ?? 20);
        $offset = isset($params['offset']) ? (int)$params['offset'] : (isset($params['_offset']) ? (int)$params['_offset'] : null);
        $page = isset($params['page']) ? (int)$params['page'] : (isset($params['_page']) ? (int)$params['_page'] : null);

        // If we have a page but no offset, calculate the offset
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        // Get logs from service
        $logs = $this->logService->getLogs($register, $schema, $id, [
            'limit' => $limit,
            'offset' => $offset,
            'page' => $page,
            'filters' => $params
        ]);

        // Get total count for pagination
        $total = $this->logService->count($register, $schema, $id);

        // Return paginated results
        return new JSONResponse([
            'results' => $logs,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
            'limit' => $limit,
            'offset' => $offset
        ]);
    }
} 