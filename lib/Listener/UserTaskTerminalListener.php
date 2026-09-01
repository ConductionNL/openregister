<?php

/**
 * A task the graph raised has ended: wake its run and spend the node's budget.
 *
 * Listens for {@see TaskTerminalEvent} and hands every task that carries a
 * `run_uuid` to {@see FlowTaskBridge::continueRun()}. A task with no run uuid
 * is a standalone task and nothing here concerns it.
 *
 * Everything the bridge does is best-effort by design: the completion has
 * already committed by the time this fires, so a failure here costs latency
 * (the heartbeat or the worker picks the run up) and never the answer. The
 * listener therefore logs and swallows, exactly as the propagation listener
 * does, and for the same reason.
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
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Continues the run a terminal task belongs to.
 *
 * @template-implements IEventListener<TaskTerminalEvent>
 */
class UserTaskTerminalListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param FlowTaskBridge $bridge Wakes and, per budget, advances the run.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly FlowTaskBridge $bridge,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public function handle(Event $event): void {
		if ($event instanceof TaskTerminalEvent === false || $event->isCommitted() === false) {
			return;
		}

		$task = $event->getTask();
		if (trim((string)$task->getRunUuid()) === '') {
			return;
		}

		try {
			$this->bridge->continueRun(task: $task);
		} catch (Throwable $failure) {
			$this->logger->error(
				'[UserTaskTerminalListener] Could not continue the run of task ' . $task->getUuid()
				. '; the task is terminal and the worker will pick the run up: ' . $failure->getMessage(),
				['run' => $task->getRunUuid(), 'exception' => $failure]
			);
		}
	}//end handle()
}//end class
