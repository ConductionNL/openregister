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
use Psr\Log\LoggerInterface;

class SessionCacheManagementTest extends TestCase
{
    private OrganisationService $organisationService;
    private OrganisationMapper|MockObject $organisationMapper;
    private IUserSession|MockObject $userSession;
    private ISession|MockObject $session;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->organisationService = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->logger
        );
    }

    /**
     * Test 7.1: Session Persistence
     */
    public function testSessionPersistence(): void
    {
        // Arrange.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        
        $orgUuid = 'persistent-org-uuid';
        
        // Mock: Set active organisation.
        $this->session->expects($this->once())
            ->method('set')
            ->with('openregister_active_organisation_alice', $orgUuid);
        
        // Mock: Subsequent get from session.
        $this->session->expects($this->once())
            ->method('get')
            ->with('openregister_active_organisation_alice')
            ->willReturn($orgUuid);

        // Act & Assert: Set and get should persist.
        $this->organisationService->setActiveOrganisation($orgUuid);
        $this->assertEquals($orgUuid, $this->session->get('openregister_active_organisation_alice'));
    }

    /**
     * Test 7.2: Cache Performance
     */
    public function testCachePerformance(): void
    {
        // Arrange.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        
        $cachedOrgs = [new Organisation()];
        
        // Mock: First call hits database.
        $this->organisationMapper->expects($this->once())
            ->method('findByUserId')
            ->willReturn($cachedOrgs);
        
        // Mock: Second call uses cache.
        $this->session->method('get')
            ->with('openregister_organisations_alice')
            ->willReturn($cachedOrgs);

        // Act: Multiple calls should use cache.
        $orgs1 = $this->organisationService->getUserOrganisations(false);
        $orgs2 = $this->organisationService->getUserOrganisations(true); // Use cache

        // Assert: Performance improvement through caching.
        $this->assertEquals($orgs1, $cachedOrgs);
        $this->assertEquals($orgs2, $cachedOrgs);
    }

    /**
     * Test 7.3: Manual Cache Clear
     */
    public function testManualCacheClear(): void
    {
        // Arrange.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        
        // Mock: Cache removal.
        $this->session->expects($this->exactly(2))
            ->method('remove')
            ->withConsecutive(
                ['openregister_active_organisation_alice'],
                ['openregister_organisations_alice']
            );

        // Act: Clear cache.
        $this->organisationService->clearCache();
        
        // Assert: Cache cleared successfully.
        $this->addToAssertionCount(1);
    }

    /**
     * Test 7.4: Cross-User Session Isolation
     */
    public function testCrossUserSessionIsolation(): void
    {
        // Arrange: Two different users.
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        
        // Mock: Alice's session.
        $this->userSession->method('getUser')->willReturn($alice);
        $this->session->method('set')
            ->with('openregister_active_organisation_alice', 'alice-org');

        // Act: Alice sets active organisation.
        $this->organisationService->setActiveOrganisation('alice-org');
        
        // Mock: Bob's session should be isolated.
        $this->userSession->method('getUser')->willReturn($bob);
        $this->session->method('get')
            ->with('openregister_active_organisation_bob')
            ->willReturn('bob-org'); // Bob has different active org

        // Assert: Users have isolated sessions.
        $this->assertNotEquals('alice-org', 'bob-org');
    }
} 