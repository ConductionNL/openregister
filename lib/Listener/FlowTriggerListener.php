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

use OCA\OpenRegister\Event\CaseItemTransitionedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugs;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Queues flow runs on every object-lifecycle event.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectLockedEvent|ObjectUnlockedEvent|ObjectRevertedEvent|ObjectTransitionedEvent|CaseItemTransitionedEvent>
 */
class FlowTriggerListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param FlowTriggerService $triggers Queues the runs.
	 * @param IUserSession $userSession The acting user, for attribution.
	 * @param FlowTriggerSlugs $slugs Turns the object's numeric ids into the slugs triggers match on.
	 */
	public function __construct(
		private readonly FlowTriggerService $triggers,
		private readonly IUserSession $userSession,
		private readonly FlowTriggerSlugs $slugs,
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
		// A plan item reaching a terminal state (flow-cmmn-case-semantics)
		// fires its catalog trigger against the ANCHORING object — the same
		// subject shape as every object event below, so it goes through the
		// same seam: the item's numeric register/schema ids are resolved to
		// the slugs the trigger index stores, and the acting user rides along.
		// A non-terminal transition names no catalog trigger and fires nothing.
		if ($event instanceof CaseItemTransitionedEvent) {
			$this->fireCaseItemTrigger(event: $event);
			return;
		}

		$eventId = $this->eventIdFor(event: $event);
		if ($eventId === null) {
			return;
		}

		$object = $event->getObject();
		$user = null;
		if ($this->userSession->getUser() !== null) {
			$user = $this->userSession->getUser()->getUID();
		}

		// 🔴 SLUGS, NOT THE OBJECT'S NUMERIC IDS. The trigger index and the
		// flow trigger columns both hold slugs (`dossiq`/`case`) — an imported
		// `x-openregister-flows` declaration cannot know an instance's row ids
		// — while `$object->getRegister()` answers `16`. Firing the ids meant
		// the comparison was `16 === 'dossiq'` on every event: three case
		// creations on a clean instance queued NOTHING, with the flow enabled
		// and owned, and nothing logged. Measured 2026-09-01 on dossiq
		// 0.3.11-unstable / openregister 2.0.13-unstable.
		$this->triggers->fire(
			event: $eventId,
			subject: [
				'uuid' => (string)$object->getUuid(),
				'register' => $this->slugs->registerSlug(identifier: (string)$object->getRegister()),
				'schema' => $this->slugs->schemaSlug(identifier: (string)$object->getSchema()),
			],
			user: $user,
			context: $this->contextFor(event: $event)
		);

	}//end handle()

	/**
	 * Fire a terminal plan-item transition as its catalog trigger.
	 *
	 * Lived in `EventCatalogListener` for the days between the case layer
	 * landing and that listener's retirement as a duplicate trigger path.
	 * Moving here rather than surviving there is what the retirement MEANS:
	 * one listener decides what a fired subject looks like. This branch also
	 * gains the two things the duplicate path dropped — the id-to-slug
	 * resolution without which a case trigger never matches an imported
	 * flow's index rows, and the acting user on the run.
	 *
	 * @param CaseItemTransitionedEvent $event The transition, already persisted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-trigger-canonical-slugs/specs/flow-engine/spec.md
	 */
	private function fireCaseItemTrigger(CaseItemTransitionedEvent $event): void {
		$trigger = $event->getCatalogTrigger();
		if ($trigger === null) {
			return;
		}

		$subject = $event->getSubject();
		$user = $this->userSession->getUser()?->getUID();

		$this->triggers->fire(
			event: $trigger,
			subject: [
				'uuid' => (string)($subject['uuid'] ?? ''),
				'register' => $this->slugs->registerSlug(identifier: (string)($subject['register'] ?? '')),
				'schema' => $this->slugs->schemaSlug(identifier: (string)($subject['schema'] ?? '')),
			],
			user: $user
		);

	}//end fireCaseItemTrigger()

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
