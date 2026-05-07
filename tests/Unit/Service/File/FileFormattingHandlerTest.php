<?php

declare(strict_types=1);

namespace Unit\Service\File;

use DateTime;
use OCA\OpenRegister\Service\File\FileFormattingHandler;
use OCA\OpenRegister\Service\File\FileSharingHandler;
use OCA\OpenRegister\Service\File\TaggingHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FileFormattingHandler covering:
 * - `formatFile()` lock metadata gating (authenticated vs anonymous)
 * - `formatLock()` envelope shape and Nextcloud constant mapping
 * - `formatFiles()` per-file `LockedException` resilience
 * - `_limit` pagination handling (no upper ceiling)
 *
 * Corresponds to tasks 4.2-4.6 of change
 * `fix-object-files-listing-lock-and-limit`.
 */
class FileFormattingHandlerTest extends TestCase
{

    private FileFormattingHandler $handler;

    private TaggingHandler&MockObject $taggingHandler;

    private FileSharingHandler&MockObject $fileSharingHandler;

    private IURLGenerator&MockObject $urlGenerator;

    private ILockManager&MockObject $lockManager;

    private IUserSession&MockObject $userSession;

    private LoggerInterface&MockObject $logger;

    private FileService&MockObject $fileService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->taggingHandler     = $this->createMock(TaggingHandler::class);
        $this->fileSharingHandler = $this->createMock(FileSharingHandler::class);
        $this->urlGenerator       = $this->createMock(IURLGenerator::class);
        $this->lockManager        = $this->createMock(ILockManager::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->fileService = $this->createMock(FileService::class);

        $this->handler = new FileFormattingHandler(
            $this->taggingHandler,
            $this->fileSharingHandler,
            $this->urlGenerator,
            $this->lockManager,
            $this->userSession,
            $this->logger
        );
        $this->handler->setFileService($this->fileService);

        // Common baseline: no shares, no tags.
        $this->fileService->method('findShares')->willReturn([]);
        $this->fileService->method('getFileTags')->willReturn([]);
    }//end setUp()

    private function mockAuthenticatedUser(string $userId='admin'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);
        $this->userSession->method('getUser')->willReturn($user);
    }//end mockAuthenticatedUser()

    private function mockAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
    }//end mockAnonymous()

    private function makeFileNode(int $id=42, string $name='file.pdf'): Node&MockObject
    {
        $file = $this->createMock(Node::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/user/files/'.$name);
        $file->method('getMimetype')->willReturn('application/pdf');
        $file->method('getExtension')->willReturn('pdf');
        $file->method('getSize')->willReturn(1024);
        $file->method('getEtag')->willReturn('etag-'.$id);
        $file->method('getCreationTime')->willReturn(1700000000);
        $file->method('getUploadTime')->willReturn(1700000100);
        return $file;
    }//end makeFileNode()

    // =========================================================================
    // Task 4.2 - formatFile() authenticated, lock provider available
    // =========================================================================
    public function testFormatFileAuthenticatedNoLocksReturnsLockedFalse(): void
    {
        $this->mockAuthenticatedUser();
        $this->lockManager->method('isLockProviderAvailable')->willReturn(true);
        $this->lockManager->method('getLocks')->with(42)->willReturn([]);

        $file = $this->makeFileNode();

        $result = $this->handler->formatFile(file: $file);

        $this->assertArrayHasKey('locked', $result);
        $this->assertFalse($result['locked']);
        $this->assertArrayNotHasKey('lock', $result);
    }//end testFormatFileAuthenticatedNoLocksReturnsLockedFalse()

    public function testFormatFileAuthenticatedWithUserLockReturnsLockEnvelope(): void
    {
        $this->mockAuthenticatedUser();
        $this->lockManager->method('isLockProviderAvailable')->willReturn(true);

        $lock = $this->createMock(ILock::class);
        $lock->method('getType')->willReturn(ILock::TYPE_USER);
        $lock->method('getScope')->willReturn(ILock::LOCK_EXCLUSIVE);
        $lock->method('getOwner')->willReturn('alice');
        $lock->method('getCreatedAt')->willReturn(1700000000);
        $lock->method('getTimeout')->willReturn(0);

        $this->lockManager->method('getLocks')->with(42)->willReturn([$lock]);

        $file = $this->makeFileNode();

        $result = $this->handler->formatFile(file: $file);

        $this->assertTrue($result['locked']);
        $this->assertSame('user', $result['lock']['type']);
        $this->assertSame('exclusive', $result['lock']['scope']);
        $this->assertSame('alice', $result['lock']['owner']);
        $this->assertNull($result['lock']['expiresAt']);
    }//end testFormatFileAuthenticatedWithUserLockReturnsLockEnvelope()

    public function testFormatFileAuthenticatedAppLockWithTimeoutComputesExpiresAt(): void
    {
        $this->mockAuthenticatedUser();
        $this->lockManager->method('isLockProviderAvailable')->willReturn(true);

        $createdAt = 1700000000;
        $timeout   = 1800;

        $lock = $this->createMock(ILock::class);
        $lock->method('getType')->willReturn(ILock::TYPE_APP);
        $lock->method('getScope')->willReturn(ILock::LOCK_SHARED);
        $lock->method('getOwner')->willReturn('text');
        $lock->method('getCreatedAt')->willReturn($createdAt);
        $lock->method('getTimeout')->willReturn($timeout);

        $this->lockManager->method('getLocks')->with(42)->willReturn([$lock]);

        $file = $this->makeFileNode();

        $result = $this->handler->formatFile(file: $file);

        $expectedExpiresAt = (new DateTime())->setTimestamp($createdAt + $timeout)->format('c');
        $this->assertSame('app', $result['lock']['type']);
        $this->assertSame('shared', $result['lock']['scope']);
        $this->assertSame($expectedExpiresAt, $result['lock']['expiresAt']);
    }//end testFormatFileAuthenticatedAppLockWithTimeoutComputesExpiresAt()

    // =========================================================================
    // Task 4.3 - formatFile() authenticated, lock provider unavailable
    // =========================================================================
    public function testFormatFileAuthenticatedLockProviderUnavailableReturnsLockedFalse(): void
    {
        $this->mockAuthenticatedUser();
        $this->lockManager->method('isLockProviderAvailable')->willReturn(false);

        // Must NOT call getLocks when provider unavailable.
        $this->lockManager->expects($this->never())->method('getLocks');

        $file = $this->makeFileNode();

        $result = $this->handler->formatFile(file: $file);

        $this->assertFalse($result['locked']);
        $this->assertArrayNotHasKey('lock', $result);
    }//end testFormatFileAuthenticatedLockProviderUnavailableReturnsLockedFalse()

    // =========================================================================
    // Task 4.4 - formatFile() anonymous caller omits locked/lock entirely
    // =========================================================================
    public function testFormatFileAnonymousOmitsLockedAndLock(): void
    {
        $this->mockAnonymous();

        // Anonymous callers MUST never touch the lock manager.
        $this->lockManager->expects($this->never())->method('isLockProviderAvailable');
        $this->lockManager->expects($this->never())->method('getLocks');

        $file = $this->makeFileNode();

        $result = $this->handler->formatFile(file: $file);

        $this->assertArrayNotHasKey('locked', $result);
        $this->assertArrayNotHasKey('lock', $result);
    }//end testFormatFileAnonymousOmitsLockedAndLock()

    // =========================================================================
    // Task 4.5 - formatFiles() resilience on LockedException
    // =========================================================================
    public function testFormatFilesResilientToLockedExceptionAuthenticated(): void
    {
        $this->mockAuthenticatedUser();
        $this->lockManager->method('isLockProviderAvailable')->willReturn(true);
        $this->lockManager->method('getLocks')->willReturn([]);

        $goodFile   = $this->makeFileNode(id: 1, name: 'good.pdf');
        $lockedFile = $this->makeFileNode(id: 2, name: 'locked.pdf');

        // Make the locked file trip LockedException through findShares() (first
        // call inside formatFile that could raise it on the hot path).
        $this->fileService->method('findShares')->willReturnCallback(
            function (Node $node) use ($goodFile, $lockedFile): array {
                if ($node->getId() === 2) {
                    throw new LockedException('locked.pdf');
                }

                return [];
            }
        );

        $this->logger->expects($this->atLeastOnce())->method('info');

        $result = $this->handler->formatFiles(files: [$goodFile, $lockedFile], requestParams: []);

        $this->assertCount(2, $result['results']);
        $this->assertSame(1, $result['results'][0]['id']);
        $this->assertSame(2, $result['results'][1]['id']);
        $this->assertSame('locked', $result['results'][1]['error']);
        $this->assertTrue($result['results'][1]['locked']);
    }//end testFormatFilesResilientToLockedExceptionAuthenticated()

    public function testFormatFilesResilientToLockedExceptionAnonymousOmitsLockFields(): void
    {
        $this->mockAnonymous();

        $goodFile   = $this->makeFileNode(id: 1, name: 'good.pdf');
        $lockedFile = $this->makeFileNode(id: 2, name: 'locked.pdf');

        $this->fileService->method('findShares')->willReturnCallback(
            function (Node $node): array {
                if ($node->getId() === 2) {
                    throw new LockedException('locked.pdf');
                }

                return [];
            }
        );

        // Anonymous must not see any lock metadata, even on the error path.
        $this->lockManager->expects($this->never())->method('getLocks');
        $this->logger->expects($this->atLeastOnce())->method('info');

        $result = $this->handler->formatFiles(files: [$goodFile, $lockedFile], requestParams: []);

        $this->assertCount(2, $result['results']);
        $this->assertSame('locked', $result['results'][1]['error']);
        $this->assertArrayNotHasKey('locked', $result['results'][1]);
        $this->assertArrayNotHasKey('lock', $result['results'][1]);
    }//end testFormatFilesResilientToLockedExceptionAnonymousOmitsLockFields()

    // =========================================================================
    // Task 4.6 - _limit handling (no upper ceiling, floor 1, default 30)
    // =========================================================================
    public function testLimitHonoursLargeValueWithoutCeiling(): void
    {
        $this->mockAnonymous();

        $result = $this->handler->formatFiles(files: [], requestParams: ['_limit' => 500]);
        $this->assertSame(500, $result['limit']);

        $result = $this->handler->formatFiles(files: [], requestParams: ['_limit' => 5000]);
        $this->assertSame(5000, $result['limit']);
    }//end testLimitHonoursLargeValueWithoutCeiling()

    public function testLimitZeroAndNegativeValuesClampToFloorOfOne(): void
    {
        $this->mockAnonymous();

        $result = $this->handler->formatFiles(files: [], requestParams: ['_limit' => 0]);
        $this->assertSame(1, $result['limit']);

        $result = $this->handler->formatFiles(files: [], requestParams: ['_limit' => -1]);
        $this->assertSame(1, $result['limit']);
    }//end testLimitZeroAndNegativeValuesClampToFloorOfOne()

    public function testLimitDefaultsToThirtyWhenMissing(): void
    {
        $this->mockAnonymous();

        $result = $this->handler->formatFiles(files: [], requestParams: []);
        $this->assertSame(30, $result['limit']);
    }//end testLimitDefaultsToThirtyWhenMissing()
}//end class
