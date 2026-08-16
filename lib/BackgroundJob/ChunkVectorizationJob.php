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

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Service\Vectorization\Handlers\VectorStorageHandler;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
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
	 * Maximum chunks to embed per run (bounded provider calls)
	 */
	private const BATCH_SIZE = 50;

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
	 *
	 * @spec openspec/changes/hybrid-document-search/tasks.md#5.2
	 */
	public function __construct(ITimeFactory $time) {
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
	 * @spec openspec/changes/hybrid-document-search/tasks.md#5.2
	 */
	protected function run($argument): void {
		$startTime = microtime(true);

		$logger = \OC::$server->get(LoggerInterface::class);
		$appConfig = \OC::$server->get(IAppConfig::class);
		$chunkMapper = \OC::$server->get(ChunkMapper::class);
		$embeddings = \OC::$server->get(VectorEmbeddings::class);
		$storageHandler = \OC::$server->get(VectorStorageHandler::class);

		$logger->debug(
			message: '[ChunkVectorizationJob] Starting chunk vectorization batch',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		$vectorized = 0;
		$failed = 0;

		try {
			[$vectorized, $failed] = $this->vectorizeChunks(
				chunkMapper: $chunkMapper,
				embeddings: $embeddings,
				storageHandler: $storageHandler,
				logger: $logger
			);
		} catch (\Throwable $e) {
			$logger->warning(
				message: '[ChunkVectorizationJob] Chunk vectorization batch failed '
					. '(embedding provider unavailable or misconfigured?)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}

		// The pgvector warm-up backfill (job-only warm-up, DECIDED 2026-07-06).
		$backfill = ['converted' => 0, 'failed' => 0, 'remaining' => 0];
		try {
			$backfill = $this->runWarmupBackfill(
				storageHandler: $storageHandler,
				appConfig: $appConfig
			);
		} catch (\Throwable $e) {
			$logger->error(
				message: '[ChunkVectorizationJob] pgvector warm-up backfill failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}

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

		$executionTime = microtime(true) - $startTime;

		$logger->info(
			message: '[ChunkVectorizationJob] Batch completed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'vectorized' => $vectorized,
				'failed' => $failed,
				'backfill_converted' => $backfill['converted'],
				'backfill_failed' => $backfill['failed'],
				'backfill_remaining' => $backfill['remaining'],
				'execution_time_seconds' => round($executionTime, 2),
			]
		);
	}//end run()

	/**
	 * Vectorize a batch of unvectorized chunks.
	 *
	 * A single chunk's embedding or storage failure is logged and skipped —
	 * the rest of the batch continues, and the failed chunk stays
	 * `vectorized = false` for retry on the next scheduled run.
	 *
	 * @param ChunkMapper $chunkMapper Chunk mapper
	 * @param VectorEmbeddings $embeddings Embedding coordinator
	 * @param VectorStorageHandler $storageHandler Vector storage handler
	 * @param LoggerInterface $logger Logger
	 *
	 * @return array{0: int, 1: int} [vectorized count, failed count]
	 *
	 * @throws Exception When the batch-embedding call itself fails (e.g. no provider configured)
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/hybrid-document-search/tasks.md#5.2
	 */
	private function vectorizeChunks(
		ChunkMapper $chunkMapper,
		VectorEmbeddings $embeddings,
		VectorStorageHandler $storageHandler,
		LoggerInterface $logger,
	): array {
		$chunks = $chunkMapper->findUnvectorized(limit: self::BATCH_SIZE);

		if ($chunks === []) {
			return [0, 0];
		}

		$texts = array_map(
			static fn (Chunk $chunk): string => (string)$chunk->getTextContent(),
			$chunks
		);

		// The batch-embedding call tolerates per-text failures (null embedding
		// entries) but throws when the provider itself is unavailable.
		$embeddingResults = $embeddings->generateBatchEmbeddings($texts);

		$vectorized = 0;
		$failed = 0;
		$totalChunksCache = [];

		foreach ($chunks as $index => $chunk) {
			$embeddingData = $embeddingResults[$index] ?? null;

			if ($embeddingData === null || ($embeddingData['embedding'] ?? null) === null) {
				$failed++;
				$logger->warning(
					message: '[ChunkVectorizationJob] Embedding failed for chunk, will retry next run',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'chunk_id' => $chunk->getId(),
						'error' => $embeddingData['error'] ?? 'No embedding returned',
					]
				);
				continue;
			}

			try {
				$sourceKey = $chunk->getSourceType() . '_' . $chunk->getSourceId();
				if (isset($totalChunksCache[$sourceKey]) === false) {
					$totalChunksCache[$sourceKey] = count(
						$chunkMapper->findBySource(
							sourceType: (string)$chunk->getSourceType(),
							sourceId: (int)$chunk->getSourceId()
						)
					);
				}

				$storageHandler->storeVector(
					entityType: (string)$chunk->getSourceType(),
					entityId: (string)$chunk->getSourceId(),
					embedding: $embeddingData['embedding'],
					model: (string)$embeddingData['model'],
					dimensions: (int)$embeddingData['dimensions'],
					chunkIndex: (int)$chunk->getChunkIndex(),
					totalChunks: max(1, $totalChunksCache[$sourceKey]),
					chunkText: substr((string)$chunk->getTextContent(), 0, 500),
					metadata: [
						'source_id' => $chunk->getSourceId(),
						'start_offset' => $chunk->getStartOffset(),
						'end_offset' => $chunk->getEndOffset(),
					]
				);

				$chunk->setVectorized(true);
				$chunk->setUpdatedAt(new DateTime());
				$chunkMapper->update($chunk);
				$vectorized++;
			} catch (Exception $e) {
				$failed++;
				$logger->warning(
					message: '[ChunkVectorizationJob] Failed to store vector for chunk, will retry next run',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'chunk_id' => $chunk->getId(),
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return [$vectorized, $failed];
	}//end vectorizeChunks()

	/**
	 * Run one pgvector warm-up backfill batch with cursor tracking.
	 *
	 * The cursor (appconfig) lets consecutive runs make progress past rows that
	 * persistently fail to convert; when a sweep completes (no rows processed
	 * or nothing remaining) the cursor resets to 0 so future NULL rows — e.g.
	 * after an embedding-dimension change — are swept again.
	 *
	 * @param VectorStorageHandler $storageHandler Vector storage handler
	 * @param IAppConfig $appConfig App config (cursor storage)
	 *
	 * @return array{converted: int, failed: int, last_id: int, remaining: int}
	 *
	 * @spec openspec/changes/hybrid-document-search/tasks.md#2.2
	 */
	private function runWarmupBackfill(
		VectorStorageHandler $storageHandler,
		IAppConfig $appConfig,
	): array {
		$cursor = (int)$appConfig->getValueString(
			app: 'openregister',
			key: self::CONFIG_PREFIX . 'backfill_cursor',
			default: '0'
		);

		$result = $storageHandler->backfillEmbeddingVectors(
			batchSize: self::BACKFILL_BATCH_SIZE,
			afterId: $cursor
		);

		$newCursor = (int)$result['last_id'];
		if ($newCursor <= $cursor || (int)$result['remaining'] === 0) {
			// Sweep finished (or made no progress): restart from the beginning
			// next run so earlier rows and future NULL rows are re-checked.
			$newCursor = 0;
		}

		$appConfig->setValueString(
			app: 'openregister',
			key: self::CONFIG_PREFIX . 'backfill_cursor',
			value: (string)$newCursor
		);

		return $result;
	}//end runWarmupBackfill()
}//end class
