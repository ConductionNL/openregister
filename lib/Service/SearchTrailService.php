<?php
/**
 * OpenRegister SearchTrailService
 *
 * Service class for managing search trail operations in the OpenRegister application.
 * This service acts as a business logic layer between controllers and the SearchTrailMapper,
 * providing comprehensive search analytics and logging functionality.
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
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service class for managing search trail operations
 *
 * This service provides business logic for search trail logging, analytics,
 * and management operations.
 */
class SearchTrailService
{


    /**
     * Constructor for SearchTrailService
     *
     * @param SearchTrailMapper $searchTrailMapper Mapper for search trail database operations
     */
    public function __construct(
        private readonly SearchTrailMapper $searchTrailMapper
    ) {

    }//end __construct()


    /**
     * Create a search trail log entry
     *
     * This method processes search query parameters and creates a comprehensive
     * search trail entry for analytics and monitoring purposes. System parameters
     * (starting with _) are automatically excluded from tracking.
     *
     * @param array  $query         The search query parameters
     * @param int    $resultCount   The number of results returned
     * @param int    $totalResults  The total number of matching results
     * @param float  $responseTime  The response time in milliseconds
     * @param string $executionType The execution type ('sync' or 'async')
     *
     * @return SearchTrail The created search trail entity
     *
     * @throws Exception If search trail creation fails
     */
    public function createSearchTrail(
        array $query,
        int $resultCount,
        int $totalResults,
        float $responseTime = 0.0,
        string $executionType = 'sync'
    ): SearchTrail {
        try {
            return $this->searchTrailMapper->createSearchTrail(
                $query,
                $resultCount,
                $totalResults,
                $responseTime,
                $executionType
            );
        } catch (Exception $e) {
            error_log("Failed to create search trail: " . $e->getMessage());
            throw new Exception("Search trail creation failed: " . $e->getMessage(), 0, $e);
        }

    }//end createSearchTrail()


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
     * @return array Array containing search trails and pagination information
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

        $total = $this->searchTrailMapper->count(
            filters: $processedConfig['filters'],
            search: $processedConfig['search'],
            from: $processedConfig['from'],
            to: $processedConfig['to']
        );

        return [
            'results' => $trails,
            'total' => $total,
            'page' => $processedConfig['page'],
            'pages' => $processedConfig['limit'] > 0 ? ceil($total / $processedConfig['limit']) : 1,
            'limit' => $processedConfig['limit'],
            'offset' => $processedConfig['offset'],
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
        return $this->searchTrailMapper->find($id);

    }//end getSearchTrail()


    /**
     * Get comprehensive search statistics
     *
     * @param DateTime|null $from Start date for statistics
     * @param DateTime|null $to   End date for statistics
     *
     * @return array Comprehensive search statistics including trends and insights
     */
    public function getSearchStatistics(?DateTime $from = null, ?DateTime $to = null): array
    {
        $baseStats = $this->searchTrailMapper->getSearchStatistics($from, $to);

        // Add additional calculated metrics
        $baseStats['searches_with_results'] = $baseStats['non_empty_searches'];
        $baseStats['searches_without_results'] = $baseStats['total_searches'] - $baseStats['non_empty_searches'];
        $baseStats['success_rate'] = $baseStats['total_searches'] > 0 
            ? round(($baseStats['non_empty_searches'] / $baseStats['total_searches']) * 100, 2) 
            : 0;

        // Add period information
        $baseStats['period'] = [
            'from' => $from?->format('Y-m-d H:i:s'),
            'to' => $to?->format('Y-m-d H:i:s'),
            'days' => $from && $to ? $from->diff($to)->days + 1 : null,
        ];

        // Add daily averages if we have a time period
        if ($baseStats['period']['days'] && $baseStats['period']['days'] > 0) {
            $baseStats['daily_averages'] = [
                'searches_per_day' => round($baseStats['total_searches'] / $baseStats['period']['days'], 2),
                'results_per_day' => round($baseStats['total_results'] / $baseStats['period']['days'], 2),
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
     * @return array Enhanced popular search terms data
     */
    public function getPopularSearchTerms(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array
    {
        $terms = $this->searchTrailMapper->getPopularSearchTerms($limit, $from, $to);

        // Add enhanced analytics
        $totalSearches = array_sum(array_column($terms, 'count'));
        $enhancedTerms = array_map(function ($term) use ($totalSearches) {
            $term['percentage'] = $totalSearches > 0 
                ? round(($term['count'] / $totalSearches) * 100, 2) 
                : 0;
            $term['effectiveness'] = $term['avg_results'] > 0 ? 'high' : 'low';
            return $term;
        }, $terms);

        return [
            'terms' => $enhancedTerms,
            'total_unique_terms' => count($enhancedTerms),
            'total_searches' => $totalSearches,
            'period' => [
                'from' => $from?->format('Y-m-d H:i:s'),
                'to' => $to?->format('Y-m-d H:i:s'),
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
     * @return array Search activity data with trends and insights
     */
    public function getSearchActivity(string $interval = 'day', ?DateTime $from = null, ?DateTime $to = null): array
    {
        $activity = $this->searchTrailMapper->getSearchActivityByTime($interval, $from, $to);

        // Calculate trends and insights
        $insights = $this->calculateActivityInsights($activity, $interval);

        return [
            'activity' => $activity,
            'insights' => $insights,
            'interval' => $interval,
            'period' => [
                'from' => $from?->format('Y-m-d H:i:s'),
                'to' => $to?->format('Y-m-d H:i:s'),
            ],
        ];

    }//end getSearchActivity()


    /**
     * Get search statistics by register and schema with insights
     *
     * @param DateTime|null $from Start date filter
     * @param DateTime|null $to   End date filter
     *
     * @return array Enhanced register/schema statistics
     */
    public function getRegisterSchemaStatistics(?DateTime $from = null, ?DateTime $to = null): array
    {
        $stats = $this->searchTrailMapper->getSearchStatisticsByRegisterSchema($from, $to);

        $totalSearches = array_sum(array_column($stats, 'count'));
        $enhancedStats = array_map(function ($stat) use ($totalSearches) {
            $stat['percentage'] = $totalSearches > 0 
                ? round(($stat['count'] / $totalSearches) * 100, 2) 
                : 0;
            $stat['performance_rating'] = $this->calculatePerformanceRating($stat);
            return $stat;
        }, $stats);

        // Sort by usage percentage
        usort($enhancedStats, function ($a, $b) {
            return $b['percentage'] <=> $a['percentage'];
        });

        return [
            'statistics' => $enhancedStats,
            'total_combinations' => count($enhancedStats),
            'total_searches' => $totalSearches,
            'period' => [
                'from' => $from?->format('Y-m-d H:i:s'),
                'to' => $to?->format('Y-m-d H:i:s'),
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
     * @return array Enhanced user agent statistics
     */
    public function getUserAgentStatistics(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array
    {
        $stats = $this->searchTrailMapper->getUserAgentStatistics($limit, $from, $to);

        $enhancedStats = array_map(function ($stat) {
            $stat['browser_info'] = $this->parseUserAgent($stat['user_agent']);
            return $stat;
        }, $stats);

        // Aggregate by browser type
        $browserStats = $this->aggregateByBrowser($enhancedStats);

        return [
            'user_agents' => $enhancedStats,
            'browser_distribution' => $browserStats,
            'total_user_agents' => count($enhancedStats),
            'period' => [
                'from' => $from?->format('Y-m-d H:i:s'),
                'to' => $to?->format('Y-m-d H:i:s'),
            ],
        ];

    }//end getUserAgentStatistics()


    /**
     * Clean up old search trail logs
     *
     * @param DateTime|null $before Delete entries older than this date
     *
     * @return array Cleanup results
     */
    public function cleanupSearchTrails(?DateTime $before = null): array
    {
        try {
            $deletedCount = $this->searchTrailMapper->cleanup($before);

            return [
                'success' => true,
                'deleted' => $deletedCount,
                'cleanup_date' => $before?->format('Y-m-d H:i:s') ?? (new DateTime('-1 year'))->format('Y-m-d H:i:s'),
                'message' => "Successfully deleted {$deletedCount} old search trail entries",
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'deleted' => 0,
                'error' => $e->getMessage(),
                'message' => 'Cleanup operation failed',
            ];
        }

    }//end cleanupSearchTrails()


    /**
     * Process configuration parameters for search trail operations
     *
     * @param array $config Raw configuration parameters
     *
     * @return array Processed configuration parameters
     */
    private function processConfig(array $config): array
    {
        // Set defaults
        $processed = [
            'limit' => 20,
            'offset' => null,
            'page' => null,
            'filters' => [],
            'sort' => ['created' => 'DESC'],
            'search' => null,
            'from' => null,
            'to' => null,
        ];

        // Process pagination parameters
        if (isset($config['limit'])) {
            $processed['limit'] = max(1, (int) $config['limit']);
        } else if (isset($config['_limit'])) {
            $processed['limit'] = max(1, (int) $config['_limit']);
        }

        if (isset($config['offset'])) {
            $processed['offset'] = (int) $config['offset'];
        } else if (isset($config['_offset'])) {
            $processed['offset'] = (int) $config['_offset'];
        }

        if (isset($config['page'])) {
            $processed['page'] = max(1, (int) $config['page']);
        } else if (isset($config['_page'])) {
            $processed['page'] = max(1, (int) $config['_page']);
        }

        // Calculate offset from page if provided
        if ($processed['page'] !== null && $processed['offset'] === null) {
            $processed['offset'] = ($processed['page'] - 1) * $processed['limit'];
        }

        // Calculate page from offset if not provided
        if ($processed['page'] === null && $processed['offset'] !== null) {
            $processed['page'] = floor($processed['offset'] / $processed['limit']) + 1;
        }

        // Default page
        $processed['page'] = $processed['page'] ?? 1;
        $processed['offset'] = $processed['offset'] ?? 0;

        // Process search parameter
        $processed['search'] = $config['search'] ?? $config['_search'] ?? null;

        // Process sort parameters
        if (isset($config['sort']) || isset($config['_sort'])) {
            $sortField = $config['sort'] ?? $config['_sort'] ?? 'created';
            $sortOrder = $config['order'] ?? $config['_order'] ?? 'DESC';
            $processed['sort'] = [$sortField => $sortOrder];
        }

        // Process date filters
        if (isset($config['from'])) {
            try {
                $processed['from'] = new DateTime($config['from']);
            } catch (Exception $e) {
                // Invalid date format, ignore
            }
        }
        if (isset($config['to'])) {
            try {
                $processed['to'] = new DateTime($config['to']);
            } catch (Exception $e) {
                // Invalid date format, ignore
            }
        }

        // Process filters (exclude system parameters and pagination)
        $excludeKeys = [
            'limit', '_limit', 'offset', '_offset', 'page', '_page',
            'search', '_search', 'sort', '_sort', 'order', '_order',
            'from', 'to', '_route', 'id'
        ];

        foreach ($config as $key => $value) {
            if (!in_array($key, $excludeKeys) && !str_starts_with($key, '_')) {
                $processed['filters'][$key] = $value;
            }
        }

        return $processed;

    }//end processConfig()


    /**
     * Calculate activity insights from search activity data
     *
     * @param array  $activity Search activity data
     * @param string $interval Time interval used
     *
     * @return array Activity insights and trends
     */
    private function calculateActivityInsights(array $activity, string $interval): array
    {
        if (empty($activity)) {
            return [
                'peak_period' => null,
                'low_period' => null,
                'trend' => 'no_data',
                'average_searches_per_period' => 0,
            ];
        }

        $counts = array_column($activity, 'count');
        $maxCount = max($counts);
        $minCount = min($counts);
        $avgCount = array_sum($counts) / count($counts);

        $peakIndex = array_search($maxCount, $counts);
        $lowIndex = array_search($minCount, $counts);

        // Calculate trend (simple linear regression)
        $trend = $this->calculateTrend($counts);

        return [
            'peak_period' => $activity[$peakIndex]['period'] ?? null,
            'peak_count' => $maxCount,
            'low_period' => $activity[$lowIndex]['period'] ?? null,
            'low_count' => $minCount,
            'trend' => $trend,
            'average_searches_per_period' => round($avgCount, 2),
            'total_periods' => count($activity),
        ];

    }//end calculateActivityInsights()


    /**
     * Calculate trend direction from count data
     *
     * @param array $counts Array of count values
     *
     * @return string Trend direction ('increasing', 'decreasing', 'stable')
     */
    private function calculateTrend(array $counts): string
    {
        if (count($counts) < 2) {
            return 'stable';
        }

        $n = count($counts);
        $sumX = array_sum(range(0, $n - 1));
        $sumY = array_sum($counts);
        $sumXY = 0;
        $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $i * $counts[$i];
            $sumX2 += $i * $i;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);

        if ($slope > 0.1) {
            return 'increasing';
        } else if ($slope < -0.1) {
            return 'decreasing';
        } else {
            return 'stable';
        }

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
        $avgResults = $stat['avg_results'];
        $avgResponseTime = $stat['avg_response_time'];

        // Rate based on results and response time
        if ($avgResults >= 10 && $avgResponseTime <= 100) {
            return 'excellent';
        } else if ($avgResults >= 5 && $avgResponseTime <= 200) {
            return 'good';
        } else if ($avgResults >= 1 && $avgResponseTime <= 500) {
            return 'average';
        } else {
            return 'poor';
        }

    }//end calculatePerformanceRating()


    /**
     * Parse user agent string to extract browser information
     *
     * @param string $userAgent User agent string
     *
     * @return array Browser information
     */
    private function parseUserAgent(string $userAgent): array
    {
        // Simple user agent parsing (could be enhanced with a proper library)
        $browsers = [
            'Chrome' => '/Chrome\/([0-9.]+)/',
            'Firefox' => '/Firefox\/([0-9.]+)/',
            'Safari' => '/Safari\/([0-9.]+)/',
            'Edge' => '/Edge\/([0-9.]+)/',
            'Opera' => '/Opera\/([0-9.]+)/',
        ];

        foreach ($browsers as $browser => $pattern) {
            if (preg_match($pattern, $userAgent, $matches)) {
                return [
                    'browser' => $browser,
                    'version' => $matches[1] ?? 'unknown',
                    'full_string' => $userAgent,
                ];
            }
        }

        return [
            'browser' => 'unknown',
            'version' => 'unknown',
            'full_string' => $userAgent,
        ];

    }//end parseUserAgent()


    /**
     * Aggregate user agent statistics by browser type
     *
     * @param array $userAgentStats User agent statistics
     *
     * @return array Browser distribution statistics
     */
    private function aggregateByBrowser(array $userAgentStats): array
    {
        $browserCounts = [];

        foreach ($userAgentStats as $stat) {
            $browser = $stat['browser_info']['browser'];
            if (!isset($browserCounts[$browser])) {
                $browserCounts[$browser] = 0;
            }
            $browserCounts[$browser] += $stat['count'];
        }

        arsort($browserCounts);

        $total = array_sum($browserCounts);
        $distribution = [];

        foreach ($browserCounts as $browser => $count) {
            $distribution[] = [
                'browser' => $browser,
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
            ];
        }

        return $distribution;

    }//end aggregateByBrowser()


}//end class 