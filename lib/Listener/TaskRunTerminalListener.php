<?php

/**
 * Cancellation propagation: a terminal run empties its inboxes.
 *
 * Listens for {@see FlowRunTerminalEvent} and TERMINATES (never deletes)
 * every non-terminal task carrying that `run_uuid`, with the reason and the
 * propagation source recorded — orphaned inbox entries are the classic
 * retrofit bug, and this is the built-in that prevents it (design D-8).
 *
 * Idempotent by construction: the event can fire more than once for one run
 * (the stale-run reaper races completing runs), and the service only selects
 * non-terminal tasks. A task with `run_uuid` null is structurally out of
 * reach — nothing about it derives from a run.
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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Terminates a terminal run's open tasks.
 *
 * @template-implements IEventListener<FlowRunTerminalEvent>
 */
class TaskRunTerminalListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param TaskService $tasks The task lifecycle.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly TaskService $tasks,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * A propagation failure is logged, not rethrown: the run's own terminal
	 * write must never be unwound by task bookkeeping, and the propagation
	 * re-fires on the next observation of terminality.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
	 */
	public function handle(Event $event): void {
		if ($event instanceof FlowRunTerminalEvent === false) {
			return;
		}

		try {
			$terminated = $this->tasks->terminateForRun(
				runUuid: $event->getRunUuid(),
				runStatus: $event->getStatus()
			);
			if ($terminated > 0) {
				$this->logger->info(
					sprintf(
						'[TaskRunTerminalListener] Terminated %d task(s) of run %s (%s).',
						$terminated,
						$event->getRunUuid(),
						$event->getStatus()
					)
				);
			}
		} catch (Throwable $failure) {
			$this->logger->error(
				'[TaskRunTerminalListener] Cancellation propagation failed: ' . $failure->getMessage(),
				['run' => $event->getRunUuid(), 'exception' => $failure]
			);
		}
	}//end handle()
}//end class
