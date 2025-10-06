<?php
/**
 * Default Organisation Management Unit Tests
 *
 * This test class covers all scenarios related to default organisation creation,
 * user auto-assignment, and preventing multiple default organisations.
 * 
 * Test Coverage:
 * - Test 1.1: Default Organisation Creation on Empty Database
 * - Test 1.2: User Auto-Assignment to Default Organisation  
 * - Test 1.3: Multiple Default Organisations Prevention
 *
 * Key Features Tested:
 * - Automatic default organisation creation when none exists
 * - User auto-assignment to default organisation on first access
 * - Database constraints preventing multiple default organisations
 * - Proper UUID generation and metadata
 * - Session management for active organisation
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
use OCP\IUserSession;
use OCP\IUser;
use OCP\ISession;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Test class for Default Organisation Management
 */
class DefaultOrganisationManagementTest extends TestCase
{
    /**
     * @var OrganisationService
     */
    private OrganisationService $organisationService;
    
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
    }

    /**
     * Clean up after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clear static cache to prevent test interference
        $this->organisationService->clearDefaultOrganisationCache();
        
        unset(
            $this->organisationService,
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->logger,
            $this->mockUser
        );
    }

    /**
     * Test 1.1: Default Organisation Creation on Empty Database
     * 
     * Scenario: System creates default organisation when none exists
     * Expected: Default organisation is created with proper metadata
     *
     * @return void
     */
    public function testDefaultOrganisationCreationOnEmptyDatabase(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        // Mock: No default organisation exists initially
        $this->organisationMapper
            ->expects($this->once())
            ->method('findDefault')
            ->willThrowException(new DoesNotExistException('No default organisation found'));
        
        // Mock: Default organisation creation
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setDescription('Default organisation for users without specific organisation membership');
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setOwner('system');
        $defaultOrg->setUuid('default-uuid-123');
        $defaultOrg->setUsers(['alice']);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('createDefault')
            ->willReturn($defaultOrg);
        
        // Mock: User organisations lookup (empty initially)
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUserId')
            ->with('alice')
            ->willReturn([]);
        
        // Mock: Default organisation update (called multiple times - once for admin users, once for current user)
        $this->organisationMapper
            ->expects($this->atLeast(2))
            ->method('update')
            ->willReturn($defaultOrg);

        // Act: Get user organisations (should trigger default creation)
        $organisations = $this->organisationService->getUserOrganisations(false);

        // Assert: Default organisation was created and user was added
        $this->assertCount(1, $organisations);
        $this->assertInstanceOf(Organisation::class, $organisations[0]);
        $this->assertEquals('Default Organisation', $organisations[0]->getName());
        $this->assertTrue($organisations[0]->getIsDefault());
        $this->assertTrue($organisations[0]->hasUser('alice'));
        $this->assertEquals('system', $organisations[0]->getOwner());
    }

    /**
     * Test 1.2: User Auto-Assignment to Default Organisation
     *
     * Scenario: New user automatically gets assigned to default organisation
     * Expected: User is added to existing default organisation
     *
     * @return void
     */
    public function testUserAutoAssignmentToDefaultOrganisation(): void
    {
        // Arrange: Mock user session for new user 'bob'
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bobUser);
        
        // Mock: Default organisation exists
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setDescription('Default organisation for users without specific organisation membership');
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setOwner('system');
        $defaultOrg->setUuid('default-uuid-123');
        $defaultOrg->setUsers(['alice']); // Alice already in default org
        
        $this->organisationMapper
            ->method('findDefault')
            ->willReturn($defaultOrg);
        
        // Mock: Bob has no organisations initially
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUserId')
            ->with('bob')
            ->willReturn([]);
        
        // Mock: Update default organisation to add Bob
        $updatedDefaultOrg = clone $defaultOrg;
        $updatedDefaultOrg->addUser('bob');
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function($org) {
                return $org instanceof Organisation && 
                       $org->hasUser('alice') && 
                       $org->hasUser('bob') &&
                       $org->getIsDefault() === true;
            }))
            ->willReturn($updatedDefaultOrg);

        // Act: Get user organisations for Bob
        $organisations = $this->organisationService->getUserOrganisations(false);

        // Assert: Bob was automatically assigned to default organisation
        $this->assertCount(1, $organisations);
        $this->assertInstanceOf(Organisation::class, $organisations[0]);
        $this->assertEquals('Default Organisation', $organisations[0]->getName());
        $this->assertTrue($organisations[0]->getIsDefault());
        $this->assertTrue($organisations[0]->hasUser('bob'));
        $this->assertTrue($organisations[0]->hasUser('alice'));
    }

    /**
     * Test 1.3: Multiple Default Organisations Prevention
     *
     * Scenario: System prevents creation of multiple default organisations
     * Expected: Attempt to create second default organisation should fail
     *
     * @return void
     */
    public function testMultipleDefaultOrganisationsPrevention(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        // Mock: Default organisation already exists
        $existingDefaultOrg = new Organisation();
        $existingDefaultOrg->setName('Default Organisation');
        $existingDefaultOrg->setIsDefault(true);
        $existingDefaultOrg->setOwner('system');
        $existingDefaultOrg->setUuid('existing-default-uuid');
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findDefault')
            ->willReturn($existingDefaultOrg);

        // Act: Attempt to ensure default organisation exists (should return existing one)
        $defaultOrg = $this->organisationService->ensureDefaultOrganisation();

        // Assert: Existing default organisation is returned, no new one created
        $this->assertInstanceOf(Organisation::class, $defaultOrg);
        $this->assertEquals('existing-default-uuid', $defaultOrg->getUuid());
        $this->assertTrue($defaultOrg->getIsDefault());
        $this->assertEquals('system', $defaultOrg->getOwner());
    }

    /**
     * Test 1.3b: Database Constraint Prevention of Multiple Defaults
     *
     * Scenario: Database constraints prevent multiple default organisations
     * Expected: Database should enforce uniqueness constraint
     *
     * @return void
     */
    public function testDatabaseConstraintPreventionOfMultipleDefaults(): void
    {
        // Arrange: Mock existing default organisation exists
        $existingDefault = new Organisation();
        $existingDefault->setName('Default Organisation');
        $existingDefault->setIsDefault(true);
        $existingDefault->setUuid('existing-default-uuid-456');
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findDefault')
            ->willReturn($existingDefault);

        // Mock: createDefault should never be called when default exists
        $this->organisationMapper
            ->expects($this->never())
            ->method('createDefault');

        // Act: Ensure default organisation (should return existing one)
        $result = $this->organisationService->ensureDefaultOrganisation();
        
        // Assert: Existing default organisation is returned, no new one created
        $this->assertInstanceOf(Organisation::class, $result);
        $this->assertEquals('existing-default-uuid-456', $result->getUuid());
        $this->assertTrue($result->getIsDefault());
    }

    /**
     * Test active organisation auto-setting with default organisation
     *
     * Scenario: When user has no active organisation, default should be set as active
     * Expected: Default organisation becomes active automatically
     *
     * @return void
     */
    public function testActiveOrganisationAutoSettingWithDefault(): void
    {
        // Arrange: Mock user session
        $this->mockUser->method('getUID')->willReturn('charlie');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        // Mock: No active organisation in config initially
        $this->config
            ->expects($this->once())
            ->method('getUserValue')
            ->with('charlie', 'openregister', 'active_organisation', '')
            ->willReturn('');
        
        // Mock: User has default organisation
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setUuid('default-uuid-456');
        $defaultOrg->setUsers(['charlie']);
        $defaultOrg->setCreated(new \DateTime('2024-01-01'));
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUserId')
            ->with('charlie')
            ->willReturn([$defaultOrg]);
        
        // Mock: Set active organisation in config
        $this->config
            ->expects($this->once())
            ->method('setUserValue')
            ->with('charlie', 'openregister', 'active_organisation', 'default-uuid-456');

        // Act: Get active organisation
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Default organisation is set as active
        $this->assertInstanceOf(Organisation::class, $activeOrg);
        $this->assertEquals('default-uuid-456', $activeOrg->getUuid());
        $this->assertTrue($activeOrg->getIsDefault());
    }

    /**
     * Test default organisation metadata validation
     *
     * Scenario: Default organisation should have correct metadata
     * Expected: Proper name, description, owner, and flags
     *
     * @return void
     */
    public function testDefaultOrganisationMetadataValidation(): void
    {
        // Arrange: Mock no existing default
        $this->organisationMapper
            ->expects($this->once())
            ->method('findDefault')
            ->willThrowException(new DoesNotExistException('No default organisation'));
        
        // Mock: Default organisation creation with proper metadata
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setDescription('Default organisation for users without specific organisation membership');
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setOwner('system');
        $defaultOrg->setUuid('metadata-test-uuid');
        $createdDate = new \DateTime();
        $defaultOrg->setCreated($createdDate);
        $defaultOrg->setUpdated($createdDate);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('createDefault')
            ->willReturn($defaultOrg);
            
        $this->organisationMapper
            ->expects($this->once())
            ->method('update')
            ->willReturn($defaultOrg);

        // Act: Ensure default organisation
        $result = $this->organisationService->ensureDefaultOrganisation();

        // Assert: Metadata is correct
        $this->assertEquals('Default Organisation', $result->getName());
        $this->assertEquals('Default organisation for users without specific organisation membership', $result->getDescription());
        $this->assertTrue($result->getIsDefault());
        $this->assertEquals('system', $result->getOwner());
        $this->assertNotNull($result->getUuid());
        $this->assertInstanceOf(\DateTime::class, $result->getCreated());
        $this->assertInstanceOf(\DateTime::class, $result->getUpdated());
    }
} 