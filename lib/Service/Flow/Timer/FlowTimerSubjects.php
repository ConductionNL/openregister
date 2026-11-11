<?php

/**
 * The task a timer measures, the task actions it applies and the dispatcher that raises its fired transitions.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * The collaborators of FlowTimerService that reach the timer's subject, wired by DI autowiring.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */
final class FlowTimerSubjects {
	/**
	 * Bundle the services.
	 *
	 * @param TaskMapper           $tasks       The task table, for the projection and the subject read.
	 * @param TaskServiceInterface $taskService Applies enforcing outcomes as named task actions.
	 * @param IEventDispatcher     $dispatcher  Raises the fired transitions.
	 */
	public function __construct(
		public readonly TaskMapper $tasks,
		public readonly TaskServiceInterface $taskService,
		public readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()
}//end class
