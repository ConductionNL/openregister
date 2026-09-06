<?php

/**
 * Mapper for the per-task projection state.
 *
 * One row per (task, surface). The projector upserts it before it writes
 * the surface, so a write observed on the surface can be compared against
 * what the projector meant to put there.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Reads and writes `openregister_task_projections`.
 *
 * @template-extends QBMapper<TaskProjectionState>
 */
class TaskProjectionStateMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_task_projections', entityClass: TaskProjectionState::class);

	}//end __construct()

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
	public function findForTask(string $taskUuid, string $surface = TaskProjectionState::SURFACE_CALDAV): ?TaskProjectionState {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('task_uuid', $qb->createNamedParameter($taskUuid)))
			->andWhere($qb->expr()->eq('surface', $qb->createNamedParameter($surface)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end findForTask()

	/**
	 * Insert or update a state row.
	 *
	 * @param TaskProjectionState $state The row to persist.
	 *
	 * @return TaskProjectionState The persisted row.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
	 */
	public function save(TaskProjectionState $state): TaskProjectionState {
		if ($state->getId() === null) {
			return $this->insert(entity: $state);
		}

		return $this->update(entity: $state);
	}//end save()
}//end class
