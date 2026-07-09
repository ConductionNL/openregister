<?php

declare(strict_types=1);

/**
 * VectorStorageHandler Unit Tests
 *
 * Covers the hybrid-document-search additions: the additive pgvector
 * dual-write in storeVector() and the job-driven warm-up backfill
 * (backfillEmbeddingVectors()).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vectorization\Handlers
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/hybrid-document-search/tasks.md#2.1
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Vectorization\Handlers;

use OCA\OpenRegister\Service\Vectorization\Handlers\PgVectorPlatform;
use OCA\OpenRegister\Service\Vectorization\Handlers\VectorStorageHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VectorStorageHandlerTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private PgVectorPlatform&MockObject $pgVector;
    private LoggerInterface&MockObject $logger;
    private VectorStorageHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db       = $this->createMock(IDBConnection::class);
        $this->pgVector = $this->createMock(PgVectorPlatform::class);
        $this->logger   = $this->createMock(LoggerInterface::class);

        $this->handler = new VectorStorageHandler(
            $this->db,
            $this->pgVector,
            $this->logger
        );
    }//end setUp()

    /**
     * Wire an insert query builder returning the given last-insert id.
     */
    private function buildInsertQueryBuilder(int $insertId): IQueryBuilder&MockObject
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('insert')->willReturnSelf();
        $qb->method('values')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeStatement')->willReturn(1);
        $qb->method('getLastInsertId')->willReturn($insertId);

        return $qb;
    }//end buildInsertQueryBuilder()

    // =========================================================================
    // storeVector — dual-write (task 2.1)
    // =========================================================================

    /**
     * BLOB insert always happens; the pgvector ANN sidecar row is upserted
     * when the platform supports it and the dimension matches.
     */
    public function testStoreVectorDualWritesPgvectorSidecarWhenDimensionMatches(): void
    {
        $this->db->method('getQueryBuilder')->willReturn($this->buildInsertQueryBuilder(7));

        $this->pgVector->method('getVectorColumnDimension')->willReturn(3);
        $this->pgVector->method('formatVector')->willReturn('[0.1,0.2,0.3]');

        $capturedSql    = null;
        $capturedParams = null;
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(
                function (string $sql, array $params=[]) use (&$capturedSql, &$capturedParams): int {
                    $capturedSql    = $sql;
                    $capturedParams = $params;
                    return 1;
                }
            );

        $vectorId = $this->handler->storeVector(
            entityType: 'file',
            entityId: '42',
            embedding: [0.1, 0.2, 0.3],
            model: 'test-model',
            dimensions: 3
        );

        $this->assertSame(7, $vectorId);
        $this->assertStringContainsString('INSERT INTO openregister_vec_ann (vector_id, embedding)', $capturedSql);
        $this->assertStringContainsString('ON CONFLICT (vector_id) DO UPDATE SET embedding = EXCLUDED.embedding', $capturedSql);
        $this->assertSame(['7', '[0.1,0.2,0.3]'], $capturedParams);
    }//end testStoreVectorDualWritesPgvectorSidecarWhenDimensionMatches()

    /**
     * No sidecar write when the fast path is unavailable (non-Postgres or
     * sidecar missing) — the BLOB write is unchanged.
     */
    public function testStoreVectorSkipsPgvectorWriteWhenUnavailable(): void
    {
        $this->db->method('getQueryBuilder')->willReturn($this->buildInsertQueryBuilder(8));

        $this->pgVector->method('getVectorColumnDimension')->willReturn(null);

        $this->db->expects($this->never())->method('executeStatement');

        $vectorId = $this->handler->storeVector(
            entityType: 'file',
            entityId: '42',
            embedding: [0.1, 0.2, 0.3],
            model: 'test-model',
            dimensions: 3
        );

        $this->assertSame(8, $vectorId);
    }//end testStoreVectorSkipsPgvectorWriteWhenUnavailable()

    /**
     * Dimension-mismatched embeddings stay BLOB-only (decision 2): no sidecar
     * write is attempted.
     */
    public function testStoreVectorSkipsPgvectorWriteOnDimensionMismatch(): void
    {
        $this->db->method('getQueryBuilder')->willReturn($this->buildInsertQueryBuilder(9));

        $this->pgVector->method('getVectorColumnDimension')->willReturn(1536);

        $this->db->expects($this->never())->method('executeStatement');

        $vectorId = $this->handler->storeVector(
            entityType: 'file',
            entityId: '42',
            embedding: [0.1, 0.2, 0.3],
            model: 'test-model',
            dimensions: 3
        );

        $this->assertSame(9, $vectorId);
    }//end testStoreVectorSkipsPgvectorWriteOnDimensionMismatch()

    /**
     * A failing sidecar upsert is logged and tolerated — storeVector still
     * returns the inserted id (BLOB is the storage of record).
     */
    public function testStoreVectorToleratesPgvectorWriteFailure(): void
    {
        $this->db->method('getQueryBuilder')->willReturn($this->buildInsertQueryBuilder(10));

        $this->pgVector->method('getVectorColumnDimension')->willReturn(3);
        $this->pgVector->method('formatVector')->willReturn('[0.1,0.2,0.3]');

        $this->db->method('executeStatement')
            ->willThrowException(new \Exception('vector cast failed'));

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $vectorId = $this->handler->storeVector(
            entityType: 'file',
            entityId: '42',
            embedding: [0.1, 0.2, 0.3],
            model: 'test-model',
            dimensions: 3
        );

        $this->assertSame(10, $vectorId);
    }//end testStoreVectorToleratesPgvectorWriteFailure()

    // =========================================================================
    // backfillEmbeddingVectors — job-only warm-up (task 2.2)
    // =========================================================================

    /**
     * No-op when the fast path is unavailable.
     */
    public function testBackfillNoOpWhenPgvectorUnavailable(): void
    {
        $this->pgVector->method('getVectorColumnDimension')->willReturn(null);
        $this->db->expects($this->never())->method('executeQuery');

        $result = $this->handler->backfillEmbeddingVectors(batchSize: 100, afterId: 0);

        $this->assertSame(
            ['converted' => 0, 'failed' => 0, 'last_id' => 0, 'remaining' => 0],
            $result
        );
    }//end testBackfillNoOpWhenPgvectorUnavailable()

    /**
     * Selects sidecar-less, dimension-matched rows after the cursor, converts
     * each deserialisable BLOB, skips unparseable rows without aborting, and
     * reports the remaining count.
     */
    public function testBackfillConvertsBatchAndSkipsUnparseableRows(): void
    {
        $this->pgVector->method('getVectorColumnDimension')->willReturn(2);
        $this->pgVector->method('formatVector')->willReturnCallback(
            static fn(array $embedding): string => '['.implode(',', $embedding).']'
        );

        $selectResult = $this->createMock(IResult::class);
        $selectResult->method('fetchAll')->willReturn(
            [
                ['id' => 11, 'embedding' => serialize([0.5, 0.5])],
                ['id' => 12, 'embedding' => 'not-a-serialized-array'],
                ['id' => 13, 'embedding' => serialize([1.0, 0.0])],
            ]
        );

        $remainingResult = $this->createMock(IResult::class);
        $remainingResult->method('fetchOne')->willReturn(4);

        $capturedSelectSql = null;
        $capturedParams    = null;
        $this->db->method('executeQuery')
            ->willReturnCallback(
                function (string $sql, array $params=[]) use (&$capturedSelectSql, &$capturedParams, $selectResult, $remainingResult) {
                    if (str_contains($sql, 'SELECT v.id, v.embedding') === true) {
                        $capturedSelectSql = $sql;
                        $capturedParams    = $params;
                        return $selectResult;
                    }

                    return $remainingResult;
                }
            );

        $upsertedIds = [];
        $this->db->method('executeStatement')
            ->willReturnCallback(
                function (string $sql, array $params=[]) use (&$upsertedIds): int {
                    $upsertedIds[] = (int) $params[0];
                    return 1;
                }
            );

        $result = $this->handler->backfillEmbeddingVectors(batchSize: 100, afterId: 5);

        // Sidecar equivalent of "embedding_vector IS NULL": no ANN row yet.
        $this->assertStringContainsString('LEFT JOIN openregister_vec_ann a ON a.vector_id = v.id', $capturedSelectSql);
        $this->assertStringContainsString('a.vector_id IS NULL', $capturedSelectSql);
        $this->assertStringContainsString('v.embedding_dimensions = ?', $capturedSelectSql);
        $this->assertStringContainsString('v.id > ?', $capturedSelectSql);
        $this->assertStringContainsString('ORDER BY v.id ASC', $capturedSelectSql);
        $this->assertSame(['2', '5'], $capturedParams);

        $this->assertSame(2, $result['converted']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(13, $result['last_id']);
        $this->assertSame(4, $result['remaining']);
        $this->assertSame([11, 13], $upsertedIds);
    }//end testBackfillConvertsBatchAndSkipsUnparseableRows()

    /**
     * PostgreSQL returns BLOB columns as stream resources (live-verified on
     * PG16): the backfill MUST normalise resources to strings before
     * unserialize() — a raw resource is a TypeError, not an Exception.
     */
    public function testBackfillNormalisesResourceBlobsBeforeUnserialize(): void
    {
        $this->pgVector->method('getVectorColumnDimension')->willReturn(2);
        $this->pgVector->method('formatVector')->willReturn('[0.5,0.5]');

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, serialize([0.5, 0.5]));
        rewind($stream);

        $selectResult = $this->createMock(IResult::class);
        $selectResult->method('fetchAll')->willReturn(
            [
                ['id' => 21, 'embedding' => $stream],
            ]
        );

        $remainingResult = $this->createMock(IResult::class);
        $remainingResult->method('fetchOne')->willReturn(0);

        $this->db->method('executeQuery')
            ->willReturnOnConsecutiveCalls($selectResult, $remainingResult);

        $upserted = 0;
        $this->db->method('executeStatement')
            ->willReturnCallback(
                function () use (&$upserted): int {
                    $upserted++;
                    return 1;
                }
            );

        $result = $this->handler->backfillEmbeddingVectors(batchSize: 100, afterId: 0);

        $this->assertSame(1, $result['converted']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $upserted);
    }//end testBackfillNormalisesResourceBlobsBeforeUnserialize()

    /**
     * Idempotence: rows already converted are excluded by the selection
     * (no sidecar row), so an empty batch converts nothing.
     */
    public function testBackfillEmptyBatchReturnsZeroCounts(): void
    {
        $this->pgVector->method('getVectorColumnDimension')->willReturn(2);

        $selectResult = $this->createMock(IResult::class);
        $selectResult->method('fetchAll')->willReturn([]);

        $remainingResult = $this->createMock(IResult::class);
        $remainingResult->method('fetchOne')->willReturn(0);

        $this->db->method('executeQuery')
            ->willReturnOnConsecutiveCalls($selectResult, $remainingResult);
        $this->db->expects($this->never())->method('executeStatement');

        $result = $this->handler->backfillEmbeddingVectors(batchSize: 100, afterId: 0);

        $this->assertSame(0, $result['converted']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['last_id']);
        $this->assertSame(0, $result['remaining']);
    }//end testBackfillEmptyBatchReturnsZeroCounts()
}//end class
