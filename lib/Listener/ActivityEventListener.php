<?php

/**
 * OpenRegister ActivityEventListener.
 *
 * Listens to OpenRegister entity events and publishes corresponding activity events.
 *
<<<<<<< HEAD
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
=======
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
<<<<<<< HEAD
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-26
=======
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-26
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
<<<<<<< HEAD
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-26
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-26
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
