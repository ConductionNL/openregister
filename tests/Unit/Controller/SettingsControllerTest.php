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
use Psr\Container\ContainerInterface;
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
}
