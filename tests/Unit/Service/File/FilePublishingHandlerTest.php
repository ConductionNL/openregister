<?php

declare(strict_types=1);

/**
 * FilePublishingHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FilePublishingHandler
 *
 * Tests file publishing (creating public shares), unpublishing (removing shares),
 * and ZIP archive creation for object files.
 */
class FilePublishingHandlerTest extends TestCase
{
    /** @var FilePublishingHandler */
    private FilePublishingHandler $handler;

    /** @var MagicMapper&MockObject */
    private MagicMapper $objectEntityMapper;

    /** @var FileMapper&MockObject */
    private FileMapper $fileMapper;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var FileService&MockObject */
    private FileService $fileService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->fileMapper = $this->createMock(FileMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->fileService = $this->createMock(FileService::class);

        $this->handler = new FilePublishingHandler(
            $this->objectEntityMapper,
            $this->fileMapper,
            $this->logger
        );

        // Most tests need the file service
        $this->handler->setFileService($this->fileService);
    }

    /**
     * Helper to create an ObjectEntity with an ID.
     */
    private function createObjectEntity(int $id, string $uuid = 'test-uuid'): ObjectEntity
    {
        $object = new ObjectEntity();
        $reflection = new \ReflectionProperty($object, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($object, $id);
        $object->setUuid($uuid);
        return $object;
    }

    // =========================================================================
    // setFileService tests
    // =========================================================================

    #[Test]
    public function testSetFileServiceStoresReference(): void
    {
        $handler = new FilePublishingHandler(
            $this->objectEntityMapper,
            $this->fileMapper,
            $this->logger
        );

        // Should not throw
        $handler->setFileService($this->fileService);
        $this->assertTrue(true);
    }

    // =========================================================================
    // publishFile tests — file ID path
    // =========================================================================

    #[Test]
    public function testPublishFileByIdSuccess(): void
    {
        $object = $this->createObjectEntity(1);

        $mockFile = $this->createMock(File::class);
        $mockFile->method('getId')->willReturn(42);
        $mockFile->method('getName')->willReturn('document.pdf');
        $mockFile->method('getPath')->willReturn('/openregister/files/document.pdf');

        $this->fileService->method('getFile')
            ->with($object, 42)
            ->willReturn($mockFile);

        $this->fileService->method('checkOwnership');

        $mockUser = $this->createMock(IUser::class);
        $mockUser->method('getUID')->willReturn('openregister');
        $this->fileService->method('getUser')
            ->willReturn($mockUser);

        $this->fileMapper->expects($this->once())
            ->method('publishFile')
            ->with(42, 'openregister', 'openregister')
            ->willReturn([
                'id' => 1,
                'token' => 'abc123',
                'accessUrl' => 'https://example.com/s/abc123',
            ]);

        $result = $this->handler->publishFile($object, 42);

        $this->assertInstanceOf(File::class, $result);
        $this->assertEquals('document.pdf', $result->getName());
    }

    #[Test]
    public function testPublishFileByIdThrowsWhenFileNotFound(): void
    {
        $object = $this->createObjectEntity(1);

        $this->fileService->method('getFile')
            ->with($object, 99)
            ->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File with ID 99 does not exist');

        $this->handler->publishFile($object, 99);
    }

    // =========================================================================
    // publishFile tests — string path
    // =========================================================================

    #[Test]
    public function testPublishFileByPathSuccess(): void
    {
        $object = $this->createObjectEntity(1);

        $mockFile = $this->createMock(File::class);
        $mockFile->method('getId')->willReturn(50);
        $mockFile->method('getName')->willReturn('report.pdf');
        $mockFile->method('getPath')->willReturn('/openregister/files/report.pdf');

        $this->fileService->method('extractFileNameFromPath')
            ->with('report.pdf')
            ->willReturn(['cleanPath' => 'report.pdf', 'fileName' => 'report.pdf']);

        $mockFolder = $this->createMock(Folder::class);
        $mockFolder->method('getPath')->willReturn('/openregister/files');
        $mockFolder->method('getDirectoryListing')->willReturn([$mockFile]);
        $mockFolder->method('get')
            ->with('report.pdf')
            ->willReturn($mockFile);

        $this->fileService->method('getObjectFolder')
            ->with($object)
            ->willReturn($mockFolder);

        $this->fileService->method('checkOwnership');

        $mockUser = $this->createMock(IUser::class);
        $mockUser->method('getUID')->willReturn('openregister');
        $this->fileService->method('getUser')->willReturn($mockUser);

        $this->fileMapper->expects($this->once())
            ->method('publishFile')
            ->willReturn([
                'id' => 2,
                'token' => 'def456',
                'accessUrl' => 'https://example.com/s/def456',
            ]);

        $result = $this->handler->publishFile($object, 'report.pdf');

        $this->assertInstanceOf(File::class, $result);
    }

    #[Test]
    public function testPublishFileByPathThrowsWhenObjectFolderNull(): void
    {
        $object = $this->createObjectEntity(1);

        $this->fileService->method('extractFileNameFromPath')
            ->willReturn(['cleanPath' => 'file.pdf', 'fileName' => 'file.pdf']);

        $this->fileService->method('getObjectFolder')
            ->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Object folder not found');

        $this->handler->publishFile($object, 'file.pdf');
    }

    #[Test]
    public function testPublishFileByPathThrowsWhenFileNotInFolder(): void
    {
        $object = $this->createObjectEntity(1);

        $this->fileService->method('extractFileNameFromPath')
            ->willReturn(['cleanPath' => 'missing.pdf', 'fileName' => 'missing.pdf']);

        $mockFolder = $this->createMock(Folder::class);
        $mockFolder->method('getPath')->willReturn('/openregister/files');
        $mockFolder->method('getDirectoryListing')->willReturn([]);
        $mockFolder->method('get')
            ->willThrowException(new NotFoundException('Not found'));

        $this->fileService->method('getObjectFolder')
            ->willReturn($mockFolder);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->handler->publishFile($object, 'missing.pdf');
    }

    #[Test]
    public function testPublishFileThrowsWhenNodeIsNotFile(): void
    {
        $object = $this->createObjectEntity(1);

        // A folder node instead of file
        $folderNode = $this->createMock(Folder::class);
        $folderNode->method('getName')->willReturn('subfolder');

        $this->fileService->method('extractFileNameFromPath')
            ->willReturn(['cleanPath' => 'subfolder', 'fileName' => 'subfolder']);

        $mockFolder = $this->createMock(Folder::class);
        $mockFolder->method('getPath')->willReturn('/openregister/files');
        $mockFolder->method('getDirectoryListing')->willReturn([]);
        $mockFolder->method('get')
            ->with('subfolder')
            ->willReturn($folderNode);

        $this->fileService->method('getObjectFolder')
            ->willReturn($mockFolder);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->handler->publishFile($object, 'subfolder');
    }

    // =========================================================================
    // publishFile tests — string object ID resolution
    // =========================================================================

    #[Test]
    public function testPublishFileResolvesStringObjectId(): void
    {
        $object = $this->createObjectEntity(1);

        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with('object-uuid-123')
            ->willReturn($object);

        $mockFile = $this->createMock(File::class);
        $mockFile->method('getId')->willReturn(42);
        $mockFile->method('getName')->willReturn('doc.pdf');
        $mockFile->method('getPath')->willReturn('/files/doc.pdf');

        $this->fileService->method('getFile')
            ->willReturn($mockFile);
        $this->fileService->method('checkOwnership');

        $mockUser = $this->createMock(IUser::class);
        $mockUser->method('getUID')->willReturn('openregister');
        $this->fileService->method('getUser')->willReturn($mockUser);

        $this->fileMapper->method('publishFile')
            ->willReturn([
                'id' => 3,
                'token' => 'ghi789',
                'accessUrl' => 'https://example.com/s/ghi789',
            ]);

        $result = $this->handler->publishFile('object-uuid-123', 42);

        $this->assertInstanceOf(File::class, $result);
    }

    // =========================================================================
    // publishFile tests — share creation failure
    // =========================================================================

    #[Test]
    public function testPublishFileThrowsWhenShareCreationFails(): void
    {
        $object = $this->createObjectEntity(1);

        $mockFile = $this->createMock(File::class);
        $mockFile->method('getId')->willReturn(42);
        $mockFile->method('getName')->willReturn('doc.pdf');
        $mockFile->method('getPath')->willReturn('/files/doc.pdf');

        $this->fileService->method('getFile')
            ->willReturn($mockFile);
        $this->fileService->method('checkOwnership');

        $mockUser = $this->createMock(IUser::class);
        $mockUser->method('getUID')->willReturn('openregister');
        $this->fileService->method('getUser')->willReturn($mockUser);

        $this->fileMapper->method('publishFile')
            ->willThrowException(new Exception('Database error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to create share link');

        $this->handler->publishFile($object, 42);
    }

    // =========================================================================
    // unpublishFile tests
    // =========================================================================

    #[Test]
    public function testUnpublishFileByIdSuccess(): void
    {
        $object = $this->createObjectEntity(1);

        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with('some-uuid')
            ->willReturn($object);

        $mockFile = $this->createMock(File::class);
        $mockFile->method('getId')->willReturn(42);
        $mockFile->method('getName')->willReturn('doc.pdf');
        $mockFile->method('getPath')->willReturn('/files/doc.pdf');

        $this->fileService->method('getFile')
            ->willReturn($mockFile);
        $this->fileService->method('checkOwnership');

        $this->fileMapper->expects($this->once())
            ->method('depublishFile')
            ->with(42)
            ->willReturn(['deleted_shares' => 1, 'file_id' => 42]);

        $result = $this->handler->unpublishFile('some-uuid', 42);

        $this->assertInstanceOf(File::class, $result);
    }

    // =========================================================================
    // createObjectFilesZip tests
    // =========================================================================

    #[Test]
    public function testCreateObjectFilesZipResolvesStringObjectId(): void
    {
        $object = $this->createObjectEntity(1, 'zip-uuid');

        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with('zip-uuid')
            ->willReturn($object);

        // Mock getObjectFolder returning null (no folder)
        $this->fileService->method('getObjectFolder')
            ->willReturn(null);

        $this->expectException(Exception::class);

        $this->handler->createObjectFilesZip('zip-uuid');
    }
}
