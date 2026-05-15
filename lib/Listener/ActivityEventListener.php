<?php

/**
 * OpenRegister ActivityEventListener.
 *
 * Listens to OpenRegister entity events and publishes corresponding activity events.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-26
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\ActivityService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Event listener that bridges OpenRegister entity events to Nextcloud Activity.
 *
 * @implements IEventListener<Event>
 */
class ActivityEventListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param ActivityService $activityService The activity publishing service.
     */
    public function __construct(
        private ActivityService $activityService,
    ) {
    }//end __construct()

    /**
     * Handle an incoming event and delegate to the appropriate ActivityService method.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-26
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent) {
            $this->activityService->publishObjectCreated($event->getObject());
            return;
        }

        if ($event instanceof ObjectUpdatedEvent) {
            $this->activityService->publishObjectUpdated(
                $event->getNewObject(),
                $event->getOldObject()
            );
            return;
        }

        if ($event instanceof ObjectDeletedEvent) {
            $this->activityService->publishObjectDeleted($event->getObject());
            return;
        }

        if ($event instanceof RegisterCreatedEvent) {
            $this->activityService->publishRegisterCreated($event->getRegister());
            return;
        }

        if ($event instanceof RegisterUpdatedEvent) {
            $this->activityService->publishRegisterUpdated($event->getNewRegister());
            return;
        }

        if ($event instanceof RegisterDeletedEvent) {
            $this->activityService->publishRegisterDeleted($event->getRegister());
            return;
        }

        if ($event instanceof SchemaCreatedEvent) {
            $this->activityService->publishSchemaCreated($event->getSchema());
            return;
        }

        if ($event instanceof SchemaUpdatedEvent) {
            $this->activityService->publishSchemaUpdated($event->getNewSchema());
            return;
        }

        if ($event instanceof SchemaDeletedEvent) {
            $this->activityService->publishSchemaDeleted($event->getSchema());
        }
    }//end handle()
}//end class
