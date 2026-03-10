<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FileSearchController;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileSearchControllerTest extends TestCase
{
    private FileSearchController $controller;
    private IRequest&MockObject $request;
    private IndexService&MockObject $indexService;
    private VectorizationService&MockObject $vectorService;
    private SettingsService&MockObject $settingsService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->vectorService = $this->createMock(VectorizationService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new FileSearchController(
            'openregister',
            $this->request,
            $this->indexService,
            $this->vectorService,
            $this->settingsService,
            $this->logger
        );
    }

    public function testKeywordSearchEmptyQuery(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', ''],
                ['limit', 10, 10],
                ['offset', 0, 0],
                ['file_types', [], []],
            ]);

        $result = $this->controller->keywordSearch();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testKeywordSearchNoCollection(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'test query'],
                ['limit', 10, 10],
                ['offset', 0, 0],
                ['file_types', [], []],
            ]);

        $this->settingsService->method('getSettings')->willReturn([
            'solr' => ['fileCollection' => null],
        ]);

        $result = $this->controller->keywordSearch();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testKeywordSearchException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'test'],
                ['limit', 10, 10],
                ['offset', 0, 0],
                ['file_types', [], []],
            ]);

        $this->settingsService->method('getSettings')->willReturn([
            'solr' => ['fileCollection' => 'files'],
        ]);

        $this->indexService->method('getEndpointUrl')
            ->willThrowException(new \Exception('Connection error'));

        $result = $this->controller->keywordSearch();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testSemanticSearchEmptyQuery(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', ''],
                ['limit', 10, 10],
            ]);

        $result = $this->controller->semanticSearch();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testSemanticSearchSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'test query'],
                ['limit', 10, 10],
            ]);

        $this->vectorService->method('semanticSearch')->willReturn([
            ['id' => 1, 'score' => 0.9],
        ]);

        $result = $this->controller->semanticSearch();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('semantic', $data['search_type']);
        $this->assertEquals(1, $data['total']);
    }

    public function testSemanticSearchException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'test'],
                ['limit', 10, 10],
            ]);

        $this->vectorService->method('semanticSearch')
            ->willThrowException(new \Exception('Vector error'));

        $result = $this->controller->semanticSearch();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testHybridSearchEmptyQuery(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', ''],
                ['limit', 10, 10],
                ['keyword_weight', 0.5, 0.5],
                ['semantic_weight', 0.5, 0.5],
            ]);

        $result = $this->controller->hybridSearch();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testHybridSearchSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'test'],
                ['limit', 10, 10],
                ['keyword_weight', 0.5, 0.6],
                ['semantic_weight', 0.5, 0.4],
            ]);

        $this->vectorService->method('hybridSearch')->willReturn([]);

        $result = $this->controller->hybridSearch();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('hybrid', $data['search_type']);
    }

    public function testHybridSearchException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'test'],
                ['limit', 10, 10],
                ['keyword_weight', 0.5, 0.5],
                ['semantic_weight', 0.5, 0.5],
            ]);

        $this->vectorService->method('hybridSearch')
            ->willThrowException(new \Exception('Hybrid error'));

        $result = $this->controller->hybridSearch();

        $this->assertEquals(500, $result->getStatus());
    }
}
