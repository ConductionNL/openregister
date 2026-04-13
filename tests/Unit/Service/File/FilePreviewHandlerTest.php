<?php

declare(strict_types=1);

namespace Unit\Service\File;

use Exception;
use OCA\OpenRegister\Service\File\FilePreviewHandler;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IPreview;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FilePreviewHandlerTest extends TestCase
{
    private FilePreviewHandler $handler;
    private IPreview&MockObject $previewManager;
    private IRootFolder&MockObject $rootFolder;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previewManager = $this->createMock(IPreview::class);
        $this->rootFolder     = $this->createMock(IRootFolder::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        $this->handler = new FilePreviewHandler(
            $this->previewManager,
            $this->rootFolder,
            $this->logger
        );
    }

    /**
     * Test preview generation for supported file type.
     */
    public function testGetPreviewSuccess(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('photo.jpg');

        $previewFile = $this->createMock(ISimpleFile::class);

        $this->previewManager->method('isAvailable')->willReturn(true);
        $this->previewManager->method('getPreview')->willReturn($previewFile);

        $result = $this->handler->getPreview($file);

        $this->assertSame($previewFile, $result);
    }

    /**
     * Test preview for unsupported file type throws exception.
     */
    public function testGetPreviewUnsupportedType(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('data.csv');

        $this->previewManager->method('isAvailable')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Preview not available for this file type');

        $this->handler->getPreview($file);
    }

    /**
     * Test isPreviewAvailable returns true for supported types.
     */
    public function testIsPreviewAvailableTrue(): void
    {
        $file = $this->createMock(File::class);
        $this->previewManager->method('isAvailable')->willReturn(true);

        $this->assertTrue($this->handler->isPreviewAvailable($file));
    }

    /**
     * Test isPreviewAvailable returns false for unsupported types.
     */
    public function testIsPreviewAvailableFalse(): void
    {
        $file = $this->createMock(File::class);
        $this->previewManager->method('isAvailable')->willReturn(false);

        $this->assertFalse($this->handler->isPreviewAvailable($file));
    }
}
