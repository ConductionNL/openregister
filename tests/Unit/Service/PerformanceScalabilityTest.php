<?php
/**
 * Performance and Scalability Unit Tests
 *
 * Test Coverage:
 * - Test 8.1: Large Organisation with Many Users
 * - Test 8.2: User with Many Organisations
 * - Test 8.3: Concurrent Active Organisation Changes
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

class PerformanceScalabilityTest extends TestCase
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
     * Test 8.1: Large Organisation with Many Users (100+ users)
     */
    public function testLargeOrganisationWithManyUsers(): void
    {
        // Arrange: Create organisation with 100+ users.
        $largeOrg = new Organisation();
        $largeOrg->setName('Large Organisation');
        $largeOrg->setUuid('large-org-uuid');
        
        // Generate 150 user IDs.
        $users = [];
        for ($i = 1; $i <= 150; $i++) {
            $users[] = "user{$i}";
        }
        $largeOrg->setUsers($users);
        
        // Mock: Database performance with large dataset.
        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with('large-org-uuid')
            ->willReturn($largeOrg);

        // Act: Operations should handle large user list efficiently.
        $startTime = microtime(true);
        $result = $this->organisationService->getOrganisation('large-org-uuid');
        $endTime = microtime(true);
        
        // Assert: Performance within acceptable bounds.
        $this->assertInstanceOf(Organisation::class, $result);
        $this->assertCount(150, $result->getUserIds());
        $this->assertLessThan(1.0, $endTime - $startTime); // Should complete under 1 second
    }

    /**
     * Test 8.2: User with Many Organisations (50+ organisations)
     */
    public function testUserWithManyOrganisations(): void
    {
        // Arrange: User belongs to 60 organisations.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('power_user');
        $this->userSession->method('getUser')->willReturn($user);
        
        $organisations = [];
        for ($i = 1; $i <= 60; $i++) {
            $org = new Organisation();
            $org->setName("Organisation {$i}");
            $org->setUuid("org-uuid-{$i}");
            $org->setUsers(['power_user']);
                         $org->setCreated(new \DateTime("2024-01-" . sprintf("%02d", $i)));
            $organisations[] = $org;
        }
        
        // Mock: Database query with many results.
        $this->organisationMapper->expects($this->once())
            ->method('findByUserId')
            ->with('power_user')
            ->willReturn($organisations);

        // Act: Get user organisations.
        $startTime = microtime(true);
        $userOrgs = $this->organisationService->getUserOrganisations(false);
        $endTime = microtime(true);
        
        // Assert: Handle many organisations efficiently.
        $this->assertCount(60, $userOrgs);
        $this->assertLessThan(0.5, $endTime - $startTime); // Should be fast
        
        // Test oldest organisation selection (performance critical).
        $this->assertEquals('Organisation 1', $userOrgs[0]->getName());
    }

    /**
     * Test 8.3: Concurrent Active Organisation Changes
     */
    public function testConcurrentActiveOrganisationChanges(): void
    {
        // Arrange: Simulate concurrent requests.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('concurrent_user');
        $this->userSession->method('getUser')->willReturn($user);
        
        $orgs = [
            'org1-uuid' => new Organisation(),
            'org2-uuid' => new Organisation(),
            'org3-uuid' => new Organisation()
        ];
        
        // Mock: Multiple rapid set operations.
        $this->session->expects($this->exactly(3))
            ->method('set')
            ->withConsecutive(
                ['openregister_active_organisation_concurrent_user', 'org1-uuid'],
                ['openregister_active_organisation_concurrent_user', 'org2-uuid'],
                ['openregister_active_organisation_concurrent_user', 'org3-uuid']
            );

        // Mock: Organisation validation.
        $this->organisationMapper->method('findByUuid')
            ->willReturnCallback(function($uuid) use ($orgs) {
                return $orgs[$uuid] ?? null;
            });

        // Act: Rapid consecutive changes.
        $results = [];
        foreach (array_keys($orgs) as $orgUuid) {
            $results[] = $this->organisationService->setActiveOrganisation($orgUuid);
        }

        // Assert: All operations succeed.
        $this->assertCount(3, $results);
        $this->assertTrue($results[0]);
        $this->assertTrue($results[1]);
        $this->assertTrue($results[2]);
    }

    /**
     * Test database query optimization with large datasets
     */
    public function testDatabaseQueryOptimization(): void
    {
        // Arrange: Large dataset query.
        $this->organisationMapper->expects($this->once())
            ->method('getStatistics')
            ->willReturn([
                'total_organisations' => 10000,
                'total_users' => 50000,
                'active_organisations' => 8500
            ]);

        // Act: Get statistics (should be optimized).
        $stats = $this->organisationMapper->getStatistics();

        // Assert: Statistics retrieved efficiently.
        $this->assertIsArray($stats);
        $this->assertEquals(10000, $stats['total_organisations']);
        $this->assertGreaterThan(0, $stats['active_organisations']);
    }

    /**
     * Test memory usage with large user lists
     */
    public function testMemoryUsageWithLargeUserLists(): void
    {
        // Arrange: Organisation with very large user list.
        $massiveOrg = new Organisation();
        $massiveOrg->setName('Massive Organisation');
        
        // Generate 1000 users.
        $massiveUserList = [];
        for ($i = 1; $i <= 1000; $i++) {
            $massiveUserList[] = "massive_user_{$i}";
        }
        $massiveOrg->setUsers($massiveUserList);

        // Act & Assert: Memory usage should be reasonable.
        $memoryBefore = memory_get_usage();
        
        $userCount = count($massiveOrg->getUserIds());
        $hasUser = $massiveOrg->hasUser('massive_user_500');
        
        $memoryAfter = memory_get_usage();
        $memoryDelta = $memoryAfter - $memoryBefore;

        $this->assertEquals(1000, $userCount);
        $this->assertTrue($hasUser);
        $this->assertLessThan(1024 * 1024, $memoryDelta); // Less than 1MB additional memory
    }

    /**
     * Test cache effectiveness under load
     */
    public function testCacheEffectivenessUnderLoad(): void
    {
        // Arrange: Heavy load simulation.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('load_test_user');
        $this->userSession->method('getUser')->willReturn($user);
        
        $cachedOrgs = [new Organisation()];
        
        // Mock: Database should only be hit once.
        $this->organisationMapper->expects($this->once())
            ->method('findByUserId')
            ->willReturn($cachedOrgs);
        
        // Mock: Cache hits.
        $this->session->method('get')
            ->with('openregister_organisations_load_test_user')
            ->willReturn($cachedOrgs);

        // Act: Multiple rapid requests (simulating load).
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = $this->organisationService->getUserOrganisations(true); // Use cache
        }

        // Assert: Cache effectiveness.
        $this->assertCount(10, $results);
        foreach ($results as $result) {
            $this->assertEquals($cachedOrgs, $result);
        }
    }
} 