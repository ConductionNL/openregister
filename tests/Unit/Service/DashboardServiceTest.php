<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\DashboardService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Test class for DashboardService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class DashboardServiceTest extends TestCase
{
    private DashboardService $dashboardService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private ObjectEntityMapper $objectMapper;
    private AuditTrailMapper $auditTrailMapper;
    private IDBConnection $db;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectMapper = $this->createMock(ObjectEntityMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create DashboardService instance
        $this->dashboardService = new DashboardService(
            $this->registerMapper,
            $this->schemaMapper,
            $this->objectMapper,
            $this->auditTrailMapper,
            $this->db,
            $this->logger
        );
    }

    /**
     * Test getDashboardData method with register and schema
     */
    public function testGetDashboardDataWithRegisterAndSchema(): void
    {
        $registerId = 1;
        $schemaId = 2;

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn($registerId);
        $register->method('getTitle')->willReturn('Test Register');

        // Create mock schema
        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn($schemaId);
        $schema->method('getTitle')->willReturn('Test Schema');

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($registerId)
            ->willReturn($register);

        // Mock schema mapper
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($schemaId)
            ->willReturn($schema);

        // Mock object mapper statistics
        $this->objectMapper->expects($this->once())
            ->method('getStatistics')
            ->with($registerId, $schemaId)
            ->willReturn([
                'total' => 100,
                'size' => 1024000,
                'invalid' => 5,
                'deleted' => 10,
                'locked' => 2,
                'published' => 80
            ]);

        // Mock audit trail mapper statistics
        $this->auditTrailMapper->expects($this->once())
            ->method('getStatistics')
            ->with($registerId, $schemaId)
            ->willReturn([
                'total' => 500,
                'size' => 512000
            ]);

        // Mock file statistics query
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

        $queryBuilder->expects($this->once())
            ->method('setParameter')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createMock(\Doctrine\DBAL\Result::class));

        $result = $this->dashboardService->getDashboardData($registerId, $schemaId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('register', $result);
        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('stats', $result);

        $this->assertEquals($register, $result['register']);
        $this->assertEquals($schema, $result['schema']);

        $stats = $result['stats'];
        $this->assertArrayHasKey('objects', $stats);
        $this->assertArrayHasKey('logs', $stats);
        $this->assertArrayHasKey('files', $stats);

        $this->assertEquals(100, $stats['objects']['total']);
        $this->assertEquals(1024000, $stats['objects']['size']);
        $this->assertEquals(5, $stats['objects']['invalid']);
        $this->assertEquals(10, $stats['objects']['deleted']);
        $this->assertEquals(2, $stats['objects']['locked']);
        $this->assertEquals(80, $stats['objects']['published']);

        $this->assertEquals(500, $stats['logs']['total']);
        $this->assertEquals(512000, $stats['logs']['size']);
    }

    /**
     * Test getDashboardData method with register only
     */
    public function testGetDashboardDataWithRegisterOnly(): void
    {
        $registerId = 1;
        $schemaId = null;

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn($registerId);
        $register->method('getTitle')->willReturn('Test Register');

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($registerId)
            ->willReturn($register);

        // Mock object mapper statistics
        $this->objectMapper->expects($this->once())
            ->method('getStatistics')
            ->with($registerId, null)
            ->willReturn([
                'total' => 200,
                'size' => 2048000,
                'invalid' => 10,
                'deleted' => 20,
                'locked' => 4,
                'published' => 160
            ]);

        // Mock audit trail mapper statistics
        $this->auditTrailMapper->expects($this->once())
            ->method('getStatistics')
            ->with($registerId, null)
            ->willReturn([
                'total' => 1000,
                'size' => 1024000
            ]);

        // Mock file statistics query
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

        $queryBuilder->expects($this->once())
            ->method('setParameter')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createMock(\Doctrine\DBAL\Result::class));

        $result = $this->dashboardService->getDashboardData($registerId, $schemaId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('register', $result);
        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('stats', $result);

        $this->assertEquals($register, $result['register']);
        $this->assertNull($result['schema']);

        $stats = $result['stats'];
        $this->assertEquals(200, $stats['objects']['total']);
        $this->assertEquals(1000, $stats['logs']['total']);
    }

    /**
     * Test getDashboardData method with no parameters (global stats)
     */
    public function testGetDashboardDataGlobal(): void
    {
        $registerId = null;
        $schemaId = null;

        // Mock object mapper statistics
        $this->objectMapper->expects($this->once())
            ->method('getStatistics')
            ->with(null, null)
            ->willReturn([
                'total' => 1000,
                'size' => 10240000,
                'invalid' => 50,
                'deleted' => 100,
                'locked' => 20,
                'published' => 800
            ]);

        // Mock audit trail mapper statistics
        $this->auditTrailMapper->expects($this->once())
            ->method('getStatistics')
            ->with(null, null)
            ->willReturn([
                'total' => 5000,
                'size' => 5120000
            ]);

        // Mock file statistics query
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

        $queryBuilder->expects($this->once())
            ->method('setParameter')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createMock(\Doctrine\DBAL\Result::class));

        $result = $this->dashboardService->getDashboardData($registerId, $schemaId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('register', $result);
        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('stats', $result);

        $this->assertNull($result['register']);
        $this->assertNull($result['schema']);

        $stats = $result['stats'];
        $this->assertEquals(1000, $stats['objects']['total']);
        $this->assertEquals(5000, $stats['logs']['total']);
    }

    /**
     * Test getDashboardData method with non-existent register
     */
    public function testGetDashboardDataWithNonExistentRegister(): void
    {
        $registerId = 999;
        $schemaId = null;

        // Mock register mapper to throw exception
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($registerId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Register not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Register not found');

        $this->dashboardService->getDashboardData($registerId, $schemaId);
    }

    /**
     * Test getDashboardData method with non-existent schema
     */
    public function testGetDashboardDataWithNonExistentSchema(): void
    {
        $registerId = 1;
        $schemaId = 999;

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn($registerId);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($registerId)
            ->willReturn($register);

        // Mock schema mapper to throw exception
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($schemaId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Schema not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Schema not found');

        $this->dashboardService->getDashboardData($registerId, $schemaId);
    }

    /**
     * Test getRecentActivity method
     */
    public function testGetRecentActivity(): void
    {
        $registerId = 1;
        $schemaId = 2;
        $limit = 10;

        // Create mock audit trail entries
        $auditTrail1 = $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);
        $auditTrail2 = $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);
        $expectedActivity = [$auditTrail1, $auditTrail2];

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('findRecentActivity')
            ->with($registerId, $schemaId, $limit)
            ->willReturn($expectedActivity);

        $result = $this->dashboardService->getRecentActivity($registerId, $schemaId, $limit);

        $this->assertEquals($expectedActivity, $result);
    }

    /**
     * Test getRecentActivity method with default limit
     */
    public function testGetRecentActivityWithDefaultLimit(): void
    {
        $registerId = 1;
        $schemaId = 2;

        // Create mock audit trail entries
        $expectedActivity = [$this->createMock(\OCA\OpenRegister\Db\AuditTrail::class)];

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('findRecentActivity')
            ->with($registerId, $schemaId, 20) // default limit
            ->willReturn($expectedActivity);

        $result = $this->dashboardService->getRecentActivity($registerId, $schemaId);

        $this->assertEquals($expectedActivity, $result);
    }

    /**
     * Test getTopRegisters method
     */
    public function testGetTopRegisters(): void
    {
        $limit = 5;

        // Create mock registers
        $register1 = $this->createMock(Register::class);
        $register2 = $this->createMock(Register::class);
        $expectedRegisters = [$register1, $register2];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('findTopByObjectCount')
            ->with($limit)
            ->willReturn($expectedRegisters);

        $result = $this->dashboardService->getTopRegisters($limit);

        $this->assertEquals($expectedRegisters, $result);
    }

    /**
     * Test getTopRegisters method with default limit
     */
    public function testGetTopRegistersWithDefaultLimit(): void
    {
        // Create mock registers
        $expectedRegisters = [$this->createMock(Register::class)];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('findTopByObjectCount')
            ->with(10) // default limit
            ->willReturn($expectedRegisters);

        $result = $this->dashboardService->getTopRegisters();

        $this->assertEquals($expectedRegisters, $result);
    }

    /**
     * Test getSystemHealth method
     */
    public function testGetSystemHealth(): void
    {
        // Mock object mapper statistics
        $this->objectMapper->expects($this->once())
            ->method('getStatistics')
            ->with(null, null)
            ->willReturn([
                'total' => 1000,
                'size' => 10240000,
                'invalid' => 50,
                'deleted' => 100,
                'locked' => 20,
                'published' => 800
            ]);

        // Mock audit trail mapper statistics
        $this->auditTrailMapper->expects($this->once())
            ->method('getStatistics')
            ->with(null, null)
            ->willReturn([
                'total' => 5000,
                'size' => 5120000
            ]);

        $result = $this->dashboardService->getSystemHealth();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('warnings', $result);

        $this->assertIsString($result['status']);
        $this->assertIsArray($result['metrics']);
        $this->assertIsArray($result['warnings']);

        // Check that metrics contain expected data
        $metrics = $result['metrics'];
        $this->assertArrayHasKey('total_objects', $metrics);
        $this->assertArrayHasKey('invalid_objects', $metrics);
        $this->assertArrayHasKey('deleted_objects', $metrics);
        $this->assertArrayHasKey('locked_objects', $metrics);
        $this->assertArrayHasKey('published_objects', $metrics);
        $this->assertArrayHasKey('total_logs', $metrics);

        $this->assertEquals(1000, $metrics['total_objects']);
        $this->assertEquals(50, $metrics['invalid_objects']);
        $this->assertEquals(100, $metrics['deleted_objects']);
        $this->assertEquals(20, $metrics['locked_objects']);
        $this->assertEquals(800, $metrics['published_objects']);
        $this->assertEquals(5000, $metrics['total_logs']);
    }
}
