<?php
/**
 * Class SearchTrailController
 *
 * Controller for managing search trail operations and analytics in the OpenRegister app.
 * Provides functionality to retrieve search statistics, popular search terms, and search logs.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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

use DateTime;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Class SearchTrailController
 * Handles all search trail related operations and analytics
 */
class SearchTrailController extends Controller
{


    /**
     * Constructor for SearchTrailController
     *
     * @param string             $appName             The name of the app
     * @param IRequest           $request             The request object
     * @param SearchTrailService $searchTrailService  The search trail service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SearchTrailService $searchTrailService
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
     *               - from: (DateTime|null) Start date filter
     *               - to: (DateTime|null) End date filter
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

        // Extract date filters.
        $from = null;
        $to = null;
        if (isset($params['from']) === true) {
            try {
                $from = new DateTime($params['from']);
            } catch (\Exception $e) {
                // Invalid date format, ignore
            }
        }
        if (isset($params['to']) === true) {
            try {
                $to = new DateTime($params['to']);
            } catch (\Exception $e) {
                // Invalid date format, ignore
            }
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
                            'from',
                            'to',
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
            'from'    => $from,
            'to'      => $to,
        ];

    }//end extractRequestParameters()


    /**
     * Get all search trail logs
     *
     * @return JSONResponse A JSON response containing the search logs
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        // Extract common parameters.
        $params = $this->extractRequestParameters();

        try {
            // Get paginated search trails from service.
            $result = $this->searchTrailService->getSearchTrails($params);

            // Return paginated results.
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to retrieve search trails: ' . $e->getMessage()],
                500
            );
        }

    }//end index()


    /**
     * Get a specific search trail log by ID
     *
     * @param int $id The search trail ID
     *
     * @return JSONResponse A JSON response containing the search log
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(int $id): JSONResponse
    {
        try {
            $log = $this->searchTrailService->getSearchTrail($id);
            return new JSONResponse($log);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Search trail not found'],
                404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to retrieve search trail: ' . $e->getMessage()],
                500
            );
        }

    }//end show()


    /**
     * Get search statistics for a given period
     *
     * @return JSONResponse A JSON response containing search statistics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function statistics(): JSONResponse
    {
        // Extract date filters.
        $params = $this->extractRequestParameters();

        try {
            $statistics = $this->searchTrailService->getSearchStatistics(
                from: $params['from'],
                to: $params['to']
            );

            return new JSONResponse($statistics);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to get search statistics: ' . $e->getMessage()],
                500
            );
        }

    }//end statistics()


    /**
     * Get popular search terms
     *
     * @return JSONResponse A JSON response containing popular search terms
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function popularTerms(): JSONResponse
    {
        // Extract parameters.
        $params = $this->extractRequestParameters();
        $limit = $this->request->getParam('limit', 10);

        try {
            $result = $this->searchTrailService->getPopularSearchTerms(
                limit: (int) $limit,
                from: $params['from'],
                to: $params['to']
            );

            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to get popular search terms: ' . $e->getMessage()],
                500
            );
        }

    }//end popularTerms()


    /**
     * Get search activity by time period
     *
     * @return JSONResponse A JSON response containing search activity data
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function activity(): JSONResponse
    {
        // Extract parameters.
        $params = $this->extractRequestParameters();
        $interval = $this->request->getParam('interval', 'day');

        try {
            $result = $this->searchTrailService->getSearchActivity(
                interval: $interval,
                from: $params['from'],
                to: $params['to']
            );

            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to get search activity: ' . $e->getMessage()],
                500
            );
        }

    }//end activity()


    /**
     * Get search statistics by register and schema
     *
     * @return JSONResponse A JSON response containing search statistics by register/schema
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function registerSchemaStats(): JSONResponse
    {
        // Extract parameters.
        $params = $this->extractRequestParameters();

        try {
            $result = $this->searchTrailService->getRegisterSchemaStatistics(
                from: $params['from'],
                to: $params['to']
            );

            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to get register/schema statistics: ' . $e->getMessage()],
                500
            );
        }

    }//end registerSchemaStats()


    /**
     * Get user agent statistics
     *
     * @return JSONResponse A JSON response containing user agent statistics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function userAgentStats(): JSONResponse
    {
        // Extract parameters.
        $params = $this->extractRequestParameters();
        $limit = $this->request->getParam('limit', 10);

        try {
            $result = $this->searchTrailService->getUserAgentStatistics(
                limit: (int) $limit,
                from: $params['from'],
                to: $params['to']
            );

            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to get user agent statistics: ' . $e->getMessage()],
                500
            );
        }

    }//end userAgentStats()


    /**
     * Clean up old search trail logs
     *
     * @return JSONResponse A JSON response indicating cleanup results
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function cleanup(): JSONResponse
    {
        // Extract date parameter.
        $before = $this->request->getParam('before');
        $beforeDate = null;
        
        if ($before !== null) {
            try {
                $beforeDate = new DateTime($before);
            } catch (\Exception $e) {
                return new JSONResponse(
                    ['error' => 'Invalid date format for before parameter'],
                    400
                );
            }
        }

        try {
            $result = $this->searchTrailService->cleanupSearchTrails($beforeDate);
            
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Cleanup failed: ' . $e->getMessage()],
                500
            );
        }

    }//end cleanup()


}//end class 