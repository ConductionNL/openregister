<?php

declare(strict_types=1);

namespace Unit\Service\File;

use Exception;
use OCA\OpenRegister\Service\File\FilePreviewHandler;
use OCA\OpenRegister\Files\Service\File\PreviewSize;
use OCP\Files\File;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IPreview;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FilePreviewHandlerTest extends TestCase {
	private FilePreviewHandler $handler;
	private IPreview&MockObject $previewManager;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->previewManager = $this->createMock(IPreview::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new FilePreviewHandler(
			$this->previewManager,
			$this->logger
		);
	}

	/**
	 * Test preview generation for supported file type.
	 */
	public function testGetPreviewSuccess(): void {
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
	public function testGetPreviewUnsupportedType(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('data.csv');

		$this->previewManager->method('isAvailable')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Preview not available for this file type');

		$this->handler->getPreview($file);
	}
	/**
	 * Calls the handler with an optional width and height.
	 */
	private function preview(File $file, ?int $width = null, ?int $height = null): ISimpleFile {
		if ($width === null) {
			return $this->handler->getPreview($file);
		}

		return $this->handler->getPreview($file, new PreviewSize($width, (int) $height));
	}

	public function testGetPreviewUsesTheDefaultSizeAndLogsIt(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('photo.jpg');
		$previewFile = $this->createMock(ISimpleFile::class);

		$this->previewManager->method('isAvailable')->willReturn(true);
		$this->previewManager->expects($this->once())->method('getPreview')
			->with($file, 256, 256)->willReturn($previewFile);
		$this->logger->expects($this->once())->method('debug')
			->with('[FilePreviewHandler] Generated preview for file photo.jpg (256x256)', $this->anything());
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame($previewFile, $this->preview($file));
	}

	public function testGetPreviewPassesTheRequestedSize(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('photo.jpg');
		$previewFile = $this->createMock(ISimpleFile::class);

		$this->previewManager->method('isAvailable')->willReturn(true);
		$this->previewManager->expects($this->once())->method('getPreview')
			->with($file, 100, 50)->willReturn($previewFile);
		$this->logger->expects($this->once())->method('debug')
			->with('[FilePreviewHandler] Generated preview for file photo.jpg (100x50)', $this->anything());

		$this->assertSame($previewFile, $this->preview($file, 100, 50));
	}

	public function testGetPreviewReportsAFailedGeneration(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('photo.jpg');

		$this->previewManager->method('isAvailable')->willReturn(true);
		$this->previewManager->method('getPreview')->willThrowException(new Exception('boom'));
		$this->logger->expects($this->once())->method('warning')
			->with('[FilePreviewHandler] Failed to generate preview: boom', $this->anything());

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Preview not available for this file type');

		$this->preview($file);
	}

	public function testGetPreviewDoesNotGenerateForAnUnsupportedType(): void {
		$file = $this->createMock(File::class);

		$this->previewManager->method('isAvailable')->willReturn(false);
		$this->previewManager->expects($this->never())->method('getPreview');
		$this->logger->expects($this->never())->method('warning');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Preview not available for this file type');

		$this->preview($file);
	}
}
