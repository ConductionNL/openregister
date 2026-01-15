<?php

/**
 * Lock Handler
 *
 * Handles object locking and unlocking operations.
 * Locks prevent concurrent modifications to objects.
 * Supports both blob storage and magic table objects.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntityMapper;
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
 * Works with both blob storage and magic table objects.
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
     * @param ObjectEntityMapper $objectEntityMapper Object entity mapper
     * @param MagicMapper        $magicMapper        Magic mapper for magic table operations
     * @param AuditTrailMapper   $auditTrailMapper   Audit trail mapper for logging actions
     * @param LoggerInterface    $logger             PSR-3 logger
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly MagicMapper $magicMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Find an object across all storage sources and get its context.
     *
     * @param string $identifier Object ID or UUID
     *
     * @return array{object: \OCA\OpenRegister\Db\ObjectEntity, register: Register|null, schema: Schema|null, isMagic: bool}
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     */
    private function findObjectWithContext(string $identifier): array
    {
        $result = $this->objectEntityMapper->findAcrossAllSources(
            identifier: $identifier,
            includeDeleted: false,
            _rbac: false,
            _multitenancy: false
        );

        // Determine if this is a magic table object.
        $isMagic = false;
        if ($result['register'] !== null && $result['schema'] !== null) {
            $isMagic = $result['register']->isMagicMappingEnabledForSchema(
                schemaId: $result['schema']->getId(),
                schemaSlug: $result['schema']->getSlug()
            );
        }

        return [
            'object' => $result['object'],
            'register' => $result['register'],
            'schema' => $result['schema'],
            'isMagic' => $isMagic,
        ];
    }

    /**
     * Lock an object
     *
     * Locks an object to prevent concurrent modifications.
     * The lock can be associated with a process and have a duration.
     * Works with both blob storage and magic table objects.
     *
     * @param string      $identifier Object ID or UUID
     * @param string|null $process    Process ID (for tracking who locked it)
     * @param int|null    $duration   Lock duration in seconds
     *
     * @return array Lock result with locked details and uuid.
     *
     * @throws LockedException If object is already locked.
     * @throws \Exception      If lock operation fails.
     */
    public function lock(string $identifier, ?string $process=null, ?int $duration=null): array
    {
        $this->logger->debug(
            message: '[LockHandler] Locking object',
            context: [
                'identifier' => $identifier,
                'process'    => $process,
                'duration'   => $duration,
            ]
        );

        try {
            // Find the object and determine its storage type.
            $context = $this->findObjectWithContext($identifier);
            $objectBefore = $context['object'];

            if ($context['isMagic'] === true) {
                // Use MagicMapper for magic table objects.
                $objectAfter = $this->magicMapper->lockObjectEntity(
                    entity: $objectBefore,
                    register: $context['register'],
                    schema: $context['schema'],
                    lockDuration: $duration
                );

                $lockResult = [
                    'uuid' => $objectAfter->getUuid(),
                    'locked' => $objectAfter->getLocked(),
                ];
            } else {
                // Use ObjectEntityMapper for blob storage objects.
                $lockResult = $this->objectEntityMapper->lockObject($identifier, $duration);

                // Reload the object after locking to get updated state.
                $reloadContext = $this->findObjectWithContext($identifier);
                $objectAfter = $reloadContext['object'];
            }

            // Record lock action in audit trail.
            $this->auditTrailMapper->createAuditTrail(old: $objectBefore, new: $objectAfter, action: 'lock');

            $this->logger->info(
                message: '[LockHandler] Object locked successfully',
                context: [
                    'identifier' => $identifier,
                    'process'    => $process,
                    'isMagic'    => $context['isMagic'],
                ]
            );

            return $lockResult;
        } catch (LockedException $e) {
            $this->logger->warning(
                message: '[LockHandler] Object is already locked',
                context: [
                    'identifier' => $identifier,
                    'error'      => $e->getMessage(),
                ]
            );
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[LockHandler] Failed to lock object',
                context: [
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
     * Works with both blob storage and magic table objects.
     *
     * @param string $identifier Object ID or UUID
     *
     * @return true True if unlocked successfully
     *
     * @throws \Exception If unlock operation fails
     */
    public function unlock(string $identifier): bool
    {
        $this->logger->debug(
            message: '[LockHandler] Unlocking object',
            context: ['identifier' => $identifier]
        );

        try {
            // Find the object and determine its storage type.
            $context = $this->findObjectWithContext($identifier);
            $objectBefore = $context['object'];

            if ($context['isMagic'] === true) {
                // Use MagicMapper for magic table objects.
                $objectAfter = $this->magicMapper->unlockObjectEntity(
                    entity: $objectBefore,
                    register: $context['register'],
                    schema: $context['schema']
                );
            } else {
                // Use ObjectEntityMapper for blob storage objects.
                $this->objectEntityMapper->unlockObject(uuid: $identifier);

                // Reload the object after unlocking to get updated state.
                $reloadContext = $this->findObjectWithContext($identifier);
                $objectAfter = $reloadContext['object'];
            }

            // Record unlock action in audit trail.
            $this->auditTrailMapper->createAuditTrail(old: $objectBefore, new: $objectAfter, action: 'unlock');

            $this->logger->info(
                message: '[LockHandler] Object unlocked successfully',
                context: [
                    'identifier' => $identifier,
                    'isMagic'    => $context['isMagic'],
                ]
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[LockHandler] Failed to unlock object',
                context: [
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
     * Works with both blob storage and magic table objects.
     *
     * @param string $identifier Object ID or UUID
     *
     * @return bool True if locked, false otherwise
     */
    public function isLocked(string $identifier): bool
    {
        try {
            $context = $this->findObjectWithContext($identifier);
            $object = $context['object'];

            // Check the locked property on the ObjectEntity.
            $locked = $object->getLocked();

            if (empty($locked) === true) {
                return false;
            }

            // Check if lock has expired.
            if (isset($locked['expiresAt']) === true) {
                $expiryDate = new DateTime($locked['expiresAt']);
                if ($expiryDate < new DateTime()) {
                    return false; // Lock expired.
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[LockHandler] Failed to check lock status',
                context: [
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
     * Works with both blob storage and magic table objects.
     *
     * @param string $identifier Object ID or UUID
     *
     * @return array|null Lock info array or null if not locked.
     */
    public function getLockInfo(string $identifier): array|null
    {
        try {
            $context = $this->findObjectWithContext($identifier);
            $object = $context['object'];

            $locked = $object->getLocked();

            if (empty($locked) === true) {
                return null;
            }

            return [
                'locked_at'  => $locked['lockedAt'] ?? null,
                'locked_by'  => $locked['userId'] ?? null,
                'process'    => $locked['process'] ?? null,
                'expires_at' => $locked['expiresAt'] ?? null,
                'is_magic'   => $context['isMagic'],
            ];
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[LockHandler] Failed to get lock info',
                context: [
                    'identifier' => $identifier,
                    'error'      => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end getLockInfo()

}//end class
