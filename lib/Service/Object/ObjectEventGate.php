<?php

/**
 * The one place that decides which event an object's save or delete dispatches (decision 227).
 *
 * A draft is the destination object in status `draft`: it may be half typed
 * and nobody but its owner knows it. Everything that reacts to a new object
 * (flows, webhooks, activity, push, metrics, notifications, search) must
 * wait until it is real. So:
 *
 * - a draft's first save, its later saves and its delete dispatch
 *   DraftCreatedEvent, DraftUpdatedEvent and DraftDeletedEvent, which only the
 *   listeners that must see a draft receive;
 * - the save that leaves draft dispatches DraftUpdatedEvent too, and no
 *   ObjectUpdatedEvent: leaving draft is a creation, not a change;
 * - leaving draft then dispatches ObjectCreatedEvent once, followed by
 *   ObjectActivatedEvent with the moment of receipt;
 * - every other object dispatches the events it always did.
 *
 * A listener on ObjectCreatedEvent therefore never receives a draft and
 * receives every object exactly once, when it exists for its readers. No
 * listener checks a status, and no app that listens to OpenRegister's events
 * has to know that drafts exist.
 *
 * Nothing else may create these events: ObjectEventRuleTest fails when a
 * class outside this one does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\DraftCreatedEvent;
use OCA\OpenRegister\Event\DraftDeletedEvent;
use OCA\OpenRegister\Event\DraftUpdatedEvent;
use OCA\OpenRegister\Event\ObjectActivatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Dispatches the event that fits the object's status.
 *
 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
 */
class ObjectEventGate {

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $events Nextcloud's dispatcher.
	 */
	public function __construct(
		private readonly IEventDispatcher $events,
	) {

	}//end __construct()

	/**
	 * An object was stored for the first time.
	 *
	 * @param ObjectEntity $object The stored object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	public function created(ObjectEntity $object): void {
		if ($object->isDraft() === true) {
			$this->events->dispatchTyped(new DraftCreatedEvent(object: $object));

			return;
		}

		$this->events->dispatchTyped(new ObjectCreatedEvent(object: $object));
	}//end created()

	/**
	 * Whether a write that stores this object for the first time is held from creation work: it is a draft.
	 * For a listener that runs BEFORE the write and does creation work there
	 * (issuing a number). It asks here, so before a write and after it the
	 * same class decides what a creation is.
	 * @param ObjectEntity $object The object about to be stored.
	 * @return bool True for a draft.
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-a-generated-identifier-must-be-issued-when-the-object-leaves-draft
	 */
	public function holdsCreation(ObjectEntity $object): bool {
		return $object->isDraft() === true;
	}//end holdsCreation()

	/**
	 * Whether a save takes a stored draft out of draft: the moment its held creation work runs.
	 * @param ObjectEntity      $new The object as it is about to be stored.
	 * @param ObjectEntity|null $old The object as stored, when the caller knows it.
	 * @return bool True when the stored object is a draft and the new one is not.
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-a-generated-identifier-must-be-issued-when-the-object-leaves-draft
	 */
	public function leavesDraft(ObjectEntity $new, ?ObjectEntity $old): bool {
		return $old !== null && $old->isDraft() === true && $new->isDraft() === false;
	}//end leavesDraft()

	/**
	 * A stored object was saved again.
	 *
	 * @param ObjectEntity      $new The object as stored now.
	 * @param ObjectEntity|null $old The object as it was, when the caller knows it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-a-drafts-updates-and-delete-must-stay-on-the-draft-path
	 */
	public function updated(ObjectEntity $new, ?ObjectEntity $old = null): void {
		// Still a draft, or the save that just left draft. The second is
		// announced as a creation by leftDraft(), not as a change here.
		if ($new->isDraft() === true || $old?->isDraft() === true) {
			$this->events->dispatchTyped(new DraftUpdatedEvent(newObject: $new, oldObject: $old));

			return;
		}

		$this->events->dispatchTyped(new ObjectUpdatedEvent(newObject: $new, oldObject: $old));
	}//end updated()

	/**
	 * A stored object was removed.
	 *
	 * @param ObjectEntity $object The removed object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-a-drafts-updates-and-delete-must-stay-on-the-draft-path
	 */
	public function deleted(ObjectEntity $object): void {
		if ($object->isDraft() === true) {
			$this->events->dispatchTyped(new DraftDeletedEvent(object: $object));

			return;
		}

		$this->events->dispatchTyped(new ObjectDeletedEvent(object: $object));
	}//end deleted()

	/**
	 * A draft left draft: it now exists for its readers.
	 *
	 * Creation side effects run here, once: ObjectCreatedEvent first, then
	 * ObjectActivatedEvent with the moment of receipt. Called by the two
	 * paths that leave draft (the form submit and the owner's activation),
	 * after the whole write succeeded, so a refused submit announces nothing.
	 *
	 * @param ObjectEntity      $object     The object, now `active`.
	 * @param DateTimeImmutable $receivedAt The moment it left draft.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	public function leftDraft(ObjectEntity $object, DateTimeImmutable $receivedAt): void {
		$this->events->dispatchTyped(new ObjectCreatedEvent(object: $object));
		$this->events->dispatchTyped(new ObjectActivatedEvent(object: $object, receivedAt: $receivedAt));
	}//end leftDraft()
}//end class
