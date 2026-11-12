<?php

/**
 * Chunk Vectorization Background Job
 *
 * Recurring background job that vectorizes extracted chunks automatically:
 * chunks accumulate in openregister_chunks with `vectorized = false` after text
 * extraction, and before this job existed they stayed unvectorized until an
 * admin remembered to run a manual batch action — search quality silently
 * decayed on every install. This job embeds them in bounded batches so new
 * extracted text becomes searchable without manual action.
 *
 * The job also drives the pgvector warm-up backfill (DECIDED 2026-07-06,
 * job-only warm-up): existing serialized-BLOB vector rows are converted to
 * PostgreSQL `openregister_vec_ann` ANN-sidecar rows in batches, selecting
 * vectors without a sidecar row (the sidecar equivalent of `embedding_vector
 * IS NULL`), with zero upgrade-time impact.
 *
 * ADR-031 note: this is OpenRegister-internal data-pipeline maintenance
 * (embedding-generation batch processing), the same category as
 * BlobMigrationJob and CronFileTextExtractionJob — a native Nextcloud TimedJob,
 * not business-domain workflow automation (no ScheduledWorkflow/n8n).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\BackgroundJob;

use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandlerInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Vectorizes unvectorized chunks and warms up the pgvector column in batches
 *
 * Runs every 5 minutes (mirroring BlobMigrationJob). Each run:
 * 1. Fetches up to BATCH_SIZE chunks where `vectorized = false`
 *    (ChunkMapper::findUnvectorized(), FIFO), generates embeddings via the
 *    existing generateBatchEmbeddings() path, stores them via
 *    VectorStorageHandler::storeVector() (which dual-writes the pgvector ANN
 *    sidecar), and marks each successfully-processed chunk `vectorized = true`.
 *    A single chunk's embedding failure is logged and does NOT abort the batch;
 *    the failed chunk stays `vectorized = false` for retry on the next run.
 * 2. Converts up to BACKFILL_BATCH_SIZE existing BLOB-only vector rows to
 *    pgvector ANN-sidecar rows (PostgreSQL only, dimension-matched, idempotent)
 *    via VectorStorageHandler::backfillEmbeddingVectors(), tracking a cursor in
 *    appconfig so persistently-failing rows can't stall the sweep.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ChunkVectorizationJob extends TimedJob {
	/**
	 * Interval: 5 minutes (matches BlobMigrationJob::INTERVAL)
	 */
	private const INTERVAL = 5 * 60;

	/**
	 * Maximum BLOB rows to convert to pgvector per run (cheap, no provider calls)
	 */
	private const BACKFILL_BATCH_SIZE = 100;

	/**
	 * App config key prefix
	 */
	private const CONFIG_PREFIX = 'chunk_vectorization_';

	/**
	 * Constructor
	 *
	 * @param ITimeFactory $time Time factory for parent class
	 * @param ContainerInterface $container App container the job resolves its collaborators from at run time
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.2
	 */
	public function __construct(ITimeFactory $time, private readonly ContainerInterface $container) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Execute the chunk vectorization job
	 *
	 * @param mixed $argument Job arguments (unused for recurring jobs)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.2
	 */
	protected function run($argument): void {
		$startTime = microtime(true);

		$logger = $this->container->get(LoggerInterface::class);
		$appConfig = $this->container->get(IAppConfig::class);

		$logger->debug(
			message: '[ChunkVectorizationJob] Starting chunk vectorization batch',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		$batch = $this->vectorizeBatch(logger: $logger);
		$backfill = $this->runBackfill(logger: $logger, appConfig: $appConfig);
		$this->recordRun(appConfig: $appConfig, backfill: $backfill);

		$context = $this->completionContext(batch: $batch, backfill: $backfill);
		$context['execution_time_seconds'] = round(microtime(true) - $startTime, 2);
		$logger->info(message: '[ChunkVectorizationJob] Batch completed', context: $context);
	}//end run()

	/**
	 * Record when the job last ran and how many rows the backfill has left.
	 *
	 * @param IAppConfig           $appConfig App config.
	 * @param array<string, mixed> $backfill  The backfill outcome.
	 *
	 * @return void
	 */
	private function recordRun(IAppConfig $appConfig, array $backfill): void {
		$appConfig->setValueString(
			app: 'openregister',
			key: self::CONFIG_PREFIX . 'last_run',
			value: date('c')
		);
		$appConfig->setValueString(
			app: 'openregister',
			key: self::CONFIG_PREFIX . 'backfill_remaining',
			value: (string)$backfill['remaining']
		);
	}//end recordRun()

	/**
	 * The completion log context: the batch and backfill counts.
	 *
	 * @param array<int, int>      $batch    [vectorized count, failed count].
	 * @param array<string, mixed> $backfill The backfill outcome.
	 *
	 * @return array<string, mixed>
	 */
	private function completionContext(array $batch, array $backfill): array {
		return [
			'file' => __FILE__, 'line' => __LINE__, 'vectorized' => $batch[0], 'failed' => $batch[1],
			'backfill_converted' => $backfill['converted'], 'backfill_failed' => $backfill['failed'],
			'backfill_remaining' => $backfill['remaining'],
		];
	}//end completionContext()

	/**
	 * Vectorize one batch of chunks; a provider-level failure is logged and counts as nothing done.
	 *
	 * @param LoggerInterface $logger Logger
	 *
	 * @return array{0: int, 1: int} [vectorized count, failed count]
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.2
	 */
	private function vectorizeBatch(LoggerInterface $logger): array {
		$batch = new ChunkVectorizationBatch(container: $this->container);
		try {
			return $batch->vectorize();
		} catch (\Throwable $e) {
			$logger->warning(
				message: '[ChunkVectorizationJob] Chunk vectorization batch failed '
					. '(embedding provider unavailable or misconfigured?)',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [0, 0];
		}
	}//end vectorizeBatch()

	/**
	 * Run the pgvector warm-up backfill (job-only warm-up, DECIDED 2026-07-06); a failure is logged.
	 *
	 * @param LoggerInterface $logger    Logger
	 * @param IAppConfig      $appConfig App config (cursor storage)
	 *
	 * @return array{converted: int, failed: int, remaining: int, ...}
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#2.2
	 */
	private function runBackfill(LoggerInterface $logger, IAppConfig $appConfig): array {
		$storageHandler = $this->container->get(VectorStorageHandlerInterface::class);
		try {
			return $this->runWarmupBackfill(
				storageHandler: $storageHandler,
				appConfig: $appConfig
			);
		} catch (\Throwable $e) {
			$logger->error(
				message: '[ChunkVectorizationJob] pgvector warm-up backfill failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return ['converted' => 0, 'failed' => 0, 'remaining' => 0];
		}
	}//end runBackfill()

	/**
	 * Run one pgvector warm-up backfill batch with cursor tracking.
	 *
	 * The cursor (appconfig) lets consecutive runs make progress past rows that
	 * persistently fail to convert; when a sweep completes (no rows processed
	 * or nothing remaining) the cursor resets to 0 so future NULL rows — e.g.
	 * after an embedding-dimension change — are swept again.
	 *
	 * @param VectorStorageHandlerInterface $storageHandler Vector storage handler
	 * @param IAppConfig $appConfig App config (cursor storage)
	 *
	 * @return array{converted: int, failed: int, last_id: int, remaining: int}
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#2.2
	 */
	private function runWarmupBackfill(
		VectorStorageHandlerInterface $storageHandler,
		IAppConfig $appConfig,
	): array {
		$cursor = $this->backfillCursor(appConfig: $appConfig);

		$result = $storageHandler->backfillEmbeddingVectors(
			batchSize: self::BACKFILL_BATCH_SIZE,
			afterId: $cursor
		);

		$this->storeBackfillCursor(appConfig: $appConfig, cursor: $this->nextBackfillCursor(cursor: $cursor, result: $result));

		return $result;
	}//end runWarmupBackfill()

	/**
	 * The stored backfill cursor; 0 before the first run.
	 *
	 * @param IAppConfig $appConfig App config (cursor storage).
	 *
	 * @return int The cursor.
	 */
	private function backfillCursor(IAppConfig $appConfig): int {
		return (int)$appConfig->getValueString(
			app: 'openregister',
			key: self::CONFIG_PREFIX . 'backfill_cursor',
			default: '0'
		);
	}//end backfillCursor()

	/**
	 * The cursor the next backfill run starts after.
	 *
	 * @param int                  $cursor The cursor this run started after.
	 * @param array<string, mixed> $result This run's backfill outcome.
	 *
	 * @return int The next cursor.
	 */
	private function nextBackfillCursor(int $cursor, array $result): int {
		$newCursor = (int)$result['last_id'];
		if ($newCursor <= $cursor || (int)$result['remaining'] === 0) {
			// Sweep finished (or made no progress): restart from the beginning
			// next run so earlier rows and future NULL rows are re-checked.
			return 0;
		}

		return $newCursor;
	}//end nextBackfillCursor()

	/**
	 * Store the backfill cursor.
	 *
	 * @param IAppConfig $appConfig App config (cursor storage).
	 * @param int        $cursor    The cursor.
	 *
	 * @return void
	 */
	private function storeBackfillCursor(IAppConfig $appConfig, int $cursor): void {
		$appConfig->setValueString(
			app: 'openregister',
			key: self::CONFIG_PREFIX . 'backfill_cursor',
			value: (string)$cursor
		);
	}//end storeBackfillCursor()
}//end class
