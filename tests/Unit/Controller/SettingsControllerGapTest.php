<?php

declare(strict_types=1);

namespace Unit\Controller;

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

/**
 * Gap tests for SettingsController covering uncovered methods and branches.
 */
class SettingsControllerGapTest extends TestCase
{
    private SettingsController $controller;
    private IRequest&MockObject $request;
    private IAppConfig&MockObject $config;
    private IDBConnection&MockObject $db;
    private ContainerInterface&MockObject $container;
    private IAppManager&MockObject $appManager;
    private SettingsService&MockObject $settingsService;
    private VectorizationService&MockObject $vectorizationService;
    private LoggerInterface&MockObject $logger;

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

    /**
     * Test getObjectService when openregister is installed.
     */
    public function testGetObjectServiceReturnsNullWhenInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister', 'files']);

        $result = $this->controller->getObjectService();

        $this->assertNull($result);
    }

    /**
     * Test getObjectService when openregister is not installed.
     */
    public function testGetObjectServiceThrowsWhenNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['files', 'contacts']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available');

        $this->controller->getObjectService();
    }

    /**
     * Test getConfigurationService when openregister is not installed.
     */
    public function testGetConfigurationServiceThrowsWhenNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['files']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration service is not available');

        $this->controller->getConfigurationService();
    }

    /**
     * Test load method.
     */
    public function testLoadReturnsSettings(): void
    {
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
    public function testLoadException(): void
    {
        $this->settingsService->method('getSettings')
            ->willThrowException(new \Exception('Load error'));

        $result = $this->controller->load();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test updatePublishingOptions.
     */
    public function testUpdatePublishingOptions(): void
    {
        $this->request->method('getParams')
            ->willReturn(['publish' => true]);
        $this->settingsService->method('updatePublishingOptions')
            ->willReturn(['publish' => true]);

        $result = $this->controller->updatePublishingOptions();

        $this->assertEquals(200, $result->getStatus());
    }

    /**
     * Test updatePublishingOptions exception.
     */
    public function testUpdatePublishingOptionsException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updatePublishingOptions')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->updatePublishingOptions();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test rebase method.
     */
    public function testRebaseSuccess(): void
    {
        $this->settingsService->method('rebase')
            ->willReturn(['rebased' => 10]);

        $result = $this->controller->rebase();

        $this->assertEquals(200, $result->getStatus());
    }

    /**
     * Test rebase exception.
     */
    public function testRebaseException(): void
    {
        $this->settingsService->method('rebase')
            ->willThrowException(new \Exception('Rebase error'));

        $result = $this->controller->rebase();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test getStatistics is alias for stats.
     */
    public function testGetStatisticsIsAliasForStats(): void
    {
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
    public function testGetVersionInfo(): void
    {
        $this->settingsService->method('getVersionInfoOnly')
            ->willReturn(['version' => '2.0.0']);

        $result = $this->controller->getVersionInfo();

        $this->assertEquals(200, $result->getStatus());
    }

    /**
     * Test getVersionInfo exception.
     */
    public function testGetVersionInfoException(): void
    {
        $this->settingsService->method('getVersionInfoOnly')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->getVersionInfo();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test getSearchBackend.
     */
    public function testGetSearchBackend(): void
    {
        $this->settingsService->method('getSearchBackendConfig')
            ->willReturn(['active' => 'solr']);

        $result = $this->controller->getSearchBackend();

        $this->assertEquals(200, $result->getStatus());
    }

    /**
     * Test getSearchBackend exception.
     */
    public function testGetSearchBackendException(): void
    {
        $this->settingsService->method('getSearchBackendConfig')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->getSearchBackend();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test updateSearchBackend with empty backend param (covers validation).
     */
    public function testUpdateSearchBackendEmptyBackend(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $result = $this->controller->updateSearchBackend();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Backend parameter is required', $data['error']);
    }

    /**
     * Test semanticSearch with empty query.
     */
    public function testSemanticSearchEmptyQuery(): void
    {
        $result = $this->controller->semanticSearch('   ');

        $this->assertEquals(400, $result->getStatus());
    }

    /**
     * Test semanticSearch success.
     */
    public function testSemanticSearchSuccess(): void
    {
        $this->vectorizationService->method('semanticSearch')
            ->willReturn([['id' => 1, 'score' => 0.95]]);

        $result = $this->controller->semanticSearch('test query');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['total']);
    }

    /**
     * Test semanticSearch exception.
     */
    public function testSemanticSearchException(): void
    {
        $this->vectorizationService->method('semanticSearch')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->semanticSearch('query');

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test hybridSearch with empty query.
     */
    public function testHybridSearchEmptyQuery(): void
    {
        $result = $this->controller->hybridSearch('  ');

        $this->assertEquals(400, $result->getStatus());
    }

    /**
     * Test hybridSearch success.
     */
    public function testHybridSearchSuccess(): void
    {
        $this->vectorizationService->method('hybridSearch')
            ->willReturn(['results' => [], 'total' => 0]);

        $result = $this->controller->hybridSearch('test query');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Test hybridSearch exception.
     */
    public function testHybridSearchException(): void
    {
        $this->vectorizationService->method('hybridSearch')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->hybridSearch('query');

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test getDatabaseInfo with cached data.
     */
    public function testGetDatabaseInfoWithCachedData(): void
    {
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
    public function testGetDatabaseInfoException(): void
    {
        $this->request->method('getParam')->willReturn('true');

        $this->config->method('getValueString')->willReturn('');

        $this->db->method('getDatabasePlatform')
            ->willThrowException(new \Exception('DB Error'));

        $result = $this->controller->getDatabaseInfo();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test reindexSpecificCollection with invalid batch size.
     */
    public function testReindexSpecificCollectionInvalidBatchSize(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 10000],
            ]);

        $result = $this->controller->reindexSpecificCollection('test-collection');

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('batch size', strtolower($data['message']));
    }

    /**
     * Test reindexSpecificCollection with negative maxObjects.
     */
    public function testReindexSpecificCollectionNegativeMaxObjects(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, -1],
                ['batchSize', 1000, 100],
            ]);

        $result = $this->controller->reindexSpecificCollection('test-collection');

        $this->assertEquals(400, $result->getStatus());
    }

    /**
     * Test reindexSpecificCollection exception.
     */
    public function testReindexSpecificCollectionException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 100],
            ]);

        $this->container->method('get')
            ->willThrowException(new \Exception('Service error'));

        $result = $this->controller->reindexSpecificCollection('test-collection');

        $this->assertEquals(422, $result->getStatus());
    }

    /**
     * Test semanticSearch with filters and provider.
     */
    public function testSemanticSearchWithFiltersAndProvider(): void
    {
        $this->vectorizationService->method('semanticSearch')
            ->with('test', 5, ['type' => 'document'], 'ollama')
            ->willReturn([]);

        $result = $this->controller->semanticSearch('test', 5, ['type' => 'document'], 'ollama');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(0, $data['total']);
        $this->assertEquals(5, $data['limit']);
    }

    /**
     * Test hybridSearch with custom weights.
     */
    public function testHybridSearchWithCustomWeights(): void
    {
        $this->vectorizationService->method('hybridSearch')
            ->willReturn(['results' => [['id' => 1]], 'total' => 1]);

        $result = $this->controller->hybridSearch(
            'query',
            10,
            [],
            ['solr' => 0.7, 'vector' => 0.3]
        );

        $this->assertEquals(200, $result->getStatus());
    }
}
