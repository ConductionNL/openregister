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
    public function vectorizeObject(ObjectEntity $object, ?string $provider = null): array { return []; }
    public function vectorizeObjects(array $objects, ?string $provider = null): array { return []; }
}

/**
 * Stub for ObjectEntityMapper methods used by SolrController.
 */
class SolrControllerObjectMapperStub
{
    public function find(int $id): ObjectEntity { return new ObjectEntity(); }
    public function findAll(
        ?int $limit = null, ?int $offset = null, ?array $filters = null,
        ?array $searchConditions = null, ?array $searchParams = null,
        array $sort = [], ?string $search = null, ?array $ids = null,
        ?string $uses = null, bool $includeDeleted = false
    ): array { return []; }
    public function countAll(): int { return 0; }
}

/**
 * Stub for VectorizationService methods used by SolrController.
 *
 * The controller calls generateEmbeddingWithCustomConfig which exists on VectorEmbeddings
 * but is called via VectorizationService (forwarded at runtime).
 */
class SolrControllerVectorizationStub
{
    public function semanticSearch(string $query, int $limit = 10, array $filters = [], ?string $provider = null): array { return []; }
    public function hybridSearch(string $query, array $solrFilters = [], int $limit = 20, array $weights = [], ?string $provider = null): array { return []; }
    public function getVectorStats(): array { return []; }
    public function generateEmbeddingWithCustomConfig(string $text, array $config): array { return []; }
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
    private MockObject $vectorService;
    private MockObject $indexService;
    private MockObject $objectMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->vectorService = $this->createMock(SolrControllerVectorizationStub::class);

        $this->indexService = $this->createMock(SolrControllerIndexServiceStub::class);
        $this->objectMapper = $this->createMock(SolrControllerObjectMapperStub::class);

        $this->container->method('get')
            ->willReturnMap([
                [VectorizationService::class, $this->vectorService],
                [IndexService::class, $this->indexService],
                [ObjectEntityMapper::class, $this->objectMapper],
            ]);

        $this->controller = new SolrController(
            'openregister',
            $this->request,
            $this->container,
            $this->logger
        );
    }

    // =========================================================================
    // semanticSearch tests
    // =========================================================================

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
        $this->assertSame('test query', $data['query']);
        $this->assertSame($results, $data['results']);
        $this->assertSame(10, $data['limit']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testSemanticSearchReturns400ForEmptyQuery(): void
    {
        $result = $this->controller->semanticSearch('  ', 10);

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('empty', $data['error']);
    }

    public function testSemanticSearchReturns400ForBlankQuery(): void
    {
        $result = $this->controller->semanticSearch('', 10);

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testSemanticSearchReturns400ForInvalidLimit(): void
    {
        $result = $this->controller->semanticSearch('test', 0);

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Limit', $data['error']);
    }

    public function testSemanticSearchReturns400ForLimitTooHigh(): void
    {
        $result = $this->controller->semanticSearch('test', 101);

        $this->assertSame(400, $result->getStatus());
    }

    public function testSemanticSearchWithFiltersAndProvider(): void
    {
        $filters = ['entity_type' => 'file'];
        $results = [['id' => 2, 'score' => 0.80]];
        $this->vectorService->expects($this->once())
            ->method('semanticSearch')
            ->with('filtered query', 5, $filters, 'openai')
            ->willReturn($results);

        $result = $this->controller->semanticSearch('filtered query', 5, $filters, 'openai');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame($filters, $data['filters']);
    }

    public function testSemanticSearchWithEmptyResults(): void
    {
        $this->vectorService->method('semanticSearch')->willReturn([]);

        $result = $this->controller->semanticSearch('no matches', 10);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['total']);
        $this->assertSame([], $data['results']);
    }

    public function testSemanticSearchReturns500OnException(): void
    {
        $this->vectorService->method('semanticSearch')
            ->willThrowException(new Exception('Search failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Semantic search failed'),
                $this->callback(function ($context) {
                    return $context['error'] === 'Search failed'
                        && $context['query'] === 'test';
                })
            );

        $result = $this->controller->semanticSearch('test', 10);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Search failed', $data['error']);
        $this->assertSame('test', $data['query']);
    }

    public function testSemanticSearchWithBoundaryLimitOne(): void
    {
        $this->vectorService->method('semanticSearch')->willReturn([['id' => 1]]);

        $result = $this->controller->semanticSearch('test', 1);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame(1, $result->getData()['limit']);
    }

    public function testSemanticSearchWithBoundaryLimitHundred(): void
    {
        $this->vectorService->method('semanticSearch')->willReturn([]);

        $result = $this->controller->semanticSearch('test', 100);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame(100, $result->getData()['limit']);
    }

    public function testSemanticSearchWithNegativeLimit(): void
    {
        $result = $this->controller->semanticSearch('test', -1);

        $this->assertSame(400, $result->getStatus());
    }

    // =========================================================================
    // hybridSearch tests
    // =========================================================================

    public function testHybridSearchReturnsResults(): void
    {
        $hybridResult = ['results' => [['id' => 1]], 'total' => 1];
        $this->vectorService->method('hybridSearch')->willReturn($hybridResult);

        $result = $this->controller->hybridSearch('test query');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('hybrid', $data['search_type']);
        $this->assertSame('test query', $data['query']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testHybridSearchReturns400ForEmptyQuery(): void
    {
        $result = $this->controller->hybridSearch('');

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testHybridSearchReturns400ForWhitespaceOnlyQuery(): void
    {
        $result = $this->controller->hybridSearch('   ');

        $this->assertSame(400, $result->getStatus());
    }

    public function testHybridSearchReturns400ForInvalidLimit(): void
    {
        $result = $this->controller->hybridSearch('test', 201);

        $this->assertSame(400, $result->getStatus());
    }

    public function testHybridSearchReturns400ForZeroLimit(): void
    {
        $result = $this->controller->hybridSearch('test', 0);

        $this->assertSame(400, $result->getStatus());
    }

    public function testHybridSearchReturns400ForNegativeLimit(): void
    {
        $result = $this->controller->hybridSearch('test', -5);

        $this->assertSame(400, $result->getStatus());
    }

    public function testHybridSearchWithBoundaryLimitOne(): void
    {
        $this->vectorService->method('hybridSearch')->willReturn([]);

        $result = $this->controller->hybridSearch('test', 1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testHybridSearchWithBoundaryLimitTwoHundred(): void
    {
        $this->vectorService->method('hybridSearch')->willReturn([]);

        $result = $this->controller->hybridSearch('test', 200);

        $this->assertSame(200, $result->getStatus());
    }

    public function testHybridSearchReturns400ForInvalidWeights(): void
    {
        $result = $this->controller->hybridSearch('test', 20, [], ['solr' => 1.5, 'vector' => 0.5]);

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Weights', $data['error']);
    }

    public function testHybridSearchReturns400ForNegativeSolrWeight(): void
    {
        $result = $this->controller->hybridSearch('test', 20, [], ['solr' => -0.1, 'vector' => 0.5]);

        $this->assertSame(400, $result->getStatus());
    }

    public function testHybridSearchReturns400ForNegativeVectorWeight(): void
    {
        $result = $this->controller->hybridSearch('test', 20, [], ['solr' => 0.5, 'vector' => -0.1]);

        $this->assertSame(400, $result->getStatus());
    }

    public function testHybridSearchReturns400ForVectorWeightTooHigh(): void
    {
        $result = $this->controller->hybridSearch('test', 20, [], ['solr' => 0.5, 'vector' => 1.1]);

        $this->assertSame(400, $result->getStatus());
    }

    public function testHybridSearchWithValidWeights(): void
    {
        $weights = ['solr' => 0.7, 'vector' => 0.3];
        $this->vectorService->expects($this->once())
            ->method('hybridSearch')
            ->with('test', [], 20, $weights, null)
            ->willReturn(['results' => []]);

        $result = $this->controller->hybridSearch('test', 20, [], $weights);

        $this->assertSame(200, $result->getStatus());
    }

    public function testHybridSearchWithDefaultWeights(): void
    {
        $this->vectorService->expects($this->once())
            ->method('hybridSearch')
            ->with('test', [], 20, ['solr' => 0.5, 'vector' => 0.5], null)
            ->willReturn(['merged' => []]);

        $result = $this->controller->hybridSearch('test');

        $this->assertSame(200, $result->getStatus());
    }

    public function testHybridSearchWithSolrFilters(): void
    {
        $solrFilters = ['category' => 'docs', 'status' => 'active'];
        $this->vectorService->expects($this->once())
            ->method('hybridSearch')
            ->with('test', $solrFilters, 20, $this->anything(), null)
            ->willReturn([]);

        $result = $this->controller->hybridSearch('test', 20, $solrFilters);

        $this->assertSame(200, $result->getStatus());
    }

    public function testHybridSearchWithProvider(): void
    {
        $this->vectorService->expects($this->once())
            ->method('hybridSearch')
            ->with('test', [], 20, $this->anything(), 'ollama')
            ->willReturn([]);

        $result = $this->controller->hybridSearch('test', 20, [], ['solr' => 0.5, 'vector' => 0.5], 'ollama');

        $this->assertSame(200, $result->getStatus());
    }

    public function testHybridSearchWithEmptyArrayResult(): void
    {
        // When hybridSearch returns an empty array, resultArray is empty
        $this->vectorService->method('hybridSearch')->willReturn([]);

        $result = $this->controller->hybridSearch('test');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('hybrid', $data['search_type']);
    }

    public function testHybridSearchSpreadsResultArray(): void
    {
        $hybridResult = [
            'solr_results' => [['id' => 1]],
            'vector_results' => [['id' => 2]],
            'merged' => [['id' => 1], ['id' => 2]],
        ];
        $this->vectorService->method('hybridSearch')->willReturn($hybridResult);

        $result = $this->controller->hybridSearch('test');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // The spread operator should include these keys
        $this->assertArrayHasKey('solr_results', $data);
        $this->assertArrayHasKey('vector_results', $data);
        $this->assertArrayHasKey('merged', $data);
    }

    public function testHybridSearchWithMissingWeightKeysUsesDefaults(): void
    {
        // When weights array doesn't have 'solr' or 'vector' keys, defaults to 0.5
        $this->vectorService->method('hybridSearch')->willReturn([]);

        $result = $this->controller->hybridSearch('test', 20, [], []);

        $this->assertSame(200, $result->getStatus());
    }

    public function testHybridSearchReturns500OnException(): void
    {
        $this->vectorService->method('hybridSearch')
            ->willThrowException(new Exception('Hybrid search failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Hybrid search failed'),
                $this->callback(function ($context) {
                    return $context['error'] === 'Hybrid search failed';
                })
            );

        $result = $this->controller->hybridSearch('test');

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Hybrid search failed', $data['error']);
        $this->assertSame('test', $data['query']);
    }

    // =========================================================================
    // getVectorStats tests
    // =========================================================================

    public function testGetVectorStatsReturnsStats(): void
    {
        $stats = ['total_vectors' => 100];
        $this->vectorService->method('getVectorStats')->willReturn($stats);

        $result = $this->controller->getVectorStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame($stats, $data['stats']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testGetVectorStatsReturns500OnException(): void
    {
        $this->vectorService->method('getVectorStats')
            ->willThrowException(new Exception('Stats error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to get vector stats'),
                $this->anything()
            );

        $result = $this->controller->getVectorStats();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Stats error', $data['error']);
    }

    // =========================================================================
    // testVectorEmbedding tests
    // =========================================================================

    public function testTestVectorEmbeddingReturns400WhenProviderMissing(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Provider is required', $data['error']);
    }

    public function testTestVectorEmbeddingReturns400WhenProviderEmpty(): void
    {
        $this->request->method('getParams')->willReturn(['provider' => '']);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(400, $result->getStatus());
    }

    public function testTestVectorEmbeddingReturns400ForInvalidProvider(): void
    {
        $this->request->method('getParams')->willReturn(['provider' => 'invalid']);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Invalid provider', $data['error']);
    }

    public function testTestVectorEmbeddingReturns400WhenOpenaiMissingApiKey(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => 'openai',
            'config' => [],
        ]);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('OpenAI API key', $data['error']);
    }

    public function testTestVectorEmbeddingReturns400WhenOpenaiApiKeyEmpty(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => 'openai',
            'config' => ['apiKey' => ''],
        ]);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(400, $result->getStatus());
    }

    public function testTestVectorEmbeddingReturns400WhenFireworksMissingApiKey(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => 'fireworks',
            'config' => [],
        ]);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Fireworks', $data['error']);
    }

    public function testTestVectorEmbeddingReturns400WhenFireworksApiKeyEmpty(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => 'fireworks',
            'config' => ['apiKey' => ''],
        ]);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(400, $result->getStatus());
    }

    public function testTestVectorEmbeddingSuccessWithOpenai(): void
    {
        $embedding = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6];
        $this->request->method('getParams')->willReturn([
            'provider' => 'openai',
            'config' => ['apiKey' => 'sk-test123', 'model' => 'text-embedding-3-large'],
            'testText' => 'Hello world',
        ]);

        $this->vectorService->expects($this->once())
            ->method('generateEmbeddingWithCustomConfig')
            ->with('Hello world', $this->callback(function ($config) {
                return $config['provider'] === 'openai'
                    && $config['apiKey'] === 'sk-test123'
                    && $config['model'] === 'text-embedding-3-large';
            }))
            ->willReturn($embedding);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Testing vector embedding'), $this->anything());

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('Embedding generated successfully', $data['message']);
        $this->assertSame('openai', $data['metadata']['provider']);
        $this->assertSame('text-embedding-3-large', $data['metadata']['model']);
        $this->assertSame(6, $data['metadata']['dimensions']);
        $this->assertSame(11, $data['metadata']['textLength']);
        $this->assertIsFloat($data['metadata']['duration_ms']);
        $this->assertCount(5, $data['metadata']['firstValues']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testTestVectorEmbeddingSuccessWithOpenaiDefaultModel(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->request->method('getParams')->willReturn([
            'provider' => 'openai',
            'config' => ['apiKey' => 'sk-test'],
        ]);

        $this->vectorService->expects($this->once())
            ->method('generateEmbeddingWithCustomConfig')
            ->with($this->anything(), $this->callback(function ($config) {
                return $config['model'] === 'text-embedding-3-small';
            }))
            ->willReturn($embedding);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(200, $result->getStatus());
    }

    public function testTestVectorEmbeddingSuccessWithOllama(): void
    {
        $embedding = [0.5, 0.6, 0.7, 0.8];
        $this->request->method('getParams')->willReturn([
            'provider' => 'ollama',
            'config' => ['url' => 'http://ollama:11434', 'model' => 'mxbai-embed-large'],
            'testText' => 'Test ollama embedding',
        ]);

        $this->vectorService->expects($this->once())
            ->method('generateEmbeddingWithCustomConfig')
            ->with('Test ollama embedding', $this->callback(function ($config) {
                return $config['provider'] === 'ollama'
                    && $config['url'] === 'http://ollama:11434'
                    && $config['model'] === 'mxbai-embed-large';
            }))
            ->willReturn($embedding);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('ollama', $data['metadata']['provider']);
        $this->assertSame('mxbai-embed-large', $data['metadata']['model']);
    }

    public function testTestVectorEmbeddingSuccessWithOllamaDefaults(): void
    {
        $embedding = [0.1];
        $this->request->method('getParams')->willReturn([
            'provider' => 'ollama',
            'config' => [],
        ]);

        $this->vectorService->expects($this->once())
            ->method('generateEmbeddingWithCustomConfig')
            ->with($this->anything(), $this->callback(function ($config) {
                return $config['url'] === 'http://localhost:11434'
                    && $config['model'] === 'nomic-embed-text';
            }))
            ->willReturn($embedding);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(200, $result->getStatus());
    }

    public function testTestVectorEmbeddingSuccessWithFireworks(): void
    {
        $embedding = [0.9, 0.8, 0.7];
        $this->request->method('getParams')->willReturn([
            'provider' => 'fireworks',
            'config' => [
                'apiKey' => 'fw-test-key',
                'model' => 'custom-model',
                'baseUrl' => 'https://custom.fireworks.ai/v1',
            ],
        ]);

        $this->vectorService->expects($this->once())
            ->method('generateEmbeddingWithCustomConfig')
            ->with($this->anything(), $this->callback(function ($config) {
                return $config['provider'] === 'fireworks'
                    && $config['apiKey'] === 'fw-test-key'
                    && $config['model'] === 'custom-model'
                    && $config['baseUrl'] === 'https://custom.fireworks.ai/v1';
            }))
            ->willReturn($embedding);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('fireworks', $data['metadata']['provider']);
    }

    public function testTestVectorEmbeddingSuccessWithFireworksDefaults(): void
    {
        $embedding = [0.1];
        $this->request->method('getParams')->willReturn([
            'provider' => 'fireworks',
            'config' => ['apiKey' => 'fw-key'],
        ]);

        $this->vectorService->expects($this->once())
            ->method('generateEmbeddingWithCustomConfig')
            ->with($this->anything(), $this->callback(function ($config) {
                return $config['model'] === 'nomic-ai/nomic-embed-text-v1.5'
                    && $config['baseUrl'] === 'https://api.fireworks.ai/inference/v1';
            }))
            ->willReturn($embedding);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(200, $result->getStatus());
    }

    public function testTestVectorEmbeddingWithDefaultTestText(): void
    {
        $embedding = [0.1, 0.2];
        $this->request->method('getParams')->willReturn([
            'provider' => 'ollama',
        ]);

        $this->vectorService->expects($this->once())
            ->method('generateEmbeddingWithCustomConfig')
            ->with('This is a test embedding generation.', $this->anything())
            ->willReturn($embedding);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(200, $result->getStatus());
    }

    public function testTestVectorEmbeddingReturns500WhenEmbeddingEmpty(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => 'ollama',
            'config' => [],
        ]);

        $this->vectorService->method('generateEmbeddingWithCustomConfig')
            ->willReturn([]);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testTestVectorEmbeddingReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => 'ollama',
            'config' => [],
        ]);

        $this->vectorService->method('generateEmbeddingWithCustomConfig')
            ->willThrowException(new Exception('Connection refused'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->stringContains('Failed to test vector embedding'),
                $this->callback(function ($context) {
                    return $context['error'] === 'Connection refused'
                        && $context['provider'] === 'ollama';
                })
            );

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Connection refused', $data['error']);
    }

    public function testTestVectorEmbeddingFirstValuesLimitedToFive(): void
    {
        $embedding = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0];
        $this->request->method('getParams')->willReturn([
            'provider' => 'ollama',
            'config' => [],
        ]);

        $this->vectorService->method('generateEmbeddingWithCustomConfig')
            ->willReturn($embedding);

        $result = $this->controller->testVectorEmbedding();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(5, $data['metadata']['firstValues']);
        $this->assertSame([0.1, 0.2, 0.3, 0.4, 0.5], $data['metadata']['firstValues']);
        $this->assertSame(10, $data['metadata']['dimensions']);
    }

    // =========================================================================
    // listCollections tests
    // =========================================================================

    public function testListCollectionsReturnsCollections(): void
    {
        $collections = ['collection1', 'collection2'];
        $this->indexService->method('listCollections')->willReturn($collections);

        $result = $this->controller->listCollections();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['total']);
        $this->assertSame($collections, $data['collections']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testListCollectionsReturnsEmptyList(): void
    {
        $this->indexService->method('listCollections')->willReturn([]);

        $result = $this->controller->listCollections();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['total']);
    }

    public function testListCollectionsReturns500OnException(): void
    {
        $this->indexService->method('listCollections')
            ->willThrowException(new Exception('Connection failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to list collections'), $this->anything());

        $result = $this->controller->listCollections();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Connection failed', $data['error']);
    }

    // =========================================================================
    // listConfigSets tests
    // =========================================================================

    public function testListConfigSetsReturnsConfigSets(): void
    {
        $configSets = ['_default', 'custom'];
        $this->indexService->method('listConfigSets')->willReturn($configSets);

        $result = $this->controller->listConfigSets();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame($configSets, $data['configSets']);
        $this->assertSame(2, $data['total']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testListConfigSetsReturnsEmptyList(): void
    {
        $this->indexService->method('listConfigSets')->willReturn([]);

        $result = $this->controller->listConfigSets();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(0, $data['total']);
    }

    public function testListConfigSetsReturns500OnException(): void
    {
        $this->indexService->method('listConfigSets')
            ->willThrowException(new Exception('Config error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to list ConfigSets'), $this->anything());

        $result = $this->controller->listConfigSets();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Config error', $data['error']);
    }

    // =========================================================================
    // createCollection tests
    // =========================================================================

    public function testCreateCollectionReturnsSuccess(): void
    {
        $this->indexService->method('createCollection')
            ->willReturn(['status' => 'ok']);

        $result = $this->controller->createCollection('test-collection', 'config1');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('Collection created successfully', $data['message']);
        $this->assertSame('test-collection', $data['collection']);
        $this->assertSame(['status' => 'ok'], $data['result']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testCreateCollectionWithCustomShardConfig(): void
    {
        $this->indexService->expects($this->once())
            ->method('createCollection')
            ->with('my-col', 'my-config', 3, 2, 5)
            ->willReturn(['status' => 'created']);

        $result = $this->controller->createCollection('my-col', 'my-config', 3, 2, 5);

        $this->assertSame(200, $result->getStatus());
    }

    public function testCreateCollectionReturns500OnException(): void
    {
        $this->indexService->method('createCollection')
            ->willThrowException(new Exception('Failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to create collection'),
                $this->callback(function ($context) {
                    return $context['collection'] === 'test';
                })
            );

        $result = $this->controller->createCollection('test', 'config');

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Failed', $data['error']);
    }

    // =========================================================================
    // createConfigSet tests
    // =========================================================================

    public function testCreateConfigSetReturnsSuccess(): void
    {
        $this->indexService->method('createConfigSet')
            ->willReturn(['status' => 'ok']);

        $result = $this->controller->createConfigSet('my-config');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('ConfigSet created successfully', $data['message']);
        $this->assertSame('my-config', $data['configSet']);
        $this->assertSame(['status' => 'ok'], $data['result']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testCreateConfigSetWithCustomBase(): void
    {
        $this->indexService->expects($this->once())
            ->method('createConfigSet')
            ->with('new-config', 'existing-config')
            ->willReturn(['status' => 'ok']);

        $result = $this->controller->createConfigSet('new-config', 'existing-config');

        $this->assertSame(200, $result->getStatus());
    }

    public function testCreateConfigSetReturns500OnException(): void
    {
        $this->indexService->method('createConfigSet')
            ->willThrowException(new Exception('Config create failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to create ConfigSet'),
                $this->callback(function ($context) {
                    return $context['configSet'] === 'bad-config';
                })
            );

        $result = $this->controller->createConfigSet('bad-config');

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Config create failed', $data['error']);
    }

    // =========================================================================
    // deleteConfigSet tests
    // =========================================================================

    public function testDeleteConfigSetReturnsSuccess(): void
    {
        $this->indexService->method('deleteConfigSet')
            ->willReturn(['status' => 'ok']);

        $result = $this->controller->deleteConfigSet('old-config');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('ConfigSet deleted successfully', $data['message']);
        $this->assertSame('old-config', $data['configSet']);
        $this->assertSame(['status' => 'ok'], $data['result']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testDeleteConfigSetReturns500OnException(): void
    {
        $this->indexService->method('deleteConfigSet')
            ->willThrowException(new Exception('Delete failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to delete ConfigSet'),
                $this->callback(function ($context) {
                    return $context['configSet'] === 'protected-config';
                })
            );

        $result = $this->controller->deleteConfigSet('protected-config');

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Delete failed', $data['error']);
    }

    // =========================================================================
    // copyCollection tests
    // =========================================================================

    public function testCopyCollectionReturnsSuccess(): void
    {
        $this->indexService->method('copyCollection')
            ->willReturn(['status' => 'ok']);

        $result = $this->controller->copyCollection('source', 'target');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('Collection copied successfully', $data['message']);
        $this->assertSame('source', $data['source']);
        $this->assertSame('target', $data['target']);
        $this->assertSame(['status' => 'ok'], $data['result']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testCopyCollectionReturns500OnException(): void
    {
        $this->indexService->method('copyCollection')
            ->willThrowException(new Exception('Copy failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to copy collection'),
                $this->callback(function ($context) {
                    return $context['source'] === 'src' && $context['target'] === 'tgt';
                })
            );

        $result = $this->controller->copyCollection('src', 'tgt');

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Copy failed', $data['error']);
    }

    // =========================================================================
    // vectorizeObject tests
    // =========================================================================

    public function testVectorizeObjectReturnsSuccess(): void
    {
        $object = new ObjectEntity();
        $this->objectMapper->method('find')->with(42)->willReturn($object);
        $this->indexService->method('vectorizeObject')
            ->with($object, null)
            ->willReturn(['dimensions' => 384, 'model' => 'nomic']);

        $result = $this->controller->vectorizeObject(42);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('Object vectorized successfully', $data['message']);
        $this->assertSame(384, $data['dimensions']);
        $this->assertSame('nomic', $data['model']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testVectorizeObjectWithProvider(): void
    {
        $object = new ObjectEntity();
        $this->objectMapper->method('find')->with(99)->willReturn($object);
        $this->indexService->expects($this->once())
            ->method('vectorizeObject')
            ->with($object, 'openai')
            ->willReturn(['provider' => 'openai']);

        $result = $this->controller->vectorizeObject(99, 'openai');

        $this->assertSame(200, $result->getStatus());
    }

    public function testVectorizeObjectWithEmptyArrayResult(): void
    {
        $object = new ObjectEntity();
        $this->objectMapper->method('find')->willReturn($object);
        $this->indexService->method('vectorizeObject')->willReturn([]);

        $result = $this->controller->vectorizeObject(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('Object vectorized successfully', $data['message']);
    }

    public function testVectorizeObjectReturns500OnObjectNotFound(): void
    {
        $this->objectMapper->method('find')
            ->willThrowException(new Exception('Object not found'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to vectorize object'),
                $this->callback(function ($context) {
                    return $context['object_id'] === 999;
                })
            );

        $result = $this->controller->vectorizeObject(999);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Object not found', $data['error']);
        $this->assertSame(999, $data['object_id']);
    }

    public function testVectorizeObjectReturns500OnVectorizationFailure(): void
    {
        $object = new ObjectEntity();
        $this->objectMapper->method('find')->willReturn($object);
        $this->indexService->method('vectorizeObject')
            ->willThrowException(new Exception('Embedding service unavailable'));

        $result = $this->controller->vectorizeObject(5);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Embedding service unavailable', $data['error']);
        $this->assertSame(5, $data['object_id']);
    }

    // =========================================================================
    // bulkVectorizeObjects tests
    // =========================================================================

    public function testBulkVectorizeObjectsReturns400ForInvalidLimit(): void
    {
        $result = $this->controller->bulkVectorizeObjects(null, null, 0);

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Limit', $data['error']);
    }

    public function testBulkVectorizeObjectsReturns400ForLimitTooHigh(): void
    {
        $result = $this->controller->bulkVectorizeObjects(null, null, 1001);

        $this->assertSame(400, $result->getStatus());
    }

    public function testBulkVectorizeObjectsReturns400ForNegativeLimit(): void
    {
        $result = $this->controller->bulkVectorizeObjects(null, null, -1);

        $this->assertSame(400, $result->getStatus());
    }

    public function testBulkVectorizeObjectsReturns400ForNegativeOffset(): void
    {
        $result = $this->controller->bulkVectorizeObjects(null, null, 10, -1);

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Offset', $data['error']);
    }

    public function testBulkVectorizeObjectsBoundaryLimitOne(): void
    {
        $object = new ObjectEntity();
        $this->objectMapper->method('findAll')->willReturn([$object]);
        $this->indexService->method('vectorizeObjects')
            ->willReturn(['success' => true, 'total' => 1, 'successful' => 1, 'failed' => 0]);

        $result = $this->controller->bulkVectorizeObjects(null, null, 1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testBulkVectorizeObjectsBoundaryLimitThousand(): void
    {
        $this->objectMapper->method('findAll')->willReturn([]);

        $result = $this->controller->bulkVectorizeObjects(null, null, 1000);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('No objects found to vectorize', $data['message']);
    }

    public function testBulkVectorizeObjectsReturnsEmptyResult(): void
    {
        $this->objectMapper->method('findAll')->willReturn([]);

        $result = $this->controller->bulkVectorizeObjects(null, null, 50, 0);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('No objects found to vectorize', $data['message']);
        $this->assertSame(0, $data['total']);
        $this->assertSame(0, $data['successful']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame([], $data['results']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testBulkVectorizeObjectsReturnsSuccessWithResults(): void
    {
        $objects = [new ObjectEntity(), new ObjectEntity()];
        $this->objectMapper->method('findAll')->willReturn($objects);

        $vectorResult = [
            'success' => true,
            'total' => 2,
            'successful' => 2,
            'failed' => 0,
            'results' => [['id' => 1, 'status' => 'ok'], ['id' => 2, 'status' => 'ok']],
        ];
        $this->indexService->method('vectorizeObjects')
            ->with($objects, null)
            ->willReturn($vectorResult);

        $result = $this->controller->bulkVectorizeObjects(1, 2, 100, 0);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('Processed 2 of 2', $data['message']);
        $this->assertSame(2, $data['total']);
        $this->assertSame(2, $data['successful']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame(100, $data['pagination']['limit']);
        $this->assertSame(0, $data['pagination']['offset']);
        $this->assertFalse($data['pagination']['has_more']);
        $this->assertSame(1, $data['filters']['schema_id']);
        $this->assertSame(2, $data['filters']['register_id']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testBulkVectorizeObjectsHasMoreWhenFullPage(): void
    {
        // When number of objects equals limit, has_more should be true
        $objects = array_fill(0, 10, new ObjectEntity());
        $this->objectMapper->method('findAll')->willReturn($objects);

        $vectorResult = [
            'success' => true,
            'total' => 10,
            'successful' => 10,
            'failed' => 0,
        ];
        $this->indexService->method('vectorizeObjects')->willReturn($vectorResult);

        $result = $this->controller->bulkVectorizeObjects(null, null, 10, 0);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['pagination']['has_more']);
    }

    public function testBulkVectorizeObjectsHasMoreFalseWhenPartialPage(): void
    {
        // When number of objects is less than limit, has_more should be false
        $objects = array_fill(0, 5, new ObjectEntity());
        $this->objectMapper->method('findAll')->willReturn($objects);

        $vectorResult = [
            'success' => true,
            'total' => 5,
            'successful' => 5,
            'failed' => 0,
        ];
        $this->indexService->method('vectorizeObjects')->willReturn($vectorResult);

        $result = $this->controller->bulkVectorizeObjects(null, null, 10, 0);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['pagination']['has_more']);
    }

    public function testBulkVectorizeObjectsWithProvider(): void
    {
        $objects = [new ObjectEntity()];
        $this->objectMapper->method('findAll')->willReturn($objects);

        $this->indexService->expects($this->once())
            ->method('vectorizeObjects')
            ->with($objects, 'openai')
            ->willReturn(['success' => true, 'total' => 1, 'successful' => 1, 'failed' => 0]);

        $result = $this->controller->bulkVectorizeObjects(null, null, 100, 0, 'openai');

        $this->assertSame(200, $result->getStatus());
    }

    public function testBulkVectorizeObjectsWithNonArrayResult(): void
    {
        $objects = [new ObjectEntity()];
        $this->objectMapper->method('findAll')->willReturn($objects);

        // When vectorizeObjects returns a non-array result, resultArray stays empty
        // But the code accesses $result['success'] and $result['successful'] etc,
        // which would cause an error. This tests the is_array check path.
        $vectorResult = [
            'success' => true,
            'total' => 1,
            'successful' => 1,
            'failed' => 0,
        ];
        $this->indexService->method('vectorizeObjects')->willReturn($vectorResult);

        $result = $this->controller->bulkVectorizeObjects(null, null, 100, 0);

        $this->assertSame(200, $result->getStatus());
    }

    public function testBulkVectorizeObjectsWithOffset(): void
    {
        $this->objectMapper->expects($this->once())
            ->method('findAll')
            ->with(50, 100)
            ->willReturn([]);

        $result = $this->controller->bulkVectorizeObjects(null, null, 50, 100);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('No objects found to vectorize', $data['message']);
    }

    public function testBulkVectorizeObjectsReturns500OnException(): void
    {
        $this->objectMapper->method('findAll')
            ->willThrowException(new Exception('Database error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to bulk vectorize'),
                $this->callback(function ($context) {
                    return $context['error'] === 'Database error'
                        && $context['schema_id'] === 1
                        && $context['register_id'] === 2
                        && $context['limit'] === 50
                        && $context['offset'] === 10;
                })
            );

        $result = $this->controller->bulkVectorizeObjects(1, 2, 50, 10);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Database error', $data['error']);
    }

    public function testBulkVectorizeObjectsWithNullFilters(): void
    {
        $this->objectMapper->method('findAll')->willReturn([]);

        $result = $this->controller->bulkVectorizeObjects(null, null, 100, 0);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // When no filters, the message still applies
        $this->assertSame('No objects found to vectorize', $data['message']);
    }

    // =========================================================================
    // getVectorizationStats tests
    // =========================================================================

    public function testGetVectorizationStatsReturnsStats(): void
    {
        $vectorStats = [
            'object_vectors' => 50,
            'file_vectors' => 30,
        ];
        $this->vectorService->method('getVectorStats')->willReturn($vectorStats);
        $this->objectMapper->method('countAll')->willReturn(100);

        $result = $this->controller->getVectorizationStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(100, $data['stats']['total_objects']);
        $this->assertSame(50, $data['stats']['vectorized_objects']);
        $this->assertSame(50.0, $data['stats']['progress_percentage']);
        $this->assertSame(50, $data['stats']['remaining_objects']);
        $this->assertSame($vectorStats, $data['stats']['vector_breakdown']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testGetVectorizationStatsWithZeroObjects(): void
    {
        $vectorStats = ['object_vectors' => 0];
        $this->vectorService->method('getVectorStats')->willReturn($vectorStats);
        $this->objectMapper->method('countAll')->willReturn(0);

        $result = $this->controller->getVectorizationStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['stats']['total_objects']);
        $this->assertSame(0, $data['stats']['vectorized_objects']);
        // When totalObjects is 0, progress stays as int 0 (never enters round())
        $this->assertSame(0, $data['stats']['progress_percentage']);
        $this->assertSame(0, $data['stats']['remaining_objects']);
    }

    public function testGetVectorizationStatsWithAllVectorized(): void
    {
        $vectorStats = ['object_vectors' => 200];
        $this->vectorService->method('getVectorStats')->willReturn($vectorStats);
        $this->objectMapper->method('countAll')->willReturn(200);

        $result = $this->controller->getVectorizationStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(100.0, $data['stats']['progress_percentage']);
        $this->assertSame(0, $data['stats']['remaining_objects']);
    }

    public function testGetVectorizationStatsWithMissingObjectVectorsKey(): void
    {
        // When 'object_vectors' key is missing, it defaults to 0
        $vectorStats = ['total' => 100];
        $this->vectorService->method('getVectorStats')->willReturn($vectorStats);
        $this->objectMapper->method('countAll')->willReturn(50);

        $result = $this->controller->getVectorizationStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(0, $data['stats']['vectorized_objects']);
        $this->assertSame(0.0, $data['stats']['progress_percentage']);
        $this->assertSame(50, $data['stats']['remaining_objects']);
    }

    public function testGetVectorizationStatsProgressRounding(): void
    {
        $vectorStats = ['object_vectors' => 1];
        $this->vectorService->method('getVectorStats')->willReturn($vectorStats);
        $this->objectMapper->method('countAll')->willReturn(3);

        $result = $this->controller->getVectorizationStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // 1/3 * 100 = 33.33... rounded to 2 decimal places
        $this->assertSame(33.33, $data['stats']['progress_percentage']);
        $this->assertSame(2, $data['stats']['remaining_objects']);
    }

    public function testGetVectorizationStatsReturns500OnException(): void
    {
        $this->vectorService->method('getVectorStats')
            ->willThrowException(new Exception('Vector stats failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to get vectorization stats'),
                $this->callback(function ($context) {
                    return $context['error'] === 'Vector stats failed'
                        && array_key_exists('trace', $context);
                })
            );

        $result = $this->controller->getVectorizationStats();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Vector stats failed', $data['error']);
    }

    public function testGetVectorizationStatsReturns500OnMapperException(): void
    {
        $this->vectorService->method('getVectorStats')->willReturn(['object_vectors' => 10]);
        $this->objectMapper->method('countAll')
            ->willThrowException(new Exception('DB connection lost'));

        $result = $this->controller->getVectorizationStats();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('DB connection lost', $data['error']);
    }
}
