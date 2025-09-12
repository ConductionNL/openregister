<?php

declare(strict_types=1);

/**
 * OrganisationControllerTest
 * 
 * Unit tests for the OrganisationController
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

use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the OrganisationController
 *
 * This test class covers all functionality of the OrganisationController
 * including organisation management and multi-tenancy operations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class OrganisationControllerTest extends TestCase
{
    /**
     * The OrganisationController instance being tested
     *
     * @var OrganisationController
     */
    private OrganisationController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock organisation service
     *
     * @var MockObject|OrganisationService
     */
    private MockObject $organisationService;

    /**
     * Mock organisation mapper
     *
     * @var MockObject|OrganisationMapper
     */
    private MockObject $organisationMapper;

    /**
     * Mock logger
     *
     * @var MockObject|LoggerInterface
     */
    private MockObject $logger;

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
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new OrganisationController(
            'openregister',
            $this->request,
            $this->organisationService,
            $this->organisationMapper,
            $this->logger
        );
    }

    /**
     * Test index method with successful organisation listing
     *
     * @return void
     */
    public function testIndexSuccessful(): void
    {
        $organisations = [
            ['id' => 1, 'name' => 'Organisation 1'],
            ['id' => 2, 'name' => 'Organisation 2']
        ];

        $this->organisationService->expects($this->once())
            ->method('getUserOrganisationStats')
            ->willReturn($organisations);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($organisations, $response->getData());
    }

    /**
     * Test index method with exception
     *
     * @return void
     */
    public function testIndexWithException(): void
    {
        $this->organisationService->expects($this->once())
            ->method('getUserOrganisationStats')
            ->willThrowException(new \Exception('Service error'));

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals(['error' => 'Failed to retrieve organisations'], $response->getData());
    }

    /**
     * Test show method with successful organisation retrieval
     *
     * @return void
     */
    public function testShowSuccessful(): void
    {
        $uuid = 'test-uuid';
        $organisation = $this->createMock(\OCA\OpenRegister\Db\Organisation::class);
        $organisation->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Test Organisation']);

        $this->organisationService->expects($this->once())
            ->method('hasAccessToOrganisation')
            ->with($uuid)
            ->willReturn(true);

        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with($uuid)
            ->willReturn($organisation);

        $response = $this->controller->show($uuid);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('organisation', $data);
        $this->assertEquals(['id' => 1, 'name' => 'Test Organisation'], $data['organisation']);
    }

    /**
     * Test show method with organisation not found
     *
     * @return void
     */
    public function testShowOrganisationNotFound(): void
    {
        $uuid = 'non-existent-uuid';

        $this->organisationService->expects($this->once())
            ->method('hasAccessToOrganisation')
            ->with($uuid)
            ->willReturn(true);

        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with($uuid)
            ->willThrowException(new \Exception('Organisation not found'));

        $response = $this->controller->show($uuid);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test create method with successful organisation creation
     *
     * @return void
     */
    public function testCreateSuccessful(): void
    {
        $data = ['name' => 'New Organisation', 'description' => 'Test description'];
        $createdOrganisation = $this->createMock(\OCA\OpenRegister\Db\Organisation::class);
        $createdOrganisation->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'New Organisation']);

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->organisationService->expects($this->once())
            ->method('createOrganisation')
            ->with($data['name'], $data['description'], true, '')
            ->willReturn($createdOrganisation);

        $response = $this->controller->create($data['name'], $data['description']);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(201, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('organisation', $data);
    }

    /**
     * Test create method with validation error
     *
     * @return void
     */
    public function testCreateWithValidationError(): void
    {
        $response = $this->controller->create('', '');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals(['error' => 'Organisation name is required'], $response->getData());
    }

    /**
     * Test update method with successful organisation update
     *
     * @return void
     */
    public function testUpdateSuccessful(): void
    {
        $id = 1;
        $data = ['name' => 'Updated Organisation'];
        $updatedOrganisation = ['id' => 1, 'name' => 'Updated Organisation'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->organisationService->expects($this->once())
            ->method('update')
            ->with($id, $data)
            ->willReturn($updatedOrganisation);

        $response = $this->controller->update($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($updatedOrganisation, $response->getData());
    }

    /**
     * Test update method with organisation not found
     *
     * @return void
     */
    public function testUpdateOrganisationNotFound(): void
    {
        $id = 999;
        $data = ['name' => 'Updated Organisation'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->organisationService->expects($this->once())
            ->method('update')
            ->willThrowException(new \Exception('Organisation not found'));

        $response = $this->controller->update($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Organisation not found'], $response->getData());
    }

    /**
     * Test destroy method with successful organisation deletion
     *
     * @return void
     */
    public function testDestroySuccessful(): void
    {
        $id = 1;

        $this->organisationService->expects($this->once())
            ->method('delete')
            ->with($id)
            ->willReturn(true);

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['success' => true], $response->getData());
    }

    /**
     * Test destroy method with organisation not found
     *
     * @return void
     */
    public function testDestroyOrganisationNotFound(): void
    {
        $id = 999;

        $this->organisationService->expects($this->once())
            ->method('delete')
            ->willThrowException(new \Exception('Organisation not found'));

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Organisation not found'], $response->getData());
    }

    /**
     * Test getActiveOrganisation method with successful retrieval
     *
     * @return void
     */
    public function testGetActiveOrganisationSuccessful(): void
    {
        $activeOrganisation = ['id' => 1, 'name' => 'Active Organisation'];

        $this->organisationService->expects($this->once())
            ->method('getActiveOrganisation')
            ->willReturn($activeOrganisation);

        $response = $this->controller->getActiveOrganisation();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($activeOrganisation, $response->getData());
    }

    /**
     * Test getActiveOrganisation method with no active organisation
     *
     * @return void
     */
    public function testGetActiveOrganisationNotFound(): void
    {
        $this->organisationService->expects($this->once())
            ->method('getActiveOrganisation')
            ->willReturn(null);

        $response = $this->controller->getActiveOrganisation();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'No active organisation'], $response->getData());
    }

    /**
     * Test setActiveOrganisation method with successful setting
     *
     * @return void
     */
    public function testSetActiveOrganisationSuccessful(): void
    {
        $id = 1;

        $this->organisationService->expects($this->once())
            ->method('setActiveOrganisation')
            ->with($id)
            ->willReturn(true);

        $response = $this->controller->setActiveOrganisation($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['success' => true], $response->getData());
    }

    /**
     * Test setActiveOrganisation method with organisation not found
     *
     * @return void
     */
    public function testSetActiveOrganisationNotFound(): void
    {
        $id = 999;

        $this->organisationService->expects($this->once())
            ->method('setActiveOrganisation')
            ->willThrowException(new \Exception('Organisation not found'));

        $response = $this->controller->setActiveOrganisation($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Organisation not found'], $response->getData());
    }

    /**
     * Test getUserOrganisations method with successful retrieval
     *
     * @return void
     */
    public function testGetUserOrganisationsSuccessful(): void
    {
        $userId = 'user123';
        $organisations = [
            ['id' => 1, 'name' => 'Organisation 1'],
            ['id' => 2, 'name' => 'Organisation 2']
        ];

        $this->organisationService->expects($this->once())
            ->method('getUserOrganisations')
            ->with($userId)
            ->willReturn($organisations);

        $response = $this->controller->getUserOrganisations($userId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($organisations, $response->getData());
    }

    /**
     * Test addUserToOrganisation method with successful addition
     *
     * @return void
     */
    public function testAddUserToOrganisationSuccessful(): void
    {
        $organisationId = 1;
        $userId = 'user123';
        $role = 'member';

        $this->organisationService->expects($this->once())
            ->method('addUserToOrganisation')
            ->with($organisationId, $userId, $role)
            ->willReturn(true);

        $response = $this->controller->addUserToOrganisation($organisationId, $userId, $role);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['success' => true], $response->getData());
    }

    /**
     * Test removeUserFromOrganisation method with successful removal
     *
     * @return void
     */
    public function testRemoveUserFromOrganisationSuccessful(): void
    {
        $organisationId = 1;
        $userId = 'user123';

        $this->organisationService->expects($this->once())
            ->method('removeUserFromOrganisation')
            ->with($organisationId, $userId)
            ->willReturn(true);

        $response = $this->controller->removeUserFromOrganisation($organisationId, $userId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['success' => true], $response->getData());
    }

    /**
     * Test getOrganisationUsers method with successful retrieval
     *
     * @return void
     */
    public function testGetOrganisationUsersSuccessful(): void
    {
        $organisationId = 1;
        $users = [
            ['id' => 'user1', 'name' => 'User 1', 'role' => 'admin'],
            ['id' => 'user2', 'name' => 'User 2', 'role' => 'member']
        ];

        $this->organisationService->expects($this->once())
            ->method('getOrganisationUsers')
            ->with($organisationId)
            ->willReturn($users);

        $response = $this->controller->getOrganisationUsers($organisationId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($users, $response->getData());
    }
}
