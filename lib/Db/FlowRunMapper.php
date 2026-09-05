<?php

/**
 * Persistence for flow runs.
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
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;

/**
 * Reads and writes flow runs.
 *
 * A mapper's public methods are its query vocabulary: each one is a distinct
 * question the scheduler, the worker or retention asks of the run table, and
 * they exist as named methods precisely so those questions are not rebuilt as
 * ad-hoc query builders at each call site.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.TooManyMethods)     Over by four since the subject-scoped
 * reads of `flow-runs-subject-scope`, for the same reason: each public method is
 * a distinct question with its own predicate set, and the private helpers behind
 * the new reads exist so a row read and its count cannot drift apart on what
 * they filter. Splitting the table across two mappers would hide the count, not
 * the vocabulary.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Over the line budget by the same
 * change. Most of the length is the docblocks that record WHY each query carries
 * the predicates it does; those predicates are tenant boundaries, and trimming
 * the reasoning to fit a line count is the wrong trade.
 *
 * @template-extends QBMapper<FlowRun>
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 * @spec openspec/changes/or-flow-queue-fairness/specs/flow-queue-fairness/spec.md
 */
class FlowRunMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 * @param IEventDispatcher|null $dispatcher Publishes run terminality
	 *                                          ({@see FlowRunTerminalEvent}).
	 *                                          Nullable so the mapper stays
	 *                                          constructible without a
	 *                                          container; absent, terminality
	 *                                          simply goes unannounced.
	 */
	public function __construct(
		IDBConnection $db,
		private readonly ?IEventDispatcher $dispatcher = null,
	) {
		parent::__construct(db: $db, tableName: 'openregister_flow_runs', entityClass: FlowRun::class);

	}//end __construct()

	/**
	 * Update, announcing terminality.
	 *
	 * This is the ONE choke point every terminal status write passes — the
	 * engine's persist, the worker's failure paths and the stale-run reaper
	 * all land here — so the terminal event is dispatched from it rather
	 * than from each of those sites, where a new failure path could forget
	 * it. The event can therefore fire more than once for one run; its
	 * listeners are idempotent by contract (see the event's docblock).
	 *
	 * Dispatched AFTER the row is persisted: a listener that reads the run
	 * back must see the terminal status it was told about.
	 *
	 * @param Entity $entity The run to update.
	 *
	 * @return FlowRun The updated run.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
	 */
	public function update(Entity $entity): FlowRun {
		if ($entity instanceof FlowRun === false) {
			throw new InvalidArgumentException('FlowRunMapper persists FlowRun entities only.');
		}

		$updated = parent::update(entity: $entity);

		if ($this->dispatcher !== null && $updated->isTerminal() === true) {
			$this->dispatcher->dispatchTyped(
				new FlowRunTerminalEvent(
					runUuid: (string)$updated->getUuid(),
					status: (string)$updated->getStatus()
				)
			);
		}

		return $updated;
	}//end update()

	/**
	 * Find a run by its public uuid.
	 *
	 * @param string $uuid The run uuid.
	 *
	 * @return FlowRun The run.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such run exists.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function findByUuid(string $uuid): FlowRun {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * The suspended runs carrying a correlation key, capped at two.
	 *
	 * Two is all a fail-closed resolution needs (flow-approval-consolidation
	 * design D-7): zero is "not found", one is the addressee, and a second
	 * row already proves ambiguity, which refuses without caring whether a
	 * third exists. Only SUSPENDED runs are consulted, on the
	 * (status, correlation_key) index.
	 *
	 * @param string $correlationKey The business key.
	 *
	 * @return array<int, FlowRun> Zero, one or two suspended runs.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-the-signal-node-keeps-machine-to-machine-work-and-gains-a-correlation-key
	 */
	public function findSuspendedByCorrelationKey(string $correlationKey): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_SUSPENDED)))
			->andWhere($qb->expr()->eq('correlation_key', $qb->createNamedParameter($correlationKey)))
			->orderBy('id', 'ASC')
			->setMaxResults(2);

		return $this->findEntities(query: $qb);
	}//end findSuspendedByCorrelationKey()

	/**
	 * Read one run FOR UPDATE — the critical section's lock.
	 *
	 * Must be called inside a transaction; the lock lives exactly as long as
	 * that transaction. Everything FlowRunCommit writes is computed from the
	 * row this returns, never from a value read before the lock was taken.
	 *
	 * @param string $uuid The run uuid.
	 *
	 * @return FlowRun The locked run.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When there is no such run.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function lockByUuid(string $uuid): FlowRun {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->forUpdate();

		return $this->findEntity(query: $qb);
	}//end lockByUuid()

	/**
	 * List runs, newest first.
	 *
	 * VISIBILITY. `$requesterUid` is the scoping switch, and it is deliberately
	 * required-by-convention rather than optional-by-default: passing null means
	 * "no scoping", which is correct only for an administrator or a system read.
	 * Until this parameter existed the method had no scoping at ALL, so
	 * `GET /api/flow-runs` returned every run on the instance to any
	 * authenticated caller — including each run's log, which records the subject
	 * data the flow touched. That is precisely what design D7 of
	 * `shared-credentials-and-flows` exists to prevent.
	 *
	 * When scoping IS applied, a run is visible if the caller triggered it OR it
	 * belongs to a flow the caller owns. The second disjunct matters because
	 * `triggered_by` is NULL for cron- and trigger-fired runs, so a
	 * "only runs you triggered" rule would hide every automated run from the
	 * flow's own owner.
	 *
	 * An empty `$ownedFlowIds` is NOT the same as null: it means "the caller owns
	 * no flows", and the predicate must then reduce to `triggered_by = uid`
	 * rather than silently dropping the whole disjunction and matching nothing —
	 * or, worse, matching everything.
	 *
	 * @param string|null $flowId Restrict to one flow.
	 * @param string|null $status Restrict to one status.
	 * @param integer $limit Page size.
	 * @param integer $offset Page offset.
	 * @param string|null $requesterUid The caller, or null to apply NO scoping
	 *                                  (administrators and system reads only).
	 * @param array<int, string> $ownedFlowIds Flow ids the caller owns; runs of these
	 *                                         are visible regardless of who triggered them.
	 *
	 * @return array<int, FlowRun> The runs.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function findAllRuns(
		?string $flowId = null,
		?string $status = null,
		int $limit = 50,
		int $offset = 0,
		?string $requesterUid = null,
		array $ownedFlowIds = [],
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		if ($flowId !== null && $flowId !== '') {
			$qb->andWhere($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)));
		}

		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		$this->scopeToVisible(qb: $qb, requesterUid: $requesterUid, ownedFlowIds: $ownedFlowIds);

		return $this->findEntities(query: $qb);
	}//end findAllRuns()

	/**
	 * Narrow a run query to what one caller may see.
	 *
	 * Extracted so the LIST and the SINGLE-RUN reads apply the same predicate
	 * rather than each carrying its own copy. Two copies of one access rule do
	 * not stay identical, and the copy that drifts is the one nobody is looking
	 * at — a divergence here does not throw, it just answers with somebody
	 * else's run, correctly formatted, HTTP 200.
	 *
	 * A null `$requesterUid` applies NO scoping. That is an administrator's
	 * semantics and must never be reached by falling through from a missing
	 * session; callers resolve "no identity" to a refusal before calling.
	 *
	 * @param IQueryBuilder      $qb           The query being built.
	 * @param string|null        $requesterUid The caller, or null for no scoping.
	 * @param array<int, string> $ownedFlowIds Flow ids the caller owns.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	private function scopeToVisible(IQueryBuilder $qb, ?string $requesterUid, array $ownedFlowIds): void {
		if ($requesterUid === null) {
			return;
		}

		$visible = $qb->expr()->orX(
			$qb->expr()->eq('triggered_by', $qb->createNamedParameter($requesterUid))
		);

		if (empty($ownedFlowIds) === false) {
			$visible->add(
				$qb->expr()->in(
					'flow_id',
					$qb->createNamedParameter($ownedFlowIds, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);
		}

		$qb->andWhere($visible);
	}//end scopeToVisible()

	/**
	 * The suspended runs whose subject is this object.
	 *
	 * "Which run is waiting on this thing" is the question a leaf app asks when
	 * something outside the engine finishes — a decision concluded, a document
	 * signed — and needs to wake whatever was waiting for it.
	 *
	 * Deliberately narrowed to SUSPENDED. A completed or failed run is not
	 * waiting for anything, and signalling one would be a no-op at best and a
	 * second advance of a finished run at worst.
	 *
	 * This does NOT scope by caller: it is an engine-side lookup used to route
	 * an external outcome, not a user-facing read. Callers that expose anything
	 * derived from it must apply their own visibility rule.
	 *
	 * @param string  $subjectUuid The subject object's uuid.
	 * @param integer $limit       Maximum runs to return.
	 *
	 * @return FlowRun[] The suspended runs for that subject, oldest first.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function findSuspendedBySubject(string $subjectUuid, int $limit = 25): array {
		if (trim($subjectUuid) === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('subject_uuid', $qb->createNamedParameter($subjectUuid)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_SUSPENDED)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findSuspendedBySubject()

	/**
	 * Find one run by uuid, but only if this caller may see it.
	 *
	 * Returns null both when the run does not exist and when it exists but is
	 * not the caller's, so the caller cannot distinguish the two — the absence
	 * of a run and the absence of permission look identical from outside, which
	 * is what stops the endpoint being a probe for which runs exist.
	 *
	 * @param string             $uuid         The run uuid.
	 * @param string|null        $requesterUid The caller, or null for an administrator.
	 * @param array<int, string> $ownedFlowIds Flow ids the caller owns.
	 *
	 * @return FlowRun|null The run, or null when it does not exist or is not visible.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function findByUuidVisibleTo(string $uuid, ?string $requesterUid, array $ownedFlowIds = []): ?FlowRun {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->setMaxResults(1);

		$this->scopeToVisible(qb: $qb, requesterUid: $requesterUid, ownedFlowIds: $ownedFlowIds);

		$found = $this->findEntities(query: $qb);
		if ($found === []) {
			return null;
		}

		return $found[0];
	}//end findByUuidVisibleTo()

	/**
	 * Delete terminal runs older than a cutoff, optionally for one flow only.
	 *
	 * Only TERMINAL runs are swept. A `queued` or `suspended` run is work that
	 * has not happened yet — a flow waiting on a timer can legitimately be
	 * older than the retention window, and deleting it would silently cancel
	 * it rather than expire its history.
	 *
	 * `$flowId` is what makes a per-flow override work: the sweep applies the
	 * instance cutoff to everything EXCEPT the flows declaring their own, then
	 * applies each of those flows' cutoff by id.
	 *
	 * @param DateTime $cutoff Runs updated before this are removed.
	 * @param string|null $flowId Restrict the deletion to one flow.
	 *
	 * @return array<int, string> The uuids of the deleted runs, so their step
	 *                            rows can be removed too.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function deleteTerminalOlderThan(DateTime $cutoff, ?string $flowId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uuid')
			->from($this->getTableName())
			->where($qb->expr()->lt('updated', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)))
			->andWhere(
				$qb->expr()->in(
					'status',
					$qb->createNamedParameter(FlowRun::TERMINAL, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		if ($flowId !== null && $flowId !== '') {
			$qb->andWhere($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)));
		}

		$result = $qb->executeQuery();
		$uuids = [];
		while (($row = $result->fetch()) !== false) {
			$uuids[] = (string)$row['uuid'];
		}

		$result->closeCursor();

		if (empty($uuids) === true) {
			return [];
		}

		$del = $this->db->getQueryBuilder();
		$del->delete($this->getTableName())
			->where(
				$del->expr()->in('uuid', $del->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY))
			);
		$del->executeStatement();

		return $uuids;
	}//end deleteTerminalOlderThan()

	/**
	 * Mark a running run as still alive.
	 *
	 * The stale reaper fails any run left `running` with an `updated` older than
	 * its threshold, on the stated premise that "a pass that is still going has
	 * touched its row far more recently than this". Nothing made that true:
	 * `updated` was written once when the run entered `running` and not again
	 * until it finished, so the reaper was not measuring liveness at all — it was
	 * measuring how long the run had been going. Any walk longer than the
	 * threshold was failed underneath a healthy executor, which then carried on
	 * and completed. Measured on the dev instance at low load: a run started
	 * 09:00:56 was marked abandoned 09:20:03 and went on to import every record.
	 *
	 * This is the write that makes the premise true. It is deliberately a narrow
	 * UPDATE rather than an entity save: the executor holds a FlowRun loaded
	 * before the walk, and persisting that would write back its whole stale row.
	 *
	 * @param string $uuid The run uuid.
	 * @param DateTime $when The moment to record.
	 *
	 * @return boolean Whether a running row was updated.
	 *
	 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
	 */
	public function touch(string $uuid, DateTime $when): bool {
		if ($uuid === '') {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('updated', $qb->createNamedParameter($when, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			// Only while it is RUNNING. Without this a late beat from an executor
			// that has already been reaped would push `updated` forward on a row
			// the reaper has marked failed, hiding the abandonment it just
			// recorded — and a beat arriving after a legitimate finish would
			// disturb a terminal row for no reason.
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_RUNNING)));

		return $qb->executeStatement() > 0;
	}//end touch()

	/**
	 * Delete every run of one flow, returning their uuids.
	 *
	 * @param string $flowId The flow uuid.
	 *
	 * @return array<int, string> The deleted runs' uuids.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function deleteByFlow(string $flowId): array {
		if ($flowId === '') {
			// Never let an empty id widen into "every run on the instance".
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)));

		$result = $qb->executeQuery();
		$uuids = [];
		while (($row = $result->fetch()) !== false) {
			$uuids[] = (string)$row['uuid'];
		}

		$result->closeCursor();

		if (empty($uuids) === true) {
			return [];
		}

		$del = $this->db->getQueryBuilder();
		$del->delete($this->getTableName())
			->where(
				$del->expr()->in('uuid', $del->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY))
			);
		$del->executeStatement();

		return $uuids;
	}//end deleteByFlow()

	/**
	 * Delete terminal runs older than a cutoff, EXCLUDING a set of flows.
	 *
	 * The instance-wide half of the sweep: every flow that does not declare its
	 * own retention.
	 *
	 * @param DateTime $cutoff Runs updated before this are removed.
	 * @param array<int, string> $excludeFlowIds Flow ids with their own retention.
	 *
	 * @return array<int, string> The uuids of the deleted runs.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function deleteTerminalOlderThanExcluding(DateTime $cutoff, array $excludeFlowIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uuid')
			->from($this->getTableName())
			->where($qb->expr()->lt('updated', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)))
			->andWhere(
				$qb->expr()->in(
					'status',
					$qb->createNamedParameter(FlowRun::TERMINAL, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		if (empty($excludeFlowIds) === false) {
			$qb->andWhere(
				$qb->expr()->notIn(
					'flow_id',
					$qb->createNamedParameter($excludeFlowIds, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);
		}

		$result = $qb->executeQuery();
		$uuids = [];
		while (($row = $result->fetch()) !== false) {
			$uuids[] = (string)$row['uuid'];
		}

		$result->closeCursor();

		if (empty($uuids) === true) {
			return [];
		}

		$del = $this->db->getQueryBuilder();
		$del->delete($this->getTableName())
			->where(
				$del->expr()->in('uuid', $del->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY))
			);
		$del->executeStatement();

		return $uuids;
	}//end deleteTerminalOlderThanExcluding()

	/**
	 * The runs that are still going, newest first.
	 *
	 * "Still going" is every NON-terminal status — `queued` (about to start),
	 * `running` (executing now) and `suspended` (mid-graph, waiting on a timer
	 * or a child run). A dashboard that showed only `running` would be empty
	 * almost all of the time: a run holds that status for the duration of one
	 * worker pass, while `queued` and `suspended` are where a run actually
	 * spends its wall-clock.
	 *
	 * Scoping is STRICT: pass an organisation and only that organisation's runs
	 * come back. A run recorded before runs carried an organisation (or queued
	 * with no session to attribute it to) has none, and is therefore returned
	 * to nobody — deliberately, since this feeds a widget every app renders to
	 * every user, and guessing a tenant for an unattributed run would leak one
	 * tenant's activity into another's dashboard.
	 *
	 * The subject filter only ever NARROWS. It is a second predicate on
	 * `subject_uuid` added NEXT TO the organisation predicate, never instead of
	 * it: a subject uuid is guessable, so a run in another organisation that
	 * happens to carry the asked-for subject must stay as invisible as it is
	 * without the filter. Filtering here, in the datastore, is also what keeps
	 * the total honest: a caller that filtered a bounded page client-side would
	 * silently drop every matching run the page did not contain.
	 *
	 * @param string|null $organisation Restrict to one organisation uuid.
	 * @param integer $limit Page size.
	 * @param string|null $subject Restrict to runs anchored to one subject object uuid.
	 *
	 * @return array<int, FlowRun> The non-terminal runs.
	 *
	 * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	public function findActive(?string $organisation = null, int $limit = 25, ?string $subject = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->in(
					'status',
					$qb->createNamedParameter(FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY)
				)
			)
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		if ($organisation !== null && $organisation !== '') {
			$qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation)));
		}

		$this->narrowToSubject(qb: $qb, subject: $subject);

		return $this->findEntities(query: $qb);
	}//end findActive()

	/**
	 * Whether one flow already has a run that has not finished.
	 *
	 * A SCHEDULED flow can be slower than its own interval — a hydra-shaped
	 * pipeline poll easily outlives five minutes — and `fireDueFlows()` has no
	 * overlap guard of its own, so tick N+1 would start while tick N is still
	 * going. Two runs of the same flow then race on whatever that flow is
	 * bookkeeping.
	 *
	 * This is the query that lets the scheduler refuse to do that. It is
	 * deliberately the same NON-terminal definition {@see findActive} uses:
	 * `queued`, `running` and `suspended` all mean "still going". A guard that
	 * only looked at `running` would let a suspended run be overlapped, which
	 * is precisely the long-lived state a slow flow spends its time in.
	 *
	 * Cheap on purpose — a count with a limit, not a fetch. The scheduler asks
	 * this once per due flow on every cron tick.
	 *
	 * Counting `queued` has a sharp edge worth knowing about: a run that is
	 * STARVED in the queue reads as "still going", so the guard refuses every
	 * later tick of that flow and the whole schedule stops. That is a real
	 * failure, not a hypothetical — it is why queued runs now expire
	 * ({@see expireQueuedBefore}) rather than waiting indefinitely.
	 *
	 * @param string $flowId The flow's uuid.
	 *
	 * @return boolean True when a non-terminal run exists for this flow.
	 *
	 * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
	 * @spec openspec/changes/or-flow-queue-fairness/specs/flow-queue-fairness/spec.md
	 */
	public function hasActiveRun(string $flowId): bool {
		if (trim($flowId) === '') {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)))
			->andWhere(
				$qb->expr()->in(
					'status',
					$qb->createNamedParameter(FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY)
				)
			)
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row !== false;
	}//end hasActiveRun()

	/**
	 * How many runs this flow has EVER had, terminal ones included.
	 *
	 * {@see hasActiveRun} answers a different question — whether a run is going
	 * right now — and a flow that ran to completion last year has none. Deleting
	 * a flow is irreversible, so the guard that authorises it has to ask about
	 * the flow's whole history, not its current activity.
	 *
	 * Used by {@see \OCA\OpenRegister\Repair\PurgePhantomMigratedFlows} as one
	 * conjunct of the never-dispatched proof: a row with even one run row has
	 * been part of something that happened and is never removed.
	 *
	 * @param string $flowId The flow's uuid.
	 *
	 * @return integer The number of run rows recorded against this flow.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function countRunsForFlow(string $flowId): int {
		if (trim($flowId) === '') {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*) AS `total`'))
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['total'] ?? 0);
	}//end countRunsForFlow()

	/**
	 * How many runs are still going, for one organisation.
	 *
	 * Separate from {@see findActive} because a widget shows a bounded list but
	 * an honest total — "3 of 47 running" needs the 47, and paging the whole
	 * set to count it would be absurd.
	 *
	 * Takes the same subject filter as {@see findActive}, applied the same
	 * way, so the total a widget states is the total of the rows it filtered
	 * rather than of a set the caller never asked for.
	 *
	 * @param string|null $organisation Restrict to one organisation uuid.
	 * @param string|null $subject Restrict to runs anchored to one subject object uuid.
	 *
	 * @return integer The number of non-terminal runs.
	 *
	 * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	public function countActive(?string $organisation = null, ?string $subject = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*) AS `total`'))
			->from($this->getTableName())
			->where(
				$qb->expr()->in(
					'status',
					$qb->createNamedParameter(FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		if ($organisation !== null && $organisation !== '') {
			$qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation)));
		}

		$this->narrowToSubject(qb: $qb, subject: $subject);

		return $this->fetchTotal(qb: $qb);
	}//end countActive()

	/**
	 * The finished runs anchored to ONE subject object, newest first.
	 *
	 * A case page's "what already ran here". The read is deliberately narrow
	 * in a way the history surface (`findAllRuns`) is not: the subject is
	 * REQUIRED and the organisation is REQUIRED, and both are equality
	 * predicates in the datastore. There is no org-wide "all finished runs"
	 * on this path; a caller who wants that has the history endpoint, with
	 * its own per-caller visibility rule. Widening THIS read would widen run
	 * visibility through the back door, which `or-flow-active-runs` forbids.
	 *
	 * Fails CLOSED on a missing predicate: an empty organisation or subject
	 * returns nothing without a query rather than dropping the predicate and
	 * answering a wider question than was asked.
	 *
	 * @param string $organisation The caller's organisation uuid.
	 * @param string $subject The subject object uuid.
	 * @param integer $limit Page size.
	 *
	 * @return array<int, FlowRun> The terminal runs, newest first.
	 *
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	public function findCompletedForSubject(string $organisation, string $subject, int $limit = 25): array {
		if ($organisation === '' || $subject === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		$this->whereTerminalForSubject(qb: $qb, organisation: $organisation, subject: $subject);

		return $this->findEntities(query: $qb);
	}//end findCompletedForSubject()

	/**
	 * How many finished runs one subject object has, for the honest total.
	 *
	 * Same predicates as {@see findCompletedForSubject}, so "3 of 14 earlier
	 * runs" counts the set the rows came from.
	 *
	 * @param string $organisation The caller's organisation uuid.
	 * @param string $subject The subject object uuid.
	 *
	 * @return integer The number of terminal runs on the subject.
	 *
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	public function countCompletedForSubject(string $organisation, string $subject): int {
		if ($organisation === '' || $subject === '') {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*) AS `total`'))
			->from($this->getTableName());

		$this->whereTerminalForSubject(qb: $qb, organisation: $organisation, subject: $subject);

		return $this->fetchTotal(qb: $qb);
	}//end countCompletedForSubject()

	/**
	 * Add the subject predicate when a subject was asked for.
	 *
	 * One place for both the row read and the count, so the two cannot drift
	 * apart on what "this subject's runs" means. A null or empty subject adds
	 * nothing, which is what makes the unfiltered read bit-identical to before.
	 *
	 * @param IQueryBuilder $qb The query being built.
	 * @param string|null $subject The subject object uuid, or null for no filter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	private function narrowToSubject(IQueryBuilder $qb, ?string $subject): void {
		if ($subject === null || $subject === '') {
			return;
		}

		$qb->andWhere($qb->expr()->eq('subject_uuid', $qb->createNamedParameter($subject)));
	}//end narrowToSubject()

	/**
	 * The three predicates of the completed-runs read: terminal, this organisation, this subject.
	 *
	 * Order is a correctness statement, not an optimisation: the organisation
	 * predicate is unconditional, so a guessed subject uuid from another
	 * tenant matches zero rows rather than leaking one.
	 *
	 * @param IQueryBuilder $qb The query being built.
	 * @param string $organisation The caller's organisation uuid.
	 * @param string $subject The subject object uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	private function whereTerminalForSubject(IQueryBuilder $qb, string $organisation, string $subject): void {
		$qb->where(
			$qb->expr()->in(
				'status',
				$qb->createNamedParameter(FlowRun::TERMINAL, IQueryBuilder::PARAM_STR_ARRAY)
			)
		)
			->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation)))
			->andWhere($qb->expr()->eq('subject_uuid', $qb->createNamedParameter($subject)));
	}//end whereTerminalForSubject()

	/**
	 * Run a COUNT(*) query and return its one number.
	 *
	 * @param IQueryBuilder $qb A query selecting `COUNT(*) AS total`.
	 *
	 * @return integer The count.
	 *
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	private function fetchTotal(IQueryBuilder $qb): int {
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();

		return $total;
	}//end fetchTotal()

	/**
	 * Suspended runs that are due to resume.
	 *
	 * A run with no `resume_at` is waiting on something other than a clock — a
	 * child run, or a webhook — and is NOT picked up here. Resuming those on a
	 * timer would run them before whatever they are waiting for arrives.
	 *
	 * @param DateTime $now The current time.
	 * @param integer $limit Maximum runs to claim in one pass.
	 *
	 * @return array<int, FlowRun> The due runs.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function findDue(DateTime $now, int $limit = 25): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_SUSPENDED)))
			->andWhere($qb->expr()->isNotNull('resume_at'))
			->andWhere(
				$qb->expr()->lte(
					'resume_at',
					$qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			)
			->orderBy('resume_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findDue()

	/**
	 * Runs left in `running` by a worker pass that never came back.
	 *
	 * `execute()` sets `running` and clears it when the walk returns. A pass
	 * that dies instead — a fatal, a PHP timeout, an OOM, a container
	 * restart — never clears it, and no `catch` can help because the process
	 * is gone. The row then sits in `running` forever: the worker will not
	 * pick it up (it only reads `queued` and due `suspended` runs), so it is
	 * not just stale, it is unreachable.
	 *
	 * That was invisible while nothing read `running`. It stops being
	 * invisible the moment a dashboard widget shows live runs — 68 such rows
	 * existed on one dev instance, the oldest two days old, and every one of
	 * them would have read as "running right now".
	 *
	 * @param DateTime $before Runs not touched since this moment are stale.
	 * @param integer $limit Maximum runs to reap in one pass.
	 *
	 * @return array<int, FlowRun> The abandoned runs.
	 *
	 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
	 */
	public function findStale(DateTime $before, int $limit = 25): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_RUNNING)))
			->andWhere(
				$qb->expr()->lt(
					'updated',
					$qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			)
			->orderBy('updated', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findStale()

	/**
	 * Runs suspended on a SIGNAL that never came.
	 *
	 * The counterpart to {@see self::findStale()}, for the other status that can
	 * strand a run. `findDue()` deliberately skips suspended runs with no
	 * `resume_at`, because waking those on a clock would run them before the
	 * thing they are waiting for arrives. The consequence, until this existed,
	 * was that nothing looked at them at ALL: no query read them, so a signal
	 * that was never delivered — an approval nobody actioned, a webhook that
	 * fired at a dead endpoint, a child run that failed before it could report —
	 * left its run suspended forever.
	 *
	 * Forever is not the worst of it. `hasActiveRun()` counts `suspended`, so
	 * one such run also stops its flow from EVER being scheduled again. A
	 * missed signal silently retires the flow.
	 *
	 * These are FAILED rather than resumed, for the same reason
	 * {@see \OCA\OpenRegister\BackgroundJob\FlowRunWorker::reapStale()} fails rather than
	 * requeues: the run is mid-graph and resuming it would run the awaiting node
	 * as though its answer had arrived, when what actually happened is that
	 * nobody answered. Failing says that, and leaves retry to a person.
	 *
	 * @param DateTime $before Runs not touched since this moment have waited too long.
	 * @param integer $limit Maximum runs to claim in one pass.
	 *
	 * @return array<int, FlowRun> The abandoned runs.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
	 */
	public function findAbandonedSignals(DateTime $before, int $limit = 25): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_SUSPENDED)))
			->andWhere($qb->expr()->isNull('resume_at'))
			->andWhere(
				$qb->expr()->lt(
					'updated',
					$qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			)
			->orderBy('updated', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findAbandonedSignals()

	/**
	 * Runs parked waiting for somebody to allow a delegation.
	 *
	 * NO time predicate, unlike every other sweep here. A consent is not waiting
	 * on a clock: re-asking in five minutes is not how a person decides, and a
	 * parked run must be released the moment the answer lands rather than on the
	 * next tick of a timer that has nothing to do with it. The sweep re-resolves
	 * each run's delegation instead — the grant record IS the signal.
	 *
	 * Oldest first, so a run that has been waiting longest is released first when
	 * one answer frees several.
	 *
	 * @param integer $limit Maximum runs to claim in one pass.
	 *
	 * @return array<int, FlowRun> The parked runs.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function findAwaitingConsent(int $limit = 25): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq(
					'status',
					$qb->createNamedParameter(FlowRun::STATUS_AWAITING_CONSENT)
				)
			)
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findAwaitingConsent()

	/**
	 * Runs waiting to start, shared FAIRLY between the flows that are waiting.
	 *
	 * A single global FIFO — which is what this was — makes queue position a
	 * function of nothing but arrival order, so one flow that queues in bulk
	 * owns the entire queue until it drains. Measured on the dev instance
	 * 2026-08-02: ONE flow held 9,644 queued runs, and at 25 per cron pass a
	 * run queued behind them waited about thirty-two hours to start. Anything
	 * queued in that window — a schedule tick, a user pressing "run", a
	 * sub-flow — waited the same thirty-two hours, because FIFO cannot tell
	 * those apart from the burst.
	 *
	 * That is not a backlog that clears; it is a queue with no fairness
	 * property at all, and it returns the moment anything queues in bulk again.
	 *
	 * So the batch is divided between the flows that have work rather than
	 * handed to whoever arrived first: each waiting flow may take about
	 * `limit / flowCount` runs, oldest-first within the flow. The guarantee is
	 * the one FIFO lacks — NO FLOW CAN CONSUME MORE THAN ITS SHARE WHILE
	 * ANOTHER FLOW HAS WORK WAITING — so a flow that queues one run starts it
	 * on the next pass no matter how deep anyone else's backlog is.
	 *
	 * Flows are served oldest-waiting-first, and that ordering rotates by
	 * itself: serving a flow advances its oldest queued id, which moves it
	 * behind the flows it just went ahead of. Round-robin falls out of the
	 * ordering instead of needing a stored cursor.
	 *
	 * With exactly one flow waiting this is byte-for-byte the old behaviour —
	 * it takes the whole batch, oldest first — so the common case is unchanged.
	 *
	 * @param integer $limit Maximum runs to claim in one pass.
	 *
	 * @return array<int, FlowRun> The queued runs.
	 *
	 * @spec openspec/changes/or-flow-queue-fairness/specs/flow-queue-fairness/spec.md
	 */
	public function findQueued(int $limit = 25): array {
		if ($limit < 1) {
			return [];
		}

		$flowIds = $this->flowsWithQueuedRuns(limit: $limit);
		if ($flowIds === []) {
			return [];
		}

		// Ceil, not floor: with more waiting flows than batch slots a floor
		// would be zero and the pass would claim nothing at all.
		$share = (int)ceil($limit / count($flowIds));
		$runs = [];

		foreach ($flowIds as $flowId) {
			$remaining = ($limit - count($runs));
			if ($remaining < 1) {
				break;
			}

			foreach ($this->queuedForFlow(flowId: $flowId, limit: min($share, $remaining)) as $run) {
				$runs[] = $run;
			}
		}

		return $runs;
	}//end findQueued()

	/**
	 * The flows that have queued runs, the longest-waiting flow first.
	 *
	 * Ordered by each flow's OLDEST queued run, which is what makes the
	 * rotation self-maintaining: a flow that was just served has a newer oldest
	 * run and drops behind the others.
	 *
	 * Protected rather than private so the sharing rule in `findQueued()` can
	 * be tested for what it DECIDES, without a database standing in for the
	 * decision.
	 *
	 * @param integer $limit Maximum distinct flows to report.
	 *
	 * @return array<int, string> The flow ids.
	 *
	 * @spec openspec/changes/or-flow-queue-fairness/specs/flow-queue-fairness/spec.md
	 */
	protected function flowsWithQueuedRuns(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('flow_id')
			->selectAlias($qb->func()->min('id'), 'oldest_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_QUEUED)))
			->groupBy('flow_id')
			->orderBy('oldest_id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$flowIds = [];
		while (($row = $result->fetch()) !== false) {
			$flowIds[] = (string)$row['flow_id'];
		}

		$result->closeCursor();

		return $flowIds;
	}//end flowsWithQueuedRuns()

	/**
	 * One flow's queued runs, oldest first.
	 *
	 * @param string $flowId The flow id.
	 * @param integer $limit Maximum runs to return.
	 *
	 * @return array<int, FlowRun> The queued runs.
	 *
	 * @spec openspec/changes/or-flow-queue-fairness/specs/flow-queue-fairness/spec.md
	 */
	protected function queuedForFlow(string $flowId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_QUEUED)))
			->andWhere($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end queuedForFlow()

	/**
	 * Abandon runs that have waited in `queued` past their sell-by date.
	 *
	 * A queued run is an intention to do something NOW. Running a schedule tick
	 * from last Tuesday does not catch the flow up, it replays a decision
	 * against a world that has moved on — and a poll, a reminder or a sync that
	 * fires days late is usually worse than one that never fired, because
	 * nothing downstream expects it any more.
	 *
	 * FAILED rather than deleted, and failed one row at a time in capped
	 * batches rather than in a single sweeping statement. The status carries an
	 * explanation, the run stays visible on every surface that lists runs, and
	 * the existing retry endpoint turns it back into work if a person decides
	 * the tick still matters. That is the same call `reapStale()` makes for
	 * abandoned `running` rows, for the same reason: a cron job may say "this
	 * did not happen", never "this should happen anyway".
	 *
	 * This also unwedges the SCHEDULER. `hasActiveRun()` counts `queued`, so a
	 * starved run makes the singleton guard refuse every later tick of its own
	 * flow ({@see FlowScheduleService}) — one stuck run silently stops the
	 * whole schedule. Expiring it lets the next tick fire.
	 *
	 * @param DateTime $before Runs queued before this moment are stale.
	 * @param string $reason The error recorded on each expired run.
	 * @param integer $limit Maximum runs to expire in one pass.
	 *
	 * @return array<int, FlowRun> The runs that were expired.
	 *
	 * @spec openspec/changes/or-flow-queue-fairness/specs/flow-queue-fairness/spec.md
	 */
	public function expireQueuedBefore(DateTime $before, string $reason, int $limit = 500): array {
		if ($limit < 1) {
			return [];
		}

		$expired = [];
		$now = new DateTime();

		foreach ($this->queuedBefore(before: $before, limit: $limit) as $run) {
			// Re-checked rather than assumed: the row was read outside a
			// transaction and a worker pass may have started it since.
			if ($run->getStatus() !== FlowRun::STATUS_QUEUED) {
				continue;
			}

			$run->setStatus(FlowRun::STATUS_FAILED);
			$run->setError($reason);
			$run->setUpdated($now);
			$expired[] = $this->update(entity: $run);
		}

		return $expired;
	}//end expireQueuedBefore()

	/**
	 * Queued runs created before a cut-off, oldest first.
	 *
	 * @param DateTime $before The cut-off.
	 * @param integer $limit Maximum runs to return.
	 *
	 * @return array<int, FlowRun> The runs.
	 *
	 * @spec openspec/changes/or-flow-queue-fairness/specs/flow-queue-fairness/spec.md
	 */
	protected function queuedBefore(DateTime $before, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_QUEUED)))
			->andWhere(
				$qb->expr()->lt(
					'created',
					$qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			)
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end queuedBefore()

	/**
	 * Delete terminal runs older than a cut-off.
	 *
	 * Runs are operational data and grow without bound; this instance has
	 * already been taken down once by an unbounded log file. Only TERMINAL
	 * runs are eligible — deleting a suspended run would strand whatever it
	 * was waiting for.
	 *
	 * @param DateTime $before Delete terminal runs last updated before this.
	 *
	 * @return integer The number of runs deleted.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function pruneBefore(DateTime $before): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(FlowRun::TERMINAL, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere(
				$qb->expr()->lt(
					'updated',
					$qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			);

		return (int)$qb->executeStatement();
	}//end pruneBefore()

	/**
	 * How many runs that can still move are pinned to one version of a flow.
	 *
	 * Asked before a version may be removed. Counts only ACTIVE runs on
	 * purpose: a completed run's pin is history, and history keeping a version
	 * alive forever would make the version table unprunable. A run that can
	 * still move, though, needs its graph to still exist.
	 *
	 * @param string  $flowUuid The flow.
	 * @param integer $version  The version number.
	 *
	 * @return integer The number of non-terminal runs pinned to that version.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function countActivePinnedTo(string $flowUuid, int $version): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*) AS `c`'))
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowUuid)))
			->andWhere($qb->expr()->eq('flow_version', $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['c'] ?? 0);
	}//end countActivePinnedTo()

	/**
	 * Pin every still-movable run of a flow that has no version yet.
	 *
	 * The upgrade path. Deliberately scoped to `flow_version IS NULL` so it is
	 * idempotent — a second pass matches nothing — and to ACTIVE runs, so a
	 * terminal run from before versioning keeps its null rather than being
	 * told, retrospectively, that it executed version 1.
	 *
	 * @param string  $flowUuid The flow.
	 * @param integer $version  The version to pin.
	 *
	 * @return integer The number of runs pinned.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function pinUnversionedActive(string $flowUuid, int $version): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('flow_version', $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowUuid)))
			->andWhere($qb->expr()->isNull('flow_version'))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY)));

		return (int)$qb->executeStatement();
	}//end pinUnversionedActive()
}//end class
