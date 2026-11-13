<?php

/**
 * Fires object-lifecycle triggers into the flow engine.
 *
 * The object-lifecycle triggers: when an OpenRegister object is created,
 * updated, deleted, locked, unlocked, reverted or changes state, this hands the
 * event to {@see FlowTriggerServiceInterface}, which queues a run for every flow wired to
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
 * @spec openspec/specs/flow-triggers/spec.md
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
use OCA\OpenRegister\Service\Flow\FlowTriggerServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugsInterface;
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
	 * The trigger id each object event class is wired to, checked in this order.
	 *
	 * @var array<class-string<Event>, string>
	 */
	private const EVENT_IDS = [
		ObjectCreatedEvent::class => 'object.created',
		ObjectUpdatedEvent::class => 'object.updated',
		ObjectDeletedEvent::class => 'object.deleted',
		ObjectLockedEvent::class => 'object.locked',
		ObjectUnlockedEvent::class => 'object.unlocked',
		ObjectRevertedEvent::class => 'object.reverted',
		ObjectTransitionedEvent::class => 'object.transitioned',
	];

	/**
	 * Constructor.
	 *
	 * @param FlowTriggerServiceInterface $triggers Queues the runs.
	 * @param IUserSession $userSession The acting user, for attribution.
	 * @param FlowTriggerSlugsInterface $slugs Turns the object's numeric ids into the slugs triggers match on.
	 */
	public function __construct(
		private readonly FlowTriggerServiceInterface $triggers,
		private readonly IUserSession $userSession,
		private readonly FlowTriggerSlugsInterface $slugs,
	) {

	}//end __construct()

	/**
	 * Translate an object event into a trigger.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-triggers/spec.md
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

		$this->fireObjectTrigger(event: $event, eventId: $eventId);
	}//end handle()

	/**
	 * Fire an object event as its trigger, against the object's register and schema slugs.
	 *
	 * @param Event  $event   The object event.
	 * @param string $eventId The trigger id flows are wired to.
	 *
	 * @return void
	 */
	private function fireObjectTrigger(Event $event, string $eventId): void {
		$object = $event->getObject();
		$user = $this->callerUid();

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
	}//end fireObjectTrigger()

	/**
	 * The acting user's uid; null when nobody is signed in.
	 *
	 * @return string|null
	 */
	private function callerUid(): ?string {
		$user = null;
		if ($this->userSession->getUser() !== null) {
			$user = $this->userSession->getUser()->getUID();
		}

		return $user;
	}//end callerUid()

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
	 * @spec openspec/specs/flow-engine/spec.md
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
	 * put on the run context where the flow's conditions can read them.
	 *
	 * A deletion is the one lifecycle event whose SUBJECT CANNOT BE RE-RESOLVED.
	 * Every run — inline or queued — re-reads its subject by uuid before any
	 * node executes ({@see \OCA\OpenRegister\Service\Flow\FlowRunAdvancer::advance()}),
	 * and a deleted object is, by definition, never there to find: the delete
	 * already happened by the time ANY run reaches it, so a flow wired to
	 * `object.deleted` failed with "Subject ... no longer exists" on every
	 * firing, unconditionally — not a bug in the flow, a gap in what the trigger
	 * handed the run. The object's last-known state travels under `payload`
	 * instead, the same slot a subjectless trigger (a file, a user) already
	 * seeds its first item from, so the advancer's existing fallback picks it up
	 * with no new mechanism.
	 *
	 * Every other lifecycle event adds nothing beyond its subject — the object
	 * still exists, so a flow that needs more than its uuid already has a
	 * resolvable subject to read it from.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, mixed> The extra context, empty for most events.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function contextFor(Event $event): array {
		if ($event instanceof ObjectTransitionedEvent) {
			$automatic = 'false';
			if ($event->isAutomatic() === true) {
				$automatic = 'true';
			}

			return [
				'action' => $event->getAction(),
				'from' => $event->getFrom(),
				'to' => $event->getTo(),
				// A flow branching on a state change needs to know whether a
				// person asked for the move or a rule made it: "notify the
				// applicant" is right for one and wrong for the other.
				'automatic' => $automatic,
			];
		}

		if ($event instanceof ObjectDeletedEvent) {
			return ['payload' => $event->getObject()->jsonSerialize()];
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return ['changedFields' => $this->changedFields(event: $event)];
		}

		return [];
	}//end contextFor()

	/**
	 * The top-level properties whose values differ between the old and new object.
	 *
	 * `@self` metadata is left out. Without the old object nothing proves a
	 * field changed, so the list is empty and a watching trigger does not start.
	 *
	 * @param ObjectUpdatedEvent $event The update.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md#requirement-an-update-trigger-can-watch-named-fields
	 */
	private function changedFields(ObjectUpdatedEvent $event): array {
		$old = $event->getOldObject();
		if ($old === null) {
			return [];
		}

		$before = ($old->getObject() ?? []);
		$after = ($event->getNewObject()->getObject() ?? []);
		$changed = [];
		foreach (array_keys($before + $after) as $key) {
			if ((string)$key === '@self') {
				continue;
			}

			if (array_key_exists($key, $before) !== array_key_exists($key, $after) || ($before[$key] ?? null) !== ($after[$key] ?? null)) {
				$changed[] = (string)$key;
			}
		}

		return $changed;
	}//end changedFields()

	/**
	 * Map an event class to the trigger id flows are wired to.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return string|null The trigger id, or null when this is not an object event.
	 *
	 * @spec openspec/specs/flow-triggers/spec.md
	 */
	private function eventIdFor(Event $event): ?string {
		foreach (self::EVENT_IDS as $class => $eventId) {
			if ($event instanceof $class) {
				return $eventId;
			}
		}

		return null;
	}//end eventIdFor()
}//end class
