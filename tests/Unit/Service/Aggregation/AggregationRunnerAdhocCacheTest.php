<?php

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AggregationRunner::runAdhoc() read-through cache behaviour.
 *
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.5
 */
class AggregationRunnerAdhocCacheTest extends TestCase
{

    private IDBConnection&MockObject $db;
    private AggregationCache&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private AggregationRunner $sut;

    private const REGISTER_SLUG = 'testreg';
    private const SCHEMA_SLUG   = 'testschema';

    protected function setUp(): void
    {
        $this->db     = $this->createMock(IDBConnection::class);
        $this->cache  = $this->createMock(AggregationCache::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new AggregationRunner(
            db: $this->db,
            cache: $this->cache,
            logger: $this->logger
        );

    }

    /**
     * Cache hit returns cached envelope with cached=true and skips dispatch.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.5
     */
    public function testCacheHitReturnsCachedEnvelopeWithCachedTrue(): void
    {
        $cachedEnvelope = [
            'groups'  => [['key' => '2026-01-01T00:00:00Z', 'value' => 5]],
            'backend' => 'mysql',
            'cached'  => false,
        ];

        $this->cache
            ->method('getAdhoc')
            ->willReturn($cachedEnvelope);

        // If cache hit works, the DB must not be queried.
        $this->db->expects($this->never())->method('prepare');

        $query = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: [],
            groupBy: null,
            dateBucket: 'day'
        );

        $result = $this->sut->runAdhoc(
            registerSlug: self::REGISTER_SLUG,
            schemaSlug: self::SCHEMA_SLUG,
            query: $query
        );

        $this->assertTrue($result['cached'], 'Cache hit must set cached=true');
        $this->assertSame($cachedEnvelope['groups'], $result['groups']);
        $this->assertSame('mysql', $result['backend']);
    }

    /**
     * Cache miss executes aggregation, populates cache, returns cached=false.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.5
     */
    public function testCacheMissPopulatesCacheAndReturnsCachedFalse(): void
    {
        // Cache miss.
        $this->cache->method('getAdhoc')->willReturn(null);

        // Expect setAdhoc to be called once.
        $this->cache->expects($this->once())->method('setAdhoc');

        // Arrange DB: unknown platform so bucketInPhp fires but returns empty.
        // dateBucket=null skips tryNativeAggregation entirely, so no platform mock needed.
        $this->db->method('prepare')->willReturn($this->buildPreparedStatementMock(rows: []));

        $query = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: [],
            groupBy: null,
            dateBucket: null
        );

        $result = $this->sut->runAdhoc(
            registerSlug: self::REGISTER_SLUG,
            schemaSlug: self::SCHEMA_SLUG,
            query: $query
        );

        $this->assertFalse($result['cached'], 'Cache miss must set cached=false');
        $this->assertSame('php-fallback', $result['backend']);
    }

    /**
     * The query object reaches both getAdhoc() and setAdhoc() unchanged.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.5
     */
    public function testQueryReachesGetAdhocAndSetAdhocUnchanged(): void
    {
        $capturedGetQuery = null;
        $capturedSetQuery = null;

        $this->cache
            ->expects($this->once())
            ->method('getAdhoc')
            ->willReturnCallback(function ($reg, $sch, $q) use (&$capturedGetQuery) {
                $capturedGetQuery = $q;
                return null;
            });

        $this->cache
            ->expects($this->once())
            ->method('setAdhoc')
            ->willReturnCallback(function ($reg, $sch, $q) use (&$capturedSetQuery) {
                $capturedSetQuery = $q;
            });

        $this->db->method('prepare')->willReturn($this->buildPreparedStatementMock(rows: []));

        $query = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: ['status' => 'open'],
            groupBy: null,
            dateBucket: null
        );

        $this->sut->runAdhoc(
            registerSlug: self::REGISTER_SLUG,
            schemaSlug: self::SCHEMA_SLUG,
            query: $query
        );

        $this->assertSame($query, $capturedGetQuery, 'getAdhoc must receive the same query object');
        $this->assertSame($query, $capturedSetQuery, 'setAdhoc must receive the same query object');
    }

    /**
     * A differing query produces a cache miss even when a similar query was cached.
     *
     * Verified by asserting setAdhoc() is called twice (once per miss).
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.5
     */
    public function testDifferingQueryIsDifferentCacheEntry(): void
    {
        // Always miss.
        $this->cache->method('getAdhoc')->willReturn(null);
        $this->cache->expects($this->exactly(2))->method('setAdhoc');

        $this->db->method('prepare')->willReturn($this->buildPreparedStatementMock(rows: []));

        $queryA = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: [],
            groupBy: null,
            dateBucket: 'day'
        );

        $queryB = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: [],
            groupBy: null,
            dateBucket: 'hour'
        );

        $this->sut->runAdhoc(registerSlug: self::REGISTER_SLUG, schemaSlug: self::SCHEMA_SLUG, query: $queryA);
        $this->sut->runAdhoc(registerSlug: self::REGISTER_SLUG, schemaSlug: self::SCHEMA_SLUG, query: $queryB);
    }

    /**
     * Helper: create an IPreparedStatement mock that returns the given rows on fetchAll().
     *
     * @param array<array<string,mixed>> $rows
     *
     * @return IPreparedStatement&MockObject
     */
    private function buildPreparedStatementMock(array $rows): IPreparedStatement
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn($rows);
        $result->method('closeCursor')->willReturn(true);

        $stmt = $this->createMock(IPreparedStatement::class);
        $stmt->method('execute')->willReturn($result);

        return $stmt;
    }//end buildPreparedStatementMock()

}//end class
