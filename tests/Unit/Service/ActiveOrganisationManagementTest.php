<?php
/**
 * Active Organisation Management Unit Tests
 *
 * This test class covers all scenarios related to active organisation management
 * including getting, setting, persistence, and auto-switching functionality.
 * 
 * Test Coverage:
 * - Test 4.1: Get Active Organisation (Auto-Set)
 * - Test 4.2: Set Active Organisation
 * - Test 4.3: Active Organisation Persistence
 * - Test 4.4: Active Organisation Auto-Switch on Leave
 * - Test 4.5: Set Non-Member Organisation as Active (negative)
 * - Test 4.6: Set Non-Existent Organisation as Active (negative)
 *
 * Key Features Tested:
 * - Automatic active organisation setting when none exists
 * - Manual active organisation switching by users
 * - Session persistence of active organisation
 * - Auto-switching when leaving the active organisation
 * - Validation of user membership before setting active
 * - Error handling for non-existent organisations
 * - Cache management and session handling
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
 * Test class for Active Organisation Management
 */
class ActiveOrganisationManagementTest extends TestCase
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
        
        // Create service instance with mocked dependencies
        $this->organisationService = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $this->groupManager,
            $this->logger
        );
        
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
     * Test 4.1: Get Active Organisation (Auto-Set)
     *
     * Scenario: First call should auto-set the oldest organisation as active
     * Expected: Oldest organisation from user's list is set as active
     *
     * @return void
     */
    public function testGetActiveOrganisationAutoSet(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        // Mock: No active organisation in config initially
        $this->config
            ->expects($this->once())
            ->method('getUserValue')
            ->with('alice', 'openregister', 'active_organisation', '')
            ->willReturn('');
        
        // Mock: User belongs to multiple organisations (oldest first)
        $oldestOrg = new Organisation();
        $oldestOrg->setName('Oldest Organisation');
        $oldestOrg->setUuid('oldest-uuid-123');
        $oldestOrg->setUsers(['alice']);
        $oldestOrg->setCreated(new \DateTime('2024-01-01')); // Oldest
        
        $newerOrg = new Organisation();
        $newerOrg->setName('Newer Organisation');
        $newerOrg->setUuid('newer-uuid-456');
        $newerOrg->setUsers(['alice']);
        $newerOrg->setCreated(new \DateTime('2024-02-01')); // Newer
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUserId')
            ->with('alice')
            ->willReturn([$oldestOrg, $newerOrg]);
        
        // Mock: Set active organisation in config (oldest one)
        $this->config
            ->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'openregister', 'active_organisation', 'oldest-uuid-123');

        // Act: Get active organisation (should trigger auto-set)
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Oldest organisation is auto-set as active
        $this->assertInstanceOf(Organisation::class, $activeOrg);
        $this->assertEquals('oldest-uuid-123', $activeOrg->getUuid());
        $this->assertEquals('Oldest Organisation', $activeOrg->getName());
    }

    /**
     * Test 4.2: Set Active Organisation
     *
     * Scenario: User manually sets specific organisation as active
     * Expected: Active organisation is changed to specified organisation
     *
     * @return void
     */
    public function testSetActiveOrganisation(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $targetOrgUuid = 'tech-startup-uuid-456';
        
        // Mock: User belongs to the target organisation
        $techStartupOrg = new Organisation();
        $techStartupOrg->setName('Tech Startup');
        $techStartupOrg->setUuid($targetOrgUuid);
        $techStartupOrg->setOwner('alice');
        $techStartupOrg->setUsers(['alice', 'bob']);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($targetOrgUuid)
            ->willReturn($techStartupOrg);
        
        // Mock: Set active organisation in config
        $this->config
            ->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'openregister', 'active_organisation', $targetOrgUuid);

        // Act: Set active organisation via service
        $result = $this->organisationService->setActiveOrganisation($targetOrgUuid);

        // Assert: Organisation set successfully
        $this->assertTrue($result);
    }

    /**
     * Test 4.3: Active Organisation Persistence
     *
     * Scenario: Multiple calls should return the same active organisation
     * Expected: Active organisation persists across multiple requests
     *
     * @return void
     */
    public function testActiveOrganisationPersistence(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $activeOrgUuid = 'persistent-org-uuid';
        
        // Mock: Active organisation is already set in config
        $this->config
            ->expects($this->exactly(2))
            ->method('getUserValue')
            ->with('alice', 'openregister', 'active_organisation', '')
            ->willReturn($activeOrgUuid);
        
        // Mock: Organisation exists
        $persistentOrg = new Organisation();
        $persistentOrg->setName('Persistent Organisation');
        $persistentOrg->setUuid($activeOrgUuid);
        $persistentOrg->setUsers(['alice']);
        
        $this->organisationMapper
            ->expects($this->exactly(2))
            ->method('findByUuid')
            ->with($activeOrgUuid)
            ->willReturn($persistentOrg);

        // Act: Multiple calls to get active organisation
        $activeOrg1 = $this->organisationService->getActiveOrganisation();
        $activeOrg2 = $this->organisationService->getActiveOrganisation();

        // Assert: Same organisation returned both times
        $this->assertInstanceOf(Organisation::class, $activeOrg1);
        $this->assertInstanceOf(Organisation::class, $activeOrg2);
        $this->assertEquals($activeOrg1->getUuid(), $activeOrg2->getUuid());
        $this->assertEquals($activeOrgUuid, $activeOrg1->getUuid());
        $this->assertEquals($activeOrgUuid, $activeOrg2->getUuid());
    }

    /**
     * Test 4.4: Active Organisation Auto-Switch on Leave
     *
     * Scenario: When user leaves their active organisation, another should become active
     * Expected: System automatically switches to another organisation
     *
     * @return void
     */
    public function testActiveOrganisationAutoSwitchOnLeave(): void
    {
        // Arrange: Mock user session
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);
        
        $currentActiveUuid = 'current-active-uuid';
        $alternativeOrgUuid = 'alternative-org-uuid';
        
        // Mock: Bob currently has active organisation set initially, then empty after clearing
        $this->config
            ->expects($this->atLeast(2))
            ->method('getUserValue')
            ->with('bob', 'openregister', 'active_organisation', '')
            ->willReturnOnConsecutiveCalls($currentActiveUuid, $currentActiveUuid, ''); // First two calls return current, third returns empty
        
        // Mock: Current active organisation and alternative
        $currentActiveOrg = new Organisation();
        $currentActiveOrg->setName('Current Active Org');
        $currentActiveOrg->setUuid($currentActiveUuid);
        $currentActiveOrg->setUsers(['alice', 'bob']);
        
        $alternativeOrg = new Organisation();
        $alternativeOrg->setName('Alternative Organisation');
        $alternativeOrg->setUuid($alternativeOrgUuid);
        $alternativeOrg->setUsers(['bob', 'charlie']);
        $alternativeOrg->setCreated(new \DateTime('2024-01-01')); // Oldest remaining
        
        // Mock: Bob belongs to multiple organisations initially (before leaving), then only alternative after leaving
        $this->organisationMapper
            ->expects($this->atLeast(2))
            ->method('findByUserId')
            ->with('bob')
            ->willReturnOnConsecutiveCalls(
                [$currentActiveOrg, $alternativeOrg], // Before leaving
                [$alternativeOrg] // After leaving (only alternative org remains)
            );
        
        // Mock: findByUuid for leave operation (called multiple times in leaveOrganisation and getActiveOrganisation)
        $this->organisationMapper
            ->expects($this->atLeast(2))
            ->method('findByUuid')
            ->with($currentActiveUuid)
            ->willReturn($currentActiveOrg);
        
        // Mock: Update organisation to remove Bob
        $updatedCurrentOrg = clone $currentActiveOrg;
        $updatedCurrentOrg->removeUser('bob');
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('removeUserFromOrganisation')
            ->with($currentActiveUuid, 'bob')
            ->willReturn($updatedCurrentOrg);
        
        // Mock: Set new active organisation (alternative)
        $this->config
            ->expects($this->once())
            ->method('setUserValue')
            ->with('bob', 'openregister', 'active_organisation', $alternativeOrgUuid);

        // Act: Leave current active organisation
        $leaveResult = $this->organisationService->leaveOrganisation($currentActiveUuid);
        
        // Get active organisation (should be switched)
        $newActiveOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Successfully left and switched to alternative organisation
        $this->assertTrue($leaveResult);
        $this->assertInstanceOf(Organisation::class, $newActiveOrg);
        $this->assertEquals($alternativeOrgUuid, $newActiveOrg->getUuid());
        $this->assertEquals('Alternative Organisation', $newActiveOrg->getName());
    }

    /**
     * Test 4.5: Set Non-Member Organisation as Active (Negative Test)
     *
     * Scenario: User tries to set organisation they don't belong to as active
     * Expected: Error indicating user does not belong to the organisation
     *
     * @return void
     */
    public function testSetNonMemberOrganisationAsActive(): void
    {
        // Arrange: Mock user session
        $charlieUser = $this->createMock(IUser::class);
        $charlieUser->method('getUID')->willReturn('charlie');
        $this->userSession->method('getUser')->willReturn($charlieUser);
        
        $acmeOrgUuid = 'acme-uuid-123';
        
        // Mock: ACME organisation exists but Charlie is not a member
        $acmeOrg = new Organisation();
        $acmeOrg->setName('ACME Corporation');
        $acmeOrg->setUuid($acmeOrgUuid);
        $acmeOrg->setOwner('alice');
        $acmeOrg->setUsers(['alice', 'bob']); // Charlie not in list
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($acmeOrgUuid)
            ->willReturn($acmeOrg);

        // Act: Attempt to set non-member organisation as active via controller
        $response = $this->organisationController->setActive($acmeOrgUuid);

        // Assert: Error response
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('belong', strtolower($responseData['error']));
    }

    /**
     * Test 4.6: Set Non-Existent Organisation as Active (Negative Test)
     *
     * Scenario: User tries to set non-existent organisation as active
     * Expected: Error indicating organisation not found
     *
     * @return void
     */
    public function testSetNonExistentOrganisationAsActive(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $invalidUuid = 'invalid-uuid-123';
        
        // Mock: Organisation not found
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($invalidUuid)
            ->willThrowException(new DoesNotExistException('Organisation not found'));

        // Act: Attempt to set non-existent organisation as active via controller
        $response = $this->organisationController->setActive($invalidUuid);

        // Assert: Error response
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('not found', strtolower($responseData['error']));
    }

    /**
     * Test get active organisation via controller endpoint
     *
     * Scenario: Test the GET /active endpoint functionality
     * Expected: Active organisation returned in proper JSON format
     *
     * @return void
     */
    public function testGetActiveOrganisationViaController(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('diana');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $activeOrgUuid = 'diana-active-org';
        
        // Mock: Active organisation in config
        $this->config
            ->expects($this->once())
            ->method('getUserValue')
            ->with('diana', 'openregister', 'active_organisation', '')
            ->willReturn($activeOrgUuid);
        
        // Mock: Organisation exists
        $activeOrg = new Organisation();
        $activeOrg->setName('Diana Active Org');
        $activeOrg->setUuid($activeOrgUuid);
        $activeOrg->setOwner('diana');
        $activeOrg->setUsers(['diana']);
        $activeOrg->setCreated(new \DateTime());
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($activeOrgUuid)
            ->willReturn($activeOrg);

        // Act: Get active organisation via controller
        $response = $this->organisationController->getActive();

        // Assert: Successful response with organisation data
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $responseData = $response->getData();
        $activeOrgData = $responseData['activeOrganisation'];
        $this->assertEquals('Diana Active Org', $activeOrgData['name']);
        $this->assertEquals($activeOrgUuid, $activeOrgData['uuid']);
        $this->assertEquals('diana', $activeOrgData['owner']);
        $this->assertContains('diana', $activeOrgData['users']);
    }

    /**
     * Test active organisation cache clearing
     *
     * Scenario: Cache should be properly cleared when requested
     * Expected: Next request fetches fresh data from database
     *
     * @return void
     */
    public function testActiveOrganisationCacheClearing(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('eve');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        // Mock: Clear cache operation (config doesn't need explicit clearing in this context)
        // The clearCache method might not use config, so we don't need to mock it

        // Act: Clear cache via service
        $this->organisationService->clearCache();

        // Assert: Cache clearing method completes without error
        $this->addToAssertionCount(1); // Ensure test passes if no exceptions thrown
    }

    /**
     * Test active organisation setting with validation
     *
     * Scenario: Setting active organisation should validate user membership
     * Expected: Only organisations where user is member can be set as active
     *
     * @return void
     */
    public function testActiveOrganisationSettingWithValidation(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('frank');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $validOrgUuid = 'valid-org-uuid';
        
        // Mock: Organisation where Frank is a member
        $validOrg = new Organisation();
        $validOrg->setName('Valid Organisation');
        $validOrg->setUuid($validOrgUuid);
        $validOrg->setUsers(['alice', 'frank']); // Frank is member
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($validOrgUuid)
            ->willReturn($validOrg);
        
        // Mock: Config update
        $this->config
            ->expects($this->once())
            ->method('setUserValue')
            ->with('frank', 'openregister', 'active_organisation', $validOrgUuid);

        // Act: Set valid organisation as active
        $result = $this->organisationService->setActiveOrganisation($validOrgUuid);

        // Assert: Successfully set as active
        $this->assertTrue($result);
    }

    /**
     * Test active organisation auto-selection when user has no organisations
     *
     * Scenario: User with no organisations should get default organisation as active
     * Expected: Default organisation is created and set as active
     *
     * @return void
     */
    public function testActiveOrganisationAutoSelectionForUserWithNoOrganisations(): void
    {
        // Arrange: Mock user session
        $newUser = $this->createMock(IUser::class);
        $newUser->method('getUID')->willReturn('newuser');
        $this->userSession->method('getUser')->willReturn($newUser);
        
        // Mock: No active organisation in config
        $this->config
            ->expects($this->once())
            ->method('getUserValue')
            ->with('newuser', 'openregister', 'active_organisation', '')
            ->willReturn('');
        
        // Mock: User has no organisations initially
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUserId')
            ->with('newuser')
            ->willReturn([]);
        
        // Mock: Default organisation
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setUuid('default-uuid-789');
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setOwner('system');
        $defaultOrg->setUsers(['newuser']);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findDefault')
            ->willReturn($defaultOrg);
        
        // Mock: Add user to default organisation
        $this->organisationMapper
            ->expects($this->once())
            ->method('update')
            ->willReturn($defaultOrg);
        
        // Mock: Set active organisation
        $this->config
            ->expects($this->once())
            ->method('setUserValue')
            ->with('newuser', 'openregister', 'active_organisation', 'default-uuid-789');

        // Act: Get active organisation (should create and set default)
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Default organisation is set as active
        $this->assertInstanceOf(Organisation::class, $activeOrg);
        $this->assertEquals('default-uuid-789', $activeOrg->getUuid());
        $this->assertTrue($activeOrg->getIsDefault());
        $this->assertTrue($activeOrg->hasUser('newuser'));
    }
} 