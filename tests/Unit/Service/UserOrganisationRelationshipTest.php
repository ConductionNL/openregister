<?php
/**
 * User-Organisation Relationship Unit Tests
 *
 * This test class covers all scenarios related to user-organisation relationships
 * including joining organisations, leaving organisations, and membership management.
 *
 * Test Coverage:
 * - Test 3.1: Join Organisation
 * - Test 3.2: Multiple Organisation Membership
 * - Test 3.3: Leave Organisation (Non-Last)
 * - Test 3.4: Join Non-Existent Organisation (negative)
 * - Test 3.5: Leave Last Organisation (negative)
 * - Test 3.6: Join Already Member Organisation (negative)
 *
 * Key Features Tested:
 * - User joining organisations successfully
 * - Multi-organisation membership management
 * - Leaving organisations while maintaining at least one membership
 * - Validation of organisation existence before joining
 * - Prevention of leaving last organisation
 * - Graceful handling of duplicate membership attempts
 * - User membership validation and access control
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
 * Test class for User-Organisation Relationships
 */
class UserOrganisationRelationshipTest extends TestCase
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
     * Test 3.1: Join Organisation
     *
     * Scenario: User joins an existing organisation
     * Expected: User is successfully added to organisation member list
     *
     * @return void
     */
    public function testJoinOrganisation(): void
    {
        // Arrange: Mock user session.
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);

        $organisationUuid = 'acme-uuid-123';

        // Mock: addUserToOrganisation on mapper (joinOrganisation uses this directly).
        $updatedOrg = new Organisation();
        $updatedOrg->setName('ACME Corporation');
        $updatedOrg->setUuid($organisationUuid);
        $updatedOrg->setOwner('alice');
        $updatedOrg->setUsers(['alice', 'bob']);

        $this->organisationMapper
            ->expects($this->once())
            ->method('addUserToOrganisation')
            ->with(organisationUuid: $organisationUuid, userId: 'bob')
            ->willReturn($updatedOrg);

        // Act: Join organisation via service.
        $result = $this->organisationService->joinOrganisation(organisationUuid: $organisationUuid);

        // Assert: Successfully joined organisation.
        $this->assertTrue($result);
    }

    /**
     * Test 3.2: Multiple Organisation Membership
     *
     * Scenario: User belongs to multiple organisations simultaneously
     * Expected: User can be member of multiple organisations
     *
     * @return void
     */
    public function testMultipleOrganisationMembership(): void
    {
        // Arrange: Mock user session.
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);

        // Mock: Bob already belongs to ACME organisation.
        $acmeOrg = new Organisation();
        $acmeOrg->setName('ACME Corporation');
        $acmeOrg->setUuid('acme-uuid-123');
        $acmeOrg->setUsers(['alice', 'bob']);

        // Mock: Tech Startup organisation (after Bob joins).
        $updatedTechOrg = new Organisation();
        $updatedTechOrg->setName('Tech Startup');
        $updatedTechOrg->setUuid('tech-startup-uuid-456');
        $updatedTechOrg->setOwner('alice');
        $updatedTechOrg->setUsers(['alice', 'bob']);

        // Mock: addUserToOrganisation for joining Tech Startup.
        $this->organisationMapper
            ->expects($this->once())
            ->method('addUserToOrganisation')
            ->with(organisationUuid: 'tech-startup-uuid-456', userId: 'bob')
            ->willReturn($updatedTechOrg);

        // Mock: findByUserId returns multiple organisations after join.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUserId')
            ->with('bob')
            ->willReturn([$acmeOrg, $updatedTechOrg]);

        // Act: Join second organisation.
        $joinResult = $this->organisationService->joinOrganisation(organisationUuid: 'tech-startup-uuid-456');

        // Get user's organisations.
        $organisations = $this->organisationService->getUserOrganisations(_useCache: false);

        // Assert: User belongs to multiple organisations.
        $this->assertTrue($joinResult);
        $this->assertCount(2, $organisations);

        $orgNames = array_map(function($org) { return $org->getName(); }, $organisations);
        $this->assertContains('ACME Corporation', $orgNames);
        $this->assertContains('Tech Startup', $orgNames);
    }

    /**
     * Test 3.3: Leave Organisation (Non-Last)
     *
     * Scenario: User leaves one organisation while maintaining membership in others
     * Expected: User is removed from specified organisation but remains in others
     *
     * @return void
     */
    public function testLeaveOrganisationNonLast(): void
    {
        // Arrange: Mock user session.
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);

        $acmeUuid = 'acme-uuid-123';

        // Mock: Bob belongs to two organisations.
        $acmeOrg = new Organisation();
        $acmeOrg->setName('ACME Corporation');
        $acmeOrg->setUuid($acmeUuid);
        $acmeOrg->setUsers(['alice', 'bob']);

        $techOrg = new Organisation();
        $techOrg->setName('Tech Startup');
        $techOrg->setUuid('tech-startup-uuid-456');
        $techOrg->setUsers(['alice', 'bob']);

        // Mock: User organisations lookup returns both (so can leave one).
        $this->organisationMapper
            ->method('findByUserId')
            ->with('bob')
            ->willReturn([$acmeOrg, $techOrg]);

        // Mock: removeUserFromOrganisation succeeds.
        $updatedAcme = clone $acmeOrg;
        $updatedAcme->removeUser('bob');

        $this->organisationMapper
            ->expects($this->once())
            ->method('removeUserFromOrganisation')
            ->with(organisationUuid: $acmeUuid, userId: 'bob')
            ->willReturn($updatedAcme);

        // Mock: Session/config operations for active org check after leave.
        $this->session->method('get')->willReturn(null);
        $this->config->method('getUserValue')->willReturn('');

        // Act: Leave one organisation.
        $result = $this->organisationService->leaveOrganisation(organisationUuid: $acmeUuid);

        // Assert: Successfully left organisation.
        $this->assertTrue($result);
    }

    /**
     * Test 3.4: Join Non-Existent Organisation (Negative Test)
     *
     * Scenario: User attempts to join organisation that doesn't exist
     * Expected: Error response indicating organisation not found
     *
     * @return void
     */
    public function testJoinNonExistentOrganisation(): void
    {
        // Arrange: Mock user session.
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);

        $invalidUuid = 'invalid-uuid-123';

        // Mock: addUserToOrganisation throws DoesNotExistException (org not found).
        $this->organisationMapper
            ->expects($this->once())
            ->method('addUserToOrganisation')
            ->with(organisationUuid: $invalidUuid, userId: 'bob')
            ->willThrowException(new DoesNotExistException('Organisation not found'));

        // Mock: Request params for controller.
        $this->request->method('getParams')->willReturn([]);

        // Act: Attempt to join non-existent organisation via controller.
        $response = $this->organisationController->join(uuid: $invalidUuid);

        // Assert: Error response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('not found', strtolower($responseData['error']));
    }

    /**
     * Test 3.5: Leave Last Organisation (Negative Test)
     *
     * Scenario: User attempts to leave their only remaining organisation
     * Expected: Exception thrown preventing user from leaving last organisation
     *
     * @return void
     */
    public function testLeaveLastOrganisation(): void
    {
        // Arrange: Mock user session.
        $charlieUser = $this->createMock(IUser::class);
        $charlieUser->method('getUID')->willReturn('charlie');
        $this->userSession->method('getUser')->willReturn($charlieUser);

        $defaultUuid = 'default-org-uuid';

        // Mock: Charlie only belongs to default organisation.
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setUuid($defaultUuid);
        $defaultOrg->setUsers(['charlie']);

        $this->organisationMapper
            ->method('findByUserId')
            ->with('charlie')
            ->willReturn([$defaultOrg]); // Only one organisation

        // Act & Assert: Attempt to leave last organisation throws exception.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot leave last organisation');

        $this->organisationService->leaveOrganisation(organisationUuid: $defaultUuid);
    }

    /**
     * Test 3.6: Join Already Member Organisation (Negative Test)
     *
     * Scenario: User attempts to join organisation they already belong to
     * Expected: Graceful handling without duplicate membership
     *
     * @return void
     */
    public function testJoinAlreadyMemberOrganisation(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $acmeUuid = 'acme-uuid-123';

        // Mock: addUserToOrganisation handles idempotent adds (addUser doesn't duplicate).
        $acmeOrg = new Organisation();
        $acmeOrg->setName('ACME Corporation');
        $acmeOrg->setUuid($acmeUuid);
        $acmeOrg->setOwner('alice');
        $acmeOrg->setUsers(['alice']); // Alice already a member, no duplicate

        $this->organisationMapper
            ->expects($this->once())
            ->method('addUserToOrganisation')
            ->with(organisationUuid: $acmeUuid, userId: 'alice')
            ->willReturn($acmeOrg);

        // Act: Attempt to join organisation user already belongs to.
        $result = $this->organisationService->joinOrganisation(organisationUuid: $acmeUuid);

        // Assert: Gracefully handled (returns true, no duplicate membership).
        $this->assertTrue($result);
    }

    /**
     * Test user membership validation before operations
     *
     * Scenario: Verify user membership is properly validated before operations
     * Expected: Operations only succeed when user has appropriate access
     *
     * @return void
     */
    public function testUserMembershipValidation(): void
    {
        // Arrange: Mock user session.
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);

        // Mock: groupManager for hasAccessToOrganisation (bob is not admin).
        $this->groupManager->method('isAdmin')->willReturn(false);

        $privateOrgUuid = 'private-org-uuid';

        // Mock: Private organisation where Bob is not a member.
        $privateOrg = new Organisation();
        $privateOrg->setName('Private Organisation');
        $privateOrg->setUuid($privateOrgUuid);
        $privateOrg->setOwner('alice');
        $privateOrg->setUsers(['alice', 'charlie']); // Bob not a member

        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($privateOrgUuid)
            ->willReturn($privateOrg);

        // Act: Check if Bob has access to private organisation.
        $hasAccess = $this->organisationService->hasAccessToOrganisation(organisationUuid: $privateOrgUuid);

        // Assert: Bob should not have access.
        $this->assertFalse($hasAccess);
    }

    /**
     * Test organisation statistics after membership changes
     *
     * Scenario: User statistics should update after joining/leaving organisations
     * Expected: Statistics reflect current membership status
     *
     * @return void
     */
    public function testOrganisationStatisticsAfterMembershipChanges(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('diana');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: Diana belongs to multiple organisations.
        $org1 = new Organisation();
        $org1->setName('Organisation 1');
        $org1->setUuid('org1-uuid');
        $org1->setUsers(['diana']);

        $org2 = new Organisation();
        $org2->setName('Organisation 2');
        $org2->setUuid('org2-uuid');
        $org2->setUsers(['diana']);

        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setUuid('default-uuid');
        $defaultOrg->setUsers(['diana']);

        $this->organisationMapper
            ->method('findByUserId')
            ->with('diana')
            ->willReturn([$org1, $org2, $defaultOrg]);

        // Mock: Session/config for getActiveOrganisation within getUserOrganisationStats.
        $this->session->method('get')->willReturn(null);
        $this->config->method('getUserValue')->willReturn('');

        // Act: Get user organisation statistics.
        // getUserOrganisationStats returns {total, active, results}.
        $stats = $this->organisationService->getUserOrganisationStats();

        // Assert: Statistics reflect membership.
        $this->assertEquals(3, $stats['total']);
        $this->assertArrayHasKey('active', $stats);
        $this->assertArrayHasKey('results', $stats);
        $this->assertCount(3, $stats['results']);
    }

    /**
     * Test concurrent membership operations
     *
     * Scenario: Multiple operations on same organisation should be handled properly
     * Expected: Operations should be atomic and consistent
     *
     * @return void
     */
    public function testConcurrentMembershipOperations(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('eve');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: groupManager for hasAccessToOrganisation.
        $this->groupManager->method('isAdmin')->willReturn(false);

        $orgUuid = 'concurrent-test-uuid';

        // Mock: Organisation with current membership.
        $organisation = new Organisation();
        $organisation->setName('Concurrent Test Org');
        $organisation->setUuid($orgUuid);
        $organisation->setUsers(['alice', 'bob', 'eve']); // Eve already a member

        // Mock: addUserToOrganisation for join.
        $this->organisationMapper
            ->expects($this->once())
            ->method('addUserToOrganisation')
            ->with(organisationUuid: $orgUuid, userId: 'eve')
            ->willReturn($organisation);

        // Mock: findByUuid for hasAccessToOrganisation.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($orgUuid)
            ->willReturn($organisation);

        // Act: Simulate join + access check operations.
        $result1 = $this->organisationService->joinOrganisation(organisationUuid: $orgUuid);
        $hasAccess = $this->organisationService->hasAccessToOrganisation(organisationUuid: $orgUuid);

        // Assert: Operations completed successfully.
        $this->assertTrue($result1);
        $this->assertTrue($hasAccess);
    }

    /**
     * Test organisation membership with role validation
     *
     * Scenario: Different user roles should have different permissions
     * Expected: Owner and member roles are properly distinguished
     *
     * @return void
     */
    public function testOrganisationMembershipWithRoleValidation(): void
    {
        // Arrange: Create organisation with owner and members.
        $organisation = new Organisation();
        $organisation->setName('Role Test Organisation');
        $organisation->setUuid('role-test-uuid');
        $organisation->setOwner('alice'); // Alice is owner
        $organisation->setUsers(['alice', 'bob', 'charlie']); // All are members

        // Test owner role.
        $this->assertTrue($organisation->hasUser('alice'));
        $this->assertEquals('alice', $organisation->getOwner());

        // Test member role (not owner).
        $this->assertTrue($organisation->hasUser('bob'));
        $this->assertNotEquals('bob', $organisation->getOwner());

        // Test non-member.
        $this->assertFalse($organisation->hasUser('diana'));

        // Assert: Role distinctions are maintained.
        $this->assertCount(3, $organisation->getUserIds());
        $this->assertNotNull($organisation->getOwner());
    }
}
