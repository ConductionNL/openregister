<?php

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Service\DashboardService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class DashboardServiceTest extends TestCase
{

    /**
     * @var ObjectEntityMapper&MockObject
     */
    private ObjectEntityMapper $objectMapper;

    /**
     * @var AuditTrailMapper&MockObject
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * @var WebhookLogMapper&MockObject
     */
    private WebhookLogMapper $webhookLogMapper;

    /**
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper $registerMapper;

    /**
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper $schemaMapper;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    private DashboardService $service;

    protected function setUp(): void
    {
        $this->objectMapper = $this->createMock(ObjectEntityMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->webhookLogMapper = $this->createMock(WebhookLogMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new DashboardService(
            $this->objectMapper,
            $this->auditTrailMapper,
            $this->webhookLogMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->logger
        );
    }

    // --- recalculateSizes ---

    public function testRecalculateSizesProcessesAllObjects(): void
    {
        $obj1 = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $obj2 = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);

        $this->objectMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$obj1, $obj2]);

        $this->objectMapper
            ->expects($this->exactly(2))
            ->method('update');

        $result = $this->service->recalculateSizes();

        $this->assertSame(2, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function testRecalculateSizesCountsFailures(): void
    {
        $obj1 = new \OCA\OpenRegister\Db\ObjectEntity();
        $reflection = new \ReflectionClass($obj1);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($obj1, 1);

        $obj2 = new \OCA\OpenRegister\Db\ObjectEntity();
        $idProp->setValue($obj2, 2);

        $this->objectMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$obj1, $obj2]);

        $callCount = 0;
        $this->objectMapper
            ->expects($this->exactly(2))
            ->method('update')
            ->willReturnCallback(function ($obj) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new Exception('Update failed');
                }
                return $obj;
            });

        $result = $this->service->recalculateSizes();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['failed']);
    }

    public function testRecalculateSizesWithFilters(): void
    {
        $this->objectMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->recalculateSizes(1, 2);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function testRecalculateSizesThrowsOnFindAllError(): void
    {
        $this->objectMapper
            ->expects($this->once())
            ->method('findAll')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to recalculate sizes');

        $this->service->recalculateSizes();
    }

    // --- recalculateLogSizes ---

    public function testRecalculateLogSizesProcessesAllLogs(): void
    {
        $log1 = $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$log1]);

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('update');

        $result = $this->service->recalculateLogSizes();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function testRecalculateLogSizesThrowsOnError(): void
    {
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('findAll')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to recalculate log sizes');

        $this->service->recalculateLogSizes();
    }

    // --- recalculateAllSizes ---

    public function testRecalculateAllSizesCombinesResults(): void
    {
        // Objects
        $this->objectMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        // Logs
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->recalculateAllSizes();

        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('logs', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame(0, $result['total']['processed']);
        $this->assertSame(0, $result['total']['failed']);
    }

    // --- getRegistersWithSchemas ---

    public function testGetRegistersWithSchemasReturnsStructuredData(): void
    {
        $register = new Register();
        $reflection = new \ReflectionClass($register);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 1);
        $register->setTitle('Test Register');

        $schema = new Schema();
        $sReflection = new \ReflectionClass($schema);
        $sIdProp = $sReflection->getProperty('id');
        $sIdProp->setAccessible(true);
        $sIdProp->setValue($schema, 10);

        $this->registerMapper
            ->method('findAll')
            ->willReturn([$register]);

        $this->registerMapper
            ->method('getSchemasByRegisterId')
            ->willReturn([$schema]);

        $this->objectMapper
            ->method('getStatistics')
            ->willReturn([
                'total' => 10, 'size' => 1024, 'invalid' => 0,
                'deleted' => 0, 'locked' => 0, 'published' => 5,
            ]);

        $this->auditTrailMapper
            ->method('getStatistics')
            ->willReturn(['total' => 5, 'size' => 512]);

        $this->webhookLogMapper
            ->method('getStatistics')
            ->willReturn(['total' => 2]);

        $result = $this->service->getRegistersWithSchemas();

        // First entry should be system totals.
        $this->assertSame('totals', $result[0]['id']);
        $this->assertSame('System Totals', $result[0]['title']);

        // Last entry should be orphaned.
        $lastIndex = count($result) - 1;
        $this->assertSame('orphaned', $result[$lastIndex]['id']);
    }

    public function testGetRegistersWithSchemasThrowsOnError(): void
    {
        $this->registerMapper
            ->method('findAll')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to get registers with schemas');

        $this->service->getRegistersWithSchemas();
    }

    // --- Chart data methods (delegate to mappers, test error handling) ---

    public function testGetAuditTrailActionChartDataReturnsData(): void
    {
        $expected = [
            'labels' => ['2024-01', '2024-02'],
            'series' => [['data' => [5, 10], 'name' => 'create']],
        ];

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('getActionChartData')
            ->willReturn($expected);

        $result = $this->service->getAuditTrailActionChartData();

        $this->assertSame($expected, $result);
    }

    public function testGetAuditTrailActionChartDataReturnsEmptyOnError(): void
    {
        $this->auditTrailMapper
            ->method('getActionChartData')
            ->willThrowException(new Exception('DB error'));

        $result = $this->service->getAuditTrailActionChartData();

        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['series']);
    }

    public function testGetObjectsByRegisterChartDataReturnsEmptyOnError(): void
    {
        $this->objectMapper
            ->method('getRegisterChartData')
            ->willThrowException(new Exception('DB error'));

        $result = $this->service->getObjectsByRegisterChartData();

        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['series']);
    }

    public function testGetObjectsBySchemaChartDataReturnsEmptyOnError(): void
    {
        $this->objectMapper
            ->method('getSchemaChartData')
            ->willThrowException(new Exception('DB error'));

        $result = $this->service->getObjectsBySchemaChartData();

        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['series']);
    }

    public function testGetObjectsBySizeChartDataReturnsEmptyOnError(): void
    {
        $this->objectMapper
            ->method('getSizeDistributionChartData')
            ->willThrowException(new Exception('DB error'));

        $result = $this->service->getObjectsBySizeChartData();

        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['series']);
    }

    // --- Audit trail statistics ---

    public function testGetAuditTrailStatisticsReturnsData(): void
    {
        $expected = ['total' => 100, 'creates' => 40, 'updates' => 30, 'deletes' => 10, 'reads' => 20];

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('getDetailedStatistics')
            ->willReturn($expected);

        $result = $this->service->getAuditTrailStatistics();

        $this->assertSame($expected, $result);
    }

    public function testGetAuditTrailStatisticsReturnsZeroOnError(): void
    {
        $this->auditTrailMapper
            ->method('getDetailedStatistics')
            ->willThrowException(new Exception('error'));

        $result = $this->service->getAuditTrailStatistics();

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['creates']);
    }

    public function testGetAuditTrailActionDistributionReturnsEmptyOnError(): void
    {
        $this->auditTrailMapper
            ->method('getActionDistribution')
            ->willThrowException(new Exception('error'));

        $result = $this->service->getAuditTrailActionDistribution();

        $this->assertSame([], $result['actions']);
    }

    public function testGetMostActiveObjectsReturnsEmptyOnError(): void
    {
        $this->auditTrailMapper
            ->method('getMostActiveObjects')
            ->willThrowException(new Exception('error'));

        $result = $this->service->getMostActiveObjects();

        $this->assertSame([], $result['objects']);
    }
}
