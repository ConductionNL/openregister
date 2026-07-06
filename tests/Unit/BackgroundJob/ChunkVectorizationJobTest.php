<?php

declare(strict_types=1);

/**
 * ChunkVectorizationJob Unit Tests
 *
 * Tests the recurring background job that auto-vectorizes extracted chunks
 * (vectorized = false → embedding → openregister_vectors → vectorized = true)
 * and drives the pgvector warm-up backfill (job-only warm-up, DECIDED
 * 2026-07-06).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/hybrid-document-search/tasks.md#7.4
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ChunkVectorizationJob;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Service\Vectorization\Handlers\VectorStorageHandler;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Test class for ChunkVectorizationJob
 */
class ChunkVectorizationJobTest extends TestCase
{
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;
    private IAppConfig&MockObject $appConfig;
    private ChunkMapper&MockObject $chunkMapper;
    private VectorEmbeddings&MockObject $embeddings;
    private VectorStorageHandler&MockObject $storageHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timeFactory    = $this->createMock(ITimeFactory::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
        $this->appConfig      = $this->createMock(IAppConfig::class);
        $this->chunkMapper    = $this->createMock(ChunkMapper::class);
        $this->embeddings     = $this->createMock(VectorEmbeddings::class);
        $this->storageHandler = $this->createMock(VectorStorageHandler::class);
    }

    /**
     * Create the job instance and register mocks in \OC::$server.
     */
    private function makeJob(): ChunkVectorizationJob
    {
        \OC::$server->registerService(LoggerInterface::class, function () {
            return $this->logger;
        });
        \OC::$server->registerService(IAppConfig::class, function () {
            return $this->appConfig;
        });
        \OC::$server->registerService(ChunkMapper::class, function () {
            return $this->chunkMapper;
        });
        \OC::$server->registerService(VectorEmbeddings::class, function () {
            return $this->embeddings;
        });
        \OC::$server->registerService(VectorStorageHandler::class, function () {
            return $this->storageHandler;
        });

        return new ChunkVectorizationJob($this->timeFactory);
    }

    /**
     * Invoke the protected run() method via reflection.
     */
    private function runJob(ChunkVectorizationJob $job, mixed $argument = []): void
    {
        $ref    = new ReflectionClass($job);
        $method = $ref->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($job, $argument);
    }

    /**
     * Build a Chunk entity for testing.
     */
    private function makeChunk(int $id, string $text, int $sourceId = 42, int $chunkIndex = 0): Chunk
    {
        $chunk = new Chunk();
        $chunk->setId($id);
        $chunk->setSourceType('file');
        $chunk->setSourceId($sourceId);
        $chunk->setTextContent($text);
        $chunk->setChunkIndex($chunkIndex);
        $chunk->setVectorized(false);

        return $chunk;
    }

    /**
     * Default no-op backfill result.
     */
    private function noBackfill(): array
    {
        return ['converted' => 0, 'failed' => 0, 'last_id' => 0, 'remaining' => 0];
    }

    /**
     * Successful batch: every chunk is embedded, stored, and marked vectorized.
     */
    public function testRunVectorizesBatchAndMarksChunksVectorized(): void
    {
        $chunkA = $this->makeChunk(1, 'first chunk text', 42, 0);
        $chunkB = $this->makeChunk(2, 'second chunk text', 42, 1);

        $this->chunkMapper->method('findUnvectorized')->willReturn([$chunkA, $chunkB]);
        $this->chunkMapper->method('findBySource')->willReturn([$chunkA, $chunkB]);

        $this->embeddings->expects($this->once())
            ->method('generateBatchEmbeddings')
            ->with(['first chunk text', 'second chunk text'])
            ->willReturn(
                [
                    ['embedding' => [0.1, 0.2], 'model' => 'test-model', 'dimensions' => 2],
                    ['embedding' => [0.3, 0.4], 'model' => 'test-model', 'dimensions' => 2],
                ]
            );

        $storedEntityIds = [];
        $this->storageHandler->expects($this->exactly(2))
            ->method('storeVector')
            ->willReturnCallback(
                function (string $entityType, string $entityId) use (&$storedEntityIds): int {
                    $this->assertSame('file', $entityType);
                    $storedEntityIds[] = $entityId;
                    return count($storedEntityIds);
                }
            );

        $updatedChunks = [];
        $this->chunkMapper->expects($this->exactly(2))
            ->method('update')
            ->willReturnCallback(
                function (Chunk $chunk) use (&$updatedChunks): Chunk {
                    $updatedChunks[] = $chunk->getId();
                    $this->assertTrue($chunk->getVectorized());
                    return $chunk;
                }
            );

        $this->storageHandler->method('backfillEmbeddingVectors')->willReturn($this->noBackfill());
        $this->appConfig->method('getValueString')->willReturn('0');

        $this->runJob($this->makeJob());

        $this->assertSame(['42', '42'], $storedEntityIds);
        $this->assertSame([1, 2], $updatedChunks);
    }

    /**
     * A single chunk's embedding failure must not abort the batch: the other
     * chunks are stored and marked vectorized; the failed chunk stays
     * vectorized = false for retry on the next run.
     */
    public function testSingleEmbeddingFailureDoesNotAbortBatch(): void
    {
        $chunkA = $this->makeChunk(1, 'ok text', 42, 0);
        $chunkB = $this->makeChunk(2, 'failing text', 42, 1);
        $chunkC = $this->makeChunk(3, 'also ok', 42, 2);

        $this->chunkMapper->method('findUnvectorized')->willReturn([$chunkA, $chunkB, $chunkC]);
        $this->chunkMapper->method('findBySource')->willReturn([$chunkA, $chunkB, $chunkC]);

        $this->embeddings->method('generateBatchEmbeddings')->willReturn(
            [
                ['embedding' => [0.1, 0.2], 'model' => 'test-model', 'dimensions' => 2],
                ['embedding' => null, 'model' => 'test-model', 'dimensions' => 0, 'error' => 'provider timeout'],
                ['embedding' => [0.5, 0.6], 'model' => 'test-model', 'dimensions' => 2],
            ]
        );

        $this->storageHandler->expects($this->exactly(2))->method('storeVector')->willReturn(1);

        $updatedChunks = [];
        $this->chunkMapper->method('update')
            ->willReturnCallback(
                function (Chunk $chunk) use (&$updatedChunks): Chunk {
                    $updatedChunks[] = $chunk->getId();
                    return $chunk;
                }
            );

        $this->storageHandler->method('backfillEmbeddingVectors')->willReturn($this->noBackfill());
        $this->appConfig->method('getValueString')->willReturn('0');

        $this->runJob($this->makeJob());

        // Chunks 1 and 3 marked vectorized; chunk 2 left for retry.
        $this->assertSame([1, 3], $updatedChunks);
        $this->assertFalse($chunkB->getVectorized());
        $this->assertTrue($chunkA->getVectorized());
        $this->assertTrue($chunkC->getVectorized());
    }

    /**
     * A storage failure for one chunk is tolerated the same way: logged,
     * skipped, batch continues.
     */
    public function testSingleStorageFailureDoesNotAbortBatch(): void
    {
        $chunkA = $this->makeChunk(1, 'stores fine', 42, 0);
        $chunkB = $this->makeChunk(2, 'storage explodes', 42, 1);

        $this->chunkMapper->method('findUnvectorized')->willReturn([$chunkA, $chunkB]);
        $this->chunkMapper->method('findBySource')->willReturn([$chunkA, $chunkB]);

        $this->embeddings->method('generateBatchEmbeddings')->willReturn(
            [
                ['embedding' => [0.1, 0.2], 'model' => 'test-model', 'dimensions' => 2],
                ['embedding' => [0.3, 0.4], 'model' => 'test-model', 'dimensions' => 2],
            ]
        );

        $calls = 0;
        $this->storageHandler->method('storeVector')
            ->willReturnCallback(
                function () use (&$calls): int {
                    $calls++;
                    if ($calls === 2) {
                        throw new \Exception('db gone');
                    }

                    return $calls;
                }
            );

        $updatedChunks = [];
        $this->chunkMapper->method('update')
            ->willReturnCallback(
                function (Chunk $chunk) use (&$updatedChunks): Chunk {
                    $updatedChunks[] = $chunk->getId();
                    return $chunk;
                }
            );

        $this->storageHandler->method('backfillEmbeddingVectors')->willReturn($this->noBackfill());
        $this->appConfig->method('getValueString')->willReturn('0');

        $this->runJob($this->makeJob());

        $this->assertSame([1], $updatedChunks);
        $this->assertFalse($chunkB->getVectorized());
    }

    /**
     * No unvectorized chunks: no embedding calls, but the warm-up backfill
     * still runs (job-only warm-up).
     */
    public function testRunWithNoChunksStillRunsWarmupBackfill(): void
    {
        $this->chunkMapper->method('findUnvectorized')->willReturn([]);
        $this->embeddings->expects($this->never())->method('generateBatchEmbeddings');

        $this->appConfig->method('getValueString')->willReturn('0');
        $this->storageHandler->expects($this->once())
            ->method('backfillEmbeddingVectors')
            ->with(100, 0)
            ->willReturn(['converted' => 40, 'failed' => 0, 'last_id' => 140, 'remaining' => 60]);

        $cursorWrites = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(
                function (string $app, string $key, string $value) use (&$cursorWrites): bool {
                    $cursorWrites[$key] = $value;
                    return true;
                }
            );

        $this->runJob($this->makeJob());

        // Progressing sweep with rows remaining → cursor advances to last_id.
        $this->assertSame('140', $cursorWrites['chunk_vectorization_backfill_cursor']);
        $this->assertSame('60', $cursorWrites['chunk_vectorization_backfill_remaining']);
    }

    /**
     * A completed sweep (remaining = 0) resets the cursor to 0 so future
     * NULL rows (e.g. after a dimension change) get swept again.
     */
    public function testWarmupBackfillCursorResetsWhenSweepCompletes(): void
    {
        $this->chunkMapper->method('findUnvectorized')->willReturn([]);

        $this->appConfig->method('getValueString')->willReturn('500');
        $this->storageHandler->method('backfillEmbeddingVectors')
            ->with(100, 500)
            ->willReturn(['converted' => 3, 'failed' => 0, 'last_id' => 512, 'remaining' => 0]);

        $cursorWrites = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(
                function (string $app, string $key, string $value) use (&$cursorWrites): bool {
                    $cursorWrites[$key] = $value;
                    return true;
                }
            );

        $this->runJob($this->makeJob());

        $this->assertSame('0', $cursorWrites['chunk_vectorization_backfill_cursor']);
    }

    /**
     * A provider-level batch failure (generateBatchEmbeddings throws) is
     * logged and swallowed; the warm-up backfill still runs and no chunk is
     * marked vectorized.
     */
    public function testProviderFailureIsToleratedAndBackfillStillRuns(): void
    {
        $chunk = $this->makeChunk(1, 'text', 42, 0);

        $this->chunkMapper->method('findUnvectorized')->willReturn([$chunk]);
        $this->embeddings->method('generateBatchEmbeddings')
            ->willThrowException(new \Exception('no embedding provider configured'));

        $this->chunkMapper->expects($this->never())->method('update');
        $this->storageHandler->expects($this->once())
            ->method('backfillEmbeddingVectors')
            ->willReturn($this->noBackfill());

        $this->appConfig->method('getValueString')->willReturn('0');

        $this->runJob($this->makeJob());

        $this->assertFalse($chunk->getVectorized());
    }
}
