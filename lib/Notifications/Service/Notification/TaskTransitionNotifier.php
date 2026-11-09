<?php

/**
 * TaskTransitionNotifier: a task transition becomes its declarative notifications.
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
use OCA\OpenRegister\Service\Task\TaskInboxServiceInterface;

/**
 * Builds the task's adapter from its inbox row and hands it, with the task rule
 * schema, to the one dispatcher. Failures propagate: each caller decides how a
 * failed delivery is reported.
 */
final class TaskTransitionNotifier implements TaskTransitionNotifierInterface {

	/**
	 * Constructor.
	 *
	 * @param NotificationRuleDispatcherInterface $dispatcher The one dispatcher.
	 * @param TaskNotificationRulesInterface      $rules      The task rule registry.
	 * @param TaskInboxServiceInterface           $inbox      The row the adapter is built from.
	 */
	public function __construct(
		private readonly NotificationRuleDispatcherInterface $dispatcher,
		private readonly TaskNotificationRulesInterface $rules,
		private readonly TaskInboxServiceInterface $inbox,
	) {

	}//end __construct()

	/**
	 * Dispatch the `transition` trigger for one task through the task rule set.
	 *
	 * @param Task                 $task    The task, presented to the dispatcher as its inbox row.
	 * @param array<string, mixed> $extra   Fields added to the task's notification payload.
	 * @param array<string, mixed> $context The transition context the rules match on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function notifyTransition(Task $task, array $extra, array $context): void {
		$adapter = TaskObjectAdapters::forTask(task: $task, row: $this->inbox->enrich(task: $task), extra: $extra);

		$this->dispatcher->dispatchWithSchema(
			object: $adapter,
			trigger: 'transition',
			context: $context,
			schema: $this->rules->buildSchema()
		);
	}//end notifyTransition()
}//end class
