<?php

/**
 * TaskProjectionServiceInterface: what callers outside the Service component ask of TaskProjectionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTransitionedEvent;

/**
 * Keeping a task's projections in step with the task, as the projection and
 * write-back listeners use it. Only the methods those callers call.
 */
interface TaskProjectionServiceInterface {

	/**
	 * Project a committed task transition onto its surfaces.
	 *
	 * @param TaskTransitionedEvent $event The committed transition.
	 *
	 * @return void
	 */
	public function afterTransition(TaskTransitionedEvent $event): void;

	/**
	 * Reconcile one task's projections with the task.
	 *
	 * @param Task $task The task.
	 *
	 * @return bool True when reconciled; false when the surface failed.
	 */
	public function reconcileTask(Task $task): bool;

	/**
	 * Reconcile one task's projections by its uuid.
	 *
	 * @param string $taskUuid The task uuid.
	 *
	 * @return bool True when reconciled; false when the task is unknown or the surface failed.
	 */
	public function reconcile(string $taskUuid): bool;
}//end interface
