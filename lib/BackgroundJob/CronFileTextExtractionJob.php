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
     * Constructor
     *
     * @param ITimeFactory $time Time factory for job scheduling
     *
     * @return void
     */
    public function __construct(ITimeFactory $time)
    {
        parent::__construct($time);

        // Set interval to 15 minutes (configurable via settings).
        $this->setInterval(self::DEFAULT_INTERVAL);

        // Set as time insensitive to run during low-load periods.
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

    }//end __construct()


    /**
     * Execute the cron file text extraction job
     *
     * @param array $argument Job arguments (unused for recurring jobs)
     *
     * @return void
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
             * @var TextExtractionService $textExtractionService
             */
            $textExtractionService = \OC::$server->get(TextExtractionService::class);

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
            $pendingFiles = $this->getPendingFiles($fileMapper, $extractionScope, $batchSize, $logger);

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

                    $textExtractionService->extractFile($fileId, false);
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
     * Get pending files for extraction based on scope
     *
     * @param FileMapper      $fileMapper      File mapper
     * @param string          $extractionScope Extraction scope (none, all, folders, objects)
     * @param int             $batchSize       Batch size limit
     * @param LoggerInterface $logger          Logger
     *
     * @return array List of pending files
     */
    private function getPendingFiles(
        FileMapper $fileMapper,
        string $extractionScope,
        int $batchSize,
        LoggerInterface $logger
    ): array {
        try {
            // Get untracked files (files without chunks) based on extraction scope.
            // Note: Extraction scope filtering may need enhancement based on specific requirements.
            // For now, we get all untracked files and let TextExtractionService handle scope checking.
            $files = $fileMapper->findUntrackedFiles($batchSize);

            // Filter by extraction scope if needed.
            // For 'objects' scope, we'd need to check if files are attached to objects.
            // For 'folders' scope, we'd need to check if files are in specific folders.
            // This is a simplified implementation - scope filtering happens in TextExtractionService.
            return $files;
        } catch (\Exception $e) {
            $logger->error(
                    'Failed to get pending files for cron extraction',
                    ['error' => $e->getMessage()]
                    );

            return [];
        }//end try

    }//end getPendingFiles()


}//end class
