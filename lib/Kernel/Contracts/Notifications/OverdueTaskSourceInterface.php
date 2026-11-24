<?php
/**
 * OverdueTaskSourceInterface: the open overdue tasks a scheduled task notification rule sweeps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Notifications
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

namespace OCA\OpenRegister\Kernel\Contracts\Notifications;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * The candidate tasks of a scheduled task notification sweep, one page at a time.
 *
 * The sweep (Notifications) asks; the task inbox (Processes) answers with its
 * derived-overdue filter over every open task, and is bound to this contract
 * in its own registrar.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
 */
interface OverdueTaskSourceInterface {

	/**
	 * One page of the open tasks that are overdue at the given instant, in creation order.
	 *
	 * @param DateTime  $now  The instant the sweep runs at.
	 * @param ListQuery $page The page size and offset.
	 *
	 * @return array<int, \OCA\OpenRegister\Kernel\Contracts\TaskInterface> The page.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
	 */
	public function overdueOpenTasks(DateTime $now, ListQuery $page): array;
}//end interface
