<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Branch coverage tests for SchemaCacheHandler — targets uncovered branches in
 * getSchema, clearSchemaCache, cacheSchema, invalidateForSchemaChange,
 * clearAllCaches, cleanExpiredEntries, getCacheStatistics.
 */
class SchemaCacheHandlerBranchCoverageTest extends TestCase
{
    private SchemaCacheHandler $handler;
    private IDBConnection&MockObject $db;
    private SchemaMapper&MockObject $schemaMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Clear static memory cache
        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $prop = $ref->getProperty('memoryCache');
        $prop->setValue(null, []);

        $this->handler = new SchemaCacheHandler(
            $this->db,
            $this->schemaMapper,
            $this->logger
        );
    }

    private function createMockQueryBuilder(): IQueryBuilder&MockObject
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('lt')->willReturn('1<2');
        $expr->method('isNotNull')->willReturn('1 IS NOT NULL');
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('select')->willReturnSelf();
        $qb->method('addSelect')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('delete')->willReturnSelf();
        $qb->method('update')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('insert')->willReturnSelf();
        $qb->method('values')->willReturnSelf();
        return $qb;
    }

    /**
     * Create a real Schema object with fields populated via __call setters.
     */
    private function createTestSchema(int $id = 1): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setUuid('uuid-' . $id);
        $schema->setTitle('Test Schema ' . $id);
        $schema->setVersion('1.0');
        $schema->setDescription('A test schema');
        $schema->setSummary('Summary');
        $schema->setProperties(['name' => ['type' => 'string']]);
        $schema->setConfiguration([]);
        $schema->setSource(null);
        $schema->setOrganisation(null);
        $schema->setOwner(null);
        return $schema;
    }

    // =========================================================================
    // getSchema — memory cache hit
    // =========================================================================

    public function testGetSchemaMemoryCacheHit(): void
    {
        $schema = $this->createTestSchema(1);

        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $prop = $ref->getProperty('memoryCache');
        $prop->setValue(null, ['schema_1_schema_object' => $schema]);

        $result = $this->handler->getSchema(1);
        $this->assertSame($schema, $result);
    }

    // =========================================================================
    // getSchema — not found returns null
    // =========================================================================

    public function testGetSchemaNotFoundReturnsNull(): void
    {
        $qb = $this->createMockQueryBuilder();
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturn(false);
        $qb->method('executeQuery')->willReturn($result);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $loaded = $this->handler->getSchema(999);
        $this->assertNull($loaded);
    }

    // =========================================================================
    // clearSchemaCache
    // =========================================================================

    public function testClearSchemaCache(): void
    {
        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $prop = $ref->getProperty('memoryCache');
        $prop->setValue(null, [
            'schema_1_schema_object' => 'cached',
            'schema_1_facetable_fields' => [],
            'schema_2_schema_object' => 'other',
        ]);

        $this->db->method('executeQuery')
            ->willReturn($this->createMock(\OCP\DB\IResult::class));

        $this->handler->clearSchemaCache(1);

        $cache = $prop->getValue(null);
        $this->assertArrayNotHasKey('schema_1_schema_object', $cache);
        $this->assertArrayNotHasKey('schema_1_facetable_fields', $cache);
        $this->assertArrayHasKey('schema_2_schema_object', $cache);
    }

    public function testClearSchemaCacheDbException(): void
    {
        $this->db->method('executeQuery')
            ->willThrowException(new \Exception('DB error'));

        $this->handler->clearSchemaCache(1);
        $this->assertTrue(true);
    }

    // =========================================================================
    // invalidateForSchemaChange
    // =========================================================================

    public function testInvalidateForSchemaChange(): void
    {
        $qb = $this->createMockQueryBuilder();
        $qb->method('executeStatement')->willReturn(3);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->invalidateForSchemaChange(1, 'update');
        $this->assertTrue(true);
    }

    public function testInvalidateForSchemaChangeDbException(): void
    {
        $qb = $this->createMockQueryBuilder();
        $qb->method('executeStatement')
            ->willThrowException(new \Exception('Table not found'));
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->invalidateForSchemaChange(1, 'delete');
        $this->assertTrue(true);
    }

    // =========================================================================
    // clearAllCaches
    // =========================================================================

    public function testClearAllCaches(): void
    {
        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $prop = $ref->getProperty('memoryCache');
        $prop->setValue(null, ['some_key' => 'value']);

        $qb = $this->createMockQueryBuilder();
        $qb->method('executeStatement')->willReturn(5);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->clearAllCaches();

        $cache = $prop->getValue(null);
        $this->assertEmpty($cache);
    }

    // =========================================================================
    // cleanExpiredEntries
    // =========================================================================

    public function testCleanExpiredEntriesRemovesSome(): void
    {
        $qb = $this->createMockQueryBuilder();
        $qb->method('executeStatement')->willReturn(3);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->handler->cleanExpiredEntries();
        $this->assertSame(3, $result);
    }

    public function testCleanExpiredEntriesNone(): void
    {
        $qb = $this->createMockQueryBuilder();
        $qb->method('executeStatement')->willReturn(0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->handler->cleanExpiredEntries();
        $this->assertSame(0, $result);
    }

    // =========================================================================
    // getCacheStatistics
    // =========================================================================

    public function testGetCacheStatistics(): void
    {
        $qb = $this->createMockQueryBuilder();
        $qb->method('func')->willReturn(
            new class {
                public function count($col, $alias = null)
                {
                    return $alias ?? $col;
                }
            }
        );
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturn([
            'total_entries' => '10',
            'entries_with_ttl' => '8',
        ]);
        $qb->method('executeQuery')->willReturn($result);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->handler->getCacheStatistics();
        $this->assertSame(10, $stats['total_entries']);
        $this->assertSame(8, $stats['entries_with_ttl']);
        $this->assertArrayHasKey('memory_cache_size', $stats);
        $this->assertArrayHasKey('query_time', $stats);
    }

    // =========================================================================
    // cacheSchemaConfiguration + cacheSchemaProperties
    // =========================================================================

    public function testCacheSchemaConfigurationAndProperties(): void
    {
        $qb = $this->createMockQueryBuilder();
        $qb->method('executeStatement')->willReturn(0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $schema = $this->createTestSchema(1);

        $this->handler->cacheSchemaConfiguration($schema);
        $this->handler->cacheSchemaProperties($schema);
        $this->assertTrue(true);
    }
}
