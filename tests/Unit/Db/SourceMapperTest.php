<?php

declare(strict_types=1);

/**
 * SourceMapperTest
 *
 * Unit tests for the SourceMapper class to verify source database operations.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Source Mapper Test Suite
 *
 * Unit tests for source database operations focusing on
 * class structure and basic functionality.
 *
 * @coversDefaultClass SourceMapper
 */
class SourceMapperTest extends TestCase
{
    private SourceMapper $sourceMapper;
    private IDBConnection|MockObject $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->sourceMapper = new SourceMapper($this->db);
    }

    /**
     * Test constructor
     *
     * @covers ::__construct
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(SourceMapper::class, $this->sourceMapper);
    }

    /**
     * Test Source entity creation
     *
     * @return void
     */
    public function testSourceEntityCreation(): void
    {
        $source = new Source();
        $source->setId(1);
        $source->setUuid('test-uuid-123');
        $source->setTitle('Test Source');
        $source->setDescription('Test Description');
        $source->setDatabaseUrl('https://example.com');
        $source->setCreated(new \DateTime('2024-01-01 00:00:00'));
        $source->setUpdated(new \DateTime('2024-01-02 00:00:00'));

        $this->assertEquals(1, $source->getId());
        $this->assertEquals('test-uuid-123', $source->getUuid());
        $this->assertEquals('Test Source', $source->getTitle());
        $this->assertEquals('Test Description', $source->getDescription());
        $this->assertEquals('https://example.com', $source->getDatabaseUrl());
    }

    /**
     * Test Source entity JSON serialization
     *
     * @return void
     */
    public function testSourceJsonSerialization(): void
    {
        $source = new Source();
        $source->setId(1);
        $source->setUuid('test-uuid-123');
        $source->setTitle('Test Source');
        $source->setDescription('Test Description');
        $source->setDatabaseUrl('https://example.com');

        $json = json_encode($source);
        $this->assertIsString($json);
        $this->assertStringContainsString('test-uuid-123', $json);
        $this->assertStringContainsString('Test Source', $json);
    }

    /**
     * Test Source entity string representation
     *
     * @return void
     */
    public function testSourceToString(): void
    {
        $source = new Source();
        $source->setUuid('test-uuid-123');
        
        $this->assertEquals('test-uuid-123', (string)$source);
    }

    /**
     * Test Source entity string representation with ID fallback
     *
     * @return void
     */
    public function testSourceToStringWithId(): void
    {
        $source = new Source();
        $source->setId(123);
        
        $this->assertEquals('Source #123', (string)$source);
    }

    /**
     * Test Source entity string representation fallback
     *
     * @return void
     */
    public function testSourceToStringFallback(): void
    {
        $source = new Source();
        
        $this->assertEquals('Source', (string)$source);
    }

    /**
     * Test find method with valid ID
     *
     * @return void
     */
    public function testFindWithValidId(): void
    {
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->with('openregister_sources')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturnSelf();

        $expressionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expressionBuilder->expects($this->once())
            ->method('eq')
            ->willReturn('expr_eq');

        $queryBuilder->expects($this->once())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $queryBuilder->expects($this->once())
            ->method('createNamedParameter')
            ->willReturn(':param');

        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => 1,
                    'uuid' => 'test-uuid-123',
                    'title' => 'Test Source',
                    'description' => 'Test Description',
                    'database_url' => 'https://example.com',
                    'created' => '2024-01-01 00:00:00',
                    'updated' => '2024-01-02 00:00:00'
                ],
                false
            );

        $result = $this->sourceMapper->find(1);
        $this->assertInstanceOf(Source::class, $result);
        $this->assertEquals(1, $result->getId());
        $this->assertEquals('test-uuid-123', $result->getUuid());
    }

    /**
     * Test find method with non-existent ID
     *
     * @return void
     */
    public function testFindWithNonExistentId(): void
    {
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturnSelf();

        $expressionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expressionBuilder->expects($this->once())
            ->method('eq')
            ->willReturn('expr_eq');

        $queryBuilder->expects($this->once())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $queryBuilder->expects($this->once())
            ->method('createNamedParameter')
            ->willReturn(':param');

        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $this->expectException(DoesNotExistException::class);
        $this->sourceMapper->find(999);
    }

    /**
     * Test findAll method
     *
     * @return void
     */
    public function testFindAll(): void
    {
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('setFirstResult')
            ->willReturnSelf();

        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => 1,
                    'uuid' => 'test-uuid-123',
                    'title' => 'Test Source 1',
                    'description' => 'Test Description 1',
                    'database_url' => 'https://example1.com',
                    'created' => '2024-01-01 00:00:00',
                    'updated' => '2024-01-02 00:00:00'
                ],
                [
                    'id' => 2,
                    'uuid' => 'test-uuid-456',
                    'title' => 'Test Source 2',
                    'description' => 'Test Description 2',
                    'database_url' => 'https://example2.com',
                    'created' => '2024-01-03 00:00:00',
                    'updated' => '2024-01-04 00:00:00'
                ],
                false
            );

        $result = $this->sourceMapper->findAll();
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(Source::class, $result[0]);
        $this->assertInstanceOf(Source::class, $result[1]);
    }

    /**
     * Test createFromArray method
     *
     * @return void
     */
    public function testCreateFromArray(): void
    {
        $data = [
            'title' => 'Test Source',
            'description' => 'Test Description',
            'databaseUrl' => 'https://example.com'
        ];

        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('insert')
            ->willReturnSelf();

        $queryBuilder->expects($this->atLeast(1))
            ->method('setValue')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $queryBuilder->expects($this->once())
            ->method('getLastInsertId')
            ->willReturn(1);

        $result = $this->sourceMapper->createFromArray($data);
        $this->assertInstanceOf(Source::class, $result);
    }

    /**
     * Test updateFromArray method
     *
     * @return void
     */
    public function testUpdateFromArray(): void
    {
        $data = [
            'title' => 'Updated Source',
            'description' => 'Updated Description',
            'databaseUrl' => 'https://updated.com'
        ];

        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->atLeast(2))
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        // Mock for find() method
        $queryBuilder->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $queryBuilder->expects($this->atLeast(1))
            ->method('where')
            ->willReturnSelf();

        $queryBuilder->expects($this->any())
            ->method('createNamedParameter')
            ->willReturn('?');

        // Mock expr() method
        $expressionBuilder = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $queryBuilder->expects($this->any())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $expressionBuilder->expects($this->any())
            ->method('eq')
            ->willReturn('id = ?');

        // Mock findEntity result
        $existingSource = new Source();
        $existingSource->setId(1);
        $existingSource->setTitle('Original Title');
        $existingSource->setDescription('Original Description');
        $existingSource->setDatabaseUrl('https://original.com');
        $existingSource->setVersion('1.0.0');

        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'title' => 'Original Title',
                'description' => 'Original Description',
                'databaseUrl' => 'https://original.com',
                'version' => '1.0.0'
            ], false);

        // Mock for update() method
        $queryBuilder->expects($this->once())
            ->method('update')
            ->willReturnSelf();

        $queryBuilder->expects($this->atLeast(1))
            ->method('set')
            ->willReturnSelf();

        $queryBuilder->expects($this->atLeast(1))
            ->method('where')
            ->willReturnSelf();


        $queryBuilder->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $result = $this->sourceMapper->updateFromArray(1, $data);
        $this->assertInstanceOf(Source::class, $result);
    }

}//end class
