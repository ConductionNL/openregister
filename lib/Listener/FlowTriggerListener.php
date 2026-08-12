<?php

/**
 * Fires object-lifecycle triggers into the flow engine.
 *
 * The object-lifecycle triggers: when an OpenRegister object is created,
 * updated, deleted, locked, unlocked, reverted or changes state, this hands the
 * event to {@see FlowTriggerService}, which queues a run for every flow wired to
 * it. Each is one line in the event map here; the mechanism is written once.
 *
 * The lock/revert/state triggers were declared in the event catalog from the
 * start but had no listener firing them — a flow could select "an object is
 * locked" and it would never run. Wiring them here closes that gap so every
 * catalog trigger a flow can pick is one the engine actually fires.
 *
 * Non-object native triggers (files, shares, calendar, users, tags) carry a
 * subject that is not an OpenRegister object, so they need a run-seed model this
 * object-centric listener does not have; they are a separate change.
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
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Queues flow runs on every object-lifecycle event.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectLockedEvent|ObjectUnlockedEvent|ObjectRevertedEvent|ObjectTransitionedEvent>
 */
class FlowTriggerListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param FlowTriggerService $triggers Queues the runs.
	 * @param IUserSession $userSession The acting user, for attribution.
	 */
	public function __construct(
		private readonly FlowTriggerService $triggers,
		private readonly IUserSession $userSession,
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
	public function handle(Event $event): void {
		$eventId = $this->eventIdFor(event: $event);
		if ($eventId === null) {
			return;
		}

		$object = $event->getObject();
		$user = null;
		if ($this->userSession->getUser() !== null) {
			$user = $this->userSession->getUser()->getUID();
		}

		$this->triggers->fire(
			event: $eventId,
			subject: [
				'uuid' => (string)$object->getUuid(),
				'register' => (string)$object->getRegister(),
				'schema' => (string)$object->getSchema(),
			],
			user: $user,
			context: $this->contextFor(event: $event)
		);

	}//end handle()

	/**
	 * Extra run context an event carries beyond the object it is about.
	 *
	 * A state change is the one lifecycle event that is *about* a change rather
	 * than a moment: which action ran and the places it moved between are what a
	 * flow wired to "an object changes state" needs to branch on, so they are
	 * put on the run context where the flow's conditions can read them. Every
	 * other lifecycle event adds nothing beyond its subject.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, string> The extra context, empty for most events.
	 */
	private function contextFor(Event $event): array {
		if ($event instanceof ObjectTransitionedEvent) {
			return [
				'action' => $event->getAction(),
				'from' => $event->getFrom(),
				'to' => $event->getTo(),
			];
		}

		return [];
	}//end contextFor()

	/**
	 * Map an event class to the trigger id flows are wired to.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return string|null The trigger id, or null when this is not an object event.
	 *
	 * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
	 */
	private function eventIdFor(Event $event): ?string {
		if ($event instanceof ObjectCreatedEvent) {
			return 'object.created';
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return 'object.updated';
		}

		if ($event instanceof ObjectDeletedEvent) {
			return 'object.deleted';
		}

		if ($event instanceof ObjectLockedEvent) {
			return 'object.locked';
		}

		if ($event instanceof ObjectUnlockedEvent) {
			return 'object.unlocked';
		}

		if ($event instanceof ObjectRevertedEvent) {
			return 'object.reverted';
		}

		if ($event instanceof ObjectTransitionedEvent) {
			return 'object.transitioned';
		}

		return null;
	}//end eventIdFor()
}//end class
