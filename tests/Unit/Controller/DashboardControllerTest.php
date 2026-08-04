<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\DashboardController;
use OCA\OpenRegister\Service\DashboardService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DashboardControllerTest extends TestCase
{
    private DashboardController $controller;
    private IRequest&MockObject $request;
    private DashboardService&MockObject $dashboardService;
    private LoggerInterface&MockObject $logger;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->dashboardService = $this->createMock(DashboardService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        // The dashboard analytics aggregate tenant-wide data and are admin-only;
        // default to an admin so the happy-path tests exercise the payloads.
        $admin = $this->createMock(IUser::class);
        $admin->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($admin);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->controller = new DashboardController(
            'openregister',
            $this->request,
            $this->dashboardService,
            $this->logger,
            $this->userSession,
            $this->groupManager
        );
    }

    public function testAnalyticsEndpointsRejectNonAdmin(): void
    {
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($bob);
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);

        $controller = new DashboardController(
            'openregister',
            $this->request,
            $this->dashboardService,
            $this->logger,
            $session,
            $groupManager
        );

        // None of the tenant-wide aggregations must be computed for a non-admin.
        $this->dashboardService->expects($this->never())->method($this->anything());

        $this->assertEquals(403, $controller->index()->getStatus());
        $this->assertEquals(403, $controller->calculate()->getStatus());
        $this->assertEquals(403, $controller->getAuditTrailActionChart()->getStatus());
        $this->assertEquals(403, $controller->getObjectsByRegisterChart()->getStatus());
        $this->assertEquals(403, $controller->getObjectsBySchemaChart()->getStatus());
        $this->assertEquals(403, $controller->getObjectsBySizeChart()->getStatus());
        $this->assertEquals(403, $controller->getAuditTrailStatistics()->getStatus());
        $this->assertEquals(403, $controller->getAuditTrailActionDistribution()->getStatus());
        $this->assertEquals(403, $controller->getMostActiveObjects()->getStatus());
    }

    public function testPage(): void
    {
        $result = $this->controller->page();
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testIndexSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->dashboardService->method('getRegistersWithSchemas')->willReturn([]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('registers', $data);
    }

    public function testIndexException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->dashboardService->method('getRegistersWithSchemas')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testCalculateSuccess(): void
    {
        $this->dashboardService->method('calculate')->willReturn(['status' => 'success']);

        $result = $this->controller->calculate(1, 2);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCalculateException(): void
    {
        $this->dashboardService->method('calculate')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->calculate();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('error', $data['status']);
    }

    public function testGetAuditTrailActionChartSuccess(): void
    {
        $this->dashboardService->method('getAuditTrailActionChartData')
            ->willReturn(['labels' => [], 'series' => []]);

        $result = $this->controller->getAuditTrailActionChart();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetAuditTrailActionChartException(): void
    {
        $this->dashboardService->method('getAuditTrailActionChartData')
            ->willThrowException(new \Exception('Chart error'));

        $result = $this->controller->getAuditTrailActionChart();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testGetObjectsByRegisterChartSuccess(): void
    {
        $this->dashboardService->method('getObjectsByRegisterChartData')
            ->willReturn(['labels' => [], 'series' => []]);

        $result = $this->controller->getObjectsByRegisterChart();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetObjectsBySchemaChartSuccess(): void
    {
        $this->dashboardService->method('getObjectsBySchemaChartData')
            ->willReturn(['labels' => [], 'series' => []]);

        $result = $this->controller->getObjectsBySchemaChart();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetObjectsBySizeChartSuccess(): void
    {
        $this->dashboardService->method('getObjectsBySizeChartData')
            ->willReturn(['labels' => [], 'series' => []]);

        $result = $this->controller->getObjectsBySizeChart();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetAuditTrailStatisticsSuccess(): void
    {
        $this->dashboardService->method('getAuditTrailStatistics')
            ->willReturn(['total' => 100]);

        $result = $this->controller->getAuditTrailStatistics();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetAuditTrailActionDistributionSuccess(): void
    {
        $this->dashboardService->method('getAuditTrailActionDistribution')
            ->willReturn(['actions' => []]);

        $result = $this->controller->getAuditTrailActionDistribution();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetMostActiveObjectsSuccess(): void
    {
        $this->dashboardService->method('getMostActiveObjects')
            ->willReturn(['objects' => []]);

        $result = $this->controller->getMostActiveObjects();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetMostActiveObjectsException(): void
    {
        $this->dashboardService->method('getMostActiveObjects')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->getMostActiveObjects();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * @dataProvider chartExceptionProvider
     */
    public function testChartMethodsHandleExceptions(string $method, string $serviceMethod): void
    {
        $this->dashboardService->method($serviceMethod)
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->$method();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function chartExceptionProvider(): array
    {
        return [
            'objectsByRegister' => ['getObjectsByRegisterChart', 'getObjectsByRegisterChartData'],
            'objectsBySchema' => ['getObjectsBySchemaChart', 'getObjectsBySchemaChartData'],
            'objectsBySize' => ['getObjectsBySizeChart', 'getObjectsBySizeChartData'],
            'auditTrailStats' => ['getAuditTrailStatistics', 'getAuditTrailStatistics'],
            'actionDistribution' => ['getAuditTrailActionDistribution', 'getAuditTrailActionDistribution'],
        ];
    }
}
