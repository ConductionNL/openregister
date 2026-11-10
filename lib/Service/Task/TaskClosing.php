<?php

/**
 * How a task is closed by something other than its performer: the act and the outcome recorded.
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
 * The act that closes a task and the outcome it records on it.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-advisory-due-date-notifies-an-enforcing-expiry-transitions
 */
final class TaskClosing {
	/**
	 * The closing.
	 *
	 * @param TaskAct $act The closing act (verb, actor, reason, target state).
	 * @param string $outcome The outcome recorded on the task.
	 */
	public function __construct(
		public readonly TaskAct $act,
		public readonly string $outcome,
	) {
	}//end __construct()
}//end class
