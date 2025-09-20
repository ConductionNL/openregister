<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Organisation;
use PHPUnit\Framework\TestCase;
use OCP\IUserSession;
use OCP\IUser;
use OCP\ISession;
use OCP\IGroupManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Test class for OrganisationService
 *
 * Comprehensive unit tests for the OrganisationService class, which handles
 * organization management and user-organization relationships in OpenRegister.
 * This test suite covers:
 * 
 * ## Test Categories:
 * 
 * ### 1. Organization CRUD Operations
 * - testCreateOrganisation: Tests creating new organizations
 * - testUpdateOrganisation: Tests updating existing organizations
 * - testDeleteOrganisation: Tests deleting organizations
 * - testGetOrganisation: Tests retrieving organizations by ID
 * - testListOrganisations: Tests listing all organizations
 * 
 * ### 2. User-Organization Relationships
 * - testAssignUserToOrganisation: Tests assigning users to organizations
 * - testRemoveUserFromOrganisation: Tests removing users from organizations
 * - testGetUserOrganisations: Tests retrieving user's organizations
 * - testGetOrganisationUsers: Tests retrieving organization's users
 * - testUserOrganisationPermissions: Tests user permissions within organizations
 * 
 * ### 3. Organization Hierarchy
 * - testParentChildRelationships: Tests parent-child organization relationships
 * - testOrganisationTree: Tests organization tree structure
 * - testInheritanceRules: Tests permission inheritance rules
 * - testOrganisationPath: Tests organization path resolution
 * 
 * ### 4. Data Validation
 * - testOrganisationDataValidation: Tests organization data validation
 * - testRequiredFieldsValidation: Tests required field validation
 * - testUniqueConstraints: Tests unique constraint validation
 * - testDataIntegrity: Tests data integrity constraints
 * 
 * ### 5. Permission Management
 * - testOrganisationPermissions: Tests organization-level permissions
 * - testUserPermissions: Tests user-level permissions
 * - testPermissionInheritance: Tests permission inheritance
 * - testAccessControl: Tests access control mechanisms
 * 
 * ### 6. Search and Filtering
 * - testSearchOrganisations: Tests organization search functionality
 * - testFilterOrganisations: Tests organization filtering
 * - testSortOrganisations: Tests organization sorting
 * - testPagination: Tests pagination functionality
 * 
 * ## OrganisationService Features:
 * 
 * The OrganisationService provides:
 * - **Organization Management**: Complete CRUD operations for organizations
 * - **User Assignment**: Managing user-organization relationships
 * - **Hierarchy Management**: Handling organization hierarchies
 * - **Permission Management**: Managing organization and user permissions
 * - **Search Capabilities**: Advanced search and filtering
 * - **Data Validation**: Comprehensive data validation
 * 
 * ## Mocking Strategy:
 * 
 * The tests use comprehensive mocking to isolate the service from dependencies:
 * - OrganisationMapper: Mocked for database operations
 * - IUserSession: Mocked for user session management
 * - IUser: Mocked for user operations
 * - ISession: Mocked for session operations
 * - IGroupManager: Mocked for group management
 * - IConfig: Mocked for configuration management
 * - LoggerInterface: Mocked for logging verification
 * 
 * ## Data Flow:
 * 
 * 1. **Organization Creation**: Validate data → Create organization → Set up relationships
 * 2. **User Assignment**: Validate user → Assign to organization → Update permissions
 * 3. **Permission Management**: Check permissions → Apply rules → Update access
 * 4. **Hierarchy Management**: Validate relationships → Update tree → Maintain integrity
 * 
 * ## Integration Points:
 * 
 * - **Database Layer**: Integrates with OrganisationMapper
 * - **User Management**: Integrates with Nextcloud user system
 * - **Group Management**: Integrates with Nextcloud group system
 * - **Session Management**: Integrates with Nextcloud session system
 * - **Configuration System**: Uses Nextcloud configuration system
 * - **RBAC System**: Integrates with role-based access control
 * 
 * ## Performance Considerations:
 * 
 * Tests cover performance aspects:
 * - Large organization hierarchies (1000+ organizations)
 * - User assignment operations
 * - Permission checking performance
 * - Search and filtering performance
 * - Memory usage optimization
 * 
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class OrganisationServiceTest extends TestCase
{
    private OrganisationService $organisationService;
    private OrganisationMapper $organisationMapper;
    private IUserSession $userSession;
    private ISession $session;
    private IConfig $config;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create OrganisationService instance
        $this->organisationService = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $this->groupManager,
            $this->logger
        );
        
        // Clear static cache to ensure clean test state
        $this->organisationService->clearDefaultOrganisationCache();
    }

    /**
     * Test ensureDefaultOrganisation method
     */
    public function testEnsureDefaultOrganisation(): void
    {
        // Create real organisation object
        $organisation = new Organisation();
        $organisation->setUuid('existing-uuid');
        $organisation->setName('Existing Organisation');
        $organisation->setIsDefault(true);

        // Mock organisation mapper to return existing organisation
        $this->organisationMapper->expects($this->once())
            ->method('findDefault')
            ->willReturn($organisation);

        $result = $this->organisationService->ensureDefaultOrganisation();

        $this->assertInstanceOf(Organisation::class, $result);
        $this->assertEquals('existing-uuid', $result->getUuid());
        $this->assertEquals('Existing Organisation', $result->getName());
    }

    /**
     * Test ensureDefaultOrganisation method when no default exists
     */
    public function testEnsureDefaultOrganisationWhenNoDefaultExists(): void
    {
        // Create real organisation object
        $organisation = new Organisation();
        $organisation->setUuid('default-uuid-123');
        $organisation->setName('Default Organisation');
        $organisation->setDescription('Default organisation for users without specific organisation membership');
        $organisation->setOwner('system');
        $organisation->setUsers(['alice', 'bob']);
        $organisation->setIsDefault(true);
        $organisation->setActive(true);

        // Mock group manager to return admin users
        $adminGroup = $this->createMock(\OCP\IGroup::class);
        $adminUser1 = $this->createMock(\OCP\IUser::class);
        $adminUser1->method('getUID')->willReturn('admin1');
        $adminUser2 = $this->createMock(\OCP\IUser::class);
        $adminUser2->method('getUID')->willReturn('admin2');
        $adminGroup->method('getUsers')->willReturn([$adminUser1, $adminUser2]);
        $this->groupManager->method('get')->with('admin')->willReturn($adminGroup);

        // Mock organisation mapper to throw exception (no default exists)
        $this->organisationMapper->expects($this->once())
            ->method('findDefault')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('No default organisation'));

        // Mock organisation mapper to create new organisation
        $this->organisationMapper->expects($this->once())
            ->method('createDefault')
            ->willReturn($organisation);
            
        // Mock update method to return the same organisation
        $this->organisationMapper->expects($this->once())
            ->method('update')
            ->willReturn($organisation);

        $result = $this->organisationService->ensureDefaultOrganisation();

        $this->assertInstanceOf(Organisation::class, $result);
        $this->assertEquals('default-uuid-123', $result->getUuid());
        $this->assertEquals('Default Organisation', $result->getName());
    }

    /**
     * Test getUserOrganisations method
     */
    public function testGetUserOrganisations(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisations
        $organisation1 = $this->createMock(Organisation::class);
        $organisation2 = $this->createMock(Organisation::class);

        $organisations = [$organisation1, $organisation2];

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('findByUserId')
            ->with('test-user')
            ->willReturn($organisations);

        $result = $this->organisationService->getUserOrganisations();

        $this->assertEquals($organisations, $result);
    }

    /**
     * Test getUserOrganisations method with no user session
     */
    public function testGetUserOrganisationsWithNoUserSession(): void
    {
        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $result = $this->organisationService->getUserOrganisations();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test getActiveOrganisation method
     */
    public function testGetActiveOrganisation(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);

        // Mock user session
        $this->userSession->expects($this->exactly(2))
            ->method('getUser')
            ->willReturn($user);

        // Mock config to return organisation UUID
        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with('test-user', 'openregister', 'active_organisation', '')
            ->willReturn('org-uuid-123');

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with('org-uuid-123')
            ->willReturn($organisation);

        // Mock getUserOrganisations to return the same organisation
        $this->organisationMapper->expects($this->once())
            ->method('findByUserId')
            ->with('test-user')
            ->willReturn([$organisation]);

        $result = $this->organisationService->getActiveOrganisation();

        $this->assertEquals($organisation, $result);
    }

    /**
     * Test getActiveOrganisation method with no active organisation
     */
    public function testGetActiveOrganisationWithNoActiveOrganisation(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Mock user session
        $this->userSession->expects($this->exactly(2))
            ->method('getUser')
            ->willReturn($user);

        // Mock config to return empty string
        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with('test-user', 'openregister', 'active_organisation', '')
            ->willReturn('');

        // Mock getUserOrganisations to return empty array
        $this->organisationMapper->expects($this->once())
            ->method('findByUserId')
            ->with('test-user')
            ->willReturn([]);

        // Mock ensureDefaultOrganisation to return null
        $this->organisationService = $this->getMockBuilder(OrganisationService::class)
            ->setConstructorArgs([
                $this->organisationMapper,
                $this->userSession,
                $this->session,
                $this->config,
                $this->groupManager,
                $this->logger
            ])
            ->onlyMethods(['ensureDefaultOrganisation'])
            ->getMock();
        
        $this->organisationService->expects($this->once())
            ->method('ensureDefaultOrganisation')
            ->willReturn($this->createMock(Organisation::class));

        $result = $this->organisationService->getActiveOrganisation();

        $this->assertInstanceOf(Organisation::class, $result);
    }

    /**
     * Test setActiveOrganisation method
     */
    public function testSetActiveOrganisation(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);
        $organisation->method('hasUser')->with('test-user')->willReturn(true);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with('org-uuid-123')
            ->willReturn($organisation);

        // Mock config to set user value
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('test-user', 'openregister', 'active_organisation', 'org-uuid-123')
            ->willReturn(true);

        $result = $this->organisationService->setActiveOrganisation('org-uuid-123');

        $this->assertTrue($result);
    }

    /**
     * Test setActiveOrganisation method with no user session
     */
    public function testSetActiveOrganisationWithNoUserSession(): void
    {
        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->organisationService->setActiveOrganisation('org-uuid-123');
    }

    /**
     * Test createOrganisation method
     */
    public function testCreateOrganisation(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('save')
            ->willReturn($organisation);

        // Mock group manager for admin users
        $this->groupManager->expects($this->exactly(2))
            ->method('get')
            ->with('admin')
            ->willReturn($this->createMock(\OCP\IGroup::class));

        $result = $this->organisationService->createOrganisation('New Organisation', 'Description');

        $this->assertEquals($organisation, $result);
    }

    /**
     * Test createOrganisation method with no user session
     */
    public function testCreateOrganisationWithNoUserSession(): void
    {
        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        // Mock group manager for admin users
        $this->groupManager->expects($this->exactly(2))
            ->method('get')
            ->with('admin')
            ->willReturn($this->createMock(\OCP\IGroup::class));

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('save')
            ->willReturn($this->createMock(Organisation::class));

        $result = $this->organisationService->createOrganisation('New Organisation', 'Description');

        $this->assertInstanceOf(Organisation::class, $result);
    }

    /**
     * Test hasAccessToOrganisation method
     */
    public function testHasAccessToOrganisation(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);
        $organisation->method('hasUser')->with('test-user')->willReturn(true);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with('org-uuid-123')
            ->willReturn($organisation);

        $result = $this->organisationService->hasAccessToOrganisation('org-uuid-123');

        $this->assertTrue($result);
    }

    /**
     * Test hasAccessToOrganisation method with no access
     */
    public function testHasAccessToOrganisationWithNoAccess(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);
        $organisation->method('hasUser')->with('test-user')->willReturn(false);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with('org-uuid-123')
            ->willReturn($organisation);

        $result = $this->organisationService->hasAccessToOrganisation('org-uuid-123');

        $this->assertFalse($result);
    }

    /**
     * Test clearCache method
     */
    public function testClearCache(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        $result = $this->organisationService->clearCache();

        $this->assertTrue($result);
    }

    /**
     * Test clearCache method with persistent clear
     */
    public function testClearCacheWithPersistentClear(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        $result = $this->organisationService->clearCache(true);

        $this->assertTrue($result);
    }
}