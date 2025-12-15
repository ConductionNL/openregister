<?php

/**
 * Lock Handler
 *
 * Handles object locking and unlocking operations.
 * Locks prevent concurrent modifications to objects.
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

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Exception\LockedException;
use Psr\Log\LoggerInterface;

/**
 * LockHandler
 *
 * Responsible for managing object locks to prevent concurrent modifications.
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
     * @param LoggerInterface    $logger             PSR-3 logger
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


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
     * @return array The locked object data
     *
     * @throws LockedException If object is already locked
     * @throws \Exception      If lock operation fails
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
            $object = $this->objectEntityMapper->lockObject(
                identifier: $identifier,
                process: $process,
                duration: $duration
            );

            $this->logger->info(
                message: '[LockHandler] Object locked successfully',
                context: [
                    'identifier' => $identifier,
                    'process'    => $process,
                ]
            );

            return $object;
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
     *
     * @param string $identifier Object ID or UUID
     *
     * @return bool True if unlocked successfully
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
            // Call the mapper's unlock method.
            $this->objectEntityMapper->unlockObject(identifier: $identifier);

            $this->logger->info(
                message: '[LockHandler] Object unlocked successfully',
                context: ['identifier' => $identifier]
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
     * @param string $identifier Object ID or UUID
     *
     * @return bool True if locked, false otherwise
     */
    public function isLocked(string $identifier): bool
    {
        try {
            $object = $this->objectEntityMapper->find(id: $identifier);

            // Check if object has a lock_date and it's still valid.
            if (empty($object['lock_date']) === true) {
                return false;
            }

            // Check if lock has expired (if lock_duration is set).
            if (empty($object['lock_duration']) === false) {
                $lockDate     = new \DateTime($object['lock_date']);
                $lockDuration = (int) $object['lock_duration'];
                $expiryDate   = $lockDate->modify("+{$lockDuration} seconds");

                if ($expiryDate < new \DateTime()) {
                    return false;
                    // Lock expired.
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
     *
     * @param string $identifier Object ID or UUID
     *
     * @return array|null Lock information or null if not locked
     */
    public function getLockInfo(string $identifier): ?array
    {
        try {
            $object = $this->objectEntityMapper->find(id: $identifier);

            if (empty($object['lock_date']) === true) {
                return null;
            }

            $lockInfo = [
                'locked_at' => $object['lock_date'],
                'process'   => $object['lock_process'] ?? null,
                'duration'  => $object['lock_duration'] ?? null,
            ];

            // Calculate expiry if duration is set.
            if (empty($object['lock_duration']) === false) {
                $lockDate   = new \DateTime($object['lock_date']);
                $duration   = (int) $object['lock_duration'];
                $expiryDate = $lockDate->modify("+{$duration} seconds");

                $lockInfo['expires_at'] = $expiryDate->format('Y-m-d H:i:s');
                $lockInfo['is_expired'] = $expiryDate < new \DateTime();
            }

            return $lockInfo;
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
