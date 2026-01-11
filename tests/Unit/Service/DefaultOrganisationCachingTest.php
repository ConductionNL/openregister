<?php
/**
 * Default Organisation Caching Optimization Unit Tests
 *
 * This test class covers the static application-level caching optimization
 * for the ensureDefaultOrganisation() method to improve RBAC and general performance.
 * 
 * Test Coverage:
 * - Static cache hit scenarios with valid data
 * - Static cache miss scenarios requiring database fetch
 * - Static cache expiration handling
 * - Cache invalidation when default organisation is modified
 * - Performance optimization verification
 * - Cross-instance cache sharing
 *
 * Key Features Tested:
 * - Application-wide caching (not session-based)
 * - Cache persistence across multiple service instances
 * - Automatic cache invalidation on modifications
 * - Admin user addition handling with cache management
 * - Cache expiration after timeout period
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
use OCP\IConfig;
use OCP\IGroup;
use Psr\Log\LoggerInterface;

/**
 * Test class for Default Organisation Caching Optimization
 */
class DefaultOrganisationCachingTest extends TestCase
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
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear static cache before each test.
        $this->clearStaticCache();
        
        // Create mock objects.
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Create service instance with mocked dependencies.
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
        
        // Clear static cache after each test.
        $this->clearStaticCache();
        
        unset(
            $this->organisationService,
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $this->groupManager,
            $this->logger
        );
    }

    /**
     * Clear static cache using reflection (for testing purposes)
     *
     * @return void
     */
    private function clearStaticCache(): void
    {
        $reflection = new \ReflectionClass(OrganisationService::class);
        
        $cacheProperty = $reflection->getProperty('defaultOrganisationCache');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue(null);
        
        $timestampProperty = $reflection->getProperty('defaultOrganisationCacheTimestamp');
        $timestampProperty->setAccessible(true);
        $timestampProperty->setValue(null);
    }

    /**
     * Test static cache hit scenario - should return cached organisation without database call
     *
     * @return void
     */
    public function testDefaultOrganisationStaticCacheHit(): void
    {
        // Arrange: Create default organisation.
        $defaultOrg = new Organisation();
        $defaultOrg->setId(1);
        $defaultOrg->setUuid('default-uuid-123');
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setOwner('system');
        $defaultOrg->setUsers(['admin']);
        
        // Mock admin group.
        $adminGroup = $this->createMock(IGroup::class);
        $adminUser = $this->createMock(IUser::class);
        $adminUser->method('getUID')->willReturn('admin');
        $adminGroup->method('getUsers')->willReturn([$adminUser]);
        $this->groupManager->method('get')->with('admin')->willReturn($adminGroup);
        
        // First call: Should fetch from database and cache.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findDefault')
            ->willReturn($defaultOrg);
        
        // First call to populate cache.
        $firstResult = $this->organisationService->ensureDefaultOrganisation();
        
        // Second call: Should use cache (no database call).
        $this->organisationMapper
            ->expects($this->never())
            ->method('findDefault');
        
        // Act: Second call should hit cache.
        $secondResult = $this->organisationService->ensureDefaultOrganisation();

        // Assert: Both calls return the same organisation data.
        $this->assertInstanceOf(Organisation::class, $firstResult);
        $this->assertInstanceOf(Organisation::class, $secondResult);
        $this->assertEquals($defaultOrg->getUuid(), $firstResult->getUuid());
        $this->assertEquals($defaultOrg->getUuid(), $secondResult->getUuid());
        $this->assertEquals('Default Organisation', $firstResult->getName());
        $this->assertEquals('Default Organisation', $secondResult->getName());
    }

    /**
     * Test cache expiration - should fetch fresh data when cache expires
     *
     * @return void
     */
    public function testDefaultOrganisationCacheExpiration(): void
    {
        // Arrange: Mock admin group.
        $adminGroup = $this->createMock(IGroup::class);
        $adminUser = $this->createMock(IUser::class);
        $adminUser->method('getUID')->willReturn('admin');
        $adminGroup->method('getUsers')->willReturn([$adminUser]);
        $this->groupManager->method('get')->with('admin')->willReturn($adminGroup);
        
        // Create default organisation.
        $defaultOrg = new Organisation();
        $defaultOrg->setUuid('default-uuid-456');
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setUsers(['admin']);
        
        // First call: Populate cache.
        $this->organisationMapper
            ->expects($this->exactly(2)) // Once for initial, once after expiration
            ->method('findDefault')
            ->willReturn($defaultOrg);
        
        // First call.
        $this->organisationService->ensureDefaultOrganisation();
        
        // Simulate cache expiration by manipulating timestamp using reflection.
        $reflection = new \ReflectionClass(OrganisationService::class);
        $timestampProperty = $reflection->getProperty('defaultOrganisationCacheTimestamp');
        $timestampProperty->setAccessible(true);
        $timestampProperty->setValue(time() - 1000); // Expired (older than 900 seconds)

        // Act: Second call should fetch fresh data due to expiration.
        $expiredResult = $this->organisationService->ensureDefaultOrganisation();

        // Assert: Fresh data is fetched.
        $this->assertInstanceOf(Organisation::class, $expiredResult);
        $this->assertEquals('default-uuid-456', $expiredResult->getUuid());
    }

    /**
     * Test cache sharing across multiple service instances
     *
     * @return void
     */
    public function testDefaultOrganisationCacheSharedAcrossInstances(): void
    {
        // Arrange: Create second service instance.
        $organisationMapper2 = $this->createMock(OrganisationMapper::class);
        $userSession2 = $this->createMock(IUserSession::class);
        $session2 = $this->createMock(ISession::class);
        $config2 = $this->createMock(IConfig::class);
        $groupManager2 = $this->createMock(IGroupManager::class);
        $logger2 = $this->createMock(LoggerInterface::class);
        
        $organisationService2 = new OrganisationService(
            $organisationMapper2,
            $userSession2,
            $session2,
            $config2,
            $groupManager2,
            $logger2
        );
        
        // Mock admin group for both instances.
        $adminGroup = $this->createMock(IGroup::class);
        $adminUser = $this->createMock(IUser::class);
        $adminUser->method('getUID')->willReturn('admin');
        $adminGroup->method('getUsers')->willReturn([$adminUser]);
        $this->groupManager->method('get')->with('admin')->willReturn($adminGroup);
        $groupManager2->method('get')->with('admin')->willReturn($adminGroup);
        
        // Create default organisation.
        $defaultOrg = new Organisation();
        $defaultOrg->setUuid('shared-cache-uuid');
        $defaultOrg->setName('Shared Cache Organisation');
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setUsers(['admin']);
        
        // First instance: Should fetch from database.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findDefault')
            ->willReturn($defaultOrg);
        
        // Second instance: Should NOT fetch from database (cache hit).
        $organisationMapper2
            ->expects($this->never())
            ->method('findDefault');

        // Act: First instance populates cache.
        $firstResult = $this->organisationService->ensureDefaultOrganisation();
        
        // Second instance uses shared cache.
        $secondResult = $organisationService2->ensureDefaultOrganisation();

        // Assert: Both instances return the same data.
        $this->assertEquals($firstResult->getUuid(), $secondResult->getUuid());
        $this->assertEquals('Shared Cache Organisation', $firstResult->getName());
        $this->assertEquals('Shared Cache Organisation', $secondResult->getName());
    }

    /**
     * Test cache invalidation when default organisation is modified
     *
     * @return void
     */
    public function testDefaultOrganisationCacheInvalidationOnModification(): void
    {
        // Arrange: Mock admin group.
        $adminGroup = $this->createMock(IGroup::class);
        $adminUser = $this->createMock(IUser::class);
        $adminUser->method('getUID')->willReturn('admin');
        $adminGroup->method('getUsers')->willReturn([$adminUser]);
        $this->groupManager->method('get')->with('admin')->willReturn($adminGroup);
        
        // Create default organisation.
        $defaultOrg = new Organisation();
        $defaultOrg->setUuid('modified-default-uuid');
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setUsers(['admin']);
        
        // First call: Populate cache.
        $this->organisationMapper
            ->expects($this->exactly(2)) // Once for cache, once after invalidation
            ->method('findDefault')
            ->willReturn($defaultOrg);
        
        // Update method should be called when admin users are added.
        $this->organisationMapper
            ->method('update')
            ->willReturn($defaultOrg);
        
        // First call: Should cache the result.
        $this->organisationService->ensureDefaultOrganisation();
        
        // Act: Clear cache explicitly (simulating modification).
        $this->organisationService->clearDefaultOrganisationCache();
        
        // Second call: Should fetch fresh data after cache clear.
        $freshResult = $this->organisationService->ensureDefaultOrganisation();

        // Assert: Fresh data is fetched after cache invalidation.
        $this->assertInstanceOf(Organisation::class, $freshResult);
        $this->assertEquals('modified-default-uuid', $freshResult->getUuid());
    }

    /**
     * Test cache behavior when creating default organisation for first time
     *
     * @return void
     */
    public function testDefaultOrganisationCacheOnFirstTimeCreation(): void
    {
        // Arrange: Mock admin group.
        $adminGroup = $this->createMock(IGroup::class);
        $adminUser = $this->createMock(IUser::class);
        $adminUser->method('getUID')->willReturn('admin');
        $adminGroup->method('getUsers')->willReturn([$adminUser]);
        $this->groupManager->method('get')->with('admin')->willReturn($adminGroup);
        
        // Mock: No default organisation exists initially.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findDefault')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('No default organisation'));
        
        // Mock: Create new default organisation.
        $newDefaultOrg = new Organisation();
        $newDefaultOrg->setUuid('new-default-uuid');
        $newDefaultOrg->setName('New Default Organisation');
        $newDefaultOrg->setIsDefault(true);
        $newDefaultOrg->setUsers(['admin']);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('createDefault')
            ->willReturn($newDefaultOrg);
        
        $this->organisationMapper
            ->expects($this->once())
            ->method('update')
            ->willReturn($newDefaultOrg);

        // Act: Ensure default organisation (should create and cache).
        $result = $this->organisationService->ensureDefaultOrganisation();

        // Assert: New default organisation is created and cached.
        $this->assertInstanceOf(Organisation::class, $result);
        $this->assertEquals('new-default-uuid', $result->getUuid());
        $this->assertEquals('New Default Organisation', $result->getName());
        $this->assertTrue($result->getIsDefault());
    }

    /**
     * Test performance improvement by avoiding multiple database calls
     *
     * @return void
     */
    public function testDefaultOrganisationPerformanceOptimization(): void
    {
        // Arrange: Mock admin group.
        $adminGroup = $this->createMock(IGroup::class);
        $adminUser = $this->createMock(IUser::class);
        $adminUser->method('getUID')->willReturn('admin');
        $adminGroup->method('getUsers')->willReturn([$adminUser]);
        $this->groupManager->method('get')->with('admin')->willReturn($adminGroup);
        
        // Create default organisation.
        $defaultOrg = new Organisation();
        $defaultOrg->setUuid('performance-test-uuid');
        $defaultOrg->setName('Performance Test Org');
        $defaultOrg->setIsDefault(true);
        $defaultOrg->setUsers(['admin']);
        
        // Should only be called once despite multiple ensureDefaultOrganisation calls.
        $this->organisationMapper
            ->expects($this->once())
            ->method('findDefault')
            ->willReturn($defaultOrg);

        // Act: Multiple calls to ensureDefaultOrganisation.
        $result1 = $this->organisationService->ensureDefaultOrganisation();
        $result2 = $this->organisationService->ensureDefaultOrganisation();
        $result3 = $this->organisationService->ensureDefaultOrganisation();
        $result4 = $this->organisationService->ensureDefaultOrganisation();

        // Assert: All calls return the same data, but only one database call was made.
        $this->assertEquals($result1->getUuid(), $result2->getUuid());
        $this->assertEquals($result2->getUuid(), $result3->getUuid());
        $this->assertEquals($result3->getUuid(), $result4->getUuid());
        $this->assertEquals('performance-test-uuid', $result1->getUuid());
        $this->assertEquals('Performance Test Org', $result1->getName());
    }
}
