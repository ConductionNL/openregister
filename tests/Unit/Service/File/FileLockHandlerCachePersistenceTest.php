<?php

declare(strict_types=1);

namespace Unit\Service\File;

use OCA\OpenRegister\Service\File\FileLockHandler;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the lock state crosses request boundaries via the distributed
 * cache. Asserts: a handler instance writes to the cache; a SECOND
 * handler instance backed by the SAME ICache reads back the lock as if
 * the next request landed on a different PHP-FPM worker.
 *
 * Background: prior implementation kept locks in a private `$locks`
 * array on the handler, so a fresh handler in the next request saw an
 * empty map even though the user had just locked the file. Caught
 * during the 2026-05-01 file-actions audit.
 */
class FileLockHandlerCachePersistenceTest extends TestCase
{
    public function testLockSurvivesAcrossHandlerInstancesViaSharedCache(): void
    {
        // One in-memory ICache shared across both handler instances —
        // emulates the distributed cache being persistent between requests.
        $cache = $this->makeInMemoryCache();
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);

        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-1');
        $session->method('getUser')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $h1 = new FileLockHandler($factory, $session, $groups, $logger);
        $h1->lockFile(fileId: 42);

        // Brand-new handler instance — the lock map on $h1 is unreachable;
        // only the shared cache can prove cross-request survival.
        $h2 = new FileLockHandler($factory, $session, $groups, $logger);

        $this->assertTrue($h2->isLocked(fileId: 42));
        $info = $h2->getLockInfo(fileId: 42);
        $this->assertNotNull($info);
        $this->assertSame('user-1', $info['lockedBy']);
    }

    public function testCacheTtlElidesExpiredLockOnRead(): void
    {
        // Cache that returns null for any get — emulates TTL eviction.
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(null);
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);

        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-1');
        $session->method('getUser')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $handler = new FileLockHandler($factory, $session, $groups, $logger);
        $this->assertFalse($handler->isLocked(fileId: 42));
        $this->assertNull($handler->getLockInfo(fileId: 42));
    }

    public function testDefensiveTtlRecheckClearsStaleEntry(): void
    {
        // Cache that hands back an entry whose `expiresAt` already passed —
        // the handler MUST notice and remove it rather than trust the cache.
        $stale = [
            'lockedBy'  => 'user-1',
            'lockedAt'  => '2024-01-01T00:00:00+00:00',
            'expiresAt' => '2024-01-01T00:01:00+00:00',
        ];

        $cache = $this->createMock(ICache::class);
        $cache->expects($this->once())->method('get')->willReturn($stale);
        $cache->expects($this->once())->method('remove');

        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);

        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-1');
        $session->method('getUser')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $handler = new FileLockHandler($factory, $session, $groups, $logger);
        $this->assertNull($handler->getLockInfo(fileId: 42));
    }

    public function testUnlockRemovesEntryFromSharedCache(): void
    {
        $cache = $this->makeInMemoryCache();
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);

        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-1');
        $session->method('getUser')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $handler = new FileLockHandler($factory, $session, $groups, $logger);
        $handler->lockFile(fileId: 42);
        $handler->unlockFile(fileId: 42);

        // Same shared cache + a brand-new handler — must NOT see the lock.
        $h2 = new FileLockHandler($factory, $session, $groups, $logger);
        $this->assertFalse($h2->isLocked(fileId: 42));
    }

    /**
     * Returns an ICache mock backed by a real PHP array — emulates a
     * distributed cache that persists across handler instances within
     * the same test method.
     */
    private function makeInMemoryCache(): ICache
    {
        return new class implements ICache {
            private array $store = [];
            public function get($key)
            {
                return ($this->store[$key] ?? null);
            }
            public function set($key, $value, $ttl = 0): bool
            {
                $this->store[$key] = $value;
                return true;
            }
            public function hasKey($key): bool
            {
                return isset($this->store[$key]);
            }
            public function remove($key): bool
            {
                unset($this->store[$key]);
                return true;
            }
            public function clear($prefix = ''): bool
            {
                $this->store = [];
                return true;
            }
            public static function isAvailable(): bool
            {
                return true;
            }
        };
    }
}
