<?php

namespace Unit\Service;

use OCA\OpenRegister\Service\MetricsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MetricsServiceTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private LoggerInterface&MockObject $logger;
    private MetricsService $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MetricsService(
            $this->db,
            $this->logger
        );
    }

    private function createQueryBuilder(): IQueryBuilder&MockObject
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $func = $this->createMock(IFunctionBuilder::class);

        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($func);
        $expr->method('eq')->willReturn('eq_expr');
        $expr->method('gte')->willReturn('gte_expr');
        $expr->method('lt')->willReturn('lt_expr');
        $expr->method('isNotNull')->willReturn('notnull_expr');
        $queryFunc = $this->createMock(IQueryFunction::class);
        $func->method('count')->willReturn($queryFunc);
        $func->method('min')->willReturn($queryFunc);
        $func->method('max')->willReturn($queryFunc);

        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('createFunction')->willReturnArgument(0);

        // Fluent interface
        $qb->method('insert')->willReturnSelf();
        $qb->method('values')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('selectAlias')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('delete')->willReturnSelf();

        return $qb;
    }

    public function testRecordMetricSuccess(): void
    {
        $qb = $this->createQueryBuilder();
        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->expects($this->once())->method('executeStatement');

        $this->service->recordMetric(
            MetricsService::METRIC_FILE_PROCESSED,
            'file',
            'file-123',
            'success',
            150,
            ['key' => 'value'],
            null,
            'admin'
        );
    }

    public function testRecordMetricDbFailureDoesNotThrow(): void
    {
        $qb = $this->createQueryBuilder();
        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('executeStatement')->willThrowException(new \Exception('DB error'));

        $this->logger->expects($this->once())->method('error');

        // Should not throw
        $this->service->recordMetric(MetricsService::METRIC_FILE_PROCESSED);
    }

    public function testRecordMetricWithNullMetadata(): void
    {
        $qb = $this->createQueryBuilder();
        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->expects($this->once())->method('executeStatement');

        // null metadata should result in '{}' being stored
        $this->service->recordMetric(
            MetricsService::METRIC_SEARCH_KEYWORD,
            null,
            null,
            'success',
            null,
            null
        );
    }

    public function testGetFilesProcessedPerDay(): void
    {
        $qb = $this->createQueryBuilder();
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([
            ['date' => '2024-01-01', 'count' => '5'],
            ['date' => '2024-01-02', 'count' => '10'],
        ]);
        $result->method('closeCursor')->willReturn(true);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $data = $this->service->getFilesProcessedPerDay(7);

        $this->assertSame(5, $data['2024-01-01']);
        $this->assertSame(10, $data['2024-01-02']);
    }

    public function testGetFilesProcessedPerDayEmpty(): void
    {
        $qb = $this->createQueryBuilder();
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([]);
        $result->method('closeCursor')->willReturn(true);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $data = $this->service->getFilesProcessedPerDay();

        $this->assertSame([], $data);
    }

    public function testGetEmbeddingStats(): void
    {
        $qb = $this->createQueryBuilder();
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn([
            'total' => '100',
            'successful' => '95',
        ]);
        $result->method('closeCursor')->willReturn(true);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->service->getEmbeddingStats(30);

        $this->assertSame(100, $stats['total']);
        $this->assertSame(95, $stats['successful']);
        $this->assertSame(5, $stats['failed']);
        $this->assertSame(95.0, $stats['success_rate']);
        $this->assertSame(30, $stats['period_days']);
        $this->assertArrayHasKey('estimated_cost_usd', $stats);
    }

    public function testGetEmbeddingStatsZeroTotal(): void
    {
        $qb = $this->createQueryBuilder();
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn([
            'total' => '0',
            'successful' => '0',
        ]);
        $result->method('closeCursor')->willReturn(true);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->service->getEmbeddingStats();

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0.0, $stats['success_rate']);
        $this->assertSame(0.0, $stats['estimated_cost_usd']);
    }

    public function testGetSearchLatencyStats(): void
    {
        $qb = $this->createQueryBuilder();
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn([
            'count' => '50',
            'avg_ms' => '123.45',
            'min_ms' => '10',
            'max_ms' => '500',
        ]);
        $result->method('closeCursor')->willReturn(true);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->service->getSearchLatencyStats(7);

        $this->assertArrayHasKey('keyword', $stats);
        $this->assertArrayHasKey('semantic', $stats);
        $this->assertArrayHasKey('hybrid', $stats);
        $this->assertSame(50, $stats['keyword']['count']);
        $this->assertSame(123.45, $stats['keyword']['avg_ms']);
        $this->assertSame(10, $stats['keyword']['min_ms']);
        $this->assertSame(500, $stats['keyword']['max_ms']);
    }

    public function testGetSearchLatencyStatsNullAvg(): void
    {
        $qb = $this->createQueryBuilder();
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn([
            'count' => '0',
            'avg_ms' => null,
            'min_ms' => null,
            'max_ms' => null,
        ]);
        $result->method('closeCursor')->willReturn(true);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->service->getSearchLatencyStats();

        $this->assertSame(0.0, $stats['keyword']['avg_ms']);
    }

    public function testGetStorageGrowth(): void
    {
        $qb1 = $this->createQueryBuilder();
        $result1 = $this->createMock(IResult::class);
        $result1->method('fetchAll')->willReturn([
            ['date' => '2024-01-01', 'count' => '100'],
            ['date' => '2024-01-02', 'count' => '200'],
        ]);
        $result1->method('closeCursor')->willReturn(true);
        $qb1->method('executeQuery')->willReturn($result1);

        $qb2 = $this->createQueryBuilder();
        $result2 = $this->createMock(IResult::class);
        $result2->method('fetch')->willReturn(['total_bytes' => '1048576']);
        $result2->method('closeCursor')->willReturn(true);
        $qb2->method('executeQuery')->willReturn($result2);

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($qb1, $qb2);

        $growth = $this->service->getStorageGrowth(30);

        $this->assertSame(100, $growth['daily_vectors_added']['2024-01-01']);
        $this->assertSame(200, $growth['daily_vectors_added']['2024-01-02']);
        $this->assertSame(1048576, $growth['current_storage_bytes']);
        $this->assertSame(1.0, $growth['current_storage_mb']);
        $this->assertSame(150.0, $growth['avg_vectors_per_day']);
        $this->assertSame(30, $growth['period_days']);
    }

    public function testGetStorageGrowthEmpty(): void
    {
        $qb1 = $this->createQueryBuilder();
        $result1 = $this->createMock(IResult::class);
        $result1->method('fetchAll')->willReturn([]);
        $result1->method('closeCursor')->willReturn(true);
        $qb1->method('executeQuery')->willReturn($result1);

        $qb2 = $this->createQueryBuilder();
        $result2 = $this->createMock(IResult::class);
        $result2->method('fetch')->willReturn(['total_bytes' => '0']);
        $result2->method('closeCursor')->willReturn(true);
        $qb2->method('executeQuery')->willReturn($result2);

        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($qb1, $qb2);

        $growth = $this->service->getStorageGrowth();

        $this->assertSame([], $growth['daily_vectors_added']);
        $this->assertSame(0.0, $growth['avg_vectors_per_day']);
    }

    public function testGetDashboardMetrics(): void
    {
        // getDashboardMetrics aggregates other methods; just verify structure
        $qb = $this->createQueryBuilder();
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([]);
        $result->method('fetch')->willReturn([
            'total' => '0',
            'successful' => '0',
            'count' => '0',
            'avg_ms' => null,
            'min_ms' => null,
            'max_ms' => null,
            'total_bytes' => '0',
        ]);
        $result->method('closeCursor')->willReturn(true);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $dashboard = $this->service->getDashboardMetrics();

        $this->assertArrayHasKey('files_processed', $dashboard);
        $this->assertArrayHasKey('embedding_stats', $dashboard);
        $this->assertArrayHasKey('search_latency', $dashboard);
        $this->assertArrayHasKey('storage_growth', $dashboard);
    }

    public function testCleanOldMetricsReturnsInt(): void
    {
        $qb = $this->createQueryBuilder();
        $qb->method('executeStatement')->willReturn(42);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $deleted = $this->service->cleanOldMetrics(90);

        $this->assertSame(42, $deleted);
    }

    public function testCleanOldMetricsDefaultRetention(): void
    {
        $qb = $this->createQueryBuilder();
        $qb->method('executeStatement')->willReturn(0);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $deleted = $this->service->cleanOldMetrics();

        $this->assertSame(0, $deleted);
    }

    public function testMetricConstants(): void
    {
        $this->assertSame('file_processed', MetricsService::METRIC_FILE_PROCESSED);
        $this->assertSame('object_vectorized', MetricsService::METRIC_OBJECT_VECTORIZED);
        $this->assertSame('embedding_generated', MetricsService::METRIC_EMBEDDING_GENERATED);
        $this->assertSame('search_semantic', MetricsService::METRIC_SEARCH_SEMANTIC);
        $this->assertSame('search_hybrid', MetricsService::METRIC_SEARCH_HYBRID);
        $this->assertSame('search_keyword', MetricsService::METRIC_SEARCH_KEYWORD);
        $this->assertSame('chat_message', MetricsService::METRIC_CHAT_MESSAGE);
    }
}
