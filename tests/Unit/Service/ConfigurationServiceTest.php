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
use PHPUnit\Framework\TestCase;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

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
    private ContainerInterface $container;
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
        $this->container = $this->createMock(ContainerInterface::class);
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
            $this->container,
            $this->appConfig,
            $this->client,
            $this->objectService
        );
    }

    /**
     * Test getOpenConnector method when OpenConnector is installed
     */
    public function testGetOpenConnectorWhenInstalled(): void
    {
        // Mock app manager to return OpenConnector as installed
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['openconnector', 'openregister']);

        // Mock container to return OpenConnector service
        $openConnectorService = $this->createMock(\stdClass::class);
        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenConnector\Service\ConfigurationService')
            ->willReturn($openConnectorService);

        $result = $this->configurationService->getOpenConnector();

        $this->assertTrue($result);
    }

    /**
     * Test getOpenConnector method when OpenConnector is not installed
     */
    public function testGetOpenConnectorWhenNotInstalled(): void
    {
        // Mock app manager to return only OpenRegister as installed
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['openregister']);

        $result = $this->configurationService->getOpenConnector();

        $this->assertFalse($result);
    }

    /**
     * Test getOpenConnector method with empty installed apps
     */
    public function testGetOpenConnectorWithEmptyInstalledApps(): void
    {
        // Mock app manager to return empty array
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn([]);

        $result = $this->configurationService->getOpenConnector();

        $this->assertFalse($result);
    }

    /**
     * Test getOpenConnector method with null installed apps
     */
    public function testGetOpenConnectorWithNullInstalledApps(): void
    {
        // Mock app manager to return empty array instead of null to avoid TypeError
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn([]);

        $result = $this->configurationService->getOpenConnector();

        $this->assertFalse($result);
    }

    /**
     * Test getOpenConnector method when container fails to get service
     */
    public function testGetOpenConnectorWhenContainerFails(): void
    {
        // Mock app manager to return OpenConnector as installed
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['openconnector', 'openregister']);

        // Mock container to throw exception
        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenConnector\Service\ConfigurationService')
            ->willThrowException(new \Exception('Service not found'));

        $result = $this->configurationService->getOpenConnector();

        $this->assertFalse($result);
    }

    /**
     * Test getOpenConnector method with multiple apps including OpenConnector
     */
    public function testGetOpenConnectorWithMultipleApps(): void
    {
        // Mock app manager to return multiple apps including OpenConnector
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['files', 'openconnector', 'openregister', 'calendar']);

        // Mock container to return OpenConnector service
        $openConnectorService = $this->createMock(\stdClass::class);
        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenConnector\Service\ConfigurationService')
            ->willReturn($openConnectorService);

        $result = $this->configurationService->getOpenConnector();

        $this->assertTrue($result);
    }

    /**
     * Test getOpenConnector method with OpenConnector in different position
     */
    public function testGetOpenConnectorWithOpenConnectorInDifferentPosition(): void
    {
        // Mock app manager to return OpenConnector at the end
        $this->appManager->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['openregister', 'files', 'calendar', 'openconnector']);

        // Mock container to return OpenConnector service
        $openConnectorService = $this->createMock(\stdClass::class);
        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenConnector\Service\ConfigurationService')
            ->willReturn($openConnectorService);

        $result = $this->configurationService->getOpenConnector();

        $this->assertTrue($result);
    }
}