<?php

declare(strict_types=1);

/**
 * DeletedControllerTest
 * 
 * Unit tests for the DeletedController
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

use OCA\OpenRegister\Controller\DeletedController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the DeletedController
 *
 * This test class covers all functionality of the DeletedController
 * including soft deleted object management operations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class DeletedControllerTest extends TestCase
{
    /**
     * The DeletedController instance being tested
     *
     * @var DeletedController
     */
    private DeletedController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock object entity mapper
     *
     * @var MockObject|ObjectEntityMapper
     */
    private MockObject $objectEntityMapper;

    /**
     * Mock register mapper
     *
     * @var MockObject|RegisterMapper
     */
    private MockObject $registerMapper;

    /**
     * Mock schema mapper
     *
     * @var MockObject|SchemaMapper
     */
    private MockObject $schemaMapper;

    /**
     * Mock object service
     *
     * @var MockObject|ObjectService
     */
    private MockObject $objectService;

    /**
     * Mock user session
     *
     * @var MockObject|IUserSession
     */
    private MockObject $userSession;

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
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new DeletedController(
            'openregister',
            $this->request,
            $this->objectEntityMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->objectService,
            $this->userSession
        );
    }

    /**
     * Test restore method when object is not deleted
     *
     * @return void
     */
    public function testRestoreNotDeleted(): void
    {
        $id = 'test-uuid-123';
        $mockObject = $this->createMock(ObjectEntity::class);
        $mockObject->method('getDeleted')->willReturn(null);

        $this->objectEntityMapper
            ->expects($this->once())
            ->method('find')
            ->with($id, null, null, true)
            ->willReturn($mockObject);

        $response = $this->controller->restore($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Object is not deleted', $data['error']);
    }

    /**
     * Test restore method when object not found
     *
     * @return void
     */
    public function testRestoreNotFound(): void
    {
        $id = 'non-existent-uuid';

        $this->objectEntityMapper
            ->expects($this->once())
            ->method('find')
            ->with($id, null, null, true)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Object not found'));

        $response = $this->controller->restore($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Failed to restore object', $data['error']);
    }

    /**
     * Test destroy method when object not found
     *
     * @return void
     */
    public function testDestroyNotFound(): void
    {
        $id = 'non-existent-uuid';

        $this->objectEntityMapper
            ->expects($this->once())
            ->method('find')
            ->with($id, null, null, true)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Object not found'));

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Failed to permanently delete object', $data['error']);
    }
}