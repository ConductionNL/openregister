<?php

/**
 * Lock Handler
 *
 * Handles object locking and unlocking operations.
 * Locks prevent concurrent modifications to objects.
 *
<<<<<<< HEAD
=======
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
>>>>>>> origin/development
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
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-59
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-59
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
<<<<<<< HEAD
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\LockedException;
=======
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\LockedException;
use OCP\IGroupManager;
use OCP\IUserSession;
>>>>>>> origin/development
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
 */
class LockHandler
{
    /**
     * Constructor
     *
     * @param MagicMapper      $magicMapper      Magic mapper for magic table operations
     * @param AuditTrailMapper $auditTrailMapper Audit trail mapper for logging actions
     * @param LoggerInterface  $logger           PSR-3 logger
<<<<<<< HEAD
=======
     * @param IUserSession     $userSession      User session for authorization checks
     * @param IGroupManager    $groupManager     Group manager for admin checks
     * @param SchemaMapper     $schemaMapper     Schema mapper for resolving manage rules
>>>>>>> origin/development
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
<<<<<<< HEAD
        private readonly LoggerInterface $logger
=======
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly SchemaMapper $schemaMapper
>>>>>>> origin/development
    ) {
    }//end __construct()

    /**
     * Find an object and get its register/schema context.
     *
<<<<<<< HEAD
     * @param string $identifier Object ID or UUID
=======
     * The `$_rbacBypass` flag is reserved for unlock paths that perform their
     * own caller-vs-lock-holder/owner/manage authorization check on top (see
     * `unlock()`); for lock/isLocked/getLockInfo it stays false so the regular
     * RBAC + multitenancy boundary is respected.
     *
     * @param string $identifier  Object ID or UUID
     * @param bool   $_rbacBypass When true, skip RBAC + multitenancy in the
     *                            mapper lookup (caller MUST perform its own
     *                            authorization gate).
>>>>>>> origin/development
     *
     * @return array{object: \OCA\OpenRegister\Db\ObjectEntity, register: Register|null, schema: Schema|null}
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
<<<<<<< HEAD
     */
    private function findObjectWithContext(string $identifier): array
=======
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC bypass flag follows established API patterns.
     */
    private function findObjectWithContext(string $identifier, bool $_rbacBypass=false): array
>>>>>>> origin/development
    {
        $result = $this->magicMapper->findAcrossAllSources(
            identifier: $identifier,
            includeDeleted: false,
<<<<<<< HEAD
            _rbac: false,
            _multitenancy: false
=======
            _rbac: ($_rbacBypass === false),
            _multitenancy: ($_rbacBypass === false)
>>>>>>> origin/development
        );

        return [
            'object'   => $result['object'],
            'register' => $result['register'],
            'schema'   => $result['schema'],
        ];
    }//end findObjectWithContext()

    /**
<<<<<<< HEAD
=======
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
>>>>>>> origin/development
     * Lock an object
     *
     * Locks an object to prevent concurrent modifications.
     * The lock can be associated with a process and have a duration.
     *
     * @param string      $identifier Object ID or UUID
     * @param string|null $process    Process ID (for tracking who locked it)
     * @param int|null    $duration   Lock duration in seconds
     *
     * @return array Lock result with locked details and uuid.
     *
     * @throws LockedException If object is already locked.
     * @throws \Exception      If lock operation fails.
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-59
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-59
     */
    public function lock(string $identifier, ?string $process=null, ?int $duration=null): array
    {
        $this->logger->debug(
            message: '[LockHandler] Locking object',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'identifier' => $identifier,
                'process'    => $process,
                'duration'   => $duration,
            ]
        );

        try {
            // Find the object and its register/schema context.
            $context      = $this->findObjectWithContext(identifier: $identifier);
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
     *
     * @return true True if unlocked successfully
     *
     * @throws \Exception If unlock operation fails
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-59
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-59
     */
    public function unlock(string $identifier): bool
    {
        $this->logger->debug(
            message: '[LockHandler] Unlocking object',
            context: ['file' => __FILE__, 'line' => __LINE__, 'identifier' => $identifier]
        );

        try {
            // Find the object and its register/schema context.
<<<<<<< HEAD
            $context      = $this->findObjectWithContext(identifier: $identifier);
            $objectBefore = $context['object'];

=======
            //
            // SECURITY: bypass RBAC + multitenancy on the read so we can
            // resolve cross-tenant lock holders, but perform an explicit
            // authorization check before mutating state. Without the bypass
            // a non-owner who was nonetheless the lock holder could not
            // resolve the object at all and would be blocked from
            // releasing their own lock; the explicit `callerMayUnlock`
            // gate replaces the wave-3 C14 "any authenticated user can
            // unlock anything" behavior.
            $context      = $this->findObjectWithContext(identifier: $identifier, _rbacBypass: true);
            $objectBefore = $context['object'];

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

>>>>>>> origin/development
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
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-12
     */
    public function isLocked(string $identifier): bool
    {
        try {
            $context = $this->findObjectWithContext(identifier: $identifier);
            $object  = $context['object'];

<<<<<<< HEAD
            // Check the locked property on the ObjectEntity.
            $locked = $object->getLocked();

            if (empty($locked) === true) {
                return false;
            }

            // Check if lock has expired.
            if (isset($locked['expiresAt']) === true) {
                $expiryDate = new DateTime($locked['expiresAt']);
                if ($expiryDate < new DateTime()) {
                    return false;
                    // Lock expired.
                }
            }

            return true;
=======
            // Delegate to the canonical ObjectEntity::isLocked() implementation,
            // which understands both the current `{user, process, created, duration, expiration}`
            // schema (see ObjectEntity::lock() at lib/Db/ObjectEntity.php:1042-1066) and the
            // legacy lockedAt+duration fallback. Reading bespoke keys ('expiresAt', 'userId',
            // 'lockedAt') here would never match what lock() writes, so a stale/expired lock
            // could never be detected via this code path.
            return $object->isLocked();
>>>>>>> origin/development
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
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-12
     */
    public function getLockInfo(string $identifier): array|null
    {
        try {
            $context = $this->findObjectWithContext(identifier: $identifier);
            $object  = $context['object'];

<<<<<<< HEAD
            $locked = $object->getLocked();

            if (empty($locked) === true) {
=======
            // Delegate to ObjectEntity::getLockInfo() which returns the raw lock payload
            // written by lock() — `{user, process, created, duration, expiration}` — or
            // null if no active (non-expired) lock is present. Map to the public, snake_case
            // representation expected by API consumers without re-inventing the key names.
            $locked = $object->getLockInfo();

            if ($locked === null) {
>>>>>>> origin/development
                return null;
            }

            return [
<<<<<<< HEAD
                'locked_at'  => $locked['lockedAt'] ?? null,
                'locked_by'  => $locked['userId'] ?? null,
                'process'    => $locked['process'] ?? null,
                'expires_at' => $locked['expiresAt'] ?? null,
=======
                'locked_at'  => $locked['created'] ?? null,
                'locked_by'  => $locked['user'] ?? null,
                'process'    => $locked['process'] ?? null,
                'expires_at' => $locked['expiration'] ?? null,
                'duration'   => $locked['duration'] ?? null,
>>>>>>> origin/development
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
