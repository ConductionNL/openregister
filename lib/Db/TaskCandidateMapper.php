<?php

/**
 * Persistence for the candidate-pool index rows.
 *
 * These rows are DERIVED from the task's `candidate_users` /
 * `candidate_groups` / `candidate_role` columns and are rewritten wholesale
 * by the one service write path that also writes those columns, inside the
 * same transaction. Nothing edits a candidate row in place.
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
 * @template-extends QBMapper<TaskCandidate>
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and rewrites candidate index rows.
 *
 * @template-extends QBMapper<TaskCandidate>
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
 */
class TaskCandidateMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_task_candidates', entityClass: TaskCandidate::class);

	}//end __construct()

	/**
	 * The candidate rows of one task.
	 *
	 * @param int $taskId The task's row id.
	 *
	 * @return array<int, TaskCandidate> The rows.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function findForTask(int $taskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities(query: $qb);
	}//end findForTask()

	/**
	 * Replace one task's candidate rows with a fresh set.
	 *
	 * Delete-then-insert on purpose: the rows are an index derived from the
	 * JSON record, so the correct write is "make it equal", not a diff. The
	 * CALLER holds the transaction that also writes the JSON — this method
	 * must never open one of its own, or the two halves could commit apart.
	 *
	 * @param int $taskId The task's row id.
	 * @param array<int, array{kind: string, ref: string}> $candidates The new rows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function replaceForTask(int $taskId, array $candidates): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		foreach ($candidates as $candidate) {
			$row = new TaskCandidate();
			$row->setTaskId($taskId);
			$row->setKind($candidate['kind']);
			$row->setRef($candidate['ref']);
			$this->insert(entity: $row);
		}
	}//end replaceForTask()
}//end class
