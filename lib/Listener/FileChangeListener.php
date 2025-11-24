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
 * @license  AGPL-3.0-or-later
 *
 * @template-implements IEventListener<NodeCreatedEvent|NodeWrittenEvent>
 */
class FileChangeListener implements IEventListener
{


    /**
     * Constructor
     *
     * @param TextExtractionService $textExtractionService Text extraction service
     * @param IJobList        $jobList         Job list for queuing background jobs
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly TextExtractionService $textExtractionService,
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

        // Only process OpenRegister files to avoid unnecessary processing.
        // OpenRegister files are stored in paths containing 'OpenRegister/files'.
        if (strpos($filePath, 'OpenRegister/files') === false
            && strpos($filePath, '/Open Registers/') === false
        ) {
            $this->logger->debug(
                    '[FileChangeListener] Skipping non-OpenRegister file',
                    [
                        'file_id'   => $fileId,
                        'file_path' => $filePath,
                    ]
                    );
            return;
        }

        $this->logger->debug(
                '[FileChangeListener] File event detected',
                [
                    'event_type' => get_class($event),
                    'file_id'    => $fileId,
                    'file_name'  => $fileName,
                    'file_path'  => $filePath,
                ]
                );

        // Queue background job for text extraction (non-blocking).
        try {
            $this->logger->info(
                    '[FileChangeListener] Queueing text extraction job',
                    [
                        'file_id'   => $fileId,
                        'file_name' => $fileName,
                    ]
                    );

            // Queue the background job with file_id as argument.
            // The job will run asynchronously without blocking this request.
            // TextExtractionService will check internally if extraction is needed.
            $this->jobList->add(FileTextExtractionJob::class, ['file_id' => $fileId]);

            $this->logger->debug(
                    '[FileChangeListener] Text extraction job queued successfully',
                    [
                        'file_id' => $fileId,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[FileChangeListener] Failed to queue text extraction job',
                    [
                        'file_id' => $fileId,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ]
                    );
        }//end try

    }//end handle()


}//end class
