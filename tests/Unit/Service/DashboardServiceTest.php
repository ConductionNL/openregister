<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\DashboardService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
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
    private ObjectEntityMapper $objectMapper;
    private AuditTrailMapper $auditTrailMapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private IDBConnection $db;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->objectMapper = $this->createMock(ObjectEntityMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
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
     * Test calculate method with no parameters
     */
    public function testCalculateWithNoParameters(): void
    {
        $result = $this->dashboardService->calculate();

        $this->assertIsArray($result);
    }

    /**
     * Test calculate method with register ID
     */
    public function testCalculateWithRegisterId(): void
    {
        $registerId = 1;
        $result = $this->dashboardService->calculate($registerId);

        $this->assertIsArray($result);
    }


    /**
     * Test getAuditTrailStatistics method
     */
    public function testGetAuditTrailStatistics(): void
    {
        $result = $this->dashboardService->getAuditTrailStatistics();

        $this->assertIsArray($result);
    }

    /**
     * Test getAuditTrailStatistics method with parameters
     */
    public function testGetAuditTrailStatisticsWithParameters(): void
    {
        $registerId = 1;
        $schemaId = 2;
        $hours = 48;
        $result = $this->dashboardService->getAuditTrailStatistics($registerId, $schemaId, $hours);

        $this->assertIsArray($result);
    }

    /**
     * Test getAuditTrailActionDistribution method
     */
    public function testGetAuditTrailActionDistribution(): void
    {
        $result = $this->dashboardService->getAuditTrailActionDistribution();

        $this->assertIsArray($result);
    }

    /**
     * Test getMostActiveObjects method
     */
    public function testGetMostActiveObjects(): void
    {
        $result = $this->dashboardService->getMostActiveObjects();

        $this->assertIsArray($result);
    }

    /**
     * Test getMostActiveObjects method with parameters
     */
    public function testGetMostActiveObjectsWithParameters(): void
    {
        $registerId = 1;
        $schemaId = 2;
        $limit = 5;
        $hours = 12;
        $result = $this->dashboardService->getMostActiveObjects($registerId, $schemaId, $limit, $hours);

        $this->assertIsArray($result);
    }

    /**
     * Test getObjectsByRegisterChartData method
     */
    public function testGetObjectsByRegisterChartData(): void
    {
        $result = $this->dashboardService->getObjectsByRegisterChartData();

        $this->assertIsArray($result);
    }

    /**
     * Test getObjectsBySchemaChartData method
     */
    public function testGetObjectsBySchemaChartData(): void
    {
        $result = $this->dashboardService->getObjectsBySchemaChartData();

        $this->assertIsArray($result);
    }

    /**
     * Test getObjectsBySizeChartData method
     */
    public function testGetObjectsBySizeChartData(): void
    {
        $result = $this->dashboardService->getObjectsBySizeChartData();

        $this->assertIsArray($result);
    }

    /**
     * Test getAuditTrailActionChartData method
     */
    public function testGetAuditTrailActionChartData(): void
    {
        $result = $this->dashboardService->getAuditTrailActionChartData();

        $this->assertIsArray($result);
    }

    /**
     * Test getAuditTrailActionChartData method with parameters
     */
    public function testGetAuditTrailActionChartDataWithParameters(): void
    {
        $from = new \DateTime('2024-01-01');
        $till = new \DateTime('2024-01-31');
        $registerId = 1;
        $schemaId = 2;
        $result = $this->dashboardService->getAuditTrailActionChartData($from, $till, $registerId, $schemaId);

        $this->assertIsArray($result);
    }

    /**
     * Test recalculateSizes method
     */
    public function testRecalculateSizes(): void
    {
        $result = $this->dashboardService->recalculateSizes();

        $this->assertIsArray($result);
    }

    /**
     * Test recalculateLogSizes method
     */
    public function testRecalculateLogSizes(): void
    {
        $result = $this->dashboardService->recalculateLogSizes();

        $this->assertIsArray($result);
    }

    /**
     * Test recalculateAllSizes method
     */
    public function testRecalculateAllSizes(): void
    {
        $result = $this->dashboardService->recalculateAllSizes();

        $this->assertIsArray($result);
    }
}