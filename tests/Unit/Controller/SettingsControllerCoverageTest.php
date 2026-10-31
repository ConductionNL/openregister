<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Coverage tests for SettingsController — targets remaining uncovered branches.
 */
class SettingsControllerCoverageTest extends TestCase {
	private SettingsController $controller;
	private IRequest&MockObject $request;
	private IAppConfig&MockObject $config;
	private IDBConnection&MockObject $db;
	private ContainerInterface&MockObject $container;
	private IAppManager&MockObject $appManager;
	private MockObject $settingsService;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->settingsService = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				return vsprintf($text, $parameters);
			}
		);

		$this->controller = new SettingsController(
			'openregister',
			$this->request,
			$this->config,
			$this->db,
			$this->container,
			$this->appManager,
			$this->settingsService,
			$this->logger,
			$l10n
		);
	}

	/**
	 * Create a platform mock with getName returning a specific name.
	 */
	private function createPlatformMock(string $name): object {
		// Use an anonymous class since we need getName to work
		return new class($name) {
			private string $platformName;

			public function __construct(string $name) {
				$this->platformName = $name;
			}

			public function getName(): string {
				return $this->platformName;
			}
		};
	}

	// =========================================================================
	// getConfigurationService — success path
	// =========================================================================

	public function testGetConfigurationServiceReturnsServiceWhenInstalled(): void {
		$configSvc = $this->createMock(\OCA\OpenRegister\Service\ConfigurationService::class);
		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister', 'files']);
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ConfigurationService')
			->willReturn($configSvc);

		$result = $this->controller->getConfigurationService();

		$this->assertSame($configSvc, $result);
	}

	// =========================================================================
	// getDatabaseInfo — with SQLite platform
	// =========================================================================

	public function testGetDatabaseInfoWithSqlitePlatform(): void {
		$this->request->method('getParam')
			->willReturn('true'); // force refresh
		$this->config->method('getValueString')
			->willReturn('');

		$platform = $this->createPlatformMock('sqlite');
		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$result = $this->controller->getDatabaseInfo();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('SQLite', $data['database']['type']);
		$this->assertFalse($data['database']['vectorSupport']);
	}

	public function testGetDatabaseInfoWithMysqlPlatform(): void {
		$this->request->method('getParam')
			->willReturn('true');
		$this->config->method('getValueString')
			->willReturn('');

		$platform = $this->createPlatformMock('mysql');
		$this->db->method('getDatabasePlatform')->willReturn($platform);

		// VERSION() query mock — throws to test the catch branch
		$this->db->method('prepare')
			->willThrowException(new \Exception('No DB'));

		$result = $this->controller->getDatabaseInfo();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('MySQL/MariaDB', $data['database']['type']);
		$this->assertFalse($data['database']['vectorSupport']);
	}

	public function testGetDatabaseInfoWithInvalidCachedJson(): void {
		$this->request->method('getParam')
			->willReturn(false);
		$this->config->method('getValueString')
			->with('openregister', 'databaseInfo', '')
			->willReturn('invalid-json');

		$platform = $this->createPlatformMock('sqlite');
		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$result = $this->controller->getDatabaseInfo();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['fromCache']);
	}

	// =========================================================================
	// refreshDatabaseInfo
	// =========================================================================

	public function testRefreshDatabaseInfoDeletesCache(): void {
		$this->config->expects($this->once())
			->method('deleteKey')
			->with('openregister', 'databaseInfo');

		$this->config->method('getValueString')
			->willReturn('');

		$platform = $this->createPlatformMock('sqlite');
		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$result = $this->controller->refreshDatabaseInfo();

		$this->assertEquals(200, $result->getStatus());
	}

	// =========================================================================
	// getSearchIndexStatus — the /api/settings/search-index inventory endpoint
	// =========================================================================

	public function testGetSearchIndexStatusReturnsInventory(): void {
		$maintenance = $this->createMock(\OCA\OpenRegister\Service\Search\SearchIndexMaintenance::class);
		$maintenance->method('tablesInScope')->willReturn(['oc_openregister_table_1']);
		$maintenance->method('indexNamesFor')->willReturn(['oc_openregister_table_1' => ['idx_a', 'idx_b']]);
		$maintenance->method('supportsConcurrentRebuild')->willReturn(true);
		$maintenance->method('lastRun')->willReturn([]);
		$this->container->method('get')
			->with(\OCA\OpenRegister\Service\Search\SearchIndexMaintenance::class)
			->willReturn($maintenance);

		$result = $this->controller->getSearchIndexStatus();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertSame(1, $data['tableCount']);
		$this->assertSame(2, $data['indexCount']);
		$this->assertTrue($data['concurrentRebuildSupported']);
		$this->assertArrayHasKey('oc_openregister_table_1', $data['tables']);
	}
}
