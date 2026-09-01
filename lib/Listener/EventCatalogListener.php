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
use OCA\OpenRegister\Event\CaseItemTransitionedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Routes every catalog event to the flow trigger service.
 *
 * This is now the ONE path from a dispatched object event to a queued run.
 * Create/update/delete used to be handled separately by the action-list
 * engine's own listener, which meant two independent notions of "a flow fired"
 * that could — and did — disagree about which flows were wired to an event.
 *
 * @template-implements IEventListener<Event>
 */
class EventCatalogListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param FlowTriggerService $triggers Queues an engine run per wired flow.
	 */
	public function __construct(
		private readonly FlowTriggerService $triggers,
	) {
	}//end __construct()

	/**
	 * Map a catalog event to its trigger id and run the object's flows.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) A dispatch table: one
	 * instanceof branch per catalog event, each a one-liner; splitting it
	 * would spread the catalog over several methods.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Same cause.
	 */
	public function handle(Event $event): void {
		// A plan item reaching a terminal state (flow-cmmn-case-semantics) fires
		// its catalog trigger against the ANCHORING object, the same shape as
		// every object event below; no separate subject type is introduced.
		if ($event instanceof CaseItemTransitionedEvent) {
			$trigger = $event->getCatalogTrigger();
			if ($trigger !== null) {
				$this->triggers->fire(event: $trigger, subject: $event->getSubject());
			}

			return;
		}

		if ($event instanceof ObjectCreatedEvent) {
			$this->dispatch(object: $event->getObject(), trigger: 'object.created');
			return;
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$this->dispatch(object: $event->getNewObject(), trigger: 'object.updated');
			return;
		}

		if ($event instanceof ObjectDeletedEvent) {
			$this->dispatch(object: $event->getObject(), trigger: 'object.deleted');
			return;
		}

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
	 * @param ObjectEntity|null $object The object the event carried.
	 * @param string $trigger The catalog trigger id.
	 *
	 * @return void
	 */
	private function dispatch(?ObjectEntity $object, string $trigger): void {
		if ($object === null) {
			return;
		}

		// Queue an engine run per wired flow, rather than executing an action
		// list inline. A catalog event fires inside somebody's save, so the
		// trigger records intent and returns; the worker does the walking.
		$this->triggers->fire(
			event: $trigger,
			subject: [
				'uuid' => $object->getUuid(),
				'register' => (string)$object->getRegister(),
				'schema' => (string)$object->getSchema(),
			]
		);
	}//end dispatch()
}//end class
