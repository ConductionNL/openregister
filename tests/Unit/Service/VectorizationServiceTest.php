<?php

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Service\VectorizationService;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\Vectorization\Strategies\VectorizationStrategyInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class VectorizationServiceTest extends TestCase
{
    private VectorizationService $service;
    private VectorEmbeddings&MockObject $vectorService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->vectorService = $this->createMock(VectorEmbeddings::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new VectorizationService($this->vectorService, $this->logger);
    }

    private function createStrategy(): VectorizationStrategyInterface&MockObject
    {
        return $this->createMock(VectorizationStrategyInterface::class);
    }

    // ── registerStrategy ──

    public function testRegisterStrategyAddsStrategy(): void
    {
        $strategy = $this->createStrategy();
        $this->service->registerStrategy('object', $strategy);

        // If vectorizeBatch doesn't throw for 'object', it was registered.
        $strategy->method('fetchEntities')->willReturn([]);

        $result = $this->service->vectorizeBatch('object');
        $this->assertTrue($result['success']);
    }

    // ── vectorizeBatch ──

    public function testVectorizeBatchThrowsForUnregisteredEntityType(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No vectorization strategy registered');

        $this->service->vectorizeBatch('unknown');
    }

    public function testVectorizeBatchReturnsZeroWhenNoEntities(): void
    {
        $strategy = $this->createStrategy();
        $strategy->method('fetchEntities')->willReturn([]);
        $this->service->registerStrategy('object', $strategy);

        $result = $this->service->vectorizeBatch('object');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['total_entities']);
        $this->assertSame(0, $result['vectorized']);
        $this->assertSame(0, $result['failed']);
    }

    public function testVectorizeBatchProcessesEntitiesSerially(): void
    {
        $strategy = $this->createStrategy();
        $entity = ['id' => 1, 'data' => 'test'];
        $strategy->method('fetchEntities')->willReturn([$entity]);
        $strategy->method('extractVectorizationItems')->willReturn([
            ['text' => 'Some text to vectorize'],
        ]);
        $strategy->method('getEntityIdentifier')->willReturn('1');
        $strategy->method('prepareVectorMetadata')->willReturn([
            'entity_type' => 'object',
            'entity_id' => '1',
            'chunk_index' => 0,
            'total_chunks' => 1,
            'chunk_text' => 'Some text',
        ]);

        $this->vectorService->method('generateEmbedding')->willReturn([
            'embedding' => [0.1, 0.2, 0.3],
            'model' => 'test-model',
            'dimensions' => 3,
        ]);
        $this->vectorService->expects($this->once())->method('storeVector');

        $this->service->registerStrategy('object', $strategy);

        $result = $this->service->vectorizeBatch('object');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total_entities']);
        $this->assertSame(1, $result['vectorized']);
        $this->assertSame(0, $result['failed']);
    }

    public function testVectorizeBatchHandlesEntityWithNoItems(): void
    {
        $strategy = $this->createStrategy();
        $strategy->method('fetchEntities')->willReturn([['id' => 1]]);
        $strategy->method('extractVectorizationItems')->willReturn([]);
        $strategy->method('getEntityIdentifier')->willReturn('1');

        $this->service->registerStrategy('object', $strategy);

        $result = $this->service->vectorizeBatch('object');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total_entities']);
        $this->assertSame(0, $result['vectorized']);
    }

    public function testVectorizeBatchHandlesEmbeddingFailure(): void
    {
        $strategy = $this->createStrategy();
        $strategy->method('fetchEntities')->willReturn([['id' => 1]]);
        $strategy->method('extractVectorizationItems')->willReturn([
            ['text' => 'test'],
        ]);
        $strategy->method('getEntityIdentifier')->willReturn('1');

        $this->vectorService->method('generateEmbedding')
            ->willThrowException(new Exception('Embedding API error'));

        $this->service->registerStrategy('object', $strategy);

        $result = $this->service->vectorizeBatch('object');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['failed']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testVectorizeBatchHandlesEntityProcessingException(): void
    {
        $strategy = $this->createStrategy();
        $strategy->method('fetchEntities')->willReturn([['id' => 1]]);
        $strategy->method('extractVectorizationItems')
            ->willThrowException(new Exception('Processing error'));
        $strategy->method('getEntityIdentifier')->willReturn('1');

        $this->service->registerStrategy('object', $strategy);

        $result = $this->service->vectorizeBatch('object');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testVectorizeBatchParallelMode(): void
    {
        $strategy = $this->createStrategy();
        $strategy->method('fetchEntities')->willReturn([['id' => 1]]);
        $strategy->method('extractVectorizationItems')->willReturn([
            ['text' => 'chunk1'],
            ['text' => 'chunk2'],
        ]);
        $strategy->method('getEntityIdentifier')->willReturn('1');
        $strategy->method('prepareVectorMetadata')->willReturn([
            'entity_type' => 'object',
            'entity_id' => '1',
            'chunk_index' => 0,
            'total_chunks' => 2,
        ]);

        $this->vectorService->method('generateBatchEmbeddings')->willReturn([
            ['embedding' => [0.1], 'model' => 'test', 'dimensions' => 1],
            ['embedding' => [0.2], 'model' => 'test', 'dimensions' => 1],
        ]);

        $this->service->registerStrategy('object', $strategy);

        $result = $this->service->vectorizeBatch('object', ['mode' => 'parallel', 'batch_size' => 50]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['vectorized']);
    }

    // ── Facade methods (delegates to VectorEmbeddings) ──

    public function testGenerateEmbeddingDelegatesToVectorService(): void
    {
        $expected = ['embedding' => [0.1], 'model' => 'test', 'dimensions' => 1];
        $this->vectorService->method('generateEmbedding')->willReturn($expected);

        $result = $this->service->generateEmbedding('test text');
        $this->assertSame($expected, $result);
    }

    public function testSemanticSearchDelegatesToVectorService(): void
    {
        $expected = [['id' => 1, 'score' => 0.9]];
        $this->vectorService->method('semanticSearch')->willReturn($expected);

        $result = $this->service->semanticSearch('query');
        $this->assertSame($expected, $result);
    }

    public function testHybridSearchDelegatesToVectorService(): void
    {
        $expected = ['results' => []];
        $this->vectorService->method('hybridSearch')->willReturn($expected);

        $result = $this->service->hybridSearch('query');
        $this->assertSame($expected, $result);
    }

    public function testGetVectorStatsDelegatesToVectorService(): void
    {
        $expected = ['total' => 100];
        $this->vectorService->method('getVectorStats')->willReturn($expected);

        $result = $this->service->getVectorStats();
        $this->assertSame($expected, $result);
    }

    public function testTestEmbeddingDelegatesToVectorService(): void
    {
        $expected = ['success' => true, 'message' => 'OK'];
        $this->vectorService->method('testEmbedding')->willReturn($expected);

        $result = $this->service->testEmbedding('openai', []);
        $this->assertSame($expected, $result);
    }

    public function testCheckEmbeddingModelMismatchDelegatesToVectorService(): void
    {
        $expected = ['has_vectors' => false, 'mismatch' => false];
        $this->vectorService->method('checkEmbeddingModelMismatch')->willReturn($expected);

        $result = $this->service->checkEmbeddingModelMismatch();
        $this->assertSame($expected, $result);
    }

    public function testClearAllEmbeddingsDelegatesToVectorService(): void
    {
        $expected = ['success' => true, 'message' => 'Cleared', 'deleted' => 100];
        $this->vectorService->method('clearAllEmbeddings')->willReturn($expected);

        $result = $this->service->clearAllEmbeddings();
        $this->assertSame($expected, $result);
    }
}
