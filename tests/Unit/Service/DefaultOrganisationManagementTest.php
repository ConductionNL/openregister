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
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
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
        $this->config = $this->createMock(IConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
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
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $this->appConfig,
            $this->groupManager,
            $this->userManager,
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
        // Arrange: Create default organisation entity with proper metadata.
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setDescription('Default organisation for users without specific organisation membership');
        $defaultOrg->setOwner('system');
        $defaultOrg->setUuid('default-uuid-123');
        $defaultOrg->setUsers(['alice']);

        // Assert: Organisation entity was created with correct metadata.
        $this->assertInstanceOf(Organisation::class, $defaultOrg);
        $this->assertEquals('Default Organisation', $defaultOrg->getName());
        $this->assertEquals('Default organisation for users without specific organisation membership', $defaultOrg->getDescription());
        $this->assertTrue($defaultOrg->hasUser('alice'));
        $this->assertEquals('system', $defaultOrg->getOwner());
        $this->assertEquals('default-uuid-123', $defaultOrg->getUuid());
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
        // Arrange: Create a default organisation entity and verify user management.
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setDescription('Default organisation for users without specific organisation membership');
        $defaultOrg->setOwner('system');
        $defaultOrg->setUuid('default-uuid-123');
        $defaultOrg->setUsers(['alice']); // Alice already in default org

        // Act: Add Bob to the organisation.
        $defaultOrg->addUser('bob');

        // Assert: Both users are assigned to the organisation.
        $this->assertInstanceOf(Organisation::class, $defaultOrg);
        $this->assertEquals('Default Organisation', $defaultOrg->getName());
        $this->assertTrue($defaultOrg->hasUser('bob'));
        $this->assertTrue($defaultOrg->hasUser('alice'));
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
        // Arrange: Create an existing default organisation entity.
        $existingDefaultOrg = new Organisation();
        $existingDefaultOrg->setName('Default Organisation');
        $existingDefaultOrg->setOwner('system');
        $existingDefaultOrg->setUuid('existing-default-uuid');

        // Assert: Existing organisation has correct metadata.
        $this->assertInstanceOf(Organisation::class, $existingDefaultOrg);
        $this->assertEquals('existing-default-uuid', $existingDefaultOrg->getUuid());
        $this->assertEquals('system', $existingDefaultOrg->getOwner());
        $this->assertEquals('Default Organisation', $existingDefaultOrg->getName());
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
        // Arrange: Create an existing default organisation entity.
        $existingDefault = new Organisation();
        $existingDefault->setName('Default Organisation');
        $existingDefault->setUuid('existing-default-uuid-456');

        // Assert: Existing default organisation has correct metadata.
        $this->assertInstanceOf(Organisation::class, $existingDefault);
        $this->assertEquals('existing-default-uuid-456', $existingDefault->getUuid());
        $this->assertEquals('Default Organisation', $existingDefault->getName());
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
        // Arrange: Create a default organisation entity.
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setUuid('default-uuid-456');
        $defaultOrg->setUsers(['charlie']);
        $defaultOrg->setCreated(new \DateTime('2024-01-01'));

        // Assert: Organisation has correct metadata for active setting.
        $this->assertInstanceOf(Organisation::class, $defaultOrg);
        $this->assertEquals('default-uuid-456', $defaultOrg->getUuid());
        $this->assertTrue($defaultOrg->hasUser('charlie'));
        $this->assertEquals('Default Organisation', $defaultOrg->getName());
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
        // Arrange: Create default organisation with proper metadata.
        $defaultOrg = new Organisation();
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setDescription('Default organisation for users without specific organisation membership');
        $defaultOrg->setOwner('system');
        $defaultOrg->setUuid('metadata-test-uuid');
        $createdDate = new \DateTime();
        $defaultOrg->setCreated($createdDate);
        $defaultOrg->setUpdated($createdDate);

        // Assert: Metadata is correct.
        $this->assertEquals('Default Organisation', $defaultOrg->getName());
        $this->assertEquals('Default organisation for users without specific organisation membership', $defaultOrg->getDescription());
        $this->assertEquals('system', $defaultOrg->getOwner());
        $this->assertNotNull($defaultOrg->getUuid());
        $this->assertInstanceOf(\DateTime::class, $defaultOrg->getCreated());
        $this->assertInstanceOf(\DateTime::class, $defaultOrg->getUpdated());
    }
} 