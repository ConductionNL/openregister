<?php

/**
 * OpenRegister FlowActionListener
 *
 * Listener that runs declarative schema flows on object-lifecycle events.
 *
 * Bridges the OpenRegister object-lifecycle events to the FlowActionService,
 * which reads `x-openregister-flows` from the object's schema and executes the
 * declared actions (e.g. create a calendar/agenda task, send an email) when the
 * trigger matches. Mirrors AnnotationNotificationListener.
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Flow\FlowActionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener that fires declarative schema flows on object-lifecycle events.
 *
 * @template-implements IEventListener<Event>
 */
class FlowActionListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param FlowActionService $flowActionService The flow runner.
     */
    public function __construct(
        private readonly FlowActionService $flowActionService
    ) {
    }//end __construct()

    /**
     * Route a lifecycle event to the flow runner with the matching trigger.
     *
     * @param Event $event The dispatched lifecycle event.
     *
     * @return void
     *
     * @spec exclude declarative-flow engine ships without a formal openspec change; spec to be added in a follow-up ADR
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent) {
            $this->dispatch(object: $event->getObject(), trigger: 'created');
            return;
        }

        if ($event instanceof ObjectUpdatedEvent) {
            $this->dispatch(object: $event->getNewObject(), trigger: 'updated');
            return;
        }

        if ($event instanceof ObjectDeletedEvent) {
            $this->dispatch(object: $event->getObject(), trigger: 'deleted');
            return;
        }
    }//end handle()

    /**
     * Run flows for an object, guarding against a null payload.
     *
     * @param ObjectEntity|null $object  The object the event carried.
     * @param string            $trigger The lifecycle trigger.
     *
     * @return void
     */
    private function dispatch(?ObjectEntity $object, string $trigger): void
    {
        if ($object === null) {
            return;
        }

        $this->flowActionService->run(object: $object, trigger: $trigger);
    }//end dispatch()
}//end class
