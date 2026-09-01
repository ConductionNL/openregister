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
use InvalidArgumentException;
<<<<<<< HEAD
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
=======
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
>>>>>>> origin/development
use OCP\IDBConnection;

/**
 * Reads and writes tasks.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) A mapper's public methods
 * are its query vocabulary, one per distinct question the service and the
 * inbox ask of the table (same reasoning as FlowRunMapper); two of them
 * (`watchersAsText`, `candidateMembershipSql`) are public so the
 * platform-dependent SQL is unit-testable without a database.
 *
 * @template-extends QBMapper<Task>
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
<<<<<<< HEAD
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Two over the threshold
 * since the terminality announcement (flow-business-timers D-9) joined both
 * write paths; the branches are the inbox predicates, which are the tenant
 * boundary, plus that one dispatch.
=======
>>>>>>> origin/development
 */
class TaskMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
<<<<<<< HEAD
	 * @param IEventDispatcher|null $dispatcher Publishes task terminality
	 *                                          ({@see TaskTerminalEvent}) so
	 *                                          business timers are cancelled in
	 *                                          the same operation. Nullable so
	 *                                          the mapper stays constructible
	 *                                          without a container.
	 */
	public function __construct(
		IDBConnection $db,
		private readonly ?IEventDispatcher $dispatcher = null,
	) {
=======
	 */
	public function __construct(IDBConnection $db) {
>>>>>>> origin/development
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
		if ($entity instanceof Task === false) {
			throw new InvalidArgumentException('TaskMapper persists Task entities only.');
		}

		if ($entity->getCreated() === null) {
			$entity->setCreated(new DateTime());
		}

		return parent::insert(entity: $entity);
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
		if ($entity instanceof Task === false) {
			throw new InvalidArgumentException('TaskMapper persists Task entities only.');
		}

		$entity->setUpdated(new DateTime());

<<<<<<< HEAD
		$updated = parent::update(entity: $entity);
		$this->announceTerminality(task: $updated);

		return $updated;
	}//end update()

	/**
	 * Announce a terminal write (flow-business-timers D-9).
	 *
	 * Called from BOTH persistence paths — {@see update()} and
	 * {@see updateIfOpen()} — so the two choke points every terminal task
	 * write passes both dispatch, inside the caller's transaction, and a
	 * listener cancelling the task's business timers does so in the same
	 * operation that made the subject terminal. Idempotent listeners only:
	 * the event can fire more than once for one task.
	 *
	 * @param Task $task The task as persisted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	private function announceTerminality(Task $task): void {
		if ($this->dispatcher === null || $task->isInTerminalState() === false) {
			return;
		}

		$this->dispatcher->dispatchTyped(
			new TaskTerminalEvent(
				taskUuid: (string)$task->getUuid(),
				state: (string)$task->getState(),
				outcome: $task->getOutcome()
			)
		);
	}//end announceTerminality()

	/**
=======
		return parent::update(entity: $entity);
	}//end update()

	/**
>>>>>>> origin/development
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
	 * Update a task ONLY while it is still open: the conditional write every
	 * state-changing verb goes through.
	 *
	 * Two completions, or a completion and a cancellation, can both pass the
	 * in-memory terminality check and then race to the row. This statement
	 * carries `AND is_terminal = false`, so the database lets exactly one
	 * through and the other affects no row; the SERVICE turns that into a
	 * conflict rather than letting the second outcome overwrite the first.
	 *
	 * Mirrors QBMapper::update() field by field (updated fields only) with
	 * the extra predicate; a row id is required, as there.
	 *
	 * @param Task $task The task, with its setters already applied.
	 *
	 * @return boolean True when the open row was updated; false when the
	 *                 task had already been closed by someone else.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function updateIfOpen(Task $task): bool {
		$id = $task->getId();
		if ($id === null) {
			throw new InvalidArgumentException('A task must be persisted before it can be updated.');
		}

		$task->setUpdated(new DateTime());
		$properties = $task->getUpdatedFields();
		unset($properties['id']);

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName());
		foreach (array_keys($properties) as $property) {
			$getter = 'get' . ucfirst($property);
			$qb->set(
				$task->propertyToColumn(property: $property),
				$qb->createNamedParameter($task->$getter(), $this->getParameterTypeForProperty(entity: $task, property: $property))
			);
		}

		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_terminal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

<<<<<<< HEAD
		$won = ($qb->executeStatement() === 1);
		if ($won === true) {
			$this->announceTerminality(task: $task);
		}

		return $won;
=======
		return $qb->executeStatement() === 1;
>>>>>>> origin/development
	}//end updateIfOpen()

	/**
	 * The `watchers` JSON column, readable as text on every platform.
	 *
	 * `Types::JSON` creates a `json` column on PostgreSQL, and `json LIKE
	 * text` is not an operator there (`operator does not exist: json ~~
	 * unknown`): without this cast every non-admin inbox request 500s on
	 * PostgreSQL. MySQL/MariaDB and SQLite cast with `AS CHAR`, PostgreSQL
	 * with `AS TEXT`, so the choice is by platform, the way MagicMapper does
	 * it for its metadata columns.
	 *
	 * @return string The platform-correct cast expression.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public function watchersAsText(): string {
		$column = $this->quote(identifier: 'watchers');
		if ($this->isPostgres() === true) {
			return sprintf('CAST(%s AS TEXT)', $column);
		}

		return sprintf('CAST(%s AS CHAR)', $column);
	}//end watchersAsText()

	/**
	 * The correlated EXISTS over the candidate index, as SQL text.
	 *
	 * Public and parameter-agnostic so the shape is unit-testable: the
	 * caller supplies the placeholders it created on its own builder. Every
	 * kind the index holds is matched — a uid against `user`, the caller's
	 * groups against `group` AND against `role` (a role names the group of
	 * the same name, exactly as TaskAuthorizationService resolves it), so a
	 * role-only pool is visible to the people who may claim from it.
	 *
	 * @param string $uidPlaceholder The named parameter holding the caller's uid.
	 * @param string|null $groupsPlaceholder The named parameter holding the
	 *                                       caller's group ids, or null when
	 *                                       the caller has none.
	 *
	 * @return string The EXISTS predicate.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public function candidateMembershipSql(string $uidPlaceholder, ?string $groupsPlaceholder): string {
		$kind = $this->quote(identifier: 'tc.kind');
		$ref = $this->quote(identifier: 'tc.ref');
		$membership = [
			sprintf("(%s = 'user' AND %s = %s)", $kind, $ref, $uidPlaceholder),
		];
		if ($groupsPlaceholder !== null) {
			$membership[] = sprintf("(%s = 'group' AND %s IN (%s))", $kind, $ref, $groupsPlaceholder);
			$membership[] = sprintf("(%s = 'role' AND %s IN (%s))", $kind, $ref, $groupsPlaceholder);
		}

		return sprintf(
			'EXISTS (SELECT 1 FROM %s %s WHERE %s = %s AND (%s))',
			$this->quote(identifier: '*PREFIX*openregister_task_candidates'),
			$this->quote(identifier: 'tc'),
			$this->quote(identifier: 'tc.task_id'),
			$this->quote(identifier: '*PREFIX*' . $this->getTableName() . '.id'),
			implode(' OR ', $membership)
		);
	}//end candidateMembershipSql()

	/**
	 * Whether the connection speaks PostgreSQL.
	 *
	 * @return boolean True on PostgreSQL.
	 */
	private function isPostgres(): bool {
		return stripos($this->db->getDatabasePlatform()::class, 'PostgreSQL') !== false;
	}//end isPostgres()

	/**
	 * Quote a (possibly dotted) identifier the way THIS platform wants it.
	 *
	 * Raw SQL handed to createFunction() bypasses the query builder's
	 * quoting, and a backtick is a syntax error on PostgreSQL, so every
	 * identifier in raw SQL goes through the platform's own quoter.
	 *
	 * @param string $identifier `column`, `alias.column` or `*PREFIX*table`.
	 *
	 * @return string The quoted identifier.
	 */
	private function quote(string $identifier): string {
		$platform = $this->db->getDatabasePlatform();
		$parts = [];
		foreach (explode('.', $identifier) as $part) {
			$parts[] = $platform->quoteIdentifier($part);
		}

		return implode('.', $parts);
	}//end quote()

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
		$this->applyScope(qb: $qb, criteria: $criteria);
		$this->applyVisibility(qb: $qb, criteria: $criteria);
		$this->applyFilters(qb: $qb, criteria: $criteria);
	}//end applyInboxPredicates()

	/**
	 * The scope half of the predicate: which relationship the list is about.
	 *
	 * @param IQueryBuilder $qb The query under construction.
	 * @param TaskInboxCriteria $criteria Carries the scope and identity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function applyScope(IQueryBuilder $qb, TaskInboxCriteria $criteria): void {
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
				// SCOPE_ALL: everything the caller may see; visibility decides.
				break;
		}//end switch
	}//end applyScope()

	/**
	 * The visibility half: an administrator sees everything; anyone else
	 * sees a task only through one of the five sanctioned relationships.
	 *
	 * @param IQueryBuilder $qb The query under construction.
	 * @param TaskInboxCriteria $criteria Carries the identity facts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function applyVisibility(IQueryBuilder $qb, TaskInboxCriteria $criteria): void {
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

	}//end applyVisibility()

	/**
	 * The filter half — every filter in the WHERE clause, none in PHP.
	 *
	 * @param IQueryBuilder $qb The query under construction.
	 * @param TaskInboxCriteria $criteria Carries the filters.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function applyFilters(IQueryBuilder $qb, TaskInboxCriteria $criteria): void {
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
					sprintf(
						'COALESCE(%s, %s) < %s',
						$this->quote(identifier: 'due_at'),
						$this->quote(identifier: 'expires_at'),
						$qb->createNamedParameter($criteria->overdueAt, IQueryBuilder::PARAM_DATETIME_MUTABLE)
					)
				)
			);
		}
	}//end applyFilters()

	/**
	 * The caller is in the task's candidate pool (by uid or by group).
	 *
	 * An EXISTS over the candidate INDEX table — the reason that table
	 * exists — so pooled visibility is an index hit, not a JSON scan.
	 *
	 * @param IQueryBuilder $qb The query under construction.
	 * @param TaskInboxCriteria $criteria Carries the uid and group ids.
	 *
	 * @return \OCP\DB\QueryBuilder\IQueryFunction The EXISTS predicate.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function candidateMembershipPredicate(IQueryBuilder $qb, TaskInboxCriteria $criteria): \OCP\DB\QueryBuilder\IQueryFunction {
		// Parameters are created on the OUTER builder so the correlated
		// subquery shares its parameter bag; the SQL shape itself lives in
		// candidateMembershipSql() where a test can read it.
		$groups = null;
		if ($criteria->groupIds !== []) {
			$groups = (string)$qb->createNamedParameter($criteria->groupIds, IQueryBuilder::PARAM_STR_ARRAY);
		}

		return $qb->createFunction(
			$this->candidateMembershipSql(
				uidPlaceholder: (string)$qb->createNamedParameter($criteria->uid),
				groupsPlaceholder: $groups
			)
		);
	}//end candidateMembershipPredicate()

	/**
	 * The caller is on the task's watcher list.
	 *
	 * A LIKE over the JSON column cast to text (see watchersAsText()):
	 * watchers confer READ visibility only and
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

		return (string)$qb->createFunction(
			sprintf('%s LIKE %s', $this->watchersAsText(), $qb->createNamedParameter($needle))
		);
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
						sprintf(
							"CASE %s WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END",
							$this->quote(identifier: 'priority')
						)
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
