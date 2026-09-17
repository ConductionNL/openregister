<?php

/**
 * Cron File Text Extraction Background Job
 *
 * Recurring background job that periodically processes files for text extraction.
 * This job runs at configurable intervals to handle files when extraction mode is set to 'cron'.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Recurring background job for periodic file text extraction
 *
 * This job runs automatically at configurable intervals to process files
 * that are pending text extraction when extraction mode is set to 'cron'.
 *
 * Features:
 * - Runs at configurable intervals (default: 15 minutes)
 * - Processes files in batches based on batch size setting
 * - Respects extraction scope and file type settings
 * - Detailed logging and error handling
 * - Automatic retry for failed files
 */

class CronFileTextExtractionJob extends TimedJob {
	/**
	 * Default interval: 15 minutes
	 */
	private const DEFAULT_INTERVAL = 15 * 60;

	/**
	 * Default batch size for processing files
	 */
	private const DEFAULT_BATCH_SIZE = 10;

	/**
	 * Constructor
	 *
	 * Initializes the timed job with the time factory and sets the interval.
	 *
	 * @param ITimeFactory $time Time factory for parent class
	 * @param ContainerInterface $container App container the job resolves its collaborators from at run time
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(ITimeFactory $time, private readonly ContainerInterface $container) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
	}//end __construct()

	/**
	 * Execute the cron file text extraction job
	 *
	 * @param mixed $argument Job arguments (unused for recurring jobs)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function run($argument): void {
		$startTime = microtime(true);

		/*
		 * @var LoggerInterface $logger
		 */

		$logger = $this->container->get(LoggerInterface::class);

		$logger->info(
			message: '[CronFileTextExtractionJob] 🔄 Cron File Text Extraction Job Started',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'job_id' => $this->getId(),
				'scheduled_time' => date('Y-m-d H:i:s'),
			]
		);

		try {
			/*
			 * Get required services.
			 *
			 * @var SettingsService $settingsService
			 */

			$settingsService = $this->container->get(SettingsService::class);

			/*
			 * @var TextExtractionService $textExtractor
			 */

			$textExtractor = $this->container->get(TextExtractionService::class);



			// Check if extraction mode is set to 'cron'.
			$fileSettings = $settingsService->getFileSettingsOnly();
			$extractionMode = $fileSettings['extractionMode'] ?? 'background';

			if ($extractionMode !== 'cron') {
				$logger->debug(
					message: '[CronFileTextExtractionJob] Skipped - mode is not cron',
					context: ['file' => __FILE__, 'line' => __LINE__, 'extraction_mode' => $extractionMode]
				);
				return;
			}

			// Get batch size from settings.
			$batchSize = $fileSettings['batchSize'] ?? self::DEFAULT_BATCH_SIZE;
			$extractionScope = $fileSettings['extractionScope'] ?? 'objects';

			$logger->info(
				message: '[CronFileTextExtractionJob] Starting cron file text extraction',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'batch_size' => $batchSize,
					'extraction_scope' => $extractionScope,
				]
			);

			// One selection loop for every extraction path. The job used to take a
			// single window of findUntrackedFiles() and walk it itself, so a handful
			// of permanently unreadable files with low fileids filled that window on
			// every run and the cron mode never reached a newer upload (WOO-576, the
			// same head-of-queue effect the bulk endpoint had). extractPendingFiles()
			// steps its window past the failures, so the cron mode inherits that.
			$stats = $textExtractor->extractPendingFiles(limit: $batchSize);
			$processed = $stats['processed'];
			$failed = $stats['failed'];
			if ($stats['total'] === 0) {
				// phpcs:ignore Generic.Files.LineLength.MaxExceeded
				$logger->info(message: '[CronFileTextExtractionJob] No pending files found for cron extraction', context: ['file' => __FILE__, 'line' => __LINE__]);
				return;
			}

			$executionTime = microtime(true) - $startTime;

			$logger->info(
				message: '[CronFileTextExtractionJob] ✅ Cron File Text Extraction Job Completed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'job_id' => $this->getId(),
					'execution_time_seconds' => round($executionTime, 2),
					'files_processed' => $processed,
					'files_failed' => $failed,
					'next_run' => date('Y-m-d H:i:s', time() + self::DEFAULT_INTERVAL),
				]
			);
		} catch (\Exception $e) {
			$executionTime = microtime(true) - $startTime;

			$logger->error(
				message: '[CronFileTextExtractionJob] 🚨 Cron File Text Extraction Job Exception',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'job_id' => $this->getId(),
					'execution_time_seconds' => round($executionTime, 2),
					'exception' => $e->getMessage(),
					'exception_file' => $e->getFile(),
					'exception_line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Don't re-throw for recurring jobs - let them retry next time.
		}//end try
	}//end run()

}//end class
