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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\LockedException;
use OCP\IAppConfig;
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
class LockHandler
{
    /**
     * Constructor
     *
     * @param MagicMapper      $magicMapper      Magic mapper for magic table operations
     * @param AuditTrailMapper $auditTrailMapper Audit trail mapper for logging actions
     * @param LoggerInterface  $logger           PSR-3 logger
     * @param IUserSession     $userSession      User session for authorization checks
     * @param IGroupManager    $groupManager     Group manager for admin checks
     * @param SchemaMapper     $schemaMapper     Schema mapper for resolving manage rules
     * @param IAppConfig       $appConfig        App config store for advisory (pre-creation) locks
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly SchemaMapper $schemaMapper,
        private readonly IAppConfig $appConfig
    ) {
    }//end __construct()

    /**
     * Advisory-lock app-config key prefix.
     *
     * @var string
     */
    private const ADVISORY_LOCK_PREFIX = 'advisory_lock_';

    /**
     * Default advisory lock duration in seconds when none supplied.
     *
     * @var int
     */
    private const ADVISORY_LOCK_DEFAULT_DURATION = 3600;

    /**
     * Build the app-config key used to store an advisory (pre-creation) lock.
     *
     * @param string $identifier The arbitrary advisory-lock identifier.
     *
     * @return string The namespaced app-config key.
     */
    private function advisoryLockKey(string $identifier): string
    {
        return self::ADVISORY_LOCK_PREFIX.md5($identifier);
    }//end advisoryLockKey()

    /**
     * Acquire an advisory (pre-creation) lock for an arbitrary identifier that
     * does not (yet) resolve to a stored object.
     *
     * Supports create-then-store flows (e.g. the openbuild wizard locking
     * `createApp:<slug>` before the object exists). The lock is stored in
     * app-config with an expiry timestamp. A still-valid lock raises
     * LockedException; an expired lock is silently overwritten.
     *
     * @param string      $identifier The arbitrary advisory-lock identifier.
     * @param string|null $process    Optional process tag (who holds the lock).
     * @param int|null    $duration   Lock duration in seconds.
     *
     * @return array{uuid: string, locked: array<string, mixed>} Advisory lock result.
     *
     * @throws LockedException If a non-expired advisory lock already exists.
     */
    private function acquireAdvisoryLock(string $identifier, ?string $process=null, ?int $duration=null): array
    {
        $duration = ($duration ?? self::ADVISORY_LOCK_DEFAULT_DURATION);
        $key      = $this->advisoryLockKey(identifier: $identifier);
        $now      = new DateTime();

        $existingRaw = $this->appConfig->getValueString('openregister', $key, '');
        if ($existingRaw !== '') {
            $existing = json_decode($existingRaw, true);
            if (is_array($existing) === true && isset($existing['expiration']) === true) {
                $expiration = new DateTime($existing['expiration']);
                if ($expiration > $now) {
                    throw new LockedException(message: "Advisory lock '{$identifier}' is already held");
                }
            }
        }

        $expiration = (clone $now)->modify("+{$duration} seconds");
        $lock       = [
            'user'       => $this->userSession->getUser()?->getUID(),
            'process'    => $process,
            'created'    => $now->format(DateTime::ATOM),
            'duration'   => $duration,
            'expiration' => $expiration->format(DateTime::ATOM),
            'advisory'   => true,
        ];

        $this->appConfig->setValueString('openregister', $key, json_encode($lock));

        $this->logger->info(
            message: '[LockHandler] Advisory (pre-creation) lock acquired',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'identifier' => $identifier,
                'process'    => $process,
            ]
        );

        return ['uuid' => $identifier, 'locked' => $lock];
    }//end acquireAdvisoryLock()

    /**
     * Release an advisory (pre-creation) lock if one exists for the identifier.
     *
     * @param string $identifier The arbitrary advisory-lock identifier.
     *
     * @return bool True if an advisory lock was found and removed.
     */
    private function releaseAdvisoryLock(string $identifier): bool
    {
        $key = $this->advisoryLockKey(identifier: $identifier);
        if ($this->appConfig->getValueString('openregister', $key, '') === '') {
            return false;
        }

        $this->appConfig->deleteKey('openregister', $key);
        $this->logger->info(
            message: '[LockHandler] Advisory (pre-creation) lock released',
            context: ['file' => __FILE__, 'line' => __LINE__, 'identifier' => $identifier]
        );

        return true;
    }//end releaseAdvisoryLock()

    /**
     * Find an object and get its register/schema context.
     *
     * The `$_rbacBypass` flag is reserved for unlock paths that perform their
     * own caller-vs-lock-holder/owner/manage authorization check on top (see
     * `unlock()`); for lock/isLocked/getLockInfo it stays false so the regular
     * RBAC + multitenancy boundary is respected.
     *
     * @param string $identifier  Object ID or UUID
     * @param bool   $_rbacBypass When true, skip RBAC + multitenancy in the
     *                            mapper lookup (caller MUST perform its own
     *                            authorization gate).
     *
     * @return array{object: \OCA\OpenRegister\Db\ObjectEntity, register: Register|null, schema: Schema|null}
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC bypass flag follows established API patterns.
     */
    private function findObjectWithContext(string $identifier, bool $_rbacBypass=false): array
    {
        $result = $this->magicMapper->findAcrossAllSources(
            identifier: $identifier,
            includeDeleted: false,
            _rbac: ($_rbacBypass === false),
            _multitenancy: ($_rbacBypass === false)
        );

        return [
            'object'   => $result['object'],
            'register' => $result['register'],
            'schema'   => $result['schema'],
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
    private function callerHasSchemaManagePermission(ObjectEntity $object): bool
    {
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
            $schema        = $this->schemaMapper->find((int) $schemaId);
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
     *
     * @return bool True if the caller may unlock this object.
     */
    private function callerMayUnlock(ObjectEntity $object): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $userId = $user->getUID();

        // Admins always pass.
        if ($this->groupManager->isAdmin($userId) === true) {
            return true;
        }

        // Lock holder.
        $lockInfo = $object->getLockInfo();
        if (is_array($lockInfo) === true && ($lockInfo['user'] ?? null) === $userId) {
            return true;
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
     * @param string      $identifier Object ID or UUID
     * @param string|null $process    Process ID (for tracking who locked it)
     * @param int|null    $duration   Lock duration in seconds
     * @param bool        $advisory   When true, skip the object lookup and take the
     *                                appConfig-backed advisory lock directly (used by
     *                                pre-creation guards where no object exists yet)
     *
     * @return array Lock result with locked details and uuid.
     *
     * @throws LockedException If object is already locked.
     * @throws \Exception      If lock operation fails.
     *
     * @spec openspec/specs/object-interactions/spec.md
     */
    public function lock(string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false): array
    {
        $this->logger->debug(
            message: '[LockHandler] Locking object',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'identifier' => $identifier,
                'process'    => $process,
                'duration'   => $duration,
                'advisory'   => $advisory,
            ]
        );

        // Advisory (pre-creation) fast-path: the caller knows the identifier is a
        // synthetic key that does NOT resolve to a stored object (e.g. openbuild's
        // `createApp:<slug>` guard). Go straight to the appConfig-backed advisory
        // lock and skip findObjectWithContext, whose findAcrossAllMagicTables scan
        // would otherwise query every magic table just to conclude "not found".
        if ($advisory === true) {
            return $this->acquireAdvisoryLock(identifier: $identifier, process: $process, duration: $duration);
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
                return $this->acquireAdvisoryLock(
                    identifier: $identifier,
                    process: $process,
                    duration: $duration
                );
            }

            $objectBefore = $context['object'];

            // Use MagicMapper for lock operation.
            $objectAfter = $this->magicMapper->lockObjectEntity(
                entity: $objectBefore,
                register: $context['register'],
                schema: $context['schema'],
                lockDuration: $duration
            );

            $lockResult = [
                'uuid'   => $objectAfter->getUuid(),
                'locked' => $objectAfter->getLocked(),
            ];

            // Record lock action in audit trail.
            $this->auditTrailMapper->createAuditTrail(old: $objectBefore, new: $objectAfter, action: 'lock');

            $this->logger->info(
                message: '[LockHandler] Object locked successfully',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'identifier' => $identifier,
                    'process'    => $process,
                ]
            );

            return $lockResult;
        } catch (LockedException $e) {
            $this->logger->warning(
                message: '[LockHandler] Object is already locked',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'identifier' => $identifier,
                    'error'      => $e->getMessage(),
                ]
            );
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[LockHandler] Failed to lock object',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'identifier' => $identifier,
                    'error'      => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end lock()

    /**
     * Unlock an object
     *
     * Removes the lock from an object, allowing other processes to modify it.
     *
     * @param string $identifier Object ID or UUID
     * @param bool   $advisory   When true, release the appConfig-backed advisory lock
     *                           directly and skip the object lookup / all-tables scan
     *
     * @return true True if unlocked successfully
     *
     * @throws \Exception If unlock operation fails
     *
     * @spec openspec/specs/object-interactions/spec.md
     */
    public function unlock(string $identifier, bool $advisory=false): bool
    {
        $this->logger->debug(
            message: '[LockHandler] Unlocking object',
            context: ['file' => __FILE__, 'line' => __LINE__, 'identifier' => $identifier, 'advisory' => $advisory]
        );

        // Advisory (pre-creation) fast-path — mirror of lock(): release the
        // appConfig-backed advisory lock directly and skip the all-tables scan.
        if ($advisory === true) {
            $this->releaseAdvisoryLock(identifier: $identifier);
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
                $this->releaseAdvisoryLock(identifier: $identifier);
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

            if ($this->callerMayUnlock(object: $objectBefore) === false) {
                $this->logger->warning(
                    message: '[LockHandler] Unauthorized unlock attempt',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'identifier' => $identifier,
                    ]
                );
                throw new Exception('User does not have permission to unlock this object');
            }

            // Use MagicMapper for unlock operation.
            $objectAfter = $this->magicMapper->unlockObjectEntity(
                entity: $objectBefore,
                register: $context['register'],
                schema: $context['schema']
            );

            // Record unlock action in audit trail.
            $this->auditTrailMapper->createAuditTrail(old: $objectBefore, new: $objectAfter, action: 'unlock');

            $this->logger->info(
                message: '[LockHandler] Object unlocked successfully',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'identifier' => $identifier,
                ]
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[LockHandler] Failed to unlock object',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'identifier' => $identifier,
                    'error'      => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end unlock()

    /**
     * Check if an object is locked
     *
     * @param string $identifier Object ID or UUID
     *
     * @return bool True if locked, false otherwise
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function isLocked(string $identifier): bool
    {
        try {
            $context = $this->findObjectWithContext(identifier: $identifier);
            $object  = $context['object'];

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
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'identifier' => $identifier,
                    'error'      => $e->getMessage(),
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
    public function getLockInfo(string $identifier): array|null
    {
        try {
            $context = $this->findObjectWithContext(identifier: $identifier);
            $object  = $context['object'];

            // Delegate to ObjectEntity::getLockInfo() which returns the raw lock payload
            // written by lock() — `{user, process, created, duration, expiration}` — or
            // null if no active (non-expired) lock is present. Map to the public, snake_case
            // representation expected by API consumers without re-inventing the key names.
            $locked = $object->getLockInfo();

            if ($locked === null) {
                return null;
            }

            return [
                'locked_at'  => $locked['created'] ?? null,
                'locked_by'  => $locked['user'] ?? null,
                'process'    => $locked['process'] ?? null,
                'expires_at' => $locked['expiration'] ?? null,
                'duration'   => $locked['duration'] ?? null,
            ];
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[LockHandler] Failed to get lock info',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'identifier' => $identifier,
                    'error'      => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end getLockInfo()
}//end class
