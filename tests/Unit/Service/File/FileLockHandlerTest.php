<?php

declare(strict_types=1);

namespace Unit\Service\File;

use DateTime;
use OCA\OpenRegister\Db\FileLock;
use OCA\OpenRegister\Db\FileLockMapper;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileLockHandlerTest extends TestCase
{
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private LoggerInterface&MockObject $logger;
    private FileLockMapper&MockObject $fileLockMapper;

    /**
     * Simulated `openregister_file_locks` table, keyed by file ID. Shared by
     * every FileLockHandler built via newHandler() in a test, the same way
     * every PHP worker in production shares one database row per file: this
     * is what lets us prove a lock survives a fresh handler instance instead
     * of only existing in that one instance's memory.
     *
     * @var array<int, FileLock>
     */
    private array $lockStore = [];

    private int $nextId = 1;

    /**
     * The user ID FileLockHandler sees as "currently logged in". Mutable so
     * a single test can simulate two different requests by two different
     * users acting on the same underlying lock store.
     */
    private ?string $currentUserId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockStore     = [];
        $this->nextId        = 1;
        $this->currentUserId = null;

        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->userSession->method('getUser')->willReturnCallback(function (): ?IUser {
            if ($this->currentUserId === null) {
                return null;
            }

            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($this->currentUserId);
            return $user;
        });

        $this->fileLockMapper = $this->createMock(FileLockMapper::class);

        $this->fileLockMapper->method('findByFileId')->willReturnCallback(
            fn (int $fileId): ?FileLock => $this->lockStore[$fileId] ?? null
        );

        $this->fileLockMapper->method('deleteByFileId')->willReturnCallback(
            function (int $fileId): void {
                unset($this->lockStore[$fileId]);
            }
        );

        $this->fileLockMapper->method('insert')->willReturnCallback(
            function (FileLock $lock): FileLock {
                $lock->setId($this->nextId++);
                $this->lockStore[$lock->getFileId()] = $lock;
                return $lock;
            }
        );

        $this->fileLockMapper->method('update')->willReturnCallback(
            function (FileLock $lock): FileLock {
                $this->lockStore[$lock->getFileId()] = $lock;
                return $lock;
            }
        );
    }

    /**
     * Build a fresh FileLockHandler backed by the shared lock store.
     *
     * Each call simulates a new request/PHP worker picking up a handler
     * from the DI container: nothing carries over except what is in
     * $this->lockStore (the "database").
     */
    private function newHandler(): FileLockHandler
    {
        return new FileLockHandler(
            $this->userSession,
            $this->groupManager,
            $this->logger,
            $this->fileLockMapper
        );
    }

    private function loginAs(string $userId): void
    {
        $this->currentUserId = $userId;
    }

    /**
     * Seed an already-expired lock row directly into the store, bypassing
     * lockFile()/setLock() so the expiry test does not depend on how the
     * TTL arithmetic parses negative durations.
     */
    private function seedExpiredLock(int $fileId, string $lockedBy): void
    {
        $lock = new FileLock();
        $lock->setId($this->nextId++);
        $lock->setFileId($fileId);
        $lock->setLockedBy($lockedBy);
        $lock->setLockedAt((new DateTime())->modify('-31 minutes'));
        $lock->setLockExpires((new DateTime())->modify('-1 minute'));
        $this->lockStore[$fileId] = $lock;
    }

    public function testLockFileSuccess(): void
    {
        $this->loginAs('user-1');

        $result = $this->newHandler()->lockFile(42);

        $this->assertTrue($result['locked']);
        $this->assertEquals('user-1', $result['lockedBy']);
        $this->assertArrayHasKey('lockedAt', $result);
        $this->assertArrayHasKey('expiresAt', $result);
    }

    /**
     * The whole point of persisting locks via FileLockMapper: a lock set by
     * one handler instance (one request) must be visible to a completely
     * separate handler instance (a later request) as long as the backing
     * store still has the row. The old in-memory implementation could not
     * pass this test across two independently constructed handlers.
     */
    public function testLockPersistsAcrossHandlerInstances(): void
    {
        $this->loginAs('user-1');
        $this->newHandler()->lockFile(42);

        $laterHandler = $this->newHandler();

        $this->assertTrue($laterHandler->isLocked(42));
        $info = $laterHandler->getLockInfo(42);
        $this->assertSame('user-1', $info['lockedBy']);
    }

    public function testLockFileConflict(): void
    {
        $this->loginAs('user-1');
        $this->newHandler()->lockFile(42);

        $this->loginAs('user-2');
        $handler2 = $this->newHandler();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File is locked by user-1');
        $handler2->lockFile(42);
    }

    public function testUnlockByOwner(): void
    {
        $this->loginAs('user-1');
        $handler = $this->newHandler();

        $handler->lockFile(42);
        $result = $handler->unlockFile(42);

        $this->assertFalse($result['locked']);
        $this->assertFalse($handler->isLocked(42));
    }

    public function testUnlockAlreadyUnlocked(): void
    {
        $this->loginAs('user-1');

        $result = $this->newHandler()->unlockFile(42);
        $this->assertFalse($result['locked']);
    }

    public function testUnlockByNonOwnerDenied(): void
    {
        $this->loginAs('user-1');
        $this->newHandler()->lockFile(42);

        $this->loginAs('user-2');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Only the lock owner or an admin can unlock this file');
        $this->newHandler()->unlockFile(42);
    }

    public function testAdminForceUnlock(): void
    {
        $this->loginAs('user-1');
        $this->newHandler()->lockFile(42);

        $this->loginAs('admin-1');
        $this->groupManager->method('isAdmin')->with('admin-1')->willReturn(true);

        $result = $this->newHandler()->unlockFile(42, true);

        $this->assertFalse($result['locked']);
        $this->assertFalse($this->newHandler()->isLocked(42));
    }

    public function testForceUnlockByNonAdminDenied(): void
    {
        $this->loginAs('user-1');
        $this->newHandler()->lockFile(42);

        $this->loginAs('user-2');
        $this->groupManager->method('isAdmin')->with('user-2')->willReturn(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Only administrators can force-unlock files');
        $this->newHandler()->unlockFile(42, true);
    }

    public function testAssertCanModifyUnlockedFile(): void
    {
        $this->loginAs('user-1');
        // Should not throw for unlocked file.
        $this->newHandler()->assertCanModify(42);
        $this->assertTrue(true); // If we got here, no exception was thrown.
    }

    public function testAssertCanModifyByLockOwner(): void
    {
        $this->loginAs('user-1');
        $handler = $this->newHandler();
        $handler->lockFile(42);
        // Lock owner should be able to modify, even via a fresh handler instance.
        $this->newHandler()->assertCanModify(42);
        $this->assertTrue(true);
    }

    public function testAssertCanModifyByNonOwnerThrows(): void
    {
        $this->loginAs('user-1');
        $this->newHandler()->lockFile(42);

        $this->loginAs('user-2');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File is locked by user-1');
        $this->newHandler()->assertCanModify(42);
    }

    public function testGetLockInfoUnlocked(): void
    {
        $this->assertNull($this->newHandler()->getLockInfo(42));
    }

    public function testGetLockInfoLocked(): void
    {
        $this->loginAs('user-1');
        $handler = $this->newHandler();
        $handler->lockFile(42);

        $info = $handler->getLockInfo(42);
        $this->assertNotNull($info);
        $this->assertEquals('user-1', $info['lockedBy']);
        $this->assertInstanceOf(DateTime::class, $info['lockedAt']);
        $this->assertInstanceOf(DateTime::class, $info['expiresAt']);
    }

    /**
     * An expired lock must be auto-cleared (both reported as unlocked and
     * removed from storage) the next time it is looked at, per the "Lock
     * expires automatically" spec scenario.
     */
    public function testLockExpiryAutoClears(): void
    {
        $this->seedExpiredLock(42, 'user-1');

        $this->assertFalse($this->newHandler()->isLocked(42));
        $this->assertArrayNotHasKey(42, $this->lockStore);
    }
}
