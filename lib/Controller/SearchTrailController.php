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
use OCP\AppFramework\Http\TemplateResponse;
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
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param SearchTrailService $searchTrailService The search trail service
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
     * @return ((mixed|string)[]|DateTime|int|mixed|null)[] Array containing processed parameters: - limit: (int) Maximum number of items per page - offset: (int|null) Number of items to skip - page: (int|null) Current page number - filters: (array) Filter parameters - sort: (array) Sort parameters ['field' => 'ASC|DESC'] - search: (string|null) Search term - from: (DateTime|null) Start date filter - to: (DateTime|null) End date filter
     *
     * @psalm-return array{limit: int, offset: int|null, page: int|null, filters: array, sort: array<array-key|mixed, 'DESC'|mixed>, search: mixed|null, from: DateTime|null, to: DateTime|null}
     */
    private function extractRequestParameters(): array
    {
        // Get request parameters for filtering and pagination.
        $params = $this->request->getParams();

        // Extract pagination parameters (prioritize underscore-prefixed versions).
        if (isset($params['_limit']) === true) {
            $limit = (int) $params['_limit'];
        } else if (isset($params['limit']) === true) {
            $limit = (int) $params['limit'];
        } else {
            $limit = 20;
        }

        if (isset($params['_offset']) === true) {
            $offset = (int) $params['_offset'];
        } else if (isset($params['offset']) === true) {
            $offset = (int) $params['offset'];
        } else {
            $offset = null;
        }

        if (isset($params['_page']) === true) {
            $page = (int) $params['_page'];
        } else if (isset($params['page']) === true) {
            $page = (int) $params['page'];
        } else {
            $page = null;
        }

        // If we have a page but no offset, calculate the offset.
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        // Extract search parameter (prioritize underscore-prefixed version).
        $search = $params['_search'] ?? $params['search'] ?? null;

        // Extract sort parameters (prioritize underscore-prefixed versions).
        $sort = [];
        if (isset($params['_sort']) === true || isset($params['sort']) === true) {
            $sortField        = $params['_sort'] ?? $params['sort'] ?? 'created';
            $sortOrder        = $params['_order'] ?? $params['order'] ?? 'DESC';
            $sort[$sortField] = $sortOrder;
        } else {
            $sort['created'] = 'DESC';
        }

        // Extract date filters.
        $from = null;
        $to   = null;
        if (isset($params['from']) === true) {
            try {
                $from = new DateTime($params['from']);
            } catch (\Exception $e) {
                // Invalid date format, ignore.
            }
        }

        if (isset($params['to']) === true) {
            try {
                $to = new DateTime($params['to']);
            } catch (\Exception $e) {
                // Invalid date format, ignore.
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
     * Private helper method to handle pagination of results.
     *
     * This method paginates the given results array based on the provided total, limit, offset, and page parameters.
     * It calculates the number of pages, sets the appropriate offset and page values, and returns the paginated results
     * along with metadata such as total items, current page, total pages, limit, and offset.
     *
     * @param array    $results The array of objects to paginate.
     * @param int|null $total   The total number of items (before pagination). Defaults to 0.
     * @param int|null $limit   The number of items per page. Defaults to 20.
     * @param int|null $offset  The offset of items. Defaults to 0.
     * @param int|null $page    The current page number. Defaults to 1.
     *
     * @return (array|float|int|null|string)[]
     *
     * @phpstan-param array<int, mixed> $results
     *
     * @phpstan-return array<string, mixed>
     *
     * @psalm-param array<int, mixed> $results
     *
     * @psalm-return array{results: array<int, mixed>, total: int<0, max>, page: float|int<1, max>, pages: 1|float, limit: int<1, max>, offset: int<0, max>, next?: null|string, prev?: null|string}
     */
    private function paginate(array $results, ?int $total=0, ?int $limit=20, ?int $offset=0, ?int $page=1): array
    {
        // Ensure we have valid values (never null).
        $total = max(0, $total ?? 0);
        $limit = max(1, $limit ?? 20);
        // Minimum limit of 1.
        $offset = max(0, $offset ?? 0);
        $page   = max(1, $page ?? 1);
        // Minimum page of 1.
        // Calculate the number of pages (minimum 1 page).
        $pages = max(1, ceil($total / $limit));

        // If we have a page but no offset, calculate the offset.
        if ($offset === 0) {
            $offset = ($page - 1) * $limit;
        }

        // If we have an offset but page is 1, calculate the page.
        if ($page === 1 && $offset > 0) {
            $page = floor($offset / $limit) + 1;
        }

        // If total is smaller than the number of results, set total to the number of results.
        if ($total < count($results)) {
            $total = count($results);
            $pages = max(1, ceil($total / $limit));
        }

        // Initialize the results array with pagination information.
        $paginatedResults = [
            'results' => $results,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset,
        ];

        // Add next/prev page URLs if applicable.
        $currentUrl = $_SERVER['REQUEST_URI'];

        // Add next page link if there are more pages.
        if ($page < $pages) {
            $nextPage = $page + 1;
            $nextUrl  = preg_replace('/([?&])_page=\d+/', '$1_page='.$nextPage, $currentUrl);
            if (strpos($nextUrl, '_page=') === false) {
                // Also handle legacy 'page' parameter.
                $nextUrl = preg_replace('/([?&])page=\d+/', '$1_page='.$nextPage, $nextUrl);
                if (strpos($nextUrl, '_page=') === false) {
                    $separator = '?';
                    if (strpos($nextUrl, '?') !== false) {
                        $separator = '&';
                    }

                    $nextUrl .= $separator.'_page='.$nextPage;
                }
            }

            $paginatedResults['next'] = $nextUrl;
        }

        // Add previous page link if not on first page.
        if ($page > 1) {
            $prevPage = $page - 1;
            $prevUrl  = preg_replace('/([?&])_page=\d+/', '$1_page='.$prevPage, $currentUrl);
            if (strpos($prevUrl, '_page=') === false) {
                // Also handle legacy 'page' parameter.
                $prevUrl = preg_replace('/([?&])page=\d+/', '$1_page='.$prevPage, $prevUrl);
                if (strpos($prevUrl, '_page=') === false) {
                    $separator = '?';
                    if (strpos($prevUrl, '?') !== false) {
                        $separator = '&';
                    }

                    $prevUrl .= $separator.'_page='.$prevPage;
                }
            }

            $paginatedResults['prev'] = $prevUrl;
        }

        return $paginatedResults;

    }//end paginate()


    /**
     * Get all search trail logs
     *
     * @return JSONResponse A JSON response containing the search logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array<string, mixed>, array<never, never>>
     */
    public function index(): JSONResponse
    {
        try {
            // Get raw request parameters (this is what the service expects).
            $rawParams = $this->request->getParams();

            // Remove system parameters that shouldn't be passed to the service.
            unset($rawParams['_route'], $rawParams['id']);

            // Get paginated search trails from service using raw parameters.
            $serviceResult = $this->searchTrailService->getSearchTrails($rawParams);

            // Extract the raw results and pagination info from service.
            $results = $serviceResult['results'] ?? [];
            $total   = $serviceResult['total'] ?? 0;
            $limit   = $serviceResult['limit'] ?? 20;
            $offset  = $serviceResult['offset'] ?? 0;
            $page    = $serviceResult['page'] ?? 1;

            // Use the paginate method to ensure consistent format with ObjectsController.
            $paginatedResult = $this->paginate($results, $total, $limit, $offset, $page);

            return new JSONResponse($paginatedResult);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to retrieve search trails: '.$e->getMessage()],
                statusCode: 500
            );
        }//end try

    }//end index()


    /**
     * Get a specific search trail log by ID
     *
     * @param int $id The search trail ID
     *
     * @return JSONResponse A JSON response containing the search log
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\SearchTrail, array<never, never>>|JSONResponse<404|500, array{error: string}, array<never, never>>
     */
    public function show(int $id): JSONResponse
    {
        try {
            $log = $this->searchTrailService->getSearchTrail($id);
            return new JSONResponse($log);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Search trail not found'],
                statusCode: 404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to retrieve search trail: '.$e->getMessage()],
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
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
                ['error' => 'Failed to get search statistics: '.$e->getMessage()],
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array{error?: mixed|string, total_searches?: 0|mixed, period?: mixed|null,...}, array<never, never>>
     */
    public function popularTerms(): JSONResponse
    {
        // Extract parameters.
        $params = $this->extractRequestParameters();
        // Prioritize underscore-prefixed limit parameter.
        $limit = $this->request->getParam('_limit', $this->request->getParam('limit', 10));

        try {
            $serviceResult = $this->searchTrailService->getPopularSearchTerms(
                limit: (int) $limit,
                from: $params['from'],
                to: $params['to']
            );

            // Extract the terms array and metadata.
            $terms            = $serviceResult['terms'] ?? [];
            $totalUniqueTerms = $serviceResult['total_unique_terms'] ?? 0;
            $totalSearches    = $serviceResult['total_searches'] ?? 0;
            $period           = $serviceResult['period'] ?? null;

            // Use pagination format for the terms array.
            $page           = $params['page'] ?? 1;
            $offset         = $params['offset'] ?? 0;
            $paginatedTerms = $this->paginate($terms, $totalUniqueTerms, $limit, $offset, $page);

            // Add the additional metadata from the service.
            $paginatedTerms['total_searches'] = $totalSearches;
            $paginatedTerms['period']         = $period;

            return new JSONResponse($paginatedTerms);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to get popular search terms: '.$e->getMessage()],
                500
            );
        }//end try

    }//end popularTerms()


    /**
     * Get search activity by time period
     *
     * @return JSONResponse A JSON response containing search activity data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function activity(): JSONResponse
    {
        // Extract parameters.
        $params   = $this->extractRequestParameters();
        $interval = $this->request->getParam(key: 'interval', default: 'day');

        try {
            $result = $this->searchTrailService->getSearchActivity(
                interval: $interval,
                from: $params['from'],
                to: $params['to']
            );

            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to get search activity: '.$e->getMessage()],
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
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array{error?: mixed|string, total_searches?: 0|mixed, period?: mixed|null,...}, array<never, never>>
     */
    public function registerSchemaStats(): JSONResponse
    {
        // Extract parameters.
        $params = $this->extractRequestParameters();

        try {
            $serviceResult = $this->searchTrailService->getRegisterSchemaStatistics(
                from: $params['from'],
                to: $params['to']
            );

            // Extract the statistics array and metadata.
            $statistics        = $serviceResult['statistics'] ?? [];
            $totalCombinations = $serviceResult['total_combinations'] ?? 0;
            $totalSearches     = $serviceResult['total_searches'] ?? 0;
            $period            = $serviceResult['period'] ?? null;

            // Use pagination format for the statistics array.
            // Prioritize underscore-prefixed limit parameter.
            $defaultLimit   = $this->request->getParam('limit', 20);
            $limit          = $this->request->getParam('_limit', $defaultLimit);
            $page           = $params['page'] ?? 1;
            $offset         = $params['offset'] ?? 0;
            $paginatedStats = $this->paginate($statistics, $totalCombinations, $limit, $offset, $page);

            // Add the additional metadata from the service.
            $paginatedStats['total_searches'] = $totalSearches;
            $paginatedStats['period']         = $period;

            return new JSONResponse($paginatedStats);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to get register/schema statistics: '.$e->getMessage()],
                500
            );
        }//end try

    }//end registerSchemaStats()


    /**
     * Get user agent statistics
     *
     * @return JSONResponse A JSON response containing user agent statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array<string, mixed>, array<never, never>>
     */
    public function userAgentStats(): JSONResponse
    {
        // Extract parameters.
        $params = $this->extractRequestParameters();
        // Prioritize underscore-prefixed limit parameter.
        $limit = $this->request->getParam('_limit', $this->request->getParam('limit', 10));

        try {
            $serviceResult = $this->searchTrailService->getUserAgentStatistics(
                limit: (int) $limit,
                from: $params['from'],
                to: $params['to']
            );

            // Check if service result is a structured array with nested data.
            if (isset($serviceResult['user_agents']) === true) {
                // Extract the user agents array and metadata from structured response.
                /** @psalm-suppress RedundantCondition */
                $userAgentsArray = $serviceResult['user_agents'] ?? [];
                // Ensure we have a proper indexed array for pagination.
                $userAgents = is_array($userAgentsArray) ? array_values($userAgentsArray) : [];
                /*
                 * @psalm-suppress InvalidArrayOffset
                 */
                $totalUniqueAgents = $serviceResult['total_unique_agents'] ?? 0;
                /*
                 * @psalm-suppress InvalidArrayOffset
                 */
                $totalSearches = $serviceResult['total_searches'] ?? 0;
                $period        = $serviceResult['period'] ?? null;
                /*
                 * @psalm-suppress InvalidArrayOffset
                 */
                $browserStats = $serviceResult['browser_breakdown'] ?? null;

                // Use pagination format for the user agents array.
                $page   = $params['page'] ?? 1;
                $offset = $params['offset'] ?? 0;
                $paginatedUserAgents = $this->paginate($userAgents, $totalUniqueAgents, $limit, $offset, $page);

                // Add the additional metadata from the service.
                $paginatedUserAgents['total_searches'] = $totalSearches;
                $paginatedUserAgents['period']         = $period;
                if ($browserStats !== null && empty($browserStats) === false) {
                    $paginatedUserAgents['browser_breakdown'] = $browserStats;
                }

                return new JSONResponse($paginatedUserAgents);
            } else {
                // If service returns a simple array, treat it as the user agents list.
                // $serviceResult is always an array at this point (non-null).
                $userAgentsArray = $serviceResult;
                // Ensure we have a proper indexed array for pagination.
                // $userAgentsArray is always an array at this point, but may be associative.
                $userAgents        = array_values($userAgentsArray);
                $totalUniqueAgents = count($userAgents);

                // Use pagination format for the user agents array.
                $page   = $params['page'] ?? 1;
                $offset = $params['offset'] ?? 0;
                $paginatedUserAgents = $this->paginate($userAgents, $totalUniqueAgents, $limit, $offset, $page);

                return new JSONResponse($paginatedUserAgents);
            }//end if
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => 'Failed to get user agent statistics: '.$e->getMessage()],
                500
            );
        }//end try

    }//end userAgentStats()


    /**
     * Clean up old search trail logs
     *
     * @return JSONResponse A JSON response indicating cleanup results
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|500, array, array<never, never>>
     */
    public function cleanup(): JSONResponse
    {
        // Extract date parameter.
        $before     = $this->request->getParam(key: 'before');
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
                ['error' => 'Cleanup failed: '.$e->getMessage()],
                500
            );
        }

    }//end cleanup()


    /**
     * Export search trail logs in specified format
     *
     * @return JSONResponse A JSON response containing the export data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array{error?: string, success?: true, data?: array{content: false|string, filename: string, contentType: 'application/json'|'text/csv', size: int<0, max>}}, array<never, never>>
     */
    public function export(): JSONResponse
    {
        // Extract request parameters.
        $params = $this->extractRequestParameters();

        // Get export specific parameters.
        $format          = $this->request->getParam(key: 'format', default: 'csv');
        $includeMetadata = $this->request->getParam(key: 'includeMetadata', default: false);

        try {
            // Build export configuration.
            $exportConfig = [
                'filters'         => $params['filters'],
                'search'          => $params['search'],
                'from'            => $params['from'],
                'to'              => $params['to'],
                'includeMetadata' => filter_var($includeMetadata, FILTER_VALIDATE_BOOLEAN),
            ];

            // Export search trails using service.
            $searchTrails = $this->searchTrailService->getSearchTrails(
                    [
                        'filters' => $params['filters'],
                        'search'  => $params['search'],
                        'from'    => $params['from'],
                        'to'      => $params['to'],
                        'limit'   => null,
                        'offset'  => null,
                    ]
                    );

            // Format export data.
            $exportData = [];
            foreach ($searchTrails['results'] as $trail) {
                $row = [
                    'id'             => $trail->getId(),
                    'search_term'    => $trail->getSearchTerm(),
                    'request_uri'    => $trail->getRequestUri(),
                    'result_count'   => $trail->getResultCount(),
                    'total_results'  => $trail->getTotalResults(),
                    'response_time'  => $trail->getResponseTime(),
                    'execution_type' => $trail->getExecutionType(),
                    'user_id'        => $trail->getUserId(),
                    'user_agent'     => $trail->getUserAgent(),
                    'ip_address'     => $trail->getIpAddress(),
                    'session_id'     => $trail->getSessionId(),
                    'created'        => $trail->getCreated(),
                    'updated'        => $trail->getUpdated(),
                ];

                if ($exportConfig['includeMetadata'] === true) {
                    $row['search_parameters'] = $trail->getSearchParameters();
                    $row['result_metadata']   = $trail->getResultMetadata();
                }

                $exportData[] = $row;
            }//end foreach

            // Generate export content based on format.
            if ($format === 'json') {
                $content     = json_encode($exportData, JSON_PRETTY_PRINT);
                $contentType = 'application/json';
                $filename    = 'search-trails-'.date('Y-m-d-H-i-s').'.json';
            } else {
                // Default to CSV.
                $content     = $this->arrayToCsv($exportData);
                $contentType = 'text/csv';
                $filename    = 'search-trails-'.date('Y-m-d-H-i-s').'.csv';
            }

            // Return export data.
            return new JSONResponse(
                    [
                        'success' => true,
                        'data'    => [
                            'content'     => $content,
                            'filename'    => $filename,
                            'contentType' => $contentType,
                            'size'        => strlen($content),
                        ],
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    [
                        'error' => 'Export failed: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end export()


    /**
     * Delete a single search trail log
     *
     * @param int $id The search trail ID to delete
     *
     * @return JSONResponse A JSON response indicating success or failure
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|404|500, array{error?: string, success?: true, message?: 'Search trail deletion not implemented yet'}, array<never, never>>
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $searchTrail = $this->searchTrailService->getSearchTrail($id);

            // For now, we'll just return a success message since we don't have a delete method in the service.
            // In a real implementation, you'd add a deleteSearchTrail method to the service.
            return new JSONResponse(
                    [
                        'success' => true,
                        'message' => 'Search trail deletion not implemented yet',
                    ]
                    );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                    [
                        'error' => 'Search trail not found',
                    ],
                    404
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    [
                        'error' => 'Deletion failed: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end destroy()


    /**
     * Delete multiple search trail logs based on filters or specific IDs
     *
     * @return JSONResponse A JSON response with deletion results
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array{error?: string, success?: true, results?: array{deleted: 0, failed: 0, message: 'Multiple search trail deletion not implemented yet'}, message?: 'Multiple search trail deletion not implemented yet'}, array<never, never>>
     */
    public function destroyMultiple(): JSONResponse
    {
        // Extract request parameters.
        $params = $this->extractRequestParameters();

        // Get specific parameters for mass deletion.
        $ids = $this->request->getParam(key: 'ids', default: null);

        try {
            // For now, we'll just return a success message since we don't have a delete method in the service.
            // In a real implementation, you'd add a deleteMultipleSearchTrails method to the service.
            $result = [
                'deleted' => 0,
                'failed'  => 0,
                'message' => 'Multiple search trail deletion not implemented yet',
            ];

            return new JSONResponse(
                    [
                        'success' => true,
                        'results' => $result,
                        'message' => 'Multiple search trail deletion not implemented yet',
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    [
                        'error' => 'Mass deletion failed: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end destroyMultiple()


    /**
     * Convert array to CSV format
     *
     * @param array $data The data to convert
     *
     * @return string The CSV formatted string
     */
    private function arrayToCsv(array $data): string
    {
        if (empty($data) === true) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // Add headers.
        fputcsv($output, array_keys($data[0]));

        // Add data rows.
        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;

    }//end arrayToCsv()


    /**
     * Clear all search trail logs
     *
     * @return JSONResponse A JSON response indicating success or failure
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function clearAll(): JSONResponse
    {
        try {
            // Get the search trail mapper from the container.
            /*
             * @psalm-suppress UndefinedClass
             */
            /*
             * @var \OCA\OpenRegister\Db\SearchTrailMapper $searchTrailMapper
             */
            $searchTrailMapper = \OC::$server->get('OCA\OpenRegister\Db\SearchTrailMapper');

                    // Use the clearAllLogs method from the mapper.
                    $result = $searchTrailMapper->clearAllLogs();

            if ($result === true) {
                return new JSONResponse(
                        [
                            'success' => true,
                            'message' => 'All search trails cleared successfully',
                            'deleted' => 'All expired search trails have been deleted',
                        ]
                        );
            } else {
                return new JSONResponse(
                        [
                            'success' => true,
                            'message' => 'No expired search trails found to clear',
                            'deleted' => 0,
                        ]
                        );
            }
        } catch (\Exception $e) {
            return new JSONResponse(
                    [
                        'success' => false,
                        'error'   => 'Failed to clear search trails: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end clearAll()


}//end class
