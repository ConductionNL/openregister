<?php

/**
 * FileLockHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use Exception;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles file locking operations.
 *
 * Provides advisory file-level locking with TTL expiry and admin force-unlock.
 * Lock metadata is stored as in-memory state (to be backed by DB columns in FileMapper).
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileLockHandler
{

    /**
     * Default lock TTL in minutes.
     *
     * @var int
     */
    private const DEFAULT_TTL_MINUTES = 30;

    /**
     * In-memory lock storage keyed by file ID.
     *
     * @var array<int, array{lockedBy: string, lockedAt: DateTime, expiresAt: DateTime}>
     */
    private array $locks = [];

    /**
     * Constructor for FileLockHandler.
     *
     * @param IUserSession    $userSession  User session for current user context.
     * @param IGroupManager   $groupManager Group manager for admin checks.
     * @param LoggerInterface $logger       Logger for logging operations.
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Lock a file.
     *
     * @param int      $fileId    The file ID to lock.
     * @param int|null $ttlMinutes Optional TTL in minutes (default: 30).
     *
     * @return array Lock metadata.
     *
     * @throws Exception If the file is already locked by another user.
     */
    public function lockFile(int $fileId, ?int $ttlMinutes = null): array
    {
        $currentUserId = $this->getCurrentUserId();
        $ttl = $ttlMinutes ?? self::DEFAULT_TTL_MINUTES;

        // Check for existing lock.
        $existingLock = $this->getLockInfo($fileId);
        if ($existingLock !== null) {
            if ($existingLock['lockedBy'] === $currentUserId) {
                // Refresh the lock for the same user.
                return $this->setLock($fileId, $currentUserId, $ttl);
            }

            throw new Exception(
                'File is locked by ' . $existingLock['lockedBy']
            );
        }

        return $this->setLock($fileId, $currentUserId, $ttl);
    }//end lockFile()

    /**
     * Unlock a file.
     *
     * @param int  $fileId The file ID to unlock.
     * @param bool $force  Force unlock (admin only).
     *
     * @return array{locked: false} Unlock confirmation.
     *
     * @throws Exception If the current user is not the lock owner and not admin.
     */
    public function unlockFile(int $fileId, bool $force = false): array
    {
        $currentUserId = $this->getCurrentUserId();
        $lockInfo = $this->getLockInfo($fileId);

        if ($lockInfo === null) {
            return ['locked' => false];
        }

        // Allow unlock if: same user, admin with force, or no lock.
        if ($lockInfo['lockedBy'] !== $currentUserId && $force === false) {
            throw new Exception('Only the lock owner or an admin can unlock this file');
        }

        if ($force === true && $this->isCurrentUserAdmin() === false) {
            throw new Exception('Only administrators can force-unlock files');
        }

        unset($this->locks[$fileId]);

        $this->logger->info(
            message: "[FileLockHandler] File {$fileId} unlocked by {$currentUserId}" . ($force ? ' (force)' : ''),
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        return ['locked' => false];
    }//end unlockFile()

    /**
     * Check if a file is locked.
     *
     * Automatically clears expired locks.
     *
     * @param int $fileId The file ID to check.
     *
     * @return bool True if the file is currently locked.
     */
    public function isLocked(int $fileId): bool
    {
        return $this->getLockInfo($fileId) !== null;
    }//end isLocked()

    /**
     * Get lock information for a file.
     *
     * Returns null if the file is not locked or the lock has expired.
     *
     * @param int $fileId The file ID.
     *
     * @return array|null Lock metadata or null.
     */
    public function getLockInfo(int $fileId): ?array
    {
        if (isset($this->locks[$fileId]) === false) {
            return null;
        }

        $lock = $this->locks[$fileId];

        // Check TTL expiry.
        $now = new DateTime();
        if ($lock['expiresAt'] <= $now) {
            unset($this->locks[$fileId]);
            $this->logger->info(
                message: "[FileLockHandler] Lock on file {$fileId} expired, auto-cleared",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        return $lock;
    }//end getLockInfo()

    /**
     * Check if the current user can modify a locked file.
     *
     * The lock owner can always modify. Non-owners are blocked.
     *
     * @param int $fileId The file ID to check.
     *
     * @return void
     *
     * @throws Exception If the file is locked by another user.
     */
    public function assertCanModify(int $fileId): void
    {
        $lockInfo = $this->getLockInfo($fileId);
        if ($lockInfo === null) {
            return;
        }

        $currentUserId = $this->getCurrentUserId();
        if ($lockInfo['lockedBy'] !== $currentUserId) {
            throw new Exception('File is locked by ' . $lockInfo['lockedBy']);
        }
    }//end assertCanModify()

    /**
     * Set a lock on a file.
     *
     * @param int    $fileId     The file ID.
     * @param string $userId     The user ID.
     * @param int    $ttlMinutes The TTL in minutes.
     *
     * @return array Lock metadata.
     */
    private function setLock(int $fileId, string $userId, int $ttlMinutes): array
    {
        $now = new DateTime();
        $expires = (clone $now)->modify("+{$ttlMinutes} minutes");

        $this->locks[$fileId] = [
            'lockedBy'  => $userId,
            'lockedAt'  => $now,
            'expiresAt' => $expires,
        ];

        $this->logger->info(
            message: "[FileLockHandler] File {$fileId} locked by {$userId} until {$expires->format('c')}",
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        return [
            'locked'    => true,
            'lockedBy'  => $userId,
            'lockedAt'  => $now->format('c'),
            'expiresAt' => $expires->format('c'),
        ];
    }//end setLock()

    /**
     * Get the current user ID.
     *
     * @return string The current user ID.
     *
     * @throws Exception If no user is logged in.
     */
    private function getCurrentUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        return $user->getUID();
    }//end getCurrentUserId()

    /**
     * Check if the current user is an admin.
     *
     * @return bool True if the current user is in the admin group.
     */
    private function isCurrentUserAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());
    }//end isCurrentUserAdmin()
}//end class
