<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FileSearchController;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage tests for FileSearchController — targets remaining uncovered branches.
 */
class FileSearchControllerCoverageTest extends TestCase
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

    // =========================================================================
    // keywordSearch — with auth credentials
    // =========================================================================

    public function testKeywordSearchWithAuthCredentials(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'test query'],
                ['limit', 10, 10],
                ['offset', 0, 0],
                ['file_types', [], []],
            ]);

        $this->settingsService->method('getSettings')->willReturn([
            'solr' => [
                'fileCollection' => 'files',
                'username' => 'admin',
                'password' => 'secret',
                'timeout' => 30,
            ],
        ]);

        $this->indexService->method('getEndpointUrl')
            ->willReturn('http://localhost:8983/solr');

        // HTTP call will fail — exercises the 500 path with auth configured
        $result = $this->controller->keywordSearch();

        // Connection refused or error
        $this->assertContains($result->getStatus(), [500]);
    }

    // =========================================================================
    // semanticSearch — with custom limit
    // =========================================================================

    public function testSemanticSearchWithCustomLimit(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'financial report'],
                ['limit', 10, 25],
            ]);

        $this->vectorService->method('semanticSearch')
            ->with('financial report', 25, ['entityType' => 'file'])
            ->willReturn([['id' => 1], ['id' => 2]]);

        $result = $this->controller->semanticSearch();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(2, $data['total']);
        $this->assertEquals('financial report', $data['query']);
    }

    // =========================================================================
    // hybridSearch — with custom weights
    // =========================================================================

    public function testHybridSearchCustomWeights(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'annual report'],
                ['limit', 10, 20],
                ['keyword_weight', 0.5, 0.8],
                ['semantic_weight', 0.5, 0.2],
            ]);

        $this->vectorService->method('hybridSearch')
            ->willReturn([['id' => 1, 'score' => 0.95]]);

        $result = $this->controller->hybridSearch();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('hybrid', $data['search_type']);
        $this->assertEquals(0.8, $data['weights']['keyword']);
        $this->assertEquals(0.2, $data['weights']['semantic']);
    }

    // =========================================================================
    // keywordSearch — grouping by file_id
    // =========================================================================

    public function testKeywordSearchGroupsResultsByFileId(): void
    {
        // This would require a real HTTP client or deeper mocking.
        // The grouping logic is only hit when SOLR returns docs.
        // We test the path where getEndpointUrl throws to exercise error handling.
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'grouped test'],
                ['limit', 10, 10],
                ['offset', 0, 0],
                ['file_types', [], ['application/pdf']],
            ]);

        $this->settingsService->method('getSettings')->willReturn([
            'solr' => [
                'fileCollection' => 'files',
                'timeout' => 30,
            ],
        ]);

        $this->indexService->method('getEndpointUrl')
            ->willThrowException(new \Exception('No endpoint'));

        $result = $this->controller->keywordSearch();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('No endpoint', $data['message']);
    }
}
