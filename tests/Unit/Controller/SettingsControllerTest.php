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
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock app config
     *
     * @var MockObject|IAppConfig
     */
    private MockObject $config;

    /**
     * Mock container
     *
     * @var MockObject|ContainerInterface
     */
    private MockObject $container;

    /**
     * Mock app manager
     *
     * @var MockObject|IAppManager
     */
    private MockObject $appManager;

    /**
     * Mock settings service
     *
     * @var MockObject|SettingsService
     */
    private MockObject $settingsService;

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
     * Test page method returns TemplateResponse
     *
     * @return void
     */
    public function testPageReturnsTemplateResponse(): void
    {
        $response = $this->controller->page();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('openregister', $response->getAppName());
        $this->assertEquals('settings', $response->getTemplateName());
        $this->assertArrayHasKey('appName', $response->getParams());
        $this->assertEquals('openregister', $response->getParams()['appName']);
    }

    /**
     * Test getObjectService method when app is installed
     *
     * @return void
     */
    public function testGetObjectServiceWhenAppInstalled(): void
    {
        $objectService = $this->createMock(ObjectService::class);

        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['openregister', 'other-app']);

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->controller->getObjectService();

        $this->assertSame($objectService, $result);
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
        $configurationService = $this->createMock(ConfigurationService::class);

        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['openregister', 'other-app']);

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ConfigurationService')
            ->willReturn($configurationService);

        $result = $this->controller->getConfigurationService();

        $this->assertSame($configurationService, $result);
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
        $this->expectExceptionMessage('OpenRegister service is not available.');

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

        $response = $this->controller->getSettings();

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

        $response = $this->controller->getSettings();

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
            ->willReturn(true);

        $response = $this->controller->updateSettings();

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

        $response = $this->controller->updateSettings();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
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
            ->method('resetSettings')
            ->willReturn(true);

        $response = $this->controller->resetSettings();

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
            ->method('resetSettings')
            ->willThrowException(new \Exception('Reset failed'));

        $response = $this->controller->resetSettings();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals(['error' => 'Reset failed'], $response->getData());
    }

    /**
     * Test getAppConfig method returns app configuration
     *
     * @return void
     */
    public function testGetAppConfigReturnsConfiguration(): void
    {
        $expectedConfig = [
            'version' => '1.0.0',
            'enabled' => true
        ];

        $this->config->expects($this->once())
            ->method('getAppKeys')
            ->with('openregister')
            ->willReturn(['version', 'enabled']);

        $this->config->expects($this->exactly(2))
            ->method('getAppValue')
            ->willReturnMap([
                ['openregister', 'version', '', '1.0.0'],
                ['openregister', 'enabled', '', 'true']
            ]);

        $response = $this->controller->getAppConfig();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($expectedConfig, $response->getData());
    }

    /**
     * Test getAppConfig method with exception
     *
     * @return void
     */
    public function testGetAppConfigWithException(): void
    {
        $this->config->expects($this->once())
            ->method('getAppKeys')
            ->willThrowException(new \Exception('Config error'));

        $response = $this->controller->getAppConfig();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals(['error' => 'Config error'], $response->getData());
    }
}
