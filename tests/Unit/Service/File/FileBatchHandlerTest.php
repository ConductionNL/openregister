<?php

declare(strict_types=1);

namespace Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\DeleteFileHandler;
use OCA\OpenRegister\Service\File\FileBatchHandler;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\File\TaggingHandler;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileBatchHandlerTest extends TestCase
{
    private FileBatchHandler $handler;
    private FilePublishingHandler&MockObject $publishingHandler;
    private DeleteFileHandler&MockObject $deleteHandler;
    private TaggingHandler&MockObject $taggingHandler;
    private LoggerInterface&MockObject $logger;
    private FileService&MockObject $fileService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishingHandler = $this->createMock(FilePublishingHandler::class);
        $this->deleteHandler     = $this->createMock(DeleteFileHandler::class);
        $this->taggingHandler    = $this->createMock(TaggingHandler::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->fileService       = $this->createMock(FileService::class);

        $this->handler = new FileBatchHandler(
            $this->publishingHandler,
            $this->deleteHandler,
            $this->taggingHandler,
            $this->logger
        );

        $this->handler->setFileService($this->fileService);
    }

    private function createObjectEntity(): ObjectEntity
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('getUuid')->willReturn('abc-123');
        return $object;
    }

    /**
     * Test batch publish succeeds for all files.
     */
    public function testBatchPublishSuccess(): void
    {
        $object = $this->createObjectEntity();

        $this->fileService
            ->expects($this->exactly(3))
            ->method('publishFile');

        $result = $this->handler->executeBatch($object, 'publish', [42, 43, 44]);

        $this->assertEquals(3, $result['summary']['total']);
        $this->assertEquals(3, $result['summary']['succeeded']);
        $this->assertEquals(0, $result['summary']['failed']);
    }

    /**
     * Test batch with partial failure returns mixed results.
     */
    public function testBatchPartialFailure(): void
    {
        $object = $this->createObjectEntity();

        $this->fileService
            ->method('deleteFile')
            ->willReturnCallback(function ($file, $obj) {
                if ($file === 43) {
                    throw new Exception('File is locked');
                }
                return true;
            });

        $result = $this->handler->executeBatch($object, 'delete', [42, 43, 44]);

        $this->assertEquals(3, $result['summary']['total']);
        $this->assertEquals(2, $result['summary']['succeeded']);
        $this->assertEquals(1, $result['summary']['failed']);
        $this->assertFalse($result['results'][1]['success']);
    }

    /**
     * Test batch size limit throws exception.
     */
    public function testBatchSizeLimit(): void
    {
        $object = $this->createObjectEntity();
        $fileIds = range(1, 101);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Batch operations are limited to 100 files per request');

        $this->handler->executeBatch($object, 'publish', $fileIds);
    }

    /**
     * Test invalid batch action throws exception.
     */
    public function testBatchInvalidAction(): void
    {
        $object = $this->createObjectEntity();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid batch action');

        $this->handler->executeBatch($object, 'archive', [42]);
    }

    /**
     * Test empty file IDs throws exception.
     */
    public function testBatchEmptyFileIds(): void
    {
        $object = $this->createObjectEntity();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No file IDs provided');

        $this->handler->executeBatch($object, 'publish', []);
    }
}
