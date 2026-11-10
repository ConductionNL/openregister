<?php

/**
 * What one scheduled task rule evaluates each candidate task against.
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

use OCA\OpenRegister\Db\Schema;

/**
 * The rule name, its declared filter, its dedupe fields and the synthetic
 * schema it dispatches under, for one sweep of TaskScheduledNotificationJob.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
 */
final readonly class TaskSweep {
	/**
	 * Bundle the rule values.
	 *
	 * @param string               $name    The rule name.
	 * @param array<string, mixed> $filter  The trigger's declared filter.
	 * @param list<string>         $watched The sorted dedupe fields.
	 * @param Schema               $schema  The synthetic schema carrying only this rule.
	 */
	public function __construct(
		public string $name,
		public array $filter,
		public array $watched,
		public Schema $schema,
	) {
	}//end __construct()
}//end class
