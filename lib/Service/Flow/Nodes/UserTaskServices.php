<?php

/**
 * The services the user-task step creates, reads and times its task with.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Task\TaskFormReader;

/**
 * The services the user-task step creates, reads and times its task with.
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
 */
final class UserTaskServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowTaskBridge   $bridge Creates and reads the node's task.
	 * @param TaskFormReader   $forms  Reads the form a task asks for.
	 * @param FlowTimerService $timers Arms the task's deadline timers.
	 */
	public function __construct(
		public readonly FlowTaskBridge $bridge,
		public readonly TaskFormReader $forms,
		public readonly FlowTimerService $timers,
	) {
	}//end __construct()
}//end class
