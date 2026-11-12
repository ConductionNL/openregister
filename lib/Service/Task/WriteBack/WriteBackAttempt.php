<?php

/**
 * One calendar write-back attempt: the task it names and what it requested.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task\WriteBack
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task\WriteBack;

use OCA\OpenRegister\Db\Task;

/**
 * The `(task, requested_verb, actor)` triple that crosses the gate, plus the
 * uuid the write named (which is what the lifecycle is called with).
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
 */
final class WriteBackAttempt {

	/**
	 * Constructor.
	 *
	 * @param string      $taskUuid The uuid the write named.
	 * @param Task        $task     The task it resolved to.
	 * @param string      $verb     The verb requested (or `status` for an illegal status edit).
	 * @param string|null $actor    The acting identity, or null when unknown.
	 */
	public function __construct(
		public readonly string $taskUuid,
		public readonly Task $task,
		public readonly string $verb,
		public readonly ?string $actor,
	) {

	}//end __construct()
}//end class
