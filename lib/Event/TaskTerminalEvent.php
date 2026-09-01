<?php

/**
 * A task was persisted in a terminal state.
 *
 * Dispatched from {@see \OCA\OpenRegister\Db\TaskMapper::update()}, the one
 * choke point every terminal task write passes, INSIDE the verb's transaction,
 * so a listener that cancels the task's business timers does so in the same
 * operation that made the subject terminal (flow-business-timers design D-9).
 * May fire more than once for one task; listeners are idempotent by contract.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Carries the terminal task's identity, state and outcome.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
 */
class TaskTerminalEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $taskUuid The task's public uuid.
	 * @param string $state The terminal state it was persisted with.
	 * @param string|null $outcome The recorded outcome.
	 */
	public function __construct(
		private readonly string $taskUuid,
		private readonly string $state,
		private readonly ?string $outcome,
	) {
		parent::__construct();

	}//end __construct()

	/**
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
}//end class
