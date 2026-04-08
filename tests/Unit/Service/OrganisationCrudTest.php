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
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCA\OpenRegister\Controller\OrganisationController;
use OCP\IUserSession;
use OCP\IUser;
use OCP\ISession;
use OCP\IConfig;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IRequest;
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
     * @var IConfig|MockObject
     */
    private $config;

    /**
     * @var IAppConfig|MockObject
     */
    private $appConfig;

    /**
     * @var IGroupManager|MockObject
     */
    private $groupManager;

    /**
     * @var IUserManager|MockObject
     */
    private $userManager;

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
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Reset static caches between tests.
        $reflection = new \ReflectionClass(OrganisationService::class);

        $defaultOrgCache = $reflection->getProperty('defaultOrgCache');
        $defaultOrgCache->setAccessible(true);
        $defaultOrgCache->setValue(null, null);

        $defaultOrgCacheTs = $reflection->getProperty('defaultOrgCacheTs');
        $defaultOrgCacheTs->setAccessible(true);
        $defaultOrgCacheTs->setValue(null, null);

        $userOrgsCache = $reflection->getProperty('userOrgsCache');
        $userOrgsCache->setAccessible(true);
        $userOrgsCache->setValue(null, []);

        // Create mock objects.
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->config = $this->createMock(IConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->request = $this->createMock(IRequest::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mockUser = $this->createMock(IUser::class);

        // Create service instance with mocked dependencies.
        $this->organisationService = new OrganisationService(
            organisationMapper: $this->organisationMapper,
            userSession: $this->userSession,
            session: $this->session,
            config: $this->config,
            appConfig: $this->appConfig,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            logger: $this->logger
        );

        // Create controller instance with mocked dependencies.
        $this->organisationController = new OrganisationController(
            appName: 'openregister',
            request: $this->request,
            organisationService: $this->organisationService,
            organisationMapper: $this->organisationMapper,
            logger: $this->logger,
            tenantLifecycleService: $this->createMock(TenantLifecycleService::class),
            tenantUsageMapper: $this->createMock(TenantUsageMapper::class)
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
            $this->config,
            $this->appConfig,
            $this->groupManager,
            $this->userManager,
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
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: Request parameters (for uuid extraction in controller).
        $this->request->method('getParams')->willReturn([]);

        // Mock: Created organisation returned by mapper save.
        $createdOrg = new Organisation();
        $createdOrg->setName('Acme Corporation');
        $createdOrg->setDescription('Test organisation for ACME Inc.');
        $createdOrg->setUuid('acme-uuid-123');
        $createdOrg->setOwner('alice');
        $createdOrg->addUser('alice');
        $createdOrg->setCreated(new \DateTime());
        $createdOrg->setUpdated(new \DateTime());

        // createOrganisation uses save() not insert().
        $this->organisationMapper
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function($org) {
                return $org instanceof Organisation &&
                       $org->getName() === 'Acme Corporation' &&
                       $org->getDescription() === 'Test organisation for ACME Inc.' &&
                       $org->getOwner() === 'alice' &&
                       $org->hasUser('alice');
            }))
            ->willReturn($createdOrg);

        // Mock: appConfig for default organisation check.
        $this->appConfig->method('getValueString')
            ->willReturn('existing-default-uuid');

        // Mock: groupManager for addAdminUsersToOrganisation.
        $this->groupManager->method('get')->willReturn(null);

        // Act: Create organisation via controller.
        $response = $this->organisationController->create(name: 'Acme Corporation', description: 'Test organisation for ACME Inc.');

        // Assert: Response is successful (201 Created).
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(201, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('organisation', $responseData);
        $this->assertEquals('Acme Corporation', $responseData['organisation']['name']);
        $this->assertEquals('Test organisation for ACME Inc.', $responseData['organisation']['description']);
        $this->assertEquals('alice', $responseData['organisation']['owner']);
    }

    /**
     * Test 2.2: Get Organisation Details
     *
     * Scenario: User retrieves details of organisation they belong to
     * Expected: Full organisation details are returned via mapper
     *
     * @return void
     */
    public function testGetOrganisationDetails(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $organisationUuid = 'acme-uuid-123';

        // Mock: Organisation exists and user has access.
        $organisation = new Organisation();
        $organisation->setName('Acme Corporation');
        $organisation->setDescription('Test organisation for ACME Inc.');
        $organisation->setUuid($organisationUuid);
        $organisation->setOwner('alice');
        $organisation->setUsers(['alice', 'bob']);

        $this->organisationMapper
            ->expects($this->atLeastOnce())
            ->method('findByUuid')
            ->with($organisationUuid)
            ->willReturn($organisation);

        // Mock: groupManager for hasAccessToOrganisation.
        $this->groupManager->method('isAdmin')->willReturn(false);

        // Mock: findChildrenChain for show().
        $this->organisationMapper
            ->method('findChildrenChain')
            ->willReturn([]);

        // Act: Get organisation details via controller show().
        $result = $this->organisationController->show(uuid: $organisationUuid);

        // Assert: Organisation details returned correctly.
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(200, $result->getStatus());

        $responseData = $result->getData();
        $this->assertArrayHasKey('organisation', $responseData);
        $this->assertEquals('Acme Corporation', $responseData['organisation']['name']);
        $this->assertEquals($organisationUuid, $responseData['organisation']['uuid']);
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
        // Arrange: Mock user session (alice is owner).
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $organisationUuid = 'acme-uuid-123';

        // Mock: Existing organisation.
        $existingOrg = new Organisation();
        $existingOrg->setName('Acme Corporation');
        $existingOrg->setDescription('Test organisation for ACME Inc.');
        $existingOrg->setUuid($organisationUuid);
        $existingOrg->setOwner('alice');
        $existingOrg->addUser('alice');

        // Mock: groupManager for hasAccessToOrganisation.
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->organisationMapper
            ->expects($this->atLeastOnce())
            ->method('findByUuid')
            ->with($organisationUuid)
            ->willReturn($existingOrg);

        // Mock: Request data for update.
        $this->request->method('getParams')->willReturn([
            'name' => 'ACME Corporation Ltd',
            'description' => 'Updated description'
        ]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['name', null, 'ACME Corporation Ltd'],
                ['description', null, 'Updated description']
            ]);

        // Mock: Updated organisation returned by mapper save (update uses save).
        $updatedOrg = clone $existingOrg;
        $updatedOrg->setName('ACME Corporation Ltd');
        $updatedOrg->setDescription('Updated description');
        $updatedOrg->setUpdated(new \DateTime());

        $this->organisationMapper
            ->expects($this->once())
            ->method('save')
            ->willReturn($updatedOrg);

        // Act: Update organisation via controller (only takes uuid now).
        $response = $this->organisationController->update(uuid: $organisationUuid);

        // Assert: Update successful.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
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
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: Request params for pagination.
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, '50'],
                ['_offset', 0, '0']
            ]);

        // Mock: Search results.
        $acmeOrg = new Organisation();
        $acmeOrg->setName('ACME Corporation');
        $acmeOrg->setDescription('ACME Inc. organisation');
        $acmeOrg->setUuid('acme-uuid-123');

        $this->organisationMapper
            ->expects($this->once())
            ->method('findByName')
            ->with(name: 'ACME', limit: 50, offset: 0)
            ->willReturn([$acmeOrg]);

        // Act: Search organisations via controller.
        $response = $this->organisationController->search(query: 'ACME');

        // Assert: Search results returned.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('organisations', $responseData);
        $this->assertCount(1, $responseData['organisations']);
        $this->assertEquals('ACME Corporation', $responseData['organisations'][0]['name']);
        // Sensitive data like users and owner should not be included in search results.
        $this->assertArrayNotHasKey('users', $responseData['organisations'][0]);
        $this->assertArrayNotHasKey('owner', $responseData['organisations'][0]);
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
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Act & Assert: Attempt to create organisation with empty name should fail.
        $response = $this->organisationController->create(name: '', description: 'Invalid test');

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
        // Arrange: Mock user session (bob trying to access alice's org).
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);

        $organisationUuid = 'alice-private-org-uuid';

        // Mock: Organisation exists but bob is not a member.
        $aliceOrg = new Organisation();
        $aliceOrg->setName('Alice Private Org');
        $aliceOrg->setOwner('alice');
        $aliceOrg->setUuid($organisationUuid);
        $aliceOrg->setUsers(['alice']); // Bob is not in users list

        $this->organisationMapper
            ->method('findByUuid')
            ->with($organisationUuid)
            ->willReturn($aliceOrg);

        // Mock: groupManager for hasAccessToOrganisation (bob is not admin).
        $this->groupManager->method('isAdmin')->willReturn(false);

        // Act: Attempt to access organisation via controller.
        $response = $this->organisationController->show(uuid: $organisationUuid);

        // Assert: Access denied.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(403, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('access', strtolower($responseData['error']));
    }

    /**
     * Test 2.7: Update Organisation Without Access (Negative Test)
     *
     * Scenario: Non-member user tries to update organisation
     * Expected: HTTP 403 Forbidden
     *
     * @return void
     */
    public function testUpdateOrganisationWithoutAccess(): void
    {
        // Arrange: Mock user session (bob trying to update alice's org).
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);

        $organisationUuid = 'alice-org-uuid';

        // Mock: Organisation exists, bob is not a member.
        $aliceOrg = new Organisation();
        $aliceOrg->setName('Alice Organization');
        $aliceOrg->setOwner('alice');
        $aliceOrg->setUuid($organisationUuid);
        $aliceOrg->setUsers(['alice']); // Bob not a member

        $this->organisationMapper
            ->method('findByUuid')
            ->with($organisationUuid)
            ->willReturn($aliceOrg);

        // Mock: groupManager for hasAccessToOrganisation (bob is not admin).
        $this->groupManager->method('isAdmin')->willReturn(false);

        // Act: Attempt to update organisation via controller.
        $response = $this->organisationController->update(uuid: $organisationUuid);

        // Assert: Update denied.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(403, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('access', strtolower($responseData['error']));
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
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('diana');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: Request parameters.
        $this->request->method('getParams')->willReturn([]);

        // Mock: Organisation creation.
        $createdOrg = new Organisation();
        $createdOrg->setName('Diana Corp');
        $createdOrg->setDescription('Diana\'s organisation');
        $createdOrg->setUuid('diana-uuid-456');
        $createdOrg->setOwner('diana');
        $createdOrg->addUser('diana');
        $createdDate = new \DateTime();
        $createdOrg->setCreated($createdDate);
        $createdOrg->setUpdated($createdDate);

        // createOrganisation uses save() not insert().
        $this->organisationMapper
            ->expects($this->once())
            ->method('save')
            ->willReturn($createdOrg);

        // Mock: appConfig for default organisation check.
        $this->appConfig->method('getValueString')
            ->willReturn('existing-default-uuid');

        // Mock: groupManager for addAdminUsersToOrganisation.
        $this->groupManager->method('get')->willReturn(null);

        // Act: Create organisation.
        $response = $this->organisationController->create(name: 'Diana Corp', description: 'Diana\'s organisation');

        // Assert: Metadata is properly set (201 Created with {message, organisation}).
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(201, $response->getStatus());
        $responseData = $response->getData();

        $this->assertArrayHasKey('organisation', $responseData);
        $orgData = $responseData['organisation'];
        $this->assertNotEmpty($orgData['uuid']);
        $this->assertNotEmpty($orgData['created']);
        $this->assertNotEmpty($orgData['updated']);
        $this->assertEquals('diana', $orgData['owner']);
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
        // Arrange: Mock multiple search results.
        $tech1 = new Organisation();
        $tech1->setName('Tech Startup');
        $tech1->setDescription('Technology startup');
        $tech1->setUuid('tech1-uuid');

        $tech2 = new Organisation();
        $tech2->setName('Tech Solutions');
        $tech2->setDescription('Technology solutions provider');
        $tech2->setUuid('tech2-uuid');

        // Mock: Request params for pagination.
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, '50'],
                ['_offset', 0, '0']
            ]);

        $this->organisationMapper
            ->expects($this->once())
            ->method('findByName')
            ->with(name: 'Tech', limit: 50, offset: 0)
            ->willReturn([$tech1, $tech2]);

        // Act: Search for 'Tech'.
        $response = $this->organisationController->search(query: 'Tech');

        // Assert: Multiple results returned.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('organisations', $responseData);
        $this->assertCount(2, $responseData['organisations']);

        // Verify both results present.
        $names = array_column($responseData['organisations'], 'name');
        $this->assertContains('Tech Startup', $names);
        $this->assertContains('Tech Solutions', $names);
    }

    /**
     * Test organisation not found error
     *
     * Scenario: User requests organisation that doesn't exist
     * Expected: When org doesn't exist, hasAccessToOrganisation returns false
     *           causing show() to return 403 before it can return 404.
     *           To get a 404, the access check must pass (admin user) but findByUuid
     *           must throw in show()'s own try block.
     *
     * @return void
     */
    public function testOrganisationNotFound(): void
    {
        // Arrange: Mock user session as admin (so hasAccessToOrganisation returns true
        // even for non-existent org, allowing show() to attempt findByUuid and get 404).
        $this->mockUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $nonExistentUuid = 'non-existent-uuid';

        // Mock: findByUuid always throws (org doesn't exist).
        $this->organisationMapper
            ->method('findByUuid')
            ->with($nonExistentUuid)
            ->willThrowException(new DoesNotExistException('Organisation not found'));

        // Mock: Admin user bypasses access check in hasAccessToOrganisation
        // but hasAccessToOrganisation catches the DoesNotExistException and returns false.
        // So for non-existent orgs, show() will return 403 "Access denied".
        $this->groupManager->method('isAdmin')->willReturn(false);

        // Act: Attempt to get non-existent organisation.
        $response = $this->organisationController->show(uuid: $nonExistentUuid);

        // Assert: Access denied (because hasAccessToOrganisation returns false for non-existent orgs).
        $this->assertInstanceOf(JSONResponse::class, $response);
        // show() returns 403 because hasAccessToOrganisation catches DoesNotExistException -> false.
        $this->assertEquals(403, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test getOrganisationSettingsOnly returns default values when config is empty
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnlyEmptyConfig(): void
    {
        // Arrange: appConfig returns empty string (no stored config).
        $this->appConfig->method('getValueString')
            ->with('openregister', 'organisation', '')
            ->willReturn('');

        // Act.
        $result = $this->organisationService->getOrganisationSettingsOnly();

        // Assert: defaults returned.
        $this->assertArrayHasKey('organisation', $result);
        $this->assertNull($result['organisation']['default_organisation']);
        $this->assertTrue($result['organisation']['auto_create_default_organisation']);
    }

    /**
     * Test getOrganisationSettingsOnly returns stored values when config exists
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnlyWithStoredConfig(): void
    {
        // Arrange: appConfig returns stored JSON data.
        $storedConfig = json_encode([
            'default_organisation' => 'stored-uuid-123',
            'auto_create_default_organisation' => false,
        ]);
        $this->appConfig->method('getValueString')
            ->with('openregister', 'organisation', '')
            ->willReturn($storedConfig);

        // Act.
        $result = $this->organisationService->getOrganisationSettingsOnly();

        // Assert: stored values returned.
        $this->assertEquals('stored-uuid-123', $result['organisation']['default_organisation']);
        $this->assertFalse($result['organisation']['auto_create_default_organisation']);
    }

    /**
     * Test getDefaultOrganisationUuid returns direct config key value
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidDirectKey(): void
    {
        // Arrange: appConfig returns UUID from direct key (newer format).
        $this->appConfig->method('getValueString')
            ->willReturnMap([
                ['openregister', 'defaultOrganisation', '', 'direct-uuid-456'],
                ['openregister', 'organisation', '', ''],
            ]);

        // Act.
        $result = $this->organisationService->getDefaultOrganisationUuid();

        // Assert.
        $this->assertEquals('direct-uuid-456', $result);
    }

    /**
     * Test getDefaultOrganisationUuid falls back to nested config
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidFallbackToNested(): void
    {
        // Arrange: direct key empty, nested config has value.
        $nestedConfig = json_encode([
            'default_organisation' => 'nested-uuid-789',
        ]);
        $this->appConfig->method('getValueString')
            ->willReturnMap([
                ['openregister', 'defaultOrganisation', '', ''],
                ['openregister', 'organisation', '', $nestedConfig],
            ]);

        // Act.
        $result = $this->organisationService->getDefaultOrganisationUuid();

        // Assert.
        $this->assertEquals('nested-uuid-789', $result);
    }

    /**
     * Test getDefaultOrganisationUuid returns null when nothing configured
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidReturnsNullWhenEmpty(): void
    {
        // Arrange: both config sources empty.
        $this->appConfig->method('getValueString')
            ->willReturnMap([
                ['openregister', 'defaultOrganisation', '', ''],
                ['openregister', 'organisation', '', ''],
            ]);

        // Act.
        $result = $this->organisationService->getDefaultOrganisationUuid();

        // Assert.
        $this->assertNull($result);
    }

    /**
     * Test getDefaultOrganisationId returns UUID when configured
     *
     * @return void
     */
    public function testGetDefaultOrganisationIdReturnsUuid(): void
    {
        // Arrange.
        $this->appConfig->method('getValueString')
            ->with('openregister', 'defaultOrganisation', '')
            ->willReturn('config-uuid-123');

        // Act.
        $result = $this->organisationService->getDefaultOrganisationId();

        // Assert.
        $this->assertEquals('config-uuid-123', $result);
    }

    /**
     * Test getDefaultOrganisationId returns null when not configured
     *
     * @return void
     */
    public function testGetDefaultOrganisationIdReturnsNull(): void
    {
        // Arrange.
        $this->appConfig->method('getValueString')
            ->with('openregister', 'defaultOrganisation', '')
            ->willReturn('');

        // Act.
        $result = $this->organisationService->getDefaultOrganisationId();

        // Assert.
        $this->assertNull($result);
    }

    /**
     * Test setDefaultOrganisationId stores UUID and clears cache
     *
     * @return void
     */
    public function testSetDefaultOrganisationId(): void
    {
        // Arrange: expect appConfig to be called with the UUID.
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'defaultOrganisation', 'new-default-uuid');

        // Act.
        $this->organisationService->setDefaultOrganisationId('new-default-uuid');

        // Assert: static cache was cleared (verify by checking property via reflection).
        $reflection = new \ReflectionClass(OrganisationService::class);
        $cacheProperty = $reflection->getProperty('defaultOrgCache');
        $cacheProperty->setAccessible(true);
        $this->assertNull($cacheProperty->getValue());
    }

    /**
     * Test createOrganisation with invalid UUID format throws exception
     *
     * @return void
     */
    public function testCreateOrganisationWithInvalidUuid(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Act & Assert: Invalid UUID should throw.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid UUID format');

        $this->organisationService->createOrganisation(
            'Test Org',
            'Description',
            true,
            'not-a-valid-uuid'
        );
    }

    /**
     * Test createOrganisation without logged-in user (no current user)
     *
     * @return void
     */
    public function testCreateOrganisationWithoutUser(): void
    {
        // Arrange: No user logged in.
        $this->userSession->method('getUser')->willReturn(null);

        // Mock: groupManager for addAdminUsersToOrganisation.
        $this->groupManager->method('get')->willReturn(null);

        // Mock: mapper save.
        $savedOrg = new Organisation();
        $savedOrg->setName('No User Org');
        $savedOrg->setUuid('no-user-uuid');

        $this->organisationMapper
            ->expects($this->once())
            ->method('save')
            ->willReturn($savedOrg);

        // Mock: appConfig for default organisation check.
        $this->appConfig->method('getValueString')
            ->willReturn('existing-default-uuid');

        // Act: Create without user - should succeed but with no owner.
        $result = $this->organisationService->createOrganisation('No User Org', 'test');

        // Assert: Organisation created without owner.
        $this->assertInstanceOf(Organisation::class, $result);
        $this->assertEquals('No User Org', $result->getName());
    }

    /**
     * Test createOrganisation sets as default when no default exists
     *
     * @return void
     */
    public function testCreateOrganisationSetsAsDefaultWhenNoneExists(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: No existing default organisation.
        $this->appConfig->method('getValueString')
            ->with('openregister', 'defaultOrganisation', '')
            ->willReturn('');

        // Mock: groupManager for addAdminUsersToOrganisation.
        $this->groupManager->method('get')->willReturn(null);

        // Mock: mapper save returns org with UUID.
        $savedOrg = new Organisation();
        $savedOrg->setName('First Org');
        $savedOrg->setUuid('first-org-uuid');
        $savedOrg->setOwner('alice');
        $savedOrg->setUsers(['alice']);

        $this->organisationMapper
            ->method('save')
            ->willReturn($savedOrg);

        // Expect: setValueString called to set this as default.
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'defaultOrganisation', 'first-org-uuid');

        // Act.
        $result = $this->organisationService->createOrganisation('First Org', 'desc');

        // Assert.
        $this->assertEquals('first-org-uuid', $result->getUuid());
    }

    /**
     * Test createOrganisation with a valid specific UUID
     *
     * @return void
     */
    public function testCreateOrganisationWithValidUuid(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $specificUuid = '550e8400-e29b-41d4-a716-446655440000';

        // Mock: groupManager for addAdminUsersToOrganisation.
        $this->groupManager->method('get')->willReturn(null);

        // Mock: mapper save verifies UUID was set.
        $savedOrg = new Organisation();
        $savedOrg->setName('UUID Org');
        $savedOrg->setUuid($specificUuid);
        $savedOrg->setOwner('alice');
        $savedOrg->setUsers(['alice']);

        $this->organisationMapper
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($org) use ($specificUuid) {
                return $org instanceof Organisation
                    && $org->getUuid() === $specificUuid;
            }))
            ->willReturn($savedOrg);

        // Mock: appConfig for default organisation check.
        $this->appConfig->method('getValueString')
            ->willReturn('existing-default');

        // Act.
        $result = $this->organisationService->createOrganisation(
            'UUID Org',
            'desc',
            true,
            $specificUuid
        );

        // Assert.
        $this->assertEquals($specificUuid, $result->getUuid());
    }

    /**
     * Test createOrganisation adds admin group users when admin group exists
     *
     * @return void
     */
    public function testCreateOrganisationAddsAdminGroupUsers(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: admin group exists with users.
        $adminUser1 = $this->createMock(\OCP\IUser::class);
        $adminUser1->method('getUID')->willReturn('admin1');

        $adminUser2 = $this->createMock(\OCP\IUser::class);
        $adminUser2->method('getUID')->willReturn('admin2');

        $adminGroup = $this->createMock(\OCP\IGroup::class);
        $adminGroup->method('getUsers')->willReturn([$adminUser1, $adminUser2]);

        $this->groupManager->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        // Mock: mapper save captures the org with admin users added.
        $savedOrg = new Organisation();
        $savedOrg->setName('Admin Org');
        $savedOrg->setUuid('admin-org-uuid');
        $savedOrg->setOwner('alice');
        $savedOrg->setUsers(['alice', 'admin1', 'admin2']);

        $this->organisationMapper
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($org) {
                // Verify admin users were added.
                return $org instanceof Organisation
                    && $org->hasUser('admin1')
                    && $org->hasUser('admin2')
                    && $org->hasUser('alice');
            }))
            ->willReturn($savedOrg);

        // Mock: appConfig for default organisation check.
        $this->appConfig->method('getValueString')
            ->willReturn('existing-default');

        // Act.
        $result = $this->organisationService->createOrganisation('Admin Org', 'desc');

        // Assert.
        $this->assertInstanceOf(Organisation::class, $result);
    }

    /**
     * Test getOrganisationSettingsOnly throws RuntimeException on failure
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnlyThrowsOnFailure(): void
    {
        // Arrange: appConfig throws an exception.
        $this->appConfig->method('getValueString')
            ->willThrowException(new \Exception('Config error'));

        // Act & Assert.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve Organisation settings');

        $this->organisationService->getOrganisationSettingsOnly();
    }

    /**
     * Test getDefaultOrganisationUuid returns null on exception
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidReturnsNullOnException(): void
    {
        // Arrange: appConfig throws an exception.
        $this->appConfig->method('getValueString')
            ->willThrowException(new \Exception('Config error'));

        // Act.
        $result = $this->organisationService->getDefaultOrganisationUuid();

        // Assert: returns null, does not throw.
        $this->assertNull($result);
    }

    /**
     * Test getUserOrganisations returns empty array when no user is logged in
     *
     * @return void
     */
    public function testGetUserOrganisationsReturnsEmptyWhenNoUser(): void
    {
        // Arrange: No user.
        $this->userSession->method('getUser')->willReturn(null);

        // Act.
        $result = $this->organisationService->getUserOrganisations();

        // Assert.
        $this->assertSame([], $result);
    }

    /**
     * Test getUserOrganisations returns organisations for logged-in user
     *
     * @return void
     */
    public function testGetUserOrganisationsReturnsOrganisationsForUser(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $org = new Organisation();
        $org->setName('Alice Org');
        $org->setUuid('alice-org-uuid');
        $org->addUser('alice');

        $this->organisationMapper
            ->method('findByUserId')
            ->with('alice')
            ->willReturn([$org]);

        // Act.
        $result = $this->organisationService->getUserOrganisations();

        // Assert.
        $this->assertCount(1, $result);
        $this->assertEquals('Alice Org', $result[0]->getName());
    }

    /**
     * Test hasAccessToOrganisation returns true for admin users
     *
     * @return void
     */
    public function testHasAccessToOrganisationReturnsTrueForAdmin(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $org = new Organisation();
        $org->setUuid('some-org-uuid');
        $org->setName('Some Org');
        $org->setUsers(['other-user']);

        $this->organisationMapper
            ->method('findByUuid')
            ->with('some-org-uuid')
            ->willReturn($org);

        // Admin check returns true.
        $this->groupManager->method('isAdmin')->willReturn(true);

        // Act.
        $result = $this->organisationService->hasAccessToOrganisation('some-org-uuid');

        // Assert.
        $this->assertTrue($result);
    }

    /**
     * Test hasAccessToOrganisation returns false when not a member
     *
     * @return void
     */
    public function testHasAccessToOrganisationReturnsFalseForNonMember(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $org = new Organisation();
        $org->setUuid('alice-org');
        $org->setUsers(['alice']);

        $this->organisationMapper
            ->method('findByUuid')
            ->with('alice-org')
            ->willReturn($org);

        $this->groupManager->method('isAdmin')->willReturn(false);

        // Act.
        $result = $this->organisationService->hasAccessToOrganisation('alice-org');

        // Assert.
        $this->assertFalse($result);
    }

    /**
     * Test hasAccessToOrganisation returns false when no user logged in
     *
     * @return void
     */
    public function testHasAccessToOrganisationReturnsFalseWhenNoUser(): void
    {
        // Arrange.
        $this->userSession->method('getUser')->willReturn(null);

        $org = new Organisation();
        $org->setUuid('some-uuid');
        $org->setUsers(['alice']);

        $this->organisationMapper
            ->method('findByUuid')
            ->willReturn($org);

        // Act.
        $result = $this->organisationService->hasAccessToOrganisation('some-uuid');

        // Assert.
        $this->assertFalse($result);
    }

    /**
     * Test hasAccessToOrganisation returns false when organisation not found
     *
     * @return void
     */
    public function testHasAccessToOrganisationReturnsFalseWhenOrgNotFound(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $this->organisationMapper
            ->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        // Act.
        $result = $this->organisationService->hasAccessToOrganisation('nonexistent-uuid');

        // Assert.
        $this->assertFalse($result);
    }

    /**
     * Test setActiveOrganisation throws when no user is logged in
     *
     * @return void
     */
    public function testSetActiveOrganisationThrowsWhenNoUser(): void
    {
        // Arrange.
        $this->userSession->method('getUser')->willReturn(null);

        // Act & Assert.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->organisationService->setActiveOrganisation('some-uuid');
    }

    /**
     * Test setActiveOrganisation throws when organisation not found
     *
     * @return void
     */
    public function testSetActiveOrganisationThrowsWhenOrgNotFound(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $this->organisationMapper
            ->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        // Act & Assert.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Organisation not found');

        $this->organisationService->setActiveOrganisation('nonexistent-uuid');
    }

    /**
     * Test setActiveOrganisation throws when user not a member
     *
     * @return void
     */
    public function testSetActiveOrganisationThrowsWhenUserNotMember(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $aliceOrg = new Organisation();
        $aliceOrg->setUuid('alice-org');
        $aliceOrg->setUsers(['alice']);
        // bob not in users.

        $this->organisationMapper
            ->method('findByUuid')
            ->willReturn($aliceOrg);

        // Act & Assert.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('User does not belong to this organisation');

        $this->organisationService->setActiveOrganisation('alice-org');
    }

    /**
     * Test setActiveOrganisation succeeds for member user
     *
     * @return void
     */
    public function testSetActiveOrganisationSucceedsForMember(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $org = new Organisation();
        $org->setUuid('alice-org');
        $org->setUsers(['alice']);
        $org->setName('Alice Org');

        $this->organisationMapper
            ->method('findByUuid')
            ->willReturn($org);

        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'openregister', 'active_organisation', 'alice-org');

        $this->session->method('remove')->willReturn(null);
        $this->session->method('set')->willReturn(null);
        $this->session->method('get')->willReturn(null);

        // Act.
        $result = $this->organisationService->setActiveOrganisation('alice-org');

        // Assert.
        $this->assertTrue($result);
    }

    /**
     * Test joinOrganisation throws when no user logged in
     *
     * @return void
     */
    public function testJoinOrganisationThrowsWhenNoUser(): void
    {
        // Arrange.
        $this->userSession->method('getUser')->willReturn(null);

        // Act & Assert.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->organisationService->joinOrganisation('some-uuid');
    }

    /**
     * Test joinOrganisation throws when org not found
     *
     * @return void
     */
    public function testJoinOrganisationThrowsWhenOrgNotFound(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $this->organisationMapper
            ->method('addUserToOrganisation')
            ->willThrowException(new DoesNotExistException('Not found'));

        // Act & Assert.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Organisation not found');

        $this->organisationService->joinOrganisation('nonexistent-uuid');
    }

    /**
     * Test joinOrganisation succeeds for current user
     *
     * @return void
     */
    public function testJoinOrganisationSucceedsForCurrentUser(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $this->organisationMapper
            ->expects($this->once())
            ->method('addUserToOrganisation')
            ->with('org-uuid', 'alice');

        $this->session->method('remove')->willReturn(null);

        // Act.
        $result = $this->organisationService->joinOrganisation('org-uuid');

        // Assert.
        $this->assertTrue($result);
    }

    /**
     * Test joinOrganisation throws when target user doesn't exist
     *
     * @return void
     */
    public function testJoinOrganisationThrowsWhenTargetUserDoesNotExist(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Target user 'ghost' doesn't exist.
        $this->userManager->method('get')
            ->with('ghost')
            ->willReturn(null);

        // Act & Assert.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Target user not found');

        $this->organisationService->joinOrganisation('org-uuid', 'ghost');
    }

    /**
     * Test leaveOrganisation throws when no user logged in
     *
     * @return void
     */
    public function testLeaveOrganisationThrowsWhenNoUser(): void
    {
        // Arrange.
        $this->userSession->method('getUser')->willReturn(null);

        // Act & Assert.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->organisationService->leaveOrganisation('some-uuid');
    }

    /**
     * Test leaveOrganisation throws when it's the user's last organisation
     *
     * @return void
     */
    public function testLeaveOrganisationThrowsWhenLastOrg(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Alice has only one organisation.
        $org = new Organisation();
        $org->setUuid('only-org');
        $org->setUsers(['alice']);

        $this->organisationMapper
            ->method('findByUserId')
            ->willReturn([$org]);

        // Act & Assert.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot leave last organisation');

        $this->organisationService->leaveOrganisation('only-org');
    }

    /**
     * Test clearCache returns false when no user logged in
     *
     * @return void
     */
    public function testClearCacheReturnsFalseWhenNoUser(): void
    {
        // Arrange.
        $this->userSession->method('getUser')->willReturn(null);

        // Act.
        $result = $this->organisationService->clearCache();

        // Assert.
        $this->assertFalse($result);
    }

    /**
     * Test clearCache returns true and clears caches when user is logged in
     *
     * @return void
     */
    public function testClearCacheReturnsTrueForLoggedInUser(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $this->session->expects($this->atLeastOnce())
            ->method('remove');

        // Act.
        $result = $this->organisationService->clearCache();

        // Assert.
        $this->assertTrue($result);
    }

    /**
     * Test clearCache with clearPersistent=true deletes user value
     *
     * @return void
     */
    public function testClearCacheWithPersistentDeletesUserValue(): void
    {
        // Arrange.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('alice', 'openregister', 'active_organisation');

        // Act.
        $result = $this->organisationService->clearCache(true);

        // Assert.
        $this->assertTrue($result);
    }

    /**
     * Test getUserOrganisationStats returns empty stats when no user
     *
     * @return void
     */
    public function testGetUserOrganisationStatsReturnsEmptyWhenNoUser(): void
    {
        // Arrange.
        $this->userSession->method('getUser')->willReturn(null);

        // Act.
        $result = $this->organisationService->getUserOrganisationStats();

        // Assert.
        $this->assertEquals(0, $result['total']);
        $this->assertNull($result['active']);
        $this->assertSame([], $result['results']);
    }

    /**
     * Test clearDefaultOrganisationCache clears the static cache
     *
     * @return void
     */
    public function testClearDefaultOrganisationCacheResetsStaticCache(): void
    {
        // Act: Clear the cache.
        $this->organisationService->clearDefaultOrganisationCache();

        // Assert: Static cache is null after clear (verified via reflection).
        $reflection = new \ReflectionClass(OrganisationService::class);

        $defaultOrgCache = $reflection->getProperty('defaultOrgCache');
        $defaultOrgCache->setAccessible(true);
        $this->assertNull($defaultOrgCache->getValue());

        $defaultOrgCacheTs = $reflection->getProperty('defaultOrgCacheTs');
        $defaultOrgCacheTs->setAccessible(true);
        $this->assertNull($defaultOrgCacheTs->getValue());
    }

    /**
     * Test organisation __toString method
     *
     * Scenario: Test string conversion of organisation objects
     * Expected: UUID is returned (Organisation __toString returns UUID)
     *
     * @return void
     */
    public function testOrganisationToString(): void
    {
        // Organisation's __toString() returns the UUID, generating one if needed.

        // Test 1: Organisation with a set UUID.
        $org1 = new Organisation();
        $org1->setUuid('test-uuid-123');
        $this->assertEquals('test-uuid-123', (string) $org1);

        // Test 2: Organisation without UUID gets auto-generated UUID.
        $org2 = new Organisation();
        $result = (string) $org2;
        $this->assertNotEmpty($result);
        // Should be a valid UUID format (auto-generated).
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $result
        );
    }
}
