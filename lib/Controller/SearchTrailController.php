<?php

/**
 * Class SearchTrailController
 *
 * Controller for managing search trail operations and analytics in the OpenRegister app.
 * Provides functionality to retrieve search statistics, popular search terms, and search logs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-91
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Class SearchTrailController
 * Handles all search trail related operations and analytics
 *
 * @psalm-suppress UnusedClass
 *
 * @suppressWarnings(PHPMD.TooManyPublicMethods)
 * @suppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class SearchTrailController extends Controller
{
    /**
     * Constructor for SearchTrailController
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param SearchTrailService $searchTrailService The search trail service
     * @param IUserSession       $userSession        Active user session for caller identity
     * @param IGroupManager      $groupManager       Group manager for admin / role checks
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SearchTrailService $searchTrailService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Gate sensitive search-trail read operations on admin membership.
     *
     * SECURITY: search-trail rows record per-search IP address, user
     * ID, user-agent and full query string for every search across
     * every register/schema. Returning them to non-admin callers leaks
     * PII (GDPR) and gives any authenticated user — including users
     * restricted to a single app group — a recon view of what every
     * other tenant is searching for (wave-3 C7). Surface stays
     * admin-only at both the framework level (no `@NoAdminRequired`)
     * and the body level (defence-in-depth).
     *
     * @return JSONResponse|null 401/403 response when blocked, null when allowed.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'Authentication required'],
                statusCode: 401
            );
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                data: ['error' => 'Forbidden: this search-trail operation is admin-only'],
                statusCode: 403
            );
        }

        return null;

    }//end requireAdmin()

    /**
     * Extract pagination, filter, and search parameters from request
     *
     * @return array Request parameters including pagination and filters
     *
     * @suppressWarnings(PHPMD.NPathComplexity)       Request parameter extraction requires many conditional checks
     * @suppressWarnings(PHPMD.ExcessiveMethodLength)
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec exclude Private helper: parses pagination/filter/date params; the search-trail analytics API is owned by
     *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3.
     */
    private function extractRequestParameters(): array
    {
        // Get request parameters for filtering and pagination.
        $params = $this->request->getParams();

        // Extract pagination parameters (prioritize underscore-prefixed versions).
        $limit = 20;
        if (($params['_limit'] ?? null) !== null) {
            $limit = (int) $params['_limit'];
        }

        if (($params['limit'] ?? null) !== null) {
            $limit = (int) $params['limit'];
        }

        $offset = null;
        if (($params['_offset'] ?? null) !== null) {
            $offset = (int) $params['_offset'];
        }

        if (($params['offset'] ?? null) !== null) {
            $offset = (int) $params['offset'];
        }

        $page = null;
        if (($params['_page'] ?? null) !== null) {
            $page = (int) $params['_page'];
        }

        if (($params['page'] ?? null) !== null) {
            $page = (int) $params['page'];
        }

        // If we have a page but no offset, calculate the offset.
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        // Extract search parameter (prioritize underscore-prefixed version).
        $search = $params['_search'] ?? $params['search'] ?? null;

        // Extract sort parameters (prioritize underscore-prefixed versions).
        $sort            = [];
        $sort['created'] = 'DESC';
        if (($params['_sort'] ?? null) !== null || (($params['sort'] ?? null) !== null) === true) {
            $sortField        = $params['_sort'] ?? $params['sort'] ?? 'created';
            $sortOrder        = $params['_order'] ?? $params['order'] ?? 'DESC';
            $sort[$sortField] = $sortOrder;
        }

        // Extract date filters.
        $from = null;
        $to   = null;
        if (($params['from'] ?? null) !== null) {
            try {
                $from = new DateTime($params['from']);
            } catch (\Exception $e) {
                // Invalid date format, ignore.
            }
        }

        if (($params['to'] ?? null) !== null) {
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
     * @psalm-return array{
     *     results: array<int, mixed>,
     *     total: int<0, max>,
     *     page: float|int<1, max>,
     *     pages: 1|float,
     *     limit: int<1, max>,
     *     offset: int<0, max>,
     *     next?: null|string,
     *     prev?: null|string
     * }
     *
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
     * @suppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec exclude Private helper: shared pagination-envelope builder; the search-trail analytics API is owned by
     *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3.
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
        $currentUrl = $this->request->getRequestUri();

        // Add next page link if there are more pages.
        if ($page < $pages) {
            $nextPage = $page + 1;
            $nextUrl  = preg_replace('/([?&])_page=\d+/', '$1_page='.$nextPage, $currentUrl);
            if (strpos($nextUrl, '_page=') === false) {
                // Also handle legacy 'page' parameter.
                $nextUrl = preg_replace('/([?&])page=\d+/', '$1_page='.$nextPage, $nextUrl);
                if (strpos($nextUrl, '_page=') === false) {
                    $separator = '&';
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
                    $separator = '&';
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
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth — wave-3 C7.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with search trail logs
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function index(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

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
            $page    = (int) ($serviceResult['page'] ?? 1);

            // Use the paginate method to ensure consistent format with ObjectsController.
            $paginatedResult = $this->paginate(
                results: $results,
                total: $total,
                limit: $limit,
                offset: $offset,
                page: $page
            );

            return new JSONResponse(data: $paginatedResult);
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
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth — wave-3 C7. IDs
     * are sequential so without the gate any authed caller could
     * enumerate every tenant's recorded searches (IP, user ID,
     * user-agent, query string).
     *
     * @param int $id The search trail ID
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with search trail data
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function show(int $id): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        try {
            $log = $this->searchTrailService->getSearchTrail($id);
            return new JSONResponse(data: $log);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Search trail not found'],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $errorMsg = 'Failed to retrieve search trail: '.$e->getMessage();
            return new JSONResponse(data: ['error' => $errorMsg], statusCode: 500);
        }
    }//end show()

    /**
     * Get search statistics for a given period
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with search statistics
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function statistics(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        // Extract date filters.
        $params = $this->extractRequestParameters();

        try {
            $statistics = $this->searchTrailService->getSearchStatistics(
                from: $params['from'],
                to: $params['to']
            );

            return new JSONResponse(data: $statistics);
        } catch (\Exception $e) {
            $errorMsg = 'Failed to get search statistics: '.$e->getMessage();
            return new JSONResponse(data: ['error' => $errorMsg], statusCode: 500);
        }
    }//end statistics()

    /**
     * Get popular search terms
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with popular search terms
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function popularTerms(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

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
            $paginatedTerms = $this->paginate(
                results: $terms,
                total: $totalUniqueTerms,
                limit: $limit,
                offset: $offset,
                page: $page
            );

            // Add the additional metadata from the service.
            $paginatedTerms['total_searches'] = $totalSearches;
            $paginatedTerms['period']         = $period;

            return new JSONResponse(data: $paginatedTerms);
        } catch (\Exception $e) {
            $errorMsg = 'Failed to get popular search terms: '.$e->getMessage();
            return new JSONResponse(data: ['error' => $errorMsg], statusCode: 500);
        }//end try
    }//end popularTerms()

    /**
     * Get search activity by time period
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with search activity data
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function activity(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        // Extract parameters.
        $params   = $this->extractRequestParameters();
        $interval = $this->request->getParam(key: 'interval', default: 'day');

        try {
            $result = $this->searchTrailService->getSearchActivity(
                interval: $interval,
                from: $params['from'],
                to: $params['to']
            );

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => 'Failed to get search activity: '.$e->getMessage()], statusCode: 500);
        }
    }//end activity()

    /**
     * Get search statistics by register and schema
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with register schema statistics
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function registerSchemaStats(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

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
            $paginatedStats = $this->paginate(
                results: $statistics,
                total: $totalCombinations,
                limit: $limit,
                offset: $offset,
                page: $page
            );

            // Add the additional metadata from the service.
            $paginatedStats['total_searches'] = $totalSearches;
            $paginatedStats['period']         = $period;

            return new JSONResponse(data: $paginatedStats);
        } catch (\Exception $e) {
            $errorMsg = 'Failed to get register/schema statistics: '.$e->getMessage();
            return new JSONResponse(data: ['error' => $errorMsg], statusCode: 500);
        }//end try
    }//end registerSchemaStats()

    /**
     * Get user agent statistics
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with user agent statistics
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function userAgentStats(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

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
            if (($serviceResult['user_agents'] ?? null) !== null) {
                // Extract the user agents array and metadata from structured response.
                // GetUserAgentStatistics returns: user_agents, browser_distribution, total_user_agents, period.
                $userAgentsArray = $serviceResult['user_agents'];
                // Ensure we have a proper indexed array for pagination.
                $userAgents = [];
                if (is_array($userAgentsArray) === true) {
                    $userAgents = array_values($userAgentsArray);
                }

                $totalUniqueAgents = $serviceResult['total_user_agents'] ?? 0;
                $totalSearches     = 0;
                // Not returned by getUserAgentStatistics.
                $period       = $serviceResult['period'] ?? null;
                $browserStats = $serviceResult['browser_distribution'] ?? null;

                // Use pagination format for the user agents array.
                $page   = $params['page'] ?? 1;
                $offset = $params['offset'] ?? 0;
                $paginatedUserAgents = $this->paginate(
                    results: $userAgents,
                    total: $totalUniqueAgents,
                    limit: $limit,
                    offset: $offset,
                    page: $page
                );

                // Add the additional metadata from the service.
                $paginatedUserAgents['total_searches'] = $totalSearches;
                $paginatedUserAgents['period']         = $period;
                if ($browserStats !== null && empty($browserStats) === false) {
                    $paginatedUserAgents['browser_breakdown'] = $browserStats;
                }

                return new JSONResponse(data: $paginatedUserAgents);
            }//end if

            // If service returns a simple array, statusCode: treat it as the user agents list.
            // $serviceResult is always an array at this point (non-null).
            $userAgentsArray = $serviceResult;
            // Ensure we have a proper indexed array for pagination.
            // $userAgentsArray is always an array at this point, but may be associative.
            $userAgents        = array_values($userAgentsArray);
            $totalUniqueAgents = count($userAgents);

            // Use pagination format for the user agents array.
            $page   = $params['page'] ?? 1;
            $offset = $params['offset'] ?? 0;
            $paginatedUserAgents = $this->paginate(
                results: $userAgents,
                total: $totalUniqueAgents,
                limit: $limit,
                offset: $offset,
                page: $page
            );

            return new JSONResponse(data: $paginatedUserAgents);
        } catch (\Exception $e) {
            $errorMsg = 'Failed to get user agent statistics: '.$e->getMessage();
            return new JSONResponse(data: ['error' => $errorMsg], statusCode: 500);
        }//end try
    }//end userAgentStats()

    /**
     * Clean up old search trail logs
     *
     * @return JSONResponse JSON response containing cleanup operation results
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|400|500,
     *     array{
     *         error?: string,
     *         success?: bool,
     *         deleted?: 0|1,
     *         message?: 'Cleanup operation failed'|'No expired entries to delete'
     *             |'Successfully deleted expired search trail entries',
     *         cleanup_date?: string
     *     },
     *     array<never, never>
     * >
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function cleanup(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        // Extract date parameter.
        $before     = $this->request->getParam(key: 'before');
        $beforeDate = null;

        if ($before !== null) {
            try {
                $beforeDate = new DateTime($before);
            } catch (\Exception $e) {
                return new JSONResponse(data: ['error' => 'Invalid date format for before parameter'], statusCode: 400);
            }
        }

        try {
            $result = $this->searchTrailService->cleanupSearchTrails($beforeDate);

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => 'Cleanup failed: '.$e->getMessage()], statusCode: 500);
        }
    }//end cleanup()

    /**
     * Export search trail logs in specified format
     *
     * Admin-only at the framework level (no @NoAdminRequired): search-trail
     * rows carry per-search IP, user id, user-agent and query string across
     * every register/schema (cross-tenant PII, wave-3 C7), like the sibling
     * analytics/destructive endpoints. Body `requireAdmin()` is defence-in-depth.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with export data
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function export(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

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
                config: [
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
            // Default to CSV.
            $content     = $this->arrayToCsv(data: $exportData);
            $contentType = 'text/csv';
            $filename    = 'search-trails-'.date('Y-m-d-H-i-s').'.csv';
            if ($format === 'json') {
                $content     = json_encode($exportData, JSON_PRETTY_PRINT);
                $contentType = 'application/json';
                $filename    = 'search-trails-'.date('Y-m-d-H-i-s').'.json';
            }

            // Return export data.
            return new JSONResponse(
                data: [
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
                data: [
                    'error' => 'Export failed: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end export()

    /**
     * Delete a single search trail log
     *
     * @param int $id The search trail ID to delete
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with deletion result
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function destroy(int $id): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        try {
            // Validate that search trail exists (validation only).
            $this->searchTrailService->getSearchTrail($id);

            // For now, we'll just return a success message since we don't have a delete method in the service.
            // In a real implementation, you'd add a deleteSearchTrail method to the service.
            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'Search trail deletion not implemented yet',
                ]
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Search trail not found',
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Deletion failed: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end destroy()

    /**
     * Delete multiple search trail logs based on filters or specific IDs
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with multiple deletion result
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-91
     */
    public function destroyMultiple(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        try {
            // TODO: Implement multiple search trail deletion.
            // $ids = $this->request->getParam(key: 'ids', default: null);
            // For now, we'll just return a success message since we don't have a delete method in the service.
            // In a real implementation, you'd add a deleteMultipleSearchTrails method to the service.
            $result = [
                'deleted' => 0,
                'failed'  => 0,
                'message' => 'Multiple search trail deletion not implemented yet',
            ];

            return new JSONResponse(
                data: [
                    'success' => true,
                    'results' => $result,
                    'message' => 'Multiple search trail deletion not implemented yet',
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Mass deletion failed: '.$e->getMessage(),
                ],
                statusCode: 500
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

        // Add data rows. Flatten any array/object cell to a JSON string so
        // fputcsv doesn't emit an "Array to string conversion" warning (and
        // write the literal "Array") for nested values.
        foreach ($data as $row) {
            $flatRow = array_map(
                static function ($cell) {
                    if (is_array($cell) === true || is_object($cell) === true) {
                        return json_encode($cell);
                    }

                    return $cell;
                },
                $row
            );
            fputcsv($output, $flatRow);
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
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
     */
    public function clearAll(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }

        try {
            /*
             * Get the search trail mapper from the container.
             * @var \OCA\OpenRegister\Db\SearchTrailMapper $searchTrailMapper
             */

            $searchTrailMapper = \OC::$server->get(id: 'OCA\OpenRegister\Db\SearchTrailMapper');

                    // Use the clearAllLogs method from the mapper.
                    $result = $searchTrailMapper->clearAllLogs();

            if ($result === true) {
                return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'All search trails cleared successfully',
                        'deleted' => 'All expired search trails have been deleted',
                    ]
                );
            }

            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'No expired search trails found to clear',
                    'deleted' => 0,
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => 'Failed to clear search trails: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end clearAll()
}//end class
