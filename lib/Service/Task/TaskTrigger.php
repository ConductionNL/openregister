<?php

/**
 * A system source acting on a task, and why.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The identity a system path records as actor (a sequence, a timer) and the
 * reason it records on the audit, as {@see TaskService::enable()} takes them.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */
final class TaskTrigger {

	/**
	 * Constructor.
	 *
	 * @param string $source The identity recorded as actor.
	 * @param string $reason Why, recorded on the audit.
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
