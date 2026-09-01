<?php

/**
 * A task ended: the plan item it realised follows.
 *
 * Listens for {@see TaskTerminalEvent}, which `TaskService` dispatches after
 * a terminal transition commits (flow-user-task-node). The listener asks the
 * case layer to evaluate the plan of whichever item that task realised; the
 * task's outcome drives the item (completed -> completed, anything else ->
 * terminated) inside that evaluation. Until the dispatching side lands, the
 * same reconciliation runs on every evaluation, so nothing here is the only
 * path.
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

use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Drives plan items from task terminality.
 *
 * @template-implements IEventListener<TaskTerminalEvent>
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
 */
class CaseTaskTerminalListener implements IEventListener {

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
		if ($event instanceof TaskTerminalEvent === false) {
			return;
		}

		$uuid = trim((string)$event->getTask()->getUuid());
		if ($uuid === '') {
			return;
		}

		$this->plans->onRealisationTerminal(taskUuid: $uuid);
	}//end handle()
}//end class
