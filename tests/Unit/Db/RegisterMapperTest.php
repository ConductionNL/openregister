<?php

declare(strict_types=1);

/**
 * RegisterMapperTest
 *
 * Unit tests for the RegisterMapper class to verify register database operations.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Register Mapper Test Suite
 *
 * Unit tests for register database operations focusing on
 * class structure and basic functionality.
 *
 * @coversDefaultClass RegisterMapper
 */
class RegisterMapperTest extends TestCase
{
    private RegisterMapper $registerMapper;
    private IDBConnection|MockObject $db;
    private SchemaMapper|MockObject $schemaMapper;
    private IEventDispatcher|MockObject $eventDispatcher;
    private ObjectEntityMapper|MockObject $objectEntityMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        
        $this->registerMapper = new RegisterMapper(
            $this->db,
            $this->schemaMapper,
            $this->eventDispatcher,
            $this->objectEntityMapper
        );
    }

    /**
     * Test constructor
     *
     * @covers ::__construct
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(RegisterMapper::class, $this->registerMapper);
    }

    /**
     * Test Register entity creation
     *
     * @return void
     */
    public function testRegisterEntityCreation(): void
    {
        $register = new Register();
        $register->setId(1);
        $register->setUuid('test-uuid-123');
        $register->setTitle('Test Register');
        $register->setDescription('Test Description');
        $register->setSlug('test-register');
        $register->setCreated(new \DateTime('2024-01-01 00:00:00'));
        $register->setUpdated(new \DateTime('2024-01-02 00:00:00'));

        $this->assertEquals('test-uuid-123', $register->getId());
        $this->assertEquals('test-uuid-123', $register->getUuid());
        $this->assertEquals('Test Register', $register->getTitle());
        $this->assertEquals('Test Description', $register->getDescription());
        $this->assertEquals('test-register', $register->getSlug());
    }

    /**
     * Test Register entity JSON serialization
     *
     * @return void
     */
    public function testRegisterJsonSerialization(): void
    {
        $register = new Register();
        $register->setId(1);
        $register->setUuid('test-uuid-123');
        $register->setTitle('Test Register');
        $register->setDescription('Test Description');
        $register->setSlug('test-register');

        $json = json_encode($register);
        $this->assertIsString($json);
        $this->assertStringContainsString('test-uuid-123', $json);
        $this->assertStringContainsString('Test Register', $json);
    }

    /**
     * Test Register entity string representation
     *
     * @return void
     */
    public function testRegisterToString(): void
    {
        $register = new Register();
        $register->setUuid('test-uuid-123');
        
        $this->assertEquals('Register #unknown', (string)$register);
    }

    /**
     * Test Register entity string representation with ID fallback
     *
     * @return void
     */
    public function testRegisterToStringWithId(): void
    {
        $register = new Register();
        $register->setId(123);
        
        $this->assertEquals('Register #123', (string)$register);
    }

    /**
     * Test Register entity string representation fallback
     *
     * @return void
     */
    public function testRegisterToStringFallback(): void
    {
        $register = new Register();
        
        $this->assertEquals('Register #unknown', (string)$register);
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
            ->with('openregister_registers')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturnSelf();

        // Mock expression builder
        $expressionBuilder = $this->createMock(IExpressionBuilder::class);
        $compositeExpression = $this->createMock(ICompositeExpression::class);
        $expressionBuilder->method('orX')->willReturn($compositeExpression);
        $expressionBuilder->method('eq')->willReturn('expr_eq');
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':param');

        $result = $this->createMock(IResult::class);
        $result->expects($this->any())
            ->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'uuid' => 'test-uuid-123',
                'title' => 'Test Register',
                'description' => 'Test Description',
                'slug' => 'test-register',
                'created' => '2024-01-01 00:00:00',
                'updated' => '2024-01-02 00:00:00'
            ], false);

        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $result = $this->registerMapper->find(1);
        $this->assertInstanceOf(Register::class, $result);
        $this->assertEquals('test-uuid-123', $result->getId());
        $this->assertEquals('test-uuid-123', $result->getUuid());
    }

    /**
     * Test find method with UUID
     *
     * @return void
     */
    public function testFindWithUuid(): void
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

        // Mock expression builder
        $expressionBuilder = $this->createMock(IExpressionBuilder::class);
        $compositeExpression = $this->createMock(ICompositeExpression::class);
        $expressionBuilder->method('orX')->willReturn($compositeExpression);
        $expressionBuilder->method('eq')->willReturn('expr_eq');
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':param');

        $result = $this->createMock(IResult::class);
        $result->expects($this->any())
            ->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'uuid' => 'test-uuid-123',
                'title' => 'Test Register',
                'description' => 'Test Description',
                'slug' => 'test-register',
                'created' => '2024-01-01 00:00:00',
                'updated' => '2024-01-02 00:00:00'
            ], false);

        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $result = $this->registerMapper->find('test-uuid-123');
        $this->assertInstanceOf(Register::class, $result);
        $this->assertEquals('test-uuid-123', $result->getUuid());
    }

    /**
     * Test find method with slug
     *
     * @return void
     */
    public function testFindWithSlug(): void
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

        // Mock expression builder
        $expressionBuilder = $this->createMock(IExpressionBuilder::class);
        $compositeExpression = $this->createMock(ICompositeExpression::class);
        $expressionBuilder->method('orX')->willReturn($compositeExpression);
        $expressionBuilder->method('eq')->willReturn('expr_eq');
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':param');

        $result = $this->createMock(IResult::class);
        $result->expects($this->any())
            ->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'uuid' => 'test-uuid-123',
                'title' => 'Test Register',
                'description' => 'Test Description',
                'slug' => 'test-register',
                'created' => '2024-01-01 00:00:00',
                'updated' => '2024-01-02 00:00:00'
            ], false);

        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $result = $this->registerMapper->find('test-register');
        $this->assertInstanceOf(Register::class, $result);
        $this->assertEquals('test-register', $result->getSlug());
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

        // Mock expression builder
        $expressionBuilder = $this->createMock(IExpressionBuilder::class);
        $compositeExpression = $this->createMock(ICompositeExpression::class);
        $expressionBuilder->method('orX')->willReturn($compositeExpression);
        $expressionBuilder->method('eq')->willReturn('expr_eq');
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':param');

        $result = $this->createMock(IResult::class);
        $result->expects($this->any())
            ->method('fetch')
            ->willReturn(false);

        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $this->expectException(DoesNotExistException::class);
        $this->registerMapper->find(999);
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

        $result = $this->registerMapper->findAll();
        $this->assertIsArray($result);
    }

    /**
     * Test createFromArray method
     *
     * @return void
     */
    public function testCreateFromArray(): void
    {
        $data = [
            'title' => 'Test Register',
            'description' => 'Test Description',
            'slug' => 'test-register'
        ];

        // Mock the database connection methods
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('insert')
            ->with('openregister_registers')
            ->willReturnSelf();

        $queryBuilder->expects($this->atLeast(3))
            ->method('setValue')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $queryBuilder->expects($this->once())
            ->method('getLastInsertId')
            ->willReturn(1);

        $result = $this->registerMapper->createFromArray($data);
        $this->assertInstanceOf(Register::class, $result);
    }

    /**
     * Test updateFromArray method
     *
     * @return void
     */
    public function testUpdateFromArray(): void
    {
        $data = [
            'title' => 'Updated Register',
            'description' => 'Updated Description',
            'slug' => 'updated-register'
        ];

        // Mock the find method first
        $existingRegister = new Register();
        $existingRegister->setId(1);
        $existingRegister->setTitle('Original Title');
        $existingRegister->setDescription('Original Description');
        $existingRegister->setSlug('original-slug');
        $existingRegister->setVersion('1.0.0');

        // Mock the database connection for find method
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->atLeast(3))
            ->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        // Mock expression builder
        $expressionBuilder = $this->createMock(IExpressionBuilder::class);
        $compositeExpression = $this->createMock(ICompositeExpression::class);
        $queryBuilder->expects($this->any())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $expressionBuilder->expects($this->any())
            ->method('orX')
            ->willReturn($compositeExpression);

        $expressionBuilder->expects($this->any())
            ->method('eq')
            ->willReturn('id = ?');

        $queryBuilder->expects($this->any())
            ->method('createNamedParameter')
            ->willReturn('?');

        // Mock find method
        $queryBuilder->expects($this->any())
            ->method('select')
            ->willReturnSelf();

        $queryBuilder->expects($this->any())
            ->method('from')
            ->willReturnSelf();

        $queryBuilder->expects($this->atLeast(3))
            ->method('where')
            ->willReturnSelf();

        $mockResult = $this->createMock(IResult::class);
        $queryBuilder->expects($this->atLeast(1))
            ->method('executeQuery')
            ->willReturn($mockResult);

        $mockResult->expects($this->atLeast(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'title' => 'Original Title',
                'description' => 'Original Description',
                'slug' => 'original-slug',
                'version' => '1.0.0'
            ], false, [
                'id' => 1,
                'title' => 'Original Title',
                'description' => 'Original Description',
                'slug' => 'original-slug',
                'version' => '1.0.0'
            ], false);

        // Mock update method
        $queryBuilder->expects($this->once())
            ->method('update')
            ->willReturnSelf();

        $queryBuilder->expects($this->atLeast(1))
            ->method('set')
            ->willReturnSelf();

        $queryBuilder->expects($this->atLeast(1))
            ->method('where')
            ->willReturnSelf();

        $queryBuilder->expects($this->any())
            ->method('createNamedParameter')
            ->willReturn('?');

        $queryBuilder->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $result = $this->registerMapper->updateFromArray(1, $data);
        $this->assertInstanceOf(Register::class, $result);
    }

    /**
     * Test getIdToSlugMap method
     *
     * @return void
     */
    public function testGetIdToSlugMap(): void
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

        $mockResult = $this->createMock(IResult::class);
        $queryBuilder->expects($this->once())
            ->method('execute')
            ->willReturn($mockResult);

        $mockResult->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'slug' => 'test-register-1'],
                ['id' => 2, 'slug' => 'test-register-2'],
                false
            );

        $result = $this->registerMapper->getIdToSlugMap();
        $this->assertIsArray($result);
        $this->assertEquals('test-register-1', $result[1]);
        $this->assertEquals('test-register-2', $result[2]);
    }

    /**
     * Test getSlugToIdMap method
     *
     * @return void
     */
    public function testGetSlugToIdMap(): void
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

        $mockResult = $this->createMock(IResult::class);
        $queryBuilder->expects($this->once())
            ->method('execute')
            ->willReturn($mockResult);

        $mockResult->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'slug' => 'test-register-1'],
                ['id' => 2, 'slug' => 'test-register-2'],
                false
            );

        $result = $this->registerMapper->getSlugToIdMap();
        $this->assertIsArray($result);
        $this->assertEquals(1, $result['test-register-1']);
        $this->assertEquals(2, $result['test-register-2']);
    }

}//end class
