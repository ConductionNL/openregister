<?php

/**
 * OpenRegister Metrics Service
 *
 * Service for tracking and retrieving operational metrics.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

namespace OCA\OpenRegister\Search\Service;

use OCA\OpenRegister\Kernel\Contracts\MetricsRetentionInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
 *
 * @spec openspec/specs/production-observability/spec.md#metrics-storage-strategy
 */

class MetricsService implements MetricsRetentionInterface {

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
	 * Metric type constant for object creation (CRUD counter).
	 *
	 * @var string
	 */
	public const METRIC_OBJECT_CREATED = 'object_created';

	/**
	 * Metric type constant for object update (CRUD counter).
	 *
	 * @var string
	 */
	public const METRIC_OBJECT_UPDATED = 'object_updated';

	/**
	 * Metric type constant for object deletion (CRUD counter).
	 *
	 * @var string
	 */
	public const METRIC_OBJECT_DELETED = 'object_deleted';

	/**
	 * Constructor
	 *
	 * @param IDBConnection $db Database connection
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		IDBConnection $db,
		LoggerInterface $logger,
	) {
		$this->db = $db;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Record a metric
	 *
	 * Records operational metrics to the database for tracking performance,
	 * success rates, and usage statistics. Metrics are stored with timestamps
	 * and can include optional metadata for detailed analysis.
	 *
	 * @param MetricRecord $record The metric type, subject (entity, user), outcome (status, duration, error) and metadata
	 *
	 * @return void
	 *
	 * @throws \Exception If database operation fails (logged but not rethrown)
	 *
	 * @psalm-suppress PossiblyNullArgument
	 *
	 * @spec openspec/specs/production-observability/spec.md#metrics-storage-strategy (fail-soft insert of an
	 *       operational metric row — type, entity, status, duration, metadata, error, user, timestamp — that
	 *       never disrupts the calling operation on DB error)
	 */
	public function recordMetric(MetricRecord $record): void {
		try {
			$this->insertMetric(record: $record);
		} catch (\Exception $e) {
			// Log errors but don't throw to prevent disrupting main operations.
			// Metrics recording failures should not break application functionality.
			$this->logRecordFailure(record: $record, error: $e);
		}//end try
	}//end recordMetric()

	/**
	 * Insert one metric row, timestamped now.
	 *
	 * @param MetricRecord $record The metric type, subject, outcome and metadata
	 *
	 * @return void
	 *
	 * @throws \Exception If the insert fails
	 *
	 * @psalm-suppress PossiblyNullArgument
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function insertMetric(MetricRecord $record): void {
		// Get query builder instance for database operations.
		$qb = $this->db->getQueryBuilder();

		// Build INSERT query for metrics table.
		// Create named parameters for all values to prevent SQL injection.
		//
		// 🔴 `values()` takes a FLAT column => parameter map. Wrapping it in
		// an extra array (`[[ ... ]]`, as this did) makes each value an array
		// rather than a parameter, so Doctrine rejected every insert with
		// "Only strings, Literals and Parameters are allowed" — silently,
		// because the catch below is fail-soft by design. The result was that
		// NO metric was ever recorded while an error was logged on every
		// object write. Every other `->values()` call in this app uses the
		// flat form; this was the only nested one.
		$qb->insert('openregister_metrics')
			->values(values: $this->metricColumns(qb: $qb, record: $record));

		// Execute the insert query.
		$qb->executeStatement();
	}//end insertMetric()

	/**
	 * The metric row's columns, each a named parameter on the insert.
	 *
	 * @param IQueryBuilder $qb     The insert query.
	 * @param MetricRecord  $record The metric type, subject, outcome and metadata
	 *
	 * @return array<string, mixed> Column => parameter, flat.
	 *
	 * @psalm-suppress PossiblyNullArgument
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function metricColumns(IQueryBuilder $qb, MetricRecord $record): array {
		return [
			'metric_type' => $qb->createNamedParameter($record->metricType),
			'entity_type' => $qb->createNamedParameter($record->subject->entityType),
			'entity_id' => $qb->createNamedParameter($record->subject->entityId),
			'user_id' => $qb->createNamedParameter($record->subject->userId),
			'status' => $qb->createNamedParameter($record->outcome->status),
			'duration_ms' => $qb->createNamedParameter($record->outcome->durationMs, IQueryBuilder::PARAM_INT),
			'metadata' => $qb->createNamedParameter($this->encodeMetadata(metadata: $record->metadata)),
			'error_message' => $qb->createNamedParameter($record->outcome->errorMessage),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		];
	}//end metricColumns()

	/**
	 * Log a metric that could not be recorded.
	 *
	 * @param MetricRecord $record The metric that failed.
	 * @param \Exception   $error  Why it failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function logRecordFailure(MetricRecord $record, \Exception $error): void {
		$this->logger->error(
			message: '[MetricsService] Failed to record metric',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'metric_type' => $record->metricType,
				'error' => $error->getMessage(),
			]
		);
	}//end logRecordFailure()

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
	 * @psalm-return array{total: int, successful: int, failed: int,
	 *               success_rate: float, estimated_cost_usd: float, period_days: int}
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function getEmbeddingStats(int $days = 30): array {
		// Calculate start timestamp (N days ago).
		$startTime = time() - ($days * 86400);

		$row = $this->embeddingCountsRow(startTime: $startTime);

		// Extract and cast values from database result.
		$total = (int)($row['total'] ?? 0);
		$successful = (int)($row['successful'] ?? 0);

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
			'total' => $total,
			'successful' => $successful,
			'failed' => $failed,
			'success_rate' => round($successRate, 2),
			'estimated_cost_usd' => round($estimatedCost, 4),
			'period_days' => $days,
		];
	}//end getEmbeddingStats()

	/**
	 * Read the total and successful embedding operations since a moment.
	 *
	 * Uses CASE statement to count successful operations (status = 'success').
	 *
	 * @param int $startTime Unix timestamp the window starts at.
	 *
	 * @return array<string, mixed>|false The single result row.
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function embeddingCountsRow(int $startTime): array|false {
		// Get query builder instance.
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->func()->count('*', 'total'))
			->selectAlias($qb->createFunction('SUM(CASE WHEN status = \'success\' THEN 1 ELSE 0 END)'), 'successful')
			->from('openregister_metrics')
			->where($qb->expr()->eq('metric_type', $qb->createNamedParameter(self::METRIC_EMBEDDING_GENERATED)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($startTime)));

		// Execute query and fetch single row result.
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row;
	}//end embeddingCountsRow()

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
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function getSearchLatencyStats(int $days = 7): array {
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
			// Extract search type name (remove 'search_' prefix).
			// Example: 'search_keyword' -> 'keyword'.
			$type = str_replace('search_', '', $searchType);

			// Store statistics for this search type.
			$stats[$type] = $this->latencyStatsFor(searchType: $searchType, startTime: $startTime);
		}

		return $stats;
	}//end getSearchLatencyStats()

	/**
	 * Latency statistics of one search type since a moment.
	 *
	 * @param string $searchType The search metric type.
	 * @param int    $startTime  Unix timestamp the window starts at.
	 *
	 * @return array{count: int, avg_ms: float, min_ms: int, max_ms: int}
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function latencyStatsFor(string $searchType, int $startTime): array {
		$row = $this->latencyRow(searchType: $searchType, startTime: $startTime);

		return [
			'count' => (int)($row['count'] ?? 0),
			'avg_ms' => $this->roundAverageMs(avgMs: $row['avg_ms']),
			'min_ms' => (int)($row['min_ms'] ?? 0),
			'max_ms' => (int)($row['max_ms'] ?? 0),
		];
	}//end latencyStatsFor()

	/**
	 * Read the count, average, minimum and maximum duration of one search type since a moment.
	 *
	 * Only includes metrics with non-null duration_ms values.
	 *
	 * @param string $searchType The search metric type.
	 * @param int    $startTime  Unix timestamp the window starts at.
	 *
	 * @return array<string, mixed>|false The single result row.
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function latencyRow(string $searchType, int $startTime): array|false {
		// Get query builder instance for this iteration.
		$qb = $this->db->getQueryBuilder();

		// Build query to calculate latency statistics.
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
		$row = $result->fetch();
		$result->closeCursor();

		return $row;
	}//end latencyRow()

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
	 *                                                     - daily_vectors_added: Array of [date => count]
	 *                                                     - current_storage_bytes: Total storage in bytes
	 *                                                     - current_storage_mb: Total storage in megabytes
	 *                                                     - avg_vectors_per_day: Average vectors added per day
	 *                                                     - period_days: Number of days analyzed
	 *
	 * @psalm-return array{daily_vectors_added: array<string, int>,
	 *     current_storage_bytes: int, current_storage_mb: float,
	 *     avg_vectors_per_day: float, period_days: int}
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function getStorageGrowth(int $days = 30): array {
		// Calculate start timestamp (N days ago).
		$startTime = time() - ($days * 86400);

		$rows = $this->dailyVectorRows(startTime: $startTime);

		// Extract and calculate storage metrics.
		$totalBytes = $this->totalVectorBytes();

		// Convert bytes to megabytes (1024 * 1024 = 1 MB).
		$totalMB = $totalBytes / (1024 * 1024);

		// Transform daily counts into associative array [date => count].
		$growthData = $this->countsByDate(rows: $rows);

		// Return comprehensive storage growth statistics.
		return [
			'daily_vectors_added' => $growthData,
			'current_storage_bytes' => $totalBytes,
			'current_storage_mb' => round($totalMB, 2),
			'avg_vectors_per_day' => $this->calculateAverageVectorsPerDay(growthData: $growthData),
			'period_days' => $days,
		];
	}//end getStorageGrowth()

	/**
	 * Daily vector rows as a [date => count] map.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows of `count` and `date`.
	 *
	 * @return array<string, int> The count per date.
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function countsByDate(array $rows): array {
		$growthData = [];
		foreach ($rows as $row) {
			// Cast count to integer for type safety.
			$growthData[$row['date']] = (int)$row['count'];
		}

		return $growthData;
	}//end countsByDate()

	/**
	 * Read the number of vectors added per day since a moment, oldest day first.
	 *
	 * @param int $startTime Unix timestamp the window starts at.
	 *
	 * @return array<int, array<string, mixed>> Rows of `count` and `date` (Y-m-d).
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function dailyVectorRows(int $startTime): array {
		// Get query builder instance for daily vector counts.
		$qb = $this->db->getQueryBuilder();

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
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end dailyVectorRows()

	/**
	 * Read the total storage size of every vector embedding, in bytes.
	 *
	 * Uses SUM(LENGTH(embedding)) to calculate total bytes used by all vectors.
	 *
	 * @return int The total bytes; 0 when there are none.
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function totalVectorBytes(): int {
		$qb2 = $this->db->getQueryBuilder();
		$qb2->select($qb2->createFunction('SUM(LENGTH(embedding))'), 'total_bytes')
			->from('openregister_vectors');

		$result2 = $qb2->executeQuery();
		$sizeRow = $result2->fetch();
		$result2->closeCursor();

		return (int)($sizeRow['total_bytes'] ?? 0);
	}//end totalVectorBytes()

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
	 *
	 * @spec openspec/specs/production-observability/spec.md#metrics-storage-strategy (retention pruning:
	 *       deletes metric rows older than the retention window to bound table growth)
	 */
	public function cleanOldMetrics(int $retentionDays = 90): int {
		// Get query builder instance.
		$qb = $this->db->getQueryBuilder();

		// Calculate cutoff timestamp (metrics older than this will be deleted).
		// 86400 seconds = 1 day.
		$cutoffTime = time() - ($retentionDays * 86400);

		// Build DELETE query for old metrics.
		$qb->delete('openregister_metrics')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoffTime, IQueryBuilder::PARAM_INT)));

		// Execute delete query.
		$result = $qb->executeStatement();

		// Handle different return types from executeStatement().
		// Some database drivers return int, others return result object.
		if (is_int($result) === true) {
			return $result;
		}

		return (int)$result->rowCount();
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
	private function encodeMetadata(?array $metadata): string {
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
	 * @param int $total Total number of operations
	 * @param int $successful Number of successful operations
	 *
	 * @return float Success rate as percentage (0-100), rounded to 2 decimal places
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function calculateSuccessRate(int $total, int $successful): float {
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
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function roundAverageMs($avgMs): float {
		// Check if value is numeric (handles both string and numeric types).
		if (is_numeric($avgMs) === true) {
			// Cast to float and round to 2 decimal places.
			return round((float)$avgMs, 2);
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
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function calculateAverageVectorsPerDay(array $growthData): float {
		// Handle empty data case.
		if (empty($growthData) === true) {
			return 0.0;
		}

		// Initialize total vectors counter.
		$totalVectors = 0;

		// Count number of days in dataset.
		$days = count($growthData);

		// Sum all vector counts from growth data.
		// Note: $growthData is [date => count], where dayData is the count int value.
		foreach ($growthData as $dayData) {
			// DayData is already an int representing the count.
			$totalVectors += (int)$dayData;
		}

		// Calculate average: total vectors / number of days.
		// Round to 2 decimal places for readability.
		return round($totalVectors / $days, 2);
	}//end calculateAverageVectorsPerDay()
}//end class
