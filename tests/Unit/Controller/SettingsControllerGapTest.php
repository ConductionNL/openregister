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
 * Gap tests for SettingsController covering uncovered methods and branches.
 */
class SettingsControllerGapTest extends TestCase {
	private SettingsController $controller;
	private IRequest&MockObject $request;
	private IAppConfig&MockObject $config;
	private IDBConnection&MockObject $db;
	private ContainerInterface&MockObject $container;
	private IAppManager&MockObject $appManager;
	private SettingsService&MockObject $settingsService;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

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
	 * Test getObjectService when openregister is installed.
	 */
	public function testGetObjectServiceReturnsNullWhenInstalled(): void {
		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister', 'files']);

		$result = $this->controller->getObjectService();

		$this->assertNull($result);
	}

	/**
	 * Test getObjectService when openregister is not installed.
	 */
	public function testGetObjectServiceThrowsWhenNotInstalled(): void {
		$this->appManager->method('getInstalledApps')
			->willReturn(['files', 'contacts']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister service is not available');

		$this->controller->getObjectService();
	}

	/**
	 * Test getConfigurationService when openregister is not installed.
	 */
	public function testGetConfigurationServiceThrowsWhenNotInstalled(): void {
		$this->appManager->method('getInstalledApps')
			->willReturn(['files']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Configuration service is not available');

		$this->controller->getConfigurationService();
	}

	/**
	 * Test load method.
	 */
	public function testLoadReturnsSettings(): void {
		$this->settingsService->method('getSettings')
			->willReturn(['key' => 'value']);

		$result = $this->controller->load();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals('value', $data['key']);
	}

	/**
	 * Test load method exception.
	 */
	public function testLoadException(): void {
		$this->settingsService->method('getSettings')
			->willThrowException(new \Exception('Load error'));

		$result = $this->controller->load();

		$this->assertEquals(500, $result->getStatus());
	}

	/**
	 * Test rebase method.
	 */
	public function testRebaseSuccess(): void {
		$this->settingsService->method('rebase')
			->willReturn(['rebased' => 10]);

		$result = $this->controller->rebase();

		$this->assertEquals(200, $result->getStatus());
	}

	/**
	 * Test rebase exception.
	 */
	public function testRebaseException(): void {
		$this->settingsService->method('rebase')
			->willThrowException(new \Exception('Rebase error'));

		$result = $this->controller->rebase();

		$this->assertEquals(500, $result->getStatus());
	}

	/**
	 * Test getStatistics is alias for stats.
	 */
	public function testGetStatisticsIsAliasForStats(): void {
		$this->settingsService->method('getStats')
			->willReturn(['objects' => 100]);

		$result = $this->controller->getStatistics();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(100, $data['objects']);
	}

	/**
	 * Test getVersionInfo.
	 */
	public function testGetVersionInfo(): void {
		$this->settingsService->method('getVersionInfoOnly')
			->willReturn(['version' => '2.0.0']);

		$result = $this->controller->getVersionInfo();

		$this->assertEquals(200, $result->getStatus());
	}

	/**
	 * Test getVersionInfo exception.
	 */
	public function testGetVersionInfoException(): void {
		$this->settingsService->method('getVersionInfoOnly')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->getVersionInfo();

		$this->assertEquals(500, $result->getStatus());
	}

	/**
	 * Test getSearchBackend.
	 */
	public function testGetSearchBackend(): void {
		$this->settingsService->method('getSearchBackendConfig')
			->willReturn(['active' => 'solr']);

		$result = $this->controller->getSearchBackend();

		$this->assertEquals(200, $result->getStatus());
	}

	/**
	 * Test getSearchBackend exception.
	 */
	public function testGetSearchBackendException(): void {
		$this->settingsService->method('getSearchBackendConfig')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->getSearchBackend();

		$this->assertEquals(500, $result->getStatus());
	}

	/**
	 * Test getDatabaseInfo with cached data.
	 */
	public function testGetDatabaseInfoWithCachedData(): void {
		$this->request->method('getParam')
			->willReturn(false);

		$cachedData = json_encode([
			'database' => ['type' => 'PostgreSQL'],
			'success' => true,
		]);

		$this->config->method('getValueString')
			->with('openregister', 'databaseInfo', '')
			->willReturn($cachedData);

		$result = $this->controller->getDatabaseInfo();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['fromCache']);
	}

	/**
	 * Test getDatabaseInfo exception.
	 */
	public function testGetDatabaseInfoException(): void {
		$this->request->method('getParam')->willReturn('true');

		$this->config->method('getValueString')->willReturn('');

		$this->db->method('getDatabasePlatform')
			->willThrowException(new \Exception('DB Error'));

		$result = $this->controller->getDatabaseInfo();

		$this->assertEquals(500, $result->getStatus());
	}

}
