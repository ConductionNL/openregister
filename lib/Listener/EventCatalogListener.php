<?php

/**
 * OpenRegister EventCatalogListener
 *
 * Routes the non-CRUD object-lifecycle events in the flow event catalog
 * (locked / unlocked / reverted / transitioned) to the declarative flow runner,
 * so a flow may trigger on more than create/update/delete. The plain
 * create/update/delete events stay with {@see FlowActionListener} to avoid
 * double-firing; this listener covers only the additional catalog events.
 *
 * Each handled event carries an {@see ObjectEntity} (via `getObject()`), so the
 * object's schema selects which flows run — exactly like the CRUD path. The
 * catalog trigger id passed to the runner (`object.locked`, `object.transitioned`,
 * …) is the same id the visual builder stored, closing the author→fire loop.
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
 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Service\Flow\FlowActionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Routes catalog events beyond create/update/delete to the flow runner.
 *
 * @template-implements IEventListener<Event>
 */
class EventCatalogListener implements IEventListener
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
     * Map a catalog event to its trigger id and run the object's flows.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectLockedEvent) {
            $this->dispatch(object: $event->getObject(), trigger: 'object.locked');
            return;
        }

        if ($event instanceof ObjectUnlockedEvent) {
            $this->dispatch(object: $event->getObject(), trigger: 'object.unlocked');
            return;
        }

        if ($event instanceof ObjectRevertedEvent) {
            $this->dispatch(object: $event->getObject(), trigger: 'object.reverted');
            return;
        }

        if ($event instanceof ObjectTransitionedEvent) {
            $this->dispatch(object: $event->getObject(), trigger: 'object.transitioned');
            return;
        }
    }//end handle()

    /**
     * Run flows for an object, guarding against a null payload.
     *
     * @param ObjectEntity|null $object  The object the event carried.
     * @param string            $trigger The catalog trigger id.
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
