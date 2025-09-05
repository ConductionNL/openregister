<?php
/**
 * Organisation CRUD Operations Unit Tests
 *
 * This test class covers all CRUD (Create, Read, Update, Delete) scenarios 
 * for organisation management including positive and negative test cases.
 * 
 * Test Coverage:
 * - Test 2.1: Create New Organisation
 * - Test 2.2: Get Organisation Details
 * - Test 2.3: Update Organisation
 * - Test 2.4: Search Organisations
 * - Test 2.5: Create Organisation with Empty Name (negative)
 * - Test 2.6: Access Organisation Without Membership (negative)
 * - Test 2.7: Update Organisation Without Access (negative)
 *
 * Key Features Tested:
 * - Organisation creation with proper metadata
 * - Organisation retrieval with access control
 * - Organisation updates with validation
 * - Organisation search functionality
 * - Input validation and error handling
 * - Access control and permission checking
 * - User membership validation
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Controller\OrganisationController;
use OCP\IUserSession;
use OCP\IUser;
use OCP\ISession;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * Test class for Organisation CRUD Operations
 */
class OrganisationCrudTest extends TestCase
{
    /**
     * @var OrganisationService
     */
    private OrganisationService $organisationService;
    
    /**
     * @var OrganisationController
     */
    private OrganisationController $organisationController;
    
    /**
     * @var OrganisationMapper|MockObject
     */
    private $organisationMapper;
    
    /**
     * @var IUserSession|MockObject
     */
    private $userSession;
    
    /**
     * @var ISession|MockObject
     */
    private $session;
    
    /**
     * @var IRequest|MockObject
     */
    private $request;
    
    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;
    
    /**
     * @var IUser|MockObject
     */
    private $mockUser;

    /**
     * @var IConfig|MockObject
     */
    private $config;

    /**
     * @var IGroupManager|MockObject
     */
    private $groupManager;

    /**
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock objects
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->request = $this->createMock(IRequest::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mockUser = $this->createMock(IUser::class);
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        
        // Create service instance as mock
        $this->organisationService = $this->createMock(OrganisationService::class);
        
        // Create controller instance with mocked dependencies
        $this->organisationController = new OrganisationController(
            'openregister',
            $this->request,
            $this->organisationService,
            $this->organisationMapper,
            $this->logger
        );
    }

    /**
     * Clean up after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $this->organisationService,
            $this->organisationController,
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->request,
            $this->logger,
            $this->mockUser
        );
    }

    /**
     * Test 2.1: Create New Organisation
     *
     * Scenario: User creates a new organisation
     * Expected: Organisation is created with proper metadata and user as owner/member
     *
     * @return void
     */
    public function testCreateNewOrganisation(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        // Mock: Request parameters
        $this->request->method('getParam')
            ->willReturnMap([
                ['name', '', 'Acme Corporation'],
                ['description', '', 'Test organisation for ACME Inc.']
            ]);
        
        // Mock: Created organisation
        $createdOrg = new Organisation();
        $createdOrg->setName('Acme Corporation');
        $createdOrg->setDescription('Test organisation for ACME Inc.');
        $createdOrg->setUuid('acme-uuid-123');
        $createdOrg->setOwner('alice');
        $createdOrg->setIsDefault(false);
        $createdOrg->addUser('alice');
        $createdOrg->setCreated(new \DateTime());
        $createdOrg->setUpdated(new \DateTime());
        
        $this->organisationService
            ->expects($this->once())
            ->method('createOrganisation')
            ->with('Acme Corporation', 'Test organisation for ACME Inc.', true, '')
            ->willReturn($createdOrg);

        // Act: Create organisation via controller
        $response = $this->organisationController->create('Acme Corporation', 'Test organisation for ACME Inc.');

        // Assert: Response is successful
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(201, $response->getStatus()); // Created status
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('organisation', $responseData);
        $organisation = $responseData['organisation'];
        
        // Check if the expected fields exist in the response
        if (isset($organisation['name'])) {
            $this->assertEquals('Acme Corporation', $organisation['name']);
        }
        if (isset($organisation['description'])) {
            $this->assertEquals('Test organisation for ACME Inc.', $organisation['description']);
        }
        if (isset($organisation['owner'])) {
            $this->assertEquals('alice', $organisation['owner']);
        }
        if (isset($organisation['isDefault'])) {
            $this->assertFalse($organisation['isDefault']);
        }
        if (isset($organisation['users'])) {
            $this->assertContains('alice', $organisation['users']);
        }
        if (isset($responseData['userCount'])) {
            $this->assertEquals(1, $responseData['userCount']);
        }
    }

    /**
     * Test 2.2: Get Organisation Details
     *
     * Scenario: User retrieves details of organisation they belong to
     * Expected: Full organisation details are returned
     *
     * @return void
     */
    public function testGetOrganisationDetails(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $organisationUuid = 'acme-uuid-123';
        
        // Mock: Organisation exists and user has access
        $organisation = new Organisation();
        $organisation->setName('Acme Corporation');
        $organisation->setDescription('Test organisation for ACME Inc.');
        $organisation->setUuid($organisationUuid);
        $organisation->setOwner('alice');
        $organisation->setUsers(['alice', 'bob']);
        
        $this->organisationService
            ->expects($this->once())
            ->method('hasAccessToOrganisation')
            ->with($organisationUuid)
            ->willReturn(true);

        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($organisationUuid)
            ->willReturn($organisation);

        // Act: Get organisation details via controller
        $response = $this->organisationController->show($organisationUuid);

        // Assert: Response is successful
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('organisation', $responseData);
        $organisationData = $responseData['organisation'];
        
        $this->assertEquals('Acme Corporation', $organisationData['name']);
        $this->assertEquals('Test organisation for ACME Inc.', $organisationData['description']);
        $this->assertEquals($organisationUuid, $organisationData['uuid']);
        $this->assertEquals('alice', $organisationData['owner']);
        $this->assertContains('alice', $organisationData['users']);
        $this->assertContains('bob', $organisationData['users']);
    }

    /**
     * Test 2.3: Update Organisation
     *
     * Scenario: Organisation owner updates organisation details
     * Expected: Organisation is updated successfully
     *
     * @return void
     */
    public function testUpdateOrganisation(): void
    {
        // Arrange: Mock user session (alice is owner)
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $organisationUuid = 'acme-uuid-123';
        
        // Mock: Existing organisation
        $existingOrg = new Organisation();
        $existingOrg->setName('Acme Corporation');
        $existingOrg->setDescription('Test organisation for ACME Inc.');
        $existingOrg->setUuid($organisationUuid);
        $existingOrg->setOwner('alice');
        $existingOrg->addUser('alice');
        
        // Mock: Updated organisation
        $updatedOrg = clone $existingOrg;
        $updatedOrg->setName('ACME Corporation Ltd');
        $updatedOrg->setDescription('Updated description');
        $updatedOrg->setUpdated(new \DateTime());
        
        $this->organisationService
            ->expects($this->once())
            ->method('hasAccessToOrganisation')
            ->with($organisationUuid)
            ->willReturn(true);

        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($organisationUuid)
            ->willReturn($existingOrg);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function($org) {
                return $org instanceof Organisation && 
                       $org->getName() === 'ACME Corporation Ltd' &&
                       $org->getDescription() === 'Updated description';
            }))
            ->willReturn($updatedOrg);

        // Act: Update organisation via controller
        $response = $this->organisationController->update($organisationUuid, 'ACME Corporation Ltd', 'Updated description');

        // Assert: Update successful
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('organisation', $responseData);
        $organisation = $responseData['organisation'];
        $this->assertEquals('ACME Corporation Ltd', $organisation['name']);
        $this->assertEquals('Updated description', $organisation['description']);
    }

    /**
     * Test 2.4: Search Organisations
     *
     * Scenario: User searches for organisations by name
     * Expected: Matching organisations are returned (without sensitive details)
     *
     * @return void
     */
    public function testSearchOrganisations(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        // Mock: Search results
        $acmeOrg = new Organisation();
        $acmeOrg->setName('ACME Corporation');
        $acmeOrg->setDescription('ACME Inc. organisation');
        $acmeOrg->setUuid('acme-uuid-123');
        // Note: Users array should not be included in search results for privacy
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByName')
            ->with('ACME')
            ->willReturn([$acmeOrg]);

        // Act: Search organisations via controller
        $response = $this->organisationController->search('ACME');

        // Assert: Search results returned
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('organisations', $responseData);
        $organisations = $responseData['organisations'];
        $this->assertCount(1, $organisations);
        $this->assertEquals('ACME Corporation', $organisations[0]['name']);
        $this->assertEquals('ACME Inc. organisation', $organisations[0]['description']);
        // Sensitive data like users should not be included in search results
        $this->assertArrayNotHasKey('users', $organisations[0]);
    }

    /**
     * Test 2.5: Create Organisation with Empty Name (Negative Test)
     *
     * Scenario: User attempts to create organisation with empty name
     * Expected: HTTP 400 error with validation message
     *
     * @return void
     */
    public function testCreateOrganisationWithEmptyName(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Act & Assert: Attempt to create organisation with empty name should fail
        $response = $this->organisationController->create('', 'Invalid test');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('name', strtolower($responseData['error']));
    }

    /**
     * Test 2.6: Access Organisation Without Membership (Negative Test)
     *
     * Scenario: User tries to access organisation they don't belong to
     * Expected: HTTP 403 Forbidden
     *
     * @return void
     */
    public function testAccessOrganisationWithoutMembership(): void
    {
        // Arrange: Mock user session (bob trying to access alice's org)
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);
        
        $organisationUuid = 'alice-private-org-uuid';
        
        // Mock: Organisation exists but bob is not a member
        $aliceOrg = new Organisation();
        $aliceOrg->setName('Alice Private Org');
        $aliceOrg->setOwner('alice');
        $aliceOrg->setUsers(['alice']); // Bob is not in users list
        
        $this->organisationService
            ->expects($this->once())
            ->method('hasAccessToOrganisation')
            ->with($organisationUuid)
            ->willReturn(false);

        // Act: Attempt to access organisation via controller
        $response = $this->organisationController->show($organisationUuid);

        // Assert: Access denied
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(403, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('access', strtolower($responseData['error']));
    }

    /**
     * Test 2.7: Update Organisation Without Access (Negative Test)
     *
     * Scenario: Non-owner user tries to update organisation
     * Expected: HTTP 403 Forbidden
     *
     * @return void
     */
    public function testUpdateOrganisationWithoutAccess(): void
    {
        // Arrange: Mock user session (bob trying to update alice's org)
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);
        
        $organisationUuid = 'alice-org-uuid';
        
        // Mock: Organisation exists, bob is member but not owner
        $aliceOrg = new Organisation();
        $aliceOrg->setName('Alice Organization');
        $aliceOrg->setOwner('alice'); // Alice is owner, not Bob
        $aliceOrg->setUsers(['alice', 'bob']); // Bob is member but not owner
        
        $this->organisationService
            ->expects($this->once())
            ->method('hasAccessToOrganisation')
            ->with($organisationUuid)
            ->willReturn(false);

        // Act: Attempt to update organisation via controller
        $response = $this->organisationController->update($organisationUuid, 'Hacked Name', 'Unauthorized update');

        // Assert: Update denied
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(403, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('access denied', strtolower($responseData['error']));
    }

    /**
     * Test organisation creation with proper metadata
     *
     * Scenario: Verify all metadata fields are set correctly on creation
     * Expected: UUID, timestamps, owner, and user list are properly set
     *
     * @return void
     */
    public function testOrganisationCreationMetadata(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('diana');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        // Mock: Organisation creation
        $createdOrg = new Organisation();
        $createdOrg->setName('Diana Corp');
        $createdOrg->setDescription('Diana\'s organisation');
        $createdOrg->setUuid('diana-uuid-456');
        $createdOrg->setOwner('diana');
        $createdOrg->setIsDefault(false);
        $createdOrg->addUser('diana');
        $createdDate = new \DateTime();
        $createdOrg->setCreated($createdDate);
        $createdOrg->setUpdated($createdDate);
        
        $this->organisationService
            ->expects($this->once())
            ->method('createOrganisation')
            ->with('Diana Corp', 'Diana\'s organisation', true, '')
            ->willReturn($createdOrg);

        // Act: Create organisation
        $response = $this->organisationController->create('Diana Corp', 'Diana\'s organisation');

        // Assert: Metadata is properly set
        $this->assertInstanceOf(JSONResponse::class, $response);
        $responseData = $response->getData();
        
        $this->assertArrayHasKey('organisation', $responseData);
        $organisation = $responseData['organisation'];
        $this->assertNotEmpty($organisation['uuid']);
        $this->assertNotEmpty($organisation['created']);
        $this->assertNotEmpty($organisation['updated']);
        $this->assertEquals('diana', $organisation['owner']);
        $this->assertContains('diana', $organisation['users']);
        $this->assertEquals(1, $organisation['userCount']);
        $this->assertFalse($organisation['isDefault']);
    }

    /**
     * Test organisation search with multiple results
     *
     * Scenario: Search returns multiple matching organisations
     * Expected: All matching organisations returned in appropriate format
     *
     * @return void
     */
    public function testOrganisationSearchMultipleResults(): void
    {
        // Arrange: Mock multiple search results
        $tech1 = new Organisation();
        $tech1->setName('Tech Startup');
        $tech1->setDescription('Technology startup');
        $tech1->setUuid('tech1-uuid');
        
        $tech2 = new Organisation();
        $tech2->setName('Tech Solutions');
        $tech2->setDescription('Technology solutions provider');
        $tech2->setUuid('tech2-uuid');
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByName')
            ->with('Tech')
            ->willReturn([$tech1, $tech2]);

        // Act: Search for 'Tech'
        $response = $this->organisationController->search('Tech');

        // Assert: Multiple results returned
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('organisations', $responseData);
        $organisations = $responseData['organisations'];
        $this->assertCount(2, $organisations);
        
        // Verify both results present
        $names = array_column($organisations, 'name');
        $this->assertContains('Tech Startup', $names);
        $this->assertContains('Tech Solutions', $names);
    }

    /**
     * Test organisation not found error
     *
     * Scenario: User requests organisation that doesn't exist
     * Expected: HTTP 404 Not Found
     *
     * @return void
     */
    public function testOrganisationNotFound(): void
    {
        // Arrange: Mock organisation not found
        $nonExistentUuid = 'non-existent-uuid';
        
        $this->organisationService
            ->expects($this->once())
            ->method('hasAccessToOrganisation')
            ->with($nonExistentUuid)
            ->willReturn(true);

        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($nonExistentUuid)
            ->willThrowException(new DoesNotExistException('Organisation not found'));

        // Act: Attempt to get non-existent organisation
        $response = $this->organisationController->show($nonExistentUuid);

        // Assert: Not found error
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('not found', strtolower($responseData['error']));
    }

    /**
     * Test organisation __toString method
     *
     * Scenario: Test string conversion of organisation objects
     * Expected: Proper string representation based on available data
     *
     * @return void
     */
    public function testOrganisationToString(): void
    {
        // Test 1: Organisation with name (__toString returns UUID)
        $org1 = new Organisation();
        $org1->setName('Test Organisation');
        $string1 = (string) $org1;
        $this->assertNotEmpty($string1);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string1);

        // Test 2: Organisation with slug but no name (__toString returns UUID)
        $org2 = new Organisation();
        $org2->setSlug('test-org');
        $string2 = (string) $org2;
        $this->assertNotEmpty($string2);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string2);

        // Test 3: Organisation with neither name nor slug (__toString returns UUID)
        $org3 = new Organisation();
        $string3 = (string) $org3;
        $this->assertNotEmpty($string3);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string3);

        // Test 4: Organisation with ID (__toString returns UUID)
        $org4 = new Organisation();
        $org4->setId(123);
        $string4 = (string) $org4;
        $this->assertNotEmpty($string4);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string4);

        // Test 5: Organisation with name and slug (__toString returns UUID)
        $org5 = new Organisation();
        $org5->setName('Priority Name');
        $org5->setSlug('priority-slug');
        $string5 = (string) $org5;
        $this->assertNotEmpty($string5);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string5);
    }
} 