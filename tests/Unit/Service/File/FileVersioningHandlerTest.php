<?php

declare(strict_types=1);

namespace Unit\Service\File;

use OCA\OpenRegister\Service\File\FileVersioningHandler;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileVersioningHandlerTest extends TestCase
{
    private FileVersioningHandler $handler;
    private IRootFolder&MockObject $rootFolder;
    private IAppManager&MockObject $appManager;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootFolder  = $this->createMock(IRootFolder::class);
        $this->appManager  = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->handler = new FileVersioningHandler(
            $this->rootFolder,
            $this->appManager,
            $this->userSession,
            $this->logger
        );
    }

    /**
     * Test listing versions when files_versions is disabled.
     */
    public function testListVersionsDisabled(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $file = $this->createMock(File::class);
        $result = $this->handler->listVersions($file);

        $this->assertEmpty($result['versions']);
        $this->assertArrayHasKey('warning', $result);
        $this->assertStringContainsString('not enabled', $result['warning']);
    }

    /**
     * Test listing versions returns current version when enabled.
     */
    public function testListVersionsEnabled(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $file = $this->createMock(File::class);
        $file->method('getMTime')->willReturn(time());
        $file->method('getSize')->willReturn(1024);

        $result = $this->handler->listVersions($file);

        $this->assertNotEmpty($result['versions']);
        $this->assertTrue($result['versions'][0]['isCurrent']);
    }

    /**
     * Test restore version throws when versioning disabled.
     */
    public function testRestoreVersionDisabled(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $file = $this->createMock(File::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not enabled');

        $this->handler->restoreVersion($file, 'v-12345');
    }

    /**
     * Test isVersioningEnabled.
     */
    public function testIsVersioningEnabled(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->assertTrue($this->handler->isVersioningEnabled());
    }

    /**
     * Test isVersioningEnabled returns false.
     */
    public function testIsVersioningNotEnabled(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $this->assertFalse($this->handler->isVersioningEnabled());
    }

    /**
     * Test restoreVersion rejects malformed version IDs.
     *
     * Covers tasks.md Phase 4: "Write unit test for version restore" — at the
     * version-id-parse level. Real Files_Versions integration is exercised via
     * graceful-degradation paths (testRestoreVersionDisabled).
     */
    public function testRestoreVersionRejectsMalformedId(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $file = $this->createMock(File::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid version ID format');

        // Anything that doesn't yield a positive integer after stripping 'v-'.
        $this->handler->restoreVersion($file, 'not-a-version');
    }
}
