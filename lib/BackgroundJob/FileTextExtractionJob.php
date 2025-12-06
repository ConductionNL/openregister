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
     * @var IAppConfig
     */
    private IAppConfig $config;

    /**
     * Logger service
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Text extraction service
     *
     * @var TextExtractionService
     */
    private TextExtractionService $textExtractionService;


    /**
     * Run the background job
     *
     * Extracts text from the specified file and stores it in the database.
     * The job expects an argument array with 'file_id' key.
     *
     * @param array $argument Job arguments containing file_id
     *
     * @return void
     */
    protected function run($argument): void
    {
        if ($this->config->hasKey(app: 'openregister', key: 'fileManagement') === false
            || json_decode($this->config->getValueString(app: 'openregister', key: 'fileManagement'), true)['extractionScope'] === 'none'
        ) {
            $this->logger->info('[FileTextExtractionJob] File extraction is disabled. Not extracting text from files.');
            return;
        }

        // Validate argument.
        if (!isset($argument['file_id'])) {
            $this->logger->error(
                    '[FileTextExtractionJob] Missing file_id in job arguments',
                    [
                        'argument' => $argument,
                    ]
                    );
            return;
        }

        $fileId = (int) $argument['file_id'];

        $this->logger->info(
                '[FileTextExtractionJob] Starting text extraction',
                [
                    'file_id' => $fileId,
                    'job_id'  => $this->getId(),
                ]
                );

        $startTime = microtime(true);

        try {
            // Extract text using TextExtractionService.
            $this->textExtractionService->extractFile(fileId: $fileId, forceReextraction: false);

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                    '[FileTextExtractionJob] Text extraction completed successfully',
                    [
                        'file_id'            => $fileId,
                        'processing_time_ms' => $processingTime,
                    ]
                    );
        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

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
