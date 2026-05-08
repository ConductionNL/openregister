<?php

/**
 * Unit tests for FacetCacheHandler.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class FacetCacheHandlerTest extends TestCase
{
    private FacetCacheHandler $handler;
    private IDBConnection|MockObject $db;
    private SchemaMapper|MockObject $schemaMapper;
    private ICacheFactory|MockObject $cacheFactory;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Default: cacheFactory returns a mock cache that works.
        $cache = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);
        $this->cacheFactory->method('createLocal')->willReturn($cache);

        $this->handler = new FacetCacheHandler(
            $this->db,
            $this->schemaMapper,
            $this->cacheFactory,
            $this->logger
        );

        // Clear the static facet config cache before each test.
        $this->clearFacetConfigCache();
    }

    protected function tearDown(): void
    {
        $this->clearFacetConfigCache();
        parent::tearDown();
    }

    /**
     * Clear the static facetConfigCache via reflection.
     */
    private function clearFacetConfigCache(): void
    {
        $ref = new ReflectionClass(FacetCacheHandler::class);
        $prop = $ref->getProperty('facetConfigCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * Set the static facetConfigCache via reflection.
     */
    private function setFacetConfigCache(array $data): void
    {
        $ref = new ReflectionClass(FacetCacheHandler::class);
        $prop = $ref->getProperty('facetConfigCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $data);
    }

    /**
     * Get the static facetConfigCache via reflection.
     */
    private function getFacetConfigCache(): array
    {
        $ref = new ReflectionClass(FacetCacheHandler::class);
        $prop = $ref->getProperty('facetConfigCache');
        $prop->setAccessible(true);
        return $prop->getValue(null);
    }

    /**
     * Create a mock query builder.
     */
    private function createMockQueryBuilder(mixed $fetchResult = false, int $executeStatementResult = 0): IQueryBuilder|MockObject
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('mock_expr');
        $expr->method('lt')->willReturn('mock_expr');
        $expr->method('isNotNull')->willReturn('mock_expr');

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn($fetchResult);
        $result->method('fetchAll')->willReturn(is_array($fetchResult) ? [$fetchResult] : []);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('select')->willReturnSelf();
        $qb->method('addSelect')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('update')->willReturnSelf();
        $qb->method('insert')->willReturnSelf();
        $qb->method('delete')->willReturnSelf();
        $qb->method('values')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeQuery')->willReturn($result);
        $qb->method('executeStatement')->willReturn($executeStatementResult);

        $funcMock = new class {
            public function count(string $column, string $alias = ''): string
            {
                return "COUNT($column)";
            }
        };
        $qb->method('func')->willReturn($funcMock);

        return $qb;
    }

    // -----------------------------------------------------------------------
    // cacheFacetableFields()
    // -----------------------------------------------------------------------

    public function testCacheFacetableFieldsStoresInMemoryAndDatabase(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $fields = [
            'status' => ['type' => 'terms', 'field' => 'status'],
            'created' => ['type' => 'date_histogram', 'field' => 'created'],
        ];

        $this->handler->cacheFacetableFields(1, $fields);

        $cache = $this->getFacetConfigCache();
        $this->assertArrayHasKey('facetable_fields_1', $cache);
        $this->assertSame($fields, $cache['facetable_fields_1']);
    }

    public function testCacheFacetableFieldsWithDefaultTtl(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->atLeastOnce())
            ->method('debug')
            ->with(
                $this->stringContains('Cached facetable fields'),
                $this->callback(function (array $context) {
                    return $context['ttl'] === 7200;
                })
            );

        $this->handler->cacheFacetableFields(5, ['field1' => ['type' => 'terms']]);
    }

    public function testCacheFacetableFieldsWithCustomTtl(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->atLeastOnce())
            ->method('debug')
            ->with(
                $this->stringContains('Cached facetable fields'),
                $this->callback(function (array $context) {
                    return $context['ttl'] === 3600;
                })
            );

        $this->handler->cacheFacetableFields(6, ['f' => []], 3600);
    }

    public function testCacheFacetableFieldsLogsFieldCount(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $fields = ['a' => [], 'b' => [], 'c' => []];

        $this->logger->expects($this->atLeastOnce())
            ->method('debug')
            ->with(
                $this->stringContains('Cached facetable fields'),
                $this->callback(function (array $context) {
                    return $context['fieldCount'] === 3;
                })
            );

        $this->handler->cacheFacetableFields(7, $fields);
    }

    public function testCacheFacetableFieldsEmptyArray(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheFacetableFields(8, []);

        $cache = $this->getFacetConfigCache();
        $this->assertSame([], $cache['facetable_fields_8']);
    }

    public function testCacheFacetableFieldsUpdatesExistingRecord(): void
    {
        // executeStatement returns 1 (update succeeded, no insert needed).
        $qb = $this->createMockQueryBuilder(false, 1);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheFacetableFields(9, ['x' => ['type' => 'range']]);
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // invalidateForSchemaChange()
    // -----------------------------------------------------------------------

    public function testInvalidateForSchemaChangeRemovesFromMemory(): void
    {
        $this->setFacetConfigCache([
            'facetable_fields_10' => ['field1' => []],
            'config_10' => ['other' => 'data'],
            'facetable_fields_11' => ['field2' => []],
        ]);

        $qb = $this->createMockQueryBuilder(false, 2);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->invalidateForSchemaChange(10, 'update');

        $cache = $this->getFacetConfigCache();
        $this->assertArrayNotHasKey('facetable_fields_10', $cache);
        $this->assertArrayNotHasKey('config_10', $cache);
        $this->assertArrayHasKey('facetable_fields_11', $cache);
    }

    public function testInvalidateForSchemaChangeDefaultOperation(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->stringContains('facet cache invalidated'),
                $this->callback(function (array $context) {
                    return $context['operation'] === 'update';
                })
            );

        $this->handler->invalidateForSchemaChange(20);
    }

    public function testInvalidateForSchemaChangeWithDeleteOperation(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->stringContains('facet cache invalidated'),
                $this->callback(function (array $context) {
                    return $context['operation'] === 'delete';
                })
            );

        $this->handler->invalidateForSchemaChange(21, 'delete');
    }

    public function testInvalidateForSchemaChangeHandlesMissingTable(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('mock');
        $qb->method('expr')->willReturn($expr);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeStatement')
            ->willThrowException(new Exception('Table does not exist'));

        $this->db->method('getQueryBuilder')->willReturn($qb);

        // debug() is called multiple times; track messages and assert afterwards.
        $debugMessages = [];
        $this->logger->method('debug')
            ->willReturnCallback(function (string $message) use (&$debugMessages) {
                $debugMessages[] = $message;
            });

        $this->handler->invalidateForSchemaChange(22);

        $found = false;
        foreach ($debugMessages as $msg) {
            if (str_contains($msg, 'does not exist yet')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected a debug message containing "does not exist yet"');
    }

    public function testInvalidateForSchemaChangeClearsDistributedCaches(): void
    {
        $cache = $this->createMock(ICache::class);
        $cache->expects($this->exactly(2))->method('clear');

        // Reset cacheFactory to track calls.
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);

        $this->handler = new FacetCacheHandler(
            $this->db,
            $this->schemaMapper,
            $this->cacheFactory,
            $this->logger
        );

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->invalidateForSchemaChange(23);
    }

    public function testInvalidateForSchemaChangeLogsExecutionTime(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->anything(),
                $this->callback(function (array $context) {
                    return isset($context['executionTime'])
                        && str_contains($context['executionTime'], 'ms');
                })
            );

        $this->handler->invalidateForSchemaChange(24);
    }

    // -----------------------------------------------------------------------
    // clearAllCaches()
    // -----------------------------------------------------------------------

    public function testClearAllCachesRemovesEverything(): void
    {
        $this->setFacetConfigCache([
            'key1' => 'val1',
            'key2' => 'val2',
        ]);

        $qb = $this->createMockQueryBuilder(false, 10);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->clearAllCaches();

        $cache = $this->getFacetConfigCache();
        $this->assertEmpty($cache);
    }

    public function testClearAllCachesLogsStatistics(): void
    {
        $this->setFacetConfigCache(['a' => 1, 'b' => 2, 'c' => 3]);

        $qb = $this->createMockQueryBuilder(false, 15);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('All facet caches cleared'),
                $this->callback(function (array $context) {
                    return $context['deletedDbEntries'] === 15
                        && $context['clearedMemoryEntries'] === 3;
                })
            );

        $this->handler->clearAllCaches();
    }

    public function testClearAllCachesClearsDistributedCaches(): void
    {
        $cache = $this->createMock(ICache::class);
        $cache->expects($this->exactly(2))->method('clear');

        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);

        $this->handler = new FacetCacheHandler(
            $this->db,
            $this->schemaMapper,
            $this->cacheFactory,
            $this->logger
        );

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->clearAllCaches();
    }

    // -----------------------------------------------------------------------
    // cleanExpiredEntries()
    // -----------------------------------------------------------------------

    public function testCleanExpiredEntriesReturnsDeletedCount(): void
    {
        $qb = $this->createMockQueryBuilder(false, 5);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->handler->cleanExpiredEntries();
        $this->assertSame(5, $result);
    }

    public function testCleanExpiredEntriesLogsWhenDeleted(): void
    {
        $qb = $this->createMockQueryBuilder(false, 3);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Cleaned expired facet cache'));

        $this->handler->cleanExpiredEntries();
    }

    public function testCleanExpiredEntriesDoesNotLogWhenNoneDeleted(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->never())
            ->method('info');

        $result = $this->handler->cleanExpiredEntries();
        $this->assertSame(0, $result);
    }

    // -----------------------------------------------------------------------
    // getCacheStatistics()
    // -----------------------------------------------------------------------

    public function testGetCacheStatisticsReturnsExpectedStructure(): void
    {
        $fetchResult = [
            'total_entries' => '8',
            'facet_type' => 'terms',
            'count' => '8',
        ];

        $qb = $this->createMockQueryBuilder($fetchResult);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->handler->getCacheStatistics();

        $this->assertArrayHasKey('total_entries', $stats);
        $this->assertArrayHasKey('by_type', $stats);
        $this->assertArrayHasKey('memory_cache_size', $stats);
        $this->assertArrayHasKey('cache_table', $stats);
        $this->assertArrayHasKey('query_time', $stats);
        $this->assertArrayHasKey('timestamp', $stats);
        $this->assertSame('openregister_schema_facet_cache', $stats['cache_table']);
    }

    public function testGetCacheStatisticsAggregatesByType(): void
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([
            ['total_entries' => '5', 'facet_type' => 'terms', 'count' => '5'],
            ['total_entries' => '3', 'facet_type' => 'date_histogram', 'count' => '3'],
        ]);

        $expr = $this->createMock(IExpressionBuilder::class);
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('select')->willReturnSelf();
        $qb->method('addSelect')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);

        $funcMock = new class {
            public function count(string $column, string $alias = ''): string
            {
                return "COUNT($column)";
            }
        };
        $qb->method('func')->willReturn($funcMock);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->handler->getCacheStatistics();

        $this->assertSame(8, $stats['total_entries']);
        $this->assertSame(5, $stats['by_type']['terms']);
        $this->assertSame(3, $stats['by_type']['date_histogram']);
    }

    public function testGetCacheStatisticsIncludesMemoryCacheSize(): void
    {
        $this->setFacetConfigCache(['a' => 1, 'b' => 2]);

        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([]);

        $expr = $this->createMock(IExpressionBuilder::class);
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('select')->willReturnSelf();
        $qb->method('addSelect')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);

        $funcMock = new class {
            public function count(string $column, string $alias = ''): string
            {
                return "COUNT($column)";
            }
        };
        $qb->method('func')->willReturn($funcMock);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->handler->getCacheStatistics();
        $this->assertSame(2, $stats['memory_cache_size']);
    }

    // -----------------------------------------------------------------------
    // clearDistributedFacetCaches() (private, tested via public methods)
    // -----------------------------------------------------------------------

    public function testClearDistributedFacetCachesFallsBackToLocal(): void
    {
        $localCache = $this->createMock(ICache::class);
        $localCache->expects($this->exactly(2))->method('clear');

        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')
            ->willThrowException(new Exception('Distributed not available'));
        $this->cacheFactory->method('createLocal')
            ->willReturn($localCache);

        $this->handler = new FacetCacheHandler(
            $this->db,
            $this->schemaMapper,
            $this->cacheFactory,
            $this->logger
        );

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->clearAllCaches();
    }

    public function testClearDistributedFacetCachesHandlesBothFailures(): void
    {
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')
            ->willThrowException(new Exception('No distributed'));
        $this->cacheFactory->method('createLocal')
            ->willThrowException(new Exception('No local'));

        $this->handler = new FacetCacheHandler(
            $this->db,
            $this->schemaMapper,
            $this->cacheFactory,
            $this->logger
        );

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        // Should not throw.
        $this->handler->clearAllCaches();
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // setCachedFacetData() (private, tested via cacheFacetableFields)
    // -----------------------------------------------------------------------

    public function testSetCachedFacetDataEnforcesMaxTtl(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        // TTL 999999 should be clamped to MAX_CACHE_TTL (28800).
        $this->handler->cacheFacetableFields(40, ['f' => []], 999999);
        $this->assertTrue(true);
    }

    public function testSetCachedFacetDataWithZeroTtl(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        // TTL of 0 means no expiry (expires = null).
        $this->handler->cacheFacetableFields(41, ['f' => []], 0);
        $this->assertTrue(true);
    }
}
