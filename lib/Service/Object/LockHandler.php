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
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-59
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\LockedException;
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
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Find an object and get its register/schema context.
     *
     * @param string $identifier Object ID or UUID
     *
     * @return array{object: \OCA\OpenRegister\Db\ObjectEntity, register: Register|null, schema: Schema|null}
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     */
    private function findObjectWithContext(string $identifier): array
    {
        $result = $this->magicMapper->findAcrossAllSources(
            identifier: $identifier,
            includeDeleted: false,
            _rbac: false,
            _multitenancy: false
        );

        return [
            'object'   => $result['object'],
            'register' => $result['register'],
            'schema'   => $result['schema'],
        ];
    }//end findObjectWithContext()

    /**
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
     */
    public function unlock(string $identifier): bool
    {
        $this->logger->debug(
            message: '[LockHandler] Unlocking object',
            context: ['file' => __FILE__, 'line' => __LINE__, 'identifier' => $identifier]
        );

        try {
            // Find the object and its register/schema context.
            $context      = $this->findObjectWithContext(identifier: $identifier);
            $objectBefore = $context['object'];

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
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-12
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
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-12
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
