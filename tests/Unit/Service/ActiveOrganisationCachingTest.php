<?php
/**
 * Active Organisation Caching Optimization Unit Tests
 *
 * This test class specifically covers the session caching optimization
 * for the getActiveOrganisation() method to ensure RBAC performance.
 * 
 * Test Coverage:
 * - Cache hit scenarios with valid data
 * - Cache miss scenarios requiring database fetch
 * - Cache expiration handling
 * - Cache invalidation on organisation changes
 * - Performance optimization verification
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
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Test class for Active Organisation Caching Optimization
 */
class ActiveOrganisationCachingTest extends TestCase
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
     * @var IGroupManager|MockObject
     */
    private $groupManager;
    
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
        $appConfig = $this->createMock(IAppConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $userManager = $this->createMock(IUserManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mockUser = $this->createMock(IUser::class);

        // Create service instance with mocked dependencies.
        $this->organisationService = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $appConfig,
            $this->groupManager,
            $userManager,
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
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $this->groupManager,
            $this->logger,
            $this->mockUser
        );
    }

    /**
     * Test cache hit scenario - should return cached organisation without database call
     *
     * @return void
     */
    public function testActiveOrganisationCacheHit(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $orgUuid = 'cached-org-uuid-123';
        $currentTime = time();
        
        // Mock: Valid cache data (within timeout period).
        $cachedOrgData = [
            'id' => 1,
            'uuid' => $orgUuid,
            'name' => 'Cached Organisation',
            'description' => 'Test organisation from cache',
            'owner' => 'alice',
            'users' => ['alice', 'bob'],
            'created' => (new \DateTime())->format('Y-m-d H:i:s'),
            'updated' => (new \DateTime())->format('Y-m-d H:i:s')
        ];
        
        $this->session
            ->method('get')
            ->willReturnMap([
                ['openregister_active_organisation_alice', $cachedOrgData],
                ['openregister_active_organisation_timestamp_alice', $currentTime - 300] // 5 minutes ago
            ]);
        
        // Assert: No database calls should be made for cache hit.
        $this->organisationMapper
            ->expects($this->never())
            ->method('findByUuid');
        
        $this->organisationMapper
            ->expects($this->never())
            ->method('findByUserId');

        // Act: Get active organisation (should use cache).
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Cached organisation is returned.
        $this->assertInstanceOf(Organisation::class, $activeOrg);
        $this->assertEquals($orgUuid, $activeOrg->getUuid());
        $this->assertEquals('Cached Organisation', $activeOrg->getName());
        $this->assertEquals('alice', $activeOrg->getOwner());
    }

    /**
     * Test cache miss scenario - should fetch from database and cache result
     *
     * @return void
     */
    public function testActiveOrganisationCacheMiss(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $orgUuid = 'fresh-org-uuid-456';
        
        // Mock: No cache data (cache miss).
        $this->session
            ->method('get')
            ->willReturnMap([
                ['openregister_active_organisation_bob', null, null],
                ['openregister_active_organisation_timestamp_bob', null, null]
            ]);
        
        // Mock: Active organisation UUID from config.
        $this->config
            ->method('getUserValue')
            ->with('bob', 'openregister', 'active_organisation', '')
            ->willReturn($orgUuid);
        
        // Mock: Organisation exists in database.
        $freshOrg = new Organisation();
        $freshOrg->setId(2);
        $freshOrg->setUuid($orgUuid);
        $freshOrg->setName('Fresh Organisation');
        $freshOrg->setDescription('Fresh from database');
        $freshOrg->setOwner('bob');
        $freshOrg->setUsers(['bob', 'charlie']);
        $freshOrg->setCreated(new \DateTime());
        $freshOrg->setUpdated(new \DateTime());
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($orgUuid)
            ->willReturn($freshOrg);
        
        // Mock: Cache storage - expect organisation data to be cached.
        $this->session
            ->expects($this->atLeastOnce())
            ->method('set');

        // Act: Get active organisation (should fetch and cache).
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Fresh organisation from database is returned.
        $this->assertInstanceOf(Organisation::class, $activeOrg);
        $this->assertEquals($orgUuid, $activeOrg->getUuid());
        $this->assertEquals('Fresh Organisation', $activeOrg->getName());
        $this->assertEquals('bob', $activeOrg->getOwner());
    }

    /**
     * Test cache expiration scenario - should fetch fresh data when cache is expired
     *
     * @return void
     */
    public function testActiveOrganisationCacheExpiration(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('charlie');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $orgUuid = 'expired-cache-org-uuid';
        $expiredTime = time() - 1000; // Cache expired (older than 900 seconds)
        
        // Mock: Expired cache data.
        $expiredCacheData = [
            'uuid' => $orgUuid,
            'name' => 'Expired Cache Org'
        ];
        
        $this->session
            ->method('get')
            ->willReturnMap([
                ['openregister_active_organisation_charlie', null, $expiredCacheData],
                ['openregister_active_organisation_timestamp_charlie', null, $expiredTime]
            ]);
        
        // Mock: Fresh organisation from config and database.
        $this->config
            ->method('getUserValue')
            ->with('charlie', 'openregister', 'active_organisation', '')
            ->willReturn($orgUuid);
        
        $freshOrg = new Organisation();
        $freshOrg->setUuid($orgUuid);
        $freshOrg->setName('Updated Organisation Name');
        $freshOrg->setUsers(['charlie']);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with($orgUuid)
            ->willReturn($freshOrg);
        
        // Mock: Cache should be updated with fresh data.
        $this->session
            ->expects($this->exactly(2))
            ->method('set');

        // Act: Get active organisation (should refresh expired cache).
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Fresh organisation is returned (not expired cache).
        $this->assertInstanceOf(Organisation::class, $activeOrg);
        $this->assertEquals($orgUuid, $activeOrg->getUuid());
        $this->assertEquals('Updated Organisation Name', $activeOrg->getName());
    }

    /**
     * Test cache reconstruction with DateTime objects (not strings)
     *
     * @return void
     */
    public function testCacheReconstructionWithDateTimeObjects(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('dt-user');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $createdDt = new \DateTime('2024-06-15 10:00:00');
        $updatedDt = new \DateTime('2024-06-16 12:00:00');

        // Mock: Cache data with DateTime objects instead of strings.
        $cachedOrgData = [
            'id' => 5,
            'uuid' => 'dt-org-uuid',
            'name' => 'DateTime Org',
            'description' => 'Testing DateTime cache',
            'owner' => 'dt-user',
            'users' => ['dt-user'],
            'created' => $createdDt,
            'updated' => $updatedDt,
        ];

        $this->session
            ->method('get')
            ->willReturnMap([
                ['openregister_active_organisation_dt-user', $cachedOrgData],
                ['openregister_active_organisation_timestamp_dt-user', time() - 100],
            ]);

        // Act.
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Organisation reconstructed with DateTime objects.
        $this->assertInstanceOf(Organisation::class, $activeOrg);
        $this->assertEquals('dt-org-uuid', $activeOrg->getUuid());
        $this->assertInstanceOf(\DateTime::class, $activeOrg->getCreated());
        $this->assertInstanceOf(\DateTime::class, $activeOrg->getUpdated());
    }

    /**
     * Test active org cleared when user no longer has access (stale config)
     *
     * @return void
     */
    public function testActiveOrgClearedWhenUserNoLongerMember(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('stale-user');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: No cache (cache miss).
        $this->session->method('get')->willReturn(null);

        // Mock: Config has a stale active org UUID.
        $this->config->method('getUserValue')
            ->with('stale-user', 'openregister', 'active_organisation', '')
            ->willReturn('stale-org-uuid');

        // Mock: Org exists but user is no longer a member.
        $staleOrg = new Organisation();
        $staleOrg->setUuid('stale-org-uuid');
        $staleOrg->setName('Stale Org');
        $staleOrg->setUsers(['alice']); // stale-user NOT in users list.

        $this->organisationMapper
            ->method('findByUuid')
            ->with('stale-org-uuid')
            ->willReturn($staleOrg);

        // Mock: deleteUserValue called to clear stale config.
        $this->config->expects($this->atLeastOnce())
            ->method('deleteUserValue');

        // Mock: getUserOrganisations returns the user's actual orgs for fallback.
        $actualOrg = new Organisation();
        $actualOrg->setUuid('actual-org-uuid');
        $actualOrg->setName('Actual Org');
        $actualOrg->setUsers(['stale-user']);
        $actualOrg->setCreated(new \DateTime('2024-01-01'));

        $this->organisationMapper
            ->method('findByUserId')
            ->with('stale-user')
            ->willReturn([$actualOrg]);

        // Mock: setUserValue for auto-set.
        $this->config->expects($this->atLeastOnce())
            ->method('setUserValue');

        // Act.
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Falls back to user's actual org.
        $this->assertInstanceOf(Organisation::class, $activeOrg);
        $this->assertEquals('actual-org-uuid', $activeOrg->getUuid());
    }

    /**
     * Test active org cleared when org was deleted from DB
     *
     * @return void
     */
    public function testActiveOrgClearedWhenOrgDeleted(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('deleted-user');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Mock: No cache.
        $this->session->method('get')->willReturn(null);

        // Mock: Config has a deleted org UUID.
        $this->config->method('getUserValue')
            ->with('deleted-user', 'openregister', 'active_organisation', '')
            ->willReturn('deleted-org-uuid');

        // Mock: findByUuid throws DoesNotExistException.
        $this->organisationMapper
            ->method('findByUuid')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        // Mock: deleteUserValue called.
        $this->config->expects($this->atLeastOnce())
            ->method('deleteUserValue');

        // Mock: user has fallback orgs.
        $fallbackOrg = new Organisation();
        $fallbackOrg->setUuid('fallback-org-uuid');
        $fallbackOrg->setName('Fallback Org');
        $fallbackOrg->setUsers(['deleted-user']);
        $fallbackOrg->setCreated(new \DateTime('2024-03-01'));

        $this->organisationMapper
            ->method('findByUserId')
            ->with('deleted-user')
            ->willReturn([$fallbackOrg]);

        // Act.
        $activeOrg = $this->organisationService->getActiveOrganisation();

        // Assert: Falls back to user's remaining org.
        $this->assertInstanceOf(Organisation::class, $activeOrg);
        $this->assertEquals('fallback-org-uuid', $activeOrg->getUuid());
    }

    /**
     * Test cache invalidation on setActiveOrganisation
     *
     * @return void
     */
    public function testCacheInvalidationOnSetActive(): void
    {
        // Arrange: Mock user session.
        $this->mockUser->method('getUID')->willReturn('diana');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $newActiveUuid = 'new-active-org-uuid';
        
        // Mock: Organisation exists and user is member.
        $newActiveOrg = new Organisation();
        $newActiveOrg->setUuid($newActiveUuid);
        $newActiveOrg->setName('New Active Org');
        $newActiveOrg->setUsers(['diana']);
        
        $this->organisationMapper
            ->method('findByUuid')
            ->with($newActiveUuid)
            ->willReturn($newActiveOrg);
        
        // Mock: Config update.
        $this->config
            ->expects($this->once())
            ->method('setUserValue');
        
        // Mock: Cache invalidation and new cache storage.
        $this->session
            ->expects($this->atLeastOnce())
            ->method('remove');
        
        $this->session
            ->expects($this->exactly(2))
            ->method('set');

        // Act: Set new active organisation.
        $result = $this->organisationService->setActiveOrganisation($newActiveUuid);

        // Assert: Operation succeeds.
        $this->assertTrue($result);
    }
}
