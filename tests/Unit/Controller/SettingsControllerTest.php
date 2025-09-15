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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
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
    private IConfig $config;
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

        $this->settingsService = $this->createMock(SettingsService::class);
        $this->config = $this->createMock(IConfig::class);
        $this->request = $this->createMock(IRequest::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new SettingsController(
            'openregister',
            $this->request,
            $this->settingsService,
            $this->config,
            $this->logger
        );
    }

    /**
     * Test SOLR connection test endpoint returns proper JSON structure
     * 
     * This test ensures the API endpoint always returns valid JSON responses,
     * even when the underlying service throws exceptions.
     * 
     * @return void
     */
    public function testSolrConnectionTestReturnsValidJson(): void
    {
        // Mock successful connection test
        $this->settingsService
            ->method('testSolrConnection')
            ->willReturn([
                'success' => true,
                'message' => 'Connection successful',
                'components' => [
                    'solr' => ['success' => true, 'message' => 'SOLR OK'],
                    'zookeeper' => ['success' => true, 'message' => 'Zookeeper OK']
                ]
            ]);

        $response = $this->controller->testSolrConnection();

        // Verify response type
        $this->assertInstanceOf(JSONResponse::class, $response);

        // Verify response structure
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('components', $data);
    }

    /**
     * Test SOLR connection test handles service exceptions gracefully
     * 
     * This test ensures that if the service throws an exception (like the 
     * json_decode bug we fixed), the controller returns a proper error response.
     * 
     * @return void
     */
    public function testSolrConnectionTestHandlesServiceExceptions(): void
    {
        // Mock service throwing an exception (like our json_decode bug)
        $this->settingsService
            ->method('testSolrConnection')
            ->willThrowException(new \TypeError('json_decode(): Argument #1 ($json) must be of type string, GuzzleHttp\Psr7\Stream given'));

        $response = $this->controller->testSolrConnection();

        // Should still return valid JSON response, not throw exception
        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Connection test failed', $data['message']);
    }

    /**
     * Test SOLR setup endpoint returns proper JSON structure
     * 
     * @return void
     */
    public function testSolrSetupReturnsValidJson(): void
    {
        // Mock successful setup
        $this->settingsService
            ->method('setupSolr')
            ->willReturn(true);

        $response = $this->controller->setupSolr();

        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertTrue($data['success']);
    }

    /**
     * Test SOLR setup handles failures gracefully
     * 
     * @return void
     */
    public function testSolrSetupHandlesFailures(): void
    {
        // Mock setup failure
        $this->settingsService
            ->method('setupSolr')
            ->willReturn(false);

        $response = $this->controller->setupSolr();

        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertFalse($data['success']);
    }

    /**
     * Test SOLR settings endpoint returns configuration
     * 
     * @return void
     */
    public function testSolrSettingsReturnsConfiguration(): void
    {
        $mockSettings = [
            'host' => 'localhost',
            'port' => '8983',
            'core' => 'openregister',
            'scheme' => 'http'
        ];

        $this->settingsService
            ->method('getSolrSettings')
            ->willReturn($mockSettings);

        $response = $this->controller->getSolrSettings();

        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = $response->getData();
        $this->assertEquals($mockSettings, $data);
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
            'performance' => ['cache_hit_rate' => 0.85]
        ];

        $this->settingsService
            ->method('getStatistics')
            ->willReturn($mockStats);

        $response = $this->controller->getStatistics();

        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('registers', $data);
        $this->assertArrayHasKey('schemas', $data);
        $this->assertArrayHasKey('objects', $data);
    }

    /**
     * Test cache statistics endpoint
     * 
     * @return void
     */
    public function testGetCacheStatsReturnsValidStructure(): void
    {
        $mockCacheStats = [
            'enabled' => true,
            'hit_rate' => 0.85,
            'size' => '250MB',
            'entries' => 15000
        ];

        $this->settingsService
            ->method('getCacheStats')
            ->willReturn($mockCacheStats);

        $response = $this->controller->getCacheStats();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayHasKey('hit_rate', $data);
    }

    /**
     * Test cache clearing endpoint
     * 
     * @return void
     */
    public function testClearCacheReturnsSuccess(): void
    {
        $this->settingsService
            ->method('clearCache')
            ->willReturn(true);

        $response = $this->controller->clearCache();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
    }

    /**
     * Test RBAC settings endpoints
     * 
     * @return void
     */
    public function testRbacSettingsEndpoints(): void
    {
        $mockRbacSettings = [
            'enabled' => true,
            'default_permissions' => 'read',
            'admin_bypass' => false
        ];

        $this->settingsService
            ->method('getRbacSettings')
            ->willReturn($mockRbacSettings);

        $this->settingsService
            ->method('updateRbacSettings')
            ->willReturn(true);

        // Test GET
        $response = $this->controller->getRbacSettings();
        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('enabled', $data);

        // Test PUT
        $response = $this->controller->updateRbacSettings();
        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
    }

    /**
     * Test multitenancy settings endpoints
     * 
     * @return void
     */
    public function testMultitenancySettingsEndpoints(): void
    {
        $mockSettings = [
            'enabled' => false,
            'tenant_isolation' => 'strict',
            'shared_resources' => []
        ];

        $this->settingsService
            ->method('getMultitenancySettings')
            ->willReturn($mockSettings);

        $this->settingsService
            ->method('updateMultitenancySettings')
            ->willReturn(true);

        // Test GET
        $response = $this->controller->getMultitenancySettings();
        $this->assertInstanceOf(JSONResponse::class, $response);

        // Test PUT
        $response = $this->controller->updateMultitenancySettings();
        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    /**
     * Test retention settings endpoints
     * 
     * @return void
     */
    public function testRetentionSettingsEndpoints(): void
    {
        $mockSettings = [
            'enabled' => true,
            'default_retention_days' => 365,
            'cleanup_schedule' => 'daily'
        ];

        $this->settingsService
            ->method('getRetentionSettings')
            ->willReturn($mockSettings);

        $this->settingsService
            ->method('updateRetentionSettings')
            ->willReturn(true);

        // Test GET
        $response = $this->controller->getRetentionSettings();
        $this->assertInstanceOf(JSONResponse::class, $response);

        // Test PUT
        $response = $this->controller->updateRetentionSettings();
        $this->assertInstanceOf(JSONResponse::class, $response);
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
            ->method('getVersionInfo')
            ->willReturn($mockVersionInfo);

        $response = $this->controller->getVersionInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('environment', $data);
    }

    /**
     * Test SOLR dashboard stats endpoint
     * 
     * @return void
     */
    public function testGetSolrDashboardStatsReturnsValidStructure(): void
    {
        $mockStats = [
            'status' => 'healthy',
            'documents' => 15000,
            'index_size' => '2.5GB',
            'query_time_avg' => 45.2
        ];

        $this->settingsService
            ->method('getSolrDashboardStats')
            ->willReturn($mockStats);

        $response = $this->controller->getSolrDashboardStats();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('documents', $data);
    }

    /**
     * Test SOLR warmup endpoint
     * 
     * @return void
     */
    public function testWarmupSolrIndexReturnsSuccess(): void
    {
        $this->settingsService
            ->method('warmupSolrIndex')
            ->willReturn(true);

        $response = $this->controller->warmupSolrIndex();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
    }

    /**
     * Test schema mapping test endpoint
     * 
     * @return void
     */
    public function testTestSchemaMappingReturnsValidStructure(): void
    {
        $mockResult = [
            'success' => true,
            'mappings_tested' => 25,
            'errors' => [],
            'warnings' => []
        ];

        $this->settingsService
            ->method('testSchemaMapping')
            ->willReturn($mockResult);

        $response = $this->controller->testSchemaMapping();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('mappings_tested', $data);
    }

    /**
     * Test that all controller methods return JSONResponse objects
     * 
     * This comprehensive test ensures API consistency across ALL endpoints
     * and prevents raw PHP output that could break frontend JSON parsing.
     * 
     * @return void
     */
    public function testAllEndpointsReturnJsonResponse(): void
    {
        // Mock all service methods to return valid data
        $this->settingsService->method('testSolrConnection')->willReturn(['success' => true]);
        $this->settingsService->method('setupSolr')->willReturn(true);
        $this->settingsService->method('testSolrSetup')->willReturn(['success' => true]);
        $this->settingsService->method('getSolrSettings')->willReturn(['host' => 'localhost']);
        $this->settingsService->method('updateSolrSettings')->willReturn(true);
        $this->settingsService->method('getSolrDashboardStats')->willReturn(['status' => 'ok']);
        $this->settingsService->method('warmupSolrIndex')->willReturn(true);
        $this->settingsService->method('testSchemaMapping')->willReturn(['success' => true]);
        $this->settingsService->method('getStatistics')->willReturn(['total' => 0]);
        $this->settingsService->method('getCacheStats')->willReturn(['enabled' => true]);
        $this->settingsService->method('clearCache')->willReturn(true);
        $this->settingsService->method('warmupNamesCache')->willReturn(true);
        $this->settingsService->method('getRbacSettings')->willReturn(['enabled' => false]);
        $this->settingsService->method('updateRbacSettings')->willReturn(true);
        $this->settingsService->method('getMultitenancySettings')->willReturn(['enabled' => false]);
        $this->settingsService->method('updateMultitenancySettings')->willReturn(true);
        $this->settingsService->method('getRetentionSettings')->willReturn(['enabled' => true]);
        $this->settingsService->method('updateRetentionSettings')->willReturn(true);
        $this->settingsService->method('getVersionInfo')->willReturn(['version' => '1.0.0']);
        $this->settingsService->method('load')->willReturn(['settings' => []]);
        $this->settingsService->method('update')->willReturn(true);
        $this->settingsService->method('updatePublishingOptions')->willReturn(true);
        $this->settingsService->method('rebase')->willReturn(true);

        // Test all major endpoints (based on routes.php)
        $endpoints = [
            // Core settings
            'load',
            'update', 
            'updatePublishingOptions',
            'rebase',
            'stats',
            'getStatistics',
            
            // SOLR endpoints
            'testSolrConnection',
            'setupSolr',
            'testSolrSetup',
            'getSolrSettings',
            'updateSolrSettings',
            'getSolrDashboardStats',
            'warmupSolrIndex',
            'testSchemaMapping',
            
            // Cache endpoints
            'getCacheStats',
            'clearCache',
            'warmupNamesCache',
            
            // RBAC endpoints
            'getRbacSettings',
            'updateRbacSettings',
            
            // Multitenancy endpoints
            'getMultitenancySettings',
            'updateMultitenancySettings',
            
            // Retention endpoints
            'getRetentionSettings',
            'updateRetentionSettings',
            
            // Version info
            'getVersionInfo'
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
                    
                    // Verify response data is serializable (no objects, resources, etc.)
                    $data = $response->getData();
                    $this->assertIsArray($data, "Method {$method} should return array data");
                    
                    // Verify JSON encoding works (would catch circular references, etc.)
                    $json = json_encode($data);
                    $this->assertNotFalse($json, "Method {$method} data should be JSON encodable");
                    
                } catch (\Exception $e) {
                    $this->fail("Method {$method} threw exception: " . $e->getMessage());
                }
            }
        }
    }
}
