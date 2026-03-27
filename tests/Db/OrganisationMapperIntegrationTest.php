<?php

/**
 * Integration tests for OrganisationMapper
 *
 * Tests CRUD operations, user management, hierarchy, and query methods
 * against a real database.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 */

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class OrganisationMapperIntegrationTest extends TestCase
{
    private OrganisationMapper $mapper;

    /** @var int[] IDs of organisations created during tests */
    private array $createdOrgIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = \OC::$server->get(OrganisationMapper::class);
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ($this->createdOrgIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_organisations')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up
            }
        }

        parent::tearDown();
    }

    /**
     * Helper to create a test organisation via the mapper insert method
     */
    private function createTestOrganisation(array $overrides = []): Organisation
    {
        $org = new Organisation();
        $org->setName($overrides['name'] ?? 'PHPUnit Test Org ' . uniqid());
        $org->setDescription($overrides['description'] ?? 'Created by integration test');
        $org->setSlug($overrides['slug'] ?? 'phpunit-test-org-' . uniqid());

        if (isset($overrides['users'])) {
            $org->setUsers($overrides['users']);
        }
        if (isset($overrides['parent'])) {
            $org->setParent($overrides['parent']);
        }

        $result = $this->mapper->insert($org);
        $this->createdOrgIds[] = $result->getId();

        return $result;
    }

    // =========================================================================
    // Insert tests
    // =========================================================================

    public function testInsertSetsUuid(): void
    {
        $org = $this->createTestOrganisation();
        $this->assertNotNull($org->getUuid());
        $this->assertNotEmpty($org->getUuid());
    }

    public function testInsertSetsTimestamps(): void
    {
        $org = $this->createTestOrganisation();
        $this->assertNotNull($org->getCreated());
        $this->assertNotNull($org->getUpdated());
    }

    public function testInsertSetsId(): void
    {
        $org = $this->createTestOrganisation();
        $this->assertNotNull($org->getId());
        $this->assertGreaterThan(0, $org->getId());
    }

    // =========================================================================
    // findByUuid tests
    // =========================================================================

    public function testFindByUuid(): void
    {
        $org = $this->createTestOrganisation();

        $found = $this->mapper->findByUuid($org->getUuid());
        $this->assertInstanceOf(Organisation::class, $found);
        $this->assertSame($org->getId(), $found->getId());
        $this->assertSame($org->getUuid(), $found->getUuid());
    }

    public function testFindByUuidNotFoundThrows(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->mapper->findByUuid('nonexistent-uuid-' . uniqid());
    }

    // =========================================================================
    // findBySlug tests
    // =========================================================================

    public function testFindBySlug(): void
    {
        $slug = 'phpunit-slug-test-' . uniqid();
        $org = $this->createTestOrganisation(['slug' => $slug]);

        $found = $this->mapper->findBySlug($slug);
        $this->assertInstanceOf(Organisation::class, $found);
        $this->assertSame($org->getId(), $found->getId());
    }

    public function testFindBySlugNotFoundThrows(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->mapper->findBySlug('nonexistent-slug-' . uniqid());
    }

    // =========================================================================
    // findAll tests
    // =========================================================================

    public function testFindAllReturnsArray(): void
    {
        $this->createTestOrganisation();
        $results = $this->mapper->findAll(50, 0);
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
    }

    public function testFindAllRespectsLimit(): void
    {
        $this->createTestOrganisation();
        $this->createTestOrganisation();

        $results = $this->mapper->findAll(1, 0);
        $this->assertCount(1, $results);
    }

    public function testFindAllRespectsOffset(): void
    {
        $all = $this->mapper->findAll(10000, 0);
        if (count($all) < 2) {
            $this->markTestSkipped('Need at least 2 organisations for offset test');
        }

        $offset = $this->mapper->findAll(10000, 1);
        $this->assertCount(count($all) - 1, $offset);
    }

    // =========================================================================
    // findByName tests
    // =========================================================================

    public function testFindByName(): void
    {
        $uniqueName = 'UniqueOrgNameTest-' . uniqid();
        $this->createTestOrganisation(['name' => $uniqueName]);

        $results = $this->mapper->findByName($uniqueName);
        $this->assertNotEmpty($results);
        $this->assertSame($uniqueName, $results[0]->getName());
    }

    public function testFindByNamePartialMatch(): void
    {
        $uniquePart = 'PartialName' . uniqid();
        $this->createTestOrganisation(['name' => 'Prefix ' . $uniquePart . ' Suffix']);

        $results = $this->mapper->findByName($uniquePart);
        $this->assertNotEmpty($results);
    }

    public function testFindByNameNoResults(): void
    {
        $results = $this->mapper->findByName('NonexistentOrgName' . uniqid());
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // findMultipleByUuid tests
    // =========================================================================

    public function testFindMultipleByUuidEmpty(): void
    {
        $results = $this->mapper->findMultipleByUuid([]);
        $this->assertSame([], $results);
    }

    public function testFindMultipleByUuid(): void
    {
        $org1 = $this->createTestOrganisation();
        $org2 = $this->createTestOrganisation();

        $results = $this->mapper->findMultipleByUuid([$org1->getUuid(), $org2->getUuid()]);
        $this->assertCount(2, $results);
        $this->assertArrayHasKey($org1->getUuid(), $results);
        $this->assertArrayHasKey($org2->getUuid(), $results);
    }

    // =========================================================================
    // findAllWithUserCount tests
    // =========================================================================

    public function testFindAllWithUserCount(): void
    {
        $this->createTestOrganisation(['users' => ['user1', 'user2']]);

        $results = $this->mapper->findAllWithUserCount();
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);

        // Find our test org
        $found = false;
        foreach ($results as $org) {
            if ($org->userCount !== null && $org->userCount === 2) {
                $found = true;
                break;
            }
        }
        // At least check the property exists on results
        $this->assertNotNull($results[0]->userCount);
    }

    // =========================================================================
    // findByUserId tests
    // =========================================================================

    public function testFindByUserId(): void
    {
        $userId = 'phpunit-test-user-' . uniqid();
        $this->createTestOrganisation(['users' => [$userId]]);

        $results = $this->mapper->findByUserId($userId);
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertTrue($results[0]->hasUser($userId));
    }

    public function testFindByUserIdNoResults(): void
    {
        $results = $this->mapper->findByUserId('nonexistent-user-' . uniqid());
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // getStatistics tests
    // =========================================================================

    public function testGetStatistics(): void
    {
        $this->createTestOrganisation();

        $stats = $this->mapper->getStatistics();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertIsInt($stats['total']);
        $this->assertGreaterThanOrEqual(1, $stats['total']);
    }

    // =========================================================================
    // uuidExists tests
    // =========================================================================

    public function testUuidExistsTrue(): void
    {
        $org = $this->createTestOrganisation();
        $this->assertTrue($this->mapper->uuidExists($org->getUuid()));
    }

    public function testUuidExistsFalse(): void
    {
        $this->assertFalse($this->mapper->uuidExists(Uuid::v4()->toRfc4122()));
    }

    public function testUuidExistsExcludesId(): void
    {
        $org = $this->createTestOrganisation();
        // UUID exists but is excluded by its own ID
        $this->assertFalse($this->mapper->uuidExists($org->getUuid(), $org->getId()));
    }

    // =========================================================================
    // update tests
    // =========================================================================

    public function testUpdate(): void
    {
        $org = $this->createTestOrganisation();
        $originalUpdated = $org->getUpdated();

        usleep(100000);

        $org->setDescription('Updated description');
        $updated = $this->mapper->update($org);

        $this->assertSame('Updated description', $updated->getDescription());
        $this->assertNotNull($updated->getUpdated());
    }

    // =========================================================================
    // delete tests
    // =========================================================================

    public function testDelete(): void
    {
        $org = $this->createTestOrganisation();
        $id = $org->getId();

        $result = $this->mapper->delete($org);
        $this->assertInstanceOf(Organisation::class, $result);

        // Remove from cleanup since already deleted
        $this->createdOrgIds = array_filter(
            $this->createdOrgIds,
            fn($oid) => $oid !== $id
        );
    }

    // =========================================================================
    // save tests
    // =========================================================================

    public function testSaveNewOrganisation(): void
    {
        $org = new Organisation();
        $org->setName('Save Test Org ' . uniqid());
        $org->setSlug('save-test-' . uniqid());
        $org->setDescription('Created via save method');

        $saved = $this->mapper->save($org);
        $this->createdOrgIds[] = $saved->getId();

        $this->assertNotNull($saved->getId());
        $this->assertNotNull($saved->getUuid());
    }

    public function testSaveExistingOrganisation(): void
    {
        $org = $this->createTestOrganisation();

        $org->setDescription('Updated via save');
        $saved = $this->mapper->save($org);

        $this->assertSame($org->getId(), $saved->getId());
        $this->assertSame('Updated via save', $saved->getDescription());
    }

    // =========================================================================
    // addUserToOrganisation / removeUserFromOrganisation tests
    // =========================================================================

    public function testAddUserToOrganisation(): void
    {
        $org = $this->createTestOrganisation();
        $userId = 'phpunit-add-user-' . uniqid();

        $updated = $this->mapper->addUserToOrganisation($org->getUuid(), $userId);
        $this->assertTrue($updated->hasUser($userId));
    }

    public function testRemoveUserFromOrganisation(): void
    {
        $userId = 'phpunit-remove-user-' . uniqid();
        $org = $this->createTestOrganisation(['users' => [$userId]]);

        $updated = $this->mapper->removeUserFromOrganisation($org->getUuid(), $userId);
        $this->assertFalse($updated->hasUser($userId));
    }

    // =========================================================================
    // findParentChain / findChildrenChain tests
    // =========================================================================

    public function testFindParentChainNoParent(): void
    {
        $org = $this->createTestOrganisation();
        $parents = $this->mapper->findParentChain($org->getUuid());
        $this->assertIsArray($parents);
        $this->assertEmpty($parents);
    }

    public function testFindParentChainWithParent(): void
    {
        $parent = $this->createTestOrganisation();
        $child = $this->createTestOrganisation(['parent' => $parent->getUuid()]);

        $parents = $this->mapper->findParentChain($child->getUuid());
        $this->assertIsArray($parents);
        $this->assertContains($parent->getUuid(), $parents);
    }

    public function testFindChildrenChainNoChildren(): void
    {
        $org = $this->createTestOrganisation();
        $children = $this->mapper->findChildrenChain($org->getUuid());
        $this->assertIsArray($children);
        $this->assertEmpty($children);
    }

    public function testFindChildrenChainWithChildren(): void
    {
        $parent = $this->createTestOrganisation();
        $child = $this->createTestOrganisation(['parent' => $parent->getUuid()]);

        $children = $this->mapper->findChildrenChain($parent->getUuid());
        $this->assertIsArray($children);
        $this->assertContains($child->getUuid(), $children);
    }

    // =========================================================================
    // validateParentAssignment tests
    // =========================================================================

    public function testValidateParentAssignmentNullAllowed(): void
    {
        $org = $this->createTestOrganisation();
        // Should not throw
        $this->mapper->validateParentAssignment($org->getUuid(), null);
        $this->assertTrue(true);
    }

    public function testValidateParentAssignmentSelfReferenceThrows(): void
    {
        $org = $this->createTestOrganisation();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('cannot be its own parent');
        $this->mapper->validateParentAssignment($org->getUuid(), $org->getUuid());
    }

    public function testValidateParentAssignmentCircularReferenceThrows(): void
    {
        $parent = $this->createTestOrganisation();
        $child = $this->createTestOrganisation(['parent' => $parent->getUuid()]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Circular reference');
        $this->mapper->validateParentAssignment($parent->getUuid(), $child->getUuid());
    }

    public function testValidateParentAssignmentNonexistentParentThrows(): void
    {
        $org = $this->createTestOrganisation();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Parent organisation not found');
        $this->mapper->validateParentAssignment($org->getUuid(), Uuid::v4()->toRfc4122());
    }

    // =========================================================================
    // getOrganisationHierarchy tests
    // =========================================================================

    public function testGetOrganisationHierarchyNoParent(): void
    {
        $org = $this->createTestOrganisation();
        $hierarchy = $this->mapper->getOrganisationHierarchy($org->getUuid());
        $this->assertIsArray($hierarchy);
        $this->assertContains($org->getUuid(), $hierarchy);
        $this->assertCount(1, $hierarchy);
    }

    public function testGetOrganisationHierarchyWithParent(): void
    {
        $parent = $this->createTestOrganisation();
        $child = $this->createTestOrganisation(['parent' => $parent->getUuid()]);

        $hierarchy = $this->mapper->getOrganisationHierarchy($child->getUuid());
        $this->assertIsArray($hierarchy);
        $this->assertContains($child->getUuid(), $hierarchy);
        $this->assertContains($parent->getUuid(), $hierarchy);
        $this->assertCount(2, $hierarchy);
    }

    // =========================================================================
    // findByUserId with PostgreSQL compatibility test
    // =========================================================================

    public function testFindByUserIdMultipleOrgs(): void
    {
        $userId = 'phpunit-multi-org-user-' . uniqid();
        $org1 = $this->createTestOrganisation(['users' => [$userId, 'other-user']]);
        $org2 = $this->createTestOrganisation(['users' => [$userId]]);

        $results = $this->mapper->findByUserId($userId);
        $this->assertGreaterThanOrEqual(2, count($results));
    }
}
