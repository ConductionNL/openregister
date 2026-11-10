<?php

/**
 * TaskTransitionNotifierInterface: a task transition becomes its declarative notifications.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\Task;

/**
 * What code outside the Notifications component calls to have a task transition
 * delivered through the task rule set, without naming the adapter, the rule
 * registry or the dispatcher.
 */
interface TaskTransitionNotifierInterface {

	/**
	 * Dispatch the `transition` trigger for one task through the task rule set.
	 *
	 * @param Task             $task   The task, presented to the dispatcher as its inbox row.
	 * @param TransitionNotice $notice The payload fields the transition adds and the context the rules match on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function notifyTransition(Task $task, TransitionNotice $notice): void;
}//end interface
