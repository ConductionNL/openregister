<?php

/**
 * Runs the projections AFTER a transition committed, and never lets one of
 * them fail the task.
 *
 * The asymmetry is deliberate (flow-task-inbox-projections, design D-8): a
 * task that exists with no calendar entry is recoverable by reconciliation;
 * a task that could not be created because a calendar was down would be an
 * outage in the wrong system. Every failure here is logged naming the task
 * and the surface, and left to reconciliation.
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
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskProjectionState;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Failure-isolated fan-out to the projection surfaces.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
 */
class TaskProjectionService {

	/**
	 * Constructor.
	 *
	 * @param TaskCalendarProjector $calendar The CalDAV projection writer.
	 * @param TaskMapper $tasks Resolves a task by uuid for reconciliation.
	 * @param LoggerInterface $logger Names failed tasks and surfaces.
	 */
	public function __construct(
		private readonly TaskCalendarProjector $calendar,
		private readonly TaskMapper $tasks,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Project a committed transition.
	 *
	 * @param TaskTransitionedEvent $event The committed transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
	 */
	public function afterTransition(TaskTransitionedEvent $event): void {
		$task = $event->getTask();
		try {
			$this->calendar->project(task: $task, previousAssignee: $event->getPreviousAssignee());
		} catch (Throwable $failure) {
			$this->logFailure(task: $task, failure: $failure);
		}
	}//end afterTransition()

	/**
	 * Make a task's projections match the task, by uuid.
	 *
	 * @param string $taskUuid The task uuid.
	 *
	 * @return bool True when reconciled; false when the task is unknown or the surface failed.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
	 */
	public function reconcile(string $taskUuid): bool {
		try {
			$task = $this->tasks->findByUuid(uuid: $taskUuid);
		} catch (Throwable $missing) {
			$this->logger->info(
				'[TaskProjectionService] Nothing to reconcile: ' . $missing->getMessage(),
				['task' => $taskUuid]
			);

			return false;
		}

		return $this->reconcileTask(task: $task);
	}//end reconcile()

	/**
	 * Make a task's projections match the task.
	 *
	 * @param Task $task The task.
	 *
	 * @return bool True when reconciled; false when the surface failed.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
	 */
	public function reconcileTask(Task $task): bool {
		try {
			$this->calendar->reconcile(task: $task);

			return true;
		} catch (Throwable $failure) {
			$this->logFailure(task: $task, failure: $failure);

			return false;
		}
	}//end reconcileTask()

	/**
	 * Log a surface failure naming the task and the surface.
	 *
	 * @param Task $task The task.
	 * @param Throwable $failure What went wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
	 */
	private function logFailure(Task $task, Throwable $failure): void {
		$this->logger->warning(
			sprintf(
				'[TaskProjectionService] Projection of task %s onto %s failed and is left to reconciliation; the task itself is unaffected: %s',
				(string)$task->getUuid(),
				TaskProjectionState::SURFACE_CALDAV,
				$failure->getMessage()
			),
			[
				'task' => $task->getUuid(),
				'surface' => TaskProjectionState::SURFACE_CALDAV,
				'exception' => $failure,
			]
		);
	}//end logFailure()
}//end class
