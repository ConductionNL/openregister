<?php

/**
 * Cron File Text Extraction Background Job
 *
 * Recurring background job that periodically processes files for text extraction.
 * This job runs at configurable intervals to handle files when extraction mode is set to 'cron'.
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
use OCA\OpenRegister\Db\FileMapper;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
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

class CronFileTextExtractionJob extends TimedJob
{
    /**
     * Default interval: 15 minutes
     */
    private const DEFAULT_INTERVAL = 15 * 60;

    /**
     * Default batch size for processing files
     */
    private const DEFAULT_BATCH_SIZE = 10;

    /**
     * Execute the cron file text extraction job
     *
     * @param mixed $argument Job arguments (unused for recurring jobs)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        $startTime = microtime(true);

        /*
         * @var LoggerInterface $logger
         */

        $logger = \OC::$server->get(LoggerInterface::class);

        $logger->info(
            message: '🔄 Cron File Text Extraction Job Started',
            context: [
                'job_id'         => $this->getId(),
                'scheduled_time' => date('Y-m-d H:i:s'),
            ]
        );

        try {
            /*
             * Get required services.
             *
             * @var SettingsService $settingsService
             */

            $settingsService = \OC::$server->get(SettingsService::class);

            /*
             * @var TextExtractionService $textExtractor
             */

            $textExtractor = \OC::$server->get(TextExtractionService::class);

            /*
             * @var FileMapper $fileMapper
             */

            $fileMapper = \OC::$server->get(FileMapper::class);

            // Check if extraction mode is set to 'cron'.
            $fileSettings   = $settingsService->getFileSettingsOnly();
            $extractionMode = $fileSettings['extractionMode'] ?? 'background';

            if ($extractionMode !== 'cron') {
                $logger->debug(
                    'Cron File Text Extraction Job skipped - extraction mode is not cron',
                    ['extraction_mode' => $extractionMode]
                );
                return;
            }

            // Get batch size from settings.
            $batchSize       = $fileSettings['batchSize'] ?? self::DEFAULT_BATCH_SIZE;
            $extractionScope = $fileSettings['extractionScope'] ?? 'objects';

            $logger->info(
                'Starting cron file text extraction',
                [
                    'batch_size'       => $batchSize,
                    'extraction_scope' => $extractionScope,
                ]
            );

            // Get pending files based on extraction scope.
            $pendingFiles = $this->getPendingFiles(
                fileMapper: $fileMapper,
                    extractionScope: $extractionScope,
                batchSize: $batchSize,
                    logger: $logger
            );

            if (empty($pendingFiles) === true) {
                $logger->info('No pending files found for cron extraction');
                return;
            }

            $logger->info(
                'Processing files in cron job',
                [
                    'files_count' => count($pendingFiles),
                    'batch_size'  => $batchSize,
                ]
            );

            // Process each file.
            $processed = 0;
            $failed    = 0;

            foreach ($pendingFiles as $file) {
                try {
                    $fileId = (int) ($file['fileid'] ?? 0);

                    if ($fileId === 0) {
                        continue;
                    }

                    $logger->debug(
                        'Processing file in cron job',
                        [
                            'file_id'   => $fileId,
                            'file_name' => $file['name'] ?? 'unknown',
                        ]
                    );

                    $textExtractor->extractFile(fileId: $fileId, forceReExtract: false);
                    $processed++;

                    $logger->debug(
                        'File processed successfully in cron job',
                        ['file_id' => $fileId]
                    );
                } catch (\Exception $e) {
                    $failed++;
                    $logger->error(
                        'Failed to process file in cron job',
                        [
                            'file_id' => $fileId ?? 0,
                            'error'   => $e->getMessage(),
                        ]
                    );
                }//end try
            }//end foreach

            $executionTime = microtime(true) - $startTime;

            $logger->info(
                '✅ Cron File Text Extraction Job Completed',
                [
                    'job_id'                 => $this->getId(),
                    'execution_time_seconds' => round($executionTime, 2),
                    'files_processed'        => $processed,
                    'files_failed'           => $failed,
                    'next_run'               => date('Y-m-d H:i:s', time() + self::DEFAULT_INTERVAL),
                ]
            );
        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;

            $logger->error(
                message: '🚨 Cron File Text Extraction Job Exception',
                context: [
                    'job_id'                 => $this->getId(),
                    'execution_time_seconds' => round($executionTime, 2),
                    'exception'              => $e->getMessage(),
                    'file'                   => $e->getFile(),
                    'line'                   => $e->getLine(),
                    'trace'                  => $e->getTraceAsString(),
                ]
            );

            // Don't re-throw for recurring jobs - let them retry next time.
        }//end try
    }//end run()

    /**
     * Get pending files for text extraction based on scope and batch size.
     *
     * Retrieves files that need text extraction based on the configured extraction scope.
     * Files are returned in batches to prevent overwhelming the system.
     *
     * @param FileMapper      $fileMapper      File mapper for database queries
     * @param string          $extractionScope Extraction scope (objects, all, etc.)
     * @param int             $batchSize       Maximum number of files to retrieve
     * @param LoggerInterface $logger          Logger for debug messages
     *
     * @return array<int, array<string, mixed>> Array of pending file records
     *
     * @psalm-return array<int, array{fileid?: int, name?: string, ...}>
     */
    private function getPendingFiles(
        FileMapper $fileMapper,
        string $extractionScope,
        int $batchSize,
        LoggerInterface $logger
    ): array {
        // Log query parameters for debugging.
        $logger->debug(
            'Fetching pending files for cron extraction',
            [
                'extraction_scope' => $extractionScope,
                'batch_size'       => $batchSize,
            ]
        );

        try {
            // Get pending files based on extraction scope.
            // Files are considered "pending" if they have no extracted text or if extraction failed previously.
            $pendingFiles = $fileMapper->findUntrackedFiles(
                limit: $batchSize
            );

            $logger->debug(
                'Retrieved pending files',
                [
                    'count'      => count($pendingFiles),
                    'batch_size' => $batchSize,
                    'scope'      => $extractionScope,
                ]
            );

            return $pendingFiles;
        } catch (\Exception $e) {
            // Log error but don't throw - return empty array to continue gracefully.
            $logger->error(
                'Failed to retrieve pending files',
                [
                    'error'            => $e->getMessage(),
                    'extraction_scope' => $extractionScope,
                    'batch_size'       => $batchSize,
                ]
            );

            return [];
        }//end try
    }//end getPendingFiles()
}//end class
