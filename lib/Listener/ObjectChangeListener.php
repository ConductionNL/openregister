<?php

/**
 * OpenRegister Object Change Listener
 *
 * Listens for object creation and update events to queue asynchronous text extraction.
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

use OCA\OpenRegister\BackgroundJob\ObjectTextExtractionJob;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * ObjectChangeListener
 *
 * Listens for object creation and update events to queue asynchronous text extraction.
 * Instead of processing objects synchronously (which would block user requests),
 * this listener queues a background job for each object that needs processing.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent>
 */
class ObjectChangeListener implements IEventListener
{


    /**
     * Constructor
     *
     * @param TextExtractionService $textExtractionService Text extraction service
     * @param SettingsService       $settingsService       Settings service
     * @param IJobList              $jobList               Job list for queuing background jobs
     * @param LoggerInterface       $logger                Logger
     *
     * @psalm-suppress UnusedProperty - Properties are used in handle() method
     */
    public function __construct(
        private readonly TextExtractionService $textExtractionService,
        private readonly SettingsService $settingsService,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Handle object events
     *
     * @param Event $event Object event
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        // Only handle ObjectCreatedEvent and ObjectUpdatedEvent.
        if (($event instanceof ObjectCreatedEvent) === false
            && ($event instanceof ObjectUpdatedEvent) === false
        ) {
            return;
        }

        $object = $event->getObject();
        $objectId = $object->getId();

        $this->logger->debug(
                '[ObjectChangeListener] Object event detected',
                [
                    'event_type' => get_class($event),
                    'object_id'   => $objectId,
                    'object_uuid' => $object->getUuid(),
                ]
                );

        // Get extraction mode from settings to determine processing strategy.
        try {
            $objectSettings = $this->settingsService->getObjectSettingsOnly();
            $extractionMode = $objectSettings['objectExtractionMode'] ?? 'background';

            // Handle different extraction modes.
            switch ($extractionMode) {
                case 'immediate':
                    // Process synchronously during object creation/update - direct link between object save and parsing.
                    $this->logger->info(
                            '[ObjectChangeListener] Immediate mode - processing synchronously',
                            [
                                'object_id'   => $objectId,
                                'object_uuid' => $object->getUuid(),
                            ]
                            );
                    try {
                        $this->textExtractionService->extractObject($objectId, false);
                        $this->logger->info(
                                '[ObjectChangeListener] Immediate extraction completed',
                                ['object_id' => $objectId]
                                );
                    } catch (\Exception $e) {
                        $this->logger->error(
                                '[ObjectChangeListener] Immediate extraction failed',
                                [
                                    'object_id' => $objectId,
                                    'error'     => $e->getMessage(),
                                ]
                                );
                    }
                    break;

                case 'background':
                    // Queue background job for delayed extraction on job stack.
                    $this->logger->info(
                            '[ObjectChangeListener] Background mode - queueing extraction job',
                            [
                                'object_id'   => $objectId,
                                'object_uuid' => $object->getUuid(),
                            ]
                            );
                    try {
                        $this->jobList->add(ObjectTextExtractionJob::class, ['object_id' => $objectId]);
                        $this->logger->debug(
                                '[ObjectChangeListener] Background extraction job queued',
                                ['object_id' => $objectId]
                                );
                    } catch (\Exception $e) {
                        $this->logger->error(
                                '[ObjectChangeListener] Failed to queue background job',
                                [
                                    'object_id' => $objectId,
                                    'error'     => $e->getMessage(),
                                ]
                                );
                    }
                    break;

                case 'cron':
                    // Skip - cron job will handle periodic batch processing.
                    $this->logger->debug(
                            '[ObjectChangeListener] Cron mode - skipping, will be processed by scheduled job',
                            ['object_id' => $objectId]
                            );
                    break;

                case 'manual':
                    // Skip - only manual triggers will process.
                    $this->logger->debug(
                            '[ObjectChangeListener] Manual mode - skipping, requires manual trigger',
                            ['object_id' => $objectId]
                            );
                    break;

                default:
                    // Fallback to background mode for unknown modes.
                    $this->logger->warning(
                            '[ObjectChangeListener] Unknown extraction mode, defaulting to background',
                            [
                                'object_id'        => $objectId,
                                'extraction_mode' => $extractionMode,
                            ]
                            );
                    $this->jobList->add(ObjectTextExtractionJob::class, ['object_id' => $objectId]);
                    break;
            }//end switch
        } catch (\Exception $e) {
            $this->logger->error(
                    '[ObjectChangeListener] Error determining extraction mode',
                    [
                        'object_id' => $objectId,
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );
        }//end try

    }//end handle()


}//end class


