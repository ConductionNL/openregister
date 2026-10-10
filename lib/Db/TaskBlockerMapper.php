<?php

/**
 * The blocker lookups behind "a task may wait on another task".
 *
 * Reads the tasks table for two questions: which blockers of a page are still
 * open (the derived `blocked` flag), and which open tasks wait on a task that
 * just closed (the release). Kept apart from {@see TaskMapper}, which owns the
 * inbox predicate that leaves blocked tasks out.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Blocker lookups on the tasks table.
 *
 * @template-extends QBMapper<Task>
 *
 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
 */
class TaskBlockerMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_tasks', entityClass: Task::class);

	}//end __construct()

	/**
	 * Which of these task uuids name a task that is still open.
	 *
	 * The one lookup behind the derived `blocked` flag on a page of rows: a
	 * row is blocked while its `blockedBy` is in this answer. A uuid no task
	 * carries is not open, so a removed blocker blocks nothing.
	 *
	 * @param array<int, string> $uuids The blocker uuids of a page.
	 *
	 * @return array<int, string> The uuids among them whose task is not terminal.
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function openBlockerUuids(array $uuids): array {
		$uuids = array_values(array_unique(array_filter($uuids, static fn (string $uuid): bool => $uuid !== '')));
		if ($uuids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('uuid')
			->from($this->getTableName())
			->where($qb->expr()->in('uuid', $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('is_terminal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

		$result = $qb->executeQuery();
		$open = [];
		while (($row = $result->fetch()) !== false) {
			$open[] = (string)$row['uuid'];
		}

		$result->closeCursor();

		return $open;
	}//end openBlockerUuids()

	/**
	 * The open tasks that wait on this one.
	 *
	 * @param string $blockerUuid The blocker's uuid.
	 *
	 * @return array<int, Task> The open dependants.
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function findOpenBlockedBy(string $blockerUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('blocked_by', $qb->createNamedParameter($blockerUuid)))
			->andWhere($qb->expr()->eq('is_terminal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

		return $this->findEntities(query: $qb);
	}//end findOpenBlockedBy()
}//end class
