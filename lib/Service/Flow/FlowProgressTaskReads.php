<?php

/**
 * The inbox reads a progress report counts tasks with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\TaskInboxAnchor;
use OCA\OpenRegister\Db\TaskInboxAudience;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskInboxDueWindow;
use OCA\OpenRegister\Db\TaskInboxFilter;
use OCA\OpenRegister\Db\TaskInboxSelection;

/**
 * Every open task an administrator reading `scope=all` would see, optionally
 * of one flow and optionally overdue: the read a step's `tasksHref` lists.
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
 */
final class FlowProgressTaskReads {

	/**
	 * The open tasks, of one flow or of every flow, overdue or not.
	 *
	 * @param string|null   $flowId    Narrow to this flow's tasks, or null.
	 * @param DateTime|null $overdueAt Only tasks overdue at this instant, or null.
	 *
	 * @return TaskInboxCriteria The read.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
	 */
	public function openTasks(?string $flowId, ?DateTime $overdueAt): TaskInboxCriteria {
		return new TaskInboxCriteria(
			audience: new TaskInboxAudience(uid: '', groupIds: [], isAdmin: true, scope: TaskInboxCriteria::SCOPE_ALL),
			selection: new TaskInboxSelection(
				filter: new TaskInboxFilter(isTerminal: false),
				window: new TaskInboxDueWindow(overdueAt: $overdueAt),
				anchor: new TaskInboxAnchor(flowId: $flowId)
			)
		);
	}//end openTasks()
}//end class
