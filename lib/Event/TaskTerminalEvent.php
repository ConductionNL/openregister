<?php

/**
 * A task reached a terminal state and the transition has COMMITTED.
 *
 * Dispatched by {@see \OCA\OpenRegister\Service\Task\TaskService} after the
 * transaction that moved the task closes, never inside it: the listener that
 * matters wakes the task's flow run and may continue it in-request, and a
 * walk that ran inside the task's own transaction would make "the task is
 * completed" and "the run was advanced" one atomic fact when the design
 * wants them separate. A continuation that fails must leave a completed task
 * behind (flow-user-task-node design D-5), which is only true when the
 * completion has already committed by the time anyone hears about it.
 *
 * Fires for EVERY terminal transition (complete, resolve, cancel, moot and
 * run-terminal termination), not only completions. A run parked on a task
 * has to move on whichever way the task ended, and telling it about
 * completions alone would leave it waiting a heartbeat for a task that was
 * cancelled under it. Listeners that only care about one verb read
 * {@see Task::getOutcome()} and {@see Task::getState()}.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Task;
use OCP\EventDispatcher\Event;

/**
 * Carries the task as it was persisted in its terminal state.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
 */
class TaskTerminalEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param Task $task The task, already persisted in a terminal state.
	 */
	public function __construct(
		private readonly Task $task,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The terminal task.
	 *
	 * @return Task The task as persisted.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
	 */
	public function getTask(): Task {
		return $this->task;
	}//end getTask()
}//end class
