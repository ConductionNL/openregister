<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileTextControllerTest extends TestCase
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

    public function testGetFileTextReturnsDeprecated(): void
    {
        $result = $this->controller->getFileText(1);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('deprecated', $data['message']);
    }

    public function testExtractFileTextDisabled(): void
    {
        $this->config->method('hasKey')->willReturn(false);
        $this->config->method('getValueString')->willReturn('{}');

        $result = $this->controller->extractFileText(1);

        $this->assertEquals(Http::STATUS_NOT_IMPLEMENTED, $result->getStatus());
    }

    public function testExtractFileTextSuccess(): void
    {
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn(json_encode([
            'extractionScope' => 'all',
        ]));

        $result = $this->controller->extractFileText(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testExtractFileTextException(): void
    {
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn(json_encode([
            'extractionScope' => 'all',
        ]));
        $this->textExtractor->method('extractFile')
            ->willThrowException(new \Exception('Extract error'));

        $result = $this->controller->extractFileText(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testBulkExtractSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 100, 50],
            ]);
        $this->textExtractor->method('extractPendingFiles')
            ->willReturn(['processed' => 10, 'failed' => 0, 'total' => 10]);

        $result = $this->controller->bulkExtract();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testBulkExtractException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 100, 50],
            ]);
        $this->textExtractor->method('extractPendingFiles')
            ->willThrowException(new \Exception('Bulk error'));

        $result = $this->controller->bulkExtract();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testGetStatsSuccess(): void
    {
        $this->textExtractor->method('getStats')->willReturn(['totalFiles' => 100]);

        $result = $this->controller->getStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testGetStatsException(): void
    {
        $this->textExtractor->method('getStats')
            ->willThrowException(new \Exception('Stats error'));

        $result = $this->controller->getStats();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testDeleteFileTextNotImplemented(): void
    {
        $result = $this->controller->deleteFileText(1);

        $this->assertEquals(501, $result->getStatus());
    }

    public function testProcessAndIndexExtractedSuccess(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willReturn(['processed' => 5]);

        $result = $this->controller->processAndIndexExtracted();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testProcessAndIndexExtractedException(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willThrowException(new \Exception('Index error'));

        $result = $this->controller->processAndIndexExtracted();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testProcessAndIndexFileSuccess(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willReturn(['processed' => 1]);

        $result = $this->controller->processAndIndexFile(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetChunkingStatsSuccess(): void
    {
        $this->indexService->method('getChunkingStats')
            ->willReturn(['total' => 100]);

        $result = $this->controller->getChunkingStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testGetChunkingStatsException(): void
    {
        $this->indexService->method('getChunkingStats')
            ->willThrowException(new \Exception('Stats error'));

        $result = $this->controller->getChunkingStats();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testAnonymizeFileNotFound(): void
    {
        $this->fileService->method('getFileById')->willReturn(null);

        $result = $this->controller->anonymizeFile(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testAnonymizeFileAlreadyAnonymized(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('test_anonymized.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);

        $result = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testAnonymizeFileNoEntities(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('test.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([]);

        $result = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testAnonymizeFileException(): void
    {
        $this->fileService->method('getFileById')
            ->willThrowException(new \Exception('File error'));

        $result = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }
}
