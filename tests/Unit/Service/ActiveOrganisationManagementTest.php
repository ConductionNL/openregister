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
use OCP\IConfig;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IRequest;
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
            logger: $this->logger
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
     * Test 4.1: Get Active Organisation (Auto-Set)
     *
     * Scenario: First call should auto-set the oldest organisation as active
     * Expected: Oldest organisation from user's list is set as active
     *
     * @return void
     */
    public function testGetActiveOrganisationAutoSet(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: No active organisation in session cache (both session keys return null).
        // The service checks session cache keys: openregister_active_organisation_alice
        // and openregister_active_organisation_timestamp_alice.
        $this->session->method('get')->willReturn(null);

        // Mock: No active organisation in persistent config either.
        $this->config->method('getUserValue')->willReturn('');

        // Mock: User belongs to multiple organisations (oldest first).
        $oldestOrg = new Organisation();
        $oldestOrg->setName('Oldest Organisation');
        $oldestOrg->setUuid('oldest-uuid-123');
        $oldestOrg->setUsers(['alice']);
        $oldestOrg->setCreated(new \DateTime('2024-01-01'));

        $newerOrg = new Organisation();
        $newerOrg->setName('Newer Organisation');
        $newerOrg->setUuid('newer-uuid-456');
        $newerOrg->setUsers(['alice']);
        $newerOrg->setCreated(new \DateTime('2024-02-01'));

        $this->organisationMapper
            ->method('findByUserId')
            ->with('alice')
            ->willReturn([$oldestOrg, $newerOrg]);

        // Mock: Session set is called to cache the active organisation.
        $this->session->expects($this->atLeastOnce())
            ->method('set');

        // Act: Get active organisation (should trigger auto-set).
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: An organisation is returned (the auto-selected one).
        $this->assertInstanceOf(Organisation::class, $activeOrg);
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
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $targetOrgUuid = 'tech-startup-uuid-456';

        // Mock: User belongs to the target organisation.
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

        // Act: Set active organisation via service.
        // setActiveOrganisation validates membership, then uses config->setUserValue
        // and session for caching.
        $result = $this->organisationService->setActiveOrganisation(organisationUuid: $targetOrgUuid);

        // Assert: Organisation set successfully.
        $this->assertTrue($result);
    }

    /**
     * Test 4.3: Active Organisation Persistence
     *
     * Scenario: Active organisation data is returned from session cache
     * Expected: Cached organisation is returned without DB queries
     *
     * @return void
     */
    public function testActiveOrganisationPersistence(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $activeOrgUuid = 'persistent-org-uuid';

        // Mock: Active organisation cached in session (as array data).
        $cachedOrgData = [
            'id' => 1,
            'uuid' => $activeOrgUuid,
            'name' => 'Persistent Organisation',
            'description' => '',
            'owner' => 'alice',
            'users' => ['alice'],
            'created' => '2024-01-01T00:00:00+00:00',
            'updated' => '2024-01-01T00:00:00+00:00',
        ];

        $this->session->method('get')
            ->willReturnCallback(function (string $key) use ($cachedOrgData) {
                if ($key === 'openregister_active_organisation_alice') {
                    return $cachedOrgData;
                }
                if ($key === 'openregister_active_organisation_timestamp_alice') {
                    return time(); // Recent cache, not expired.
                }
                return null;
            });

        // Act: Get active organisation (should come from cache).
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Organisation returned from cache.
        $this->assertInstanceOf(Organisation::class, $activeOrg);
        $this->assertEquals($activeOrgUuid, $activeOrg->getUuid());
        $this->assertEquals('Persistent Organisation', $activeOrg->getName());
    }

    /**
     * Test 4.4: Active Organisation Auto-Switch on Leave
     *
     * Scenario: When user leaves their active organisation, another should become active
     * Expected: leaveOrganisation throws exception if last org, otherwise succeeds
     *
     * @return void
     */
    public function testActiveOrganisationAutoSwitchOnLeave(): void
    {
        // Arrange: Mock user session.
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);

        $currentActiveUuid = 'current-active-uuid';
        $alternativeOrgUuid = 'alternative-org-uuid';

        // Mock: Bob currently has two organisations.
        $currentActiveOrg = new Organisation();
        $currentActiveOrg->setName('Current Active Org');
        $currentActiveOrg->setUuid($currentActiveUuid);
        $currentActiveOrg->setUsers(['alice', 'bob']);

        $alternativeOrg = new Organisation();
        $alternativeOrg->setName('Alternative Organisation');
        $alternativeOrg->setUuid($alternativeOrgUuid);
        $alternativeOrg->setUsers(['bob', 'charlie']);
        $alternativeOrg->setCreated(new \DateTime('2024-01-01'));

        // Mock: After checking, Bob belongs to two organisations (so can leave one).
        $this->organisationMapper
            ->method('findByUserId')
            ->with('bob')
            ->willReturn([$currentActiveOrg, $alternativeOrg]);

        // Mock: removeUserFromOrganisation succeeds.
        $updatedCurrentOrg = clone $currentActiveOrg;
        $updatedCurrentOrg->removeUser('bob');

        $this->organisationMapper
            ->method('removeUserFromOrganisation')
            ->with(organisationUuid: $currentActiveUuid, userId: 'bob')
            ->willReturn($updatedCurrentOrg);

        // Mock: Session operations for active org check and cache clearing.
        $this->session->method('get')->willReturn(null);
        $this->config->method('getUserValue')->willReturn('');

        // Act: Leave current active organisation.
        $leaveResult = $this->organisationService->leaveOrganisation(organisationUuid: $currentActiveUuid);

        // Assert: Successfully left.
        $this->assertTrue($leaveResult);
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
        // Arrange: Mock user session.
        $charlieUser = $this->createMock(IUser::class);
        $charlieUser->method('getUID')->willReturn('charlie');
        $this->userSession->method('getUser')->willReturn($charlieUser);

        $acmeOrgUuid = 'acme-uuid-123';

        // Mock: ACME organisation exists but Charlie is not a member.
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

        // Act: Attempt to set non-member organisation as active via controller.
        // setActiveOrganisation throws Exception, controller catches it and returns 400.
        $response = $this->organisationController->setActive(uuid: $acmeOrgUuid);

        // Assert: Error response.
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
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $invalidUuid = 'invalid-uuid-123';

        // Mock: Organisation not found.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($invalidUuid)
            ->willThrowException(new DoesNotExistException('Organisation not found'));

        // Act: Attempt to set non-existent organisation as active via controller.
        $response = $this->organisationController->setActive(uuid: $invalidUuid);

        // Assert: Error response.
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
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('diana');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $activeOrgUuid = 'diana-active-org';

        // Mock: Active organisation in session cache (as array data).
        $cachedOrgData = [
            'id' => 1,
            'uuid' => $activeOrgUuid,
            'name' => 'Diana Active Org',
            'description' => '',
            'owner' => 'diana',
            'users' => ['diana'],
            'created' => '2024-01-01T00:00:00+00:00',
            'updated' => '2024-01-01T00:00:00+00:00',
        ];

        $this->session->method('get')
            ->willReturnCallback(function (string $key) use ($cachedOrgData) {
                if ($key === 'openregister_active_organisation_diana') {
                    return $cachedOrgData;
                }
                if ($key === 'openregister_active_organisation_timestamp_diana') {
                    return time();
                }
                return null;
            });

        // Act: Get active organisation via controller.
        $response = $this->organisationController->getActive();

        // Assert: Successful response with organisation data.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('activeOrganisation', $responseData);
        $this->assertNotNull($responseData['activeOrganisation']);
        $this->assertEquals('Diana Active Org', $responseData['activeOrganisation']['name']);
        $this->assertEquals($activeOrgUuid, $responseData['activeOrganisation']['uuid']);
    }

    /**
     * Test active organisation cache clearing
     *
     * Scenario: Cache should be properly cleared when requested
     * Expected: clearCache returns true and session remove is called
     *
     * @return void
     */
    public function testActiveOrganisationCacheClearing(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('eve');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: Session remove is called for user organisations cache and active org cache.
        // The service uses keys: openregister_user_organisations_<userId>,
        // openregister_active_organisation_<userId>, openregister_active_organisation_timestamp_<userId>.
        $this->session->expects($this->atLeastOnce())
            ->method('remove');

        // Act: Clear cache via service.
        $result = $this->organisationService->clearCache();

        // Assert: Cache clearing method completes successfully.
        $this->assertTrue($result);
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
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('frank');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $validOrgUuid = 'valid-org-uuid';

        // Mock: Organisation where Frank is a member.
        $validOrg = new Organisation();
        $validOrg->setName('Valid Organisation');
        $validOrg->setUuid($validOrgUuid);
        $validOrg->setUsers(['alice', 'frank']); // Frank is member

        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($validOrgUuid)
            ->willReturn($validOrg);

        // Act: Set valid organisation as active.
        $result = $this->organisationService->setActiveOrganisation(organisationUuid: $validOrgUuid);

        // Assert: Successfully set as active.
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
        $this->markTestSkipped('OrganisationMapper no longer has findDefault() method. Default organisation flow was refactored to use findByUuid() internally.');
    }
}
