<?php

/**
 * A run ended: the stage it realised follows.
 *
 * Listens for {@see FlowRunTerminalEvent} (dispatched from the one choke
 * point every terminal run write passes) and asks the case layer to evaluate
 * the plan of whichever stage that run realised. The event may fire more than
 * once for one run; evaluation is idempotent.
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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Drives stage items from run terminality.
 *
 * @template-implements IEventListener<FlowRunTerminalEvent>
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
 */
class CaseRunTerminalListener implements IEventListener {

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
	 * Handle the event. Failures are logged inside the service, never rethrown.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function handle(Event $event): void {
		if ($event instanceof FlowRunTerminalEvent === false) {
			return;
		}

		$this->plans->onRealisationTerminal(taskUuid: $event->getRunUuid());
	}//end handle()
}//end class
