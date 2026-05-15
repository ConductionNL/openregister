<?php

/**
 * FileLockHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles file locking operations.
 *
 * Provides advisory file-level locking with TTL expiry and admin force-unlock.
 *
 * Lock state is persisted in the distributed-cache layer (APCu / Redis,
 * whichever the operator has wired) keyed on `openregister:file-lock:{fileId}`.
 * That layer is shared across PHP-FPM workers within the same Nextcloud
 * instance so locks survive between requests, which an in-memory map on the
 * handler instance cannot. TTL expiry rides on the cache TTL itself: when
 * the lock TTL elapses the cache entry vanishes without an extra purge pass.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.1.0
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
     * Cache key prefix for per-file lock state.
     *
     * @var string
     */
    private const CACHE_PREFIX = 'openregister:file-lock:';

    /**
     * Distributed cache used to persist locks across requests.
     *
     * Null when no cache backend is configured — callers fall back to an
     * in-memory replacement (single-request scope only) so the handler
     * still works in test/CI environments without APCu/Redis.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Per-instance fallback when the distributed cache isn't configured.
     *
     * @var array<int, array{lockedBy: string, lockedAt: string, expiresAt: string}>
     */
    private array $localFallback = [];

    /**
     * Constructor for FileLockHandler.
     *
     * @param ICacheFactory   $cacheFactory Distributed-cache factory; falls back
     *                                      to a per-instance map when no cache
     *                                      backend is wired.
     * @param IUserSession    $userSession  User session for current user context.
     * @param IGroupManager   $groupManager Group manager for admin checks.
     * @param LoggerInterface $logger       Logger for logging operations.
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger
    ) {
        try {
            $this->cache = $cacheFactory->createDistributed('openregister_file_locks');
        } catch (\Throwable $e) {
            $this->cache = null;
            $this->logger->warning(
                message: '[FileLockHandler] Distributed cache unavailable; falling back to per-instance map (volatile).',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }

    }//end __construct()

    /**
     * Lock a file.
     *
     * @param int      $fileId     The file ID to lock.
     * @param int|null $ttlMinutes Optional TTL in minutes (default: 30).
     *
     * @return array Lock metadata.
     *
     * @throws Exception If the file is already locked by another user.
     */
    public function lockFile(int $fileId, ?int $ttlMinutes=null): array
    {
        $currentUserId = $this->getCurrentUserId();
        $ttl           = $ttlMinutes ?? self::DEFAULT_TTL_MINUTES;

        // Check for existing lock.
        $existingLock = $this->getLockInfo(fileId: $fileId);
        if ($existingLock !== null) {
            if ($existingLock['lockedBy'] === $currentUserId) {
                // Refresh the lock for the same user.
                return $this->setLock(fileId: $fileId, userId: $currentUserId, ttlMinutes: $ttl);
            }

            throw new Exception(
                'File is locked by '.$existingLock['lockedBy']
            );
        }

        return $this->setLock(fileId: $fileId, userId: $currentUserId, ttlMinutes: $ttl);

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
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function unlockFile(int $fileId, bool $force=false): array
    {
        $currentUserId = $this->getCurrentUserId();
        $lockInfo      = $this->getLockInfo(fileId: $fileId);

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

        $this->removeLockEntry(fileId: $fileId);

        $this->logger->info(
            message: "[FileLockHandler] File {$fileId} unlocked by {$currentUserId}".($force === true ? ' (force)' : ''),
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
        return $this->getLockInfo(fileId: $fileId) !== null;

    }//end isLocked()

    /**
     * Get lock information for a file.
     *
     * Returns null if the file is not locked or the lock has expired. The
     * cache layer naturally evicts entries past their TTL but we re-check
     * the stored `expiresAt` defensively in case clock drift / different
     * cache backend semantics let an expired entry leak through.
     *
     * @param int $fileId The file ID.
     *
     * @return array|null Lock metadata or null.
     */
    public function getLockInfo(int $fileId): ?array
    {
        $entry = $this->readLockEntry(fileId: $fileId);
        if ($entry === null) {
            return null;
        }

        // Defensive TTL re-check (cache may serve a slightly stale entry).
        $now = new DateTime();
        try {
            $expiresAt = new DateTime($entry['expiresAt']);
        } catch (\Throwable $e) {
            // Malformed entry — drop it.
            $this->removeLockEntry(fileId: $fileId);
            return null;
        }

        if ($expiresAt <= $now) {
            $this->removeLockEntry(fileId: $fileId);
            $this->logger->info(
                message: "[FileLockHandler] Lock on file {$fileId} expired, auto-cleared",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        return [
            'lockedBy'  => $entry['lockedBy'],
            'lockedAt'  => new DateTime($entry['lockedAt']),
            'expiresAt' => $expiresAt,
        ];

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
        $lockInfo = $this->getLockInfo(fileId: $fileId);
        if ($lockInfo === null) {
            return;
        }

        $currentUserId = $this->getCurrentUserId();
        if ($lockInfo['lockedBy'] !== $currentUserId) {
            throw new Exception('File is locked by '.$lockInfo['lockedBy']);
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
        $now     = new DateTime();
        $expires = (clone $now)->modify("+{$ttlMinutes} minutes");

        $entry = [
            'lockedBy'  => $userId,
            'lockedAt'  => $now->format('c'),
            'expiresAt' => $expires->format('c'),
        ];

        $this->writeLockEntry(fileId: $fileId, entry: $entry, ttlSeconds: ($ttlMinutes * 60));

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
     * Read a lock entry from the persistence layer.
     *
     * @param int $fileId File ID.
     *
     * @return array{lockedBy: string, lockedAt: string, expiresAt: string}|null
     */
    private function readLockEntry(int $fileId): ?array
    {
        if ($this->cache !== null) {
            $entry = $this->cache->get(self::CACHE_PREFIX.$fileId);
            return is_array($entry) === true ? $entry : null;
        }

        return ($this->localFallback[$fileId] ?? null);

    }//end readLockEntry()

    /**
     * Persist a lock entry.
     *
     * @param int                                                          $fileId     File ID.
     * @param array{lockedBy: string, lockedAt: string, expiresAt: string} $entry      Entry payload.
     * @param int                                                          $ttlSeconds Cache TTL in seconds.
     *
     * @return void
     */
    private function writeLockEntry(int $fileId, array $entry, int $ttlSeconds): void
    {
        if ($this->cache !== null) {
            $this->cache->set(self::CACHE_PREFIX.$fileId, $entry, $ttlSeconds);
            return;
        }

        $this->localFallback[$fileId] = $entry;

    }//end writeLockEntry()

    /**
     * Remove a lock entry from persistence.
     *
     * @param int $fileId File ID.
     *
     * @return void
     */
    private function removeLockEntry(int $fileId): void
    {
        if ($this->cache !== null) {
            $this->cache->remove(self::CACHE_PREFIX.$fileId);
            return;
        }

        unset($this->localFallback[$fileId]);

    }//end removeLockEntry()

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
