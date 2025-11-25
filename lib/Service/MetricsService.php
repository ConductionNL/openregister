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

    // Metric types.
    public const METRIC_FILE_PROCESSED      = 'file_processed';
    public const METRIC_OBJECT_VECTORIZED   = 'object_vectorized';
    public const METRIC_EMBEDDING_GENERATED = 'embedding_generated';
    public const METRIC_SEARCH_SEMANTIC     = 'search_semantic';
    public const METRIC_SEARCH_HYBRID       = 'search_hybrid';
    public const METRIC_SEARCH_KEYWORD      = 'search_keyword';
    public const METRIC_CHAT_MESSAGE        = 'chat_message';


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
     * @param string      $metricType   Type of metric
     * @param string|null $entityType   Entity type (file, object, etc.)
     * @param string|null $entityId     Entity ID
     * @param string      $status       Status (success, failure)
     * @param int|null    $durationMs   Duration in milliseconds
     * @param array|null  $metadata     Additional metadata
     * @param string|null $errorMessage Error message (if failed)
     * @param string|null $userId       User ID
     *
     * @return void
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
            $qb = $this->db->getQueryBuilder();

            $qb->insert('openregister_metrics')
                ->values(
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
                ]
            );

            $qb->execute();
        } catch (\Exception $e) {
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
     * @return array Array of [date => count]
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

        $result = $qb->execute();
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
     * @param int $days Number of days to analyze
     *
     * @return array Success rate and costs
     */
    public function getEmbeddingStats(int $days=30): array
    {
        $qb = $this->db->getQueryBuilder();

        $startTime = time() - ($days * 86400);

        // Get total embeddings.
        $qb->select($qb->func()->count('*', 'total'))
            ->selectAlias($qb->createFunction('SUM(CASE WHEN status = \'success\' THEN 1 ELSE 0 END)'), 'successful')
            ->from('openregister_metrics')
            ->where($qb->expr()->eq('metric_type', $qb->createNamedParameter(self::METRIC_EMBEDDING_GENERATED)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($startTime)));

        $result = $qb->execute();
        $row    = $result->fetch();
        $result->closeCursor();

        $total       = (int) ($row['total'] ?? 0);
        $successful  = (int) ($row['successful'] ?? 0);
        $failed      = $total - $successful;
        $successRate = $this->calculateSuccessRate($total, $successful);

        // Calculate estimated costs (based on OpenAI pricing).
        // text-embedding-3-large: $0.00013 per 1K tokens, avg 500 tokens per embedding.
        $estimatedCost = $successful * 0.000065;
        // $0.00013 * 0.5
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
     * @param int $days Number of days to analyze
     *
     * @return array Latency stats by search type
     */
    public function getSearchLatencyStats(int $days=7): array
    {
        $startTime = time() - ($days * 86400);

        $searchTypes = [
            self::METRIC_SEARCH_KEYWORD,
            self::METRIC_SEARCH_SEMANTIC,
            self::METRIC_SEARCH_HYBRID,
        ];

        $stats = [];

        foreach ($searchTypes as $searchType) {
            $qb = $this->db->getQueryBuilder();

            $qb->select($qb->func()->count('*', 'count'))
                ->selectAlias($qb->func()->avg('duration_ms'), 'avg_ms')
                ->selectAlias($qb->func()->min('duration_ms'), 'min_ms')
                ->selectAlias($qb->func()->max('duration_ms'), 'max_ms')
                ->from('openregister_metrics')
                ->where($qb->expr()->eq('metric_type', $qb->createNamedParameter($searchType)))
                ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($startTime)))
                ->andWhere($qb->expr()->isNotNull('duration_ms'));

            $result = $qb->execute();
            $row    = $result->fetch();
            $result->closeCursor();

            $type         = str_replace('search_', '', $searchType);
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
     * @param int $days Number of days to analyze
     *
     * @return array Storage growth data
     */
    public function getStorageGrowth(int $days=30): array
    {
        // Get daily vector counts.
        $qb = $this->db->getQueryBuilder();

        $startTime = time() - ($days * 86400);

        $qb->select($qb->func()->count('*', 'count'))
            ->selectAlias($qb->createFunction('FROM_UNIXTIME(created_at, \'%Y-%m-%d\')'), 'date')
            ->from('openregister_vectors')
            ->where($qb->expr()->gte('created_at', $qb->createNamedParameter($startTime)))
            ->groupBy('date')
            ->orderBy('date', 'ASC');

        $result = $qb->execute();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        // Get current total size.
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select($qb2->createFunction('SUM(LENGTH(embedding))'), 'total_bytes')
            ->from('openregister_vectors');

        $result2 = $qb2->execute();
        $sizeRow = $result2->fetch();
        $result2->closeCursor();

        $totalBytes = (int) ($sizeRow['total_bytes'] ?? 0);
        $totalMB    = $totalBytes / (1024 * 1024);

        $growthData = [];
        foreach ($rows as $row) {
            $growthData[$row['date']] = (int) $row['count'];
        }

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
     * @return array All metrics for dashboard
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
     * @param int $retentionDays Number of days to retain
     *
     * @return int Number of deleted records
     */
    public function cleanOldMetrics(int $retentionDays=90): int
    {
        $qb = $this->db->getQueryBuilder();

        $cutoffTime = time() - ($retentionDays * 86400);

        $qb->delete('openregister_metrics')
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoffTime)));

        return $qb->execute();

    }//end cleanOldMetrics()

    /**
     * Encode metadata array to JSON string.
     *
     * @param array $metadata Metadata array to encode.
     *
     * @return string JSON-encoded metadata string.
     */
    private function encodeMetadata(array $metadata): string
    {
        $encoded = json_encode($metadata);
        if ($encoded === false) {
            return '{}';
        }
        return $encoded;
    }//end encodeMetadata()

    /**
     * Calculate success rate percentage.
     *
     * @param int $total     Total number of operations.
     * @param int $successful Number of successful operations.
     *
     * @return float Success rate as percentage (0-100).
     */
    private function calculateSuccessRate(int $total, int $successful): float
    {
        if ($total === 0) {
            return 0.0;
        }
        return round(($successful / $total) * 100, 2);
    }//end calculateSuccessRate()

    /**
     * Round average milliseconds value.
     *
     * @param mixed $avgMs Average milliseconds value (can be string or float).
     *
     * @return float Rounded average milliseconds.
     */
    private function roundAverageMs($avgMs): float
    {
        if (is_numeric($avgMs) === true) {
            return round((float) $avgMs, 2);
        }
        return 0.0;
    }//end roundAverageMs()

    /**
     * Calculate average vectors per day from growth data.
     *
     * @param array $growthData Growth data array.
     *
     * @return float Average vectors per day.
     */
    private function calculateAverageVectorsPerDay(array $growthData): float
    {
        if (empty($growthData) === true) {
            return 0.0;
        }
        $totalVectors = 0;
        $days = count($growthData);
        foreach ($growthData as $dayData) {
            $totalVectors += (int) ($dayData['count'] ?? 0);
        }
        if ($days === 0) {
            return 0.0;
        }
        return round($totalVectors / $days, 2);
    }//end calculateAverageVectorsPerDay()

}//end class
