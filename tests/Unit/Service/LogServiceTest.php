<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\AuditTrail;
use PHPUnit\Framework\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Test class for LogService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class LogServiceTest extends TestCase
{
    private LogService $logService;
    private AuditTrailMapper $auditTrailMapper;
    private ObjectEntityMapper $objectEntityMapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        // Create LogService instance
        $this->logService = new LogService(
            $this->auditTrailMapper,
            $this->objectEntityMapper,
            $this->registerMapper,
            $this->schemaMapper
        );
    }

    /**
     * Test getAllLogs method
     */
    public function testGetAllLogs(): void
    {
        $config = [
            'limit' => 15,
            'offset' => 5,
            'filters' => ['action' => 'delete'],
            'sort' => ['created' => 'ASC'],
            'search' => 'deleted'
        ];

        // Create mock audit trail entries
        $expectedLogs = [
            $this->createMock(AuditTrail::class),
            $this->createMock(AuditTrail::class)
        ];

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->equalTo(15),
                $this->equalTo(5),
                $this->callback(function ($filters) {
                    return isset($filters['action']) && $filters['action'] === 'delete';
                }),
                $this->equalTo(['created' => 'ASC']),
                $this->equalTo('deleted')
            )
            ->willReturn($expectedLogs);

        $result = $this->logService->getAllLogs($config);

        $this->assertEquals($expectedLogs, $result);
    }

    /**
     * Test getAllLogs method with default configuration
     */
    public function testGetAllLogsWithDefaultConfig(): void
    {
        // Create mock audit trail entries
        $expectedLogs = [$this->createMock(AuditTrail::class)];

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->equalTo(20), // default limit
                $this->equalTo(0),  // default offset
                $this->equalTo([]), // default filters
                $this->equalTo(['created' => 'DESC']), // default sort
                $this->equalTo(null) // default search
            )
            ->willReturn($expectedLogs);

        $result = $this->logService->getAllLogs();

        $this->assertEquals($expectedLogs, $result);
    }

    /**
     * Test countAllLogs method
     */
    public function testCountAllLogs(): void
    {
        $filters = ['action' => 'create'];

        // Create mock audit trail entries
        $mockLogs = [
            $this->createMock(AuditTrail::class),
            $this->createMock(AuditTrail::class),
            $this->createMock(AuditTrail::class)
        ];

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('findAll')
            ->with(
                null, // limit
                null, // offset
                $this->callback(function ($filters) {
                    return isset($filters['action']) && $filters['action'] === 'create';
                }),
                ['created' => 'DESC'], // sort
                null // search
            )
            ->willReturn($mockLogs);

        $result = $this->logService->countAllLogs($filters);

        $this->assertEquals(3, $result);
    }

    /**
     * Test countAllLogs method with default configuration
     */
    public function testCountAllLogsWithDefaultConfig(): void
    {
        // Create mock audit trail entries
        $mockLogs = array_fill(0, 25, $this->createMock(AuditTrail::class));

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('findAll')
            ->with(
                null, // limit
                null, // offset
                [], // default filters
                ['created' => 'DESC'], // sort
                null // search
            )
            ->willReturn($mockLogs);

        $result = $this->logService->countAllLogs();

        $this->assertEquals(25, $result);
    }

    /**
     * Test getLog method
     */
    public function testGetLog(): void
    {
        $logId = 123;
        $expectedLog = $this->createMock(AuditTrail::class);

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('find')
            ->with($logId)
            ->willReturn($expectedLog);

        $result = $this->logService->getLog($logId);

        $this->assertEquals($expectedLog, $result);
    }

    /**
     * Test getLog method with non-existent log
     */
    public function testGetLogWithNonExistentLog(): void
    {
        $logId = 999;

        // Mock audit trail mapper to throw exception
        $this->auditTrailMapper->expects($this->once())
            ->method('find')
            ->with($logId)
            ->willThrowException(new DoesNotExistException('Log not found'));

        $this->expectException(DoesNotExistException::class);
        $this->expectExceptionMessage('Log not found');

        $this->logService->getLog($logId);
    }

    /**
     * Test exportLogs method with CSV format
     */
    public function testExportLogsWithCsvFormat(): void
    {
        $format = 'csv';
        $config = [
            'filters' => ['action' => 'create'],
            'includeChanges' => true,
            'includeMetadata' => true,
            'search' => 'test'
        ];

        // Create mock audit trail entries
        $mockLogs = [
            $this->createMock(AuditTrail::class),
            $this->createMock(AuditTrail::class)
        ];

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('findAll')
            ->with(
                null, // limit
                null, // offset
                $this->callback(function ($filters) {
                    return isset($filters['action']) && $filters['action'] === 'create';
                }),
                ['created' => 'DESC'], // sort
                'test' // search
            )
            ->willReturn($mockLogs);

        $result = $this->logService->exportLogs($format, $config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('contentType', $result);
        $this->assertEquals('text/csv', $result['contentType']);
        $this->assertStringEndsWith('.csv', $result['filename']);
    }

    /**
     * Test exportLogs method with JSON format
     */
    public function testExportLogsWithJsonFormat(): void
    {
        $format = 'json';
        $config = [];

        // Create mock audit trail entries
        $mockLogs = [$this->createMock(AuditTrail::class)];

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($mockLogs);

        $result = $this->logService->exportLogs($format, $config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('contentType', $result);
        $this->assertEquals('application/json', $result['contentType']);
        $this->assertStringEndsWith('.json', $result['filename']);
    }

    /**
     * Test exportLogs method with XML format
     */
    public function testExportLogsWithXmlFormat(): void
    {
        $format = 'xml';
        $config = [];

        // Create mock audit trail entries
        $mockLogs = [$this->createMock(AuditTrail::class)];

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($mockLogs);

        $result = $this->logService->exportLogs($format, $config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('contentType', $result);
        $this->assertEquals('application/xml', $result['contentType']);
        $this->assertStringEndsWith('.xml', $result['filename']);
    }

    /**
     * Test exportLogs method with invalid format
     */
    public function testExportLogsWithInvalidFormat(): void
    {
        $format = 'invalid';
        $config = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported export format: invalid');

        $this->logService->exportLogs($format, $config);
    }

    /**
     * Test exportLogs method with TXT format
     */
    public function testExportLogsWithTxtFormat(): void
    {
        $format = 'txt';
        $config = [];

        // Create mock audit trail entries
        $mockLogs = [$this->createMock(AuditTrail::class)];

        // Mock audit trail mapper
        $this->auditTrailMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($mockLogs);

        $result = $this->logService->exportLogs($format, $config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('contentType', $result);
        $this->assertEquals('text/plain', $result['contentType']);
        $this->assertStringEndsWith('.txt', $result['filename']);
    }
}