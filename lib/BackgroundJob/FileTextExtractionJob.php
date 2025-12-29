<?php

/**
 * File Text Extraction Background Job
 *
 * One-time background job that extracts text from uploaded files asynchronously.
 * This job is queued automatically when files are created or modified to avoid
 * blocking user requests with potentially slow text extraction operations.
 *
 * @category  BackgroundJob
 * @package   OCA\OpenRegister\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\TextExtractionService;
use OCP\BackgroundJob\QueuedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * One-time background job for file text extraction
 *
 * This job is automatically queued when files are created or modified to
 * extract text content asynchronously without blocking the user's request.
 *
 * Features:
 * - Runs once per file in the background
 * - Non-blocking: doesn't slow down file uploads
 * - Automatic retry for failed extractions
 * - Comprehensive logging and error handling
 * - Supports all file formats (PDF, DOCX, images, etc.)
 *
 * @package OCA\OpenRegister\BackgroundJob
 */
class FileTextExtractionJob extends QueuedJob
{

    /**
     * Configuration service
     *
     * Used to check if file text extraction is enabled in settings.
     *
     * @var IAppConfig Application configuration service
     */
    private readonly IAppConfig $config;

    /**
     * Logger service
     *
     * Used for logging extraction progress, errors, and debug information.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;

    /**
     * Text extraction service
     *
     * Handles actual text extraction from various file formats.
     *
     * @var TextExtractionService Text extraction service instance
     */
    private readonly TextExtractionService $textExtractionService;

    /**
     * Run the background job
     *
     * Extracts text from the specified file and stores it in the database.
     * Checks if extraction is enabled before proceeding. Validates job arguments
     * and handles errors gracefully.
     *
     * @param array<string, mixed> $argument Job arguments containing:
     *                                       - file_id: The ID of the file to extract text from (required)
     *
     * @return void
     */
    protected function run($argument): void
    {
        // Step 1: Check if file text extraction is enabled in configuration.
        // Skip extraction if disabled to avoid unnecessary processing.
        if ($this->config->hasKey(app: 'openregister', key: 'fileManagement') === false
            || json_decode($this->config->getValueString(app: 'openregister', key: 'fileManagement'), true)['extractionScope'] === 'none'
        ) {
            $this->logger->info('[FileTextExtractionJob] File extraction is disabled. Not extracting text from files.');
            return;
        }

        // Step 2: Validate that required file_id argument is present.
        if (isset($argument['file_id']) === false) {
            $this->logger->error(
                '[FileTextExtractionJob] Missing file_id in job arguments',
                [
                    'argument' => $argument,
                ]
            );
            return;
        }

        // Step 3: Extract and cast file ID to integer.
        $fileId = (int) $argument['file_id'];

        // Log start of extraction process for monitoring.
        $this->logger->info(
            '[FileTextExtractionJob] Starting text extraction',
            [
                'file_id' => $fileId,
                'job_id'  => $this->getId(),
            ]
        );

        // Record start time for performance metrics.
        $startTime = microtime(true);

        try {
            // Extract text using TextExtractionService.
            $this->textExtractionService->extractFile(fileId: $fileId, forceReExtract: false);

            // Calculate processing time in milliseconds.
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            // Log successful completion with performance metrics.
            $this->logger->info(
                '[FileTextExtractionJob] Text extraction completed successfully',
                [
                    'file_id'            => $fileId,
                    'processing_time_ms' => $processingTime,
                ]
            );
        } catch (\Exception $e) {
            // Calculate processing time even on failure for metrics.
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            // Log error with full exception details for debugging.
            $this->logger->error(
                '[FileTextExtractionJob] Exception during text extraction',
                [
                    'file_id'            => $fileId,
                    'error'              => $e->getMessage(),
                    'trace'              => $e->getTraceAsString(),
                    'processing_time_ms' => $processingTime,
                ]
            );
        }//end try
    }//end run()
}//end class
