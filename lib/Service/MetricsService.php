<?php

namespace OCA\OpenRegister\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * MetricsService
 *
 * Service for tracking and retrieving operational metrics
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
	 * @var IDBConnection Database connection
	 */
	private IDBConnection $db;

	/**
	 * @var LoggerInterface Logger
	 */
	private LoggerInterface $logger;

	// Metric types
	public const METRIC_FILE_PROCESSED = 'file_processed';
	public const METRIC_OBJECT_VECTORIZED = 'object_vectorized';
	public const METRIC_EMBEDDING_GENERATED = 'embedding_generated';
	public const METRIC_SEARCH_SEMANTIC = 'search_semantic';
	public const METRIC_SEARCH_HYBRID = 'search_hybrid';
	public const METRIC_SEARCH_KEYWORD = 'search_keyword';
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
		$this->db = $db;
		$this->logger = $logger;
	}

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
		?string $entityType = null,
		?string $entityId = null,
		string $status = 'success',
		?int $durationMs = null,
		?array $metadata = null,
		?string $errorMessage = null,
		?string $userId = null
	): void {
		try {
			$qb = $this->db->getQueryBuilder();

			$qb->insert('openregister_metrics')
				->values([
					'metric_type' => $qb->createNamedParameter($metricType),
					'entity_type' => $qb->createNamedParameter($entityType),
					'entity_id' => $qb->createNamedParameter($entityId),
					'user_id' => $qb->createNamedParameter($userId),
					'status' => $qb->createNamedParameter($status),
					'duration_ms' => $qb->createNamedParameter($durationMs),
					'metadata' => $qb->createNamedParameter($metadata ? json_encode($metadata) : null),
					'error_message' => $qb->createNamedParameter($errorMessage),
					'created_at' => $qb->createNamedParameter(time()),
				]);

			$qb->execute();
		} catch (\Exception $e) {
			$this->logger->error('[MetricsService] Failed to record metric', [
				'metric_type' => $metricType,
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Get files processed per day for last N days
	 *
	 * @param int $days Number of days to retrieve
	 *
	 * @return array Array of [date => count]
	 */
	public function getFilesProcessedPerDay(int $days = 30): array {
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
		$rows = $result->fetchAll();
		$result->closeCursor();

		$data = [];
		foreach ($rows as $row) {
			$data[$row['date']] = (int)$row['count'];
		}

		return $data;
	}

	/**
	 * Get embedding generation success rate
	 *
	 * @param int $days Number of days to analyze
	 *
	 * @return array Success rate and costs
	 */
	public function getEmbeddingStats(int $days = 30): array {
		$qb = $this->db->getQueryBuilder();

		$startTime = time() - ($days * 86400);

		// Get total embeddings
		$qb->select($qb->func()->count('*', 'total'))
			->selectAlias($qb->createFunction('SUM(CASE WHEN status = \'success\' THEN 1 ELSE 0 END)'), 'successful')
			->from('openregister_metrics')
			->where($qb->expr()->eq('metric_type', $qb->createNamedParameter(self::METRIC_EMBEDDING_GENERATED)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($startTime)));

		$result = $qb->execute();
		$row = $result->fetch();
		$result->closeCursor();

		$total = (int)($row['total'] ?? 0);
		$successful = (int)($row['successful'] ?? 0);
		$failed = $total - $successful;
		$successRate = $total > 0 ? ($successful / $total) * 100 : 0;

		// Calculate estimated costs (based on OpenAI pricing)
		// text-embedding-3-large: $0.00013 per 1K tokens, avg 500 tokens per embedding
		$estimatedCost = $successful * 0.000065; // $0.00013 * 0.5

		return [
			'total' => $total,
			'successful' => $successful,
			'failed' => $failed,
			'success_rate' => round($successRate, 2),
			'estimated_cost_usd' => round($estimatedCost, 4),
			'period_days' => $days,
		];
	}

	/**
	 * Get search latency statistics
	 *
	 * @param int $days Number of days to analyze
	 *
	 * @return array Latency stats by search type
	 */
	public function getSearchLatencyStats(int $days = 7): array {
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
			$row = $result->fetch();
			$result->closeCursor();

			$type = str_replace('search_', '', $searchType);
			$stats[$type] = [
				'count' => (int)($row['count'] ?? 0),
				'avg_ms' => $row['avg_ms'] ? round((float)$row['avg_ms'], 2) : 0,
				'min_ms' => (int)($row['min_ms'] ?? 0),
				'max_ms' => (int)($row['max_ms'] ?? 0),
			];
		}

		return $stats;
	}

	/**
	 * Get vector database storage growth
	 *
	 * @param int $days Number of days to analyze
	 *
	 * @return array Storage growth data
	 */
	public function getStorageGrowth(int $days = 30): array {
		// Get daily vector counts
		$qb = $this->db->getQueryBuilder();

		$startTime = time() - ($days * 86400);

		$qb->select($qb->func()->count('*', 'count'))
			->selectAlias($qb->createFunction('FROM_UNIXTIME(created_at, \'%Y-%m-%d\')'), 'date')
			->from('openregister_vectors')
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($startTime)))
			->groupBy('date')
			->orderBy('date', 'ASC');

		$result = $qb->execute();
		$rows = $result->fetchAll();
		$result->closeCursor();

		// Get current total size
		$qb2 = $this->db->getQueryBuilder();
		$qb2->select($qb2->func()->sum($qb2->func()->length('embedding')), 'total_bytes')
			->from('openregister_vectors');

		$result2 = $qb2->execute();
		$sizeRow = $result2->fetch();
		$result2->closeCursor();

		$totalBytes = (int)($sizeRow['total_bytes'] ?? 0);
		$totalMB = $totalBytes / (1024 * 1024);

		$growthData = [];
		foreach ($rows as $row) {
			$growthData[$row['date']] = (int)$row['count'];
		}

		return [
			'daily_vectors_added' => $growthData,
			'current_storage_bytes' => $totalBytes,
			'current_storage_mb' => round($totalMB, 2),
			'avg_vectors_per_day' => count($growthData) > 0 ? round(array_sum($growthData) / count($growthData), 2) : 0,
			'period_days' => $days,
		];
	}

	/**
	 * Get comprehensive metrics dashboard data
	 *
	 * @return array All metrics for dashboard
	 */
	public function getDashboardMetrics(): array {
		return [
			'files_processed' => $this->getFilesProcessedPerDay(30),
			'embedding_stats' => $this->getEmbeddingStats(30),
			'search_latency' => $this->getSearchLatencyStats(7),
			'storage_growth' => $this->getStorageGrowth(30),
		];
	}

	/**
	 * Clean old metrics (retention policy)
	 *
	 * @param int $retentionDays Number of days to retain
	 *
	 * @return int Number of deleted records
	 */
	public function cleanOldMetrics(int $retentionDays = 90): int {
		$qb = $this->db->getQueryBuilder();

		$cutoffTime = time() - ($retentionDays * 86400);

		$qb->delete('openregister_metrics')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoffTime)));

		return $qb->execute();
	}
}

