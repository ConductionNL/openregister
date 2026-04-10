<?php

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
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
     * @var MagicMapper&MockObject
     */
    private MagicMapper $objectMapper;

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
        $this->objectMapper = $this->createMock(MagicMapper::class);
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

    private function createRegisterEntity(int $id, string $title = 'Test Register', array $schemas = [10, 20]): Register
    {
        $register = new Register();
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        $register->setTitle($title);
        // Set schemas directly via reflection to avoid named-arg bug in parent::setSchemas.
        $schemasProp = $ref->getProperty('schemas');
        $schemasProp->setAccessible(true);
        $schemasProp->setValue($register, $schemas);
        return $register;
    }

    private function createSchemaEntity(int $id, string $title = 'Test Schema'): Schema
    {
        $schema = new Schema();
        $ref = new \ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        $schema->setTitle($title);
        return $schema;
    }

    private function createObjectEntity(int $id): ObjectEntity
    {
        $obj = new ObjectEntity();
        $ref = new \ReflectionClass($obj);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($obj, $id);
        return $obj;
    }

    private function createAuditTrailEntity(int $id): AuditTrail
    {
        $trail = new AuditTrail();
        $ref = new \ReflectionClass($trail);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($trail, $id);
        return $trail;
    }

    private function defaultObjectStats(): array
    {
        return [
            'total' => 10, 'size' => 1024, 'invalid' => 1,
            'deleted' => 2, 'locked' => 0, 'published' => 5,
        ];
    }

    private function defaultLogStats(): array
    {
        return ['total' => 5, 'size' => 512];
    }

    private function defaultWebhookStats(): array
    {
        return ['total' => 2];
    }

    private function setupStatsMappers(): void
    {
        $this->objectMapper->method('getStatistics')
            ->willReturn($this->defaultObjectStats());
        $this->auditTrailMapper->method('getStatistics')
            ->willReturn($this->defaultLogStats());
        $this->webhookLogMapper->method('getStatistics')
            ->willReturn($this->defaultWebhookStats());
    }

    // ========== recalculateSizes ==========

    public function testRecalculateSizesProcessesAllObjects(): void
    {
        $obj1 = $this->createMock(ObjectEntity::class);
        $obj2 = $this->createMock(ObjectEntity::class);

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
        $obj1 = $this->createObjectEntity(1);
        $obj2 = $this->createObjectEntity(2);

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

    public function testRecalculateSizesWithRegisterFilter(): void
    {
        $this->objectMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->recalculateSizes(1, null);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function testRecalculateSizesWithSchemaFilter(): void
    {
        $this->objectMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->recalculateSizes(null, 2);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function testRecalculateSizesWithBothFilters(): void
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

    // ========== recalculateLogSizes ==========

    public function testRecalculateLogSizesProcessesAllLogs(): void
    {
        $log1 = $this->createMock(AuditTrail::class);

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

    public function testRecalculateLogSizesCountsFailures(): void
    {
        $log1 = $this->createAuditTrailEntity(1);
        $log2 = $this->createAuditTrailEntity(2);

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$log1, $log2]);

        $callCount = 0;
        $this->auditTrailMapper
            ->expects($this->exactly(2))
            ->method('update')
            ->willReturnCallback(function ($log) use (&$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    throw new Exception('Update failed');
                }
                return $log;
            });

        $result = $this->service->recalculateLogSizes();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['failed']);
    }

    public function testRecalculateLogSizesWithRegisterFilter(): void
    {
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->recalculateLogSizes(1, null);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function testRecalculateLogSizesWithSchemaFilter(): void
    {
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->recalculateLogSizes(null, 2);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function testRecalculateLogSizesWithBothFilters(): void
    {
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->recalculateLogSizes(1, 2);

        $this->assertSame(0, $result['processed']);
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

    // ========== recalculateAllSizes ==========

    public function testRecalculateAllSizesCombinesResults(): void
    {
        $this->objectMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

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

    public function testRecalculateAllSizesAggregatesProcessedAndFailed(): void
    {
        $obj1 = $this->createMock(ObjectEntity::class);
        $obj2 = $this->createMock(ObjectEntity::class);
        $log1 = $this->createMock(AuditTrail::class);

        $this->objectMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$obj1, $obj2]);
        $this->objectMapper
            ->method('update')
            ->willReturnArgument(0);

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$log1]);
        $this->auditTrailMapper
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->recalculateAllSizes();

        $this->assertSame(2, $result['objects']['processed']);
        $this->assertSame(1, $result['logs']['processed']);
        $this->assertSame(3, $result['total']['processed']);
        $this->assertSame(0, $result['total']['failed']);
    }

    public function testRecalculateAllSizesWithFilters(): void
    {
        $this->objectMapper->method('findAll')->willReturn([]);
        $this->auditTrailMapper->method('findAll')->willReturn([]);

        $result = $this->service->recalculateAllSizes(1, 2);

        $this->assertSame(0, $result['total']['processed']);
    }

    public function testRecalculateAllSizesThrowsWhenObjectRecalcFails(): void
    {
        $this->objectMapper
            ->method('findAll')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to recalculate all sizes');

        $this->service->recalculateAllSizes();
    }

    public function testRecalculateAllSizesThrowsWhenLogRecalcFails(): void
    {
        $this->objectMapper->method('findAll')->willReturn([]);
        $this->auditTrailMapper
            ->method('findAll')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to recalculate all sizes');

        $this->service->recalculateAllSizes();
    }

    // ========== getRegistersWithSchemas ==========

    public function testGetRegistersWithSchemasReturnsStructuredData(): void
    {
        $register = $this->createRegisterEntity(1, 'Test Register');
        $schema = $this->createSchemaEntity(10, 'Test Schema');

        $this->registerMapper
            ->method('findAll')
            ->willReturn([$register]);

        $this->registerMapper
            ->method('getSchemasByRegisterId')
            ->willReturn([$schema]);

        $this->setupStatsMappers();

        $result = $this->service->getRegistersWithSchemas();

        // First entry: system totals.
        $this->assertSame('totals', $result[0]['id']);
        $this->assertSame('System Totals', $result[0]['title']);
        $this->assertArrayHasKey('stats', $result[0]);

        // Second entry: the register with schemas.
        $this->assertSame(1, $result[1]['id']);
        $this->assertCount(1, $result[1]['schemas']);
        $this->assertArrayHasKey('stats', $result[1]);
        $this->assertArrayHasKey('stats', $result[1]['schemas'][0]);

        // Last entry: orphaned.
        $lastIndex = count($result) - 1;
        $this->assertSame('orphaned', $result[$lastIndex]['id']);
        $this->assertSame('Orphaned Items', $result[$lastIndex]['title']);
    }

    public function testGetRegistersWithSchemasFiltersByRegisterId(): void
    {
        $register = $this->createRegisterEntity(5, 'Filtered Register');
        $schema = $this->createSchemaEntity(10);

        $this->registerMapper
            ->method('findAll')
            ->willReturn([$register]);

        $this->registerMapper
            ->method('getSchemasByRegisterId')
            ->willReturn([$schema]);

        $this->setupStatsMappers();

        $result = $this->service->getRegistersWithSchemas(5, null);

        // Should still have totals, register, orphaned.
        $this->assertSame('totals', $result[0]['id']);
        $this->assertGreaterThanOrEqual(3, count($result));
    }

    public function testGetRegistersWithSchemasFiltersBySchemaId(): void
    {
        $register = $this->createRegisterEntity(1);
        $schema10 = $this->createSchemaEntity(10, 'Keep');
        $schema20 = $this->createSchemaEntity(20, 'Skip');

        $this->registerMapper
            ->method('findAll')
            ->willReturn([$register]);

        $this->registerMapper
            ->method('getSchemasByRegisterId')
            ->willReturn([$schema10, $schema20]);

        $this->setupStatsMappers();

        $result = $this->service->getRegistersWithSchemas(null, 10);

        // Only schema 10 should be included.
        $this->assertCount(1, $result[1]['schemas']);
        $this->assertSame(10, $result[1]['schemas'][0]['id']);
    }

    public function testGetRegistersWithSchemasExcludesNonMatchingSchemas(): void
    {
        $register = $this->createRegisterEntity(1);
        $schema10 = $this->createSchemaEntity(10);
        $schema20 = $this->createSchemaEntity(20);

        $this->registerMapper
            ->method('findAll')
            ->willReturn([$register]);

        $this->registerMapper
            ->method('getSchemasByRegisterId')
            ->willReturn([$schema10, $schema20]);

        $this->setupStatsMappers();

        // Filter for schema 99, which doesn't exist.
        $result = $this->service->getRegistersWithSchemas(null, 99);

        // Register should have 0 schemas since none match.
        $this->assertCount(0, $result[1]['schemas']);
    }

    public function testGetRegistersWithSchemasNoRegisters(): void
    {
        $this->registerMapper
            ->method('findAll')
            ->willReturn([]);

        $this->setupStatsMappers();

        // getOrphanedStats requires these too.
        $result = $this->service->getRegistersWithSchemas();

        // Only totals and orphaned.
        $this->assertCount(2, $result);
        $this->assertSame('totals', $result[0]['id']);
        $this->assertSame('orphaned', $result[1]['id']);
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

    public function testGetRegistersWithSchemasStatsErrorReturnsZeroes(): void
    {
        $register = $this->createRegisterEntity(1);

        $this->registerMapper
            ->method('findAll')
            ->willReturn([$register]);

        $this->registerMapper
            ->method('getSchemasByRegisterId')
            ->willReturn([]);

        // objectMapper.getStatistics throws, triggering the catch in getStats.
        $this->objectMapper
            ->method('getStatistics')
            ->willThrowException(new Exception('stats error'));

        $result = $this->service->getRegistersWithSchemas();

        // Totals should have zero stats due to error fallback.
        $this->assertSame(0, $result[0]['stats']['objects']['total']);
        $this->assertSame(0, $result[0]['stats']['logs']['total']);
        $this->assertSame(0, $result[0]['stats']['webhookLogs']['total']);
        $this->assertSame(0, $result[0]['stats']['files']['total']);
    }

    // ========== calculate ==========

    public function testCalculateReturnsFullResponseNoFilters(): void
    {
        // No register/schema means fetchRegister/fetchSchema return null.
        $this->objectMapper->method('findAll')->willReturn([]);
        $this->auditTrailMapper->method('findAll')->willReturn([]);

        $result = $this->service->calculate();

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertNull($result['scope']['register']);
        $this->assertNull($result['scope']['schema']);
        $this->assertSame(0, $result['results']['total']['processed']);
        $this->assertSame(0.0, $result['summary']['success_rate']);
    }

    public function testCalculateWithRegisterId(): void
    {
        $register = $this->createRegisterEntity(1, 'My Register');

        $this->registerMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($register);

        $this->objectMapper->method('findAll')->willReturn([]);
        $this->auditTrailMapper->method('findAll')->willReturn([]);

        $result = $this->service->calculate(1);

        $this->assertSame(1, $result['scope']['register']['id']);
        $this->assertSame('My Register', $result['scope']['register']['title']);
        $this->assertNull($result['scope']['schema']);
    }

    public function testCalculateWithRegisterAndSchemaId(): void
    {
        $register = $this->createRegisterEntity(1, 'Reg');

        $schema = $this->createSchemaEntity(10, 'Sch');

        $this->registerMapper
            ->method('find')
            ->with(1)
            ->willReturn($register);

        $this->schemaMapper
            ->method('find')
            ->with(10)
            ->willReturn($schema);

        $this->objectMapper->method('findAll')->willReturn([]);
        $this->auditTrailMapper->method('findAll')->willReturn([]);

        $result = $this->service->calculate(1, 10);

        $this->assertSame(1, $result['scope']['register']['id']);
        $this->assertSame(10, $result['scope']['schema']['id']);
    }

    public function testCalculateWithSchemaNotBelongingToRegisterThrows(): void
    {
        $register = $this->createRegisterEntity(1, 'Reg', [10, 20]);
        // Register has schemas [10, 20], but we request schema 99.
        $schema = $this->createSchemaEntity(99);

        $this->registerMapper
            ->method('find')
            ->willReturn($register);

        $this->schemaMapper
            ->method('find')
            ->willReturn($schema);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Size calculation failed');

        $this->service->calculate(1, 99);
    }

    public function testCalculateWithNonExistentRegisterThrows(): void
    {
        $this->registerMapper
            ->method('find')
            ->willThrowException(new Exception('Not found'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Size calculation failed');

        $this->service->calculate(999);
    }

    public function testCalculateWithNonExistentSchemaThrows(): void
    {
        $register = $this->createRegisterEntity(1);

        $this->registerMapper
            ->method('find')
            ->willReturn($register);

        $this->schemaMapper
            ->method('find')
            ->willThrowException(new Exception('Not found'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Size calculation failed');

        $this->service->calculate(1, 999);
    }

    public function testCalculateSuccessRateWithProcessed(): void
    {
        $obj1 = $this->createObjectEntity(1);
        $obj2 = $this->createObjectEntity(2);

        $this->objectMapper
            ->method('findAll')
            ->willReturn([$obj1, $obj2]);

        $callCount = 0;
        $this->objectMapper
            ->method('update')
            ->willReturnCallback(function ($obj) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new Exception('fail');
                }
                return $obj;
            });

        $this->auditTrailMapper->method('findAll')->willReturn([]);

        $result = $this->service->calculate();

        // processed counts only successful updates, failed counts exceptions.
        // obj1 throws (failed=1), obj2 succeeds (processed=1), logs=0.
        // total processed=1, total failed=1.
        // success_rate = (1-1)/1*100 = 0.0
        $this->assertSame(1, $result['summary']['total_processed']);
        $this->assertSame(1, $result['summary']['total_failed']);
        $this->assertSame(0.0, $result['summary']['success_rate']);
    }

    public function testCalculateSuccessRateWithAllSuccessful(): void
    {
        $obj1 = $this->createMock(ObjectEntity::class);
        $log1 = $this->createMock(AuditTrail::class);

        $this->objectMapper->method('findAll')->willReturn([$obj1]);
        $this->objectMapper->method('update')->willReturnArgument(0);

        $this->auditTrailMapper->method('findAll')->willReturn([$log1]);
        $this->auditTrailMapper->method('update')->willReturnArgument(0);

        $result = $this->service->calculate();

        $this->assertSame(2, $result['summary']['total_processed']);
        $this->assertSame(0, $result['summary']['total_failed']);
        $this->assertSame(100.0, $result['summary']['success_rate']);
    }

    public function testCalculateWithSchemaIdOnlyNoRegister(): void
    {
        $schema = $this->createSchemaEntity(10, 'Solo Schema');

        $this->schemaMapper
            ->method('find')
            ->with(10)
            ->willReturn($schema);

        $this->objectMapper->method('findAll')->willReturn([]);
        $this->auditTrailMapper->method('findAll')->willReturn([]);

        // No register means fetchSchema won't check in_array.
        $result = $this->service->calculate(null, 10);

        $this->assertNull($result['scope']['register']);
        $this->assertSame(10, $result['scope']['schema']['id']);
        $this->assertSame('Solo Schema', $result['scope']['schema']['title']);
    }

    // ========== getAuditTrailActionChartData ==========

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

    public function testGetAuditTrailActionChartDataWithDateFilters(): void
    {
        $from = new DateTime('2024-01-01');
        $till = new DateTime('2024-12-31');
        $expected = [
            'labels' => ['2024-06'],
            'series' => [['data' => [3], 'name' => 'update']],
        ];

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('getActionChartData')
            ->willReturn($expected);

        $result = $this->service->getAuditTrailActionChartData($from, $till, 1, 2);

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

    // ========== getObjectsByRegisterChartData ==========

    public function testGetObjectsByRegisterChartDataReturnsData(): void
    {
        $expected = [
            'labels' => ['Register A', 'Register B'],
            'series' => [15, 25],
        ];

        $this->objectMapper
            ->expects($this->once())
            ->method('getRegisterChartData')
            ->willReturn($expected);

        $result = $this->service->getObjectsByRegisterChartData();

        $this->assertSame($expected, $result);
    }

    public function testGetObjectsByRegisterChartDataWithFilters(): void
    {
        $expected = ['labels' => ['Reg 1'], 'series' => [5]];

        $this->objectMapper
            ->method('getRegisterChartData')
            ->willReturn($expected);

        $result = $this->service->getObjectsByRegisterChartData(1, 2);

        $this->assertSame($expected, $result);
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

    // ========== getObjectsBySchemaChartData ==========

    public function testGetObjectsBySchemaChartDataReturnsData(): void
    {
        $expected = [
            'labels' => ['Schema X'],
            'series' => [42],
        ];

        $this->objectMapper
            ->expects($this->once())
            ->method('getSchemaChartData')
            ->willReturn($expected);

        $result = $this->service->getObjectsBySchemaChartData();

        $this->assertSame($expected, $result);
    }

    public function testGetObjectsBySchemaChartDataWithFilters(): void
    {
        $expected = ['labels' => ['S'], 'series' => [1]];

        $this->objectMapper
            ->method('getSchemaChartData')
            ->willReturn($expected);

        $result = $this->service->getObjectsBySchemaChartData(1, 2);

        $this->assertSame($expected, $result);
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

    // ========== getObjectsBySizeChartData ==========

    public function testGetObjectsBySizeChartDataReturnsData(): void
    {
        $expected = [
            'labels' => ['0-1 KB', '1-10 KB', '10-100 KB', '100 KB-1 MB', '> 1 MB'],
            'series' => [10, 20, 5, 2, 1],
        ];

        $this->objectMapper
            ->expects($this->once())
            ->method('getSizeDistributionChartData')
            ->willReturn($expected);

        $result = $this->service->getObjectsBySizeChartData();

        $this->assertSame($expected, $result);
    }

    public function testGetObjectsBySizeChartDataWithFilters(): void
    {
        $expected = ['labels' => ['0-1 KB'], 'series' => [3]];

        $this->objectMapper
            ->method('getSizeDistributionChartData')
            ->willReturn($expected);

        $result = $this->service->getObjectsBySizeChartData(1, 2);

        $this->assertSame($expected, $result);
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

    // ========== getAuditTrailStatistics ==========

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

    public function testGetAuditTrailStatisticsWithFilters(): void
    {
        $expected = ['total' => 5, 'creates' => 2, 'updates' => 1, 'deletes' => 1, 'reads' => 1];

        $this->auditTrailMapper
            ->method('getDetailedStatistics')
            ->willReturn($expected);

        $result = $this->service->getAuditTrailStatistics(1, 2, 48);

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
        $this->assertSame(0, $result['updates']);
        $this->assertSame(0, $result['deletes']);
        $this->assertSame(0, $result['reads']);
    }

    // ========== getAuditTrailActionDistribution ==========

    public function testGetAuditTrailActionDistributionReturnsData(): void
    {
        $expected = [
            'actions' => [
                ['name' => 'create', 'count' => 50],
                ['name' => 'update', 'count' => 30],
            ],
        ];

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('getActionDistribution')
            ->willReturn($expected);

        $result = $this->service->getAuditTrailActionDistribution();

        $this->assertSame($expected, $result);
    }

    public function testGetAuditTrailActionDistributionWithFilters(): void
    {
        $expected = ['actions' => [['name' => 'delete', 'count' => 3]]];

        $this->auditTrailMapper
            ->method('getActionDistribution')
            ->willReturn($expected);

        $result = $this->service->getAuditTrailActionDistribution(1, 2, 48);

        $this->assertSame($expected, $result);
    }

    public function testGetAuditTrailActionDistributionReturnsEmptyOnError(): void
    {
        $this->auditTrailMapper
            ->method('getActionDistribution')
            ->willThrowException(new Exception('error'));

        $result = $this->service->getAuditTrailActionDistribution();

        $this->assertSame([], $result['actions']);
    }

    // ========== getMostActiveObjects ==========

    public function testGetMostActiveObjectsReturnsData(): void
    {
        $expected = [
            'objects' => [
                ['id' => 1, 'name' => 'Object 1', 'count' => 50],
                ['id' => 2, 'name' => 'Object 2', 'count' => 30],
            ],
        ];

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('getMostActiveObjects')
            ->willReturn($expected);

        $result = $this->service->getMostActiveObjects();

        $this->assertSame($expected, $result);
    }

    public function testGetMostActiveObjectsWithFilters(): void
    {
        $expected = ['objects' => [['id' => 5, 'name' => 'Obj', 'count' => 10]]];

        $this->auditTrailMapper
            ->method('getMostActiveObjects')
            ->willReturn($expected);

        $result = $this->service->getMostActiveObjects(1, 2, 5, 48);

        $this->assertSame($expected, $result);
    }

    public function testGetMostActiveObjectsReturnsEmptyOnError(): void
    {
        $this->auditTrailMapper
            ->method('getMostActiveObjects')
            ->willThrowException(new Exception('error'));

        $result = $this->service->getMostActiveObjects();

        $this->assertSame([], $result['objects']);
    }

    // ========== getOrphanedStats (tested through getRegistersWithSchemas) ==========

    public function testOrphanedStatsWithMultipleRegistersAndSchemas(): void
    {
        $register1 = $this->createRegisterEntity(1, 'Reg 1');
        $register2 = $this->createRegisterEntity(2, 'Reg 2');
        $schema10 = $this->createSchemaEntity(10);
        $schema20 = $this->createSchemaEntity(20);

        $this->registerMapper
            ->method('findAll')
            ->willReturn([$register1, $register2]);

        $this->registerMapper
            ->method('getSchemasByRegisterId')
            ->willReturnCallback(function (int $id) use ($schema10, $schema20) {
                if ($id === 1) {
                    return [$schema10];
                }
                if ($id === 2) {
                    return [$schema20];
                }
                return [];
            });

        $this->setupStatsMappers();

        $result = $this->service->getRegistersWithSchemas();

        // Should have: totals, register1, register2, orphaned.
        $this->assertCount(4, $result);
        $lastIndex = count($result) - 1;
        $this->assertSame('orphaned', $result[$lastIndex]['id']);
        $this->assertArrayHasKey('objects', $result[$lastIndex]['stats']);
        $this->assertArrayHasKey('logs', $result[$lastIndex]['stats']);
        $this->assertArrayHasKey('files', $result[$lastIndex]['stats']);
    }

    public function testOrphanedStatsErrorFallbackReturnsZeroes(): void
    {
        $register = $this->createRegisterEntity(1);

        // First findAll returns register, second (for orphaned) should also work
        // but getSchemasByRegisterId throws to trigger orphaned error.
        $findAllCallCount = 0;
        $this->registerMapper
            ->method('findAll')
            ->willReturnCallback(function () use ($register, &$findAllCallCount) {
                $findAllCallCount++;
                if ($findAllCallCount <= 2) {
                    return [$register];
                }
                return [$register];
            });

        // getSchemasByRegisterId throws on second call (orphaned stats).
        $schemaCallCount = 0;
        $this->registerMapper
            ->method('getSchemasByRegisterId')
            ->willReturnCallback(function () use (&$schemaCallCount) {
                $schemaCallCount++;
                if ($schemaCallCount > 1) {
                    throw new Exception('DB error during orphaned');
                }
                return [];
            });

        $this->setupStatsMappers();

        $result = $this->service->getRegistersWithSchemas();

        // Orphaned stats should fall back to zeroes.
        $lastIndex = count($result) - 1;
        $this->assertSame('orphaned', $result[$lastIndex]['id']);
        $this->assertSame(0, $result[$lastIndex]['stats']['objects']['total']);
        $this->assertSame(0, $result[$lastIndex]['stats']['logs']['total']);
    }

    // ========== getStats error branch (private, tested through getRegistersWithSchemas) ==========

    public function testGetStatsErrorBranchReturnsZeroStructure(): void
    {
        $register = $this->createRegisterEntity(1);

        $this->registerMapper
            ->method('findAll')
            ->willReturn([$register]);

        $this->registerMapper
            ->method('getSchemasByRegisterId')
            ->willReturn([]);

        // Make getStatistics throw on objectMapper to trigger catch block.
        $this->objectMapper
            ->method('getStatistics')
            ->willThrowException(new Exception('Stats error'));

        $result = $this->service->getRegistersWithSchemas();

        // Check that the totals stats have the zero-fallback structure.
        $stats = $result[0]['stats'];
        $this->assertSame(0, $stats['objects']['total']);
        $this->assertSame(0, $stats['objects']['size']);
        $this->assertSame(0, $stats['objects']['invalid']);
        $this->assertSame(0, $stats['objects']['deleted']);
        $this->assertSame(0, $stats['objects']['locked']);
        $this->assertSame(0, $stats['objects']['published']);
        $this->assertSame(0, $stats['logs']['total']);
        $this->assertSame(0, $stats['logs']['size']);
        $this->assertSame(0, $stats['webhookLogs']['total']);
        $this->assertSame(0, $stats['webhookLogs']['size']);
        $this->assertSame(0, $stats['files']['total']);
        $this->assertSame(0, $stats['files']['size']);
    }

    // ========== webhookLogStats missing 'total' key ==========

    public function testGetStatsHandlesWebhookLogsMissingTotal(): void
    {
        $this->registerMapper->method('findAll')->willReturn([]);
        $this->registerMapper->method('getSchemasByRegisterId')->willReturn([]);

        $this->objectMapper->method('getStatistics')
            ->willReturn($this->defaultObjectStats());
        $this->auditTrailMapper->method('getStatistics')
            ->willReturn($this->defaultLogStats());
        // Return empty array without 'total' key.
        $this->webhookLogMapper->method('getStatistics')
            ->willReturn([]);

        $result = $this->service->getRegistersWithSchemas();

        // webhookLogs total should default to 0 via ?? operator.
        $this->assertSame(0, $result[0]['stats']['webhookLogs']['total']);
    }
}
