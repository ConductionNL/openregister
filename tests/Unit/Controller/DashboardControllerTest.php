<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Search\Controller\DashboardController;
use DateTime;
use OCA\OpenRegister\Search\Service\ActivityWindow;
use OCA\OpenRegister\Search\Service\DashboardAuditReads;
use OCA\OpenRegister\Search\Service\DashboardPeriod;
use OCA\OpenRegister\Search\Service\DashboardScope;
use OCA\OpenRegister\Search\Service\DashboardService;
use OCA\OpenRegister\Search\Service\DashboardSizes;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DashboardControllerTest extends TestCase {
	private DashboardController $controller;
	private IRequest&MockObject $request;
	private DashboardService&MockObject $dashboardService;
	private DashboardSizes&MockObject $sizes;
	private DashboardAuditReads&MockObject $auditReads;
	private LoggerInterface&MockObject $logger;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->dashboardService = $this->createMock(DashboardService::class);
		$this->sizes = $this->createMock(DashboardSizes::class);
		$this->auditReads = $this->createMock(DashboardAuditReads::class);
		$this->dashboardService->method('sizes')->willReturn($this->sizes);
		$this->dashboardService->method('auditReads')->willReturn($this->auditReads);
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
			$this->request,
			new \OCA\OpenRegister\Search\Controller\Support\DashboardControllerServices(
				$this->dashboardService,
				new \OCA\OpenRegister\Controller\Support\ControllerSession($this->userSession, $this->groupManager, $this->logger)
			)
		);
	}

	/**
	 * The dashboard counts never cover more than the caller may read: a signed-in
	 * non-admin is refused every count and chart, and nothing is aggregated for them.
	 *
	 * @spec openspec/changes/audit-and-dashboard-screens-stated/specs/built-in-dashboards/spec.md#REQ-ADS-002
	 */
	public function testAnalyticsEndpointsRejectNonAdmin(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($bob);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		$controller = new DashboardController(
			$this->request,
			new \OCA\OpenRegister\Search\Controller\Support\DashboardControllerServices(
				$this->dashboardService,
				new \OCA\OpenRegister\Controller\Support\ControllerSession($session, $groupManager, $this->logger)
			)
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
		$this->assertEquals(403, $controller->getPanels()->getStatus());
	}

	public function testPage(): void {
		$result = $this->controller->page();
		$this->assertInstanceOf(TemplateResponse::class, $result);
		$this->assertSame('index', $result->getTemplateName());
		$this->assertSame('openregister', $result->getApp());
		$this->assertSame(
			"'self'",
			\OCA\OpenRegister\Tests\Unit\Controller\UiControllerTest::connectSources(policy: $result->getContentSecurityPolicy()->buildPolicy())
		);
	}

	/**
	 * The SPA catch-all serves the same shell as page().
	 *
	 * `dashboard#catchAll` at GET /{path} is what lets a deep link such as
	 * /registers reach the SPA at all; before it, only `/` was routed and every
	 * sub-path 404'd at the server, which is why this app ran on hash routing.
	 * Asserting equality with page() rather than merely "returns a response"
	 * pins the delegation, so a rewrite that stops delegating has to say so.
	 */
	public function testCatchAllServesTheSameShellAsPage(): void {
		$result = $this->controller->catchAll();

		$this->assertInstanceOf(TemplateResponse::class, $result);
		$this->assertEquals($this->controller->page()->getTemplateName(), $result->getTemplateName());
		$this->assertEquals($this->controller->page()->getRenderAs(), $result->getRenderAs());
	}

	public function testIndexSuccess(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->dashboardService->method('getRegistersWithSchemas')->willReturn([]);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('registers', $data);
	}

	public function testIndexException(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->dashboardService->method('getRegistersWithSchemas')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->index();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testCalculateSuccess(): void {
		$this->sizes->method('calculate')->willReturn(['status' => 'success']);

		$result = $this->controller->calculate(1, 2);

		$this->assertEquals(200, $result->getStatus());
	}

	public function testCalculateException(): void {
		$this->sizes->method('calculate')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->calculate();

		$this->assertEquals(500, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals('error', $data['status']);
	}

	public function testGetAuditTrailActionChartSuccess(): void {
		$this->auditReads->method('getAuditTrailActionChartData')
			->willReturn(['labels' => [], 'series' => []]);

		$result = $this->controller->getAuditTrailActionChart();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testGetAuditTrailActionChartException(): void {
		$this->auditReads->method('getAuditTrailActionChartData')
			->willThrowException(new \Exception('Chart error'));

		$result = $this->controller->getAuditTrailActionChart();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testGetObjectsByRegisterChartSuccess(): void {
		$this->dashboardService->method('getObjectsByRegisterChartData')
			->willReturn(['labels' => [], 'series' => []]);

		$result = $this->controller->getObjectsByRegisterChart();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testGetObjectsBySchemaChartSuccess(): void {
		$this->dashboardService->method('getObjectsBySchemaChartData')
			->willReturn(['labels' => [], 'series' => []]);

		$result = $this->controller->getObjectsBySchemaChart();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testGetObjectsBySizeChartSuccess(): void {
		$this->dashboardService->method('getObjectsBySizeChartData')
			->willReturn(['labels' => [], 'series' => []]);

		$result = $this->controller->getObjectsBySizeChart();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testGetAuditTrailStatisticsSuccess(): void {
		$this->auditReads->method('getAuditTrailStatistics')
			->willReturn(['total' => 100]);

		$result = $this->controller->getAuditTrailStatistics();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testGetAuditTrailActionDistributionSuccess(): void {
		$this->auditReads->method('getAuditTrailActionDistribution')
			->willReturn(['actions' => []]);

		$result = $this->controller->getAuditTrailActionDistribution();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testAuditReadsHandTheirFiltersToTheService(): void {
		$scope = new DashboardScope(registerId: 1, schemaId: 2);
		$this->auditReads->expects($this->once())->method('getAuditTrailActionChartData')
			->with(new DashboardPeriod($scope, new DateTime('2026-01-01'), new DateTime('2026-01-31')))->willReturn([]);
		$this->auditReads->expects($this->once())->method('getAuditTrailStatistics')
			->with($scope, 48)->willReturn([]);
		$this->auditReads->expects($this->once())->method('getAuditTrailActionDistribution')
			->with($scope, 72)->willReturn([]);
		$this->auditReads->expects($this->once())->method('getMostActiveObjects')
			->with(new ActivityWindow($scope, 5, 96))->willReturn([]);

		$this->controller->getAuditTrailActionChart('2026-01-01', '2026-01-31', 1, 2);
		$this->controller->getAuditTrailStatistics(1, 2, 48);
		$this->controller->getAuditTrailActionDistribution(1, 2, 72);
		$this->controller->getMostActiveObjects(1, 2, 5, 96);
	}

	public function testGetMostActiveObjectsSuccess(): void {
		$this->auditReads->method('getMostActiveObjects')
			->willReturn(['objects' => []]);

		$result = $this->controller->getMostActiveObjects();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testGetMostActiveObjectsException(): void {
		$this->auditReads->method('getMostActiveObjects')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->getMostActiveObjects();

		$this->assertEquals(500, $result->getStatus());
	}

	/**
	 * One read answers every chart and statistics panel, each with the status
	 * and body its own endpoint answers, and hands each its filters.
	 *
	 * @spec openspec/changes/dashboard-panels-in-one-read/specs/built-in-dashboards/spec.md
	 */
	public function testGetPanelsAnswersEveryPanelLikeItsOwnEndpoint(): void {
		$scope = new DashboardScope(registerId: 1, schemaId: 2);
		$this->auditReads->expects($this->once())->method('getAuditTrailActionChartData')
			->with(new DashboardPeriod($scope, new DateTime('2026-01-01'), new DateTime('2026-01-31')))
			->willReturn(['labels' => ['a'], 'series' => []]);
		$this->dashboardService->expects($this->once())->method('getObjectsByRegisterChartData')
			->with(1, 2)->willReturn(['labels' => ['r'], 'series' => [3]]);
		$this->dashboardService->expects($this->once())->method('getObjectsBySchemaChartData')
			->with(1, 2)->willReturn(['labels' => ['s'], 'series' => [4]]);
		$this->dashboardService->expects($this->once())->method('getObjectsBySizeChartData')
			->with(1, 2)->willReturn(['labels' => ['0-1 KB'], 'series' => [5]]);
		$this->auditReads->expects($this->once())->method('getAuditTrailStatistics')
			->with($scope, 48)->willReturn(['total' => 100]);
		$this->auditReads->expects($this->once())->method('getAuditTrailActionDistribution')
			->with($scope, 48)->willReturn(['actions' => []]);
		$this->auditReads->expects($this->once())->method('getMostActiveObjects')
			->with(new ActivityWindow($scope, 5, 48))->willReturn(['objects' => []]);

		$result = $this->controller->getPanels('2026-01-01', '2026-01-31', 1, 2, 48, 5);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(
			[
				'auditTrailActions'  => ['status' => 200, 'data' => ['labels' => ['a'], 'series' => []]],
				'objectsByRegister'  => ['status' => 200, 'data' => ['labels' => ['r'], 'series' => [3]]],
				'objectsBySchema'    => ['status' => 200, 'data' => ['labels' => ['s'], 'series' => [4]]],
				'objectsBySize'      => ['status' => 200, 'data' => ['labels' => ['0-1 KB'], 'series' => [5]]],
				'auditTrailStats'    => ['status' => 200, 'data' => ['total' => 100]],
				'actionDistribution' => ['status' => 200, 'data' => ['actions' => []]],
				'mostActiveObjects'  => ['status' => 200, 'data' => ['objects' => []]],
			],
			$result->getData()
		);
	}

	/**
	 * A failing panel answers its own 500 and leaves the other panels alone.
	 *
	 * @spec openspec/changes/dashboard-panels-in-one-read/specs/built-in-dashboards/spec.md
	 */
	public function testGetPanelsKeepsAFailingPanelToItself(): void {
		$this->dashboardService->method('getObjectsBySchemaChartData')->willThrowException(new \Exception('Chart error'));
		$this->dashboardService->method('getObjectsByRegisterChartData')->willReturn(['labels' => [], 'series' => []]);

		$data = $this->controller->getPanels()->getData();

		$this->assertSame(['status' => 500, 'data' => ['error' => 'Chart error']], $data['objectsBySchema']);
		$this->assertSame(200, $data['objectsByRegister']['status']);
		$this->assertSame(200, $data['auditTrailStats']['status']);
		$this->assertCount(7, $data);
	}

	/**
	 * A date the audit chart cannot read fails that panel only.
	 *
	 * @spec openspec/changes/dashboard-panels-in-one-read/specs/built-in-dashboards/spec.md
	 */
	public function testGetPanelsKeepsABadDateToTheAuditChart(): void {
		$data = $this->controller->getPanels('not a date')->getData();

		$this->assertSame(500, $data['auditTrailActions']['status']);
		$this->assertSame(200, $data['objectsBySize']['status']);
	}

	/**
	 * @dataProvider chartExceptionProvider
	 */
	public function testChartMethodsHandleExceptions(string $method, string $serviceMethod): void {
		$target = $this->dashboardService;
		if (method_exists(DashboardAuditReads::class, $serviceMethod) === true) {
			$target = $this->auditReads;
		}

		$target->method($serviceMethod)
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->$method();

		$this->assertEquals(500, $result->getStatus());
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function chartExceptionProvider(): array {
		return [
			'objectsByRegister' => ['getObjectsByRegisterChart', 'getObjectsByRegisterChartData'],
			'objectsBySchema' => ['getObjectsBySchemaChart', 'getObjectsBySchemaChartData'],
			'objectsBySize' => ['getObjectsBySizeChart', 'getObjectsBySizeChartData'],
			'auditTrailStats' => ['getAuditTrailStatistics', 'getAuditTrailStatistics'],
			'actionDistribution' => ['getAuditTrailActionDistribution', 'getAuditTrailActionDistribution'],
		];
	}
}
