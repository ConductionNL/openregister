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
     * Test that all controller methods return JSONResponse objects
     * 
     * This ensures API consistency and prevents raw PHP output that could
     * break frontend JSON parsing.
     * 
     * @return void
     */
    public function testAllEndpointsReturnJsonResponse(): void
    {
        // Mock all service methods to return valid data
        $this->settingsService->method('testSolrConnection')->willReturn(['success' => true]);
        $this->settingsService->method('setupSolr')->willReturn(true);
        $this->settingsService->method('getSolrSettings')->willReturn(['host' => 'localhost']);
        $this->settingsService->method('getStatistics')->willReturn(['total' => 0]);
        $this->settingsService->method('getCacheSettings')->willReturn(['enabled' => true]);
        $this->settingsService->method('getRbacSettings')->willReturn(['enabled' => false]);

        // Test all major endpoints
        $endpoints = [
            'testSolrConnection',
            'setupSolr', 
            'getSolrSettings',
            'getStatistics',
            'getCacheSettings',
            'getRbacSettings'
        ];

        foreach ($endpoints as $method) {
            if (method_exists($this->controller, $method)) {
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
            }
        }
    }
}
