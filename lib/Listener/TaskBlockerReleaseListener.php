<?php

/**
 * Closing a blocker releases the tasks that waited on it.
 *
 * Listens for the COMMITTED {@see TaskTerminalEvent}. For every open task
 * whose `blocked_by` names the closed task it appends an audit entry
 * `released` through {@see TaskService::record()}, which also announces the
 * task again, so its notification and calendar entry follow with nobody
 * editing it. The blocked flag itself needs no write: it is derived on read
 * from the blocker's terminality.
 *
 * Post-event work (ADR-078): the in-transaction dispatch is ignored, so
 * closing a task never waits on its dependants. A failure for one dependant
 * is logged and the rest still run; the blocker is closed either way.
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
 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\TaskBlockerMapper;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Releases a closed task's dependants.
 *
 * @template-implements IEventListener<TaskTerminalEvent>
 */
class TaskBlockerReleaseListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param TaskBlockerMapper $tasks Finds the dependants.
	 * @param TaskService $service Records the release and announces it.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly TaskBlockerMapper $tasks,
		private readonly TaskService $service,
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
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function handle(Event $event): void {
		if ($event instanceof TaskTerminalEvent === false || $event->isCommitted() === false) {
			return;
		}

		$blocker = $event->getTaskUuid();
		try {
			$dependants = $this->tasks->findOpenBlockedBy(blockerUuid: $blocker);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TaskBlockerReleaseListener] Could not find the tasks waiting on a closed task: ' . $failure->getMessage(),
				['blocker' => $blocker]
			);

			return;
		}

		foreach ($dependants as $dependant) {
			try {
				$this->service->record(
					uuid: (string)$dependant->getUuid(),
					action: 'released',
					actor: null,
					reason: sprintf("Task '%s' it waited on reached state '%s'.", $blocker, $event->getState())
				);
			} catch (Throwable $failure) {
				$this->logger->warning(
					'[TaskBlockerReleaseListener] Could not record a release: ' . $failure->getMessage(),
					['blocker' => $blocker, 'task' => $dependant->getUuid()]
				);
			}
		}
	}//end handle()
}//end class
