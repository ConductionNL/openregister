<?php
/**
 * Class AuditTrailController
 *
 * Controller for managing audit trail operations in the OpenRegister app.
 * Provides functionality to retrieve audit trails related to objects within registers and schemas.
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
 * Class AuditTrailController
 * Handles all audit trail related operations
 */
class AuditTrailController extends Controller
{


    /**
     * Constructor for AuditTrailController
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
     * Extract pagination, filter, and search parameters from request
     *
     * @return array Array containing processed parameters:
     *               - limit: (int) Maximum number of items per page
     *               - offset: (int|null) Number of items to skip
     *               - page: (int|null) Current page number
     *               - filters: (array) Filter parameters
     *               - sort: (array) Sort parameters ['field' => 'ASC|DESC']
     *               - search: (string|null) Search term
     */
    private function extractRequestParameters(): array
    {
        // Get request parameters for filtering and pagination.
        $params = $this->request->getParams();

        // Extract pagination parameters.
        if (isset($params['limit'])) {
            $limit = (int) $params['limit'];
        } else if (isset($params['_limit'])) {
            $limit = (int) $params['_limit'];
        } else {
            $limit = 20;
        }

        if (isset($params['offset'])) {
            $offset = (int) $params['offset'];
        } else if (isset($params['_offset'])) {
            $offset = (int) $params['_offset'];
        } else {
            $offset = null;
        }

        if (isset($params['page'])) {
            $page = (int) $params['page'];
        } else if (isset($params['_page'])) {
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
                            'id',
                        ]
                        );
            },
            ARRAY_FILTER_USE_KEY
        );

        return [
            'limit'   => $limit,
            'offset'  => $offset,
            'page'    => $page,
            'filters' => $filters,
            'sort'    => $sort,
            'search'  => $search,
        ];

    }//end extractRequestParameters()


    /**
     * Get all audit trail logs
     *
     * @return JSONResponse A JSON response containing the logs
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        // Extract common parameters.
        $params = $this->extractRequestParameters();

        // Get logs from service.
        $logs = $this->logService->getAllLogs($params);

        // Get total count for pagination.
        $total = $this->logService->countAllLogs($params['filters']);

        // Return paginated results.
        return new JSONResponse(
                [
                    'results' => $logs,
                    'total'   => $total,
                    'page'    => $params['page'],
                    'pages'   => ceil($total / $params['limit']),
                    'limit'   => $params['limit'],
                    'offset'  => $params['offset'],
                ]
                );

    }//end index()


    /**
     * Get a specific audit trail log by ID
     *
     * @param int $id The audit trail ID
     *
     * @return JSONResponse A JSON response containing the log
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(int $id): JSONResponse
    {
        try {
            $log = $this->logService->getLog($id);
            return new JSONResponse($log);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Audit trail not found'],
                404
            );
        }

    }//end show()


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
    public function objects(string $register, string $schema, string $id): JSONResponse
    {
        // Extract common parameters.
        $params = $this->extractRequestParameters();

        try {
            // Get logs from service.
            $logs = $this->logService->getLogs(
                    $register,
                    $schema,
                    $id,
                    $params
                    );

            // Get total count for pagination.
            $total = $this->logService->count($register, $schema, $id);

            // Return paginated results.
            return new JSONResponse(
                    [
                        'results' => $logs,
                        'total'   => $total,
                        'page'    => $params['page'],
                        'pages'   => ceil($total / $params['limit']),
                        'limit'   => $params['limit'],
                        'offset'  => $params['offset'],
                    ]
                    );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Object not found'],
                404
            );
        }

    }//end objects()

}//end class
