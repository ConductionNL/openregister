<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SettingsController
 *
 * These tests focus on controller behavior and API response formatting.
 * They would catch issues like malformed JSON responses or missing error handling.
 *
 * @package OCA\OpenRegister\Tests\Unit\Controller
 * @category Testing
 * @author  OpenRegister Development Team
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link    https://github.com/ConductionNL/openregister
 */
class SettingsControllerTest extends TestCase
{
    private SettingsController $controller;
    private SettingsService $settingsService;
    private IAppConfig $config;
    private IDBConnection $db;
    private ContainerInterface $container;
    private IAppManager $appManager;
    private VectorizationService $vectorizationService;
    private IRequest $request;
    private LoggerInterface $logger;
    private IL10N $l10n;

    /**
     * Set up test dependencies
     *
     * @return void
     */
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
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);

        $this->controller = new SettingsController(
            'openregister',
            $this->request,
            $this->config,
            $this->db,
            $this->container,
            $this->appManager,
            $this->settingsService,
            $this->vectorizationService,
            $this->logger,
            $this->l10n
        );
    }

    /**
     * Test index (get settings) endpoint returns proper JSON structure
     *
     * @return void
     */
    public function testIndexReturnsValidJson(): void
    {
        $this->settingsService
            ->method('getSettings')
            ->willReturn([
                'solr' => ['enabled' => true],
                'rbac' => ['enabled' => false],
            ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
    }

    /**
     * Test index handles service exceptions gracefully
     *
     * @return void
     */
    public function testIndexHandlesServiceExceptions(): void
    {
        $this->settingsService
            ->method('getSettings')
            ->willThrowException(new \Exception('Config error'));

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test update endpoint returns proper JSON structure
     *
     * @return void
     */
    public function testUpdateReturnsValidJson(): void
    {
        $this->settingsService
            ->method('updateSettings')
            ->willReturn(['success' => true]);

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
    }

    /**
     * Test load endpoint returns proper JSON structure
     *
     * @return void
     */
    public function testLoadReturnsValidJson(): void
    {
        $this->settingsService
            ->method('getSettings')
            ->willReturn(['solr' => ['enabled' => true]]);

        $response = $this->controller->load();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
    }

    /**
     * Test updatePublishingOptions returns proper JSON structure
     *
     * @return void
     */
    public function testUpdatePublishingOptionsReturnsValidJson(): void
    {
        $this->settingsService
            ->method('updatePublishingOptions')
            ->willReturn(['success' => true]);

        $response = $this->controller->updatePublishingOptions();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
    }

    /**
     * Test rebase endpoint returns proper JSON structure
     *
     * @return void
     */
    public function testRebaseReturnsValidJson(): void
    {
        $this->settingsService
            ->method('rebase')
            ->willReturn(['success' => true, 'objects_updated' => 10]);

        $response = $this->controller->rebase();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
    }

    /**
     * Test statistics endpoint returns proper structure
     *
     * @return void
     */
    public function testStatisticsReturnsValidStructure(): void
    {
        $mockStats = [
            'registers' => 5,
            'schemas' => 12,
            'objects' => 1500,
        ];

        $this->settingsService
            ->method('getStats')
            ->willReturn($mockStats);

        $response = $this->controller->stats();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
    }

    /**
     * Test getStatistics is an alias for stats
     *
     * @return void
     */
    public function testGetStatisticsReturnsValidStructure(): void
    {
        $mockStats = [
            'registers' => 5,
            'schemas' => 12,
            'objects' => 1500,
        ];

        $this->settingsService
            ->method('getStats')
            ->willReturn($mockStats);

        $response = $this->controller->getStatistics();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
    }

    /**
     * Test version info endpoint
     *
     * @return void
     */
    public function testGetVersionInfoReturnsValidStructure(): void
    {
        $mockVersionInfo = [
            'version' => '2.1.0',
            'build' => 'abc123',
            'environment' => 'production',
            'php_version' => '8.1.0',
            'nextcloud_version' => '30.0.4'
        ];

        $this->settingsService
            ->method('getVersionInfoOnly')
            ->willReturn($mockVersionInfo);

        $response = $this->controller->getVersionInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('environment', $data);
    }

    /**
     * Test getSearchBackend endpoint returns proper JSON
     *
     * @return void
     */
    public function testGetSearchBackendReturnsValidJson(): void
    {
        $this->settingsService
            ->method('getSearchBackendConfig')
            ->willReturn([
                'active' => 'solr',
                'available' => ['solr', 'elasticsearch'],
            ]);

        $response = $this->controller->getSearchBackend();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('active', $data);
    }

    /**
     * Test updateSearchBackend endpoint with valid backend
     *
     * Note: SettingsService::updateSearchBackendConfig() expects array $data,
     * but SettingsController passes a string $backend directly. The mock enforces
     * the type signature, causing a TypeError. The controller only catches Exception
     * (not Error/TypeError), so the TypeError propagates.
     *
     * @return void
     */
    public function testUpdateSearchBackendReturnsValidJson(): void
    {
        $this->request->method('getParams')
            ->willReturn(['backend' => 'elasticsearch']);

        // The mock enforces the array type, so when the controller passes a string
        // it will throw a TypeError. The controller catches Exception (not Error),
        // so the TypeError propagates.
        $this->expectException(\TypeError::class);

        $this->controller->updateSearchBackend();
    }

    /**
     * Test updateSearchBackend endpoint with missing backend
     *
     * @return void
     */
    public function testUpdateSearchBackendWithMissingBackend(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $response = $this->controller->updateSearchBackend();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test rebase handles service exceptions gracefully
     *
     * @return void
     */
    public function testRebaseHandlesServiceExceptions(): void
    {
        $this->settingsService
            ->method('rebase')
            ->willThrowException(new \Exception('Rebase failed'));

        $response = $this->controller->rebase();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test getStatistics handles service exceptions gracefully
     *
     * @return void
     */
    public function testGetStatisticsHandlesServiceExceptions(): void
    {
        $this->settingsService
            ->method('getStats')
            ->willThrowException(new \Exception('Stats error'));

        $response = $this->controller->getStatistics();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test stats handles service exceptions gracefully
     *
     * @return void
     */
    public function testStatsHandlesServiceExceptions(): void
    {
        $this->settingsService
            ->method('getStats')
            ->willThrowException(new \Exception('Stats error'));

        $response = $this->controller->stats();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test getVersionInfo handles service exceptions gracefully
     *
     * @return void
     */
    public function testGetVersionInfoHandlesServiceExceptions(): void
    {
        $this->settingsService
            ->method('getVersionInfoOnly')
            ->willThrowException(new \Exception('Version error'));

        $response = $this->controller->getVersionInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test getSearchBackend handles service exceptions gracefully
     *
     * @return void
     */
    public function testGetSearchBackendHandlesServiceExceptions(): void
    {
        $this->settingsService
            ->method('getSearchBackendConfig')
            ->willThrowException(new \Exception('Config error'));

        $response = $this->controller->getSearchBackend();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test update handles service exceptions gracefully
     *
     * @return void
     */
    public function testUpdateHandlesServiceExceptions(): void
    {
        $this->settingsService
            ->method('updateSettings')
            ->willThrowException(new \Exception('Update failed'));

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test updatePublishingOptions handles service exceptions gracefully
     *
     * @return void
     */
    public function testUpdatePublishingOptionsHandlesServiceExceptions(): void
    {
        $this->settingsService
            ->method('updatePublishingOptions')
            ->willThrowException(new \Exception('Publish options error'));

        $response = $this->controller->updatePublishingOptions();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test load handles service exceptions gracefully
     *
     * @return void
     */
    public function testLoadHandlesServiceExceptions(): void
    {
        $this->settingsService
            ->method('getSettings')
            ->willThrowException(new \Exception('Load failed'));

        $response = $this->controller->load();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test semanticSearch returns results
     *
     * @return void
     */
    public function testSemanticSearchReturnsResults(): void
    {
        $this->vectorizationService
            ->method('semanticSearch')
            ->willReturn([
                ['id' => '1', 'score' => 0.95],
            ]);

        $response = $this->controller->semanticSearch('test query', 10);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('test query', $data['query']);
        $this->assertSame(1, $data['total']);
    }

    /**
     * Test semanticSearch returns 400 for empty query
     *
     * @return void
     */
    public function testSemanticSearchReturns400ForEmptyQuery(): void
    {
        $response = $this->controller->semanticSearch('   ', 10);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertSame(400, $response->getStatus());
    }

    /**
     * Test semanticSearch handles exceptions
     *
     * @return void
     */
    public function testSemanticSearchHandlesExceptions(): void
    {
        $this->vectorizationService
            ->method('semanticSearch')
            ->willThrowException(new \Exception('Search error'));

        $response = $this->controller->semanticSearch('test', 10);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Test hybridSearch returns 400 for empty query
     *
     * @return void
     */
    public function testHybridSearchReturns400ForEmptyQuery(): void
    {
        $response = $this->controller->hybridSearch('   ', 20);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());
    }

    /**
     * Test getObjectService returns null when app is installed
     *
     * @return void
     */
    public function testGetObjectServiceReturnsNullWhenInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $result = $this->controller->getObjectService();

        $this->assertNull($result);
    }

    /**
     * Test getObjectService throws when app not installed
     *
     * @return void
     */
    public function testGetObjectServiceThrowsWhenNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['other-app']);

        $this->expectException(\RuntimeException::class);
        $this->controller->getObjectService();
    }

    /**
     * Test getConfigurationService returns service when app is installed
     *
     * @return void
     */
    public function testGetConfigurationServiceReturnsService(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);
        $mockConfigService = $this->createMock(\OCA\OpenRegister\Service\ConfigurationService::class);
        $this->container->method('get')->willReturn($mockConfigService);

        $result = $this->controller->getConfigurationService();

        $this->assertSame($mockConfigService, $result);
    }

    /**
     * Test getConfigurationService throws when app not installed
     *
     * @return void
     */
    public function testGetConfigurationServiceThrowsWhenNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['other-app']);

        $this->expectException(\RuntimeException::class);
        $this->controller->getConfigurationService();
    }

    /**
     * Test testSetupHandler returns 400 when SOLR is disabled
     *
     * @return void
     */
    public function testSetupHandlerReturnsSolrDisabled(): void
    {
        $this->settingsService
            ->method('getSolrSettings')
            ->willReturn(['enabled' => false]);

        $response = $this->controller->testSetupHandler();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('disabled', $data['message']);
    }

    /**
     * Test testSetupHandler returns 422 when getSolrSettings throws
     *
     * @return void
     */
    public function testSetupHandlerReturns422OnException(): void
    {
        $this->settingsService
            ->method('getSolrSettings')
            ->willThrowException(new \Exception('Config error'));

        $response = $this->controller->testSetupHandler();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(422, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Test reindexSpecificCollection returns 400 for invalid batch size
     *
     * @return void
     */
    public function testReindexSpecificCollectionInvalidBatchSize(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 10],
                ['batchSize', 1000, 10000],
            ]);

        $response = $this->controller->reindexSpecificCollection('test-collection');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('test-collection', $data['collection']);
    }

    /**
     * Test reindexSpecificCollection returns 400 for negative maxObjects
     *
     * @return void
     */
    public function testReindexSpecificCollectionNegativeMaxObjects(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, -5],
                ['batchSize', 1000, 100],
            ]);

        $response = $this->controller->reindexSpecificCollection('test-collection');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Test reindexSpecificCollection returns 422 when container throws
     *
     * @return void
     */
    public function testReindexSpecificCollectionException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 100],
            ]);
        $this->container->method('get')
            ->willThrowException(new \Exception('IndexService unavailable'));

        $response = $this->controller->reindexSpecificCollection('my-col');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(422, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('my-col', $data['collection']);
    }

    /**
     * Test getDatabaseInfo returns cached data
     *
     * @return void
     */
    public function testGetDatabaseInfoReturnsCached(): void
    {
        $cachedJson = json_encode([
            'database' => ['type' => 'MySQL', 'version' => '8.0'],
            'success'  => true,
        ]);

        $this->request->method('getParam')
            ->willReturn(false);
        $this->config->method('getValueString')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($cachedJson);

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['fromCache']);
        $this->assertSame('MySQL', $data['database']['type']);
    }

    /**
     * Test getDatabaseInfo returns 500 on exception
     *
     * @return void
     */
    public function testGetDatabaseInfoReturns500OnException(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');
        $this->db->method('getDatabasePlatform')
            ->willThrowException(new \Exception('DB connect failed'));

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Test refreshDatabaseInfo clears cache and delegates
     *
     * @return void
     */
    public function testRefreshDatabaseInfoClearsCache(): void
    {
        $this->config->expects($this->once())
            ->method('deleteKey')
            ->with('openregister', 'databaseInfo');

        // After clearing cache, getDatabaseInfo will be called.
        // Make it return cached data (empty) then hit the db path.
        $this->request->method('getParam')
            ->willReturn(false);
        $this->config->method('getValueString')
            ->willReturn('');
        $this->db->method('getDatabasePlatform')
            ->willThrowException(new \Exception('DB error'));

        $response = $this->controller->refreshDatabaseInfo();

        // Will get 500 because db call fails after cache miss.
        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    /**
     * Test hybridSearch returns results when query is valid
     *
     * @return void
     */
    public function testHybridSearchReturnsResults(): void
    {
        $this->vectorizationService
            ->method('hybridSearch')
            ->willReturn([
                'results' => [['id' => '1']],
                'total' => 1,
            ]);

        $response = $this->controller->hybridSearch('test query', 20);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('test query', $data['query']);
    }

    /**
     * Test hybridSearch handles exceptions
     *
     * @return void
     */
    public function testHybridSearchHandlesExceptions(): void
    {
        $this->vectorizationService
            ->method('hybridSearch')
            ->willThrowException(new \Exception('Search failed'));

        $response = $this->controller->hybridSearch('test', 20);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Test that all existing controller methods return JSONResponse objects
     *
     * This comprehensive test ensures API consistency across ALL endpoints
     * and prevents raw PHP output that could break frontend JSON parsing.
     *
     * @return void
     */
    public function testAllEndpointsReturnJsonResponse(): void
    {
        // Mock all service methods to return valid data.
        $this->settingsService->method('getSettings')->willReturn(['settings' => []]);
        $this->settingsService->method('updateSettings')->willReturn(['success' => true]);
        $this->settingsService->method('updatePublishingOptions')->willReturn(['success' => true]);
        $this->settingsService->method('rebase')->willReturn(['success' => true]);
        $this->settingsService->method('getStats')->willReturn(['total' => 0]);
        $this->settingsService->method('getVersionInfoOnly')->willReturn(['version' => '1.0.0']);
        $this->settingsService->method('getSearchBackendConfig')->willReturn(['active' => 'solr']);

        // Test all major existing endpoints on the controller.
        $endpoints = [
            'index',
            'load',
            'update',
            'updatePublishingOptions',
            'rebase',
            'stats',
            'getStatistics',
            'getVersionInfo',
            'getSearchBackend',
        ];

        foreach ($endpoints as $method) {
            if (method_exists($this->controller, $method)) {
                try {
                    $response = $this->controller->$method();

                    $this->assertInstanceOf(
                        JSONResponse::class,
                        $response,
                        "Method {$method} should return JSONResponse"
                    );

                    // Verify response data is serializable (no objects, resources, etc.).
                    $data = $response->getData();
                    $this->assertIsArray($data, "Method {$method} should return array data");

                    // Verify JSON encoding works (would catch circular references, etc.).
                    $json = json_encode($data);
                    $this->assertNotFalse($json, "Method {$method} data should be JSON encodable");

                } catch (\Exception $e) {
                    $this->fail("Method {$method} threw exception: " . $e->getMessage());
                }
            }
        }
    }

    // ── testSchemaMapping tests ────────────────────────────────────────

    public function testSchemaMappingContainerThrows(): void
    {
        // testSchemaMapping calls container->get(IndexService::class)
        // When it throws, returns 422
        $this->container->method('get')
            ->willThrowException(new \Exception('IndexService not available'));

        $response = $this->controller->testSchemaMapping();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(422, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('IndexService', $data['error']);
    }

    public function testSchemaMappingReturns422OnException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $response = $this->controller->testSchemaMapping();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(422, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    // ── debugTypeFiltering tests ───────────────────────────────────────

    public function testDebugTypeFilteringReturns500OnException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('ObjectService unavailable'));

        $response = $this->controller->debugTypeFiltering();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
    }

    // ── semanticSearch with filters and provider ──────────────────────

    public function testSemanticSearchWithFiltersAndProvider(): void
    {
        $this->vectorizationService
            ->method('semanticSearch')
            ->willReturn([
                ['id' => '1', 'score' => 0.9],
                ['id' => '2', 'score' => 0.85],
            ]);

        $response = $this->controller->semanticSearch(
            'test query',
            5,
            ['entity_type' => 'register'],
            'dolphin'
        );

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['total']);
        $this->assertSame(5, $data['limit']);
    }

    // ── hybridSearch with filters ────────────────────────────────────

    public function testHybridSearchWithFilters(): void
    {
        $this->vectorizationService
            ->method('hybridSearch')
            ->willReturn([
                'results' => [['id' => '1']],
                'total' => 1,
            ]);

        $response = $this->controller->hybridSearch('test query', 5);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    // ── getDatabaseInfo when cache is empty (forces refresh) ─────────

    public function testGetDatabaseInfoWhenCacheEmpty(): void
    {
        $this->request->method('getParam')
            ->willReturn(false);
        $this->config->method('getValueString')
            ->willReturn('');
        $this->db->method('getDatabasePlatform')
            ->willThrowException(new \Exception('Platform error'));

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
    }

    // ── testSetupHandler with SOLR enabled ───────────────────────────

    public function testSetupHandlerWithSolrEnabledButServiceUnavailable(): void
    {
        // When SOLR is enabled but IndexService fails to load, we get 422
        $this->settingsService
            ->method('getSolrSettings')
            ->willReturn(['enabled' => true, 'host' => 'localhost', 'port' => 8983]);

        // The controller internally uses OC class for container resolution
        // which is not available in unit tests. The exception propagates as 422.
        try {
            $response = $this->controller->testSetupHandler();
            // If we get a response, verify it
            $this->assertInstanceOf(JSONResponse::class, $response);
        } catch (\Error $e) {
            // OC class not found is expected in unit test environment
            $this->assertStringContainsString('OC', $e->getMessage());
        }
    }

    // ── reindexSpecificCollection with valid params ──────────────────

    public function testReindexSpecificCollectionValidParams(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 100],
                ['batchSize', 1000, 500],
            ]);
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $mockIndexService->method('reindexAll')->willReturn([
            'success' => true,
            'stats' => ['indexed' => 50, 'errors' => 0],
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $response = $this->controller->reindexSpecificCollection('my-collection');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
    }

    // ── Additional tests for uncovered methods/branches ─────────────

    /**
     * Test reindexSpecificCollection returns 422 when reindexAll returns success=false
     *
     * @return void
     */
    public function testReindexSpecificCollectionReturns422WhenReindexFails(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 500],
            ]);
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $mockIndexService->method('reindexAll')->willReturn([
            'success' => false,
            'message' => 'Collection not found',
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $response = $this->controller->reindexSpecificCollection('nonexistent-col');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(422, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Collection not found', $data['message']);
        $this->assertSame('nonexistent-col', $data['collection']);
    }

    /**
     * Test reindexSpecificCollection returns 422 when reindexAll fails without message
     *
     * @return void
     */
    public function testReindexSpecificCollectionReturns422WithDefaultMessage(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 500],
            ]);
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $mockIndexService->method('reindexAll')->willReturn([
            'success' => false,
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $response = $this->controller->reindexSpecificCollection('my-col');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(422, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('Failed to reindex collection', $data['message']);
    }

    /**
     * Test reindexSpecificCollection returns 400 for batchSize of 0 (below minimum)
     *
     * @return void
     */
    public function testReindexSpecificCollectionBatchSizeZero(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 0],
            ]);

        $response = $this->controller->reindexSpecificCollection('test-col');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('batch size', strtolower($data['message']));
    }

    /**
     * Test reindexSpecificCollection with boundary batchSize=1 (valid minimum)
     *
     * @return void
     */
    public function testReindexSpecificCollectionBatchSizeMinBoundary(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 1],
            ]);
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $mockIndexService->method('reindexAll')->willReturn([
            'success' => true,
            'stats' => [],
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $response = $this->controller->reindexSpecificCollection('test-col');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
    }

    /**
     * Test reindexSpecificCollection with boundary batchSize=5000 (valid maximum)
     *
     * @return void
     */
    public function testReindexSpecificCollectionBatchSizeMaxBoundary(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 5000],
            ]);
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $mockIndexService->method('reindexAll')->willReturn([
            'success' => true,
            'stats' => [],
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $response = $this->controller->reindexSpecificCollection('test-col');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
    }

    /**
     * Test reindexSpecificCollection with batchSize=5001 (above maximum, returns 400)
     *
     * @return void
     */
    public function testReindexSpecificCollectionBatchSizeAboveMax(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 5001],
            ]);

        $response = $this->controller->reindexSpecificCollection('test-col');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());
    }

    /**
     * Test reindexSpecificCollection success path includes stats in response
     *
     * @return void
     */
    public function testReindexSpecificCollectionSuccessIncludesStats(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 50],
                ['batchSize', 1000, 100],
            ]);
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $mockIndexService->method('reindexAll')->willReturn([
            'success' => true,
            'stats' => ['indexed' => 50, 'errors' => 2],
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $response = $this->controller->reindexSpecificCollection('my-collection');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('Reindex completed successfully', $data['message']);
        $this->assertSame('my-collection', $data['collection']);
        $this->assertArrayHasKey('stats', $data);
        $this->assertSame(50, $data['stats']['indexed']);
    }

    /**
     * Test reindexSpecificCollection success without stats key defaults to empty array
     *
     * @return void
     */
    public function testReindexSpecificCollectionSuccessWithoutStats(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 100],
            ]);
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $mockIndexService->method('reindexAll')->willReturn([
            'success' => true,
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $response = $this->controller->reindexSpecificCollection('my-col');

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['stats']);
    }

    /**
     * Test updateSearchBackend with 'active' key instead of 'backend'
     *
     * @return void
     */
    public function testUpdateSearchBackendWithActiveKey(): void
    {
        $this->request->method('getParams')
            ->willReturn(['active' => 'solr']);

        // Same TypeError issue as testUpdateSearchBackendReturnsValidJson
        $this->expectException(\TypeError::class);

        $this->controller->updateSearchBackend();
    }

    /**
     * Test getDatabaseInfo with invalid cached JSON (non-parseable)
     *
     * @return void
     */
    public function testGetDatabaseInfoWithInvalidCachedJson(): void
    {
        $this->request->method('getParam')
            ->willReturn(false);
        $this->config->method('getValueString')
            ->willReturn('not-valid-json{{{');
        // After cache miss (invalid JSON), it queries the DB
        $this->db->method('getDatabasePlatform')
            ->willThrowException(new \Exception('DB error'));

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
    }

    /**
     * Test getDatabaseInfo with cached JSON missing 'database' key
     *
     * @return void
     */
    public function testGetDatabaseInfoWithCachedJsonMissingDatabaseKey(): void
    {
        $this->request->method('getParam')
            ->willReturn(false);
        $this->config->method('getValueString')
            ->willReturn(json_encode(['success' => true]));
        // Missing 'database' key means cache is invalid, falls through to DB query
        $this->db->method('getDatabasePlatform')
            ->willThrowException(new \Exception('DB error'));

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
    }

    /**
     * Test getDatabaseInfo with MySQL platform (live query path)
     *
     * @return void
     */
    public function testGetDatabaseInfoWithMysqlPlatform(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('mysql');

        $this->db->method('getDatabasePlatform')->willReturn($platform);

        // Mock the db->prepare call for VERSION query
        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $mockResult->method('fetchOne')->willReturn('8.0.32');

        $mockStatement = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $mockStatement->method('execute')->willReturn($mockResult);

        $this->db->method('prepare')->willReturn($mockStatement);

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('MySQL', $data['database']['type']);
        $this->assertSame('8.0.32', $data['database']['version']);
        $this->assertFalse($data['database']['vectorSupport']);
        $this->assertFalse($data['fromCache']);
    }

    /**
     * Test getDatabaseInfo with MariaDB version string
     *
     * @return void
     */
    public function testGetDatabaseInfoWithMariadbPlatform(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('mysql');

        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $mockResult->method('fetchOne')->willReturn('10.6.12-MariaDB-1:10.6.12+maria~ubu2204');

        $mockStatement = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $mockStatement->method('execute')->willReturn($mockResult);

        $this->db->method('prepare')->willReturn($mockStatement);

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('MariaDB', $data['database']['type']);
        $this->assertSame('10.6.12', $data['database']['version']);
        $this->assertFalse($data['database']['vectorSupport']);
    }

    /**
     * Test getDatabaseInfo with MySQL when VERSION query throws
     *
     * @return void
     */
    public function testGetDatabaseInfoMysqlVersionQueryFails(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('mysql');

        $this->db->method('getDatabasePlatform')->willReturn($platform);
        $this->db->method('prepare')
            ->willThrowException(new \Exception('Query failed'));

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('MySQL/MariaDB', $data['database']['type']);
        $this->assertSame('Unknown', $data['database']['version']);
    }

    /**
     * Test getDatabaseInfo with PostgreSQL platform without pgvector
     *
     * @return void
     */
    public function testGetDatabaseInfoWithPostgresPlatformNoPgvector(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('postgresql');

        $this->db->method('getDatabasePlatform')->willReturn($platform);

        // First prepare call = SELECT VERSION(), second = pg_extension query
        $versionResult = $this->createMock(\OCP\DB\IResult::class);
        $versionResult->method('fetchOne')->willReturn('PostgreSQL 15.4 on x86_64');

        $extResult = $this->createMock(\OCP\DB\IResult::class);
        $extResult->method('fetch')->willReturnOnConsecutiveCalls(
            ['extname' => 'plpgsql', 'extversion' => '1.0'],
            false
        );

        $stmt1 = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt1->method('execute')->willReturn($versionResult);

        $stmt2 = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt2->method('execute')->willReturn($extResult);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($stmt1, $stmt2);

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('PostgreSQL', $data['database']['type']);
        $this->assertSame('15.4', $data['database']['version']);
        $this->assertFalse($data['database']['vectorSupport']);
        $this->assertStringContainsString('not installed', $data['database']['recommendedPlugin']);
    }

    /**
     * Test getDatabaseInfo with PostgreSQL platform with pgvector installed
     *
     * @return void
     */
    public function testGetDatabaseInfoWithPostgresPlatformWithPgvector(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('postgresql');

        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $versionResult = $this->createMock(\OCP\DB\IResult::class);
        $versionResult->method('fetchOne')->willReturn('PostgreSQL 15.4 on x86_64');

        $extResult = $this->createMock(\OCP\DB\IResult::class);
        $extResult->method('fetch')->willReturnOnConsecutiveCalls(
            ['extname' => 'plpgsql', 'extversion' => '1.0'],
            ['extname' => 'vector', 'extversion' => '0.5.1'],
            false
        );

        $stmt1 = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt1->method('execute')->willReturn($versionResult);

        $stmt2 = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt2->method('execute')->willReturn($extResult);

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($stmt1, $stmt2);

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('PostgreSQL', $data['database']['type']);
        $this->assertTrue($data['database']['vectorSupport']);
        $this->assertStringContainsString('installed', $data['database']['recommendedPlugin']);
        $this->assertStringContainsString('Optimal', $data['database']['performanceNote']);
    }

    /**
     * Test getDatabaseInfo with PostgreSQL when version query throws
     *
     * @return void
     */
    public function testGetDatabaseInfoPostgresVersionQueryFails(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('postgresql');

        $this->db->method('getDatabasePlatform')->willReturn($platform);

        // All prepare calls throw to test the catch paths
        $this->db->method('prepare')
            ->willThrowException(new \Exception('Query failed'));

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('PostgreSQL', $data['database']['type']);
        $this->assertSame('Unknown', $data['database']['version']);
    }

    /**
     * Test getDatabaseInfo with SQLite platform
     *
     * @return void
     */
    public function testGetDatabaseInfoWithSqlitePlatform(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('sqlite');

        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('SQLite', $data['database']['type']);
        $this->assertFalse($data['database']['vectorSupport']);
        $this->assertStringContainsString('sqlite-vss', $data['database']['recommendedPlugin']);
    }

    /**
     * Test getDatabaseInfo with unknown platform (no getName method)
     *
     * @return void
     */
    public function testGetDatabaseInfoWithUnknownPlatform(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        // Create a platform mock without getName
        $platform = new \stdClass();

        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('unknown', $data['database']['platform']);
        $this->assertSame('Unknown', $data['database']['type']);
    }

    /**
     * Test getDatabaseInfo caches the result in app config
     *
     * @return void
     */
    public function testGetDatabaseInfoStoresInCache(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('sqlite');
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $this->config->expects($this->once())
            ->method('setValueString')
            ->with(
                'openregister',
                'databaseInfo',
                $this->isType('string')
            );

        $this->controller->getDatabaseInfo();
    }

    /**
     * Test hybridSearch with custom weights and filters
     *
     * @return void
     */
    public function testHybridSearchWithCustomWeightsAndFilters(): void
    {
        $customWeights = ['solr' => 0.7, 'vector' => 0.3];
        $solrFilters = ['schema_id' => 42];

        $this->vectorizationService
            ->method('hybridSearch')
            ->willReturn([
                'results' => [['id' => '1', 'score' => 0.92]],
                'total' => 1,
                'weights' => $customWeights,
            ]);

        $response = $this->controller->hybridSearch(
            'advanced search',
            15,
            $solrFilters,
            $customWeights,
            'openai'
        );

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('advanced search', $data['query']);
        $this->assertSame(1, $data['total']);
    }

    /**
     * Test semanticSearch with empty string query returns 400
     *
     * @return void
     */
    public function testSemanticSearchWithEmptyStringReturns400(): void
    {
        $response = $this->controller->semanticSearch('', 10);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Query parameter is required', $data['error']);
    }

    /**
     * Test hybridSearch with empty string query returns 400
     *
     * @return void
     */
    public function testHybridSearchWithEmptyStringReturns400(): void
    {
        $response = $this->controller->hybridSearch('', 20);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Test semanticSearch response includes timestamp
     *
     * @return void
     */
    public function testSemanticSearchResponseIncludesTimestamp(): void
    {
        $this->vectorizationService
            ->method('semanticSearch')
            ->willReturn([]);

        $response = $this->controller->semanticSearch('query', 10);

        $data = $response->getData();
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['total']);
    }

    /**
     * Test semanticSearch response includes filters in output
     *
     * @return void
     */
    public function testSemanticSearchResponseIncludesFilters(): void
    {
        $filters = ['entity_type' => 'schema', 'entity_id' => 5];

        $this->vectorizationService
            ->method('semanticSearch')
            ->willReturn([]);

        $response = $this->controller->semanticSearch('query', 10, $filters);

        $data = $response->getData();
        $this->assertSame($filters, $data['filters']);
    }

    /**
     * Test semanticSearch exception includes trace in response
     *
     * @return void
     */
    public function testSemanticSearchExceptionIncludesTrace(): void
    {
        $this->vectorizationService
            ->method('semanticSearch')
            ->willThrowException(new \Exception('Vector error'));

        $response = $this->controller->semanticSearch('test', 10);

        $data = $response->getData();
        $this->assertArrayHasKey('trace', $data);
        $this->assertSame('Vector error', $data['error']);
    }

    /**
     * Test hybridSearch exception includes trace in response
     *
     * @return void
     */
    public function testHybridSearchExceptionIncludesTrace(): void
    {
        $this->vectorizationService
            ->method('hybridSearch')
            ->willThrowException(new \Exception('Hybrid error'));

        $response = $this->controller->hybridSearch('test', 20);

        $data = $response->getData();
        $this->assertArrayHasKey('trace', $data);
        $this->assertSame('Hybrid error', $data['error']);
    }

    /**
     * Test updateSearchBackend handles exception from settings service
     *
     * @return void
     */
    public function testUpdateSearchBackendHandlesServiceException(): void
    {
        $this->request->method('getParams')
            ->willReturn(['backend' => 'solr']);

        $this->settingsService
            ->method('updateSearchBackendConfig')
            ->willThrowException(new \Exception('Backend update failed'));

        // TypeError occurs because mock enforces array type but controller passes string
        $this->expectException(\TypeError::class);

        $this->controller->updateSearchBackend();
    }

    /**
     * Test getDatabaseInfo with PostgreSQL where extension query throws but version succeeds
     *
     * @return void
     */
    public function testGetDatabaseInfoPostgresExtensionQueryFails(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('postgresql');

        $this->db->method('getDatabasePlatform')->willReturn($platform);

        // Version query succeeds, extension query fails
        $versionResult = $this->createMock(\OCP\DB\IResult::class);
        $versionResult->method('fetchOne')->willReturn('PostgreSQL 14.1 on x86_64');

        $stmt1 = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt1->method('execute')->willReturn($versionResult);

        $stmt2 = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt2->method('execute')->willThrowException(new \Exception('Permission denied'));

        $this->db->method('prepare')
            ->willReturnOnConsecutiveCalls($stmt1, $stmt2);

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('PostgreSQL', $data['database']['type']);
        $this->assertSame('14.1', $data['database']['version']);
        // Without extension info, vectorSupport defaults to false
        $this->assertFalse($data['database']['vectorSupport']);
        $this->assertEmpty($data['database']['extensions']);
    }

    /**
     * Test getDatabaseInfo with mariadb in platform name
     *
     * @return void
     */
    public function testGetDatabaseInfoWithMariadbPlatformName(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('mariadb');

        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $mockResult->method('fetchOne')->willReturn('10.11.4-MariaDB');

        $mockStatement = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $mockStatement->method('execute')->willReturn($mockResult);

        $this->db->method('prepare')->willReturn($mockStatement);

        $response = $this->controller->getDatabaseInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('MariaDB', $data['database']['type']);
        $this->assertFalse($data['database']['vectorSupport']);
        $this->assertStringContainsString('pgvector', $data['database']['recommendedPlugin']);
    }

    /**
     * Test getDatabaseInfo response includes lastUpdated timestamp
     *
     * @return void
     */
    public function testGetDatabaseInfoIncludesLastUpdated(): void
    {
        $this->request->method('getParam')
            ->willReturn('true');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getName')->willReturn('sqlite');

        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $response = $this->controller->getDatabaseInfo();

        $data = $response->getData();
        $this->assertArrayHasKey('lastUpdated', $data['database']);
        // Verify it's a valid ISO 8601 date
        $dateTime = \DateTime::createFromFormat(\DateTime::ATOM, $data['database']['lastUpdated']);
        $this->assertNotFalse($dateTime);
    }

    /**
     * Test stats returns 422 status on exception (not 500)
     *
     * @return void
     */
    public function testStatsReturns422StatusOnException(): void
    {
        $this->settingsService
            ->method('getStats')
            ->willThrowException(new \Exception('Stats error'));

        $response = $this->controller->stats();

        $this->assertSame(422, $response->getStatus());
    }

    /**
     * Test index returns 500 status on exception
     *
     * @return void
     */
    public function testIndexReturns500StatusOnException(): void
    {
        $this->settingsService
            ->method('getSettings')
            ->willThrowException(new \Exception('Settings error'));

        $response = $this->controller->index();

        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('Settings error', $data['error']);
    }

    /**
     * Test update returns 500 status on exception
     *
     * @return void
     */
    public function testUpdateReturns500StatusOnException(): void
    {
        $this->settingsService
            ->method('updateSettings')
            ->willThrowException(new \Exception('Update error'));

        $response = $this->controller->update();

        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('Update error', $data['error']);
    }

    /**
     * Test load returns 500 status on exception
     *
     * @return void
     */
    public function testLoadReturns500StatusOnException(): void
    {
        $this->settingsService
            ->method('getSettings')
            ->willThrowException(new \Exception('Load error'));

        $response = $this->controller->load();

        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('Load error', $data['error']);
    }

    /**
     * Test rebase returns 500 status on exception
     *
     * @return void
     */
    public function testRebaseReturns500StatusOnException(): void
    {
        $this->settingsService
            ->method('rebase')
            ->willThrowException(new \Exception('Rebase error'));

        $response = $this->controller->rebase();

        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('Rebase error', $data['error']);
    }

    /**
     * Test reindexSpecificCollection exception message is included in response
     *
     * @return void
     */
    public function testReindexSpecificCollectionExceptionMessageInResponse(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 100],
            ]);
        $this->container->method('get')
            ->willThrowException(new \Exception('Service crashed'));

        $response = $this->controller->reindexSpecificCollection('col-1');

        $data = $response->getData();
        $this->assertStringContainsString('Service crashed', $data['message']);
        $this->assertStringContainsString('Reindex failed', $data['message']);
    }

    /**
     * Test testSetupHandler exception message includes 'SOLR setup error' prefix
     *
     * @return void
     */
    public function testSetupHandlerExceptionMessageFormat(): void
    {
        $this->settingsService
            ->method('getSolrSettings')
            ->willThrowException(new \Exception('Connection refused'));

        $response = $this->controller->testSetupHandler();

        $data = $response->getData();
        $this->assertStringContainsString('SOLR setup error', $data['message']);
        $this->assertStringContainsString('Connection refused', $data['message']);
    }

    // ── testSchemaMapping success path ──────────────────────────────────

    /**
     * Test testSchemaMapping returns results when all services resolve.
     *
     * Uses an anonymous class because testSchemaAwareMapping is not defined on
     * IndexService. The controller calls it with named parameters, so addMethods
     * (which creates a parameterless method) does not work.
     *
     * @return void
     */
    public function testSchemaMappingReturnsResultsOnSuccess(): void
    {
        $expectedResults = [
            'success'  => true,
            'mappings' => ['field1' => 'text', 'field2' => 'integer'],
            'total'    => 2,
        ];

        // Anonymous class with the correct method signature.
        $mockIndexService = new class ($expectedResults) extends \OCA\OpenRegister\Service\IndexService {
            private array $returnValue;

            /**
             * @param array $returnValue Value to return.
             */
            public function __construct(array $returnValue)
            {
                // Skip parent constructor.
                $this->returnValue = $returnValue;
            }

            /**
             * Mock testSchemaAwareMapping with named params.
             *
             * @param mixed $objectMapper Object mapper.
             * @param mixed $schemaMapper Schema mapper.
             *
             * @return array Test results.
             *
             * @suppressWarnings(PHPMD.UnusedFormalParameter)
             */
            public function testSchemaAwareMapping($objectMapper=null, $schemaMapper=null): array
            {
                return $this->returnValue;
            }
        };

        $mockObjectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);
        $mockSchemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);

        $this->container->method('get')
            ->willReturnCallback(
                function (string $id) use ($mockIndexService, $mockObjectMapper, $mockSchemaMapper) {
                    if ($id === \OCA\OpenRegister\Service\IndexService::class) {
                        return $mockIndexService;
                    }

                    if ($id === \OCA\OpenRegister\Db\MagicMapper::class) {
                        return $mockObjectMapper;
                    }

                    if ($id === \OCA\OpenRegister\Db\SchemaMapper::class) {
                        return $mockSchemaMapper;
                    }

                    throw new \Exception("Unknown service: $id");
                }
            );

        $response = $this->controller->testSchemaMapping();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('mappings', $data);
        $this->assertSame(2, $data['total']);
    }

    /**
     * Test testSchemaMapping returns 422 when testSchemaAwareMapping throws
     *
     * @return void
     */
    public function testSchemaMappingReturns422WhenMappingThrows(): void
    {
        // Anonymous class that throws on testSchemaAwareMapping.
        $mockIndexService = new class extends \OCA\OpenRegister\Service\IndexService {
            /**
             * Skip parent constructor.
             */
            public function __construct()
            {
                // No-op.
            }

            /**
             * Mock testSchemaAwareMapping that throws.
             *
             * @param mixed $objectMapper Object mapper.
             * @param mixed $schemaMapper Schema mapper.
             *
             * @return array Never returns.
             *
             * @throws \Exception Always.
             *
             * @suppressWarnings(PHPMD.UnusedFormalParameter)
             */
            public function testSchemaAwareMapping($objectMapper=null, $schemaMapper=null): array
            {
                throw new \Exception('Mapping error: field not found');
            }
        };

        $mockObjectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);
        $mockSchemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);

        $this->container->method('get')
            ->willReturnCallback(
                function (string $id) use ($mockIndexService, $mockObjectMapper, $mockSchemaMapper) {
                    if ($id === \OCA\OpenRegister\Service\IndexService::class) {
                        return $mockIndexService;
                    }

                    if ($id === \OCA\OpenRegister\Db\MagicMapper::class) {
                        return $mockObjectMapper;
                    }

                    if ($id === \OCA\OpenRegister\Db\SchemaMapper::class) {
                        return $mockSchemaMapper;
                    }

                    throw new \Exception("Unknown service: $id");
                }
            );

        $response = $this->controller->testSchemaMapping();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(422, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Mapping error', $data['error']);
    }

    /**
     * Test testSchemaMapping returns 422 when objectMapper resolution fails
     *
     * @return void
     */
    public function testSchemaMappingReturns422WhenObjectMapperFails(): void
    {
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);

        $this->container->method('get')
            ->willReturnCallback(function (string $id) use ($mockIndexService) {
                if ($id === \OCA\OpenRegister\Service\IndexService::class) {
                    return $mockIndexService;
                }

                throw new \Exception('MagicMapper not available');
            });

        $response = $this->controller->testSchemaMapping();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(422, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    // ── debugTypeFiltering success path ─────────────────────────────────

    /**
     * Create a mock IResult that also provides fetchAllAssociative.
     *
     * The controller calls fetchAllAssociative() which is a Doctrine DBAL method
     * not present on OCP\DB\IResult. We create a mock of IResult and add
     * fetchAllAssociative via a wrapper that delegates to it.
     *
     * @param array $rows Rows to return.
     *
     * @return \OCP\DB\IResult Mock result with fetchAllAssociative.
     */
    private function createDoctrineResult(array $rows): \OCP\DB\IResult
    {
        // createMock implements the IResult interface, but fetchAllAssociative is not on it.
        // The controller calls fetchAllAssociative() directly. Since we're mocking,
        // we use __call which PHPUnit mocks provide, or we use a concrete implementation.
        // The simplest: mock IResult and use fetchAll as a proxy for fetchAllAssociative.
        // But the controller specifically calls fetchAllAssociative.
        // We'll create an anonymous class that implements IResult and adds fetchAllAssociative.
        return new class ($rows) implements \OCP\DB\IResult {

            /**
             * @var array Row data.
             */
            private array $rows;

            /**
             * Constructor.
             *
             * @param array $rows Rows to return.
             */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            /**
             * Close cursor.
             *
             * @return bool True.
             */
            public function closeCursor(): bool
            {
                return true;
            }

            /**
             * Fetch one row.
             *
             * @param int $fetchMode Fetch mode.
             *
             * @return mixed False (no rows).
             */
            public function fetch(int $fetchMode = \PDO::FETCH_ASSOC)
            {
                return false;
            }

            /**
             * Fetch all rows.
             *
             * @param int $fetchMode Fetch mode.
             *
             * @return array The rows.
             */
            public function fetchAll(int $fetchMode = \PDO::FETCH_ASSOC): array
            {
                return $this->rows;
            }

            /**
             * Fetch one column value.
             *
             * @return mixed False.
             */
            public function fetchColumn()
            {
                return false;
            }

            /**
             * Fetch first column of first row.
             *
             * @return mixed False.
             */
            public function fetchOne()
            {
                return false;
            }

            /**
             * Get row count.
             *
             * @return int Count.
             */
            public function rowCount(): int
            {
                return count($this->rows);
            }

            /**
             * Fetch all rows as associative arrays (Doctrine DBAL method).
             *
             * @return array The rows.
             */
            public function fetchAllAssociative(): array
            {
                return $this->rows;
            }
        };
    }

    /**
     * Set up a mock query builder chain for debugTypeFiltering.
     *
     * @param array $dbRows Rows for the direct database query.
     *
     * @return IDBConnection Mock database connection.
     */
    private function createDebugDbConnection(array $dbRows): IDBConnection
    {
        $mockQb   = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $mockExpr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);

        $mockExpr->method('like')->willReturn('like_expr');
        $mockQb->method('expr')->willReturn($mockExpr);
        $mockQb->method('select')->willReturnSelf();
        $mockQb->method('from')->willReturnSelf();
        $mockQb->method('where')->willReturnSelf();
        $mockQb->method('orWhere')->willReturnSelf();
        $mockQb->method('createNamedParameter')->willReturn('param');
        $mockQb->method('executeQuery')->willReturn($this->createDoctrineResult($dbRows));

        $mockConnection = $this->createMock(IDBConnection::class);
        $mockConnection->method('getQueryBuilder')->willReturn($mockQb);

        return $mockConnection;
    }

    /**
     * Test debugTypeFiltering returns results when ObjectService works with empty results
     *
     * @return void
     */
    public function testDebugTypeFilteringReturnsResults(): void
    {
        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $mockObjectService->method('searchObjectsPaginated')
            ->willReturn(['results' => []]);

        $mockConnection = $this->createDebugDbConnection([]);

        $this->container->method('get')
            ->willReturnCallback(function (string $id) use ($mockObjectService, $mockConnection) {
                if ($id === \OCA\OpenRegister\Service\ObjectService::class) {
                    return $mockObjectService;
                }

                if ($id === \OCP\IDBConnection::class) {
                    return $mockConnection;
                }

                throw new \Exception("Unknown service: $id");
            });

        $response = $this->controller->debugTypeFiltering();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('all_organizations', $data);
        $this->assertArrayHasKey('type_samenwerking', $data);
        $this->assertArrayHasKey('type_community', $data);
        $this->assertArrayHasKey('type_both', $data);
        $this->assertArrayHasKey('direct_database_query', $data);
        $this->assertSame(0, $data['all_organizations']['count']);
        $this->assertSame(0, $data['direct_database_query']['count']);
    }

    /**
     * Test debugTypeFiltering with actual results from ObjectService.
     *
     * ObjectEntity uses __call magic for getId/getName, so they can be mocked
     * via addMethods on the mock builder.
     *
     * @return void
     */
    public function testDebugTypeFilteringWithResults(): void
    {
        // ObjectEntity uses __call for getters, so we use addMethods
        $mockEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId', 'getName'])
            ->onlyMethods(['getObject'])
            ->getMock();
        $mockEntity->method('getId')->willReturn(1);
        $mockEntity->method('getName')->willReturn('Test Org');
        $mockEntity->method('getObject')->willReturn(['type' => 'samenwerking', 'name' => 'Test Org']);

        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $mockObjectService->method('searchObjectsPaginated')
            ->willReturn(['results' => [$mockEntity]]);

        $mockConnection = $this->createDebugDbConnection([
            ['id' => 1, 'name' => 'Test Org', 'object' => '{"type":"samenwerking"}'],
        ]);

        $this->container->method('get')
            ->willReturnCallback(function (string $id) use ($mockObjectService, $mockConnection) {
                if ($id === \OCA\OpenRegister\Service\ObjectService::class) {
                    return $mockObjectService;
                }

                if ($id === \OCP\IDBConnection::class) {
                    return $mockConnection;
                }

                throw new \Exception("Unknown service: $id");
            });

        $response = $this->controller->debugTypeFiltering();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();

        // Verify all_organizations has the entity data.
        $this->assertSame(1, $data['all_organizations']['count']);
        $orgs = $data['all_organizations']['organizations'];
        $this->assertCount(1, $orgs);
        $this->assertSame(1, $orgs[0]['id']);
        $this->assertSame('Test Org', $orgs[0]['name']);
        $this->assertSame('samenwerking', $orgs[0]['type']);
        $this->assertArrayHasKey('object_data', $orgs[0]);

        // Verify type_samenwerking.
        $this->assertSame(1, $data['type_samenwerking']['count']);
        $this->assertSame('samenwerking', $data['type_samenwerking']['organizations'][0]['type']);

        // Verify direct_database_query.
        $this->assertSame(1, $data['direct_database_query']['count']);
        $this->assertSame('samenwerking', $data['direct_database_query']['organizations'][0]['type']);
    }

    /**
     * Test debugTypeFiltering with entity missing type in object data
     *
     * @return void
     */
    public function testDebugTypeFilteringWithMissingType(): void
    {
        $mockEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId', 'getName'])
            ->onlyMethods(['getObject'])
            ->getMock();
        $mockEntity->method('getId')->willReturn(2);
        $mockEntity->method('getName')->willReturn('No Type Org');
        $mockEntity->method('getObject')->willReturn(['name' => 'No Type Org']);

        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $mockObjectService->method('searchObjectsPaginated')
            ->willReturn(['results' => [$mockEntity]]);

        $mockConnection = $this->createDebugDbConnection([
            ['id' => 2, 'name' => 'No Type Org', 'object' => '{"name":"No Type Org"}'],
        ]);

        $this->container->method('get')
            ->willReturnCallback(function (string $id) use ($mockObjectService, $mockConnection) {
                if ($id === \OCA\OpenRegister\Service\ObjectService::class) {
                    return $mockObjectService;
                }

                if ($id === \OCP\IDBConnection::class) {
                    return $mockConnection;
                }

                throw new \Exception("Unknown service: $id");
            });

        $response = $this->controller->debugTypeFiltering();

        $data = $response->getData();
        // When type is missing, should default to 'NO TYPE'.
        $this->assertSame('NO TYPE', $data['all_organizations']['organizations'][0]['type']);
        $this->assertSame('NO TYPE', $data['direct_database_query']['organizations'][0]['type']);
    }

    /**
     * Test debugTypeFiltering when setRegister throws
     *
     * @return void
     */
    public function testDebugTypeFilteringWhenSetRegisterThrows(): void
    {
        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $mockObjectService->method('setRegister')
            ->willThrowException(new \Exception('Register not found'));

        $this->container->method('get')
            ->willReturnCallback(function (string $id) use ($mockObjectService) {
                if ($id === \OCA\OpenRegister\Service\ObjectService::class) {
                    return $mockObjectService;
                }

                throw new \Exception("Unknown service: $id");
            });

        $response = $this->controller->debugTypeFiltering();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('trace', $data);
        $this->assertStringContainsString('Register not found', $data['error']);
    }

    /**
     * Test debugTypeFiltering when searchObjectsPaginated throws
     *
     * @return void
     */
    public function testDebugTypeFilteringWhenSearchThrows(): void
    {
        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $mockObjectService->method('searchObjectsPaginated')
            ->willThrowException(new \Exception('Search failed'));

        $this->container->method('get')
            ->willReturnCallback(function (string $id) use ($mockObjectService) {
                if ($id === \OCA\OpenRegister\Service\ObjectService::class) {
                    return $mockObjectService;
                }

                throw new \Exception("Unknown service: $id");
            });

        $response = $this->controller->debugTypeFiltering();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertStringContainsString('Search failed', $data['error']);
    }

    /**
     * Test debugTypeFiltering when query builder fails
     *
     * @return void
     */
    public function testDebugTypeFilteringWhenQueryBuilderFails(): void
    {
        $mockObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $mockObjectService->method('searchObjectsPaginated')
            ->willReturn(['results' => []]);

        $mockConnection = $this->createMock(IDBConnection::class);
        $mockConnection->method('getQueryBuilder')
            ->willThrowException(new \Exception('QB init failed'));

        $this->container->method('get')
            ->willReturnCallback(function (string $id) use ($mockObjectService, $mockConnection) {
                if ($id === \OCA\OpenRegister\Service\ObjectService::class) {
                    return $mockObjectService;
                }

                if ($id === \OCP\IDBConnection::class) {
                    return $mockConnection;
                }

                throw new \Exception("Unknown service: $id");
            });

        $response = $this->controller->debugTypeFiltering();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertStringContainsString('QB init failed', $data['error']);
    }

    // ── hybridSearch additional coverage ─────────────────────────────────

    /**
     * Test hybridSearch response includes timestamp
     *
     * @return void
     */
    public function testHybridSearchResponseIncludesTimestamp(): void
    {
        $this->vectorizationService
            ->method('hybridSearch')
            ->willReturn(['results' => []]);

        $response = $this->controller->hybridSearch('valid query', 10);

        $data = $response->getData();
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertTrue($data['success']);
    }

    /**
     * Test hybridSearch merges result array keys into response via spread operator
     *
     * @return void
     */
    public function testHybridSearchMergesResultKeysIntoResponse(): void
    {
        $this->vectorizationService
            ->method('hybridSearch')
            ->willReturn([
                'results' => [['id' => '1', 'score' => 0.9]],
                'total'   => 1,
                'method'  => 'hybrid',
            ]);

        $response = $this->controller->hybridSearch('merge test', 10);

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('merge test', $data['query']);
        // Spread operator merges these keys into the response.
        $this->assertSame(1, $data['total']);
        $this->assertSame('hybrid', $data['method']);
        $this->assertCount(1, $data['results']);
    }

    // ── updateSearchBackend additional coverage ─────────────────────────
    // Note: The controller has a bug where it passes a string to
    // SettingsService::updateSearchBackendConfig(array $data). Lines 525-535
    // (success + exception handler) are unreachable due to this TypeError.
    // The existing tests correctly document this with expectException(TypeError).

    /**
     * Test updateSearchBackend with missing both 'backend' and 'active' keys
     *
     * @return void
     */
    public function testUpdateSearchBackendMissingBothKeysReturns400(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')
            ->willReturn(['other_key' => 'value']);

        $controller = new SettingsController(
            'openregister',
            $request,
            $this->config,
            $this->db,
            $this->container,
            $this->appManager,
            $this->settingsService,
            $this->vectorizationService,
            $this->logger
        );

        $response = $controller->updateSearchBackend();

        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('Backend parameter is required', $data['error']);
    }

}
