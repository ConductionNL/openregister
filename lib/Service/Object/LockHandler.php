<?php

/**
 * Lock Handler
 *
 * Handles object locking and unlocking operations.
 * Locks prevent concurrent modifications to objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * LockHandler
 *
 * Responsible for managing object locks to prevent concurrent modifications.
 * All objects are stored in magic tables.
 *
 * RESPONSIBILITIES:
 * - Lock objects with optional process ID and duration
 * - Unlock objects
 * - Check lock status
 * - Validate unlock permissions
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LockHandler {
	/**
	 * Magic mapper for magic table operations.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $magicMapper;

	/**
	 * Audit trail mapper for logging actions.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * Pre-creation locks, for identifiers that do not resolve to a stored object.
	 *
	 * @var AdvisoryLockStore
	 */
	private readonly AdvisoryLockStore $advisory;

	/**
	 * Bookkeeping for run-held locks, so the terminal listener and the sweep can find them.
	 *
	 * @var RunLockRegistry|null
	 */
	private readonly ?RunLockRegistry $runLocks;

	/**
	 * Constructor
	 *
	 * @param LockStores        $stores        The magic, audit trail, advisory and run-lock stores
	 * @param LoggerInterface   $logger        PSR-3 logger
	 * @param LockAuthorization $authorization Who may take, release and break a lock
	 */
	public function __construct(
		LockStores $stores,
		private readonly LoggerInterface $logger,
		private readonly LockAuthorization $authorization,
	) {
		$this->magicMapper      = $stores->magicMapper;
		$this->auditTrailMapper = $stores->auditTrailMapper;
		$this->advisory         = $stores->advisory;
		$this->runLocks         = $stores->runLocks;
	}//end __construct()

	/**
	 * Find an object and get its register/schema context.
	 *
	 * The `$_rbacBypass` flag is reserved for unlock paths that perform their
	 * own caller-vs-lock-holder/owner/manage authorization check on top (see
	 * `unlock()`); for lock/isLocked/getLockInfo it stays false so the regular
	 * RBAC + multitenancy boundary is respected.
	 *
	 * @param string $identifier Object ID or UUID
	 * @param bool $_rbacBypass When true, skip RBAC + multitenancy in the
	 *                          mapper lookup (caller MUST perform its own
	 *                          authorization gate).
	 *
	 * @return array{object: \OCA\OpenRegister\Db\ObjectEntity, register: Register|null, schema: Schema|null}
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC bypass flag follows established API patterns.
	 */
	private function findObjectWithContext(string $identifier, bool $_rbacBypass = false): array {
		$result = $this->magicMapper->findAcrossAllSources(
			identifier: $identifier,
			includeDeleted: false,
			_rbac: ($_rbacBypass === false),
			_multitenancy: ($_rbacBypass === false)
		);

		return [
			'object' => $result['object'],
			'register' => $result['register'],
			'schema' => $result['schema'],
		];
	}//end findObjectWithContext()

	/**
	 * Lock an object
	 *
	 * Locks an object to prevent concurrent modifications.
	 * The lock can be associated with a process and have a duration.
	 *
	 * @param string $identifier Object ID or UUID
	 * @param string|null $process Process ID (for tracking who locked it)
	 * @param int|null $duration Lock duration in seconds
	 * @param bool $advisory When true, skip the object lookup and take the
	 *                       appConfig-backed advisory lock directly (used by
	 *                       pre-creation guards where no object exists yet)
	 * @param string|null $runUuid Flow run taking the lock. A run-scoped lock
	 *                             refuses every other caller, the run's own
	 *                             runAs user included.
	 * @param string|null $nodeId Flow node that took it, recorded for the sweep
	 *
	 * @return array Lock result with locked details and uuid.
	 *
	 * @throws LockedException If object is already locked.
	 * @throws \Exception If lock operation fails.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function lock(
		string $identifier,
		?string $process = null,
		?int $duration = null,
		bool $advisory = false,
		?string $runUuid = null,
		?string $nodeId = null,
	): array {
		$request = new LockRequest(identifier: $identifier, process: $process, duration: $duration, runUuid: $runUuid, nodeId: $nodeId);
		$this->logRequest(request: $request, advisory: $advisory);

		return $this->acquire(request: $request, advisory: $advisory);
	}//end lock()

	/**
	 * Take the lock on a resolved object and record it.
	 *
	 * @param array       $context The resolved object with its register and schema.
	 * @param LockRequest $request What the caller asked for.
	 *
	 * @return array Lock result with locked details and uuid.
	 *
	 * @throws LockedException If a mapper-level lock refuses the acquire.
	 * @throws \Exception If the object is already held by another holder.
	 *                    `ObjectEntity::lock()` throws the global Exception,
	 *                    not LockedException, so lock()'s outer handler needs
	 *                    both arms. Declaring only the narrow one made PHPStan
	 *                    read the broad catch as dead code when it is the arm
	 *                    that actually fires on contention.
	 * @throws NotAuthorizedException If a user who may not update the object asks for its lock.
	 *
	 * @spec openspec/changes/lock-needs-update-permission/specs/lock-needs-update-permission/spec.md#requirement-a-user-lock-needs-update-permission-on-the-object
	 */
	private function lockStoredObject(array $context, LockRequest $request): array {
		$this->authorization->assertMayLock(context: $context);

		// Use MagicMapper for lock operation.
		$objectAfter = $this->magicMapper->lockObjectEntity(
			entity: $context['object'],
			register: $context['register'],
			schema: $context['schema'],
			lockDuration: $request->duration,
			process: $request->process,
			runUuid: $request->runUuid
		);
		$this->recordLock(before: $context['object'], after: $objectAfter, request: $request);

		// `held` is read back off the entity rather than assumed from the fact that
		// the write did not throw, so an already-expired lock is not reported as held.
		return ['uuid' => $objectAfter->getUuid(), 'locked' => $objectAfter->getLocked(), 'held' => $objectAfter->isLocked()];
	}//end lockStoredObject()

	/**
	 * Unlock an object
	 *
	 * Removes the lock from an object, allowing other processes to modify it.
	 *
	 * @param string $identifier Object ID or UUID
	 * @param bool $advisory When true, release the appConfig-backed advisory lock
	 *                       directly and skip the object lookup / all-tables scan
	 * @param string|null $runUuid Flow run releasing the lock, for a run-scoped lock
	 * @param bool $break Release regardless of holder. Reserved for the
	 *                    administrator break-lock and the engine's own release
	 *                    layers, both of which authorize at their call site.
	 *
	 * @return bool True when a lock was RELEASED; false when there was nothing
	 *              to release because the object carried no live lock.
	 *
	 * 🔴 THOSE TWO ANSWERS USED TO BE THE SAME ANSWER, AND A CLIENT COULD NOT
	 * TELL THEM APART. Both returned `true`, so "I handed my lock back" and
	 * "somebody else had already taken it away" read identically, and a UI that
	 * releases on close reported success on a lock it never held. It is also
	 * what the HTTP surface needs: `DELETE`/`unlock` answers 404 for the second
	 * case, which is a fact about the object rather than a routing accident.
	 *
	 * Still IDEMPOTENT, and deliberately so: releasing a lock that is not there
	 * is not an error and needs no unlock permission, because an empty or
	 * expired `_locked` gives nothing to authorize. Only the REPORT changed.
	 *
	 * @throws \Exception If unlock operation fails
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function unlock(
		string $identifier,
		bool $advisory = false,
		?string $runUuid = null,
		bool $break = false,
	): bool {
		$this->logger->debug(
			message: '[LockHandler] Unlocking object',
			context: $this->logContext(fields: ['identifier' => $identifier, 'advisory' => $advisory])
		);

		// Advisory (pre-creation) fast-path — mirror of lock(): release the
		// appConfig-backed advisory lock directly and skip the all-tables scan.
		if ($advisory === true) {
			return $this->releaseAdvisory(identifier: $identifier);
		}

		return $this->unlockLogged(identifier: $identifier, runUuid: $runUuid, break: $break);
	}//end unlock()

	/**
	 * Release the lock on a resolved object, auditing what happened.
	 *
	 * @param array $context The resolved object with its register and schema.
	 * @param string|null $runUuid Flow run releasing the lock, for a run-scoped lock.
	 * @param bool $break Release regardless of holder; authorized at the call site.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors unlock()'s own break flag.
	 */
	private function releaseStoredLock(array $context, ?string $runUuid, bool $break): void {
		$objectBefore = $context['object'];

		// Capture the holder BEFORE the release, so a break can name what it
		// displaced. After unlockObjectEntity() there is nothing left to name.
		$displaced = ['displacedHolder' => $objectBefore->describeLockHolder(), 'displacedRun' => $objectBefore->getLockedByRun()];

		$objectAfter = $this->magicMapper->unlockObjectEntity(
			entity: $objectBefore,
			register: $context['register'],
			schema: $context['schema'],
			runUuid: $runUuid,
			break: $break
		);
		$this->recordRelease(before: $objectBefore, after: $objectAfter, displaced: $displaced, break: $break);
	}//end releaseStoredLock()

	/**
	 * Break a lock as an administrator, and record the displacement.
	 *
	 * The escape hatch for a wedged run. A run lock refuses everybody by
	 * design, holder, object owner and schema manager included, so without a
	 * break a run that died in a way none of the three release layers caught
	 * would hold a case until its TTL ran out. Restricted to administrators
	 * and always audited: an override nobody can see afterwards is
	 * indistinguishable from the lock never having worked.
	 *
	 * @param string $identifier Object ID or UUID.
	 *
	 * @return bool True when a lock was broken.
	 *
	 * @throws Exception If the caller is not an administrator.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-an-administrator-may-break-a-lock-and-the-break-is-recorded
	 */
	public function breakLock(string $identifier): bool {
		if ($this->authorization->mayBreak() === false) {
			throw new Exception('Only an administrator may break a lock');
		}

		return $this->unlock(identifier: $identifier, break: true);
	}//end breakLock()


	/**
	 * Check if an object is locked
	 *
	 * @param string $identifier Object ID or UUID
	 *
	 * @return bool True if locked, false otherwise
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isLocked(string $identifier): bool {
		try {
			// Delegate to the canonical ObjectEntity::isLocked() implementation,
			// which understands both the current lock payload and the legacy
			// lockedAt+duration fallback.
			return $this->findObjectWithContext(identifier: $identifier)['object']->isLocked();
		} catch (\Exception $e) {
			$this->logger->warning(message: '[LockHandler] Failed to check lock status', context: $this->failure(identifier: $identifier, error: $e));
			return false;
		}
	}//end isLocked()

	/**
	 * Get lock information for an object
	 *
	 * Returns details about the lock including process ID and expiry.
	 *
	 * @param string $identifier Object ID or UUID
	 *
	 * @return array|null Lock info array or null if not locked.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getLockInfo(string $identifier): ?array {
		try {
			// The raw lock payload written by lock(), or null if no active lock is present.
			$locked = $this->findObjectWithContext(identifier: $identifier)['object']->getLockInfo();
		} catch (\Exception $e) {
			$this->logger->warning(message: '[LockHandler] Failed to get lock info', context: $this->failure(identifier: $identifier, error: $e));
			return null;
		}

		return $this->publicLockInfo(locked: $locked);
	}//end getLockInfo()
	/**
	 * Log a lock request.
	 *
	 * @param LockRequest $request  What the caller asked for.
	 * @param bool        $advisory Whether an advisory lock is asked for.
	 *
	 * @return void
	 */
	private function logRequest(LockRequest $request, bool $advisory): void {
		$this->logger->debug(
			message: '[LockHandler] Locking object',
			context: $this->logContext(
				fields: [
					'identifier' => $request->identifier,
					'process' => $request->process,
					'duration' => $request->duration,
					'advisory' => $advisory,
				]
			)
		);
	}//end logRequest()

	/**
	 * Take an advisory lock or a lock on a stored object.
	 *
	 * Advisory (pre-creation) fast-path: the caller knows the identifier is a
	 * synthetic key that does NOT resolve to a stored object (e.g. openbuild's
	 * `createApp:<slug>` guard). Go straight to the appConfig-backed advisory
	 * lock and skip the all-tables scan that would only conclude "not found".
	 *
	 * @param LockRequest $request  What the caller asked for.
	 * @param bool        $advisory Whether an advisory lock is asked for.
	 *
	 * @return array Lock result with locked details and uuid.
	 *
	 * @throws LockedException If object is already locked.
	 * @throws \Exception If lock operation fails.
	 */
	private function acquire(LockRequest $request, bool $advisory): array {
		if ($advisory === true) {
			return $this->acquireAdvisory(request: $request);
		}

		return $this->lockLogged(request: $request);
	}//end acquire()

	/**
	 * Lock a stored object or, when the identifier resolves to none, take an advisory lock; log a failure.
	 *
	 * @param LockRequest $request What the caller asked for.
	 *
	 * @return array Lock result with locked details and uuid.
	 *
	 * @throws LockedException If object is already locked.
	 * @throws \Exception If lock operation fails.
	 */
	private function lockLogged(LockRequest $request): array {
		try {
			return $this->lockResolved(request: $request);
		} catch (LockedException $e) {
			$this->logger->warning(message: '[LockHandler] Object is already locked', context: $this->failure(identifier: $request->identifier, error: $e));
			throw $e;
		} catch (\Exception $e) {
			$this->logger->error(message: '[LockHandler] Failed to lock object', context: $this->failure(identifier: $request->identifier, error: $e));
			throw $e;
		}
	}//end lockLogged()

	/**
	 * Lock a stored object, or take an advisory lock when the identifier resolves to none.
	 *
	 * @param LockRequest $request What the caller asked for.
	 *
	 * @return array Lock result with locked details and uuid.
	 *
	 * @throws LockedException If object is already locked.
	 * @throws \Exception If lock operation fails.
	 */
	private function lockResolved(LockRequest $request): array {
		try {
			$context = $this->findObjectWithContext(identifier: $request->identifier);
		} catch (DoesNotExistException $e) {
			// Pre-creation / advisory lock: the identifier does not resolve to a
			// stored object (e.g. the openbuild wizard locks `createApp:<slug>`
			// before the object exists), so create-then-store flows still work.
			return $this->acquireAdvisory(request: $request);
		}

		return $this->lockStoredObject(context: $context, request: $request);
	}//end lockResolved()

	/**
	 * Take an advisory lock keyed by the identifier.
	 *
	 * @param LockRequest $request What the caller asked for.
	 *
	 * @return array Lock result with locked details and uuid.
	 *
	 * @throws LockedException If the advisory lock is held.
	 */
	private function acquireAdvisory(LockRequest $request): array {
		return $this->advisory->acquire(identifier: $request->identifier, process: $request->process, duration: $request->duration);
	}//end acquireAdvisory()

	/**
	 * Record a lock that was taken: the run bookkeeping, the audit trail and the log.
	 *
	 * The run bookkeeping lets the terminal listener and the sweep find a run's
	 * lock without reading objects. The payload on the object stays the sole
	 * authority for the write guard.
	 *
	 * @param ObjectEntity $before  The object before the lock.
	 * @param ObjectEntity $after   The locked object.
	 * @param LockRequest  $request What the caller asked for.
	 *
	 * @return void
	 */
	private function recordLock(ObjectEntity $before, ObjectEntity $after, LockRequest $request): void {
		$this->runLocks?->record(object: $after, runUuid: $request->runUuid, nodeId: $request->nodeId);
		$this->auditTrailMapper->createAuditTrail(old: $before, new: $after, action: 'lock');
		$this->logger->info(
			message: '[LockHandler] Object locked successfully',
			context: $this->logContext(fields: ['uuid' => $after->getUuid(), 'process' => $request->process])
		);
	}//end recordLock()

	/**
	 * Release a lock on a resolved object; log a failure.
	 *
	 * @param string      $identifier Object ID or UUID.
	 * @param string|null $runUuid    Flow run releasing the lock.
	 * @param bool        $break      Release regardless of holder.
	 *
	 * @return bool True when a lock was released, false when there was none.
	 *
	 * @throws \Exception If unlock operation fails.
	 */
	private function unlockLogged(string $identifier, ?string $runUuid, bool $break): bool {
		try {
			return $this->unlockResolved(identifier: $identifier, runUuid: $runUuid, break: $break);
		} catch (\Exception $e) {
			$this->logger->error(message: '[LockHandler] Failed to unlock object', context: $this->failure(identifier: $identifier, error: $e));
			throw $e;
		}
	}//end unlockLogged()

	/**
	 * Release the lock on the object an identifier resolves to, after authorizing the caller.
	 *
	 * SECURITY: the read bypasses RBAC + multitenancy so a cross-tenant lock
	 * holder can still resolve the object, and the explicit authorization check
	 * runs before any state is mutated.
	 *
	 * @param string      $identifier Object ID or UUID.
	 * @param string|null $runUuid    Flow run releasing the lock.
	 * @param bool        $break      Release regardless of holder.
	 *
	 * @return bool True when a lock was released, false when there was none.
	 *
	 * @throws Exception If the caller may not unlock this object.
	 */
	private function unlockResolved(string $identifier, ?string $runUuid, bool $break): bool {
		try {
			$context = $this->findObjectWithContext(identifier: $identifier, _rbacBypass: true);
		} catch (DoesNotExistException $e) {
			// Pre-creation / advisory lock release: the identifier never resolved
			// to a stored object (no-op if no advisory lock is present).
			return $this->releaseAdvisory(identifier: $identifier);
		}

		// Releasing a lock that does not exist needs no unlock permission: FALSE
		// means "there was nothing to release", not "this failed" (openregister#195).
		if ($context['object']->isLocked() === false) {
			return false;
		}

		$this->assertMayUnlock(identifier: $identifier, object: $context['object'], runUuid: $runUuid, break: $break);
		$this->releaseStoredLock(context: $context, runUuid: $runUuid, break: $break);

		return true;
	}//end unlockResolved()

	/**
	 * Refuse an unlock the caller may not perform; a break is authorized at its call site.
	 *
	 * @param string       $identifier Object ID or UUID.
	 * @param ObjectEntity $object     The locked object.
	 * @param string|null  $runUuid    Flow run releasing the lock.
	 * @param bool         $break      Release regardless of holder.
	 *
	 * @return void
	 *
	 * @throws Exception If the caller may not unlock this object.
	 */
	private function assertMayUnlock(string $identifier, ObjectEntity $object, ?string $runUuid, bool $break): void {
		if ($break === true || $this->authorization->mayUnlock(object: $object, runUuid: $runUuid) === true) {
			return;
		}

		$this->logger->warning(message: '[LockHandler] Unauthorized unlock attempt', context: $this->logContext(fields: ['identifier' => $identifier]));
		throw new Exception('User does not have permission to unlock this object');
	}//end assertMayUnlock()

	/**
	 * Release an advisory lock.
	 *
	 * @param string $identifier The advisory key.
	 *
	 * @return bool Always true.
	 */
	private function releaseAdvisory(string $identifier): bool {
		$this->advisory->release(identifier: $identifier);

		return true;
	}//end releaseAdvisory()

	/**
	 * Record a release: the run bookkeeping, a break, the audit trail and the log.
	 *
	 * A BREAK is recorded under its own action, naming the holder it displaced:
	 * an administrator overriding somebody else's lock is not the same event as
	 * a holder releasing their own.
	 *
	 * @param ObjectEntity $before    The object before the release.
	 * @param ObjectEntity $after     The released object.
	 * @param array        $displaced The holder and run the release displaced.
	 * @param bool         $break     Whether this was a break.
	 *
	 * @return void
	 */
	private function recordRelease(ObjectEntity $before, ObjectEntity $after, array $displaced, bool $break): void {
		if ($displaced['displacedRun'] !== null) {
			$this->runLocks?->forget(runUuid: $displaced['displacedRun'], objectUuid: (string) $after->getUuid());
		}

		if ($break === true) {
			$this->auditTrailMapper->createAuditTrailEntry(object: $after, action: 'lock.broken', context: $displaced);
		}

		$this->auditTrailMapper->createAuditTrail(old: $before, new: $after, action: 'unlock');
		$this->logger->info(message: '[LockHandler] Object unlocked successfully', context: $this->logContext(fields: ['uuid' => $after->getUuid()]));
	}//end recordRelease()

	/**
	 * The public, snake_case lock info for a raw lock payload.
	 *
	 * @param array|null $locked The raw lock payload, or null when not locked.
	 *
	 * @return array|null The lock info, or null when not locked.
	 */
	private function publicLockInfo(?array $locked): ?array {
		if ($locked === null) {
			return null;
		}

		return [
			'locked_at' => $locked['created'] ?? null,
			'locked_by' => $locked['user'] ?? null,
			'process' => $locked['process'] ?? null,
			'expires_at' => $locked['expiration'] ?? null,
			'duration' => $locked['duration'] ?? null,
		];
	}//end publicLockInfo()

	/**
	 * A log context for a failure on one identifier.
	 *
	 * @param string     $identifier Object ID or UUID.
	 * @param \Exception $error      The failure.
	 *
	 * @return array<string, mixed> The log context.
	 */
	private function failure(string $identifier, \Exception $error): array {
		return $this->logContext(fields: ['identifier' => $identifier, 'error' => $error->getMessage()]);
	}//end failure()

	/**
	 * A log context naming this file, then the given fields.
	 *
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return array<string, mixed> The log context.
	 */
	private function logContext(array $fields): array {
		return ['file' => __FILE__, 'line' => __LINE__] + $fields;
	}//end logContext()
}//end class
