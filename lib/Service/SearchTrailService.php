<?php

/**
 * OpenRegister SearchTrailService
 *
 * Service class for managing search trail operations in the OpenRegister application.
 * This service acts as a business logic layer between controllers and the SearchTrailMapper,
 * providing comprehensive search analytics and logging functionality.
 *
 * This service also supports self-clearing (automatic cleanup) of old search trails.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service class for managing search trail operations
 *
 * This service provides business logic for search trail logging, analytics,
 * management operations, and supports self-clearing (automatic cleanup) of old search trails.
 */
class SearchTrailService
{
    /**
     * Whether self-clearing (automatic cleanup) is enabled.
     * Disabled by default - cleanup should be handled by cron jobs.
     *
     * @var boolean
     */
    private bool $selfClearingEnabled = false;

    /**
     * Retention period in days for search trails.
     *
     * @var integer
     */
    private int $retentionDays = 365;

    /**
     * Constructor for SearchTrailService
     *
     * Initializes service with required mappers and optional configuration.
     * Sets retention period and self-clearing flag if provided.
     *
     * @param SearchTrailMapper $searchTrailMapper Mapper for search trail database operations
     * @param RegisterMapper    $registerMapper    Mapper for register database operations
     * @param SchemaMapper      $schemaMapper      Mapper for schema database operations
     * @param int|null          $retentionDays     Optional retention period in days (default: 365)
     * @param bool|null         $selfClearing      Optional flag to enable/disable self-clearing
     *                                             (default: false, use cron jobs instead)
     *
     * @return void
     */
    public function __construct(
        private readonly SearchTrailMapper $searchTrailMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        ?int $retentionDays = null,
        ?bool $selfClearing = null
    ) {
        // Set retention period if provided, otherwise use default (365 days).
        if ($retentionDays !== null) {
            $this->retentionDays = $retentionDays;
        }

        // Set self-clearing flag if provided, otherwise use default (false).
        if ($selfClearing !== null) {
            $this->selfClearingEnabled = $selfClearing;
        }
    }//end __construct()

    /**
     * Create a search trail log entry
     *
     * Processes search query parameters and creates a comprehensive search trail entry
     * for analytics and monitoring purposes. System parameters (starting with _) are
     * automatically excluded from tracking by the mapper.
     *
     * If self-clearing is enabled, automatically triggers cleanup of old search trails
     * after creating the new entry.
     *
     * @param array<string, mixed> $query         The search query parameters (system params excluded)
     * @param int                  $resultCount   The number of results returned in this page
     * @param int                  $totalResults  The total number of matching results
     * @param float                $responseTime  The response time in milliseconds (default: 0.0)
     * @param string               $executionType The execution type: 'sync' or 'async' (default: 'sync')
     *
     * @return SearchTrail The created search trail entity
     *
     * @throws Exception If search trail creation fails (database error, validation error, etc.)
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function createSearchTrail(
        array $query,
        int $resultCount,
        int $totalResults,
        float $responseTime = 0.0,
        string $executionType = 'sync'
    ): SearchTrail {
        try {
            // Step 1: Create search trail entry using mapper.
            // Mapper handles filtering of system parameters (starting with _).
            $trail = $this->searchTrailMapper->createSearchTrail(
                searchQuery: $query,
                resultCount: $resultCount,
                totalResults: $totalResults,
                responseTime: $responseTime,
                executionType: $executionType
            );

            // Step 2: Self-clearing: automatically clean up old search trails if enabled.
            // This prevents database growth but may impact performance on high-traffic systems.
            if ($this->selfClearingEnabled === true) {
                $this->clearExpiredSearchTrails();
            }

            // Step 3: Return created search trail entity.
            return $trail;
        } catch (Exception $e) {
            // Wrap exception with more context for debugging.
            throw new Exception("Search trail creation failed: " . $e->getMessage(), 0, $e);
        }//end try
    }//end createSearchTrail()

    /**
     * Clean up expired search trails
     *
     * This method deletes search trails that have expired based on their expires column.
     * Intended to be called by cron jobs or manual cleanup operations.
     *
     * @return (bool|int|string)[] Cleanup results
     *
     * @psalm-return array{
     *     success: bool,
     *     deleted: 0|1,
     *     error?: string,
     *     message: 'Self-clearing operation failed'|
     *              'Self-clearing: deleted expired search trail entries'|
     *              'Self-clearing: no expired entries to delete',
     *     cleanup_date?: string
     * }
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function clearExpiredSearchTrails(): array
    {
        try {
            $deletedCount = $this->searchTrailMapper->clearLogs();

            // ClearLogs returns boolean, not count.
            $deletedValue = 0;
            $message      = "Self-clearing: no expired entries to delete";
            if ($deletedCount === true) {
                $deletedValue = 1;
                $message      = "Self-clearing: deleted expired search trail entries";
            }

            return [
                'success'      => true,
                'deleted'      => $deletedValue,
                'cleanup_date' => (new DateTime())->format('Y-m-d H:i:s'),
                'message'      => $message,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'deleted' => 0,
                'error'   => $e->getMessage(),
                'message' => 'Self-clearing operation failed',
            ];
        }//end try
    }//end clearExpiredSearchTrails()

    /**
     * Get paginated search trail logs
     *
     * @param array $config Configuration array containing:
     *                      - limit: Maximum number of results to return
     *                      - offset: Number of results to skip
     *                      - page: Page number (alternative to offset)
     *                      - filters: Filter criteria
     *                      - sort: Sort criteria
     *                      - search: Search term
     *                      - from: Start date filter
     *                      - to: End date filter
     *
     * @return (array|float|int)[]
     *
     * @psalm-return array{results: array, total: int, page: float|int<1, max>, pages: int, limit: int<1, max>, offset: int}
     */
    public function getSearchTrails(array $config = []): array
    {
        $processedConfig = $this->processConfig($config);

        $trails = $this->searchTrailMapper->findAll(
            limit: $processedConfig['limit'],
            offset: $processedConfig['offset'],
            filters: $processedConfig['filters'],
            sort: $processedConfig['sort'],
            search: $processedConfig['search'],
            from: $processedConfig['from'],
            to: $processedConfig['to']
        );

        // Enrich trails with register and schema names.
        $enrichedTrails = $this->enrichTrailsWithNames($trails);

        $total = $this->searchTrailMapper->count(
            filters: $processedConfig['filters'],
            search: $processedConfig['search'],
            from: $processedConfig['from'],
            to: $processedConfig['to']
        );

        return [
            'results' => $enrichedTrails,
            'total'   => $total,
            'page'    => $processedConfig['page'],
            'pages'   => $this->calculatePages(total: $total, limit: $processedConfig['limit']),
            'limit'   => $processedConfig['limit'],
            'offset'  => $processedConfig['offset'],
        ];
    }//end getSearchTrails()

    /**
     * Get a specific search trail by ID
     *
     * @param int $id The search trail ID
     *
     * @return SearchTrail The search trail entity
     *
     * @throws DoesNotExistException If the search trail is not found
     */
    public function getSearchTrail(int $id): SearchTrail
    {
        $trail = $this->searchTrailMapper->find($id);

        // Enrich single trail with register and schema names.
        $enrichedTrails = $this->enrichTrailsWithNames([$trail]);

        return $enrichedTrails[0];
    }//end getSearchTrail()

    /**
     * Get comprehensive search statistics
     *
     * @param DateTime|null $from Start date for statistics
     * @param DateTime|null $to   End date for statistics
     *
     * @return ((float|int|null|string)[]|float|int)[]
     *
     * @psalm-return array{total_searches: int<min, max>, total_results: int, avg_results_per_search: float, avg_response_time: float, non_empty_searches: int, searches_with_results: int, searches_without_results: int, success_rate: 0|float, unique_search_terms: int<0, max>, unique_users: int<0, max>, avg_searches_per_session: float, avg_object_views_per_session: float, unique_organizations: 0, query_complexity: array{simple: 0|float, medium: 0|float, complex: 0|float}, period: array{from: null|string, to: null|string, days: int<min, max>|null}, daily_averages?: array{searches_per_day: float, results_per_day: float}}
     */
    public function getSearchStatistics(?DateTime $from = null, ?DateTime $to = null): array
    {
        $baseStats = $this->searchTrailMapper->getSearchStatistics(from: $from, to: $to);

        // Add additional calculated metrics.
        $baseStats['searches_with_results']    = $baseStats['non_empty_searches'];
        $baseStats['searches_without_results'] = $baseStats['total_searches'] - $baseStats['non_empty_searches'];
        $baseStats['success_rate'] = 0;
        if ($baseStats['total_searches'] > 0) {
            $baseStats['success_rate'] = round(($baseStats['non_empty_searches'] / $baseStats['total_searches']) * 100, 2);
        }

        // Get unique search terms count.
        $uniqueSearchTermsCount           = $this->searchTrailMapper->getUniqueSearchTermsCount(from: $from, to: $to);
        $baseStats['unique_search_terms'] = $uniqueSearchTermsCount;

        // Get unique users count.
        $uniqueUsersCount          = $this->searchTrailMapper->getUniqueUsersCount(from: $from, to: $to);
        $baseStats['unique_users'] = $uniqueUsersCount;

        // Get session-based statistics.
        $baseStats['avg_searches_per_session']     = $this->searchTrailMapper->getAverageSearchesPerSession(from: $from, to: $to);
        $baseStats['avg_object_views_per_session'] = $this->searchTrailMapper->getAverageObjectViewsPerSession(from: $from, to: $to);

        // Get unique organizations count (placeholder for now).
        $baseStats['unique_organizations'] = 0;

        // Add query complexity analysis (placeholder implementation).
        $baseStats['query_complexity'] = [
            'simple'  => 0,
            'medium'  => 0,
            'complex' => 0,
        ];
        if ($baseStats['total_searches'] > 0) {
            $baseStats['query_complexity'] = [
                'simple'  => round($baseStats['total_searches'] * 0.6),
                'medium'  => round($baseStats['total_searches'] * 0.3),
                'complex' => round($baseStats['total_searches'] * 0.1),
            ];
        }

        // Add period information.
        $days = null;
        if ($from !== null && $to !== null) {
            $days = $from->diff($to)->days + 1;
        }

        $baseStats['period'] = [
            'from' => $from?->format('Y-m-d H:i:s'),
            'to'   => $to?->format('Y-m-d H:i:s'),
            'days' => $days,
        ];

        // Add daily averages if we have a time period.
        if ($baseStats['period']['days'] !== null && $baseStats['period']['days'] > 0) {
            $baseStats['daily_averages'] = [
                'searches_per_day' => round($baseStats['total_searches'] / $baseStats['period']['days'], 2),
                'results_per_day'  => round($baseStats['total_results'] / $baseStats['period']['days'], 2),
            ];
        }

        return $baseStats;
    }//end getSearchStatistics()

    /**
     * Get popular search terms with enhanced analytics
     *
     * @param int           $limit Maximum number of terms to return
     * @param DateTime|null $from  Start date filter
     * @param DateTime|null $to    End date filter
     *
     * @return (((float|int|mixed|string)[]|null|string)[]|int)[]
     *
     * @psalm-return array{terms: array<array{term: mixed, count: int, avg_results: float, avg_response_time: float, percentage: 0|float, effectiveness: 'high'|'low'}>, total_unique_terms: int<0, max>, total_searches: int, period: array{from: null|string, to: null|string}}
     */
    public function getPopularSearchTerms(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array
    {
        $terms = $this->searchTrailMapper->getPopularSearchTerms(limit: $limit, from: $from, to: $to);

        // Add enhanced analytics.
        $totalSearches = array_sum(array_column($terms, 'count'));
        $enhancedTerms = array_map(
            function ($term) use ($totalSearches) {
                $term['percentage'] = 0;
                if ($totalSearches > 0) {
                    $term['percentage'] = round(($term['count'] / $totalSearches) * 100, 2);
                }

                $term['effectiveness'] = 'low';
                if ($term['avg_results'] > 0) {
                    $term['effectiveness'] = 'high';
                }

                return $term;
            },
            $terms
        );

        return [
            'terms'              => $enhancedTerms,
            'total_unique_terms' => count($enhancedTerms),
            'total_searches'     => $totalSearches,
            'period'             => [
                'from' => $from?->format('Y-m-d H:i:s'),
                'to'   => $to?->format('Y-m-d H:i:s'),
            ],
        ];
    }//end getPopularSearchTerms()

    /**
     * Get search activity patterns by time period
     *
     * @param string        $interval Time interval ('hour', 'day', 'week', 'month')
     * @param DateTime|null $from     Start date filter
     * @param DateTime|null $to       End date filter
     *
     * @return (((float|int|mixed)[]|float|int|mixed|null|string)[]|string)[]
     *
     * @psalm-return array{activity: array<array{period: mixed, count: int, avg_results: float, avg_response_time: float}>, insights: array{peak_period: mixed|null, peak_count?: mixed, low_period: mixed|null, low_count?: mixed, trend: string, average_searches_per_period: 0|float, total_periods?: int<1, max>}, interval: string, period: array{from: null|string, to: null|string}}
     */
    public function getSearchActivity(string $interval = 'day', ?DateTime $from = null, ?DateTime $to = null): array
    {
        $activity = $this->searchTrailMapper->getSearchActivityByTime(interval: $interval, from: $from, to: $to);

        // Calculate trends and insights.
        $insights = $this->calculateActivityInsights(activity: $activity, _interval: $interval);

        return [
            'activity' => $activity,
            'insights' => $insights,
            'interval' => $interval,
            'period'   => [
                'from' => $from?->format('Y-m-d H:i:s'),
                'to'   => $to?->format('Y-m-d H:i:s'),
            ],
        ];
    }//end getSearchActivity()

    /**
     * Get search statistics by register and schema with insights
     *
     * @param DateTime|null $from Start date filter
     * @param DateTime|null $to   End date filter
     *
     * @return (((float|int|mixed|string)[]|null|string)[]|int)[]
     *
     * @psalm-return array{statistics: list<array{avg_response_time: float, avg_results: float, count: int, percentage: 0|float, performance_rating: string, register: mixed, register_uuid: mixed, schema: mixed, schema_uuid: mixed}>, total_combinations: int<0, max>, total_searches: int, period: array{from: null|string, to: null|string}}
     */
    public function getRegisterSchemaStatistics(?DateTime $from = null, ?DateTime $to = null): array
    {
        $stats = $this->searchTrailMapper->getSearchStatisticsByRegisterSchema(from: $from, to: $to);

        $totalSearches = array_sum(array_column($stats, 'count'));
        $enhancedStats = array_map(
            function ($stat) use ($totalSearches) {
                $stat['percentage'] = 0;
                if ($totalSearches > 0) {
                    $stat['percentage'] = round(($stat['count'] / $totalSearches) * 100, 2);
                }

                $stat['performance_rating'] = $this->calculatePerformanceRating($stat);

                return $stat;
            },
            $stats
        );

        // Sort by usage percentage.
        usort(
            $enhancedStats,
            function ($a, $b) {
                return $b['percentage'] <=> $a['percentage'];
            }
        );

        return [
            'statistics'         => $enhancedStats,
            'total_combinations' => count($enhancedStats),
            'total_searches'     => $totalSearches,
            'period'             => [
                'from' => $from?->format('Y-m-d H:i:s'),
                'to'   => $to?->format('Y-m-d H:i:s'),
            ],
        ];
    }//end getRegisterSchemaStatistics()

    /**
     * Get user agent statistics with browser insights
     *
     * @param int           $limit Maximum number of user agents to return
     * @param DateTime|null $from  Start date filter
     * @param DateTime|null $to    End date filter
     *
     * @return ((((int|string)|float|mixed|string[])[]|null|string)[]|int)[]
     *
     * @psalm-return array{user_agents: array<array{user_agent: mixed, count: int, avg_results: float, avg_response_time: float, browser_info: array{browser: string, version: string, full_string: string}}>, browser_distribution: list<array{browser: array-key, count: 0|mixed, percentage: 0|float}>, total_user_agents: int<0, max>, period: array{from: null|string, to: null|string}}
     */
    public function getUserAgentStatistics(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array
    {
        $stats = $this->searchTrailMapper->getUserAgentStatistics(limit: $limit, from: $from, to: $to);

        $enhancedStats = array_map(
            function ($stat) {
                $stat['browser_info'] = $this->parseUserAgent(userAgent: $stat['user_agent']);
                return $stat;
            },
            $stats
        );

        // Aggregate by browser type.
        $browserStats = $this->aggregateByBrowser($enhancedStats);

        return [
            'user_agents'          => $enhancedStats,
            'browser_distribution' => $browserStats,
            'total_user_agents'    => count($enhancedStats),
            'period'               => [
                'from' => $from?->format('Y-m-d H:i:s'),
                'to'   => $to?->format('Y-m-d H:i:s'),
            ],
        ];
    }//end getUserAgentStatistics()

    /**
     * Clean up old search trail logs
     *
     * @param DateTime|null $_before Delete entries older than this date (unused, kept for API compatibility)
     *
     * @return (bool|int|string)[] Cleanup results
     *
     * @psalm-return array{
     *     success: bool,
     *     deleted: 0|1,
     *     error?: string,
     *     message: 'Cleanup operation failed'|'No expired entries to delete'|'Successfully deleted expired search trail entries',
     *     cleanup_date?: string
     * }
     */
    public function cleanupSearchTrails(?DateTime $_before = null): array
    {
        try {
            // Note: clearLogs() only removes expired entries, ignoring the $before parameter
            // This maintains consistency with the audit trail cleanup approach.
            $deletedCount = $this->searchTrailMapper->clearLogs();

            // ClearLogs returns boolean, not count.
            $deletedValue = 0;
            $message      = "No expired entries to delete";
            if ($deletedCount === true) {
                $deletedValue = 1;
                $message      = "Successfully deleted expired search trail entries";
            }

            return [
                'success'      => true,
                'deleted'      => $deletedValue,
                'cleanup_date' => (new DateTime())->format('Y-m-d H:i:s'),
                'message'      => $message,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'deleted' => 0,
                'error'   => $e->getMessage(),
                'message' => 'Cleanup operation failed',
            ];
        }//end try
    }//end cleanupSearchTrails()

    /**
     * Process configuration parameters for search trail operations
     *
     * @param array $config Raw configuration parameters
     *
     * @return ((mixed|string)[]|DateTime|float|int|mixed|null)[]
     *
     * @psalm-return array{limit: int<1, max>, offset: int, page: float|int<1, max>, filters: array<int|string, mixed>, sort: array<'DESC'|mixed>, search: mixed|null, from: DateTime|null, to: DateTime|null}
     */
    private function processConfig(array $config): array
    {
        // Set defaults.
        $processed = [
            'limit'   => 20,
            'offset'  => null,
            'page'    => null,
            'filters' => [],
            'sort'    => ['created' => 'DESC'],
            'search'  => null,
            'from'    => null,
            'to'      => null,
        ];

        // Process pagination parameters.
        if (($config['limit'] ?? null) !== null) {
            $processed['limit'] = max(1, (int) $config['limit']);
        } elseif (($config['_limit'] ?? null) !== null) {
            $processed['limit'] = max(1, (int) $config['_limit']);
        }

        if (($config['offset'] ?? null) !== null) {
            $processed['offset'] = (int) $config['offset'];
        } elseif (($config['_offset'] ?? null) !== null) {
            $processed['offset'] = (int) $config['_offset'];
        }

        if (($config['page'] ?? null) !== null) {
            $processed['page'] = max(1, (int) $config['page']);
        } elseif (($config['_page'] ?? null) !== null) {
            $processed['page'] = max(1, (int) $config['_page']);
        }

        // Calculate offset from page if provided.
        if ($processed['page'] !== null && $processed['offset'] === null) {
            $processed['offset'] = ($processed['page'] - 1) * $processed['limit'];
        }

        // Calculate page from offset if not provided.
        if ($processed['page'] === null && $processed['offset'] !== null) {
            $processed['page'] = floor($processed['offset'] / $processed['limit']) + 1;
        }

        // Default page.
        $processed['page']   = $processed['page'] ?? 1;
        $processed['offset'] = $processed['offset'] ?? 0;

        // Process search parameter.
        $processed['search'] = $config['search'] ?? $config['_search'] ?? null;

        // Process sort parameters.
        if (($config['sort'] ?? null) !== null || (($config['_sort'] ?? null) !== null) === true) {
            $sortField         = $config['sort'] ?? $config['_sort'] ?? 'created';
            $sortOrder         = $config['order'] ?? $config['_order'] ?? 'DESC';
            $processed['sort'] = [$sortField => $sortOrder];
        }

        // Process date filters.
        if (($config['from'] ?? null) !== null) {
            try {
                $processed['from'] = new DateTime($config['from']);
            } catch (Exception $e) {
                // Invalid date format, ignore.
            }
        }

        if (($config['to'] ?? null) !== null) {
            try {
                $processed['to'] = new DateTime($config['to']);
            } catch (Exception $e) {
                // Invalid date format, ignore.
            }
        }

        // Process filters (exclude system parameters and pagination).
        $excludeKeys = [
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
        ];

        foreach ($config as $key => $value) {
            // Ensure key is a string or integer to avoid "Illegal offset type" error.
            if (is_string($key) === true || is_int($key) === true) {
                if (in_array($key, $excludeKeys, true) === false && str_starts_with((string) $key, '_') === false) {
                    $processed['filters'][$key] = $value;
                }
            }
        }

        return $processed;
    }//end processConfig()

    /**
     * Calculate activity insights from search activity data
     *
     * @param array  $activity  Search activity data
     * @param string $_interval Time interval used (unused, kept for API compatibility)
     *
     * @return (float|int|mixed|null|string)[]
     *
     * @psalm-return array{peak_period: mixed|null, peak_count?: mixed, low_period: mixed|null, low_count?: mixed, trend: string, average_searches_per_period: 0|float, total_periods?: int<1, max>}
     */
    private function calculateActivityInsights(array $activity, string $_interval): array
    {
        if ($activity === []) {
            return [
                'peak_period'                 => null,
                'low_period'                  => null,
                'trend'                       => 'no_data',
                'average_searches_per_period' => 0,
            ];
        }

        $counts   = array_column($activity, 'count');
        $maxCount = max($counts);
        $minCount = min($counts);
        $avgCount = array_sum($counts) / count($counts);

        $peakIndex = array_search($maxCount, $counts);
        $lowIndex  = array_search($minCount, $counts);

        // Calculate trend (simple linear regression).
        $trend = $this->calculateTrend($counts);

        return [
            'peak_period'                 => $activity[$peakIndex]['period'] ?? null,
            'peak_count'                  => $maxCount,
            'low_period'                  => $activity[$lowIndex]['period'] ?? null,
            'low_count'                   => $minCount,
            'trend'                       => $trend,
            'average_searches_per_period' => round($avgCount, 2),
            'total_periods'               => count($activity),
        ];
    }//end calculateActivityInsights()

    /**
     * Calculate trend direction from count data
     *
     * @param array $counts Array of count values
     *
     * @return string Trend direction ('increasing', 'decreasing', 'stable')
     *
     * @psalm-return 'decreasing'|'increasing'|'stable'
     */
    private function calculateTrend(array $counts): string
    {
        if (count($counts) < 2) {
            return 'stable';
        }

        $n     = count($counts);
        $sumX  = array_sum(range(0, $n - 1));
        $sumY  = array_sum($counts);
        $sumXY = 0;
        $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $i * $counts[$i];
            $sumX2 += $i * $i;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);

        if ($slope > 0.1) {
            return 'increasing';
        }

        if ($slope < -0.1) {
            return 'decreasing';
        }

        return 'stable';
    }//end calculateTrend()

    /**
     * Calculate performance rating for register/schema statistics
     *
     * @param array $stat Statistics data
     *
     * @return string Performance rating ('excellent', 'good', 'average', 'poor')
     */
    private function calculatePerformanceRating(array $stat): string
    {
        $avgResults      = $stat['avg_results'];
        $avgResponseTime = $stat['avg_response_time'];

        // Rate based on results and response time.
        if ($avgResults >= 10 && $avgResponseTime <= 100) {
            return 'excellent';
        }

        if ($avgResults >= 5 && $avgResponseTime <= 200) {
            return 'good';
        }

        if ($avgResults >= 1 && $avgResponseTime <= 500) {
            return 'average';
        }

        return 'poor';
    }//end calculatePerformanceRating()

    /**
     * Parse user agent string to extract browser information
     *
     * @param string $userAgent User agent string
     *
     * @return string[]
     *
     * @psalm-return array{browser: string, version: string, full_string: string}
     */
    private function parseUserAgent(string $userAgent): array
    {
        // Simple user agent parsing (could be enhanced with a proper library).
        $browsers = [
            'Chrome'  => '/Chrome\/([0-9.]+)/',
            'Firefox' => '/Firefox\/([0-9.]+)/',
            'Safari'  => '/Safari\/([0-9.]+)/',
            'Edge'    => '/Edge\/([0-9.]+)/',
            'Opera'   => '/Opera\/([0-9.]+)/',
        ];

        foreach ($browsers as $browser => $pattern) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                return [
                    'browser'     => $browser,
                    'version'     => $matches[1] ?? 'unknown',
                    'full_string' => $userAgent,
                ];
            }
        }

        return [
            'browser'     => 'unknown',
            'version'     => 'unknown',
            'full_string' => $userAgent,
        ];
    }//end parseUserAgent()

    /**
     * Aggregate user agent statistics by browser type
     *
     * @param array $userAgentStats User agent statistics
     *
     * @return ((int|string)|float|mixed)[][] Browser distribution statistics
     *
     * @psalm-return list<array{browser: array-key, count: 0|mixed, percentage: 0|float}>
     */
    private function aggregateByBrowser(array $userAgentStats): array
    {
        $browserCounts = [];

        foreach ($userAgentStats as $stat) {
            $browser = $stat['browser_info']['browser'];
            if (isset($browserCounts[$browser]) === false) {
                $browserCounts[$browser] = 0;
            }

            $browserCounts[$browser] += $stat['count'];
        }

        arsort($browserCounts);

        $total        = array_sum($browserCounts);
        $distribution = [];

        foreach ($browserCounts as $browser => $count) {
            $percentage = 0;
            if ($total > 0) {
                $percentage = round(($count / $total) * 100, 2);
            }

            $distribution[] = [
                'browser'    => $browser,
                'count'      => $count,
                'percentage' => $percentage,
            ];
        }

        return $distribution;
    }//end aggregateByBrowser()

    /**
     * Enrich search trails with register and schema names
     *
     * This method takes an array of SearchTrail entities and enriches them with
     * register and schema names by looking up the IDs in the respective tables.
     * Names are cached to avoid duplicate database calls.
     *
     * @param array $trails Array of SearchTrail entities
     *
     * @return array Array of enriched SearchTrail entities
     */
    private function enrichTrailsWithNames(array $trails): array
    {
        if ($trails === []) {
            return $trails;
        }

        // Collect unique register and schema IDs.
        $registerIds = [];
        $schemaIds   = [];

        foreach ($trails as $trail) {
            if ($trail->getRegister() !== null) {
                $registerIds[] = $trail->getRegister();
            }

            if ($trail->getSchema() !== null) {
                $schemaIds[] = $trail->getSchema();
            }
        }

        // Remove duplicates.
        $registerIds = array_unique($registerIds);
        $schemaIds   = array_unique($schemaIds);

        // Fetch register names.
        $registerNames = [];
        foreach ($registerIds as $registerId) {
            try {
                $register = $this->registerMapper->find($registerId);
                $registerNames[$registerId] = $register->getTitle();
            } catch (DoesNotExistException $e) {
                // Register not found, use fallback.
                $registerNames[$registerId] = "Register $registerId";
            } catch (Exception $e) {
                // Other error, use fallback.
                $registerNames[$registerId] = "Register $registerId";
            }
        }

        // Fetch schema names.
        $schemaNames = [];
        foreach ($schemaIds as $schemaId) {
            try {
                $schema = $this->schemaMapper->find($schemaId);
                $schemaNames[$schemaId] = $schema->getTitle();
            } catch (DoesNotExistException $e) {
                // Schema not found, use fallback.
                $schemaNames[$schemaId] = "Schema $schemaId";
            } catch (Exception $e) {
                // Other error, use fallback.
                $schemaNames[$schemaId] = "Schema $schemaId";
            }
        }

        // Enrich the trails with names.
        foreach ($trails as $trail) {
            if ($trail->getRegister() !== null && (($registerNames[$trail->getRegister()] ?? null) !== null) === true) {
                $trail->setRegisterName($registerNames[$trail->getRegister()]);
            }

            if ($trail->getSchema() !== null && (($schemaNames[$trail->getSchema()] ?? null) !== null) === true) {
                $trail->setSchemaName($schemaNames[$trail->getSchema()]);
            }
        }

        return $trails;
    }//end enrichTrailsWithNames()

    /**
     * Calculate total number of pages
     *
     * @param int $total Total number of items
     * @param int $limit Items per page
     *
     * @return int Total number of pages
     */
    private function calculatePages(int $total, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        return (int) ceil($total / $limit);
    }//end calculatePages()
}//end class
