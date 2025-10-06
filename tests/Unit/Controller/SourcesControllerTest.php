<?php

declare(strict_types=1);

/**
 * SourcesControllerTest
 * 
 * Unit tests for the SourcesController
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

use OCA\OpenRegister\Controller\SourcesController;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the SourcesController
 *
 * This test class covers all functionality of the SourcesController
 * including source management operations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class SourcesControllerTest extends TestCase
{
    /**
     * The SourcesController instance being tested
     *
     * @var SourcesController
     */
    private SourcesController $controller;

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
     * Mock source mapper
     *
     * @var MockObject|SourceMapper
     */
    private MockObject $sourceMapper;

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
        $this->sourceMapper = $this->createMock(SourceMapper::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new SourcesController(
            'openregister',
            $this->request,
            $this->config,
            $this->sourceMapper
        );
    }

    /**
     * Test page method returns template response
     *
     * @return void
     */
    public function testPageReturnsTemplateResponse(): void
    {
        $response = $this->controller->page();

        $this->assertInstanceOf(TemplateResponse::class, $response);
    }

    /**
     * Test index method with successful sources listing
     *
     * @return void
     */
    public function testIndexSuccessful(): void
    {
        $sources = [
            ['id' => 1, 'name' => 'Source 1', 'type' => 'api'],
            ['id' => 2, 'name' => 'Source 2', 'type' => 'database']
        ];
        $objectService = $this->createMock(ObjectService::class);

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn(['search' => 'test']);

        $this->sourceMapper
            ->expects($this->once())
            ->method('findAll')
            ->with(
                null, // limit
                null, // offset
                [], // filters (after removing special params)
                ['(title LIKE ? OR description LIKE ?)'], // searchConditions
                ['%test%', '%test%'] // searchParams
            )
            ->willReturn($sources);

        $response = $this->controller->index($objectService);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertEquals($sources, $data['results']);
    }

    /**
     * Test show method with successful source retrieval
     *
     * @return void
     */
    public function testShowSuccessful(): void
    {
        $id = '123';
        $source = $this->createMock(Source::class);

        $this->sourceMapper
            ->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($source);

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($source, $response->getData());
    }

    /**
     * Test show method when source not found
     *
     * @return void
     */
    public function testShowSourceNotFound(): void
    {
        $id = '123';

        $this->sourceMapper
            ->expects($this->once())
            ->method('find')
            ->with($id)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Source not found'));

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Not Found', $response->getData()['error']);
    }

    /**
     * Test create method with successful source creation
     *
     * @return void
     */
    public function testCreateSuccessful(): void
    {
        $sourceData = [
            'name' => 'New Source',
            'type' => 'api',
            'url' => 'https://api.example.com'
        ];
        $createdSource = $this->createMock(Source::class);

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn($sourceData);

        $this->sourceMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->with($sourceData)
            ->willReturn($createdSource);

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($createdSource, $response->getData());
    }


    /**
     * Test update method with successful source update
     *
     * @return void
     */
    public function testUpdateSuccessful(): void
    {
        $id = 123;
        $sourceData = [
            'name' => 'Updated Source',
            'type' => 'database'
        ];
        $updatedSource = $this->createMock(Source::class);

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn($sourceData);

        $this->sourceMapper
            ->expects($this->once())
            ->method('updateFromArray')
            ->with($id, $sourceData)
            ->willReturn($updatedSource);

        $response = $this->controller->update($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($updatedSource, $response->getData());
    }


    /**
     * Test destroy method with successful source deletion
     *
     * @return void
     */
    public function testDestroySuccessful(): void
    {
        $id = 123;
        $mockSource = $this->createMock(Source::class);

        $this->sourceMapper
            ->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($mockSource);

        $this->sourceMapper
            ->expects($this->once())
            ->method('delete')
            ->with($mockSource)
            ->willReturn($mockSource);

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals([], $response->getData());
    }

}