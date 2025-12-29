<?php

/**
 * OpenRegister Metrics Service
 *
 * Service for tracking and retrieving operational metrics.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * MetricsService
 *
 * Service for tracking and retrieving operational metrics.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */

class MetricsService
{

    /**
     * Database connection instance.
     *
     * @var IDBConnection Database connection
     */
    private IDBConnection $db;

    /**
     * Logger instance.
     *
     * @var LoggerInterface Logger
     */
    private LoggerInterface $logger;

    /**
     * Metric type constant for file processing operations
     *
     * @var string
     */
    public const METRIC_FILE_PROCESSED = 'file_processed';

    /**
     * Metric type constant for object vectorization operations
     *
     * @var string
     */
    public const METRIC_OBJECT_VECTORIZED = 'object_vectorized';

    /**
     * Metric type constant for embedding generation operations
     *
     * @var string
     */
    public const METRIC_EMBEDDING_GENERATED = 'embedding_generated';

    /**
     * Metric type constant for semantic search operations
     *
     * @var string
     */
    public const METRIC_SEARCH_SEMANTIC = 'search_semantic';

    /**
     * Metric type constant for hybrid search operations
     *
     * @var string
     */
    public const METRIC_SEARCH_HYBRID = 'search_hybrid';

    /**
     * Metric type constant for keyword search operations
     *
     * @var string
     */
    public const METRIC_SEARCH_KEYWORD = 'search_keyword';

    /**
     * Metric type constant for chat message operations
     *
     * @var string
     */
    public const METRIC_CHAT_MESSAGE = 'chat_message';

    /**
     * Constructor
     *
     * @param IDBConnection   $db     Database connection
     * @param LoggerInterface $logger Logger
     */
    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->db     = $db;
        $this->logger = $logger;
    }//end __construct()

    /**
     * Record a metric
     *
     * Records operational metrics to the database for tracking performance,
     * success rates, and usage statistics. Metrics are stored with timestamps
     * and can include optional metadata for detailed analysis.
     *
     * @param string      $metricType   Type of metric (use class constants)
     * @param string|null $entityType   Entity type (file, object, etc.)
     * @param string|null $entityId     Entity ID
     * @param string      $status       Status (success, failure)
     * @param int|null    $durationMs   Duration in milliseconds
     * @param array|null  $metadata     Additional metadata
     * @param string|null $errorMessage Error message (if failed)
     * @param string|null $userId       User ID
     *
     * @return void
     *
     * @throws \Exception If database operation fails (logged but not rethrown)
     *
     * @psalm-suppress PossiblyNullArgument
     */
    public function recordMetric(
        string $metricType,
        ?string $entityType=null,
        ?string $entityId=null,
        string $status='success',
        ?int $durationMs=null,
        ?array $metadata=null,
        ?string $errorMessage=null,
        ?string $userId=null
    ): void {
        try {
            // Get query builder instance for database operations.
            $qb = $this->db->getQueryBuilder();

            // Build INSERT query for metrics table.
            // Create named parameters for all values to prevent SQL injection.
            $qb->insert('openregister_metrics')
                ->values(
                    values: [
                        [
                            'metric_type'   => $qb->createNamedParameter($metricType),
                            'entity_type'   => $qb->createNamedParameter($entityType),
                            'entity_id'     => $qb->createNamedParameter($entityId),
                            'user_id'       => $qb->createNamedParameter($userId),
                            'status'        => $qb->createNamedParameter($status),
                            'duration_ms'   => $qb->createNamedParameter($durationMs),
                            'metadata'      => $qb->createNamedParameter($this->encodeMetadata($metadata)),
                            'error_message' => $qb->createNamedParameter($errorMessage),
                            'created_at'    => $qb->createNamedParameter(time()),
                        ],
                    ]
                );

            // Execute the insert query.
            $qb->executeStatement();
        } catch (\Exception $e) {
            // Log errors but don't throw to prevent disrupting main operations.
            // Metrics recording failures should not break application functionality.
            $this->logger->error(
                message: '[MetricsService] Failed to record metric',
                context: [
                    'metric_type' => $metricType,
                    'error'       => $e->getMessage(),
                ]
            );
        }//end try
    }//end recordMetric()

    /**
     * Get files processed per day for last N days
     *
     * @param int $days Number of days to retrieve
     *
     * @return int[] Array of [date => count]
     *
     * @psalm-return array<int>
     */
    public function getFilesProcessedPerDay(int $days=30): array
    {
        $qb = $this->db->getQueryBuilder();

        $startTime = time() - ($days * 86400);

        $qb->select($qb->func()->count('*', 'count'))
            ->selectAlias($qb->createFunction('FROM_UNIXTIME(created_at, \'%Y-%m-%d\')'), 'date')
            ->from('openregister_metrics')
            ->where($qb->expr()->eq('metric_type', $qb->createNamedParameter(self::METRIC_FILE_PROCESSED)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($startTime)))
            ->groupBy('date')
            ->orderBy('date', 'ASC');

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        $data = [];
        foreach ($rows as $row) {
            $data[$row['date']] = (int) $row['count'];
        }

        return $data;
    }//end getFilesProcessedPerDay()

    /**
     * Get embedding generation success rate
     *
     * Analyzes embedding generation metrics over specified period.
     * Calculates success rate, failure count, and estimated costs based on
     * OpenAI pricing for text-embedding-3-large model.
     *
     * @param int $days Number of days to analyze (default: 30)
     *
     * @return array<string, int|float> Success rate and costs with keys:
     *                                  - total: Total number of embedding operations
     *                                  - successful: Number of successful operations
     *                                  - failed: Number of failed operations
     *                                  - success_rate: Success rate percentage (0-100)
     *                                  - estimated_cost_usd: Estimated cost in USD
     *                                  - period_days: Number of days analyzed
     *
     * @psalm-return array{total: int, successful: int, failed: int, success_rate: float, estimated_cost_usd: float, period_days: int}
     */
    public function getEmbeddingStats(int $days=30): array
    {
        // Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Calculate start timestamp (N days ago).
        $startTime = time() - ($days * 86400);

        // Build query to count total and successful embeddings.
        // Uses CASE statement to count successful operations (status = 'success').
        $qb->select($qb->func()->count('*', 'total'))
            ->selectAlias($qb->createFunction('SUM(CASE WHEN status = \'success\' THEN 1 ELSE 0 END)'), 'successful')
            ->from('openregister_metrics')
            ->where($qb->expr()->eq('metric_type', $qb->createNamedParameter(self::METRIC_EMBEDDING_GENERATED)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($startTime)));

        // Execute query and fetch single row result.
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        // Extract and cast values from database result.
        $total      = (int) ($row['total'] ?? 0);
        $successful = (int) ($row['successful'] ?? 0);

        // Calculate failed operations (total - successful).
        $failed = $total - $successful;

        // Calculate success rate percentage.
        $successRate = $this->calculateSuccessRate(total: $total, successful: $successful);

        // Calculate estimated costs based on OpenAI pricing.
        // Pricing: text-embedding-3-large = $0.00013 per 1K tokens.
        // Average: 500 tokens per embedding = 0.5K tokens.
        // Cost per embedding: $0.00013 * 0.5 = $0.000065.
        $estimatedCost = $successful * 0.000065;

        // Return comprehensive statistics array.
        return [
            'total'              => $total,
            'successful'         => $successful,
            'failed'             => $failed,
            'success_rate'       => round($successRate, 2),
            'estimated_cost_usd' => round($estimatedCost, 4),
            'period_days'        => $days,
        ];
    }//end getEmbeddingStats()

    /**
     * Get search latency statistics
     *
     * Analyzes search performance metrics for keyword, semantic, and hybrid searches.
     * Calculates count, average, minimum, and maximum latency for each search type.
     *
     * @param int $days Number of days to analyze (default: 7)
     *
     * @return (float|int)[][]
     *
     * @psalm-return array<string, array{count: int, avg_ms: float, min_ms: int, max_ms: int}>
     */
    public function getSearchLatencyStats(int $days=7): array
    {
        // Calculate start timestamp (N days ago).
        $startTime = time() - ($days * 86400);

        // Define search types to analyze.
        $searchTypes = [
            self::METRIC_SEARCH_KEYWORD,
            self::METRIC_SEARCH_SEMANTIC,
            self::METRIC_SEARCH_HYBRID,
        ];

        // Initialize stats array to collect results.
        $stats = [];

        // Process each search type separately.
        foreach ($searchTypes as $searchType) {
            // Get query builder instance for this iteration.
            $qb = $this->db->getQueryBuilder();

            // Build query to calculate latency statistics.
            // Only includes metrics with non-null duration_ms values.
            $qb->select($qb->func()->count('*', 'count'))
                ->selectAlias($qb->createFunction('AVG(duration_ms)'), 'avg_ms')
                ->selectAlias($qb->func()->min('duration_ms'), 'min_ms')
                ->selectAlias($qb->func()->max('duration_ms'), 'max_ms')
                ->from('openregister_metrics')
                ->where($qb->expr()->eq('metric_type', $qb->createNamedParameter($searchType)))
                ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($startTime)))
                ->andWhere($qb->expr()->isNotNull('duration_ms'));

            // Execute query and fetch single row result.
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            // Extract search type name (remove 'search_' prefix).
            // Example: 'search_keyword' -> 'keyword'.
            $type = str_replace('search_', '', $searchType);

            // Store statistics for this search type.
            $stats[$type] = [
                'count'  => (int) ($row['count'] ?? 0),
                'avg_ms' => $this->roundAverageMs($row['avg_ms']),
                'min_ms' => (int) ($row['min_ms'] ?? 0),
                'max_ms' => (int) ($row['max_ms'] ?? 0),
            ];
        }//end foreach

        return $stats;
    }//end getSearchLatencyStats()

    /**
     * Get vector database storage growth
     *
     * Analyzes vector database storage growth over specified period.
     * Calculates daily vector additions, total storage size, and average
     * vectors per day.
     *
     * @param int $days Number of days to analyze (default: 30)
     *
     * @return array<string, array<string, int>|int|float> Storage growth data with keys:
     *                                                      - daily_vectors_added: Array of [date => count]
     *                                                      - current_storage_bytes: Total storage in bytes
     *                                                      - current_storage_mb: Total storage in megabytes
     *                                                      - avg_vectors_per_day: Average vectors added per day
     *                                                      - period_days: Number of days analyzed
     *
     * @psalm-return array{daily_vectors_added: array<string, int>, current_storage_bytes: int, current_storage_mb: float, avg_vectors_per_day: float, period_days: int}
     */
    public function getStorageGrowth(int $days=30): array
    {
        // Get query builder instance for daily vector counts.
        $qb = $this->db->getQueryBuilder();

        // Calculate start timestamp (N days ago).
        $startTime = time() - ($days * 86400);

        // Build query to get daily vector counts grouped by date.
        // FROM_UNIXTIME converts timestamp to date string for grouping.
        $qb->select($qb->func()->count('*', 'count'))
            ->selectAlias($qb->createFunction('FROM_UNIXTIME(created_at, \'%Y-%m-%d\')'), 'date')
            ->from('openregister_vectors')
            ->where($qb->expr()->gte('created_at', $qb->createNamedParameter($startTime)))
            ->groupBy('date')
            ->orderBy('date', 'ASC');

        // Execute query and fetch all results.
        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        // Get current total storage size in bytes.
        // Uses SUM(LENGTH(embedding)) to calculate total bytes used by all vectors.
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select($qb2->createFunction('SUM(LENGTH(embedding))'), 'total_bytes')
            ->from('openregister_vectors');

        $result2 = $qb2->executeQuery();
        $sizeRow = $result2->fetch();
        $result2->closeCursor();

        // Extract and calculate storage metrics.
        $totalBytes = (int) ($sizeRow['total_bytes'] ?? 0);

        // Convert bytes to megabytes (1024 * 1024 = 1 MB).
        $totalMB = $totalBytes / (1024 * 1024);

        // Transform daily counts into associative array [date => count].
        $growthData = [];
        foreach ($rows as $row) {
            // Cast count to integer for type safety.
            $growthData[$row['date']] = (int) $row['count'];
        }

        // Return comprehensive storage growth statistics.
        return [
            'daily_vectors_added'   => $growthData,
            'current_storage_bytes' => $totalBytes,
            'current_storage_mb'    => round($totalMB, 2),
            'avg_vectors_per_day'   => $this->calculateAverageVectorsPerDay($growthData),
            'period_days'           => $days,
        ];
    }//end getStorageGrowth()

    /**
     * Get comprehensive metrics dashboard data
     *
     * @return ((float|int)[]|float|int)[][]
     *
     * @psalm-return array{files_processed: array<int>, embedding_stats: array{total: int, successful: int, failed: int, success_rate: float, estimated_cost_usd: float, period_days: int}, search_latency: array<string, array{count: int, avg_ms: float, min_ms: int, max_ms: int}>, storage_growth: array{daily_vectors_added: array<string, int>, current_storage_bytes: int, current_storage_mb: float, avg_vectors_per_day: float, period_days: int}}
     */
    public function getDashboardMetrics(): array
    {
        return [
            'files_processed' => $this->getFilesProcessedPerDay(30),
            'embedding_stats' => $this->getEmbeddingStats(30),
            'search_latency'  => $this->getSearchLatencyStats(7),
            'storage_growth'  => $this->getStorageGrowth(30),
        ];
    }//end getDashboardMetrics()

    /**
     * Clean old metrics (retention policy)
     *
     * Deletes metrics older than specified retention period.
     * Implements data retention policy to prevent unbounded database growth.
     *
     * @param int $retentionDays Number of days to retain (default: 90)
     *
     * @return int Number of deleted records
     *
     * @psalm-suppress PossiblyInvalidMethodCall
     */
    public function cleanOldMetrics(int $retentionDays=90): int
    {
        // Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Calculate cutoff timestamp (metrics older than this will be deleted).
        // 86400 seconds = 1 day.
        $cutoffTime = time() - ($retentionDays * 86400);

        // Build DELETE query for old metrics.
        $qb->delete('openregister_metrics')
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoffTime)));

        // Execute delete query.
        $result = $qb->executeStatement();

        // Handle different return types from executeStatement().
        // Some database drivers return int, others return result object.
        if (is_int($result) === true) {
            return $result;
        } else {
            return (int) $result->rowCount();
        }
    }//end cleanOldMetrics()

    /**
     * Encode metadata array to JSON string.
     *
     * Converts metadata array to JSON format for database storage.
     * Returns empty JSON object if encoding fails.
     *
     * @param array|null $metadata Metadata array to encode (null allowed).
     *
     * @return string JSON-encoded metadata string (empty object '{}' if null or encoding fails).
     *
     * @psalm-suppress PossiblyNullArgument
     */
    private function encodeMetadata(?array $metadata): string
    {
        // Handle null metadata by returning empty JSON object.
        if ($metadata === null) {
            return '{}';
        }

        // Encode array to JSON string.
        $encoded = json_encode($metadata);

        // If encoding fails (e.g., due to invalid UTF-8), return empty object.
        if ($encoded === false) {
            return '{}';
        }

        return $encoded;
    }//end encodeMetadata()

    /**
     * Calculate success rate percentage
     *
     * Calculates success rate as percentage of successful operations
     * out of total operations. Returns 0.0 if no operations occurred.
     *
     * @param int $total      Total number of operations
     * @param int $successful Number of successful operations
     *
     * @return float Success rate as percentage (0-100), rounded to 2 decimal places
     */
    private function calculateSuccessRate(int $total, int $successful): float
    {
        // Handle division by zero case (no operations).
        if ($total === 0) {
            return 0.0;
        }

        // Calculate percentage: (successful / total) * 100.
        // Round to 2 decimal places for readability.
        return round(($successful / $total) * 100, 2);
    }//end calculateSuccessRate()

    /**
     * Round average milliseconds value
     *
     * Converts and rounds average milliseconds value from database result.
     * Database may return numeric values as strings, so this method handles
     * type conversion and rounding.
     *
     * @param mixed $avgMs Average milliseconds value (can be string, float, or null from database)
     *
     * @return float Rounded average milliseconds (0.0 if invalid or null)
     *
     * @psalm-suppress MixedArgument
     */
    private function roundAverageMs($avgMs): float
    {
        // Check if value is numeric (handles both string and numeric types).
        if (is_numeric($avgMs) === true) {
            // Cast to float and round to 2 decimal places.
            return round((float) $avgMs, 2);
        }

        // Return 0.0 for invalid or null values.
        return 0.0;
    }//end roundAverageMs()

    /**
     * Calculate average vectors per day from growth data
     *
     * Calculates average number of vectors added per day from daily growth data.
     * Handles empty data gracefully by returning 0.0.
     *
     * @param array<string, int> $growthData Growth data array with [date => count] format
     *
     * @return float Average vectors per day, rounded to 2 decimal places
     *
     * @psalm-suppress TypeDoesNotContainType
     */
    private function calculateAverageVectorsPerDay(array $growthData): float
    {
        // Handle empty data case.
        if (empty($growthData) === true) {
            return 0.0;
        }

        // Initialize total vectors counter.
        $totalVectors = 0;

        // Count number of days in dataset.
        $days = count($growthData);

        // Sum all vector counts from growth data.
        // Note: $growthData is [date => count], so $dayData is the count value.
        foreach ($growthData as $dayData) {
            // Cast to int for type safety (handles null values).
            $totalVectors += $dayData['count'] ?? 0;
        }

        // Safety check: prevent division by zero.
        // This should never happen due to empty() check above, but included for safety.
        /*
         * @psalm-suppress TypeDoesNotContainType
         */

        if ($days <= 0) {
            return 0.0;
        }

        // Calculate average: total vectors / number of days.
        // Round to 2 decimal places for readability.
        /*
         * @psalm-suppress TypeDoesNotContainType
         */

        return round($totalVectors / $days, 2);
    }//end calculateAverageVectorsPerDay()
}//end class
