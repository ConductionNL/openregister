<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\SchemaPropertyValidatorService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Configuration;
use PHPUnit\Framework\TestCase;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

/**
 * Test class for ConfigurationService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class ConfigurationServiceTest extends TestCase
{
    private ConfigurationService $configurationService;
    private SchemaMapper $schemaMapper;
    private RegisterMapper $registerMapper;
    private ObjectEntityMapper $objectEntityMapper;
    private ConfigurationMapper $configurationMapper;
    private SchemaPropertyValidatorService $validator;
    private LoggerInterface $logger;
    private IAppManager $appManager;
    private ContainerInterface $containerInterface;
    private IAppConfig $appConfig;
    private Client $client;
    private ObjectService $objectService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->validator = $this->createMock(SchemaPropertyValidatorService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->containerInterface = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->client = $this->createMock(Client::class);
        $this->objectService = $this->createMock(ObjectService::class);

        // Create ConfigurationService instance
        $this->configurationService = new ConfigurationService(
            $this->schemaMapper,
            $this->registerMapper,
            $this->objectEntityMapper,
            $this->configurationMapper,
            $this->validator,
            $this->logger,
            $this->appManager,
            $this->containerInterface,
            $this->appConfig,
            $this->client,
            $this->objectService
        );
    }

    /**
     * Test getOpenConnector method
     */
    public function testGetOpenConnector(): void
    {
        // Mock app manager to return installed apps array
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['openconnector', 'other-app']);

        // Mock container to return a service
        $this->containerInterface->expects($this->once())
            ->method('get')
            ->with('OCA\OpenConnector\Service\ConfigurationService')
            ->willReturn($this->createMock(\OCA\OpenConnector\Service\ConfigurationService::class));

        $result = $this->configurationService->getOpenConnector();

        $this->assertTrue($result);
    }

    /**
     * Test getOpenConnector method when app is not installed
     */
    public function testGetOpenConnectorWhenNotInstalled(): void
    {
        // Mock app manager to return installed apps array without openconnector
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['other-app', 'another-app']);

        $result = $this->configurationService->getOpenConnector();

        $this->assertFalse($result);
    }

    /**
     * Test exportConfig method with empty input
     */
    public function testExportConfigWithEmptyInput(): void
    {
        $result = $this->configurationService->exportConfig();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('schemas', $result);
        $this->assertArrayHasKey('objects', $result);
    }

    /**
     * Test exportConfig method with register input
     */
    public function testExportConfigWithRegisterInput(): void
    {
        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('jsonSerialize')->willReturn([
            'id' => '1',
            'title' => 'Test Register',
            'description' => 'Test Description'
        ]);

        $result = $this->configurationService->exportConfig($register);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('schemas', $result);
        $this->assertArrayHasKey('objects', $result);
    }

    /**
     * Test exportConfig method with configuration input
     */
    public function testExportConfigWithConfigurationInput(): void
    {
        // Create mock configuration
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getData')->willReturn([
            'registers' => [],
            'schemas' => []
        ]);

        $result = $this->configurationService->exportConfig($configuration);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('schemas', $result);
        $this->assertArrayHasKey('objects', $result);
    }

    /**
     * Test exportConfig method with includeObjects true
     */
    public function testExportConfigWithIncludeObjects(): void
    {
        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('jsonSerialize')->willReturn([
            'id' => '1',
            'title' => 'Test Register'
        ]);

        $result = $this->configurationService->exportConfig($register, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('schemas', $result);
        $this->assertArrayHasKey('objects', $result);
    }

    /**
     * Test getConfiguredAppVersion method
     */
    public function testGetConfiguredAppVersion(): void
    {
        $appId = 'test-app';
        $expectedVersion = '1.0.0';

        // Mock app config to return version
        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with('openregister', 'app_version_' . $appId, '')
            ->willReturn($expectedVersion);

        $result = $this->configurationService->getConfiguredAppVersion($appId);

        $this->assertEquals($expectedVersion, $result);
    }

    /**
     * Test getConfiguredAppVersion method with no version configured
     */
    public function testGetConfiguredAppVersionWithNoVersion(): void
    {
        $appId = 'test-app';

        // Mock app config to return empty string
        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with('openregister', 'app_version_' . $appId, '')
            ->willReturn('');

        $result = $this->configurationService->getConfiguredAppVersion($appId);

        $this->assertNull($result);
    }

    /**
     * Test importFromJson method with valid data
     */
    public function testImportFromJsonWithValidData(): void
    {
        $data = [
            'registers' => [
                [
                    'title' => 'Test Register',
                    'description' => 'Test Description'
                ]
            ],
            'schemas' => [
                [
                    'title' => 'Test Schema',
                    'description' => 'Test Schema Description'
                ]
            ]
        ];

        $owner = 'test-user';
        $appId = 'test-app';
        $version = '1.0.0';

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('insert')
            ->willReturn($this->createMock(Register::class));

        // Mock schema mapper
        $this->schemaMapper->expects($this->once())
            ->method('insert')
            ->willReturn($this->createMock(Schema::class));

        $result = $this->configurationService->importFromJson($data, $owner, $appId, $version);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test importFromJson method with empty data
     */
    public function testImportFromJsonWithEmptyData(): void
    {
        $data = [];

        $result = $this->configurationService->importFromJson($data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test importFromJson method with force flag
     */
    public function testImportFromJsonWithForceFlag(): void
    {
        $data = [
            'registers' => [
                [
                    'title' => 'Test Register',
                    'description' => 'Test Description'
                ]
            ]
        ];

        $owner = 'test-user';
        $appId = 'test-app';
        $version = '1.0.0';
        $force = true;

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('insert')
            ->willReturn($this->createMock(Register::class));

        $result = $this->configurationService->importFromJson($data, $owner, $appId, $version, $force);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }
}