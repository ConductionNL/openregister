<?php

/**
 * An object changed: its plan's object on-parts may fire.
 *
 * A write to the register object is NEVER interpreted as a plan-item
 * transition; it MAY satisfy a sentry, which goes through sentry evaluation
 * like any other condition. The listener first asks the cheap indexed
 * question "does this object have a live plan at all" and returns without
 * further work for the overwhelming majority of objects that do not.
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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Feeds object events to case-plan evaluation.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */
class CaseObjectEventListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param CasePlanService $plans The case layer.
	 */
	public function __construct(
		private readonly CasePlanService $plans,
	) {

	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent) {
			$this->forward(object: $event->getObject(), trigger: 'object.transitioned');
			return;
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$this->forward(object: $event->getNewObject(), trigger: 'object.updated');
		}
	}//end handle()

	/**
	 * Forward one object event to the case layer.
	 *
	 * @param ObjectEntity|null $object The object after the change.
	 * @param string $trigger The catalog event id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function forward(?ObjectEntity $object, string $trigger): void {
		$uuid = trim((string)$object?->getUuid());
		if ($uuid === '') {
			return;
		}

		$this->plans->onObjectEvent(objectUuid: $uuid, event: $trigger, payload: $object->getObject());
	}//end forward()
}//end class
