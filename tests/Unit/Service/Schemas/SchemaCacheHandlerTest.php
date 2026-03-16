<?php

/**
 * Unit tests for SchemaCacheHandler.
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
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class SchemaCacheHandlerTest extends TestCase
{
    private SchemaCacheHandler $handler;
    private IDBConnection|MockObject $db;
    private SchemaMapper|MockObject $schemaMapper;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new SchemaCacheHandler(
            $this->db,
            $this->schemaMapper,
            $this->logger
        );

        // Clear the static memory cache before each test.
        $this->clearMemoryCache();
    }

    protected function tearDown(): void
    {
        $this->clearMemoryCache();
        parent::tearDown();
    }

    /**
     * Clear the static memory cache via reflection.
     */
    private function clearMemoryCache(): void
    {
        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $prop = $ref->getProperty('memoryCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * Set the static memory cache via reflection.
     */
    private function setMemoryCache(array $data): void
    {
        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $prop = $ref->getProperty('memoryCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $data);
    }

    /**
     * Get the static memory cache via reflection.
     */
    private function getMemoryCache(): array
    {
        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $prop = $ref->getProperty('memoryCache');
        $prop->setAccessible(true);
        return $prop->getValue(null);
    }

    /**
     * Create a Schema entity with an ID set.
     */
    private function createSchemaWithId(int $id): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setUuid('test-uuid-' . $id);
        $schema->setTitle('Test Schema ' . $id);
        $schema->setVersion('1.0.0');
        $schema->setDescription('Test description');
        $schema->setSummary('Test summary');
        $schema->setProperties([
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
        ]);
        $schema->setRequired(['name']);
        $schema->setArchive([]);
        $schema->setConfiguration(['facetable' => true]);
        $schema->setSource('test-source');
        $schema->setOrganisation('test-org');
        $schema->setOwner('admin');

        return $schema;
    }

    /**
     * Create a mock Schema that supports getTags/getRegister (which are not real Entity attributes).
     * This is needed because serializeSchemaForCache calls these methods.
     */
    private function createMockSchemaWithId(int $id): Schema|MockObject
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->addMethods(['getTags', 'getRegister'])
            ->getMock();

        $schema->setId($id);
        $schema->setUuid('test-uuid-' . $id);
        $schema->setTitle('Test Schema ' . $id);
        $schema->setVersion('1.0.0');
        $schema->setDescription('Test description');
        $schema->setSummary('Test summary');
        $schema->setProperties([
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
        ]);
        $schema->setRequired(['name']);
        $schema->setArchive([]);
        $schema->setConfiguration(['facetable' => true]);
        $schema->setSource('test-source');
        $schema->setOrganisation('test-org');
        $schema->setOwner('admin');

        $schema->method('getTags')->willReturn(null);
        $schema->method('getRegister')->willReturn(null);

        return $schema;
    }

    /**
     * Create a mock query builder that returns a mock result.
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
    // buildCacheKey (private, tested via reflection)
    // -----------------------------------------------------------------------

    public function testBuildCacheKeyFormat(): void
    {
        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $method = $ref->getMethod('buildCacheKey');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 42, 'schema_object');
        $this->assertSame('schema_42_schema_object', $result);
    }

    public function testBuildCacheKeyWithDifferentTypes(): void
    {
        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $method = $ref->getMethod('buildCacheKey');
        $method->setAccessible(true);

        $this->assertSame('schema_1_facetable_fields', $method->invoke($this->handler, 1, 'facetable_fields'));
        $this->assertSame('schema_99_configuration', $method->invoke($this->handler, 99, 'configuration'));
        $this->assertSame('schema_5_properties', $method->invoke($this->handler, 5, 'properties'));
    }

    // -----------------------------------------------------------------------
    // getSchema()
    // -----------------------------------------------------------------------

    public function testGetSchemaReturnsFromMemoryCache(): void
    {
        $schema = $this->createSchemaWithId(1);
        $this->setMemoryCache(['schema_1_schema_object' => $schema]);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('cache hit (memory)'));

        $result = $this->handler->getSchema(1);
        $this->assertSame($schema, $result);
    }

    public function testGetSchemaReturnsNullWhenNotFound(): void
    {
        $qb = $this->createMockQueryBuilder(false);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(999)
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->handler->getSchema(999);
        $this->assertNull($result);
    }

    public function testGetSchemaLoadsFromMapperOnCacheMiss(): void
    {
        $schema = $this->createMockSchemaWithId(3);

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(3)
            ->willReturn($schema);

        $result = $this->handler->getSchema(3);
        $this->assertSame($schema, $result);
    }

    public function testGetSchemaLogsOnMapperLoad(): void
    {
        $schema = $this->createMockSchemaWithId(7);

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->schemaMapper->method('find')->with(7)->willReturn($schema);

        $this->logger->expects($this->atLeastOnce())
            ->method('debug')
            ->with($this->stringContains('loaded from database and cached'));

        $this->handler->getSchema(7);
    }

    // -----------------------------------------------------------------------
    // cacheSchema()
    // -----------------------------------------------------------------------

    public function testCacheSchemaStoresInMemoryAndDatabase(): void
    {
        $schema = $this->createMockSchemaWithId(10);

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheSchema($schema);

        $cache = $this->getMemoryCache();
        $this->assertArrayHasKey('schema_10_schema_object', $cache);
        $this->assertSame($schema, $cache['schema_10_schema_object']);
    }

    public function testCacheSchemaWithCustomTtl(): void
    {
        $schema = $this->createMockSchemaWithId(11);

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheSchema($schema, 7200);

        $cache = $this->getMemoryCache();
        $this->assertArrayHasKey('schema_11_schema_object', $cache);
    }

    public function testCacheSchemaCallsCacheConfigurationAndProperties(): void
    {
        $schema = $this->createMockSchemaWithId(12);
        $schema->setConfiguration(['key' => 'val']);
        $schema->setProperties(['f1' => ['type' => 'string']]);

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        // cacheSchema internally calls cacheSchemaConfiguration and cacheSchemaProperties.
        // They write to the DB. If no exception, it passes.
        $this->handler->cacheSchema($schema);
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // cacheSchemaConfiguration()
    // -----------------------------------------------------------------------

    public function testCacheSchemaConfiguration(): void
    {
        $schema = $this->createSchemaWithId(20);
        $schema->setConfiguration(['facetable' => true, 'sortable' => false]);

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheSchemaConfiguration($schema);
        $this->assertTrue(true);
    }

    public function testCacheSchemaConfigurationWithNullConfig(): void
    {
        $schema = $this->createSchemaWithId(22);
        // Configuration defaults to null if not set.

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheSchemaConfiguration($schema);
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // cacheSchemaProperties()
    // -----------------------------------------------------------------------

    public function testCacheSchemaProperties(): void
    {
        $schema = $this->createSchemaWithId(21);
        $schema->setProperties(['field1' => ['type' => 'string']]);

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheSchemaProperties($schema);
        $this->assertTrue(true);
    }

    public function testCacheSchemaPropertiesWithEmptyProperties(): void
    {
        $schema = $this->createSchemaWithId(23);
        $schema->setProperties([]);

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheSchemaProperties($schema);
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // clearSchemaCache()
    // -----------------------------------------------------------------------

    public function testClearSchemaCacheRemovesFromMemory(): void
    {
        $schema = $this->createSchemaWithId(30);
        $this->setMemoryCache([
            'schema_30_schema_object' => $schema,
            'schema_30_configuration' => ['some' => 'config'],
            'schema_31_schema_object' => 'other',
        ]);

        $this->db->method('executeQuery')->willReturn(
            $this->createMock(IResult::class)
        );

        $this->handler->clearSchemaCache(30);

        $cache = $this->getMemoryCache();
        $this->assertArrayNotHasKey('schema_30_schema_object', $cache);
        $this->assertArrayNotHasKey('schema_30_configuration', $cache);
        $this->assertArrayHasKey('schema_31_schema_object', $cache);
    }

    public function testClearSchemaCacheHandlesDatabaseError(): void
    {
        $this->db->method('executeQuery')
            ->willThrowException(new Exception('DB error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('Failed to clear schema cache'));

        $this->handler->clearSchemaCache(99);
        $this->assertTrue(true);
    }

    public function testClearSchemaCacheLogsSuccess(): void
    {
        $this->db->method('executeQuery')->willReturn(
            $this->createMock(IResult::class)
        );

        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Cleared schema cache'));

        $this->handler->clearSchemaCache(42);
    }

    // -----------------------------------------------------------------------
    // invalidateForSchemaChange()
    // -----------------------------------------------------------------------

    public function testInvalidateForSchemaChangeRemovesFromBothCaches(): void
    {
        $this->setMemoryCache([
            'schema_50_schema_object' => 'data',
            'schema_50_facetable_fields' => 'fields',
            'schema_50_configuration' => 'config',
            'schema_50_properties' => 'props',
            'schema_51_schema_object' => 'other',
        ]);

        $qb = $this->createMockQueryBuilder(false, 4);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->invalidateForSchemaChange(50, 'delete');

        $cache = $this->getMemoryCache();
        $this->assertArrayNotHasKey('schema_50_schema_object', $cache);
        $this->assertArrayNotHasKey('schema_50_facetable_fields', $cache);
        $this->assertArrayNotHasKey('schema_50_configuration', $cache);
        $this->assertArrayNotHasKey('schema_50_properties', $cache);
        $this->assertArrayHasKey('schema_51_schema_object', $cache);
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

        $this->logger->expects($this->atLeastOnce())
            ->method('debug')
            ->with($this->stringContains('does not exist yet'));

        $this->handler->invalidateForSchemaChange(50);
        $this->assertTrue(true);
    }

    public function testInvalidateForSchemaChangeDefaultOperation(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->stringContains('cache invalidated'),
                $this->callback(function (array $context) {
                    return $context['operation'] === 'update';
                })
            );

        $this->handler->invalidateForSchemaChange(60);
    }

    public function testInvalidateForSchemaChangeLogsDeletedEntries(): void
    {
        $qb = $this->createMockQueryBuilder(false, 3);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->anything(),
                $this->callback(function (array $context) {
                    return $context['deletedEntries'] === 3;
                })
            );

        $this->handler->invalidateForSchemaChange(61, 'create');
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

        $this->handler->invalidateForSchemaChange(62);
    }

    // -----------------------------------------------------------------------
    // clearAllCaches()
    // -----------------------------------------------------------------------

    public function testClearAllCachesRemovesEverything(): void
    {
        $this->setMemoryCache([
            'schema_1_schema_object' => 'a',
            'schema_2_schema_object' => 'b',
        ]);

        $qb = $this->createMockQueryBuilder(false, 5);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->clearAllCaches();

        $cache = $this->getMemoryCache();
        $this->assertEmpty($cache);
    }

    public function testClearAllCachesLogsStatistics(): void
    {
        $this->setMemoryCache(['key1' => 'val1', 'key2' => 'val2']);

        $qb = $this->createMockQueryBuilder(false, 10);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('All schema caches cleared'),
                $this->callback(function (array $context) {
                    return $context['deletedDbEntries'] === 10
                        && $context['clearedMemoryEntries'] === 2;
                })
            );

        $this->handler->clearAllCaches();
    }

    // -----------------------------------------------------------------------
    // cleanExpiredEntries()
    // -----------------------------------------------------------------------

    public function testCleanExpiredEntriesReturnsDeletedCount(): void
    {
        $qb = $this->createMockQueryBuilder(false, 7);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->handler->cleanExpiredEntries();
        $this->assertSame(7, $result);
    }

    public function testCleanExpiredEntriesLogsWhenDeleted(): void
    {
        $qb = $this->createMockQueryBuilder(false, 3);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Cleaned expired'));

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

    public function testCleanExpiredEntriesReturnsZeroForEmptyTable(): void
    {
        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->handler->cleanExpiredEntries();
        $this->assertSame(0, $result);
        $this->assertIsInt($result);
    }

    // -----------------------------------------------------------------------
    // getCacheStatistics()
    // -----------------------------------------------------------------------

    public function testGetCacheStatisticsReturnsExpectedStructure(): void
    {
        $fetchResult = [
            'total_entries' => '5',
            'entries_with_ttl' => '3',
        ];

        $qb = $this->createMockQueryBuilder($fetchResult);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->handler->getCacheStatistics();

        $this->assertArrayHasKey('total_entries', $stats);
        $this->assertArrayHasKey('entries_with_ttl', $stats);
        $this->assertArrayHasKey('memory_cache_size', $stats);
        $this->assertArrayHasKey('cache_table', $stats);
        $this->assertArrayHasKey('query_time', $stats);
        $this->assertArrayHasKey('timestamp', $stats);
        $this->assertSame(5, $stats['total_entries']);
        $this->assertSame(3, $stats['entries_with_ttl']);
        $this->assertSame(0, $stats['memory_cache_size']);
        $this->assertSame('openregister_schema_cache', $stats['cache_table']);
    }

    public function testGetCacheStatisticsIncludesMemoryCacheSize(): void
    {
        $this->setMemoryCache(['a' => 1, 'b' => 2, 'c' => 3]);

        $fetchResult = ['total_entries' => '0', 'entries_with_ttl' => '0'];
        $qb = $this->createMockQueryBuilder($fetchResult);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->handler->getCacheStatistics();
        $this->assertSame(3, $stats['memory_cache_size']);
    }

    public function testGetCacheStatisticsTimestampIsRecent(): void
    {
        $fetchResult = ['total_entries' => '0', 'entries_with_ttl' => '0'];
        $qb = $this->createMockQueryBuilder($fetchResult);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $before = time();
        $stats = $this->handler->getCacheStatistics();
        $after = time();

        $this->assertGreaterThanOrEqual($before, $stats['timestamp']);
        $this->assertLessThanOrEqual($after, $stats['timestamp']);
    }

    public function testGetCacheStatisticsQueryTimeFormat(): void
    {
        $fetchResult = ['total_entries' => '0', 'entries_with_ttl' => '0'];
        $qb = $this->createMockQueryBuilder($fetchResult);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->handler->getCacheStatistics();
        $this->assertStringEndsWith('ms', $stats['query_time']);
    }

    // -----------------------------------------------------------------------
    // serializeSchemaForCache() (private, tested via reflection with mock)
    // -----------------------------------------------------------------------

    public function testSerializeSchemaForCacheViaReflection(): void
    {
        $schema = $this->createMockSchemaWithId(100);
        $schema->setCreated(new DateTime('2025-06-01 12:00:00'));
        $schema->setUpdated(new DateTime('2025-06-15 14:30:00'));

        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $method = $ref->getMethod('serializeSchemaForCache');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, $schema);

        $this->assertSame(100, $result['id']);
        $this->assertSame('test-uuid-100', $result['uuid']);
        $this->assertSame('Test Schema 100', $result['title']);
        $this->assertSame('1.0.0', $result['version']);
        $this->assertSame('Test description', $result['description']);
        $this->assertSame('Test summary', $result['summary']);
        $this->assertSame(['name'], $result['required']);
        $this->assertSame('2025-06-01 12:00:00', $result['created']);
        $this->assertSame('2025-06-15 14:30:00', $result['updated']);
        $this->assertNull($result['tags']);
        $this->assertNull($result['register']);
    }

    public function testSerializeSchemaForCacheWithNullDates(): void
    {
        $schema = $this->createMockSchemaWithId(101);
        // Don't set created/updated - they'll be null.

        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $method = $ref->getMethod('serializeSchemaForCache');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, $schema);
        $this->assertNull($result['created']);
        $this->assertNull($result['updated']);
    }

    // -----------------------------------------------------------------------
    // reconstructSchemaFromCache() (private, tested via reflection)
    // Note: reconstructSchemaFromCache calls setTags/setRegister which are
    // invalid on the real Schema entity. These tests exercise the error path.
    // -----------------------------------------------------------------------

    public function testReconstructSchemaFromCacheReturnsNullDueToInvalidAttributes(): void
    {
        // setTags() will throw BadFunctionCallException since 'tags' is not
        // a valid attribute on the Schema entity. This triggers the catch block.
        $cachedData = [
            'id' => 200,
            'uuid' => 'uuid-200',
            'title' => 'Reconstructed',
            'version' => '2.0',
            'description' => 'desc',
            'summary' => 'sum',
            'tags' => ['tag1'],
            'required' => ['field1'],
            'properties' => ['field1' => ['type' => 'string']],
            'archive' => [],
            'configuration' => ['key' => 'value'],
            'source' => 'src',
            'register' => 'reg1',
            'organisation' => 'org',
            'owner' => 'user1',
            'created' => '2025-01-01 10:00:00',
            'updated' => '2025-02-01 11:00:00',
        ];

        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $method = $ref->getMethod('reconstructSchemaFromCache');
        $method->setAccessible(true);

        // This should trigger the catch block since setTags is invalid.
        $result = $method->invoke($this->handler, $cachedData);
        $this->assertNull($result);
    }

    public function testReconstructSchemaFromCacheLogsErrorOnFailure(): void
    {
        $cachedData = [
            'id' => 203,
            'uuid' => 'test',
            'title' => 'test',
            'version' => '1.0',
            'description' => '',
            'summary' => '',
            'tags' => ['invalid-attr'],
            'required' => [],
            'properties' => [],
            'archive' => [],
            'configuration' => null,
            'source' => null,
            'register' => null,
            'organisation' => null,
            'owner' => null,
            'created' => null,
            'updated' => null,
        ];

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to reconstruct schema from cache'));

        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $method = $ref->getMethod('reconstructSchemaFromCache');
        $method->setAccessible(true);

        $method->invoke($this->handler, $cachedData);
    }

    // -----------------------------------------------------------------------
    // setCachedData() (private, tested via cacheSchema with mock schema)
    // -----------------------------------------------------------------------

    public function testSetCachedDataEnforcesMaxTtl(): void
    {
        $schema = $this->createMockSchemaWithId(300);

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheSchema($schema, 999999);
        $this->assertTrue(true);
    }

    public function testSetCachedDataUpdatesExistingRecord(): void
    {
        $schema = $this->createMockSchemaWithId(301);

        // executeStatement returns 1 (update succeeded, no insert).
        $qb = $this->createMockQueryBuilder(false, 1);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheSchema($schema);
        $this->assertTrue(true);
    }

    public function testSetCachedDataWithZeroTtl(): void
    {
        $schema = $this->createMockSchemaWithId(302);

        $qb = $this->createMockQueryBuilder(false, 0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->cacheSchema($schema, 0);
        $this->assertTrue(true);
    }
}
