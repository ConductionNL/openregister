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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\LockedException;
use OCP\IGroupManager;
use OCP\IUserSession;
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
	 * Constructor
	 *
	 * @param MagicMapper $magicMapper Magic mapper for magic table operations
	 * @param AuditTrailMapper $auditTrailMapper Audit trail mapper for logging actions
	 * @param LoggerInterface $logger PSR-3 logger
	 * @param IUserSession $userSession User session for authorization checks
	 * @param IGroupManager $groupManager Group manager for admin checks
	 * @param SchemaMapper $schemaMapper Schema mapper for resolving manage rules
	 * @param AdvisoryLockStore $advisory Pre-creation locks, for identifiers that do not resolve to a stored object
	 * @param RunLockRegistry|null $runLocks Bookkeeping for run-held locks, so the terminal listener and the sweep can find them without reading objects
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly SchemaMapper $schemaMapper,
		private readonly AdvisoryLockStore $advisory,
		private readonly ?RunLockRegistry $runLocks = null,
	) {
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
	 * Check if the current user has schema-manage permission on the schema
	 * owning the given object.
	 *
	 * Default-SECURE: a schema with no `manage` authorization rule can only be
	 * managed by administrators (admins always pass). When manage rules are
	 * present, group-membership grants permission.
	 *
	 * @param ObjectEntity $object The object whose owning schema is checked.
	 *
	 * @return bool True if the current user may manage the owning schema.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function callerHasSchemaManagePermission(ObjectEntity $object): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		// Admins always pass.
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			return false;
		}

		try {
			$schema = $this->schemaMapper->find((int)$schemaId);
			$authorization = $schema->getAuthorization();
		} catch (\Throwable $e) {
			return false;
		}

		if (empty($authorization) === true || isset($authorization['manage']) === false) {
			// Default-secure: no manage rule defined → admin-only (failed above).
			return false;
		}

		try {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		} catch (\Throwable $e) {
			return false;
		}

		$manageRules = $authorization['manage'];
		foreach ($userGroups as $groupId) {
			foreach ($manageRules as $entry) {
				if (is_string($entry) === true && $entry === $groupId) {
					return true;
				}

				if (is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $groupId) {
					return true;
				}
			}
		}

		return false;
	}//end callerHasSchemaManagePermission()

	/**
	 * Authorize an unlock request.
	 *
	 * The caller may unlock the object only when one of:
	 *  - the caller is the lock holder (the user recorded in the lock payload), OR
	 *  - the caller is the object owner (see `ObjectEntity::getOwner()`), OR
	 *  - the caller has schema-manage permission on the owning schema, OR
	 *  - the caller is a Nextcloud administrator.
	 *
	 * Anonymous callers are always refused. This closes the wave-3 C14
	 * finding where any authenticated user could unlock any locked object.
	 *
	 * @param ObjectEntity $object The locked object the caller wants to unlock.
	 * @param string|null $runUuid The flow run asking, when a run is asking.
	 *
	 * @return bool True if the caller may unlock this object.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-an-administrator-may-break-a-lock-and-the-break-is-recorded
	 */
	private function callerMayUnlock(ObjectEntity $object, ?string $runUuid = null): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$userId = $user->getUID();

		// Admins always pass. For a run lock this is the BREAK-LOCK route and
		// the only one, so a wedged run cannot hold a case hostage; callers
		// that mean to break a run's lock should use breakLock(), which
		// records the displacement.
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		// The holder. For a run lock this admits only the holding run, and in
		// particular NOT the run's own runAs user: a person must not be able
		// to walk in behind the identity the run happens to execute as.
		if ($object->isLockedBySomeoneElse(userId: $userId, runUuid: $runUuid) === false) {
			return true;
		}

		// The object-owner and schema-manage routes are for USER locks only.
		// Extending them to run locks would mean any case owner could quietly
		// defeat the engine's mutual exclusion, which is the whole point of a
		// run lock. They keep an administrator break as their escape hatch.
		if ($object->getLockedByRun() !== null) {
			return false;
		}

		// Object owner.
		if ($object->getOwner() === $userId) {
			return true;
		}

		// Schema-manage permission.
		return $this->callerHasSchemaManagePermission(object: $object);
	}//end callerMayUnlock()

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
		$this->logger->debug(
			message: '[LockHandler] Locking object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'identifier' => $identifier,
				'process' => $process,
				'duration' => $duration,
				'advisory' => $advisory,
			]
		);

		// Advisory (pre-creation) fast-path: the caller knows the identifier is a
		// synthetic key that does NOT resolve to a stored object (e.g. openbuild's
		// `createApp:<slug>` guard). Go straight to the appConfig-backed advisory
		// lock and skip findObjectWithContext, whose findAcrossAllMagicTables scan
		// would otherwise query every magic table just to conclude "not found".
		if ($advisory === true) {
			return $this->advisory->acquire(identifier: $identifier, process: $process, duration: $duration);
		}

		try {
			// Find the object and its register/schema context.
			try {
				$context = $this->findObjectWithContext(identifier: $identifier);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// Pre-creation / advisory lock: the identifier does not resolve
				// to a stored object (e.g. the openbuild wizard locks
				// `createApp:<slug>` before the object exists). Fall back to an
				// advisory lock keyed by the arbitrary string so create-then-store
				// flows work instead of failing with a 404/422.
				return $this->advisory->acquire(
					identifier: $identifier,
					process: $process,
					duration: $duration
				);
			}

			return $this->lockStoredObject(
				context: $context,
				duration: $duration,
				process: $process,
				runUuid: $runUuid,
				nodeId: $nodeId
			);
		} catch (LockedException $e) {
			$this->logger->warning(
				message: '[LockHandler] Object is already locked',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $identifier,
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[LockHandler] Failed to lock object',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $identifier,
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}//end try
	}//end lock()

	/**
	 * Take the lock on a resolved object and record it.
	 *
	 * @param array $context The resolved object with its register and schema.
	 * @param int|null $duration Lock duration in seconds.
	 * @param string|null $process What the lock was taken for.
	 * @param string|null $runUuid Flow run taking the lock, for a run-scoped lock.
	 * @param string|null $nodeId Flow node that took it, recorded for the sweep.
	 *
	 * @return array Lock result with locked details and uuid.
	 *
	 * @throws LockedException If the object is already locked by another holder.
	 */
	private function lockStoredObject(
		array $context,
		?int $duration,
		?string $process,
		?string $runUuid,
		?string $nodeId,
	): array {
		$objectBefore = $context['object'];

		// Use MagicMapper for lock operation.
		$objectAfter = $this->magicMapper->lockObjectEntity(
			entity: $objectBefore,
			register: $context['register'],
			schema: $context['schema'],
			lockDuration: $duration,
			process: $process,
			runUuid: $runUuid
		);

		// Record a run's lock so the terminal listener and the sweep can find
		// it without reading objects. Bookkeeping only: the payload on the
		// object stays the sole authority for the write guard, so a failure
		// to record must not undo a lock that was taken.
		$this->runLocks?->record(object: $objectAfter, runUuid: $runUuid, nodeId: $nodeId);

		// Record lock action in audit trail.
		$this->auditTrailMapper->createAuditTrail(old: $objectBefore, new: $objectAfter, action: 'lock');

		$this->logger->info(
			message: '[LockHandler] Object locked successfully',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $objectAfter->getUuid(),
				'process' => $process,
			]
		);

		return [
			'uuid' => $objectAfter->getUuid(),
			'locked' => $objectAfter->getLocked(),
		];
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
	 * @return true True if unlocked successfully
	 *
	 * @throws \Exception If unlock operation fails
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function unlock(
		string $identifier,
		bool $advisory = false,
		?string $runUuid = null,
		bool $break = false,
	): bool {
		$this->logger->debug(
			message: '[LockHandler] Unlocking object',
			context: ['file' => __FILE__, 'line' => __LINE__, 'identifier' => $identifier, 'advisory' => $advisory]
		);

		// Advisory (pre-creation) fast-path — mirror of lock(): release the
		// appConfig-backed advisory lock directly and skip the all-tables scan.
		if ($advisory === true) {
			$this->advisory->release(identifier: $identifier);
			return true;
		}

		try {
			// Find the object and its register/schema context.
			//
			// SECURITY: bypass RBAC + multitenancy on the read so we can
			// resolve cross-tenant lock holders, but perform an explicit
			// authorization check before mutating state. Without the bypass
			// a non-owner who was nonetheless the lock holder could not
			// resolve the object at all and would be blocked from
			// releasing their own lock; the explicit `callerMayUnlock`
			// gate replaces the wave-3 C14 "any authenticated user can
			// unlock anything" behavior.
			try {
				$context = $this->findObjectWithContext(identifier: $identifier, _rbacBypass: true);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// Pre-creation / advisory lock release: identifier never
				// resolved to a stored object. Clear any advisory lock held
				// under this arbitrary key (no-op if none present).
				$this->advisory->release(identifier: $identifier);
				return true;
			}

			$objectBefore = $context['object'];

			// No-op when the object isn't actually locked. Releasing a lock that
			// does not exist must not require unlock permission: an empty or expired
			// `_locked` means there is nothing to authorize. This keeps unlock
			// idempotent and prevents spurious "permission to unlock" failures on
			// flows that defensively unlock after a successful write (e.g. the
			// object update endpoint's post-save unlock). See openregister#195.
			if ($objectBefore->isLocked() === false) {
				return true;
			}

			if ($break === false && $this->callerMayUnlock(object: $objectBefore, runUuid: $runUuid) === false) {
				$this->logger->warning(
					message: '[LockHandler] Unauthorized unlock attempt',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'identifier' => $identifier,
					]
				);
				throw new Exception('User does not have permission to unlock this object');
			}

			$this->releaseStoredLock(context: $context, runUuid: $runUuid, break: $break);

			return true;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[LockHandler] Failed to unlock object',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $identifier,
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}//end try
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
		$displaced = $objectBefore->describeLockHolder();
		$displacedRun = $objectBefore->getLockedByRun();

		$objectAfter = $this->magicMapper->unlockObjectEntity(
			entity: $objectBefore,
			register: $context['register'],
			schema: $context['schema'],
			runUuid: $runUuid,
			break: $break
		);

		if ($displacedRun !== null) {
			$this->runLocks?->forget(runUuid: $displacedRun, objectUuid: (string)$objectAfter->getUuid());
		}

		// A BREAK is recorded under its own action, naming the holder it
		// displaced. An administrator overriding somebody else's lock is not
		// the same event as a holder releasing their own, and an audit trail
		// that cannot tell them apart cannot answer who took the case back.
		if ($break === true) {
			$this->auditTrailMapper->createAuditTrailEntry(
				object: $objectAfter,
				action: 'lock.broken',
				context: [
					'displacedHolder' => $displaced,
					'displacedRun' => $displacedRun,
				]
			);
		}

		$this->auditTrailMapper->createAuditTrail(old: $objectBefore, new: $objectAfter, action: 'unlock');

		$this->logger->info(
			message: '[LockHandler] Object unlocked successfully',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $objectAfter->getUuid(),
			]
		);
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
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
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
			$context = $this->findObjectWithContext(identifier: $identifier);
			$object = $context['object'];

			// Delegate to the canonical ObjectEntity::isLocked() implementation,
			// which understands both the current `{user, process, created, duration, expiration}`
			// schema (see ObjectEntity::lock() at lib/Db/ObjectEntity.php:1042-1066) and the
			// legacy lockedAt+duration fallback. Reading bespoke keys ('expiresAt', 'userId',
			// 'lockedAt') here would never match what lock() writes, so a stale/expired lock
			// could never be detected via this code path.
			return $object->isLocked();
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[LockHandler] Failed to check lock status',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $identifier,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}//end try
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
			$context = $this->findObjectWithContext(identifier: $identifier);
			$object = $context['object'];

			// Delegate to ObjectEntity::getLockInfo() which returns the raw lock payload
			// written by lock() — `{user, process, created, duration, expiration}` — or
			// null if no active (non-expired) lock is present. Map to the public, snake_case
			// representation expected by API consumers without re-inventing the key names.
			$locked = $object->getLockInfo();

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
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[LockHandler] Failed to get lock info',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'identifier' => $identifier,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try
	}//end getLockInfo()
}//end class
