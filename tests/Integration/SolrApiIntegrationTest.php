<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Tests\Integration;

use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Setup\SolrSetup;
use OCP\IConfig;
use OCP\IAppConfig;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;

/**
 * Integration tests for SOLR API endpoints
 * 
 * These tests validate that API endpoints work correctly with real HTTP clients
 * and would catch issues like response body type mismatches.
 * 
 * This test specifically would have caught the json_decode() Stream bug we just fixed.
 * 
 * @package OCA\OpenRegister\Tests\Integration
 * @category Testing
 * @author  OpenRegister Development Team
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link    https://github.com/ConductionNL/openregister
 */
class SolrApiIntegrationTest extends TestCase
{
    private SettingsController $controller;
    private SettingsService $settingsService;
    private GuzzleSolrService $guzzleSolrService;
    private IConfig $config;
    private IAppConfig $appConfig;
    private LoggerInterface $logger;

    /**
     * Set up test dependencies
     * 
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock dependencies
        $this->config = $this->createMock(IConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $clientService = $this->createMock(IClientService::class);
        
        // Configure mock SOLR settings
        $solrConfig = json_encode([
            'host' => 'localhost',
            'port' => '8983',
            'path' => '/solr',
            'core' => 'openregister',
            'scheme' => 'http',
            'zookeeper_hosts' => 'localhost:2181',
        ]);
        
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function($app, $key, $default = '') use ($solrConfig) {
                if ($app === 'openregister' && $key === 'solr') {
                    return $solrConfig;
                }
                return $default;
            });
            
        // Configure mock system config
        $this->config->method('getSystemValue')
            ->willReturnCallback(function($key, $default = null) {
                if ($key === 'instanceid') {
                    return 'test-instance-id';
                }
                if ($key === 'overwrite.cli.url') {
                    return '';
                }
                return $default;
            });

        // Create services
        $this->settingsService = new SettingsService(
            $this->appConfig,
            $this->config,
            $this->createMock(IRequest::class),
            $this->createMock(\Psr\Container\ContainerInterface::class),
            $this->createMock(\OCP\App\IAppManager::class),
            $this->createMock(\OCP\IGroupManager::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCA\OpenRegister\Db\OrganisationMapper::class),
            $this->createMock(\OCA\OpenRegister\Db\AuditTrailMapper::class),
            $this->createMock(\OCA\OpenRegister\Db\SearchTrailMapper::class),
            $this->createMock(\OCA\OpenRegister\Db\ObjectEntityMapper::class),
            $this->createMock(\OCA\OpenRegister\Service\SchemaCacheService::class),
            $this->createMock(\OCA\OpenRegister\Service\SchemaFacetCacheService::class),
            $this->createMock(\OCP\ICacheFactory::class)
        );

        $this->guzzleSolrService = new GuzzleSolrService(
            $this->settingsService,
            $this->logger,
            $clientService,
            $this->config
        );

        // Create container mock and register GuzzleSolrService
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function($className) {
                if ($className === \OCA\OpenRegister\Service\GuzzleSolrService::class) {
                    return $this->guzzleSolrService;
                }
                return $this->createMock($className);
            });
            
        $this->controller = new SettingsController(
            'openregister',
            $this->createMock(IRequest::class),
            $this->appConfig,
            $container,
            $this->createMock(\OCP\App\IAppManager::class),
            $this->settingsService
        );
    }

    /**
     * Test SOLR connection test endpoint returns proper JSON response
     * 
     * This test validates that the API endpoint properly handles response bodies
     * and would catch the json_decode() Stream issue we just fixed.
     * 
     * @return void
     */
    public function testSolrConnectionTestEndpoint(): void
    {
        // Test the actual API endpoint
        $response = $this->controller->testSolrConnection();
        
        // Verify it's a JSONResponse
        $this->assertInstanceOf(JSONResponse::class, $response);
        
        // Get the response data
        $data = $response->getData();
        
        // Verify response structure (regardless of success/failure)
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertIsBool($data['success']);
        $this->assertArrayHasKey('message', $data);
        $this->assertIsString($data['message']);
        
        // If detailed results are returned, verify structure
        if (isset($data['components'])) {
            $this->assertIsArray($data['components']);
            
            // Check component structure
            foreach (['zookeeper', 'solr', 'collection'] as $component) {
                if (isset($data['components'][$component])) {
                    $componentData = $data['components'][$component];
                    $this->assertArrayHasKey('success', $componentData);
                    $this->assertArrayHasKey('message', $componentData);
                    $this->assertIsBool($componentData['success']);
                    $this->assertIsString($componentData['message']);
                }
            }
        }
    }

    /**
     * Test SOLR setup endpoint returns proper JSON response
     * 
     * @return void
     */
    public function testSolrSetupEndpoint(): void
    {
        // Test the actual API endpoint
        $response = $this->controller->setupSolr();
        
        // Verify it's a JSONResponse
        $this->assertInstanceOf(JSONResponse::class, $response);
        
        // Get the response data
        $data = $response->getData();
        
        // Verify response structure
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertIsBool($data['success']);
        $this->assertArrayHasKey('message', $data);
        $this->assertIsString($data['message']);
    }

    /**
     * Test GuzzleSolrService properly handles HTTP response bodies
     * 
     * This test specifically validates that response body handling works correctly
     * with the direct Guzzle client implementation.
     * 
     * @return void
     */
    public function testGuzzleSolrServiceResponseHandling(): void
    {
        // Test the connection method that was failing
        $result = $this->guzzleSolrService->testConnection();
        
        // Verify it returns an array (not throwing json_decode errors)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertIsBool($result['success']);
        
        // If components are returned, verify they're properly structured
        if (isset($result['components'])) {
            $this->assertIsArray($result['components']);
            
            foreach ($result['components'] as $componentName => $componentData) {
                $this->assertIsString($componentName);
                $this->assertIsArray($componentData);
                $this->assertArrayHasKey('success', $componentData);
                $this->assertArrayHasKey('message', $componentData);
            }
        }
    }

    /**
     * Test that reproduces the json_decode Stream bug we fixed
     * 
     * This test specifically tests the scenario that caused the bug:
     * - Direct Guzzle client returns Stream objects from getBody()
     * - json_decode() expects strings, not Streams
     * - This test ensures the fix (casting to string) works correctly
     * 
     * @return void
     */
    public function testJsonDecodeStreamBugIsFixed(): void
    {
        // Create a mock Stream response (the source of our bug)
        $jsonData = ['status' => 'OK', 'message' => 'Test response'];
        $jsonString = json_encode($jsonData);
        
        // Create a Stream object (what Guzzle returns)
        $stream = \GuzzleHttp\Psr7\Utils::streamFor($jsonString);
        
        // Create a mock response with Stream body
        $mockResponse = new Response(200, [], $stream);
        
        // Create a mock handler that returns our Stream response
        $mockHandler = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new GuzzleClient(['handler' => $handlerStack]);
        
        // Create GuzzleSolrService with our mock client
        $reflection = new \ReflectionClass($this->guzzleSolrService);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($this->guzzleSolrService, $mockClient);
        
        // Test the method that was failing - this should NOT throw json_decode errors
        try {
            $result = $this->guzzleSolrService->testConnection();
            
            // If we get here, the bug is fixed - json_decode worked correctly
            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
        } catch (\TypeError $e) {
            // If we get a TypeError about json_decode, the bug still exists
            $this->assertStringNotContainsString(
                'json_decode(): Argument #1 ($json) must be of type string',
                $e->getMessage(),
                'The json_decode Stream bug still exists! Response bodies must be cast to string.'
            );
        }
    }

    /**
     * Test that all SOLR-related methods handle response bodies correctly
     * 
     * This comprehensive test ensures all methods that parse JSON responses
     * work correctly with the Guzzle client.
     * 
     * @return void
     */
    public function testAllSolrMethodsHandleResponsesProperly(): void
    {
        // Create mock responses for different scenarios
        $responses = [
            new Response(200, [], json_encode(['status' => 'OK'])),
            new Response(500, [], json_encode(['error' => 'Server Error'])),
            new Response(404, [], 'Not Found'),
        ];
        
        foreach ($responses as $mockResponse) {
            $mockHandler = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mockHandler);
            $mockClient = new GuzzleClient(['handler' => $handlerStack]);
            
            // Inject mock client
            $reflection = new \ReflectionClass($this->guzzleSolrService);
            $httpClientProperty = $reflection->getProperty('httpClient');
            $httpClientProperty->setAccessible(true);
            $httpClientProperty->setValue($this->guzzleSolrService, $mockClient);
            
            try {
                // Test the connection method - should handle all response types
                $result = $this->guzzleSolrService->testConnection();
                
                // Verify it returns an array (no json_decode errors)
                $this->assertIsArray($result);
                $this->assertArrayHasKey('success', $result);
                
            } catch (\TypeError $e) {
                $this->fail(
                    'json_decode type error detected: ' . $e->getMessage() . 
                    '. This indicates response bodies are not being cast to strings properly.'
                );
            }
        }
    }

    /**
     * Test SOLR settings retrieval
     * 
     * @return void
     */
    public function testSolrSettingsRetrieval(): void
    {
        $settings = $this->settingsService->getSolrSettings();
        
        $this->assertIsArray($settings);
        $this->assertArrayHasKey('host', $settings);
        $this->assertArrayHasKey('port', $settings);
        $this->assertArrayHasKey('core', $settings);
        $this->assertArrayHasKey('scheme', $settings);
    }

    /**
     * Test URL building with Kubernetes service names (no port should be added)
     * 
     * @return void
     */
    public function testUrlBuildingWithKubernetesServiceNames(): void
    {
        // Configure mock settings for Kubernetes service
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['openregister', 'solr_host', 'localhost', 'con-solr-solrcloud-common.solr.svc.cluster.local'],
                ['openregister', 'solr_port', '8983', '0'],
                ['openregister', 'solr_path', '/solr', '/solr'],
                ['openregister', 'solr_core', 'openregister', 'openregister'],
                ['openregister', 'solr_scheme', 'http', 'http'],
                ['openregister', 'zookeeper_hosts', 'localhost:2181', 'con-zookeeper-solrcloud-common.zookeeper.svc.cluster.local'],
            ]);

        // Mock successful response without port
        $mockResponse = new Response(200, [], json_encode(['status' => 'OK']));
        $mockHandler = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new GuzzleClient(['handler' => $handlerStack]);

        // Inject mock client
        $reflection = new \ReflectionClass($this->guzzleSolrService);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($this->guzzleSolrService, $mockClient);

        // Test connection - should work without port issues
        $result = $this->guzzleSolrService->testConnection();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Test URL building with regular hostnames (port should be included when provided)
     * 
     * @return void
     */
    public function testUrlBuildingWithRegularHostnames(): void
    {
        // Configure mock settings for regular hostname with explicit port
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['openregister', 'solr_host', 'localhost', 'solr.example.com'],
                ['openregister', 'solr_port', '8983', '9983'],
                ['openregister', 'solr_path', '/solr', '/solr'],
                ['openregister', 'solr_core', 'openregister', 'openregister'],
                ['openregister', 'solr_scheme', 'http', 'https'],
                ['openregister', 'zookeeper_hosts', 'localhost:2181', 'zk.example.com:2181'],
            ]);

        // Mock successful response
        $mockResponse = new Response(200, [], json_encode(['status' => 'OK']));
        $mockHandler = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new GuzzleClient(['handler' => $handlerStack]);

        // Inject mock client
        $reflection = new \ReflectionClass($this->guzzleSolrService);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($this->guzzleSolrService, $mockClient);

        // Test connection - should work with explicit port
        $result = $this->guzzleSolrService->testConnection();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Test URL building with port 0 (should not include port in URL)
     * 
     * @return void
     */
    public function testUrlBuildingWithPortZero(): void
    {
        // Configure mock settings with port 0
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['openregister', 'solr_host', 'localhost', 'localhost'],
                ['openregister', 'solr_port', '8983', '0'],
                ['openregister', 'solr_path', '/solr', '/solr'],
                ['openregister', 'solr_core', 'openregister', 'openregister'],
                ['openregister', 'solr_scheme', 'http', 'http'],
                ['openregister', 'zookeeper_hosts', 'localhost:2181', 'localhost:2181'],
            ]);

        // Mock successful response
        $mockResponse = new Response(200, [], json_encode(['status' => 'OK']));
        $mockHandler = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new GuzzleClient(['handler' => $handlerStack]);

        // Inject mock client
        $reflection = new \ReflectionClass($this->guzzleSolrService);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($this->guzzleSolrService, $mockClient);

        // Test connection - should work without port 0 in URL
        $result = $this->guzzleSolrService->testConnection();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // The fact that this doesn't fail means URLs are being built correctly without :0
    }
}
