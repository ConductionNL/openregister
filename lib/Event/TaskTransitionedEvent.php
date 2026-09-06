<?php

/**
 * A task lifecycle transition, announced AFTER its transaction committed.
 *
 * This is the seam every projection hangs off (flow-task-inbox-projections,
 * design D-8): notifications and the calendar VTODO are rendered from the
 * committed row, never inside the transaction that produced it, so a
 * calendar outage or a notification backend error can log and skip without
 * ever unwinding the transition. The event carries what a projection needs
 * and the row cannot tell it: who held the task BEFORE (so a reassignment
 * can withdraw the old assignee's notification and calendar entry) and who
 * acted.
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
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Task;
use OCP\EventDispatcher\Event;

/**
 * Carries the committed task, its previous holder and the acting identity.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
 */
class TaskTransitionedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param Task $task The task as committed.
	 * @param string|null $previousAssignee Who held it before this transition, when anyone.
	 * @param string|null $previousState The CMMN state before this transition, when known.
	 * @param string|null $actor The acting identity, when any.
	 */
	public function __construct(
		private readonly Task $task,
		private readonly ?string $previousAssignee = null,
		private readonly ?string $previousState = null,
		private readonly ?string $actor = null,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The committed task.
	 *
	 * @return Task The task.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
	 */
	public function getTask(): Task {
		return $this->task;
	}//end getTask()

	/**
	 * The NAMED transition action, as the row recorded it.
	 *
	 * Rules address this, not the resulting state, so a completion by
	 * approval and one by rejection stay separately addressable.
	 *
	 * @return string The action, or an empty string when the row carries none.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function getAction(): string {
		return (string)$this->task->getLastAction();
	}//end getAction()

	/**
	 * The assignee before the transition, when there was one.
	 *
	 * @return string|null The previous assignee uid.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	public function getPreviousAssignee(): ?string {
		return $this->previousAssignee;
	}//end getPreviousAssignee()

	/**
	 * The state before the transition, when known.
	 *
	 * @return string|null The previous CMMN state.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function getPreviousState(): ?string {
		return $this->previousState;
	}//end getPreviousState()

	/**
	 * The acting identity, when any.
	 *
	 * @return string|null The actor uid.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function getActor(): ?string {
		return $this->actor;
	}//end getActor()

	/**
	 * Whether the assignee changed hands in this transition.
	 *
	 * @return bool True when the previous and current assignee differ.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-notification-for-an-answered-task-is-withdrawn
	 */
	public function assigneeChanged(): bool {
		return (string)$this->previousAssignee !== (string)$this->task->getAssignee();
	}//end assigneeChanged()
}//end class
