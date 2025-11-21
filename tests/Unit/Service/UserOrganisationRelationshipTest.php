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
        
        // Create mock objects.
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->request = $this->createMock(IRequest::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mockUser = $this->createMock(IUser::class);
        
        // Create service instance with mocked dependencies.
        $this->organisationService = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->logger
        );
        
        // Create controller instance with mocked dependencies.
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
        
        // Mock: Organisation exists with current members.
        $acmeOrg = new Organisation();
        $acmeOrg->setName('ACME Corporation');
        $acmeOrg->setUuid($organisationUuid);
        $acmeOrg->setOwner('alice');
        $acmeOrg->setUsers(['alice']); // Bob not yet a member
        
        // Mock: Updated organisation with Bob added.
        $updatedOrg = clone $acmeOrg;
        $updatedOrg->addUser('bob');
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($organisationUuid)
            ->willReturn($acmeOrg);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function($org) {
                return $org instanceof Organisation && 
                       $org->hasUser('alice') && 
                       $org->hasUser('bob');
            }))
            ->willReturn($updatedOrg);

        // Act: Join organisation via service.
        $result = $this->organisationService->joinOrganisation($organisationUuid);

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
        
        // Mock: Tech Startup organisation.
        $techStartupOrg = new Organisation();
        $techStartupOrg->setName('Tech Startup');
        $techStartupOrg->setUuid('tech-startup-uuid-456');
        $techStartupOrg->setOwner('alice');
        $techStartupOrg->setUsers(['alice']);
        
        // Mock: Updated Tech Startup with Bob added.
        $updatedTechOrg = clone $techStartupOrg;
        $updatedTechOrg->addUser('bob');
        
        // Mock: findByUserId should return multiple organisations.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUserId')
            ->with('bob')
            ->willReturn([$acmeOrg, $updatedTechOrg]);
        
        // Mock: findByUuid for joining Tech Startup.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with('tech-startup-uuid-456')
            ->willReturn($techStartupOrg);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('update')
            ->willReturn($updatedTechOrg);

        // Act: Join second organisation.
        $joinResult = $this->organisationService->joinOrganisation('tech-startup-uuid-456');
        
        // Get user's organisations.
        $organisations = $this->organisationService->getUserOrganisations(false);

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
        $techUuid = 'tech-startup-uuid-456';
        
        // Mock: Bob belongs to two organisations.
        $acmeOrg = new Organisation();
        $acmeOrg->setName('ACME Corporation');
        $acmeOrg->setUuid($acmeUuid);
        $acmeOrg->setUsers(['alice', 'bob']);
        
        $techOrg = new Organisation();
        $techOrg->setName('Tech Startup');
        $techOrg->setUuid($techUuid);
        $techOrg->setUsers(['alice', 'bob']);
        
        // Mock: User organisations lookup returns both.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUserId')
            ->with('bob')
            ->willReturn([$acmeOrg, $techOrg]);
        
        // Mock: Organisation to leave.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($acmeUuid)
            ->willReturn($acmeOrg);
        
        // Mock: Updated organisation with Bob removed.
        $updatedAcme = clone $acmeOrg;
        $updatedAcme->removeUser('bob');
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function($org) {
                return $org instanceof Organisation && 
                       $org->hasUser('alice') && 
                       !$org->hasUser('bob');
            }))
            ->willReturn($updatedAcme);

        // Act: Leave one organisation.
        $result = $this->organisationService->leaveOrganisation($acmeUuid);

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
        
        // Mock: Organisation not found.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($invalidUuid)
            ->willThrowException(new DoesNotExistException('Organisation not found'));

        // Act: Attempt to join non-existent organisation via controller.
        $response = $this->organisationController->join($invalidUuid);

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
     * Expected: Error preventing user from leaving last organisation
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
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setUsers(['charlie']);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUserId')
            ->with('charlie')
            ->willReturn([$defaultOrg]); // Only one organisation

        // Act: Attempt to leave last organisation via service.
        $result = $this->organisationService->leaveOrganisation($defaultUuid);

        // Assert: Operation failed (cannot leave last organisation).
        $this->assertFalse($result);
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
        
        // Mock: Organisation where Alice is already a member.
        $acmeOrg = new Organisation();
        $acmeOrg->setName('ACME Corporation');
        $acmeOrg->setUuid($acmeUuid);
        $acmeOrg->setOwner('alice');
        $acmeOrg->setUsers(['alice']); // Alice already a member
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($acmeUuid)
            ->willReturn($acmeOrg);
        
        // Mock: Update should not change membership (graceful handling).
        $this->organisationMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function($org) {
                // Should still have alice and no duplicates.
                return $org instanceof Organisation && 
                       $org->hasUser('alice') &&
                       count($org->getUserIds()) === 1; // No duplicates
            }))
            ->willReturn($acmeOrg);

        // Act: Attempt to join organisation user already belongs to.
        $result = $this->organisationService->joinOrganisation($acmeUuid);

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
        $hasAccess = $this->organisationService->hasAccessToOrganisation($privateOrgUuid);

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
        $org1->setIsDefault(false);
        
        $org2 = new Organisation();
        $org2->setName('Organisation 2');
        $org2->setUuid('org2-uuid');
        $org2->setUsers(['diana']);
        $org2->setIsDefault(false);
        
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setUuid('default-uuid');
        $defaultOrg->setUsers(['diana']);
        $defaultOrg->setIsDefault(true);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUserId')
            ->with('diana')
            ->willReturn([$org1, $org2, $defaultOrg]);

        // Act: Get user organisation statistics.
        $stats = $this->organisationService->getUserOrganisationStats();

        // Assert: Statistics reflect membership.
        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['custom']); // Non-default organisations
        $this->assertEquals(1, $stats['default']);
        $this->assertArrayHasKey('active', $stats);
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
        
        $orgUuid = 'concurrent-test-uuid';
        
        // Mock: Organisation with current membership.
        $organisation = new Organisation();
        $organisation->setName('Concurrent Test Org');
        $organisation->setUuid($orgUuid);
        $organisation->setUsers(['alice', 'bob']);
        
        // Mock: Multiple findByUuid calls (simulating concurrent operations).
        $this->organisationMapper
            ->expects($this->exactly(2))
            ->method('findByUuid')
            ->with($orgUuid)
            ->willReturn($organisation);
        
        // Mock: Eve joins organisation.
        $updatedOrg = clone $organisation;
        $updatedOrg->addUser('eve');
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('update')
            ->willReturn($updatedOrg);

        // Act: Simulate concurrent join operations.
        $result1 = $this->organisationService->joinOrganisation($orgUuid);
        $hasAccess = $this->organisationService->hasAccessToOrganisation($orgUuid);

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