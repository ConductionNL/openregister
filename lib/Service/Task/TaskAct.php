<?php

/**
 * One act on a task: the verb, who performs it, why, and the state it leaves.
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
 * The values that travel together through a lifecycle verb of
 * {@see TaskService}: authorization reads the verb and the actor, the
 * state write reads the verb and the state, the audit row reads the verb,
 * the actor and the reason.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskAct {

	/**
	 * Constructor.
	 *
	 * @param string $verb The verb, also the audit action and the last action.
	 * @param string|null $actor The acting identity, or the system source.
	 * @param string|null $reason Why, recorded on the audit row.
	 * @param string|null $state The state the act leaves the task in, when it moves it.
	 */
	public function __construct(
		public readonly string $verb,
		public readonly string|null $actor,
		public readonly string|null $reason = null,
		public readonly string|null $state = null,
	) {
	}//end __construct()
}//end class
