<?php

/**
<<<<<<< HEAD
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
=======
 * A task was persisted in a terminal state.
 *
 * Dispatched from {@see \OCA\OpenRegister\Db\TaskMapper::update()}, the one
 * choke point every terminal task write passes, INSIDE the verb's transaction,
 * so a listener that cancels the task's business timers does so in the same
 * operation that made the subject terminal (flow-business-timers design D-9).
 * May fire more than once for one task; listeners are idempotent by contract.
>>>>>>> origin/feature/flow-business-timers
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
<<<<<<< HEAD
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
=======
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
>>>>>>> origin/feature/flow-business-timers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

<<<<<<< HEAD
use OCA\OpenRegister\Db\Task;
use OCP\EventDispatcher\Event;

/**
 * Carries the task as it was persisted in its terminal state.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
=======
use OCP\EventDispatcher\Event;

/**
 * Carries the terminal task's identity, state and outcome.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
>>>>>>> origin/feature/flow-business-timers
 */
class TaskTerminalEvent extends Event {

	/**
	 * Constructor.
	 *
<<<<<<< HEAD
	 * @param Task $task The task, already persisted in a terminal state.
	 */
	public function __construct(
		private readonly Task $task,
=======
	 * @param string $taskUuid The task's public uuid.
	 * @param string $state The terminal state it was persisted with.
	 * @param string|null $outcome The recorded outcome.
	 */
	public function __construct(
		private readonly string $taskUuid,
		private readonly string $state,
		private readonly ?string $outcome,
>>>>>>> origin/feature/flow-business-timers
	) {
		parent::__construct();

	}//end __construct()

	/**
<<<<<<< HEAD
	 * The terminal task.
	 *
	 * @return Task The task as persisted.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
	 */
	public function getTask(): Task {
		return $this->task;
	}//end getTask()
=======
	 * The task's public uuid.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function getTaskUuid(): string {
		return $this->taskUuid;
	}//end getTaskUuid()

	/**
	 * The terminal state.
	 *
	 * @return string One of Task::TERMINAL_STATES.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function getState(): string {
		return $this->state;
	}//end getState()

	/**
	 * The recorded outcome.
	 *
	 * @return string|null The outcome.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function getOutcome(): ?string {
		return $this->outcome;
	}//end getOutcome()
>>>>>>> origin/feature/flow-business-timers
}//end class
