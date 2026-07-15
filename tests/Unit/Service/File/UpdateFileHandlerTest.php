<?php

declare(strict_types=1);

/*
 * UpdateFileHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\ReadFileHandler;
use OCA\OpenRegister\Service\File\UpdateFileHandler;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for UpdateFileHandler.
 *
 * Covers the per-action write-permission gate: a readable file the session
 * may not write MUST be refused before putContent(), and a writable file
 * MUST be written.
 */
class UpdateFileHandlerTest extends TestCase
{

    /**
     * @var UpdateFileHandler
     */
    private UpdateFileHandler $handler;

    /**
     * @var IRootFolder&MockObject
     */
    private $rootFolder;

    /**
     * @var FolderManagementHandler&MockObject
     */
    private $folderMgmtHandler;

    /**
     * @var FileValidationHandler&MockObject
     */
    private $fileValidHandler;

    /**
     * @var FileOwnershipHandler&MockObject
     */
    private $fileOwnershipHandler;

    /**
     * @var ReadFileHandler&MockObject
     */
    private $readFileHandler;

    /**
     * @var ISystemTagManager&MockObject
     */
    private $systemTagManager;

    /**
     * @var ISystemTagObjectMapper&MockObject
     */
    private $systemTagMapper;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // IRootFolder extends OC\Hooks\Emitter, which is only present when the
        // Nextcloud server source tree is on the include path (Docker / CI).
        // Skip cleanly in a bare composer-autoload context (local worktree).
        if (interface_exists('OC\\Hooks\\Emitter') === false) {
            $this->markTestSkipped('Nextcloud server classes unavailable; run in the Docker test environment.');
        }

        $this->rootFolder           = $this->createMock(IRootFolder::class);
        $this->folderMgmtHandler    = $this->createMock(FolderManagementHandler::class);
        $this->fileValidHandler     = $this->createMock(FileValidationHandler::class);
        $this->fileOwnershipHandler = $this->createMock(FileOwnershipHandler::class);
        $this->readFileHandler      = $this->createMock(ReadFileHandler::class);
        $this->systemTagManager     = $this->createMock(ISystemTagManager::class);
        $this->systemTagMapper      = $this->createMock(ISystemTagObjectMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new UpdateFileHandler(
            $this->rootFolder,
            $this->folderMgmtHandler,
            $this->fileValidHandler,
            $this->fileOwnershipHandler,
            $this->readFileHandler,
            $this->systemTagManager,
            $this->systemTagMapper,
            $this->logger
        );
    }//end setUp()

    /**
     * Build a readable file mock resolved by id within an object folder.
     *
     * @param bool $updateable Whether the session may write the node.
     *
     * @return File&MockObject
     */
    private function fileResolvedById(bool $updateable)
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(42);
        $file->method('getName')->willReturn('test.pdf');
        // Non-md5 hash so the content-changed guard always enters the write branch.
        $file->method('hash')->willReturn('not-a-real-md5');
        $file->method('isUpdateable')->willReturn($updateable);

        $this->readFileHandler->method('getFile')->willReturn($file);

        return $file;
    }//end fileResolvedById()

    // =========================================================================
    // updateFile - write-permission gate
    // =========================================================================
    public function testUpdateRefusedWithoutWritePermission(): void
    {
        // checkOwnership() (readability) passes; isUpdateable() fails, so the
        // write is refused before putContent(). updateFile() wraps the
        // NotPermittedException into a generic Exception ("Can't write content").
        $file = $this->fileResolvedById(updateable: false);
        $file->expects($this->never())->method('putContent');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('is not writable');

        // Valid base64 ("testcontent") so the handler's base64 round-trip check
        // does not trip on invalid input before reaching the write-permission gate.
        $this->handler->updateFile(
            filePath: 42,
            content: 'dGVzdGNvbnRlbnQ=',
            tags: [],
            object: $this->createMock(ObjectEntity::class)
        );
    }//end testUpdateRefusedWithoutWritePermission()

    public function testUpdateWritesWhenUpdateable(): void
    {
        // A writable file must have its content written. Input is valid base64
        // ("testcontent"); the handler decodes it before writing.
        $file = $this->fileResolvedById(updateable: true);
        $file->expects($this->once())->method('putContent')->with(data: 'testcontent');

        $result = $this->handler->updateFile(
            filePath: 42,
            content: 'dGVzdGNvbnRlbnQ=',
            tags: [],
            object: $this->createMock(ObjectEntity::class)
        );

        $this->assertSame($file, $result);
    }//end testUpdateWritesWhenUpdateable()

    // =========================================================================
    // updateFile - streamed (resource) content path (stream-file-content #110)
    // =========================================================================
    public function testUpdateStreamsResourceWhenUpdateable(): void
    {
        // A stream resource whose md5 differs from the stored file MUST be written
        // straight through to putContent() as a resource, never buffered to a string.
        $file = $this->fileResolvedById(updateable: true);
        $file->expects($this->once())
            ->method('putContent')
            ->with(data: $this->callback(static fn ($arg): bool => is_resource($arg) === true));

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'streamed-bytes');
        rewind($stream);

        $result = $this->handler->updateFile(
            filePath: 42,
            content: $stream,
            tags: [],
            object: $this->createMock(ObjectEntity::class)
        );

        $this->assertSame($file, $result);
        fclose($stream);
    }//end testUpdateStreamsResourceWhenUpdateable()

    public function testUpdateSkipsWriteWhenResourceContentUnchanged(): void
    {
        // A re-synced, byte-identical file (incoming stream md5 == stored md5) MUST NOT
        // be rewritten — the version-bump-avoiding skip works on the streamed path too.
        $unchanged = 'unchanged-bytes';

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(42);
        $file->method('getName')->willReturn('test.pdf');
        $file->method('isUpdateable')->willReturn(true);
        $file->method('hash')->willReturn(md5($unchanged));
        $file->expects($this->never())->method('putContent');
        $this->readFileHandler->method('getFile')->willReturn($file);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $unchanged);
        rewind($stream);

        $result = $this->handler->updateFile(
            filePath: 42,
            content: $stream,
            tags: [],
            object: $this->createMock(ObjectEntity::class)
        );

        $this->assertSame($file, $result);
        fclose($stream);
    }//end testUpdateSkipsWriteWhenResourceContentUnchanged()

    public function testUpdateBlocksExecutableResourceByMagicBytes(): void
    {
        // On the streamed path the executable guard reads a bounded prefix and runs the
        // same check; a blocked signature MUST abort before putContent() is reached.
        $file = $this->fileResolvedById(updateable: true);
        $file->expects($this->never())->method('putContent');

        $this->fileValidHandler->method('blockExecutableFile')
            ->willThrowException(new Exception('is an executable file'));

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "MZ\x90\x00executable-header");
        rewind($stream);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('executable');

        try {
            $this->handler->updateFile(
                filePath: 42,
                content: $stream,
                tags: [],
                object: $this->createMock(ObjectEntity::class)
            );
        } finally {
            fclose($stream);
        }
    }//end testUpdateBlocksExecutableResourceByMagicBytes()
}//end class
