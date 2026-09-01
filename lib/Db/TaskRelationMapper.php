<?php

/**
 * Persistence for typed task relations.
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
 * @template-extends QBMapper<TaskRelation>
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes task relations.
 *
 * @template-extends QBMapper<TaskRelation>
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
 */
class TaskRelationMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_task_relations', entityClass: TaskRelation::class);

	}//end __construct()

	/**
	 * The relations of one task.
	 *
	 * @param int $taskId The task's row id.
	 *
	 * @return array<int, TaskRelation> The relations.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
	 */
	public function findForTask(int $taskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities(query: $qb);
	}//end findForTask()

	/**
	 * Every relation pointing at one object, optionally narrowed by role.
	 *
	 * "Tasks related to this contract" — indexed on (object_uuid, role) and
	 * deliberately not the inbox's hot path, which uses the task's own anchor
	 * columns (design D-6).
	 *
	 * @param string $objectUuid The related object's uuid.
	 * @param string|null $role Narrow to one relation role.
	 *
	 * @return array<int, TaskRelation> The relations.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
	 */
	public function findByObject(string $objectUuid, ?string $role = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		if ($role !== null) {
			$qb->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role)));
		}

		return $this->findEntities(query: $qb);
	}//end findByObject()
}//end class
