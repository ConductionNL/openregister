<?php

declare(strict_types=1);

/**
 * ConfigurationsControllerTest
 * 
 * Unit tests for the ConfigurationsController
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

use OCA\OpenRegister\Controller\ConfigurationsController;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the ConfigurationsController
 *
 * This test class covers all functionality of the ConfigurationsController
 * including configuration management operations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class ConfigurationsControllerTest extends TestCase
{
    /**
     * The ConfigurationsController instance being tested
     *
     * @var ConfigurationsController
     */
    private ConfigurationsController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock configuration mapper
     *
     * @var MockObject|ConfigurationMapper
     */
    private MockObject $configurationMapper;

    /**
     * Mock configuration service
     *
     * @var MockObject|ConfigurationService
     */
    private MockObject $configurationService;

    /**
     * Mock upload service
     *
     * @var MockObject|UploadService
     */
    private MockObject $uploadService;

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
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->uploadService = $this->createMock(UploadService::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new ConfigurationsController(
            'openregister',
            $this->request,
            $this->configurationMapper,
            $this->configurationService,
            $this->uploadService
        );
    }

    /**
     * Test index method with successful configurations listing
     *
     * @return void
     */
    public function testIndexSuccessful(): void
    {
        $configurations = [
            ['id' => 1, 'title' => 'Config 1', 'description' => 'Description 1'],
            ['id' => 2, 'title' => 'Config 2', 'description' => 'Description 2']
        ];

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn([]);

        $this->configurationMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn($configurations);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertEquals($configurations, $data['results']);
    }

    /**
     * Test show method with successful configuration retrieval
     *
     * @return void
     */
    public function testShowSuccessful(): void
    {
        $id = 123;
        $mockConfiguration = $this->createMock(Configuration::class);

        $this->configurationMapper
            ->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($mockConfiguration);

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($mockConfiguration, $response->getData());
    }

    /**
     * Test show method when configuration not found
     *
     * @return void
     */
    public function testShowNotFound(): void
    {
        $id = 123;

        $this->configurationMapper
            ->expects($this->once())
            ->method('find')
            ->with($id)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Configuration not found'));

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Configuration not found', $response->getData()['error']);
    }

    /**
     * Test create method with successful configuration creation
     *
     * @return void
     */
    public function testCreateSuccessful(): void
    {
        $configurationData = [
            'title' => 'New Config',
            'description' => 'New Description',
            'data' => ['key' => 'value']
        ];
        $createdConfiguration = $this->createMock(Configuration::class);

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn($configurationData);

        $this->configurationMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->with($this->isType('array'))
            ->willReturn($createdConfiguration);

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($createdConfiguration, $response->getData());
    }

    /**
     * Test create method with validation error
     *
     * @return void
     */
    public function testCreateWithValidationError(): void
    {
        $configurationData = [
            'description' => 'Missing title'
        ];

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn($configurationData);

        $this->configurationMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->willThrowException(new \InvalidArgumentException('Title is required'));

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Failed to create configuration: Title is required', $response->getData()['error']);
    }

    /**
     * Test update method with successful configuration update
     *
     * @return void
     */
    public function testUpdateSuccessful(): void
    {
        $id = 123;
        $configurationData = [
            'title' => 'Updated Config',
            'description' => 'Updated Description'
        ];
        $updatedConfiguration = $this->createMock(Configuration::class);

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn($configurationData);

        $this->configurationMapper
            ->expects($this->once())
            ->method('updateFromArray')
            ->with($id, $this->isType('array'))
            ->willReturn($updatedConfiguration);

        $response = $this->controller->update($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($updatedConfiguration, $response->getData());
    }

    /**
     * Test update method when configuration not found
     *
     * @return void
     */
    public function testUpdateNotFound(): void
    {
        $id = 123;
        $configurationData = ['title' => 'Updated Config'];

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn($configurationData);

        $this->configurationMapper
            ->expects($this->once())
            ->method('updateFromArray')
            ->with($id, $this->isType('array'))
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Configuration not found'));

        $response = $this->controller->update($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Failed to update configuration: Configuration not found', $response->getData()['error']);
    }

    /**
     * Test destroy method with successful configuration deletion
     *
     * @return void
     */
    public function testDestroySuccessful(): void
    {
        $id = 123;
        $mockConfiguration = $this->createMock(Configuration::class);

        $this->configurationMapper
            ->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($mockConfiguration);

        $this->configurationMapper
            ->expects($this->once())
            ->method('delete')
            ->with($mockConfiguration)
            ->willReturn($mockConfiguration);

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals([], $response->getData());
    }

    /**
     * Test destroy method when configuration not found
     *
     * @return void
     */
    public function testDestroyNotFound(): void
    {
        $id = 123;

        $this->configurationMapper
            ->expects($this->once())
            ->method('find')
            ->with($id)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Configuration not found'));

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Failed to delete configuration: Configuration not found', $response->getData()['error']);
    }
}