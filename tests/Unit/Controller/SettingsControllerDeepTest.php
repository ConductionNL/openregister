<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class SettingsControllerDeepTest extends TestCase
{
    private SettingsController $controller;
    private IRequest|MockObject $request;
    private IAppConfig|MockObject $config;
    private IDBConnection|MockObject $db;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private SettingsService|MockObject $settingsService;
    private VectorizationService|MockObject $vectorizationService;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->vectorizationService = $this->createMock(VectorizationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new SettingsController(
            'openregister',
            $this->request,
            $this->config,
            $this->db,
            $this->container,
            $this->appManager,
            $this->settingsService,
            $this->vectorizationService,
            $this->logger
        );
    }

    public function testGetObjectServiceWhenInstalled(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $result = $this->controller->getObjectService();

        $this->assertNull($result);
    }

    public function testGetObjectServiceWhenNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->controller->getObjectService();
    }

    public function testGetConfigurationServiceWhenInstalled(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $mockService = $this->createMock(\OCA\OpenRegister\Service\ConfigurationService::class);
        $this->container->method('get')->willReturn($mockService);

        $result = $this->controller->getConfigurationService();

        $this->assertNotNull($result);
    }

    public function testGetConfigurationServiceWhenNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->controller->getConfigurationService();
    }

    public function testUpdateException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updateSettings')
            ->willThrowException(new Exception('update fail'));

        $response = $this->controller->update();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testLoadException(): void
    {
        $this->settingsService->method('getSettings')
            ->willThrowException(new Exception('load fail'));

        $response = $this->controller->load();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testRebaseException(): void
    {
        $this->settingsService->method('rebase')
            ->willThrowException(new Exception('rebase fail'));

        $response = $this->controller->rebase();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testStatsException(): void
    {
        $this->settingsService->method('getStats')
            ->willThrowException(new Exception('stats fail'));

        $response = $this->controller->stats();

        $this->assertEquals(422, $response->getStatus());
    }

    public function testGetStatisticsCallsStats(): void
    {
        $this->settingsService->method('getStats')
            ->willReturn(['total' => 5]);

        $response = $this->controller->getStatistics();

        $this->assertEquals(200, $response->getStatus());
    }

    public function testUpdatePublishingOptionsException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updatePublishingOptions')
            ->willThrowException(new Exception('publish fail'));

        $response = $this->controller->updatePublishingOptions();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testGetSearchBackendException(): void
    {
        $this->settingsService->method('getSearchBackendConfig')
            ->willThrowException(new Exception('backend fail'));

        $response = $this->controller->getSearchBackend();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testUpdateSearchBackendEmptyBackend(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->updateSearchBackend();

        $this->assertEquals(400, $response->getStatus());
    }

    public function testSemanticSearchEmptyQuery(): void
    {
        $response = $this->controller->semanticSearch('   ');

        $this->assertEquals(400, $response->getStatus());
    }

    public function testSemanticSearchException(): void
    {
        $this->vectorizationService->method('semanticSearch')
            ->willThrowException(new Exception('semantic fail'));

        $response = $this->controller->semanticSearch('test query');

        $this->assertEquals(500, $response->getStatus());
    }

    public function testHybridSearchEmptyQuery(): void
    {
        $response = $this->controller->hybridSearch('   ');

        $this->assertEquals(400, $response->getStatus());
    }

    public function testHybridSearchException(): void
    {
        $this->vectorizationService->method('hybridSearch')
            ->willThrowException(new Exception('hybrid fail'));

        $response = $this->controller->hybridSearch('test query');

        $this->assertEquals(500, $response->getStatus());
    }

    public function testGetVersionInfoException(): void
    {
        $this->settingsService->method('getVersionInfoOnly')
            ->willThrowException(new Exception('version fail'));

        $response = $this->controller->getVersionInfo();

        $this->assertEquals(500, $response->getStatus());
    }
}
