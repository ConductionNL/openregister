<?php

/**
 * Putting an administered validation's refusal on the save event.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Evaluates a schema's declared validations before the write lands.
 *
 * An ADAPTER and nothing else: it unpacks the two save events into "the
 * object as it would be saved" and "the object as stored", asks
 * {@see AdministeredValidationEnforcer} whether that write is refused, and
 * stops the event when it is. The decision itself is testable without
 * dispatching anything.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
class AdministeredValidationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param AdministeredValidationEnforcer $enforcer Decides whether a write is refused.
	 */
	public function __construct(
		private readonly AdministeredValidationEnforcer $enforcer,
	) {
	}//end __construct()

	/**
	 * Evaluate the declared validations for a create or an update.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->refuse(event: $event, newObject: $event->getObject(), oldObject: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->refuse(event: $event, newObject: $event->getNewObject(), oldObject: $event->getOldObject());
		}
	}//end handle()

	/**
	 * Ask the enforcer, and stop the event when it refuses.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event     The event.
	 * @param ObjectEntity                            $newObject The object as it would be saved.
	 * @param ObjectEntity|null                       $oldObject The object as stored, null on a create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	private function refuse(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		ObjectEntity $newObject,
		?ObjectEntity $oldObject,
	): void {
		$refusal = $this->enforcer->refusalFor(newObject: $newObject, oldObject: $oldObject);
		if ($refusal === null) {
			return;
		}

		$event->setErrors($refusal);
		$event->stopPropagation();
	}//end refuse()

}//end class
