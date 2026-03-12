<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FileExtractionController;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FileExtractionControllerDeepTest extends TestCase
{
    private FileExtractionController $controller;
    private IRequest|MockObject $request;
    private TextExtractionService|MockObject $textExtractor;
    private VectorizationService|MockObject $vectorizationService;
    private ChunkMapper|MockObject $chunkMapper;
    private EntityRelationMapper|MockObject $entityRelationMapper;
    private RiskLevelService|MockObject $riskLevelService;

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

    public function testExtractFileNotFound(): void
    {
        $this->textExtractor->method('extractFile')
            ->willThrowException(new NotFoundException('not found'));

        $response = $this->controller->extract(999);

        $this->assertEquals(404, $response->getStatus());
    }

    public function testExtractException(): void
    {
        $this->textExtractor->method('extractFile')
            ->willThrowException(new \Exception('extraction error'));

        $response = $this->controller->extract(1);

        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals('Extraction failed', $response->getData()['error']);
    }

    public function testDiscoverException(): void
    {
        $this->textExtractor->method('discoverUntrackedFiles')
            ->willThrowException(new \Exception('discover error'));

        $response = $this->controller->discover();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testExtractAllException(): void
    {
        $this->textExtractor->method('extractPendingFiles')
            ->willThrowException(new \Exception('extract all error'));

        $response = $this->controller->extractAll();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testRetryFailedException(): void
    {
        $this->textExtractor->method('retryFailedExtractions')
            ->willThrowException(new \Exception('retry error'));

        $response = $this->controller->retryFailed();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testStatsException(): void
    {
        $this->textExtractor->method('getStats')
            ->willThrowException(new \Exception('stats error'));

        $response = $this->controller->stats();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testShowNoChunks(): void
    {
        $this->chunkMapper->method('findBySource')->willReturn([]);

        $response = $this->controller->show(999);

        $this->assertEquals(404, $response->getStatus());
    }

    public function testShowException(): void
    {
        $this->chunkMapper->method('findBySource')
            ->willThrowException(new \Exception('chunk error'));

        $response = $this->controller->show(1);

        $this->assertEquals(404, $response->getStatus());
    }

    public function testCleanupReturnsSuccess(): void
    {
        $response = $this->controller->cleanup();

        $this->assertEquals(200, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
        $this->assertEquals(0, $response->getData()['data']['deleted']);
    }

    public function testFileTypesReturnsEmpty(): void
    {
        $response = $this->controller->fileTypes();

        $this->assertEquals(200, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
        $this->assertEmpty($response->getData()['data']);
    }

    public function testVectorizeBatchException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->vectorizationService->method('vectorizeBatch')
            ->willThrowException(new \Exception('vec error'));

        $response = $this->controller->vectorizeBatch();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testIndexFilterByNonCompletedStatus(): void
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

        $response = $this->controller->index();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEmpty($response->getData()['data']);
    }
}
