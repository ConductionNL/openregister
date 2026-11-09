<?php

/**
 * The run-held object lock registry.
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
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Run-held object locks.
 *
 * @template-extends QBMapper<RunObjectLock>
 */
class RunObjectLockMapper extends QBMapper implements RunObjectLockMapperInterface {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_run_object_locks',
			entityClass: RunObjectLock::class
		);
	}//end __construct()

	/**
	 * Record that a run holds a lock, or refresh the record it already has.
	 *
	 * A run re-locking an object it already holds extends the lock rather
	 * than taking a second one, so the registry must behave the same way and
	 * not trip its own unique index.
	 *
	 * @param RunObjectLock $lock The lock to record.
	 *
	 * @return void
	 *
	 * @throws DbException On any database failure other than the duplicate.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
	 */
	public function record(RunObjectLock $lock): void {
		if ($this->insertUnlessRecorded(lock: $lock) === true) {
			return;
		}

		// Already recorded: refresh the expiry, which the extend branch moved.
		$this->refresh(lock: $lock);
	}//end record()

	/**
	 * Insert the lock, unless the run already holds a record for the object.
	 *
	 * @param RunObjectLock $lock The lock to record.
	 *
	 * @return bool True when inserted, false when the record already existed.
	 *
	 * @throws DbException On any database failure other than the duplicate.
	 */
	private function insertUnlessRecorded(RunObjectLock $lock): bool {
		try {
			$this->insert(entity: $lock);
			return true;
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}

		return false;
	}//end insertUnlessRecorded()

	/**
	 * Move an existing record's expiry and node to the lock's.
	 *
	 * @param RunObjectLock $lock The lock whose record to refresh.
	 *
	 * @return void
	 */
	private function refresh(RunObjectLock $lock): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('expires_at', $qb->createNamedParameter($lock->getExpiresAt(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->set('node_id', $qb->createNamedParameter($lock->getNodeId()))
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($lock->getRunUuid())))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($lock->getObjectUuid())));
		$qb->executeStatement();
	}//end refresh()

	/**
	 * Every lock one run holds.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return array<int, RunObjectLock> The run's locks.
	 */
	public function findByRun(string $runUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));

		return $this->findEntities(query: $qb);
	}//end findByRun()

	/**
	 * Forget every lock one run holds.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return int Rows deleted.
	 */
	public function forgetRun(string $runUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));

		return $qb->executeStatement();
	}//end forgetRun()

	/**
	 * Forget one run's lock on one object.
	 *
	 * @param string $runUuid The run.
	 * @param string $objectUuid The object.
	 *
	 * @return int Rows deleted.
	 */
	public function forget(string $runUuid, string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end forget()

	/**
	 * Locks whose holding run is terminal, gone, or whose lock has expired.
	 *
	 * The sweep's read. One indexed query against the runs table rather than
	 * a scan across every magic table on the instance.
	 *
	 * A lock is orphaned when ANY of these hold:
	 *  - its run row no longer exists (retention deleted it, or it was never
	 *    written), so no terminal event can ever fire for it;
	 *  - its run reached a terminal status without the release landing;
	 *  - the underlying lock has expired anyway, so the row is stale
	 *    bookkeeping for a lock that already blocks nobody.
	 *
	 * TWO plain queries rather than one composite. The obvious single query
	 * is an orX() of a `run_uuid NOT IN (sub-select)` built with
	 * createFunction() and an `expires_at <` comparison. On PostgreSQL that
	 * composes to `(a) OR (b)` where the createFunction half is not
	 * recognised as a boolean, and the whole sweep dies with "argument of OR
	 * must be type boolean, not type record" — which the sweep's own catch
	 * would have swallowed into a log line while releasing nothing, forever.
	 * Two readable queries and a merge cost one extra round trip per cron
	 * tick and cannot fail that way.
	 *
	 * @param DateTime $now The sweep's clock.
	 * @param int $limit Batch ceiling.
	 *
	 * @return array<int, RunObjectLock> The orphaned locks.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
	 */
	public function findOrphaned(DateTime $now, int $limit = 100): array {
		$byRun = $this->findNotHeldByAnActiveRun(limit: $limit);
		$byExpiry = $this->findExpired(now: $now, limit: $limit);

		$merged = [];
		foreach (array_merge($byRun, $byExpiry) as $row) {
			$merged[(string)$row->getId()] = $row;
		}

		return array_slice(array_values($merged), 0, $limit);
	}//end findOrphaned()

	/**
	 * Locks whose holding run is terminal or no longer exists.
	 *
	 * @param int $limit Batch ceiling.
	 *
	 * @return array<int, RunObjectLock> The rows.
	 */
	private function findNotHeldByAnActiveRun(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$active = $this->activeRunUuidsSql(qb: $qb);
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->createFunction('run_uuid NOT IN (' . $active . ')'))
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findNotHeldByAnActiveRun()

	/**
	 * The sub-select of every active run's uuid.
	 *
	 * The status list is bound on the OUTER builder, because only the outer
	 * query is executed and the sub-select travels into it as plain SQL.
	 *
	 * @param IQueryBuilder $qb The outer builder the parameter is bound on.
	 *
	 * @return string The sub-select's SQL.
	 */
	private function activeRunUuidsSql(IQueryBuilder $qb): string {
		$active = $this->db->getQueryBuilder();
		$active->select('uuid')
			->from('openregister_flow_runs')
			->where(
				$active->expr()->in(
					'status',
					$qb->createNamedParameter(FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		return $active->getSQL();
	}//end activeRunUuidsSql()

	/**
	 * Rows whose underlying lock has expired anyway.
	 *
	 * @param DateTime $now The sweep's clock.
	 * @param int $limit Batch ceiling.
	 *
	 * @return array<int, RunObjectLock> The rows.
	 */
	private function findExpired(DateTime $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->lt(
					'expires_at',
					$qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			)
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findExpired()
}//end class
