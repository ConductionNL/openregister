<?php

/**
 * The collaborators of the task lifecycle verbs, other than their access guards.
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
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The tables, the commit, the intake and the routing the lifecycle verbs
 * work through. The access guards are not in here: they stay direct
 * constructor parameters of the service that applies them.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskLifecycleServices {
	/**
	 * Bundle the lifecycle collaborators.
	 *
	 * @param TaskTables $tables The task tables a verb writes.
	 * @param TaskCommit $commit The transaction, logger and announcer.
	 * @param TaskIntake $intake Builds and checks boundary data.
	 * @param TaskPerformerResolver $resolver The routing strategies.
	 */
	public function __construct(
		public readonly TaskTables $tables,
		public readonly TaskCommit $commit,
		public readonly TaskIntake $intake,
		public readonly TaskPerformerResolver $resolver,
	) {
	}//end __construct()
}//end class
