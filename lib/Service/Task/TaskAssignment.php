<?php

/**
 * An assignment of a task: which task, to whom.
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
 * The task and the identity it is assigned to.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskAssignment {
	/**
	 * The assignment.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $assignee The new assignee.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $assignee,
	) {
	}//end __construct()
}//end class
