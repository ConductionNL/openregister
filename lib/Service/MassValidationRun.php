<?php

/**
 * OpenRegister Mass Validation Run
 *
 * One run of mass validation: re-save objects batch by batch and report the outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Re-saves objects in batches, serially or in chunks of batches, and collects the counts,
 * errors, timings and memory use of the run in the shape SettingsService::massValidate()
 * returns.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
 */
final class MassValidationRun {

	/**
	 * Number of batches handled per chunk in parallel mode.
	 */
	private const PARALLEL_BATCHES = 4;

	/**
	 * The run's configuration: mode, max_objects, batch_size and collect_errors.
	 *
	 * @var array{mode: string, max_objects: int, batch_size: int, collect_errors: bool}
	 */
	private array $config;

	/**
	 * The result being built.
	 *
	 * @var array
	 */
	private array $results = [];

	/**
	 * Clock and memory readings taken when the run started.
	 *
	 * @var array{time: float, memory: int, peak: int}
	 */
	private array $start;

	/**
	 * The service that re-saves objects, resolved on the first save.
	 *
	 * @var ObjectOperationsInterface|null
	 */
	private ?ObjectOperationsInterface $objectService = null;

	/**
	 * Every magic table as its search query and object count, read on first use.
	 *
	 * @var list<array{query: array, count: int}>|null
	 */
	private ?array $tables = null;

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface          $objectMapper Mapper that counts and reads the objects.
	 * @param AppSettingsInterface $settings     Settings service, for formatting byte counts.
	 * @param LoggerInterface      $logger       Logger.
	 * @param ContainerInterface   $container    Container that provides the ObjectService at save time.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
		private readonly AppSettingsInterface $settings,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Run mass validation.
	 *
	 * @param array{mode: string, max_objects: int, batch_size: int, collect_errors: bool} $config Validated configuration.
	 *
	 * @return array Mass validation results.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
	 */
	public function run(array $config): array {
		$this->start = $this->readClock();
		$this->config = $config;

		$totalObjects = $this->countObjects();
		$this->logStart(totalObjects: $totalObjects);
		$this->results = $this->initialResults(totalObjects: $totalObjects);
		$batchJobs = $this->createBatchJobs(totalObjects: $totalObjects, batchSize: $config['batch_size']);
		$this->results['stats']['batches_processed'] = count($batchJobs);
		$this->logBatchJobsCreated(batchCount: count($batchJobs));

		$this->processJobs(batchJobs: $batchJobs);

		$this->recordMetrics();
		$this->recordOutcome();
		return $this->results;
	}//end run()

	/**
	 * Clock and memory readings at this moment.
	 *
	 * @return array{time: float, memory: int, peak: int}
	 */
	private function readClock(): array {
		return [
			'time' => microtime(true),
			'memory' => memory_get_usage(true),
			'peak' => memory_get_peak_usage(true),
		];
	}//end readClock()

	/**
	 * Log the start of the run.
	 *
	 * @param int $totalObjects Number of objects to validate.
	 *
	 * @return void
	 */
	private function logStart(int $totalObjects): void {
		$this->logger->info(
			message: '[SettingsService] 🚀 STARTING MASS VALIDATION',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'totalObjects' => $totalObjects,
				'batchSize' => $this->config['batch_size'],
				'mode' => $this->config['mode'],
				'collectErrors' => $this->config['collect_errors'],
			]
		);
	}//end logStart()

	/**
	 * Log how many batch jobs the run will process.
	 *
	 * @param int $batchCount Number of batch jobs.
	 *
	 * @return void
	 */
	private function logBatchJobsCreated(int $batchCount): void {
		$this->logger->info(
			message: '[SettingsService] 📋 BATCH JOBS CREATED',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'totalBatches' => $batchCount,
				'estimatedDuration' => round(($batchCount * 2)) . 's',
			]
		);
	}//end logBatchJobsCreated()

	/**
	 * Number of objects to validate: all objects, capped by max_objects when set.
	 *
	 * @return int The object count.
	 */
	private function countObjects(): int {
		$totalObjects = array_sum(array_column($this->tables(), 'count'));

		$maxObjects = $this->config['max_objects'];
		if ($maxObjects > 0 && $maxObjects < $totalObjects) {
			return $maxObjects;
		}

		return $totalObjects;
	}//end countObjects()

	/**
	 * The result before any batch ran.
	 *
	 * @param int $totalObjects Number of objects to validate.
	 *
	 * @return array The initial result.
	 */
	private function initialResults(int $totalObjects): array {
		return [
			'success' => true,
			'message' => 'Mass validation completed successfully',
			'stats' => $this->initialStats(totalObjects: $totalObjects),
			'errors' => [],
			'batches_processed' => 0,
			'timestamp' => date('c'),
			'config_used' => $this->config,
		];
	}//end initialResults()

	/**
	 * The statistics before any batch ran.
	 *
	 * @param int $totalObjects Number of objects to validate.
	 *
	 * @return array<string, int> The initial statistics.
	 */
	private function initialStats(int $totalObjects): array {
		return [
			'total_objects' => $totalObjects,
			'processed_objects' => 0,
			'successful_saves' => 0,
			'failed_saves' => 0,
			'duration_seconds' => 0,
			'batches_processed' => 0,
			'objects_per_second' => 0,
		];
	}//end initialStats()

	/**
	 * Split the objects into batch jobs of at most batchSize objects.
	 *
	 * @param int $totalObjects Number of objects.
	 * @param int $batchSize    Objects per batch.
	 *
	 * @return array<int, array{batchNumber: int, offset: int, limit: int}> The batch jobs.
	 */
	private function createBatchJobs(int $totalObjects, int $batchSize): array {
		$batchJobs = [];
		$offset = 0;
		$batchNumber = 0;

		while ($offset < $totalObjects) {
			$currentBatchSize = min($batchSize, $totalObjects - $offset);
			$batchJobs[] = [
				'batchNumber' => ++$batchNumber,
				'offset' => $offset,
				'limit' => $currentBatchSize,
			];
			$offset += $currentBatchSize;
		}

		return $batchJobs;
	}//end createBatchJobs()

	/**
	 * Run the batch jobs in the configured mode.
	 *
	 * @param array $batchJobs The batch jobs.
	 *
	 * @return void
	 */
	private function processJobs(array $batchJobs): void {
		if ($this->config['mode'] === 'parallel') {
			$this->processJobsParallel(batchJobs: $batchJobs);
			return;
		}

		$this->processJobsSerial(batchJobs: $batchJobs);
	}//end processJobs()

	/**
	 * Run the batch jobs one after the other, logging progress per batch.
	 *
	 * @param array $batchJobs The batch jobs.
	 *
	 * @return void
	 */
	private function processJobsSerial(array $batchJobs): void {
		foreach ($batchJobs as $job) {
			$batch = $this->saveBatch(job: $job);
			$this->addBatch(batch: $batch);
			$this->logProgress(job: $job, batch: $batch);
			if ($job['batchNumber'] % 10 === 0) {
				$this->cleanupMemory();
			}
		}
	}//end processJobsSerial()

	/**
	 * Log the progress after one serial batch.
	 *
	 * @param array $job   The batch job.
	 * @param array $batch The batch counts.
	 *
	 * @return void
	 */
	private function logProgress(array $job, array $batch): void {
		$this->logger->info(
			message: '[SettingsService] 📈 MASS VALIDATION PROGRESS',
			context: array_merge(
				['file' => __FILE__, 'line' => __LINE__],
				$this->progressContext(job: $job, batch: $batch)
			)
		);
	}//end logProgress()

	/**
	 * The progress figures logged after one serial batch.
	 *
	 * The run's batch count was recorded before any batch ran.
	 *
	 * @param array $job   The batch job.
	 * @param array $batch The batch counts.
	 *
	 * @return array<string, mixed> The log context, without file and line.
	 */
	private function progressContext(array $job, array $batch): array {
		return [
			'batchNumber' => $job['batchNumber'],
			'totalBatches' => $this->results['stats']['batches_processed'],
			'processed' => $batch['processed'],
			'successful' => $batch['successful'],
			'failed' => $batch['failed'],
			'batchDuration' => round($batch['duration'] * 1000) . 'ms',
			'objectsPerSecond' => $this->objectsPerSecond(batch: $batch),
			'totalProcessed' => $this->results['stats']['processed_objects'],
		];
	}//end progressContext()

	/**
	 * The throughput of one batch, or 0 when it took no measurable time.
	 *
	 * @param array $batch The batch counts and duration.
	 *
	 * @return float|int Objects per second.
	 */
	private function objectsPerSecond(array $batch): float|int {
		if ($batch['duration'] > 0) {
			return round($batch['processed'] / $batch['duration'], 2);
		}

		return 0;
	}//end objectsPerSecond()

	/**
	 * Log memory use and collect garbage cycles.
	 *
	 * @return void
	 */
	private function cleanupMemory(): void {
		$this->logger->debug(
			message: '[SettingsService] 🧹 MEMORY CLEANUP',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'memoryUsage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
				'peakMemory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB',
			]
		);
		gc_collect_cycles();
	}//end cleanupMemory()

	/**
	 * Run the batch jobs in chunks of PARALLEL_BATCHES, logging progress per chunk.
	 *
	 * @param array $batchJobs The batch jobs.
	 *
	 * @return void
	 */
	private function processJobsParallel(array $batchJobs): void {
		$batchChunks = array_chunk($batchJobs, self::PARALLEL_BATCHES);
		foreach ($batchChunks as $chunkIndex => $chunk) {
			$this->logChunkStart(chunkIndex: $chunkIndex, batchChunks: $batchChunks);
			$chunkStartTime = microtime(true);
			$chunkResults = $this->saveChunk(chunk: $chunk);
			$this->logChunkDone(result: new ValidationChunkResult(index: $chunkIndex, startedAt: $chunkStartTime, batches: $chunkResults));
			gc_collect_cycles();
		}
	}//end processJobsParallel()

	/**
	 * Re-save every batch of a chunk, then add the batches to the result.
	 *
	 * @param array $chunk The batch jobs of the chunk.
	 *
	 * @return list<array> The batch counts, in job order.
	 */
	private function saveChunk(array $chunk): array {
		$chunkResults = [];
		foreach ($chunk as $job) {
			$chunkResults[] = $this->saveBatch(job: $job);
		}

		foreach ($chunkResults as $batch) {
			$this->addBatch(batch: $batch);
		}

		return $chunkResults;
	}//end saveChunk()

	/**
	 * Log the start of a parallel chunk.
	 *
	 * @param int         $chunkIndex  Zero-based chunk index.
	 * @param list<array> $batchChunks All chunks of batch jobs.
	 *
	 * @return void
	 */
	private function logChunkStart(int $chunkIndex, array $batchChunks): void {
		$this->logger->info(
			message: '[SettingsService] 🔄 PROCESSING PARALLEL CHUNK',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'chunkIndex' => $chunkIndex + 1,
				'totalChunks' => count($batchChunks),
				'batchesInChunk' => count($batchChunks[$chunkIndex]),
			]
		);
	}//end logChunkStart()

	/**
	 * Log the end of a parallel chunk.
	 *
	 * @param ValidationChunkResult $result The chunk: its index, start time and batch counts.
	 *
	 * @return void
	 */
	private function logChunkDone(ValidationChunkResult $result): void {
		$this->logger->info(
			message: '[SettingsService] ✅ COMPLETED PARALLEL CHUNK',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'chunkIndex' => $result->index + 1,
				'chunkTime' => round((microtime(true) - $result->startedAt) * 1000, 2) . 'ms',
				'objectsProcessed' => array_sum(array_column($result->batches, 'processed')),
				'totalProcessed' => $this->results['stats']['processed_objects'],
			]
		);
	}//end logChunkDone()

	/**
	 * Re-save the objects of one batch job.
	 *
	 * Without collect_errors the batch stops at its first failure. Serial runs log each failure.
	 *
	 * @param array $job The batch job.
	 *
	 * @return array{processed: int, successful: int, failed: int, errors: list<array>, duration: float}
	 */
	private function saveBatch(array $job): array {
		$batchStartTime = microtime(true);
		$objects = $this->objectsAt(offset: $job['offset'], limit: $job['limit']);

		$batch = $this->resaveAll(objects: $objects);
		$batch['failed'] = count($batch['errors']);
		$batch['duration'] = microtime(true) - $batchStartTime;
		return $batch;
	}//end saveBatch()

	/**
	 * Every magic table, as the query that names its register and schema and its object count.
	 *
	 * Objects live in one table per register and schema; a search or count that
	 * names neither answers nothing, so the run walks the tables.
	 *
	 * @return list<array{query: array, count: int}>
	 */
	private function tables(): array {
		if ($this->tables !== null) {
			return $this->tables;
		}

		$this->tables = [];
		foreach ($this->objectMapper->getAllRegisterSchemaPairs() as $pair) {
			$query = [
				'@self' => ['register' => (int)$pair['registerId'], 'schema' => (int)$pair['schemaId']],
				'_rbac' => false,
				'_multitenancy' => false,
			];
			$count = $this->objectMapper->countSearchObjects(query: $query, _activeOrgUuid: null, _rbac: false, _multitenancy: false);
			$this->tables[] = ['query' => $query, 'count' => $count];
		}

		return $this->tables;
	}//end tables()

	/**
	 * The objects at a run-wide offset, read table by table in the order of tables().
	 *
	 * @param int $offset The run-wide offset.
	 * @param int $limit  The number of objects.
	 *
	 * @return list<ObjectEntity>
	 */
	private function objectsAt(int $offset, int $limit): array {
		$objects = [];
		foreach ($this->tables() as $table) {
			if ($limit <= 0) {
				break;
			}

			if ($offset >= $table['count']) {
				$offset -= $table['count'];
				continue;
			}

			$page = $this->objectMapper->searchObjects(
				query: $table['query'] + ['_limit' => $limit, '_offset' => $offset],
				_activeOrgUuid: null,
				_rbac: false,
				_multitenancy: false
			);
			if (is_array($page) === true) {
				$objects = array_merge($objects, $page);
			}

			$limit -= min($limit, $table['count'] - $offset);
			$offset = 0;
		}//end foreach

		return $objects;
	}//end objectsAt()

	/**
	 * Re-save objects in order, stopping at the first failure unless collect_errors is set.
	 *
	 * @param iterable<ObjectEntity> $objects The objects.
	 *
	 * @return array{processed: int, successful: int, failed: int, errors: list<array>} The counts.
	 */
	private function resaveAll(iterable $objects): array {
		$batch = ['processed' => 0, 'successful' => 0, 'failed' => 0, 'errors' => []];
		foreach ($objects as $object) {
			$batch['processed']++;
			$error = $this->resave(object: $object);
			if ($error !== null) {
				$batch['errors'][] = $error;
				if ($this->config['collect_errors'] === false) {
					break;
				}
			}
		}

		// Every re-saved object either succeeded or left exactly one error entry.
		$batch['successful'] = ($batch['processed'] - count($batch['errors']));
		return $batch;
	}//end resaveAll()

	/**
	 * Re-save one object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array|null The error entry, or null when the save succeeded.
	 */
	private function resave(ObjectEntity $object): ?array {
		try {
			// Re-saving triggers all business logic; saveObject() signals failure by throwing.
			$this->objectService()->saveObject(
				object: $object->getObject(),
				extend: [],
				register: $object->getRegister(),
				schema: $object->getSchema(),
				uuid: $object->getUuid()
			);
			return null;
		} catch (Exception $e) {
			return $this->recordFailure(object: $object, error: $e);
		}
	}//end resave()

	/**
	 * The ObjectService, taken from the container on first use: SettingsService cannot
	 * receive it at construction because ObjectService depends on it.
	 *
	 * @return ObjectOperationsInterface The service that re-saves objects.
	 */
	private function objectService(): ObjectOperationsInterface {
		if ($this->objectService === null) {
			$this->objectService = $this->container->get(ObjectService::class);
		}

		return $this->objectService;
	}//end objectService()

	/**
	 * Describe a failed re-save, logging it in serial mode.
	 *
	 * @param ObjectEntity $object The object that failed.
	 * @param Exception    $error  The failure.
	 *
	 * @return array The error entry.
	 */
	private function recordFailure(ObjectEntity $object, Exception $error): array {
		$batchMode = 'parallel_optimized';
		if ($this->config['mode'] !== 'parallel') {
			$batchMode = 'serial_optimized';
			$this->logFailure(object: $object, error: $error);
		}

		return [
			'object_id' => $object->getUuid(),
			'object_name' => $object->getName() ?? $object->getUuid(),
			'register' => $object->getRegister(),
			'schema' => $object->getSchema(),
			'error' => $error->getMessage(),
			'batch_mode' => $batchMode,
		];
	}//end recordFailure()

	/**
	 * Log a failed re-save.
	 *
	 * @param ObjectEntity $object The object that failed.
	 * @param Exception    $error  The failure.
	 *
	 * @return void
	 */
	private function logFailure(ObjectEntity $object, Exception $error): void {
		$this->logger->error(
			message: "[SettingsService] Mass validation failed for object {$object->getUuid()}: {$error->getMessage()}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logFailure()

	/**
	 * Add one batch's counts and errors to the result.
	 *
	 * @param array $batch The batch counts and errors.
	 *
	 * @return void
	 */
	private function addBatch(array $batch): void {
		$this->results['stats']['processed_objects'] += $batch['processed'];
		$this->results['stats']['successful_saves'] += $batch['successful'];
		$this->results['stats']['failed_saves'] += $batch['failed'];
		$this->results['errors'] = array_merge($this->results['errors'], $batch['errors']);
	}//end addBatch()

	/**
	 * Record duration, throughput and memory use.
	 *
	 * @return void
	 */
	private function recordMetrics(): void {
		$endMemory = memory_get_usage(true);
		$peakMemory = max($this->start['peak'], memory_get_peak_usage(true));
		$duration = round(microtime(true) - $this->start['time'], 2);

		$this->results['stats']['duration_seconds'] = $duration;
		if ($duration > 0) {
			$this->results['stats']['objects_per_second'] = round($this->results['stats']['processed_objects'] / $duration, 2);
		}

		$this->results['memory_usage'] = $this->memoryUsage(endMemory: $endMemory, peakMemory: $peakMemory);
	}//end recordMetrics()

	/**
	 * The memory use of the run, raw and formatted.
	 *
	 * @param int $endMemory  Memory in use at the end of the run.
	 * @param int $peakMemory Peak memory use of the run.
	 *
	 * @return array The memory_usage entry of the result.
	 */
	private function memoryUsage(int $endMemory, int $peakMemory): array {
		$peakPercentage = round(($peakMemory / (1024 * 1024 * 1024)) * 100, 1);
		return [
			'start_memory' => $this->start['memory'],
			'end_memory' => $endMemory,
			'peak_memory' => $peakMemory,
			'memory_used' => $endMemory - $this->start['memory'],
			'peak_percentage' => $peakPercentage,
			'formatted' => [
				'actual_used' => $this->settings->formatBytes(bytes: $endMemory - $this->start['memory']),
				'peak_usage' => $this->settings->formatBytes(bytes: $peakMemory),
				'peak_percentage' => $peakPercentage . '%',
			],
		];
	}//end memoryUsage()

	/**
	 * Set success and message from the failures, and log the completed run.
	 *
	 * @return void
	 */
	private function recordOutcome(): void {
		$stats = $this->results['stats'];
		if ($stats['failed_saves'] > 0) {
			$this->results['success'] = $this->config['collect_errors'] === true && $stats['successful_saves'] > 0;
			$this->results['message'] = $this->failureMessage(stats: $stats);
		}

		$this->logCompleted(stats: $stats);
	}//end recordOutcome()

	/**
	 * The result message of a run with failures.
	 *
	 * @param array $stats The run statistics.
	 *
	 * @return string The message.
	 */
	private function failureMessage(array $stats): string {
		if ($this->config['collect_errors'] === true) {
			return $this->collectedMessage(stats: $stats);
		}

		return $this->stoppedMessage(stats: $stats);
	}//end failureMessage()

	/**
	 * The result message of a run that collected its failures.
	 *
	 * @param array $stats The run statistics.
	 *
	 * @return string The message.
	 */
	private function collectedMessage(array $stats): string {
		return sprintf(
			'Mass validation completed with %d errors out of %d objects (%d successful)',
			$stats['failed_saves'],
			$stats['total_objects'],
			$stats['successful_saves']
		);
	}//end collectedMessage()

	/**
	 * The result message of a run that stopped at its first failure.
	 *
	 * @param array $stats The run statistics.
	 *
	 * @return string The message.
	 */
	private function stoppedMessage(array $stats): string {
		return sprintf(
			'Mass validation stopped after %d errors (processed %d out of %d objects)',
			$stats['failed_saves'],
			$stats['processed_objects'],
			$stats['total_objects']
		);
	}//end stoppedMessage()

	/**
	 * Log the completed run.
	 *
	 * @param array $stats The run statistics.
	 *
	 * @return void
	 */
	private function logCompleted(array $stats): void {
		$this->logger->info(
			message: '[SettingsService] ✅ MASS VALIDATION COMPLETED',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'successful' => $stats['successful_saves'],
				'failed' => $stats['failed_saves'],
				'total' => $stats['processed_objects'],
				'duration' => $stats['duration_seconds'] . 's',
				'objectsPerSecond' => $stats['objects_per_second'],
				'mode' => $this->config['mode'],
			]
		);
	}//end logCompleted()
}//end class
