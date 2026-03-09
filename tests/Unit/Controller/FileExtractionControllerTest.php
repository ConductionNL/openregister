<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FileExtractionController;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FileExtractionControllerTest extends TestCase
{
    private FileExtractionController $controller;
    private IRequest&MockObject $request;
    private TextExtractionService&MockObject $textExtractor;
    private VectorizationService&MockObject $vectorizationService;
    private ChunkMapper&MockObject $chunkMapper;
    private EntityRelationMapper&MockObject $entityRelationMapper;
    private RiskLevelService&MockObject $riskLevelService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->textExtractor = $this->createMock(TextExtractionService::class);
        $this->vectorizationService = $this->createMock(VectorizationService::class);
        $this->chunkMapper = $this->createMock(ChunkMapper::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->riskLevelService = $this->createMock(RiskLevelService::class);

        $this->controller = new FileExtractionController(
            'openregister',
            $this->request,
            $this->textExtractor,
            $this->vectorizationService,
            $this->chunkMapper,
            $this->entityRelationMapper,
            $this->riskLevelService
        );
    }

    public function testIndexSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn([]);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(0);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(0, $data['count']);
    }

    public function testIndexFilterNonCompleted(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, 'pending'],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(0, $data['count']);
    }

    public function testIndexException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);
        $this->chunkMapper->method('getFileSourceSummaries')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testShowNotFound(): void
    {
        $this->chunkMapper->method('findBySource')->willReturn([]);

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testExtractSuccess(): void
    {
        $result = $this->controller->extract(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testExtractFileNotFound(): void
    {
        $this->textExtractor->method('extractFile')
            ->willThrowException(new NotFoundException('Not found'));

        $result = $this->controller->extract(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testExtractException(): void
    {
        $this->textExtractor->method('extractFile')
            ->willThrowException(new \Exception('Extraction error'));

        $result = $this->controller->extract(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testDiscoverSuccess(): void
    {
        $this->textExtractor->method('discoverUntrackedFiles')
            ->willReturn(['discovered' => 5, 'failed' => 0, 'total' => 5]);

        $result = $this->controller->discover();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testDiscoverException(): void
    {
        $this->textExtractor->method('discoverUntrackedFiles')
            ->willThrowException(new \Exception('Discovery error'));

        $result = $this->controller->discover();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testExtractAllSuccess(): void
    {
        $this->textExtractor->method('extractPendingFiles')
            ->willReturn(['processed' => 10, 'failed' => 0, 'total' => 10]);

        $result = $this->controller->extractAll();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testRetryFailedSuccess(): void
    {
        $this->textExtractor->method('retryFailedExtractions')
            ->willReturn(['retried' => 3, 'failed' => 1, 'total' => 4]);

        $result = $this->controller->retryFailed();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testStatsSuccess(): void
    {
        $this->textExtractor->method('getStats')
            ->willReturn(['totalFiles' => 100]);

        $result = $this->controller->stats();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testStatsException(): void
    {
        $this->textExtractor->method('getStats')
            ->willThrowException(new \Exception('Stats error'));

        $result = $this->controller->stats();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testCleanup(): void
    {
        $result = $this->controller->cleanup();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testFileTypes(): void
    {
        $result = $this->controller->fileTypes();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testVectorizeBatchSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->vectorizationService->method('vectorizeBatch')
            ->willReturn(['processed' => 5]);

        $result = $this->controller->vectorizeBatch();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testVectorizeBatchException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->vectorizationService->method('vectorizeBatch')
            ->willThrowException(new \Exception('Vectorization error'));

        $result = $this->controller->vectorizeBatch();

        $this->assertEquals(500, $result->getStatus());
    }
}
