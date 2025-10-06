<?php

declare(strict_types=1);

/**
 * AuditTrailMapperTest
 *
 * Comprehensive unit tests for the AuditTrailMapper class to verify audit trail operations,
 * object reversions, and statistics functionality.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 OpenRegister
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/OpenRegister/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Audit Trail Mapper Test Suite
 *
 * Comprehensive unit tests for audit trail functionality including creation,
 * retrieval, statistics, and object reversion capabilities.
 *
 * @coversDefaultClass AuditTrailMapper
 */
class AuditTrailMapperTest extends TestCase
{
    private AuditTrailMapper $auditTrailMapper;
    private IDBConnection|MockObject $db;
    private ObjectEntityMapper|MockObject $objectEntityMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        
        $this->auditTrailMapper = new AuditTrailMapper($this->db, $this->objectEntityMapper);
    }

    /**
     * Test constructor
     *
     * @covers ::__construct
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(AuditTrailMapper::class, $this->auditTrailMapper);
    }

    /**
     * Test find method with valid ID
     *
     * @covers ::find
     * @return void
     */
    public function testFindWithValidId(): void
    {
        $id = 1;
        $auditTrail = new AuditTrail();
        $auditTrail->setId($id);
        $auditTrail->setObject(123);
        $auditTrail->setObjectUuid('test-uuid');
        $auditTrail->setAction('update');
        $auditTrail->setCreated(new \DateTime());

        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('from')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('where')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('expr')
            ->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

        $qb->expects($this->once())
            ->method('createNamedParameter')
            ->with($id, IQueryBuilder::PARAM_INT)
            ->willReturn('?');

        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->expects($this->once())
            ->method('fetch')
            ->willReturn(false);
        
        $qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $this->expectException(DoesNotExistException::class);
        $this->auditTrailMapper->find($id);
    }

    /**
     * Test findAll method with default parameters
     *
     * @covers ::findAll
     * @return void
     */
    public function testFindAllWithDefaultParameters(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('from')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('addOrderBy')
            ->with('created', 'DESC')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createMock(\OCP\DB\IResult::class));

        $result = $this->auditTrailMapper->findAll();

        $this->assertIsArray($result);
    }

    /**
     * Test findAll method with custom parameters
     *
     * @covers ::findAll
     * @return void
     */
    public function testFindAllWithCustomParameters(): void
    {
        $registerId = 1;
        $schemaId = 2;
        $limit = 50;
        $offset = 10;

        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('from')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('addOrderBy')
            ->with('created', 'DESC')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('setMaxResults')
            ->with($limit)
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('setFirstResult')
            ->with($offset)
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createMock(\OCP\DB\IResult::class));

        $result = $this->auditTrailMapper->findAll($limit, $offset, ['register_id' => $registerId, 'schema_id' => $schemaId]);

        $this->assertIsArray($result);
    }

    /**
     * Test createFromArray method
     *
     * @covers ::createFromArray
     * @return void
     */
    public function testCreateFromArray(): void
    {
        $data = [
            'object' => 123,
            'objectUuid' => 'test-uuid',
            'action' => 'update',
            'created' => '2024-01-01 12:00:00',
            'old_object' => json_encode(['name' => 'old']),
            'new_object' => json_encode(['name' => 'new'])
        ];

        // Mock the insert method that will be called internally
        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('insert')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        $qb->expects($this->atLeast(6))
            ->method('setValue')
            ->willReturnSelf();

        $qb->expects($this->atLeast(6))
            ->method('createNamedParameter')
            ->willReturn('?');

        $qb->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $qb->expects($this->once())
            ->method('getLastInsertId')
            ->willReturn(1);

        $auditTrail = $this->auditTrailMapper->createFromArray($data);

        $this->assertInstanceOf(AuditTrail::class, $auditTrail);
        $this->assertEquals($data['object'], $auditTrail->getObject());
        $this->assertEquals($data['objectUuid'], $auditTrail->getObjectUuid());
        $this->assertEquals($data['action'], $auditTrail->getAction());
    }

    /**
     * Test createAuditTrail method with both old and new objects
     *
     * @covers ::createAuditTrail
     * @return void
     */
    public function testCreateAuditTrailWithBothObjects(): void
    {
        $oldObject = new ObjectEntity();
        $oldObject->setId(123);
        $oldObject->setUuid('test-uuid');
        $oldObject->setObject(['name' => 'old']);

        $newObject = new ObjectEntity();
        $newObject->setId(123);
        $newObject->setUuid('test-uuid');
        $newObject->setObject(['name' => 'new']);

        // Mock the database operations
        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('insert')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('setValue')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('setParameter')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $qb->expects($this->once())
            ->method('getLastInsertId')
            ->willReturn(1);

        // Mock OC::$server->getRequest() calls
        $request = $this->createMock(\OCP\IRequest::class);
        $request->expects($this->any())
            ->method('getId')
            ->willReturn('test-request-id');
        $request->expects($this->any())
            ->method('getRemoteAddress')
            ->willReturn('127.0.0.1');

        // Mock user session
        $user = $this->createMock(\OCP\IUser::class);
        $user->expects($this->any())
            ->method('getUID')
            ->willReturn('test-user');

        $userSession = $this->createMock(\OCP\IUserSession::class);
        $userSession->expects($this->any())
            ->method('getUser')
            ->willReturn($user);

        // Mock OC::$server
        $server = $this->createMock(\OC\Server::class);
        $server->expects($this->any())
            ->method('getRequest')
            ->willReturn($request);
        $server->expects($this->any())
            ->method('getUserSession')
            ->willReturn($userSession);

        // Use reflection to set the static OC::$server property
        $reflection = new \ReflectionClass(\OC::class);
        $serverProperty = $reflection->getProperty('server');
        $serverProperty->setAccessible(true);
        $originalServer = $serverProperty->getValue();
        $serverProperty->setValue(null, $server);

        try {
            $result = $this->auditTrailMapper->createAuditTrail($oldObject, $newObject, 'update');

            $this->assertInstanceOf(AuditTrail::class, $result);
            // The actual values depend on the implementation details
            // We just verify that the method returns an AuditTrail object
        } finally {
            // Restore the original server
            $serverProperty->setValue(null, $originalServer);
        }
    }

    /**
     * Test createAuditTrail method with only new object
     *
     * @covers ::createAuditTrail
     * @return void
     */
    public function testCreateAuditTrailWithOnlyNewObject(): void
    {
        $newObject = new ObjectEntity();
        $newObject->setId(123);
        $newObject->setUuid('test-uuid');
        $newObject->setObject(['name' => 'new']);

        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('insert')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('setValue')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('setParameter')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $qb->expects($this->once())
            ->method('getLastInsertId')
            ->willReturn(1);

        $result = $this->auditTrailMapper->createAuditTrail(null, $newObject, 'create');

        $this->assertInstanceOf(AuditTrail::class, $result);
        // The actual values depend on the implementation details
        // We just verify that the method returns an AuditTrail object
    }

    /**
     * Test getStatistics method
     *
     * @covers ::getStatistics
     * @return void
     */
    public function testGetStatistics(): void
    {
        $registerId = 1;
        $schemaId = 2;

        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('from')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        // Mock the expr() method and its return value
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expr->expects($this->any())
            ->method('eq')
            ->willReturn('register = ?');
        
        $qb->expects($this->any())
            ->method('expr')
            ->willReturn($expr);
            
        $qb->expects($this->any())
            ->method('andWhere')
            ->willReturnSelf();
            
        $qb->expects($this->any())
            ->method('createNamedParameter')
            ->willReturn('?');
            
        // Mock the func() method
        $func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
        $queryFunction = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
        $func->expects($this->any())
            ->method('count')
            ->willReturn($queryFunction);
            
        $qb->expects($this->any())
            ->method('func')
            ->willReturn($func);

        $qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createMock(\OCP\DB\IResult::class));

        $result = $this->auditTrailMapper->getStatistics($registerId, $schemaId);

        $this->assertIsArray($result);
    }

    /**
     * Test count method
     *
     * @covers ::count
     * @return void
     */
    public function testCount(): void
    {
        $filters = ['action' => 'update'];

        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('from')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('where')
            ->willReturnSelf();

        $qb->expects($this->any())
            ->method('setParameter')
            ->willReturnSelf();

        // Mock the expr() method and its return value
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expr->expects($this->any())
            ->method('eq')
            ->willReturn('action = ?');
        
        $qb->expects($this->any())
            ->method('expr')
            ->willReturn($expr);
            
        // Mock the func() method
        $func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
        $queryFunction = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
        $func->expects($this->any())
            ->method('count')
            ->willReturn($queryFunction);
            
        $qb->expects($this->any())
            ->method('func')
            ->willReturn($func);

        $qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createMock(\OCP\DB\IResult::class));

        $result = $this->auditTrailMapper->count($filters);

        $this->assertIsInt($result);
    }

    /**
     * Test clearLogs method
     *
     * @covers ::clearLogs
     * @return void
     */
    public function testClearLogs(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('delete')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        // Mock the expr() method and its return value
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expr->expects($this->any())
            ->method('isNotNull')
            ->willReturn('expires IS NOT NULL');
        
        $qb->expects($this->any())
            ->method('expr')
            ->willReturn($expr);
            
        $qb->expects($this->any())
            ->method('where')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('executeStatement')
            ->willReturn(5);

        $result = $this->auditTrailMapper->clearLogs();

        $this->assertTrue($result);
    }

    /**
     * Test sizeAuditTrails method
     *
     * @covers ::sizeAuditTrails
     * @return void
     */
    public function testSizeAuditTrails(): void
    {
        $filters = ['action' => 'update'];

        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('from')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        // Mock the expr() method and its return value
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expr->expects($this->any())
            ->method('eq')
            ->willReturn('action = ?');
        
        $qb->expects($this->any())
            ->method('expr')
            ->willReturn($expr);
            
        $qb->expects($this->any())
            ->method('where')
            ->willReturnSelf();
            
        $qb->expects($this->any())
            ->method('setParameter')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createMock(\OCP\DB\IResult::class));

        $result = $this->auditTrailMapper->sizeAuditTrails($filters);

        $this->assertIsInt($result);
    }

    /**
     * Test setExpiryDate method
     *
     * @covers ::setExpiryDate
     * @return void
     */
    public function testSetExpiryDate(): void
    {
        $retentionMs = 86400000; // 24 hours in milliseconds

        $qb = $this->createMock(IQueryBuilder::class);
        $this->db->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($qb);

        $qb->expects($this->once())
            ->method('update')
            ->with('openregister_audit_trails')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('set')
            ->willReturnSelf();

        // Mock the expr() method and its return value
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expr->expects($this->any())
            ->method('isNull')
            ->willReturn('expires IS NULL');
        
        $qb->expects($this->any())
            ->method('expr')
            ->willReturn($expr);

        $qb->expects($this->any())
            ->method('where')
            ->willReturnSelf();

        $qb->expects($this->once())
            ->method('executeStatement')
            ->willReturn(3);

        $result = $this->auditTrailMapper->setExpiryDate($retentionMs);

        $this->assertIsInt($result);
    }
}
