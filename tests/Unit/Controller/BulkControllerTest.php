<?php

declare(strict_types=1);

/**
 * BulkControllerTest
 * 
 * Unit tests for the BulkController
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

use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the BulkController
 *
 * This test class covers all functionality of the BulkController
 * including bulk operations on objects.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class BulkControllerTest extends TestCase
{
    /**
     * The BulkController instance being tested
     *
     * @var BulkController
     */
    private BulkController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

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
     * Mock group manager
     *
     * @var MockObject|IGroupManager
     */
    private MockObject $groupManager;

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
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new BulkController(
            'openregister',
            $this->request,
            $this->objectService,
            $this->userSession,
            $this->groupManager
        );
    }

    /**
     * Helper method to mock admin user
     *
     * @return void
     */
    private function mockAdminUser(): void
    {
        $mockUser = $this->createMock(IUser::class);

        $this->userSession
            ->expects($this->once())
            ->method('getUser')
            ->willReturn($mockUser);

        $mockUser
            ->expects($this->once())
            ->method('getUID')
            ->willReturn('admin');

        $this->groupManager
            ->expects($this->once())
            ->method('isAdmin')
            ->with('admin')
            ->willReturn(true);
    }

    /**
     * Test delete method with successful bulk deletion
     *
     * @return void
     */
    public function testDeleteSuccessful(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objectIds = ['obj-1', 'obj-2', 'obj-3'];
        $deletedUuids = ['obj-1', 'obj-2'];

        $this->mockAdminUser();

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn(['uuids' => $objectIds]);

        $this->objectService
            ->expects($this->once())
            ->method('setRegister')
            ->with($register)
            ->willReturn($this->objectService);

        $this->objectService
            ->expects($this->once())
            ->method('setSchema')
            ->with($schema)
            ->willReturn($this->objectService);

        $this->objectService
            ->expects($this->once())
            ->method('deleteObjects')
            ->with($objectIds)
            ->willReturn($deletedUuids);

        $response = $this->controller->delete($register, $schema);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('deleted_count', $data);
        $this->assertArrayHasKey('deleted_uuids', $data);
        $this->assertArrayHasKey('requested_count', $data);
        $this->assertArrayHasKey('skipped_count', $data);
        
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['deleted_count']);
        $this->assertEquals($deletedUuids, $data['deleted_uuids']);
        $this->assertEquals(3, $data['requested_count']);
        $this->assertEquals(1, $data['skipped_count']);
    }

    /**
     * Test delete method with no objects provided
     *
     * @return void
     */
    public function testDeleteNoObjects(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';

        $this->mockAdminUser();

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn(['uuids' => []]);

        $response = $this->controller->delete($register, $schema);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Invalid input. "uuids" array is required.', $response->getData()['error']);
    }

    /**
     * Test publish method with successful bulk publishing
     *
     * @return void
     */
    public function testPublishSuccessful(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objectIds = ['obj-1', 'obj-2', 'obj-3'];
        $publishedUuids = ['obj-1', 'obj-2'];

        $this->mockAdminUser();

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn(['uuids' => $objectIds]);

        $this->objectService
            ->expects($this->once())
            ->method('setRegister')
            ->with($register)
            ->willReturn($this->objectService);

        $this->objectService
            ->expects($this->once())
            ->method('setSchema')
            ->with($schema)
            ->willReturn($this->objectService);

        $this->objectService
            ->expects($this->once())
            ->method('publishObjects')
            ->with($objectIds)
            ->willReturn($publishedUuids);

        $response = $this->controller->publish($register, $schema);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['published_count']);
        $this->assertEquals($publishedUuids, $data['published_uuids']);
    }

    /**
     * Test depublish method with successful bulk depublishing
     *
     * @return void
     */
    public function testDepublishSuccessful(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objectIds = ['obj-1', 'obj-2', 'obj-3'];
        $depublishedUuids = ['obj-1', 'obj-2'];

        $this->mockAdminUser();

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn(['uuids' => $objectIds]);

        $this->objectService
            ->expects($this->once())
            ->method('setRegister')
            ->with($register)
            ->willReturn($this->objectService);

        $this->objectService
            ->expects($this->once())
            ->method('setSchema')
            ->with($schema)
            ->willReturn($this->objectService);

        $this->objectService
            ->expects($this->once())
            ->method('depublishObjects')
            ->with($objectIds)
            ->willReturn($depublishedUuids);

        $response = $this->controller->depublish($register, $schema);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['depublished_count']);
        $this->assertEquals($depublishedUuids, $data['depublished_uuids']);
    }

    /**
     * Test save method with successful bulk save
     *
     * @return void
     */
    public function testSaveSuccessful(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objects = [
            ['id' => 'obj-1', 'name' => 'Object 1'],
            ['id' => 'obj-2', 'name' => 'Object 2']
        ];
        $savedObjects = [
            'statistics' => [
                'saved' => 1,
                'updated' => 1
            ],
            'objects' => ['obj-1', 'obj-2']
        ];

        $this->mockAdminUser();

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn(['objects' => $objects]);

        $this->objectService
            ->expects($this->once())
            ->method('setRegister')
            ->with($register)
            ->willReturn($this->objectService);

        $this->objectService
            ->expects($this->once())
            ->method('setSchema')
            ->with($schema)
            ->willReturn($this->objectService);

        $this->objectService
            ->expects($this->once())
            ->method('saveObjects')
            ->with($objects, $register, $schema, true, true, true, false)
            ->willReturn($savedObjects);

        $response = $this->controller->save($register, $schema);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['saved_count']);
        $this->assertEquals($savedObjects, $data['saved_objects']);
    }

    /**
     * Test save method with no objects provided
     *
     * @return void
     */
    public function testSaveNoObjects(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';

        $this->mockAdminUser();

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn(['objects' => []]);

        $response = $this->controller->save($register, $schema);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Invalid input. "objects" array is required.', $response->getData()['error']);
    }
}