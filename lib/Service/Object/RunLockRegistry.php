<?php

/**
 * The lifecycle of locks held by flow runs: record, release, sweep.
 *
 * Split out of `LockHandler` because the two answer different questions.
 * LockHandler answers "may this caller take or release this lock", once per
 * request. This answers "which locks does this run hold" and "which locks has
 * nobody left to release them", asked by a terminal-event listener and by a
 * cron sweep.
 *
 * THE REGISTRY IS BOOKKEEPING, NEVER THE AUTHORITY. The `_locked` payload on
 * the object is the only thing a write guard consults, and every release here
 * is checked against it before acting. A row with no matching payload is
 * stale and gets deleted; a payload with no row still blocks writes and still
 * expires on its TTL. Making the registry authoritative would create a second
 * source of truth for the question asked on every object write, and the two
 * would drift.
 *
 * WHY A TABLE AT ALL. Locks live in the `_locked` column of magic tables, one
 * per register-schema pair. `MagicMapper::findAcrossAllMagicTables()` carries
 * a measured note that an instance-wide scan over 2,728 of them builds 690 KB
 * of SQL costing about 3.4 seconds to PLAN before a row is read. That cannot
 * go on a cron tick, and it certainly cannot go inside the run's own terminal
 * write transaction.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RunObjectLock;
use OCA\OpenRegister\Db\RunObjectLockMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records and releases the object locks a flow run holds.
 */
class RunLockRegistry {

	/**
	 * Constructor.
	 *
	 * @param RunObjectLockMapper $registry The run-lock rows.
	 * @param MagicMapper $magicMapper Resolves and mutates the objects themselves.
	 * @param AuditTrailMapper $auditTrailMapper Records the releases.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly RunObjectLockMapper $registry,
		private readonly MagicMapper $magicMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Record that a run holds a lock.
	 *
	 * A failure here is logged and swallowed: the lock itself is already
	 * taken and is what the write guard reads, so failing the lock because
	 * its index entry did not land would trade a working lock for no lock at
	 * all. The TTL still bounds a lock whose row is missing.
	 *
	 * @param ObjectEntity $object The locked object.
	 * @param string|null $runUuid The holding run, or null for a user lock.
	 * @param string|null $nodeId The flow node that took it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
	 */
	public function record(ObjectEntity $object, ?string $runUuid, ?string $nodeId): void {
		if ($runUuid === null || trim($runUuid) === '') {
			return;
		}

		try {
			$payload = ($object->getLocked() ?? []);
			$expires = null;
			if (isset($payload['expiration']) === true) {
				$expires = new DateTime((string)$payload['expiration']);
			}

			$row = new RunObjectLock();
			$row->setRunUuid(trim($runUuid));
			$row->setObjectUuid((string)$object->getUuid());
			$row->setRegisterId((string)$object->getRegister());
			$row->setSchemaId((string)$object->getSchema());
			$row->setNodeId($nodeId);
			$row->setLockedAt(new DateTime());
			$row->setExpiresAt($expires);

			$this->registry->record(lock: $row);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[RunLockRegistry] Could not record a run lock; the lock itself stands',
				context: ['file' => __FILE__, 'line' => __LINE__, 'runUuid' => $runUuid, 'error' => $e->getMessage()]
			);
		}//end try
	}//end record()

	/**
	 * Forget one run's row for one object, after the lock has been released.
	 *
	 * @param string $runUuid The run.
	 * @param string $objectUuid The object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
	 */
	public function forget(string $runUuid, string $objectUuid): void {
		try {
			$this->registry->forget(runUuid: $runUuid, objectUuid: $objectUuid);
		} catch (Throwable $e) {
			// A row left behind is collected by the sweep on its own terms.
			$this->logger->debug(
				message: '[RunLockRegistry] Could not forget a run lock row',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}
	}//end forget()

	/**
	 * Release every lock one run holds. Release layer 1.
	 *
	 * Called from the `FlowRunTerminalEvent` listener, which fires for ALL
	 * FOUR terminal statuses (completed, stopped, failed, dead_letter) and
	 * for every reaper path, because the dispatch predicate in
	 * `FlowRunMapper::update()` is `isTerminal()` rather than a whitelist.
	 * That is why the release lives here and not in a node: a run that
	 * crashed never reaches an unlock step, and it is exactly the run whose
	 * lock most needs releasing.
	 *
	 * IDEMPOTENT, because terminality can be observed more than once.
	 *
	 * @param string $runUuid The run whose locks are released.
	 *
	 * @return int How many locks were released.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
	 */
	public function releaseRunLocks(string $runUuid): int {
		if (trim($runUuid) === '') {
			return 0;
		}

		$released = 0;
		foreach ($this->registry->findByRun(runUuid: trim($runUuid)) as $row) {
			if ($this->releaseRecorded(row: $row) === true) {
				$released++;
			}
		}

		$this->registry->forgetRun(runUuid: trim($runUuid));

		return $released;
	}//end releaseRunLocks()

	/**
	 * Release locks whose holding run is terminal, gone, or expired. Layer 2.
	 *
	 * Layer 1 covers a run that reaches terminal, and the reapers do
	 * eventually terminate a run whose worker was killed. What layer 1 cannot
	 * cover is a release that itself failed, or a run row deleted out from
	 * under an outstanding lock. This finds those in one indexed query.
	 *
	 * @param DateTime $now The sweep's clock.
	 * @param int $limit Batch ceiling.
	 *
	 * @return int How many locks were released.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
	 */
	public function sweepOrphaned(DateTime $now, int $limit = 100): int {
		$released = 0;
		foreach ($this->registry->findOrphaned(now: $now, limit: $limit) as $row) {
			if ($this->releaseRecorded(row: $row) === true) {
				$released++;
			}

			$this->forget(
				runUuid: (string)$row->getRunUuid(),
				objectUuid: (string)$row->getObjectUuid()
			);
		}

		return $released;
	}//end sweepOrphaned()

	/**
	 * Release one recorded lock, if the object still says this run holds it.
	 *
	 * The registry is checked AGAINST the object rather than trusted: only a
	 * lock the object attributes to this run is released. Without that check
	 * a stale row would let the sweep strip a lock a DIFFERENT run has since
	 * taken on the same object.
	 *
	 * @param RunObjectLock $row The registry row.
	 *
	 * @return bool True when a lock was released.
	 */
	private function releaseRecorded(RunObjectLock $row): bool {
		$runUuid = trim((string)$row->getRunUuid());
		$objectUuid = trim((string)$row->getObjectUuid());
		if ($runUuid === '' || $objectUuid === '') {
			return false;
		}

		try {
			// EVERY scoping filter is off, deliberately, and each one is a way
			// this sweep could quietly do nothing.
			//
			// `_rbac` / `_multitenancy`: both release layers run from a
			// background job or `occ`, which have NO SESSION, so a scoped read
			// resolves as Anonymous and answers "no such object". The sweep
			// would then report zero locks to release and the lock would be
			// held until its TTL, with no error anywhere. This is the same
			// defect that made `prune-retired` count zero rows and delete
			// schemas that held data (openregister#3440).
			//
			// `includeDeleted`: a soft-deleted object can still carry a live
			// lock, and it is precisely the object nobody is watching. Leaving
			// it out would strand that lock and its registry row forever.
			$context = $this->magicMapper->findAcrossAllSources(
				identifier: $objectUuid,
				includeDeleted: true,
				_rbac: false,
				_multitenancy: false
			);
			$objectBefore = $context['object'];

			if ($objectBefore->getLockedByRun() !== $runUuid) {
				// Stale bookkeeping, or another run holds it now.
				return false;
			}

			$objectAfter = $this->magicMapper->unlockObjectEntity(
				entity: $objectBefore,
				register: $context['register'],
				schema: $context['schema'],
				break: true
			);

			$this->auditTrailMapper->createAuditTrail(old: $objectBefore, new: $objectAfter, action: 'unlock');

			$this->logger->info(
				message: '[RunLockRegistry] Released a run lock',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'runUuid' => $runUuid,
					'objectUuid' => $objectUuid,
				]
			);

			return true;
		} catch (Throwable $e) {
			// Never propagate: layer 1 runs inside the run's own terminal
			// write transaction, and a throw would unwind the status change.
			// The TTL is the remaining backstop and the sweep sees the row again.
			$this->logger->warning(
				message: '[RunLockRegistry] Could not release a run lock',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'runUuid' => $runUuid,
					'objectUuid' => $objectUuid,
					'error' => $e->getMessage(),
				]
			);

			return false;
		}//end try
	}//end releaseRecorded()
}//end class
