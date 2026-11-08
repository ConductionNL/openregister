<?php

/**
 * RunObjectLockMapperInterface: what callers outside the Db component use of RunObjectLockMapper.
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

use DateTime;
use OCP\DB\Exception as DbException;

/**
 * The methods of RunObjectLockMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to RunObjectLockMapper.
 */
interface RunObjectLockMapperInterface {

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
	public function record(RunObjectLock $lock): void;

	/**
	 * Every lock one run holds.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return array<int, RunObjectLock> The run's locks.
	 */
	public function findByRun(string $runUuid): array;

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
	public function findOrphaned(DateTime $now, int $limit = 100): array;

	/**
	 * Forget one run's lock on one object.
	 *
	 * @param string $runUuid The run.
	 * @param string $objectUuid The object.
	 *
	 * @return int Rows deleted.
	 */
	public function forget(string $runUuid, string $objectUuid): int;

	/**
	 * Forget every lock one run holds.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return int Rows deleted.
	 */
	public function forgetRun(string $runUuid): int;
}//end interface
