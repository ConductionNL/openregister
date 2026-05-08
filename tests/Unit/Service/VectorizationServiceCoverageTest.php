<?php

/**
 * VectorizationService Coverage Tests
 *
 * Tests for uncovered branches in VectorizationService: vectorizeBatch empty entities,
 * vectorizeBatch entity exception, vectorizeEntity serial and parallel modes,
 * parallel embedding failures, getStrategy exception, and facade delegation methods.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Service\VectorizationService;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\Vectorization\Strategies\VectorizationStrategyInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class VectorizationServiceCoverageTest extends TestCase
{
    private VectorizationService $service;
    private VectorEmbeddings|MockObject $vectorService;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->vectorService = $this->createMock(VectorEmbeddings::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new VectorizationService($this->vectorService, $this->logger);
    }

    private function invokeMethod(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    // =========================================================================
    // registerStrategy
    // =========================================================================

    public function testRegisterStrategy(): void
    {
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        $this->service->registerStrategy('object', $strategy);

        // Verify it's stored by trying to vectorize (would throw if not registered)
        $strategy->method('fetchEntities')->willReturn([]);
        $result = $this->service->vectorizeBatch('object');

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // getStrategy — not registered
    // =========================================================================

    public function testGetStrategyThrowsForUnknownType(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No vectorization strategy registered for entity type: unknown');

        $this->service->vectorizeBatch('unknown');
    }

    // =========================================================================
    // vectorizeBatch — empty entities
    // =========================================================================

    public function testVectorizeBatchEmptyEntities(): void
    {
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        $strategy->method('fetchEntities')->willReturn([]);

        $this->service->registerStrategy('test', $strategy);
        $result = $this->service->vectorizeBatch('test');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['total_entities']);
        $this->assertSame('No entities found to vectorize', $result['message']);
    }

    // =========================================================================
    // vectorizeBatch — with entity that throws exception
    // =========================================================================

    public function testVectorizeBatchEntityException(): void
    {
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        $strategy->method('fetchEntities')->willReturn(['entity1']);
        $strategy->method('extractVectorizationItems')
            ->willThrowException(new Exception('Extract failed'));
        $strategy->method('getEntityIdentifier')->willReturn('id-1');

        $this->service->registerStrategy('test', $strategy);
        $result = $this->service->vectorizeBatch('test');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total_entities']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame('Extract failed', $result['errors'][0]['error']);
    }

    // =========================================================================
    // vectorizeBatch — fetchEntities throws (outer catch)
    // =========================================================================

    public function testVectorizeBatchFetchEntitiesThrows(): void
    {
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        $strategy->method('fetchEntities')->willThrowException(new Exception('DB error'));

        $this->service->registerStrategy('test', $strategy);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DB error');
        $this->service->vectorizeBatch('test');
    }

    // =========================================================================
    // vectorizeEntity — serial mode success
    // =========================================================================

    public function testVectorizeEntitySerialSuccess(): void
    {
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        $strategy->method('fetchEntities')->willReturn(['entity1']);
        $strategy->method('getEntityIdentifier')->willReturn('uuid-1');
        $strategy->method('extractVectorizationItems')->willReturn([
            ['text' => 'Hello world'],
        ]);
        $strategy->method('prepareVectorMetadata')->willReturn([
            'entity_type' => 'object',
            'entity_id' => 'uuid-1',
            'chunk_index' => 0,
            'total_chunks' => 1,
        ]);

        $this->vectorService->method('generateEmbedding')->willReturn([
            'embedding' => [0.1, 0.2, 0.3],
            'model' => 'test-model',
            'dimensions' => 3,
        ]);
        $this->vectorService->expects($this->once())->method('storeVector');

        $this->service->registerStrategy('test', $strategy);
        $result = $this->service->vectorizeBatch('test');

        $this->assertSame(1, $result['vectorized']);
        $this->assertSame(0, $result['failed']);
    }

    // =========================================================================
    // vectorizeEntity — serial mode with exception
    // =========================================================================

    public function testVectorizeEntitySerialException(): void
    {
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        $strategy->method('fetchEntities')->willReturn(['entity1']);
        $strategy->method('getEntityIdentifier')->willReturn('uuid-1');
        $strategy->method('extractVectorizationItems')->willReturn([
            ['text' => 'Hello world'],
        ]);

        $this->vectorService->method('generateEmbedding')
            ->willThrowException(new Exception('API error'));

        $this->service->registerStrategy('test', $strategy);
        $result = $this->service->vectorizeBatch('test');

        $this->assertSame(0, $result['vectorized']);
        $this->assertSame(1, $result['failed']);
    }

    // =========================================================================
    // vectorizeEntity — parallel mode
    // =========================================================================

    public function testVectorizeEntityParallelMode(): void
    {
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        $strategy->method('fetchEntities')->willReturn(['entity1']);
        $strategy->method('getEntityIdentifier')->willReturn('uuid-1');
        $strategy->method('extractVectorizationItems')->willReturn([
            ['text' => 'chunk 1'],
            ['text' => 'chunk 2'],
        ]);
        $strategy->method('prepareVectorMetadata')->willReturn([
            'entity_type' => 'object',
            'entity_id' => 'uuid-1',
            'chunk_index' => 0,
            'total_chunks' => 2,
        ]);

        $this->vectorService->method('generateBatchEmbeddings')->willReturn([
            ['embedding' => [0.1], 'model' => 'test', 'dimensions' => 1],
            ['embedding' => [0.2], 'model' => 'test', 'dimensions' => 1],
        ]);

        $this->service->registerStrategy('test', $strategy);
        $result = $this->service->vectorizeBatch('test', ['mode' => 'parallel', 'batch_size' => 50]);

        $this->assertSame(2, $result['vectorized']);
        $this->assertSame(0, $result['failed']);
    }

    // =========================================================================
    // vectorizeEntity — parallel with null embedding
    // =========================================================================

    public function testVectorizeEntityParallelWithNullEmbedding(): void
    {
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        $strategy->method('fetchEntities')->willReturn(['entity1']);
        $strategy->method('getEntityIdentifier')->willReturn('uuid-1');
        $strategy->method('extractVectorizationItems')->willReturn([
            ['text' => 'chunk 1'],
            ['text' => 'chunk 2'],
        ]);

        $this->vectorService->method('generateBatchEmbeddings')->willReturn([
            ['embedding' => [0.1], 'model' => 'test', 'dimensions' => 1],
            ['embedding' => null, 'error' => 'Failed to embed'],
        ]);
        $strategy->method('prepareVectorMetadata')->willReturn([
            'entity_type' => 'object',
            'entity_id' => 'uuid-1',
        ]);

        $this->service->registerStrategy('test', $strategy);
        $result = $this->service->vectorizeBatch('test', ['mode' => 'parallel', 'batch_size' => 50]);

        $this->assertSame(1, $result['vectorized']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('Failed to embed', $result['errors'][0]['error']);
    }

    // =========================================================================
    // vectorizeEntity — parallel batch exception
    // =========================================================================

    public function testVectorizeEntityParallelBatchException(): void
    {
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        $strategy->method('fetchEntities')->willReturn(['entity1']);
        $strategy->method('getEntityIdentifier')->willReturn('uuid-1');
        $strategy->method('extractVectorizationItems')->willReturn([
            ['text' => 'chunk 1'],
            ['text' => 'chunk 2'],
        ]);

        $this->vectorService->method('generateBatchEmbeddings')
            ->willThrowException(new Exception('Batch failed'));

        $this->service->registerStrategy('test', $strategy);
        $result = $this->service->vectorizeBatch('test', ['mode' => 'parallel', 'batch_size' => 50]);

        $this->assertSame(0, $result['vectorized']);
        $this->assertSame(2, $result['failed']);
    }

    // =========================================================================
    // vectorizeEntity — empty items
    // =========================================================================

    public function testVectorizeEntityEmptyItems(): void
    {
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        $strategy->method('fetchEntities')->willReturn(['entity1']);
        $strategy->method('getEntityIdentifier')->willReturn('uuid-1');
        $strategy->method('extractVectorizationItems')->willReturn([]);

        $this->service->registerStrategy('test', $strategy);
        $result = $this->service->vectorizeBatch('test');

        $this->assertSame(0, $result['total_items']);
        $this->assertSame(0, $result['vectorized']);
    }

    // =========================================================================
    // Facade methods — delegation
    // =========================================================================

    public function testGenerateEmbeddingDelegates(): void
    {
        $expected = ['embedding' => [0.1], 'model' => 'test', 'dimensions' => 1];
        $this->vectorService->method('generateEmbedding')->willReturn($expected);

        $result = $this->service->generateEmbedding('test text');
        $this->assertSame($expected, $result);
    }

    public function testSemanticSearchDelegates(): void
    {
        $expected = [['id' => 1, 'score' => 0.9]];
        $this->vectorService->method('semanticSearch')->willReturn($expected);

        $result = $this->service->semanticSearch('query');
        $this->assertSame($expected, $result);
    }

    public function testGetVectorStatsDelegates(): void
    {
        $expected = ['total' => 100];
        $this->vectorService->method('getVectorStats')->willReturn($expected);

        $result = $this->service->getVectorStats();
        $this->assertSame($expected, $result);
    }

    public function testTestEmbeddingDelegates(): void
    {
        $expected = ['success' => true, 'message' => 'OK'];
        $this->vectorService->method('testEmbedding')->willReturn($expected);

        $result = $this->service->testEmbedding('openai', []);
        $this->assertSame($expected, $result);
    }

    public function testCheckEmbeddingModelMismatchDelegates(): void
    {
        $expected = ['has_vectors' => true, 'mismatch' => false];
        $this->vectorService->method('checkEmbeddingModelMismatch')->willReturn($expected);

        $result = $this->service->checkEmbeddingModelMismatch();
        $this->assertSame($expected, $result);
    }

    public function testClearAllEmbeddingsDelegates(): void
    {
        $expected = ['success' => true, 'message' => 'Cleared', 'deleted' => 50];
        $this->vectorService->method('clearAllEmbeddings')->willReturn($expected);

        $result = $this->service->clearAllEmbeddings();
        $this->assertSame($expected, $result);
    }

    public function testHybridSearchDelegates(): void
    {
        $expected = ['results' => []];
        $this->vectorService->method('hybridSearch')->willReturn($expected);

        $result = $this->service->hybridSearch('query');
        $this->assertSame($expected, $result);
    }
}
