<?php

declare(strict_types=1);

/*
 * CreateFileHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\CreateFileHandler;
use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CreateFileHandler.
 *
 * Covers the streamed (resource) content path added by stream-file-content
 * (#110): a stream resource is passed straight through to putContent() without
 * string buffering or base64 auto-decoding, while the executable-file guard is
 * preserved via a bounded magic-byte prefix read.
 */
class CreateFileHandlerTest extends TestCase
{

    /**
     * @var CreateFileHandler
     */
    private CreateFileHandler $handler;

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
     * @var MagicMapper&MockObject
     */
    private $objectEntityMapper;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * @var FileService&MockObject
     */
    private $fileService;

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
        $this->objectEntityMapper   = $this->createMock(MagicMapper::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->fileService = $this->createMock(FileService::class);

        $this->handler = new CreateFileHandler(
            $this->rootFolder,
            $this->folderMgmtHandler,
            $this->fileValidHandler,
            $this->fileOwnershipHandler,
            $this->objectEntityMapper,
            $this->logger
        );
        $this->handler->setFileService($this->fileService);
    }//end setUp()

    /**
     * Wire the folder → new file chain and the automatic-tag calls so addFile()
     * reaches putContent() and returns the created file.
     *
     * @return File&MockObject The file node newFile() will return.
     */
    private function wireNewFile()
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);
        $file->method('getName')->willReturn('doc.pdf');
        $file->method('getPath')->willReturn('/openregister/doc.pdf');

        $folder = $this->createMock(Folder::class);
        $folder->method('newFile')->willReturn($file);

        $this->folderMgmtHandler->method('getObjectFolder')->willReturn($folder);
        $this->fileService->method('generateObjectTag')->willReturn('object:uuid');

        return $file;
    }//end wireNewFile()

    // =========================================================================
    // addFile - streamed (resource) content path (stream-file-content #110)
    // =========================================================================
    public function testAddFileStreamsResourceStraightToPutContent(): void
    {
        // A stream resource MUST be passed to putContent() as a resource — never
        // buffered into a string and never base64 auto-decoded.
        $file = $this->wireNewFile();
        $file->expects($this->once())
            ->method('putContent')
            ->with($this->callback(static fn ($arg): bool => is_resource($arg) === true));

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'binary-streamed-bytes');
        rewind($stream);

        $result = $this->handler->addFile(
            objectEntity: $this->createMock(ObjectEntity::class),
            fileName: 'doc.pdf',
            content: $stream
        );

        $this->assertSame($file, $result);
        fclose($stream);
    }//end testAddFileStreamsResourceStraightToPutContent()

    public function testAddFileBlocksExecutableResourceByMagicBytes(): void
    {
        // The executable guard reads a bounded prefix from the stream and runs the
        // same check as the string path; a blocked signature MUST abort before the
        // file is created / written.
        $file = $this->wireNewFile();
        $file->expects($this->never())->method('putContent');

        $this->fileValidHandler->method('blockExecutableFile')
            ->willThrowException(new Exception('is an executable file'));

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "MZ\x90\x00windows-executable-header");
        rewind($stream);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('executable');

        try {
            $this->handler->addFile(
                objectEntity: $this->createMock(ObjectEntity::class),
                fileName: 'evil.pdf',
                content: $stream
            );
        } finally {
            fclose($stream);
        }
    }//end testAddFileBlocksExecutableResourceByMagicBytes()

    public function testAddFileStillAcceptsStringContent(): void
    {
        // Backward-compatibility: a plain string caller behaves exactly as before —
        // the content is written via putContent() as a decoded string.
        $file = $this->wireNewFile();
        $file->expects($this->once())
            ->method('putContent')
            ->with($this->callback(static fn ($arg): bool => is_string($arg) === true));

        $result = $this->handler->addFile(
            objectEntity: $this->createMock(ObjectEntity::class),
            fileName: 'doc.pdf',
            content: 'plain-string-content'
        );

        $this->assertSame($file, $result);
    }//end testAddFileStillAcceptsStringContent()
}//end class
