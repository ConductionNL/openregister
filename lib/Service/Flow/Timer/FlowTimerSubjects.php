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
 * @spec openspec/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use Closure;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * The collaborators of FlowTimerService that reach the timer's subject.
 *
 * The task service may arrive as a Closure that builds it on first use: it
 * reaches the object service, and the object service reaches this bundle when
 * a move re-anchors timers, so building it eagerly is a constructor cycle the
 * container never leaves. FlowWiring registers it deferred.
 *
 * @spec openspec/specs/flow-business-timers/spec.md
 */
final class FlowTimerSubjects {
	/**
	 * Bundle the services.
	 *
	 * @param TaskMapper                   $tasks       The task table, for the projection and the subject read.
	 * @param TaskServiceInterface|Closure $taskService Applies enforcing outcomes as named task actions, or builds it on first use.
	 * @param IEventDispatcher             $dispatcher  Raises the fired transitions.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function __construct(
		public readonly TaskMapper $tasks,
		private readonly TaskServiceInterface|Closure $taskService,
		public readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * The task service, built on first use when it was handed in deferred.
	 *
	 * @return TaskServiceInterface The task service.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function taskService(): TaskServiceInterface {
		if ($this->taskService instanceof Closure) {
			return ($this->taskService)();
		}

		return $this->taskService;
	}//end taskService()
}//end class
