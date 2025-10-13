<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\FileTextService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * FileChangeListener
 * 
 * Listens for file creation and update events to automatically extract text.
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
     * @param FileTextService $fileTextService File text service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly FileTextService $fileTextService,
        private readonly LoggerInterface $logger
    ) {
    }

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

        $fileId = $node->getId();
        $fileName = $node->getName();

        $this->logger->debug('[FileChangeListener] File event detected', [
            'event_type' => get_class($event),
            'file_id' => $fileId,
            'file_name' => $fileName,
        ]);

        // Process file extraction asynchronously to avoid blocking the request
        try {
            // Check if extraction is needed (to avoid unnecessary processing)
            if ($this->fileTextService->needsExtraction($fileId)) {
                $this->logger->info('[FileChangeListener] Triggering text extraction', [
                    'file_id' => $fileId,
                    'file_name' => $fileName,
                ]);

                // Extract and store text
                $result = $this->fileTextService->extractAndStoreFileText($fileId);

                if ($result['success']) {
                    $this->logger->info('[FileChangeListener] Text extraction successful', [
                        'file_id' => $fileId,
                        'text_length' => $result['fileText']?->getTextLength() ?? 0,
                    ]);
                } else {
                    $this->logger->warning('[FileChangeListener] Text extraction failed', [
                        'file_id' => $fileId,
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);
                }
            } else {
                $this->logger->debug('[FileChangeListener] Extraction not needed', [
                    'file_id' => $fileId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[FileChangeListener] Exception during text extraction', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

