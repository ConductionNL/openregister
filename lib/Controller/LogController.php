<?php
/**
 * Class LogController
 *
 * Controller for managing audit trail log operations in the OpenRegister app.
 * Provides functionality to retrieve logs related to objects within registers and schemas.
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

use OCA\OpenRegister\Service\LogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Class LogController
 * Handles all audit trail log related operations
 */
class LogController extends Controller
{


    /**
     * Constructor for LogController
     *
     * @param string     $appName    The name of the app
     * @param IRequest   $request    The request object
     * @param LogService $logService The log service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly LogService $logService
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


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
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        // Get request parameters for filtering and pagination.
        $params = $this->request->getParams();

        // Extract pagination parameters.
        if (isset($params['limit'])) {
            $limit = (int) $params['limit'];
        } elseif (isset($params['_limit'])) {
            $limit = (int) $params['_limit'];
        } else {
            $limit = 20;
        }

        if (isset($params['offset'])) {
            $offset = (int) $params['offset'];
        } elseif (isset($params['_offset'])) {
            $offset = (int) $params['_offset'];
        } else {
            $offset = null;
        }

        if (isset($params['page'])) {
            $page = (int) $params['page'];
        } elseif (isset($params['_page'])) {
            $page = (int) $params['_page'];
        } else {
            $page = null;
        }

        // If we have a page but no offset, calculate the offset.
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        // Extract search parameter.
        $search = $params['search'] ?? $params['_search'] ?? null;

        // Extract sort parameters.
        $sort = [];
        if (isset($params['sort']) === true || isset($params['_sort']) === true) {
            $sortField        = $params['sort'] ?? $params['_sort'] ?? 'created';
            $sortOrder        = $params['order'] ?? $params['_order'] ?? 'DESC';
            $sort[$sortField] = $sortOrder;
        } else {
            $sort['created'] = 'DESC';
        }

        // Filter out special parameters and system fields.
        $filters = array_filter(
            $params,
            function ($key) {
                return !in_array(
                        $key,
                        [
                            'limit',
                            '_limit',
                            'offset',
                            '_offset',
                            'page',
                            '_page',
                            'search',
                            '_search',
                            'sort',
                            '_sort',
                            'order',
                            '_order',
                            '_route',
                            'register',
                            'schema',
                            'id',
                        ]
                        );
            },
            ARRAY_FILTER_USE_KEY
        );

        // Get logs from service.
        $logs = $this->logService->getLogs(
                $register,
                $schema,
                $id,
                [
                    'limit'   => $limit,
                    'offset'  => $offset,
                    'page'    => $page,
                    'filters' => $filters,
                    'sort'    => $sort,
                    'search'  => $search,
                ]
                );

        // Get total count for pagination.
        $total = $this->logService->count($register, $schema, $id);

        // Return paginated results.
        return new JSONResponse(
                [
                    'results' => $logs,
                    'total'   => $total,
                    'page'    => $page,
                    'pages'   => ceil($total / $limit),
                    'limit'   => $limit,
                    'offset'  => $offset,
                ]
                );

    }//end index()


}//end class
