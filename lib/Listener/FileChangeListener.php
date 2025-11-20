<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCA\OpenRegister\Service\FileTextService;
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
     * @param FileTextService $fileTextService File text service for checking extraction needs
     * @param IJobList        $jobList         Job list for queuing background jobs
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly FileTextService $fileTextService,
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
        // Only handle NodeCreatedEvent and NodeWrittenEvent
        if (!$event instanceof NodeCreatedEvent && !$event instanceof NodeWrittenEvent) {
            return;
        }

        $node = $event->getNode();

        // Only process files, not folders
        if (!$node instanceof File) {
            return;
        }

        $fileId   = $node->getId();
        $fileName = $node->getName();
        $filePath = $node->getPath();

        // Only process OpenRegister files to avoid unnecessary processing
        // OpenRegister files are stored in paths containing 'OpenRegister/files'
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

        // Queue background job for text extraction (non-blocking)
        try {
            // Check if extraction is needed (to avoid unnecessary background jobs)
            if ($this->fileTextService->needsExtraction($fileId)) {
                $this->logger->info(
                        '[FileChangeListener] Queueing text extraction job',
                        [
                            'file_id'   => $fileId,
                            'file_name' => $fileName,
                        ]
                        );

                // Queue the background job with file_id as argument
                // The job will run asynchronously without blocking this request
                $this->jobList->add(FileTextExtractionJob::class, ['file_id' => $fileId]);

                $this->logger->debug(
                        '[FileChangeListener] Text extraction job queued successfully',
                        [
                            'file_id' => $fileId,
                        ]
                        );
            } else {
                $this->logger->debug(
                        '[FileChangeListener] Extraction not needed, skipping job queue',
                        [
                            'file_id' => $fileId,
                        ]
                        );
            }//end if
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
