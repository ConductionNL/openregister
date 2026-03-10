<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SolrController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stub for IndexService methods used by SolrController.
 *
 * The controller calls methods from IndexService and backend-specific methods
 * with param names that differ from IndexService signatures.
 */
class SolrControllerIndexServiceStub
{
    public function listCollections(): array { return []; }
    public function listConfigSets(): array { return []; }
    public function createCollection(
        string $collectionName, string $configSetName,
        int $numShards = 1, int $replicationFactor = 1, int $maxShardsPerNode = 1
    ): array { return []; }
    public function createConfigSet(string $name, string $baseConfigSet = '_default'): array { return []; }
    public function deleteConfigSet(string $name): array { return []; }
    public function copyCollection(string $sourceCollection, string $targetCollection): array { return []; }
}

/**
 * Unit tests for SolrController
 *
 * @package Unit\Controller
 */
class SolrControllerTest extends TestCase
{
    private SolrController $controller;
    private IRequest&MockObject $request;
    private ContainerInterface&MockObject $container;
    private LoggerInterface&MockObject $logger;
    private VectorizationService&MockObject $vectorService;
    private MockObject $indexService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->vectorService = $this->createMock(VectorizationService::class);
        $this->indexService = $this->createMock(IndexService::class);

        // The controller calls methods on what it gets from the container as IndexService.
        // Some methods don't exist on IndexService (they exist on backends), and
        // some use different named params. Use a mock based on an anonymous class
        // that matches the actual call signatures in the controller.
        $this->indexService = $this->createMock(SolrControllerIndexServiceStub::class);

        $this->container->method('get')
            ->willReturnMap([
                [VectorizationService::class, $this->vectorService],
                [IndexService::class, $this->indexService],
                [ObjectEntityMapper::class, $this->createMock(ObjectEntityMapper::class)],
            ]);

        $this->controller = new SolrController(
            'openregister',
            $this->request,
            $this->container,
            $this->logger
        );
    }

    public function testSemanticSearchReturnsResults(): void
    {
        $results = [['id' => 1, 'score' => 0.95]];
        $this->vectorService->method('semanticSearch')->willReturn($results);

        $result = $this->controller->semanticSearch('test query', 10);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('semantic', $data['search_type']);
        $this->assertSame(1, $data['total']);
    }

    public function testSemanticSearchReturns400ForEmptyQuery(): void
    {
        $result = $this->controller->semanticSearch('  ', 10);

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testSemanticSearchReturns400ForInvalidLimit(): void
    {
        $result = $this->controller->semanticSearch('test', 0);

        $this->assertSame(400, $result->getStatus());
    }

    public function testSemanticSearchReturns400ForLimitTooHigh(): void
    {
        $result = $this->controller->semanticSearch('test', 101);

        $this->assertSame(400, $result->getStatus());
    }

    public function testSemanticSearchReturns500OnException(): void
    {
        $this->vectorService->method('semanticSearch')
            ->willThrowException(new Exception('Search failed'));

        $result = $this->controller->semanticSearch('test', 10);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testHybridSearchReturnsResults(): void
    {
        $hybridResult = ['results' => [], 'total' => 0];
        $this->vectorService->method('hybridSearch')->willReturn($hybridResult);

        $result = $this->controller->hybridSearch('test query');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('hybrid', $data['search_type']);
    }

    public function testHybridSearchReturns400ForEmptyQuery(): void
    {
        $result = $this->controller->hybridSearch('');

        $this->assertSame(400, $result->getStatus());
    }

    public function testHybridSearchReturns400ForInvalidLimit(): void
    {
        $result = $this->controller->hybridSearch('test', 201);

        $this->assertSame(400, $result->getStatus());
    }

    public function testHybridSearchReturns400ForInvalidWeights(): void
    {
        $result = $this->controller->hybridSearch('test', 20, [], ['solr' => 1.5, 'vector' => 0.5]);

        $this->assertSame(400, $result->getStatus());
    }

    public function testHybridSearchReturns500OnException(): void
    {
        $this->vectorService->method('hybridSearch')
            ->willThrowException(new Exception('Hybrid search failed'));

        $result = $this->controller->hybridSearch('test');

        $this->assertSame(500, $result->getStatus());
    }

    public function testGetVectorStatsReturnsStats(): void
    {
        $stats = ['total_vectors' => 100];
        $this->vectorService->method('getVectorStats')->willReturn($stats);

        $result = $this->controller->getVectorStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame($stats, $data['stats']);
    }

    public function testGetVectorStatsReturns500OnException(): void
    {
        $this->vectorService->method('getVectorStats')
            ->willThrowException(new Exception('Stats error'));

        $result = $this->controller->getVectorStats();

        $this->assertSame(500, $result->getStatus());
    }

    public function testTestVectorEmbeddingReturns400WhenProviderMissing(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(400, $result->getStatus());
    }

    public function testTestVectorEmbeddingReturns400ForInvalidProvider(): void
    {
        $this->request->method('getParams')->willReturn(['provider' => 'invalid']);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(400, $result->getStatus());
    }

    public function testTestVectorEmbeddingReturns400WhenOpenaiMissingApiKey(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => 'openai',
            'config' => [],
        ]);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(400, $result->getStatus());
    }

    public function testListCollectionsReturnsCollections(): void
    {
        $collections = ['collection1', 'collection2'];
        $this->indexService->method('listCollections')->willReturn($collections);

        $result = $this->controller->listCollections();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['total']);
    }

    public function testListCollectionsReturns500OnException(): void
    {
        $this->indexService->method('listCollections')
            ->willThrowException(new Exception('Connection failed'));

        $result = $this->controller->listCollections();

        $this->assertSame(500, $result->getStatus());
    }

    public function testListConfigSetsReturnsConfigSets(): void
    {
        $configSets = ['_default', 'custom'];
        $this->indexService->method('listConfigSets')->willReturn($configSets);

        $result = $this->controller->listConfigSets();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testCreateCollectionReturnsSuccess(): void
    {
        $this->indexService->method('createCollection')
            ->willReturn(['status' => 'ok']);

        $result = $this->controller->createCollection('test-collection', 'config1');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('test-collection', $data['collection']);
    }

    public function testCreateCollectionReturns500OnException(): void
    {
        $this->indexService->method('createCollection')
            ->willThrowException(new Exception('Failed'));

        $result = $this->controller->createCollection('test', 'config');

        $this->assertSame(500, $result->getStatus());
    }

    public function testCreateConfigSetReturnsSuccess(): void
    {
        $this->indexService->method('createConfigSet')
            ->willReturn(['status' => 'ok']);

        $result = $this->controller->createConfigSet('my-config');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('my-config', $data['configSet']);
    }

    public function testDeleteConfigSetReturnsSuccess(): void
    {
        $this->indexService->method('deleteConfigSet')
            ->willReturn(['status' => 'ok']);

        $result = $this->controller->deleteConfigSet('old-config');

        $this->assertSame(200, $result->getStatus());
    }

    public function testCopyCollectionReturnsSuccess(): void
    {
        $this->indexService->method('copyCollection')
            ->willReturn(['status' => 'ok']);

        $result = $this->controller->copyCollection('source', 'target');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('source', $data['source']);
        $this->assertSame('target', $data['target']);
    }

    public function testBulkVectorizeObjectsReturns400ForInvalidLimit(): void
    {
        $result = $this->controller->bulkVectorizeObjects(null, null, 0);

        $this->assertSame(400, $result->getStatus());
    }

    public function testBulkVectorizeObjectsReturns400ForNegativeOffset(): void
    {
        $result = $this->controller->bulkVectorizeObjects(null, null, 10, -1);

        $this->assertSame(400, $result->getStatus());
    }
}
