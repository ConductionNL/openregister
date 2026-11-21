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

namespace OCA\OpenRegister\Files\BackgroundJob;

use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Files\Service\TextExtractionService;
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
			context: ['file' => __FILE__, 'line' => __LINE__, 'job_id' => $this->getId(), 'scheduled_time' => date('Y-m-d H:i:s')]
		);

		$tick = new CronExtractionRun(logger: $logger, startTime: $startTime);
		try {
			$this->extractInCronMode(tick: $tick);
		} catch (\Exception $e) {
			$this->logFailure(tick: $tick, exception: $e);
			// Don't re-throw for recurring jobs - let them retry next time.
		}
	}//end run()

	/**
	 * Extract one batch of pending files when the extraction mode is cron.
	 *
	 * @param CronExtractionRun $tick The logger and the start time.
	 *
	 * @return void
	 */
	private function extractInCronMode(CronExtractionRun $tick): void {
		$logger = $tick->logger;

		/*
		 * Get required services.
		 *
		 * @var AppSettingsInterface $settingsService
		 */

		$settingsService = $this->container->get(AppSettingsInterface::class);

		/*
		 * @var TextExtractionService $textExtractor
		 */

		$textExtractor = $this->container->get(TextExtractionService::class);

		$fileSettings = $this->cronFileSettings(logger: $logger, fileSettings: $settingsService->getFileSettingsOnly());
		if ($fileSettings === null) {
			return;
		}

		// Get batch size from settings.
		$batchSize       = $fileSettings['batchSize'] ?? self::DEFAULT_BATCH_SIZE;
		$extractionScope = $fileSettings['extractionScope'] ?? 'objects';

		$logger->info(
			message: '[CronFileTextExtractionJob] Starting cron file text extraction',
			context: ['file' => __FILE__, 'line' => __LINE__, 'batch_size' => $batchSize, 'extraction_scope' => $extractionScope]
		);

		// One selection loop for every extraction path. The job used to take a
		// single window of findUntrackedFiles() and walk it itself, so a handful
		// of permanently unreadable files with low fileids filled that window on
		// every run and the cron mode never reached a newer upload (WOO-576, the
		// same head-of-queue effect the bulk endpoint had). extractPendingFiles()
		// steps its window past the failures, so the cron mode inherits that.
		$this->logOutcome(tick: $tick, stats: $textExtractor->extractPendingFiles(limit: $batchSize));
	}//end extractInCronMode()

	/**
	 * The file settings when the extraction mode is cron, or null (logged) when it is not.
	 *
	 * @param LoggerInterface $logger       The logger.
	 * @param array           $fileSettings The file settings.
	 *
	 * @return array|null The file settings, or null when this job should not run.
	 */
	private function cronFileSettings(LoggerInterface $logger, array $fileSettings): ?array {
		// Check if extraction mode is set to 'cron'.
		$extractionMode = $fileSettings['extractionMode'] ?? 'background';
		if ($extractionMode === 'cron') {
			return $fileSettings;
		}

		$logger->debug(
			message: '[CronFileTextExtractionJob] Skipped - mode is not cron',
			context: ['file' => __FILE__, 'line' => __LINE__, 'extraction_mode' => $extractionMode]
		);
		return null;
	}//end cronFileSettings()

	/**
	 * Log how the batch ended: nothing pending, stopped on the window limit, or completed.
	 *
	 * @param CronExtractionRun $tick  The logger and the start time.
	 * @param array             $stats The extractPendingFiles() statistics.
	 *
	 * @return void
	 */
	private function logOutcome(CronExtractionRun $tick, array $stats): void {
		$logger = $tick->logger;
		if ($stats['total'] === 0) {
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			$logger->info(message: '[CronFileTextExtractionJob] No pending files found for cron extraction', context: ['file' => __FILE__, 'line' => __LINE__]);
			return;
		}

		// The cron path is the one that runs unattended, so it is the one that
		// most needs to say when a walk stopped on MAX_PENDING_WINDOWS instead of
		// on an empty queue: the offset is not carried between ticks, so every
		// following tick re-walks the same windows. Logged at warning level,
		// because in the counters alone a truncated run reads as a finished one.
		$logContext = $this->completionContext(tick: $tick, stats: $stats);

		if ($logContext['truncated'] === true) {
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			$logger->warning(message: '[CronFileTextExtractionJob] Cron File Text Extraction Job stopped on the window limit before filling its batch - the queue head is not extractable and every tick will re-walk it', context: $logContext);
			return;
		}

		$logger->info(message: '[CronFileTextExtractionJob] ✅ Cron File Text Extraction Job Completed', context: $logContext);
	}//end logOutcome()

	/**
	 * The completion log context: the run's counters, whether the walk was truncated, and the next run.
	 *
	 * @param CronExtractionRun    $tick  The run (logger and start time).
	 * @param array<string, mixed> $stats The run's stats.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function completionContext(CronExtractionRun $tick, array $stats): array {
		return [
			'file' => __FILE__, 'line' => __LINE__, 'job_id' => $this->getId(), 'execution_time_seconds' => round(microtime(true) - $tick->startTime, 2),
			'files_processed' => $stats['processed'], 'files_failed' => $stats['failed'], 'truncated' => ($stats['truncated'] ?? false),
			'next_run' => date('Y-m-d H:i:s', time() + self::DEFAULT_INTERVAL),
		];
	}//end completionContext()

	/**
	 * Log a run that threw.
	 *
	 * @param CronExtractionRun $tick      The logger and the start time.
	 * @param \Exception        $exception What was thrown.
	 *
	 * @return void
	 */
	private function logFailure(CronExtractionRun $tick, \Exception $exception): void {
		$tick->logger->error(
			message: '[CronFileTextExtractionJob] 🚨 Cron File Text Extraction Job Exception',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'job_id' => $this->getId(), 'execution_time_seconds' => round(microtime(true) - $tick->startTime, 2),
				'exception' => $exception->getMessage(), 'exception_file' => $exception->getFile(), 'exception_line' => $exception->getLine(),
				'trace' => $exception->getTraceAsString(),
			]
		);
	}//end logFailure()

}//end class
