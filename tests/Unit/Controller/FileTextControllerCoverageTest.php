<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage tests for FileTextController — targets remaining uncovered branches.
 */
class FileTextControllerCoverageTest extends TestCase
{
    private FileTextController $controller;
    private IRequest&MockObject $request;
    private TextExtractionService&MockObject $textExtractor;
    private IndexService&MockObject $indexService;
    private FileService&MockObject $fileService;
    private EntityRelationMapper&MockObject $entityRelationMapper;
    private LoggerInterface&MockObject $logger;
    private IAppConfig&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->textExtractor = $this->createMock(TextExtractionService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(IAppConfig::class);

        $this->controller = new FileTextController(
            'openregister',
            $this->request,
            $this->textExtractor,
            $this->indexService,
            $this->fileService,
            $this->entityRelationMapper,
            $this->logger,
            $this->config
        );
    }

    // =========================================================================
    // extractFileText — enabled with valid scope
    // =========================================================================

    public function testExtractFileTextEnabledWithValidScope(): void
    {
        $this->config->method('hasKey')
            ->with('openregister', 'fileManagement')
            ->willReturn(true);
        $this->config->method('getValueString')
            ->with('openregister', 'fileManagement')
            ->willReturn(json_encode(['extractionScope' => 'all']));

        $this->textExtractor->expects($this->once())
            ->method('extractFile')
            ->with(123, true);

        $result = $this->controller->extractFileText(123);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Text extracted successfully', $data['message']);
    }

    public function testExtractFileTextEnabledWithNullScope(): void
    {
        $this->config->method('hasKey')
            ->with('openregister', 'fileManagement')
            ->willReturn(true);
        $this->config->method('getValueString')
            ->with('openregister', 'fileManagement')
            ->willReturn(json_encode([])); // no extractionScope key

        $this->textExtractor->expects($this->once())
            ->method('extractFile')
            ->with(42, true);

        $result = $this->controller->extractFileText(42);

        $this->assertEquals(200, $result->getStatus());
    }

    // =========================================================================
    // processAndIndexExtracted — options passed through
    // =========================================================================

    public function testProcessAndIndexExtractedWithBothOptions(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willReturn(['processed' => 10, 'failed' => 0]);

        $result = $this->controller->processAndIndexExtracted(50, 1000, 100);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testProcessAndIndexExtractedWithNullLimit(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willReturn(['processed' => 5, 'failed' => 1]);

        $result = $this->controller->processAndIndexExtracted(null, null, null);

        $this->assertEquals(200, $result->getStatus());
    }

    // =========================================================================
    // processAndIndexFile — with chunk size
    // =========================================================================

    public function testProcessAndIndexFileWithOptions(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willReturn(['success' => true]);

        $result = $this->controller->processAndIndexFile(42, 500, 50);

        $this->assertEquals(200, $result->getStatus());
    }

    // =========================================================================
    // bulkExtract — with limit at boundary
    // =========================================================================

    public function testBulkExtractWithExactMaxLimit(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 100, 500],
            ]);
        $this->textExtractor->expects($this->once())
            ->method('extractPendingFiles')
            ->with(500)
            ->willReturn(['processed' => 500, 'failed' => 0, 'total' => 500]);

        $result = $this->controller->bulkExtract();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(500, $data['processed']);
    }

    public function testBulkExtractWithOverMaxLimit(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 100, 1000],
            ]);
        // Should be capped to 500
        $this->textExtractor->expects($this->once())
            ->method('extractPendingFiles')
            ->with(500)
            ->willReturn(['processed' => 200, 'failed' => 0, 'total' => 200]);

        $result = $this->controller->bulkExtract();

        $this->assertEquals(200, $result->getStatus());
    }

    // =========================================================================
    // anonymizeFile — already anonymized in middle of name
    // =========================================================================

    public function testAnonymizeFileNotFoundReturns404(): void
    {
        $this->fileService->method('getFileById')
            ->with(999)
            ->willReturn(null);

        $result = $this->controller->anonymizeFile(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('File not found', $data['message']);
    }
}
