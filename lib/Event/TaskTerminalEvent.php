<?php

/**
 * A task was persisted in a terminal state.
 *
 * One event class serves two dispatch points with two guarantees, told apart
 * by {@see TaskTerminalEvent::isCommitted()}:
 *
 * - `committed: false` — dispatched from
 *   {@see \OCA\OpenRegister\Db\TaskMapper}, the choke point every terminal
 *   task write passes, INSIDE the verb's transaction, so a listener that
 *   cancels the task's business timers does so in the same operation that
 *   made the subject terminal (flow-business-timers design D-9).
 * - `committed: true` — dispatched from
 *   {@see \OCA\OpenRegister\Service\Task\TaskService} AFTER the transaction
 *   that moved the task closes, never inside it: the listener that wakes the
 *   task's flow run and may continue it in-request must observe a completion
 *   that has already committed, so a continuation that fails leaves a
 *   completed task behind (flow-user-task-node design D-5).
 *
 * Fires for EVERY terminal transition (complete, resolve, cancel, moot and
 * run-terminal termination), not only completions, and may fire more than
 * once for one task; listeners are idempotent by contract and filter on the
 * flag, the state and the outcome.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
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
	 * @param Task $task      The task, already persisted in a terminal state.
	 * @param bool $committed TRUE when the terminal write's transaction has
	 *                        closed; FALSE when dispatched inside it.
	 */
	public function __construct(
		private readonly Task $task,
		private readonly bool $committed = true,
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

	/**
	 * Whether the terminal write has committed.
	 *
	 * @return bool TRUE after commit (TaskService), FALSE inside the
	 *              transaction (TaskMapper).
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function isCommitted(): bool {
		return $this->committed;
	}//end isCommitted()

	/**
	 * The task's public uuid.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function getTaskUuid(): string {
		return (string)$this->task->getUuid();
	}//end getTaskUuid()

	/**
	 * The terminal state.
	 *
	 * @return string One of Task::TERMINAL_STATES.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function getState(): string {
		return (string)$this->task->getState();
	}//end getState()

	/**
	 * The recorded outcome.
	 *
	 * @return string|null The outcome.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function getOutcome(): ?string {
		return $this->task->getOutcome();
	}//end getOutcome()
}//end class
