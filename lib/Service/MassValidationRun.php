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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use Psr\Log\LoggerInterface;

/**
 * Re-saves objects in batches, serially or in chunks of batches, and collects the counts,
 * errors, timings and memory use of the run in the shape SettingsService::massValidateObjects()
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
	 * Constructor.
	 *
	 * @param MagicMapper          $objectMapper Mapper that counts and reads the objects.
	 * @param AppSettingsInterface $settings     Settings service, for formatting byte counts.
	 * @param LoggerInterface      $logger       Logger.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
	 */
	public function __construct(
		private readonly MagicMapper $objectMapper,
		private readonly AppSettingsInterface $settings,
		private readonly LoggerInterface $logger,
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
		$this->start = [
			'time' => microtime(true),
			'memory' => memory_get_usage(true),
			'peak' => memory_get_peak_usage(true),
		];
		$this->config = $config;

		$totalObjects = $this->countObjects();
		$this->logger->info(
			message: '[SettingsService] 🚀 STARTING MASS VALIDATION',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'totalObjects' => $totalObjects,
				'batchSize' => $config['batch_size'],
				'mode' => $config['mode'],
				'collectErrors' => $config['collect_errors'],
			]
		);

		$this->results = $this->initialResults(totalObjects: $totalObjects);
		$batchJobs = $this->createBatchJobs(totalObjects: $totalObjects, batchSize: $config['batch_size']);
		$this->results['stats']['batches_processed'] = count($batchJobs);

		$this->logger->info(
			message: '[SettingsService] 📋 BATCH JOBS CREATED',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'totalBatches' => count($batchJobs),
				'estimatedDuration' => round((count($batchJobs) * 2)) . 's',
			]
		);

		// SettingsService cannot lazy-load ObjectService (circular dependency), so there is
		// no service to re-save with and a run that reaches an object fails.
		$objectService = null;
		$this->processJobs(batchJobs: $batchJobs, objectService: $objectService);

		$this->recordMetrics();
		$this->recordOutcome();
		return $this->results;
	}//end run()

	/**
	 * Number of objects to validate: all objects, capped by max_objects when set.
	 *
	 * @return int The object count.
	 */
	private function countObjects(): int {
		$totalObjects = $this->objectMapper->countSearchObjects(
			query: [],
			_activeOrgUuid: null,
			_rbac: false,
			_multitenancy: false
		);

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
			'stats' => [
				'total_objects' => $totalObjects,
				'processed_objects' => 0,
				'successful_saves' => 0,
				'failed_saves' => 0,
				'duration_seconds' => 0,
				'batches_processed' => 0,
				'objects_per_second' => 0,
			],
			'errors' => [],
			'batches_processed' => 0,
			'timestamp' => date('c'),
			'config_used' => $this->config,
		];
	}//end initialResults()

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
	 * @param array              $batchJobs     The batch jobs.
	 * @param ObjectService|null $objectService The service that re-saves objects.
	 *
	 * @return void
	 */
	private function processJobs(array $batchJobs, ?ObjectService $objectService): void {
		if ($this->config['mode'] === 'parallel') {
			$this->processJobsParallel(batchJobs: $batchJobs, objectService: $objectService);
			return;
		}

		$this->processJobsSerial(batchJobs: $batchJobs, objectService: $objectService);
	}//end processJobs()

	/**
	 * Run the batch jobs one after the other, logging progress per batch.
	 *
	 * @param array              $batchJobs     The batch jobs.
	 * @param ObjectService|null $objectService The service that re-saves objects.
	 *
	 * @return void
	 */
	private function processJobsSerial(array $batchJobs, ?ObjectService $objectService): void {
		foreach ($batchJobs as $job) {
			$batch = $this->saveBatch(job: $job, objectService: $objectService);
			$this->addBatch(batch: $batch);

			$objectsPerSecond = 0;
			if ($batch['duration'] > 0) {
				$objectsPerSecond = round($batch['processed'] / $batch['duration'], 2);
			}

			$this->logger->info(
				message: '[SettingsService] 📈 MASS VALIDATION PROGRESS',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'batchNumber' => $job['batchNumber'],
					'totalBatches' => count($batchJobs),
					'processed' => $batch['processed'],
					'successful' => $batch['successful'],
					'failed' => $batch['failed'],
					'batchDuration' => round($batch['duration'] * 1000) . 'ms',
					'objectsPerSecond' => $objectsPerSecond,
					'totalProcessed' => $this->results['stats']['processed_objects'],
				]
			);

			if ($job['batchNumber'] % 10 === 0) {
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
			}
		}//end foreach
	}//end processJobsSerial()

	/**
	 * Run the batch jobs in chunks of PARALLEL_BATCHES, logging progress per chunk.
	 *
	 * @param array              $batchJobs     The batch jobs.
	 * @param ObjectService|null $objectService The service that re-saves objects.
	 *
	 * @return void
	 */
	private function processJobsParallel(array $batchJobs, ?ObjectService $objectService): void {
		$batchChunks = array_chunk($batchJobs, self::PARALLEL_BATCHES);

		foreach ($batchChunks as $chunkIndex => $chunk) {
			$this->logger->info(
				message: '[SettingsService] 🔄 PROCESSING PARALLEL CHUNK',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'chunkIndex' => $chunkIndex + 1,
					'totalChunks' => count($batchChunks),
					'batchesInChunk' => count($chunk),
				]
			);

			$chunkStartTime = microtime(true);
			$chunkResults = [];
			foreach ($chunk as $job) {
				$chunkResults[] = $this->saveParallelBatch(objectService: $objectService, job: $job);
			}

			foreach ($chunkResults as $batch) {
				$this->addBatch(batch: $batch);
			}

			$this->logger->info(
				message: '[SettingsService] ✅ COMPLETED PARALLEL CHUNK',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'chunkIndex' => $chunkIndex + 1,
					'chunkTime' => round((microtime(true) - $chunkStartTime) * 1000, 2) . 'ms',
					'objectsProcessed' => array_sum(array_column($chunkResults, 'processed')),
					'totalProcessed' => $this->results['stats']['processed_objects'],
				]
			);

			gc_collect_cycles();
		}//end foreach
	}//end processJobsParallel()

	/**
	 * Re-save one batch in parallel mode, which requires the object service up front.
	 *
	 * @param ObjectService $objectService The service that re-saves objects.
	 * @param array         $job           The batch job.
	 *
	 * @return array The batch counts, errors and duration.
	 */
	private function saveParallelBatch(ObjectService $objectService, array $job): array {
		return $this->saveBatch(job: $job, objectService: $objectService);
	}//end saveParallelBatch()

	/**
	 * Re-save the objects of one batch job.
	 *
	 * Without collect_errors the batch stops at its first failure. Serial runs log each failure.
	 *
	 * @param array              $job           The batch job.
	 * @param ObjectService|null $objectService The service that re-saves objects.
	 *
	 * @return array{processed: int, successful: int, failed: int, errors: list<array>, duration: float}
	 */
	private function saveBatch(array $job, ?ObjectService $objectService): array {
		$batchStartTime = microtime(true);
		$objects = $this->objectMapper->findAll(limit: $job['limit'], offset: $job['offset']);

		$batch = ['processed' => 0, 'successful' => 0, 'failed' => 0, 'errors' => []];
		foreach ($objects as $object) {
			$batch['processed']++;
			try {
				// Re-saving triggers all business logic; saveObject() signals failure by throwing.
				$objectService->saveObject(
					object: $object->getObject(),
					extend: [],
					register: $object->getRegister(),
					schema: $object->getSchema(),
					uuid: $object->getUuid()
				);
				$batch['successful']++;
			} catch (Exception $e) {
				$batch['errors'][] = $this->recordFailure(object: $object, error: $e);
				if ($this->config['collect_errors'] === false) {
					break;
				}
			}
		}

		$batch['failed'] = count($batch['errors']);
		$batch['duration'] = microtime(true) - $batchStartTime;
		return $batch;
	}//end saveBatch()

	/**
	 * Describe a failed re-save, logging it in serial mode.
	 *
	 * @param ObjectEntity $object The object that failed.
	 * @param Exception    $error  The failure.
	 *
	 * @return array The error entry.
	 */
	private function recordFailure(ObjectEntity $object, Exception $error): array {
		$serial = $this->config['mode'] !== 'parallel';
		if ($serial === true) {
			$this->logger->error(
				message: "[SettingsService] Mass validation failed for object {$object->getUuid()}: {$error->getMessage()}",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		$batchMode = 'parallel_optimized';
		if ($serial === true) {
			$batchMode = 'serial_optimized';
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

		$peakPercentage = round(($peakMemory / (1024 * 1024 * 1024)) * 100, 1);
		$this->results['memory_usage'] = [
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
	}//end recordMetrics()

	/**
	 * Set success and message from the failures, and log the completed run.
	 *
	 * @return void
	 */
	private function recordOutcome(): void {
		$stats = $this->results['stats'];
		if ($stats['failed_saves'] > 0 && $this->config['collect_errors'] === true) {
			$this->results['success'] = $stats['successful_saves'] > 0;
			$this->results['message'] = sprintf(
				'Mass validation completed with %d errors out of %d objects (%d successful)',
				$stats['failed_saves'],
				$stats['total_objects'],
				$stats['successful_saves']
			);
		} elseif ($stats['failed_saves'] > 0) {
			$this->results['success'] = false;
			$this->results['message'] = sprintf(
				'Mass validation stopped after %d errors (processed %d out of %d objects)',
				$stats['failed_saves'],
				$stats['processed_objects'],
				$stats['total_objects']
			);
		}

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
	}//end recordOutcome()
}//end class
