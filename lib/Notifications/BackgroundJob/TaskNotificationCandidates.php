<?php

/**
 * Which task rules apply, which tasks they could fire on, and how a task is filtered.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Notifications\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use OCA\OpenRegister\Kernel\Contracts\Notifications\OverdueTaskSourceInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\TaskRowSourceInterface;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRules;

/**
 * Which task rules apply, which tasks they could fire on, and how a task is filtered.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
 */
final readonly class TaskNotificationCandidates {
	/**
	 * Bundle the services.
	 *
	 * @param TaskNotificationRules      $rules   The task rule registry.
	 * @param OverdueTaskSourceInterface $tasks   The candidate query.
	 * @param TaskRowSourceInterface     $inbox   The row the adapter is built from.
	 * @param ScheduledFilterEvaluator   $filters The operator-object filter evaluator.
	 */
	public function __construct(
		public TaskNotificationRules $rules,
		public OverdueTaskSourceInterface $tasks,
		public TaskRowSourceInterface $inbox,
		public ScheduledFilterEvaluator $filters,
	) {
	}//end __construct()
}//end class
