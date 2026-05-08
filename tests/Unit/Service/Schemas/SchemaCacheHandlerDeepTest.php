<?php

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

class SchemaCacheHandlerDeepTest extends TestCase
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

        // Clear static memory cache
        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $prop = $ref->getProperty('memoryCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    public function testGetSchemaNotFoundInDbReturnsNull(): void
    {
        // Mock getCachedData returning null (no db cache)
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $result = $this->createMock(IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('');
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetch')->willReturn(false);

        // SchemaMapper throws DoesNotExistException
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $schema = $this->handler->getSchema(9999);

        $this->assertNull($schema);
    }

    public function testClearSchemaCacheWithDbException(): void
    {
        $this->db->method('executeQuery')
            ->willThrowException(new Exception('db error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        // Should not throw
        $this->handler->clearSchemaCache(1);

        // Check that memory cache is cleared by setting it first
        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $prop = $ref->getProperty('memoryCache');
        $prop->setAccessible(true);
        $cache = $prop->getValue();
        $this->assertArrayNotHasKey('schema_1_schema_object', $cache);
    }

    public function testClearAllCaches(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('delete')->willReturnSelf();
        $qb->method('executeStatement')->willReturn(5);

        $this->logger->expects($this->once())
            ->method('info');

        $this->handler->clearAllCaches();

        $ref = new ReflectionClass(SchemaCacheHandler::class);
        $prop = $ref->getProperty('memoryCache');
        $prop->setAccessible(true);
        $this->assertEmpty($prop->getValue());
    }

    public function testCleanExpiredEntries(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('isNotNull')->willReturn('');
        $expr->method('lt')->willReturn('');
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeStatement')->willReturn(3);

        $deleted = $this->handler->cleanExpiredEntries();

        $this->assertEquals(3, $deleted);
    }

    public function testInvalidateForSchemaChangeWithDbException(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('');
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeStatement')->willThrowException(new Exception('table not exist'));

        $this->logger->expects($this->atLeastOnce())
            ->method('debug');

        // Should not throw
        $this->handler->invalidateForSchemaChange(42, 'delete');
    }

    public function testGetCacheStatistics(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
        $queryFunc = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
        $result = $this->createMock(IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('addSelect')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('func')->willReturn($func);
        $func->method('count')->willReturn($queryFunc);
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetch')->willReturn([
            'total_entries' => '10',
            'entries_with_ttl' => '8',
        ]);

        $stats = $this->handler->getCacheStatistics();

        $this->assertEquals(10, $stats['total_entries']);
        $this->assertEquals(8, $stats['entries_with_ttl']);
        $this->assertArrayHasKey('memory_cache_size', $stats);
        $this->assertArrayHasKey('query_time', $stats);
    }
}
