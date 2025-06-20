<?php
/**
 * SchemaMapperTest
 *
 * Tests for the SchemaMapper class in the OpenRegister application.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 * @author   Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git-id>
 * @link     https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\IDBConnection;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\OpenRegister\Service\SchemaPropertyValidatorService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use PHPUnit\Framework\TestCase;

/**
 * Class SchemaMapperTest
 *
 * @package OCA\OpenRegister\Tests\Db
 */
class SchemaMapperTest extends TestCase
{
    /**
     * Test getRegisterCountPerSchema returns an empty array when no registers exist
     *
     * @return void
     */
    public function testGetRegisterCountPerSchemaEmpty(): void
    {
        // Mock the DB connection and query builder
        $db = $this->createMock(IDBConnection::class);
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('executeQuery')->willReturnSelf();
        $qb->method('fetchAllAssociative')->willReturn([]);

        $eventDispatcher = $this->createMock(IEventDispatcher::class);
        $validator = $this->createMock(SchemaPropertyValidatorService::class);
        $objectEntityMapper = $this->createMock(ObjectEntityMapper::class);

        $mapper = new SchemaMapper($db, $eventDispatcher, $validator, $objectEntityMapper);
        $result = $mapper->getRegisterCountPerSchema();
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getRegisterCountPerSchema returns correct counts for multiple schemas
     *
     * @return void
     */
    public function testGetRegisterCountPerSchemaMultiple(): void
    {
        // Simulate DB returning two schemas with counts
        $db = $this->createMock(IDBConnection::class);
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('executeQuery')->willReturnSelf();
        $qb->method('fetchAllAssociative')->willReturn([
            ['schema_id' => '1', 'count' => '2'],
            ['schema_id' => '2', 'count' => '1'],
        ]);

        $eventDispatcher = $this->createMock(IEventDispatcher::class);
        $validator = $this->createMock(SchemaPropertyValidatorService::class);
        $objectEntityMapper = $this->createMock(ObjectEntityMapper::class);

        $mapper = new SchemaMapper($db, $eventDispatcher, $validator, $objectEntityMapper);
        $result = $mapper->getRegisterCountPerSchema();
        $this->assertIsArray($result);
        $this->assertEquals(2, $result[1]);
        $this->assertEquals(1, $result[2]);
    }

    /**
     * Test getRegisterCountPerSchema returns zero for schemas not referenced
     *
     * @return void
     */
    public function testGetRegisterCountPerSchemaZeroForUnreferenced(): void
    {
        // Simulate DB returning only one schema
        $db = $this->createMock(IDBConnection::class);
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('executeQuery')->willReturnSelf();
        $qb->method('fetchAllAssociative')->willReturn([
            ['schema_id' => '1', 'count' => '3'],
        ]);

        $eventDispatcher = $this->createMock(IEventDispatcher::class);
        $validator = $this->createMock(SchemaPropertyValidatorService::class);
        $objectEntityMapper = $this->createMock(ObjectEntityMapper::class);

        $mapper = new SchemaMapper($db, $eventDispatcher, $validator, $objectEntityMapper);
        $result = $mapper->getRegisterCountPerSchema();
        $this->assertIsArray($result);
        $this->assertEquals(3, $result[1]);
        $this->assertArrayNotHasKey(2, $result);
    }
} 