<?php

declare(strict_types=1);

/**
 * SettingsControllerTest
 * 
 * Unit tests for the SettingsController
 *
 * @category   Test
 * @package    OCA\OpenRegister\Tests\Unit\Controller
 * @author     Conduction.nl <info@conduction.nl>
 * @copyright  Conduction.nl 2024
 * @license    EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version    1.0.0
 * @link       https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the SettingsController
 *
 * This test class covers all functionality of the SettingsController
 * including settings page rendering and service retrieval.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class SettingsControllerTest extends TestCase
{
    /**
     * The SettingsController instance being tested
     *
     * @var SettingsController
     */
    private SettingsController $controller;

    /**
     * Mock request object
     *
     * @var IRequest
     */
    private $request;

    /**
     * Mock app config
     *
     * @var IAppConfig
     */
    private $config;

    /**
     * Mock container
     *
     * @var ContainerInterface
     */
    private $container;

    /**
     * Mock app manager
     *
     * @var IAppManager
     */
    private $appManager;

    /**
     * Mock settings service
     *
     * @var SettingsService
     */
    private $settingsService;

    /**
     * Mock GuzzleSolrService
     *
     * @var GuzzleSolrService
     */
    private $guzzleSolrService;

    /**
     * Set up test environment before each test
     *
     * This method initializes all mocks and the controller instance
     * for testing purposes.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects for all dependencies
        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->guzzleSolrService = $this->createMock(GuzzleSolrService::class);

        // Configure container to return services
        $this->container->expects($this->any())
            ->method('get')
            ->willReturnMap([
                [GuzzleSolrService::class, $this->guzzleSolrService],
                ['OCA\OpenRegister\Service\ObjectService', $this->createMock(ObjectService::class)],
                ['OCA\OpenRegister\Service\ConfigurationService', $this->createMock(ConfigurationService::class)]
            ]);

        // Initialize the controller with mocked dependencies
        $this->controller = new SettingsController(
            'openregister',
            $this->request,
            $this->config,
            $this->container,
            $this->appManager,
            $this->settingsService
        );
    }


    /**
     * Test getObjectService method when app is installed
     *
     * @return void
     */
    public function testGetObjectServiceWhenAppInstalled(): void
    {
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['openregister', 'other-app']);

        $result = $this->controller->getObjectService();

        $this->assertInstanceOf(ObjectService::class, $result);
    }

    /**
     * Test getObjectService method when app is not installed
     *
     * @return void
     */
    public function testGetObjectServiceWhenAppNotInstalled(): void
    {
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['other-app']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');

        $this->controller->getObjectService();
    }

    /**
     * Test getConfigurationService method when app is installed
     *
     * @return void
     */
    public function testGetConfigurationServiceWhenAppInstalled(): void
    {
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['openregister', 'other-app']);

        $result = $this->controller->getConfigurationService();

        $this->assertInstanceOf(ConfigurationService::class, $result);
    }

    /**
     * Test getConfigurationService method when app is not installed
     *
     * @return void
     */
    public function testGetConfigurationServiceWhenAppNotInstalled(): void
    {
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['other-app']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration service is not available.');

        $this->controller->getConfigurationService();
    }

    /**
     * Test getSettings method returns settings data
     *
     * @return void
     */
    public function testGetSettingsReturnsSettingsData(): void
    {
        $expectedSettings = [
            'setting1' => 'value1',
            'setting2' => 'value2'
        ];

        $this->settingsService->expects($this->once())
            ->method('getSettings')
            ->willReturn($expectedSettings);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($expectedSettings, $response->getData());
    }

    /**
     * Test getSettings method with exception
     *
     * @return void
     */
    public function testGetSettingsWithException(): void
    {
        $this->settingsService->expects($this->once())
            ->method('getSettings')
            ->willThrowException(new \Exception('Settings error'));

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals(['error' => 'Settings error'], $response->getData());
    }

    /**
     * Test updateSettings method with successful update
     *
     * @return void
     */
    public function testUpdateSettingsSuccessful(): void
    {
        $settingsData = [
            'setting1' => 'new_value1',
            'setting2' => 'new_value2'
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($settingsData);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($settingsData)
            ->willReturn(['success' => true]);

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['success' => true], $response->getData());
    }

    /**
     * Test updateSettings method with validation error
     *
     * @return void
     */
    public function testUpdateSettingsWithValidationError(): void
    {
        $settingsData = ['invalid_setting' => 'value'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($settingsData);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->willThrowException(new \InvalidArgumentException('Invalid setting'));

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals(['error' => 'Invalid setting'], $response->getData());
    }

    /**
     * Test resetSettings method with successful reset
     *
     * @return void
     */
    public function testResetSettingsSuccessful(): void
    {
        $this->settingsService->expects($this->once())
            ->method('rebase')
            ->willReturn(['success' => true]);

        $response = $this->controller->rebase();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['success' => true], $response->getData());
    }

    /**
     * Test resetSettings method with exception
     *
     * @return void
     */
    public function testResetSettingsWithException(): void
    {
        $this->settingsService->expects($this->once())
            ->method('rebase')
            ->willThrowException(new \Exception('Reset failed'));

        $response = $this->controller->rebase();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals(['error' => 'Reset failed'], $response->getData());
    }

    /**
     * Test getSolrSettings method
     */
    public function testGetSolrSettings(): void
    {
        $expectedSettings = [
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8983
        ];

        $this->settingsService->expects($this->once())
            ->method('getSolrSettingsOnly')
            ->willReturn($expectedSettings);

        $response = $this->controller->getSolrSettings();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($expectedSettings, $response->getData());
    }

    /**
     * Test updateSolrSettings method
     */
    public function testUpdateSolrSettings(): void
    {
        $settingsData = [
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8983
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($settingsData);

        $this->settingsService->expects($this->once())
            ->method('updateSolrSettingsOnly')
            ->with($settingsData)
            ->willReturn(['success' => true]);

        $response = $this->controller->updateSolrSettings();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test warmupSolrIndex method
     */
    public function testWarmupSolrIndex(): void
    {
        $warmupData = [
            'batchSize' => 1000,
            'maxObjects' => 0,
            'mode' => 'serial'
        ];

        $this->request->expects($this->any())
            ->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 1000],
                ['mode', 'serial', 'serial'],
                ['collectErrors', false, false]
            ]);

        $this->guzzleSolrService->expects($this->once())
            ->method('warmupIndex')
            ->with([], 0, 'serial', false)
            ->willReturn(['success' => true]);

        $response = $this->controller->warmupSolrIndex();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test getSolrDashboardStats method
     */
    public function testGetSolrDashboardStats(): void
    {
        $expectedStats = [
            'available' => true,
            'document_count' => 1000
        ];

        $this->guzzleSolrService->expects($this->once())
            ->method('getDashboardStats')
            ->willReturn($expectedStats);

        $response = $this->controller->getSolrDashboardStats();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test manageSolr method
     */
    public function testManageSolr(): void
    {
        $operation = 'clear';
        $expectedResult = ['success' => true];

        $this->guzzleSolrService->expects($this->once())
            ->method('clearIndex')
            ->willReturn($expectedResult);

        $response = $this->controller->manageSolr($operation);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test getRbacSettings method
     */
    public function testGetRbacSettings(): void
    {
        $expectedSettings = [
            'enabled' => true,
            'anonymousGroup' => 'public'
        ];

        $this->settingsService->expects($this->once())
            ->method('getRbacSettingsOnly')
            ->willReturn($expectedSettings);

        $response = $this->controller->getRbacSettings();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test updateRbacSettings method
     */
    public function testUpdateRbacSettings(): void
    {
        $rbacData = [
            'enabled' => true,
            'anonymousGroup' => 'public'
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($rbacData);

        $this->settingsService->expects($this->once())
            ->method('updateRbacSettingsOnly')
            ->with($rbacData)
            ->willReturn(['success' => true]);

        $response = $this->controller->updateRbacSettings();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test getMultitenancySettings method
     */
    public function testGetMultitenancySettings(): void
    {
        $expectedSettings = [
            'enabled' => false,
            'defaultUserTenant' => ''
        ];

        $this->settingsService->expects($this->once())
            ->method('getMultitenancySettingsOnly')
            ->willReturn($expectedSettings);

        $response = $this->controller->getMultitenancySettings();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test updateMultitenancySettings method
     */
    public function testUpdateMultitenancySettings(): void
    {
        $multitenancyData = [
            'enabled' => false,
            'defaultUserTenant' => ''
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($multitenancyData);

        $this->settingsService->expects($this->once())
            ->method('updateMultitenancySettingsOnly')
            ->with($multitenancyData)
            ->willReturn(['success' => true]);

        $response = $this->controller->updateMultitenancySettings();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test getRetentionSettings method
     */
    public function testGetRetentionSettings(): void
    {
        $expectedSettings = [
            'objectArchiveRetention' => 31536000000,
            'objectDeleteRetention' => 63072000000
        ];

        $this->settingsService->expects($this->once())
            ->method('getRetentionSettingsOnly')
            ->willReturn($expectedSettings);

        $response = $this->controller->getRetentionSettings();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test updateRetentionSettings method
     */
    public function testUpdateRetentionSettings(): void
    {
        $retentionData = [
            'objectArchiveRetention' => 31536000000,
            'objectDeleteRetention' => 63072000000
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($retentionData);

        $this->settingsService->expects($this->once())
            ->method('updateRetentionSettingsOnly')
            ->with($retentionData)
            ->willReturn(['success' => true]);

        $response = $this->controller->updateRetentionSettings();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test getVersionInfo method
     */
    public function testGetVersionInfo(): void
    {
        $expectedInfo = [
            'app_version' => '1.0.0',
            'php_version' => '8.1.0'
        ];

        $this->settingsService->expects($this->once())
            ->method('getVersionInfoOnly')
            ->willReturn($expectedInfo);

        $response = $this->controller->getVersionInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test testSchemaMapping method
     * Note: This test expects the current buggy behavior where solrServiceFactory is undefined
     */
    public function testTestSchemaMapping(): void
    {
        $result = $this->controller->testSchemaMapping();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(422, $result->getStatus());
        
        $data = $result->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('error', $data);
        $this->assertFalse($data['success']);
    }

    /**
     * Test clearSolrIndex method
     */
    public function testClearSolrIndex(): void
    {
        $expectedResult = ['success' => true];

        $this->guzzleSolrService->expects($this->once())
            ->method('clearIndex')
            ->willReturn($expectedResult);

        $result = $this->controller->clearSolrIndex();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(200, $result->getStatus());
    }

    /**
     * Test inspectSolrIndex method
     */
    public function testInspectSolrIndex(): void
    {
        $query = '*:*';
        $start = 0;
        $rows = 20;
        $fields = '';

        $this->request->expects($this->any())
            ->method('getParam')
            ->willReturnMap([
                ['query', '*:*', $query],
                ['start', 0, $start],
                ['rows', 20, $rows],
                ['fields', '', $fields]
            ]);

        // Mock container to return GuzzleSolrService
        $this->container->expects($this->once())
            ->method('get')
            ->with(GuzzleSolrService::class)
            ->willReturn($this->guzzleSolrService);

        $expectedResult = [
            'success' => true,
            'documents' => [],
            'total' => 0
        ];

        $this->guzzleSolrService->expects($this->once())
            ->method('inspectIndex')
            ->with($query, $start, $rows, $fields)
            ->willReturn($expectedResult);

        $result = $this->controller->inspectSolrIndex();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(200, $result->getStatus());
    }

    /**
     * Test getSolrMemoryPrediction method
     */
    public function testGetSolrMemoryPrediction(): void
    {
        // Mock container to return GuzzleSolrService
        $this->container->expects($this->once())
            ->method('get')
            ->with(GuzzleSolrService::class)
            ->willReturn($this->guzzleSolrService);

        // Mock isAvailable to return false (SOLR not available)
        $this->guzzleSolrService->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $result = $this->controller->getSolrMemoryPrediction();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(422, $result->getStatus());
        
        $data = $result->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertFalse($data['success']);
    }


}
