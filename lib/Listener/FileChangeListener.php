<?php

/**
 * OpenRegister File Change Listener
 *
 * Listens for file creation and update events to queue asynchronous text extraction.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * FileChangeListener
 *
 * Listens for file creation and update events to queue asynchronous text extraction.
 * Instead of processing files synchronously (which would block user requests),
 * this listener queues a background job for each file that needs processing.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @template-implements IEventListener<NodeCreatedEvent|NodeWrittenEvent>
 */
class FileChangeListener implements IEventListener
{
    /**
     * Constructor
     *
     * @param TextExtractionService $textExtractSvc  Text extraction service
     * @param SettingsService       $settingsService Settings service
     * @param IJobList              $jobList         Job list for queuing background jobs
     * @param LoggerInterface       $logger          Logger
     */
    public function __construct(
        private readonly TextExtractionService $textExtractSvc,
        private readonly SettingsService $settingsService,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle file events
     *
     * @param Event $event File event
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  File event handling requires many conditional checks
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) File event handling requires comprehensive case coverage
     * @SuppressWarnings(PHPMD.NPathComplexity)       File event handling requires many conditional checks
     */
    public function handle(Event $event): void
    {
        // Only handle NodeCreatedEvent and NodeWrittenEvent.
        if (($event instanceof NodeCreatedEvent) === false
            && ($event instanceof NodeWrittenEvent) === false
        ) {
            return;
        }

        $node = $event->getNode();

        // Only process files, not folders.
        if (($node instanceof File) === false) {
            return;
        }

        $fileId   = $node->getId();
        $fileName = $node->getName();
        $filePath = $node->getPath();

        // Skip anonymized files - they should not be scanned for entities.
        // Anonymized files are created with '_anonymized' suffix by the anonymization process.
        if (strpos($fileName, '_anonymized') !== false) {
            $this->logger->debug(
                message: '[FileChangeListener] Skipping anonymized file',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'file_id'   => $fileId,
                    'file_name' => $fileName,
                    'file_path' => $filePath,
                ]
            );
            return;
        }

        // Get extraction settings to determine scope.
        try {
            $fileSettings    = $this->settingsService->getFileSettingsOnly();
            $extractionScope = $fileSettings['extractionScope'] ?? 'objects';
        } catch (\Exception $e) {
            $extractionScope = 'objects';
        }

        // Determine if file should be processed based on extraction scope.
        $isOpenRegisterFile = strpos($filePath, 'OpenRegister/files') !== false
            || strpos($filePath, '/Open Registers/') !== false;

        // Check extraction scope to decide if we should process this file.
        // - 'none': Skip all files.
        // - 'objects': Only process OpenRegister files.
        // - 'files': Process all user files (not OpenRegister-specific).
        // - 'all': Process all files.
        if ($extractionScope === 'none') {
            $this->logger->debug(
                message: '[FileChangeListener] Extraction scope is none, skipping',
                context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId]
            );
            return;
        }

        if ($extractionScope === 'objects' && $isOpenRegisterFile === false) {
            $this->logger->debug(
                message: '[FileChangeListener] Skipping non-OpenRegister file (scope: objects)',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'file_id'   => $fileId,
                    'file_path' => $filePath,
                ]
            );
            return;
        }

        $this->logger->info(
            message: '[FileChangeListener] File event detected - processing',
            context: [
                'file' => __FILE__,
                'line' => __LINE__,
                'event_type'       => get_class($event),
                'file_id'          => $fileId,
                'file_name'        => $fileName,
                'file_path'        => $filePath,
                'extraction_scope' => $extractionScope,
            ]
        );

        // Get extraction mode from settings to determine processing strategy.
        try {
            $extractionMode = $fileSettings['extractionMode'] ?? 'background';

            // Handle different extraction modes.
            switch ($extractionMode) {
                case 'immediate':
                    // Process synchronously during upload - direct link between file upload and parsing.
                    $this->logger->info(
                        message: '[FileChangeListener] Immediate mode - processing synchronously',
                        context: [
                            'file' => __FILE__,
                            'line' => __LINE__,
                            'file_id'   => $fileId,
                            'file_name' => $fileName,
                        ]
                    );
                    try {
                        $this->textExtractSvc->extractFile(fileId: $fileId, forceReExtract: false);
                        $this->logger->info(
                            message: '[FileChangeListener] Immediate extraction completed',
                            context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId]
                        );
                    } catch (\Exception $e) {
                        $this->logger->error(
                            message: '[FileChangeListener] Immediate extraction failed',
                            context: [
                                'file' => __FILE__,
                                'line' => __LINE__,
                                'file_id' => $fileId,
                                'error'   => $e->getMessage(),
                            ]
                        );
                    }
                    break;

                case 'background':
                    // Queue background job for delayed extraction on job stack.
                    $this->logger->info(
                        message: '[FileChangeListener] Background mode - queueing extraction job',
                        context: [
                            'file' => __FILE__,
                            'line' => __LINE__,
                            'file_id'   => $fileId,
                            'file_name' => $fileName,
                        ]
                    );
                    try {
                        $this->jobList->add(job: FileTextExtractionJob::class, argument: ['file_id' => $fileId]);
                        $this->logger->debug(
                            message: '[FileChangeListener] Background extraction job queued',
                            context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId]
                        );
                    } catch (\Exception $e) {
                        $this->logger->error(
                            message: '[FileChangeListener] Failed to queue background job',
                            context: [
                                'file' => __FILE__,
                                'line' => __LINE__,
                                'file_id' => $fileId,
                                'error'   => $e->getMessage(),
                            ]
                        );
                    }
                    break;

                case 'cron':
                    // Skip - cron job will handle periodic batch processing.
                    $this->logger->debug(
                        message: '[FileChangeListener] Cron mode - skipping, will be processed by scheduled job',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId]
                    );
                    break;

                case 'manual':
                    // Skip - only manual triggers will process.
                    $this->logger->debug(
                        message: '[FileChangeListener] Manual mode - skipping, requires manual trigger',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId]
                    );
                    break;

                default:
                    // Fallback to background mode for unknown modes.
                    $this->logger->warning(
                        message: '[FileChangeListener] Unknown extraction mode, defaulting to background',
                        context: [
                            'file' => __FILE__,
                            'line' => __LINE__,
                            'file_id'         => $fileId,
                            'extraction_mode' => $extractionMode,
                        ]
                    );
                    $this->jobList->add(job: FileTextExtractionJob::class, argument: ['file_id' => $fileId]);
                    break;
            }//end switch
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileChangeListener] Error determining extraction mode',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]
            );
        }//end try
    }//end handle()
}//end class
