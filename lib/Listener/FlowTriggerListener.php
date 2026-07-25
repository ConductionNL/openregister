<?php

/**
 * Fires object-lifecycle triggers into the flow engine.
 *
 * The first Nextcloud-native trigger: when an OpenRegister object is created,
 * updated or deleted, this hands the event to {@see FlowTriggerService}, which
 * queues a run for every flow wired to it. Other native triggers (files,
 * shares, calendar, users, tags) will register the same way — a small listener
 * that translates a core event into a `FlowTriggerService::fire()` call — so the
 * mechanism is here once and each new trigger is a few lines.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Queues flow runs on object create / update / delete.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent>
 */
class FlowTriggerListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param FlowTriggerService $triggers    Queues the runs.
     * @param IUserSession       $userSession The acting user, for attribution.
     */
    public function __construct(
        private readonly FlowTriggerService $triggers,
        private readonly IUserSession $userSession
    ) {

    }//end __construct()

    /**
     * Translate an object event into a trigger.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    public function handle(Event $event): void
    {
        $eventId = $this->eventIdFor(event: $event);
        if ($eventId === null) {
            return;
        }

        $object = $event->getObject();
        $user   = null;
        if ($this->userSession->getUser() !== null) {
            $user = $this->userSession->getUser()->getUID();
        }

        $this->triggers->fire(
            event: $eventId,
            subject: [
                'uuid'     => (string) $object->getUuid(),
                'register' => (string) $object->getRegister(),
                'schema'   => (string) $object->getSchema(),
            ],
            user: $user
        );

    }//end handle()

    /**
     * Map an event class to the trigger id flows are wired to.
     *
     * @param Event $event The dispatched event.
     *
     * @return string|null The trigger id, or null when this is not an object event.
     *
     * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
     */
    private function eventIdFor(Event $event): ?string
    {
        if ($event instanceof ObjectCreatedEvent) {
            return 'object.created';
        }

        if ($event instanceof ObjectUpdatedEvent) {
            return 'object.updated';
        }

        if ($event instanceof ObjectDeletedEvent) {
            return 'object.deleted';
        }

        return null;

    }//end eventIdFor()
}//end class
