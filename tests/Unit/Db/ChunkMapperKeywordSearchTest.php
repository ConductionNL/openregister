<?php

declare(strict_types=1);

/**
 * ChunkMapper keyword-search + unvectorized-queue Unit Tests
 *
 * Covers the hybrid-document-search additions to ChunkMapper:
 * searchByKeyword() (ranked ts_rank keyword arm, PostgreSQL only, tolerant
 * degradation elsewhere) and findUnvectorized() (FIFO work queue for
 * ChunkVectorizationJob).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/hybrid-document-search/tasks.md#7.2
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\ChunkMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChunkMapperKeywordSearchTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private LoggerInterface&MockObject $logger;
    private ChunkMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db     = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mapper = new ChunkMapper($this->db, $this->logger);
    }//end setUp()

    /**
     * Build a raw keyword-search row as returned by the ts_rank SQL query.
     */
    private function makeKeywordRow(int $id, string $text, float $score, int $chunkIndex=0): array
    {
        return [
            'id'           => $id,
            'source_type'  => 'file',
            'source_id'    => 100 + $id,
            'text_content' => $text,
            'chunk_index'  => $chunkIndex,
            'score'        => $score,
        ];
    }//end makeKeywordRow()

    // =========================================================================
    // searchByKeyword — PostgreSQL path
    // =========================================================================

    public function testSearchByKeywordBuildsRankedTsQueryOnPostgres(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $stmt = $this->createMock(IResult::class);
        $stmt->method('fetchAll')->willReturn(
            [
                $this->makeKeywordRow(1, 'quarterly report full of terms', 0.9),
                $this->makeKeywordRow(2, 'a quarterly mention', 0.4),
            ]
        );

        $capturedSql    = null;
        $capturedParams = null;
        $this->db->expects($this->once())
            ->method('executeQuery')
            ->willReturnCallback(
                function (string $sql, array $params=[], $types=[]) use (&$capturedSql, &$capturedParams, $stmt) {
                    $capturedSql    = $sql;
                    $capturedParams = $params;
                    return $stmt;
                }
            );

        $results = $this->mapper->searchByKeyword('quarterly report', 10);

        // Expression form — matches the functional GIN index (no tsvector column).
        $this->assertStringContainsString(
            "to_tsvector('simple', text_content) @@ plainto_tsquery('simple', :query)",
            $capturedSql
        );
        $this->assertStringContainsString(
            "ts_rank(to_tsvector('simple', text_content), plainto_tsquery('simple', :query)) AS score",
            $capturedSql
        );
        $this->assertStringContainsString('ORDER BY score DESC', $capturedSql);
        $this->assertStringContainsString('LIMIT 10', $capturedSql);
        $this->assertSame('quarterly report', $capturedParams['query']);

        // Ranked-by-ts_rank descending, shaped for reciprocalRankFusion().
        $this->assertCount(2, $results);
        $this->assertSame('file', $results[0]['entity_type']);
        $this->assertSame('101', $results[0]['entity_id']);
        $this->assertEqualsWithDelta(0.9, $results[0]['score'], 0.0001);
        $this->assertSame('quarterly report full of terms', $results[0]['chunk_text']);
        $this->assertSame(0, $results[0]['chunk_index']);
        $this->assertSame([], $results[0]['metadata']);
        $this->assertGreaterThan($results[1]['score'], $results[0]['score']);
    }//end testSearchByKeywordBuildsRankedTsQueryOnPostgres()

    public function testSearchByKeywordHonoursSourceTypeFilter(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $stmt = $this->createMock(IResult::class);
        $stmt->method('fetchAll')->willReturn([]);

        $capturedSql    = null;
        $capturedParams = null;
        $this->db->method('executeQuery')
            ->willReturnCallback(
                function (string $sql, array $params=[], $types=[]) use (&$capturedSql, &$capturedParams, $stmt) {
                    $capturedSql    = $sql;
                    $capturedParams = $params;
                    return $stmt;
                }
            );

        $this->mapper->searchByKeyword('term', 5, ['source_type' => 'file']);

        $this->assertStringContainsString('source_type = :sourceType', $capturedSql);
        $this->assertSame('file', $capturedParams['sourceType']);
    }//end testSearchByKeywordHonoursSourceTypeFilter()

    // =========================================================================
    // searchByKeyword — degradation paths
    // =========================================================================

    public function testSearchByKeywordReturnsEmptyWithWarningOnNonPostgres(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $this->db->expects($this->never())->method('executeQuery');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('keyword search unavailable'), $this->anything());

        $results = $this->mapper->searchByKeyword('quarterly report', 10);

        $this->assertSame([], $results);
    }//end testSearchByKeywordReturnsEmptyWithWarningOnNonPostgres()

    public function testSearchByKeywordReturnsEmptyWithWarningWhenQueryFails(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        // Query failure → degrade, don't throw.
        $this->db->method('executeQuery')
            ->willThrowException(new \Exception('text search configuration error'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Keyword search query failed'), $this->anything());

        $results = $this->mapper->searchByKeyword('quarterly report', 10);

        $this->assertSame([], $results);
    }//end testSearchByKeywordReturnsEmptyWithWarningWhenColumnMissing()

    // =========================================================================
    // findUnvectorized — FIFO work queue (task 5.1)
    // =========================================================================

    public function testFindUnvectorizedFiltersOnVectorizedFalseFifoOrder(): void
    {
        $stmt = $this->createMock(IResult::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->expects($this->once())
            ->method('eq')
            ->with('vectorized', $this->anything())
            ->willReturn('vectorized = false');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($stmt);
        $qb->expects($this->once())
            ->method('orderBy')
            ->with('created_at', 'ASC')
            ->willReturnSelf();
        $qb->expects($this->once())
            ->method('setMaxResults')
            ->with(50)
            ->willReturnSelf();

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $results = $this->mapper->findUnvectorized(limit: 50);

        $this->assertSame([], $results);
    }//end testFindUnvectorizedFiltersOnVectorizedFalseFifoOrder()
}//end class
