<?php

/**
 * One projection of a task into its assignee's calendar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskProjectionState;

/**
 * The state of one {@see TaskCalendarProjector} sync: the task, who held it
 * before, whether the stored copy is verified, and the projection row found.
 *
 * Never leaves the projector that built it.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */
final class TaskProjectionRun {

	/**
	 * The current projection row, once the sync has looked it up.
	 *
	 * @var TaskProjectionState|null
	 */
	public ?TaskProjectionState $state = null;

	/**
	 * The run.
	 *
	 * @param Task $task The task being projected.
	 * @param string|null $previousAssignee Who held it before, if known.
	 * @param bool $verifyStore Whether to read the calendar's own copy even
	 *                          when the recorded hash matches (reconcile).
	 */
	public function __construct(
		public readonly Task $task,
		public readonly ?string $previousAssignee,
		public readonly bool $verifyStore,
	) {
	}//end __construct()
}//end class
