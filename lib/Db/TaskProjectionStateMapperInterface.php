<?php

/**
 * TaskProjectionStateMapperInterface: what callers outside the Db component use of TaskProjectionStateMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The methods of TaskProjectionStateMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to TaskProjectionStateMapper. Guarded by C04InterfaceBindingsTest.
 */
interface TaskProjectionStateMapperInterface {

	/**
	 * The state row for one task on one surface, or null when never rendered.
	 *
	 * @param string $taskUuid The task uuid.
	 * @param string $surface One of TaskProjectionState::SURFACE_*.
	 *
	 * @return TaskProjectionState|null The row, when any.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
	 */
	public function findForTask(string $taskUuid, string $surface = TaskProjectionState::SURFACE_CALDAV): ?TaskProjectionState;

	/**
	 * Insert or update a state row.
	 *
	 * @param TaskProjectionState $state The row to persist.
	 *
	 * @return TaskProjectionState The persisted row.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
	 */
	public function save(TaskProjectionState $state): TaskProjectionState;

	/**
	 * Delete one row.
	 *
	 * @param Entity $entity The row to delete.
	 *
	 * @return TaskProjectionState The deleted row.
	 */
	public function delete(Entity $entity): Entity;
}//end interface
