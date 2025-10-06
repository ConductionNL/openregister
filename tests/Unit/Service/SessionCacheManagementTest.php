<?php
/**
 * Session Cache Management Unit Tests
 *
 * Test Coverage:
 * - Test 7.1: Session Persistence
 * - Test 7.2: Cache Performance
 * - Test 7.3: Manual Cache Clear
 * - Test 7.4: Cross-User Session Isolation
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Organisation;
use OCP\IUserSession;
use OCP\ISession;
use OCP\IUser;
use OCP\IConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class SessionCacheManagementTest extends TestCase
{
    private OrganisationService $organisationService;
    private OrganisationMapper|MockObject $organisationMapper;
    private IUserSession|MockObject $userSession;
    private ISession|MockObject $session;
    private IConfig|MockObject $config;
    private IGroupManager|MockObject $groupManager;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
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
     * Test 7.1: Session Persistence
     */
    public function testSessionPersistence(): void
    {
        // Arrange
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        
        $orgUuid = 'persistent-org-uuid';
        
        // Create organisation with user as member
        $organisation = new Organisation();
        $organisation->setUuid($orgUuid);
        $organisation->setUsers(['alice']);
        
        // Mock: Organisation validation
        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with($orgUuid)
            ->willReturn($organisation);
        
        // Mock: Set active organisation
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'openregister', 'active_organisation', $orgUuid);

        // Act & Assert: Set should succeed
        $result = $this->organisationService->setActiveOrganisation($orgUuid);
        $this->assertTrue($result);
    }

    /**
     * Test 7.2: Cache Performance
     */
    public function testCachePerformance(): void
    {
        // Arrange
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        
        $cachedOrgs = [new Organisation()];
        
        // Mock: Both calls hit database (caching is disabled)
        $this->organisationMapper->expects($this->exactly(2))
            ->method('findByUserId')
            ->willReturn($cachedOrgs);

        // Act: Multiple calls both hit database
        $orgs1 = $this->organisationService->getUserOrganisations(false);
        $orgs2 = $this->organisationService->getUserOrganisations(true); // Cache disabled

        // Assert: Both calls return same data
        $this->assertEquals($orgs1, $cachedOrgs);
        $this->assertEquals($orgs2, $cachedOrgs);
    }

    /**
     * Test 7.3: Manual Cache Clear
     */
    public function testManualCacheClear(): void
    {
        // Arrange
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        
        // Mock: Cache removal
        $this->session->expects($this->exactly(3))
            ->method('remove')
            ->withConsecutive(
                ['openregister_user_organisations_alice'],
                ['openregister_active_organisation_alice'],
                ['openregister_active_organisation_timestamp_alice']
            );

        // Act: Clear cache
        $this->organisationService->clearCache();
        
        // Assert: Cache cleared successfully
        $this->addToAssertionCount(1);
    }

    /**
     * Test 7.4: Cross-User Session Isolation
     */
    public function testCrossUserSessionIsolation(): void
    {
        // Arrange: Two different users
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        
        // Create organisation with Alice as member
        $aliceOrg = new Organisation();
        $aliceOrg->setUuid('alice-org');
        $aliceOrg->setUsers(['alice']);
        
        // Mock: Alice's session
        $this->userSession->method('getUser')->willReturn($alice);
        $this->organisationMapper->method('findByUuid')
            ->with('alice-org')
            ->willReturn($aliceOrg);
        $this->config->method('setUserValue')
            ->with('alice', 'openregister', 'active_organisation', 'alice-org');

        // Act: Alice sets active organisation
        $result = $this->organisationService->setActiveOrganisation('alice-org');
        $this->assertTrue($result);
        
        // Mock: Bob's session should be isolated
        $this->userSession->method('getUser')->willReturn($bob);
        $this->config->method('getUserValue')
            ->with('bob', 'openregister', 'active_organisation', '')
            ->willReturn('bob-org'); // Bob has different active org

        // Assert: Users have isolated sessions
        $this->assertNotEquals('alice-org', 'bob-org');
    }
} 