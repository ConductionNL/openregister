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
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
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

        $object   = $event->getObject();
        $objectId = $object->getId();

        $this->logger->debug(
            '[ObjectChangeListener] Object event detected',
            [
                'event_type'  => get_class($event),
                'object_id'   => $objectId,
                'object_uuid' => $object->getUuid(),
            ]
        );

        // Get extraction mode and process accordingly.
        try {
            $objectSettings = $this->settingsService->getObjectSettingsOnly();
            $extractionMode = $objectSettings['objectExtractionMode'] ?? 'background';

            $this->processExtractionMode(mode: $extractionMode, objectId: $objectId, objectUuid: $object->getUuid());
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

    /**
     * Process extraction based on configured mode
     *
     * @param string $mode       Extraction mode (immediate, background, cron, manual).
     * @param int    $objectId   Object ID to process.
     * @param string $objectUuid Object UUID for logging.
     *
     * @return void
     */
    private function processExtractionMode(string $mode, int $objectId, string $objectUuid): void
    {
        switch ($mode) {
            case 'immediate':
                $this->processImmediateExtraction(objectId: $objectId, objectUuid: $objectUuid);
                break;

            case 'background':
                $this->processBackgroundExtraction(objectId: $objectId, objectUuid: $objectUuid);
                break;

            case 'cron':
                $this->processCronMode($objectId);
                break;

            case 'manual':
                $this->processManualMode($objectId);
                break;

            default:
                $this->processUnknownMode(mode: $mode, objectId: $objectId);
                break;
        }//end switch
    }//end processExtractionMode()

    /**
     * Process immediate synchronous extraction
     *
     * @param int    $objectId   Object ID to extract.
     * @param string $objectUuid Object UUID for logging.
     *
     * @return void
     */
    private function processImmediateExtraction(int $objectId, string $objectUuid): void
    {
        $this->logger->info(
            '[ObjectChangeListener] Immediate mode - processing synchronously',
            [
                'object_id'   => $objectId,
                'object_uuid' => $objectUuid,
            ]
        );

        try {
            $this->textExtractionService->extractObject(objectId: $objectId, forceReExtract: false);
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
    }//end processImmediateExtraction()

    /**
     * Queue background job for extraction
     *
     * @param int    $objectId   Object ID to extract.
     * @param string $objectUuid Object UUID for logging.
     *
     * @return void
     */
    private function processBackgroundExtraction(int $objectId, string $objectUuid): void
    {
        $this->logger->info(
            '[ObjectChangeListener] Background mode - queueing extraction job',
            [
                'object_id'   => $objectId,
                'object_uuid' => $objectUuid,
            ]
        );

        try {
            $this->jobList->add(job: ObjectTextExtractionJob::class, argument: ['object_id' => $objectId]);
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
    }//end processBackgroundExtraction()

    /**
     * Handle cron mode (skip processing)
     *
     * @param int $objectId Object ID.
     *
     * @return void
     */
    private function processCronMode(int $objectId): void
    {
        $this->logger->debug(
            '[ObjectChangeListener] Cron mode - skipping, will be processed by scheduled job',
            ['object_id' => $objectId]
        );
    }//end processCronMode()

    /**
     * Handle manual mode (skip processing)
     *
     * @param int $objectId Object ID.
     *
     * @return void
     */
    private function processManualMode(int $objectId): void
    {
        $this->logger->debug(
            '[ObjectChangeListener] Manual mode - skipping, requires manual trigger',
            ['object_id' => $objectId]
        );
    }//end processManualMode()

    /**
     * Handle unknown extraction mode (fallback to background)
     *
     * @param string $mode     Unknown mode name.
     * @param int    $objectId Object ID.
     *
     * @return void
     */
    private function processUnknownMode(string $mode, int $objectId): void
    {
        $this->logger->warning(
            '[ObjectChangeListener] Unknown extraction mode, defaulting to background',
            [
                'object_id'       => $objectId,
                'extraction_mode' => $mode,
            ]
        );

        $this->jobList->add(job: ObjectTextExtractionJob::class, argument: ['object_id' => $objectId]);
    }//end processUnknownMode()
}//end class
