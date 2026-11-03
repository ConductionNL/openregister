<?php

/**
 * THE published mapping between the six CMMN states and the four VTODO
 * status values, applied in both directions.
 *
 * Lossy on the way OUT (six states onto four values) and NOT lossy on the
 * way IN: an incoming VTODO status names a REQUESTED TRANSITION, never a
 * state. `STATUS:COMPLETED` can only ask for `complete`; the engine decides
 * whether that verb is legal and permitted. A status that names no legal
 * transition from the task's current state is refused, not applied
 * (flow-task-inbox-projections, design D-2 rule 3).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-status-mapping
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskConflictException;

/**
 * State to VTODO status, VTODO status to requested verb, priority both ways.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-status-mapping
 */
final class TaskVtodoStatusMapping {

	/**
	 * The four VTODO status values.
	 */
	public const NEEDS_ACTION = 'NEEDS-ACTION';

	public const IN_PROCESS = 'IN-PROCESS';

	public const COMPLETED = 'COMPLETED';

	public const CANCELLED = 'CANCELLED';

	/**
	 * State to VTODO status: the render direction.
	 *
	 * @var array<string, string>
	 */
	private const RENDER = [
		Task::STATE_AVAILABLE => self::NEEDS_ACTION,
		Task::STATE_ENABLED => self::NEEDS_ACTION,
		Task::STATE_ACTIVE => self::IN_PROCESS,
		Task::STATE_COMPLETED => self::COMPLETED,
		Task::STATE_TERMINATED => self::CANCELLED,
		Task::STATE_DISABLED => self::CANCELLED,
	];

	/**
	 * Normalised priority to iCal 0-9: the inverse of TaskPriority's import
	 * mapping, chosen so every value round-trips (1 urgent, 3 high, 5 normal,
	 * 7 low).
	 *
	 * @var array<string, int>
	 */
	private const PRIORITY = [
		'urgent' => 1,
		'high' => 3,
		'normal' => 5,
		'low' => 7,
	];

	/**
	 * The VTODO status a task state renders as.
	 *
	 * @param string $state One of the six CMMN states.
	 *
	 * @return string One of the four VTODO status values.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-status-mapping
	 */
	public static function render(string $state): string {
		return self::RENDER[$state] ?? self::NEEDS_ACTION;
	}//end render()

	/**
	 * The lifecycle verb an incoming VTODO status REQUESTS, given the task's
	 * current state.
	 *
	 * Only completion and cancellation name verbs: the trust boundary is
	 * one field wide. A status that merely restates the task's rendered
	 * status is not a request (null). A status that would reopen a terminal
	 * task is an illegal transition and is refused here, before any verb.
	 *
	 * @param string $vtodoStatus The incoming STATUS value, any case.
	 * @param Task $task The task the VTODO projects.
	 *
	 * @return string|null `complete`, `cancel`, or null for "no lifecycle request".
	 *
	 * @throws TaskConflictException When the status names no legal transition.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-status-mapping
	 */
	public static function requestedVerb(string $vtodoStatus, Task $task): ?string {
		$incoming = strtoupper(trim($vtodoStatus));
		$current = self::render(state: (string)$task->getState());

		if ($incoming === $current) {
			return null;
		}

		if ($incoming === self::COMPLETED) {
			return 'complete';
		}

		if ($incoming === self::CANCELLED) {
			return 'cancel';
		}

		if ($task->isInTerminalState() === true) {
			throw new TaskConflictException(
				message: sprintf(
					"Status '%s' refused: task '%s' is in terminal state '%s' and cannot be reopened from a calendar.",
					$incoming,
					(string)$task->getUuid(),
					(string)$task->getState()
				)
			);
		}

		// NEEDS-ACTION or IN-PROCESS on a non-terminal task: a progress note,
		// not a lifecycle verb. The next render restores the engine's value.
		return null;
	}//end requestedVerb()

	/**
	 * The iCal priority a normalised priority renders as.
	 *
	 * @param string|null $priority One of low|normal|high|urgent.
	 *
	 * @return int The iCal 0-9 value.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	public static function priority(?string $priority): int {
		return self::PRIORITY[strtolower(trim((string)$priority))] ?? self::PRIORITY['normal'];
	}//end priority()

	/**
	 * The published render mapping, for documentation surfaces and tests.
	 *
	 * @return array<string, string> state => VTODO status.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-status-mapping
	 */
	public static function mapping(): array {
		return self::RENDER;
	}//end mapping()
}//end class
