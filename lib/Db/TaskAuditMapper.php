<?php

/**
 * Persistence for the append-only task audit.
 *
 * APPEND-ONLY IS ENFORCED HERE, not promised. `update()` and `delete()` are
 * overridden to refuse: the base mapper inherits both from QBMapper, so
 * merely "not offering" them would leave a working mutation path one
 * refactor away from being called. Deleting a task does not cascade into
 * this table (no foreign key exists, by design).
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
 * @template-extends QBMapper<TaskAudit>
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use LogicException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Appends and reads task audit entries. Never updates, never deletes.
 *
 * @template-extends QBMapper<TaskAudit>
 */
class TaskAuditMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_task_audit', entityClass: TaskAudit::class);

	}//end __construct()

	/**
	 * Append an entry, stamping `created`.
	 *
	 * @param Entity $entity The entry to append.
	 *
	 * @return TaskAudit The appended entry.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function insert(Entity $entity): TaskAudit {
		if ($entity instanceof TaskAudit && $entity->getCreated() === null) {
			$entity->setCreated(new DateTime());
		}

		/*
		 * @var TaskAudit
		 */
		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Refused: the audit is append-only.
	 *
	 * @param Entity $entity Ignored.
	 *
	 * @return TaskAudit Never returns.
	 *
	 * @throws LogicException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The parameter is the
	 * inherited signature; refusing it unread is the whole method.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function update(Entity $entity): TaskAudit {
		throw new LogicException('The task audit is append-only: entries are never updated.');
	}//end update()

	/**
	 * Refused: the audit is append-only.
	 *
	 * @param Entity $entity Ignored.
	 *
	 * @return TaskAudit Never returns.
	 *
	 * @throws LogicException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The parameter is the
	 * inherited signature; refusing it unread is the whole method.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function delete(Entity $entity): TaskAudit {
		throw new LogicException('The task audit is append-only: entries are never deleted.');
	}//end delete()

	/**
	 * The audit trail of one task, oldest first.
	 *
	 * @param int $taskId The task's row id.
	 *
	 * @return array<int, TaskAudit> The entries.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function findForTask(int $taskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findForTask()
}//end class
