<?php

/**
 * The task side of the sweep: the task-timeout range scan and what applies a due task's behaviour.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;

/**
 * The collaborators FlowTimerSweep sweeps task timeouts with. Either may be
 * null; the task-timeout pass is then skipped, as before.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
 */
final class FlowTimerSweepTasks {
	/**
	 * Bundle the collaborators.
	 *
	 * @param TaskMapper|null           $tasks       The task-timeout range scan (task-expiry-and-outcomes D-3).
	 * @param TaskServiceInterface|null $taskService Applies a due task's declared behaviour.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function __construct(
		public readonly ?TaskMapper $tasks = null,
		public readonly ?TaskServiceInterface $taskService = null,
	) {
	}//end __construct()
}//end class
