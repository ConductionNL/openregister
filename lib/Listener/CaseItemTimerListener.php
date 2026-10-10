<?php

/**
 * Plan items on the shared clock: arm, cancel, and act on an expiry.
 *
 * Listens for {@see CaseItemTransitionedEvent} (dispatched after the
 * transition commits): an item entering `active` arms its timers, an item
 * reaching a terminal state cancels them. Listens for
 * {@see FlowTimerFiredEvent}: an enforcing expiry on a plan item terminates
 * it. Nothing under Service\Flow depends on Service\Case; this listener is
 * the coupling, and it points one way.
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
 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Event\CaseItemTransitionedEvent;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Service\Case\CasePlanItemTimers;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Wires plan items to the timer core.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
class CaseItemTimerListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param CasePlanItemTimers $timers What a deadline means for an item.
	 * @param CasePlanService $plans The one transition path.
	 */
	public function __construct(
		private readonly CasePlanItemTimers $timers,
		private readonly CasePlanService $plans,
	) {

	}//end __construct()

	/**
	 * Handle a plan-item transition or a fired timer.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function handle(Event $event): void {
		if ($event instanceof CaseItemTransitionedEvent) {
			$item = $event->getItem();
			if ($item->getState() === CaseItem::STATE_ACTIVE) {
				$this->timers->onEntered(item: $item, actor: null);
			}

			if ($item->isInTerminalState() === true) {
				$this->timers->onTerminal(item: $item);
			}

			return;
		}

		if ($event instanceof FlowTimerFiredEvent) {
			$itemUuid = $this->timers->itemToTerminate(event: $event);
			if ($itemUuid !== null) {
				$this->plans->onTimerExpired(itemUuid: $itemUuid, timerUuid: (string)$event->getTimer()->getUuid());
			}
		}
	}//end handle()
}//end class
