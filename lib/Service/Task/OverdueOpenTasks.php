<?php

/**
 * The open overdue tasks, as the scheduled task notification sweep reads them.
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
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\TaskInboxAudience;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskInboxDueWindow;
use OCA\OpenRegister\Db\TaskInboxFilter;
use OCA\OpenRegister\Db\TaskInboxOrder;
use OCA\OpenRegister\Db\TaskInboxSelection;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\Notifications\OverdueTaskSourceInterface;

/**
 * Reads the inbox's derived-overdue filter over every open task, as an admin sweep.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
 */
final class OverdueOpenTasks implements OverdueTaskSourceInterface {

	/**
	 * The uid the sweep queries as: an administrator's view, which the inbox
	 * does not narrow. It is never a recipient.
	 *
	 * @var string
	 */
	public const SWEEP_UID = '__openregister_task_sweep__';

	/**
	 * Hold the task mapper.
	 *
	 * @param TaskMapper $tasks The inbox query.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
	 */
	public function __construct(
		private readonly TaskMapper $tasks,
	) {
	}//end __construct()

	/**
	 * One page of the open tasks that are overdue at the given instant, in creation order.
	 *
	 * @param DateTime  $now  The instant the sweep runs at.
	 * @param ListQuery $page The page size and offset.
	 *
	 * @return array<int, \OCA\OpenRegister\Db\Task> The page.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
	 */
	public function overdueOpenTasks(DateTime $now, ListQuery $page): array {
		$criteria = new TaskInboxCriteria(
			audience: new TaskInboxAudience(uid: self::SWEEP_UID, isAdmin: true, scope: TaskInboxCriteria::SCOPE_ALL),
			selection: new TaskInboxSelection(
				filter: new TaskInboxFilter(isTerminal: false),
				window: new TaskInboxDueWindow(overdueAt: $now),
				order: new TaskInboxOrder(sort: TaskInboxCriteria::SORT_CREATED)
			)
		);

		return $this->tasks->findInbox(criteria: $criteria, page: $page);
	}//end overdueOpenTasks()
}//end class
