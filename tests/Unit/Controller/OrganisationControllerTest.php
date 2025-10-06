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
        $this->assertEquals(404, $response->getStatus());
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
        $uuid = 'test-uuid';
        $name = 'Updated Organisation';
        $description = 'Updated description';
        $organisation = $this->createMock(\OCA\OpenRegister\Db\Organisation::class);
        $organisation->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Updated Organisation']);

        $this->organisationService->expects($this->once())
            ->method('hasAccessToOrganisation')
            ->with($uuid)
            ->willReturn(true);

        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with($uuid)
            ->willReturn($organisation);

        $this->organisationMapper->expects($this->once())
            ->method('save')
            ->with($organisation)
            ->willReturn($organisation);

        $response = $this->controller->update($uuid, $name, $description);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('organisation', $data);
    }

    /**
     * Test update method with organisation not found
     *
     * @return void
     */
    public function testUpdateOrganisationNotFound(): void
    {
        $uuid = 'non-existent-uuid';
        $name = 'Updated Organisation';

        $this->organisationService->expects($this->once())
            ->method('hasAccessToOrganisation')
            ->with($uuid)
            ->willReturn(true);

        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with($uuid)
            ->willThrowException(new \Exception('Organisation not found'));

        $response = $this->controller->update($uuid, $name);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
    }


    /**
     * Test getActiveOrganisation method with successful retrieval
     *
     * @return void
     */
    public function testGetActiveOrganisationSuccessful(): void
    {
        $activeOrganisation = $this->createMock(\OCA\OpenRegister\Db\Organisation::class);
        $activeOrganisation->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Active Organisation']);

        $this->organisationService->expects($this->once())
            ->method('getActiveOrganisation')
            ->willReturn($activeOrganisation);

        $response = $this->controller->getActive();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('activeOrganisation', $data);
        $this->assertEquals(['id' => 1, 'name' => 'Active Organisation'], $data['activeOrganisation']);
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

        $response = $this->controller->getActive();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('activeOrganisation', $data);
        $this->assertNull($data['activeOrganisation']);
    }

    /**
     * Test setActiveOrganisation method with successful setting
     *
     * @return void
     */
    public function testSetActiveOrganisationSuccessful(): void
    {
        $uuid = 'test-uuid';

        $this->organisationService->expects($this->once())
            ->method('setActiveOrganisation')
            ->with($uuid)
            ->willReturn(true);

        $activeOrganisation = $this->createMock(\OCA\OpenRegister\Db\Organisation::class);
        $activeOrganisation->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Test Organisation']);

        $this->organisationService->expects($this->once())
            ->method('getActiveOrganisation')
            ->willReturn($activeOrganisation);

        $response = $this->controller->setActive($uuid);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('activeOrganisation', $data);
    }

    /**
     * Test setActiveOrganisation method with organisation not found
     *
     * @return void
     */
    public function testSetActiveOrganisationNotFound(): void
    {
        $uuid = 'non-existent-uuid';

        $this->organisationService->expects($this->once())
            ->method('setActiveOrganisation')
            ->with($uuid)
            ->willThrowException(new \Exception('Organisation not found'));

        $response = $this->controller->setActive($uuid);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
    }



}
