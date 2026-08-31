<?php

/**
 * Persistence for tasks.
 *
 * Two properties of this mapper are load-bearing for the spec rather than
 * conveniences:
 *
 * - `claim()` is a CONDITIONAL UPDATE — assign IF still unassigned — so two
 *   concurrent claims produce one assignee and one conflict, never a silent
 *   overwrite. The database decides the race, not PHP.
 * - The inbox reads (`findInbox`/`countInbox`) build visibility INTO the
 *   WHERE clause and share one predicate builder, so the page and the total
 *   cannot disagree and a total can never leak the existence of tasks the
 *   caller may not see (design D-9).
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
 * @template-extends QBMapper<Task>
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes tasks.
 *
 * @template-extends QBMapper<Task>
 */
class TaskMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_tasks', entityClass: Task::class);

	}//end __construct()

	/**
	 * Insert, stamping `created`.
	 *
	 * @param Entity $entity The task to insert.
	 *
	 * @return Task The inserted task, with its id.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function insert(Entity $entity): Task {
		if ($entity instanceof Task && $entity->getCreated() === null) {
			$entity->setCreated(new DateTime());
		}

		/*
		 * @var Task
		 */
		return parent::insert($entity);
	}//end insert()

	/**
	 * Update, stamping `updated`.
	 *
	 * @param Entity $entity The task to update.
	 *
	 * @return Task The updated task.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function update(Entity $entity): Task {
		if ($entity instanceof Task) {
			$entity->setUpdated(new DateTime());
		}

		/*
		 * @var Task
		 */
		return parent::update($entity);
	}//end update()

	/**
	 * Find a task by its public uuid.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return Task The task.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function findByUuid(string $uuid): Task {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * Atomically claim a task: assign IF still unassigned and still open.
	 *
	 * The whole race lives in this one statement. Both concurrent claimers
	 * run it; the database serialises them; exactly one affects a row. The
	 * loser gets `false`, and the SERVICE turns that into a conflict — never
	 * into a retry that overwrites.
	 *
	 * @param int $taskId The task's row id.
	 * @param string $uid The claiming user.
	 *
	 * @return boolean True when this caller won the claim.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function claim(int $taskId, string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('assignee', $qb->createNamedParameter($uid))
			->set('state', $qb->createNamedParameter(Task::STATE_ACTIVE))
			->set('last_action', $qb->createNamedParameter('claim'))
			->set('updated', $qb->createNamedParameter(new DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_terminal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('assignee'),
					$qb->expr()->eq('assignee', $qb->createNamedParameter(''))
				)
			);

		return $qb->executeStatement() === 1;
	}//end claim()

	/**
	 * Every non-terminal task raised by one run.
	 *
	 * The propagation read: a run reached a terminal status and its open
	 * tasks must be terminated. Tasks with `run_uuid` null are structurally
	 * unreachable from here — the predicate is an equality on a non-null
	 * uuid — which is half of the "a standalone task survives everything"
	 * guarantee.
	 *
	 * @param string $runUuid The run uuid.
	 *
	 * @return array<int, Task> The open tasks carrying that run uuid.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
	 */
	public function findOpenByRunUuid(string $runUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)))
			->andWhere($qb->expr()->eq('is_terminal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

		return $this->findEntities(query: $qb);
	}//end findOpenByRunUuid()

	/**
	 * Open-task counts per assignee, for the `least-loaded` strategy.
	 *
	 * @param array<int, string> $uids The candidate uids.
	 *
	 * @return array<string, int> Open-task count per uid; a uid with no open
	 *                            tasks is absent.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function countOpenAssigned(array $uids): array {
		if ($uids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('assignee')
			->selectAlias($qb->func()->count('id'), 'open_count')
			->from($this->getTableName())
			->where($qb->expr()->in('assignee', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('is_terminal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('assignee');

		$result = $qb->executeQuery();
		$counts = [];
		while (($row = $result->fetch()) !== false) {
			$counts[(string)$row['assignee']] = (int)$row['open_count'];
		}

		$result->closeCursor();

		return $counts;
	}//end countOpenAssigned()

	/**
	 * When each candidate was last handed a task, for `round-robin`.
	 *
	 * @param array<int, string> $uids The candidate uids.
	 *
	 * @return array<string, string> Latest task `created` per uid (SQL
	 *                               datetime string); a uid never assigned is
	 *                               absent, which round-robin reads as "next".
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function latestAssignedAt(array $uids): array {
		if ($uids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('assignee')
			->selectAlias($qb->func()->max('created'), 'latest_created')
			->from($this->getTableName())
			->where($qb->expr()->in('assignee', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('assignee');

		$result = $qb->executeQuery();
		$latest = [];
		while (($row = $result->fetch()) !== false) {
			$latest[(string)$row['assignee']] = (string)$row['latest_created'];
		}

		$result->closeCursor();

		return $latest;
	}//end latestAssignedAt()

	/**
	 * One page of the inbox, filtered, sorted and paginated IN the datastore.
	 *
	 * @param TaskInboxCriteria $criteria What to list, for whom.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array<int, Task> The page.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public function findInbox(TaskInboxCriteria $criteria, int $limit = 25, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$this->applyInboxPredicates(qb: $qb, criteria: $criteria);
		$this->applyInboxOrder(qb: $qb, criteria: $criteria);
		$qb->setMaxResults($limit)->setFirstResult($offset);

		return $this->findEntities(query: $qb);
	}//end findInbox()

	/**
	 * The inbox total, over the SAME predicates as the page.
	 *
	 * This is what a badge count reads, so it must be one indexed query —
	 * and it must agree with `findInbox` by construction, which is why both
	 * call the one predicate builder.
	 *
	 * @param TaskInboxCriteria $criteria What to count, for whom.
	 *
	 * @return int The total.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public function countInbox(TaskInboxCriteria $criteria): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('id'), 'total')->from($this->getTableName());
		$this->applyInboxPredicates(qb: $qb, criteria: $criteria);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return 0;
		}

		return (int)$row['total'];
	}//end countInbox()

	/**
	 * The shared WHERE clause: scope, filters AND visibility, in the datastore.
	 *
	 * Visibility is part of the predicate — assignee, candidate-pool member,
	 * requester, watcher, or administrator — never a post-filter over a wider
	 * result, because a filtered-down page silently drops rows and a
	 * filtered-down total leaks what it excluded.
	 *
	 * @param IQueryBuilder $qb The query under construction.
	 * @param TaskInboxCriteria $criteria What to select, for whom.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function applyInboxPredicates(IQueryBuilder $qb, TaskInboxCriteria $criteria): void {
		// Scope.
		switch ($criteria->scope) {
			case TaskInboxCriteria::SCOPE_ASSIGNED:
				$qb->andWhere($qb->expr()->eq('assignee', $qb->createNamedParameter($criteria->uid)));
				break;
			case TaskInboxCriteria::SCOPE_POOLED:
				$qb->andWhere(
					$qb->expr()->orX(
						$qb->expr()->isNull('assignee'),
						$qb->expr()->eq('assignee', $qb->createNamedParameter(''))
					)
				);
				$qb->andWhere($this->candidateMembershipPredicate(qb: $qb, criteria: $criteria));
				break;
			case TaskInboxCriteria::SCOPE_WATCHED:
				$qb->andWhere($this->watcherPredicate(qb: $qb, uid: $criteria->uid));
				break;
			default:
				// SCOPE_ALL: everything the caller may see; visibility below.
				break;
		}//end switch

		// Visibility. An administrator sees everything; anyone else sees a
		// task only through one of the five sanctioned relationships.
		if ($criteria->isAdmin === false) {
			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('assignee', $qb->createNamedParameter($criteria->uid)),
					$qb->expr()->eq('requester', $qb->createNamedParameter($criteria->uid)),
					$this->watcherPredicate(qb: $qb, uid: $criteria->uid),
					$this->candidateMembershipPredicate(qb: $qb, criteria: $criteria)
				)
			);
		}

		// Filters — all in the WHERE clause, none in PHP.
		if ($criteria->states !== []) {
			$qb->andWhere($qb->expr()->in('state', $qb->createNamedParameter($criteria->states, IQueryBuilder::PARAM_STR_ARRAY)));
		}

		if ($criteria->isTerminal !== null) {
			$qb->andWhere($qb->expr()->eq('is_terminal', $qb->createNamedParameter($criteria->isTerminal, IQueryBuilder::PARAM_BOOL)));
		}

		if ($criteria->priority !== null) {
			$qb->andWhere($qb->expr()->eq('priority', $qb->createNamedParameter($criteria->priority)));
		}

		if ($criteria->objectUuid !== null) {
			$qb->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($criteria->objectUuid)));
		}

		// Derived overdue as a filter: the SAME comparison
		// TaskTemporalProjection makes — effective deadline
		// (due_at, else expires_at) strictly before now — expressed as a
		// predicate, with the clock instant handed in from that one class.
		// COALESCE(NULL, NULL) < x is NULL, so deadline-less tasks fall out
		// without a separate null check.
		if ($criteria->overdueAt !== null) {
			$qb->andWhere(
				$qb->createFunction(
					'COALESCE(`due_at`, `expires_at`) < '
					. $qb->createNamedParameter($criteria->overdueAt, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			);
		}
	}//end applyInboxPredicates()

	/**
	 * The caller is in the task's candidate pool (by uid or by group).
	 *
	 * An EXISTS over the candidate INDEX table — the reason that table
	 * exists — so pooled visibility is an index hit, not a JSON scan.
	 *
	 * @param IQueryBuilder $qb The query under construction.
	 * @param TaskInboxCriteria $criteria Carries the uid and group ids.
	 *
	 * @return string The EXISTS predicate.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function candidateMembershipPredicate(IQueryBuilder $qb, TaskInboxCriteria $criteria): string {
		// Built as a raw EXISTS because the correlated subquery must share
		// the OUTER query's parameter bag: parameters are therefore created
		// on $qb, and the table names carry *PREFIX* so the connection's
		// textual prefix replacement covers the correlation too.
		$membership = [
			sprintf(
				"(`tc`.`kind` = 'user' AND `tc`.`ref` = %s)",
				$qb->createNamedParameter($criteria->uid)
			),
		];
		if ($criteria->groupIds !== []) {
			$membership[] = sprintf(
				"(`tc`.`kind` = 'group' AND `tc`.`ref` IN (%s))",
				(string)$qb->createNamedParameter($criteria->groupIds, IQueryBuilder::PARAM_STR_ARRAY)
			);
		}

		return $qb->createFunction(
			sprintf(
				'EXISTS (SELECT 1 FROM `*PREFIX*openregister_task_candidates` `tc` '
				. 'WHERE `tc`.`task_id` = `*PREFIX*%s`.`id` AND (%s))',
				$this->getTableName(),
				implode(' OR ', $membership)
			)
		);
	}//end candidateMembershipPredicate()

	/**
	 * The caller is on the task's watcher list.
	 *
	 * A LIKE over the JSON column: watchers confer READ visibility only and
	 * are not the inbox's hot path, so the readable record is authoritative
	 * here rather than an index table.
	 *
	 * @param IQueryBuilder $qb The query under construction.
	 * @param string $uid The caller.
	 *
	 * @return string The LIKE predicate.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	private function watcherPredicate(IQueryBuilder $qb, string $uid): string {
		$needle = '%"' . $this->db->escapeLikeParameter($uid) . '"%';

		return (string)$qb->expr()->like('watchers', $qb->createNamedParameter($needle));
	}//end watcherPredicate()

	/**
	 * Sorting, in the datastore.
	 *
	 * Priority is a vocabulary, not an alphabet, so it sorts through a CASE
	 * expression rather than lexically (which would put `urgent` last).
	 *
	 * @param IQueryBuilder $qb The query under construction.
	 * @param TaskInboxCriteria $criteria Carries sort field and direction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function applyInboxOrder(IQueryBuilder $qb, TaskInboxCriteria $criteria): void {
		$direction = 'ASC';
		if ($criteria->sortDescending === true) {
			$direction = 'DESC';
		}

		switch ($criteria->sort) {
			case TaskInboxCriteria::SORT_PRIORITY:
				$qb->orderBy(
					$qb->createFunction(
						"CASE `priority` WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END"
					),
					$direction
				);
				break;
			case TaskInboxCriteria::SORT_CREATED:
				$qb->orderBy('created', $direction);
				break;
			default:
				$qb->orderBy('due_at', $direction);
				break;
		}

		// A stable tiebreak so pagination never shows a row twice.
		$qb->addOrderBy('id', 'ASC');
	}//end applyInboxOrder()
}//end class
