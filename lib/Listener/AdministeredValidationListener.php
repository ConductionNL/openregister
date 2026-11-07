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
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$this->refuse(event: $event, refusal: $this->refusalOf(event: $event));
	}//end handle()

	/**
	 * Ask the enforcer about the object as it would be saved and as stored
	 * (null on a create).
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return array<string, mixed>|null The refusal, or null when the write may proceed.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	private function refusalOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ?array {
		if ($event instanceof ObjectCreatingEvent) {
			return $this->enforcer->refusalFor(newObject: $event->getObject(), oldObject: null);
		}

		return $this->enforcer->refusalFor(newObject: $event->getNewObject(), oldObject: $event->getOldObject());
	}//end refusalOf()

	/**
	 * Stop the event with the enforcer's refusal, when there is one.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The event.
	 * @param array<string, mixed>|null               $refusal The enforcer's answer; null lets the write proceed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, ?array $refusal): void {
		if ($refusal === null) {
			return;
		}

		$event->setErrors($refusal);
		$event->stopPropagation();
	}//end refuse()

}//end class
