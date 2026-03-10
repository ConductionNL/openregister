<?php

/**
 * Integration tests for RegisterMapper
 *
 * Tests CRUD operations, querying, and utility methods against a real database.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 */

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class RegisterMapperIntegrationTest extends TestCase
{
    private RegisterMapper $mapper;
    private SchemaMapper $schemaMapper;

    /** @var int[] IDs of registers created during tests, for tearDown cleanup */
    private array $createdRegisterIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegisterIds as $id) {
            try {
                $register = $this->mapper->find($id, _rbac: false, _multitenancy: false);
                // Use parent delete to bypass object-attached check
                $db = \OC::$server->get(\OCP\IDBConnection::class);
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up or does not exist
            }
        }

        parent::tearDown();
    }

    /**
     * Helper to create a test register via createFromArray
     */
    private function createTestRegister(array $overrides = []): Register
    {
        $data = array_merge([
            'title'       => 'PHPUnit Test Register ' . uniqid(),
            'description' => 'Created by integration test',
            'source'      => 'internal',
        ], $overrides);

        $register = $this->mapper->createFromArray($data);
        $this->createdRegisterIds[] = $register->getId();

        return $register;
    }

    // =========================================================================
    // findAll tests
    // =========================================================================

    public function testFindAllReturnsArray(): void
    {
        $results = $this->mapper->findAll(_rbac: false, _multitenancy: false);
        $this->assertIsArray($results);
    }

    public function testFindAllRespectsLimit(): void
    {
        $results = $this->mapper->findAll(2, 0, _rbac: false, _multitenancy: false);
        $this->assertLessThanOrEqual(2, count($results));
    }

    public function testFindAllRespectsOffset(): void
    {
        $all = $this->mapper->findAll(null, null, [], [], [], [], null, false, false);
        if (count($all) < 2) {
            $this->markTestSkipped('Need at least 2 registers for offset test');
        }

        $offset = $this->mapper->findAll(null, 1, [], [], [], [], null, false, false);
        $this->assertCount(count($all) - 1, $offset);
    }

    public function testFindAllWithFilter(): void
    {
        $register = $this->createTestRegister();

        $results = $this->mapper->findAll(
            null,
            null,
            ['source' => 'internal'],
            [],
            [],
            [],
            null,
            false,
            false
        );

        $this->assertNotEmpty($results);
        foreach ($results as $r) {
            $this->assertSame('internal', $r->getSource());
        }
    }

    public function testFindAllWithIsNullFilter(): void
    {
        $results = $this->mapper->findAll(
            null,
            null,
            ['deleted' => 'IS NULL'],
            [],
            [],
            [],
            null,
            false,
            false
        );

        $this->assertIsArray($results);
    }

    public function testFindAllWithIsNotNullFilter(): void
    {
        $results = $this->mapper->findAll(
            null,
            null,
            ['source' => 'IS NOT NULL'],
            [],
            [],
            [],
            null,
            false,
            false
        );

        $this->assertIsArray($results);
    }

    // =========================================================================
    // find tests
    // =========================================================================

    public function testFindById(): void
    {
        $register = $this->createTestRegister();
        $found = $this->mapper->find($register->getId(), _rbac: false, _multitenancy: false);

        $this->assertInstanceOf(Register::class, $found);
        $this->assertSame($register->getId(), $found->getId());
    }

    public function testFindByUuid(): void
    {
        $register = $this->createTestRegister();
        $found = $this->mapper->find($register->getUuid(), _rbac: false, _multitenancy: false);

        $this->assertSame($register->getId(), $found->getId());
    }

    public function testFindBySlug(): void
    {
        $register = $this->createTestRegister(['title' => 'SlugTest ' . uniqid()]);
        $slug = $register->getSlug();
        $this->assertNotNull($slug);

        $found = $this->mapper->find($slug, _rbac: false, _multitenancy: false);
        $this->assertSame($register->getId(), $found->getId());
    }

    public function testFindCachesResult(): void
    {
        $register = $this->createTestRegister();

        // First call populates cache
        $found1 = $this->mapper->find($register->getId(), _rbac: false, _multitenancy: false);
        // Second call should return cached version
        $found2 = $this->mapper->find($register->getId(), _rbac: false, _multitenancy: false);

        $this->assertSame($found1->getId(), $found2->getId());
    }

    public function testFindNonExistentThrowsException(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->mapper->find(999999999, _rbac: false, _multitenancy: false);
    }

    // =========================================================================
    // findMultiple tests
    // =========================================================================

    public function testFindMultiple(): void
    {
        $r1 = $this->createTestRegister();
        $r2 = $this->createTestRegister();

        $results = $this->mapper->findMultiple(
            [$r1->getId(), $r2->getId()],
            null,
            false,
            false
        );

        $this->assertCount(2, $results);
    }

    public function testFindMultipleSkipsMissing(): void
    {
        $r1 = $this->createTestRegister();
        $results = $this->mapper->findMultiple(
            [$r1->getId(), 999999999],
            null,
            false,
            false
        );

        $this->assertCount(1, $results);
    }

    // =========================================================================
    // findMultipleOptimized tests
    // =========================================================================

    public function testFindMultipleOptimizedEmpty(): void
    {
        $results = $this->mapper->findMultipleOptimized([]);
        $this->assertSame([], $results);
    }

    public function testFindMultipleOptimized(): void
    {
        $r1 = $this->createTestRegister();
        $r2 = $this->createTestRegister();

        $results = $this->mapper->findMultipleOptimized([$r1->getId(), $r2->getId()]);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey($r1->getId(), $results);
        $this->assertArrayHasKey($r2->getId(), $results);
    }

    // =========================================================================
    // createFromArray tests
    // =========================================================================

    public function testCreateFromArraySetsUuid(): void
    {
        $register = $this->createTestRegister();

        $this->assertNotNull($register->getUuid());
        $this->assertNotEmpty($register->getUuid());
    }

    public function testCreateFromArraySetsSlug(): void
    {
        $register = $this->createTestRegister(['title' => 'My Test Register']);
        $this->assertNotNull($register->getSlug());
        $this->assertStringContainsString('my-test-register', $register->getSlug());
    }

    public function testCreateFromArraySetsVersion(): void
    {
        $register = $this->createTestRegister();
        $this->assertNotNull($register->getVersion());
        $this->assertSame('0.0.1', $register->getVersion());
    }

    public function testCreateFromArraySetsSourceDefault(): void
    {
        $data = [
            'title'       => 'Source Test ' . uniqid(),
            'description' => 'test',
        ];
        $register = $this->mapper->createFromArray($data);
        $this->createdRegisterIds[] = $register->getId();

        $this->assertSame('internal', $register->getSource());
    }

    // =========================================================================
    // updateFromArray tests
    // =========================================================================

    public function testUpdateFromArray(): void
    {
        $register = $this->createTestRegister();

        $updated = $this->mapper->updateFromArray($register->getId(), [
            'title'       => 'Updated Title ' . uniqid(),
            'description' => 'Updated description',
        ]);

        $this->assertStringContainsString('Updated', $updated->getTitle());
        $this->assertSame('Updated description', $updated->getDescription());
    }

    public function testUpdateFromArrayIncrementsVersion(): void
    {
        $register = $this->createTestRegister();
        $originalVersion = $register->getVersion();

        $updated = $this->mapper->updateFromArray($register->getId(), [
            'description' => 'Version bump test',
        ]);

        $this->assertNotSame($originalVersion, $updated->getVersion());
    }

    // =========================================================================
    // delete tests
    // =========================================================================

    public function testDeleteSucceedsWithNoAttachedObjects(): void
    {
        $register = $this->createTestRegister();
        $id = $register->getId();

        $result = $this->mapper->delete($register);
        $this->assertInstanceOf(Register::class, $result);

        // Remove from cleanup list since already deleted
        $this->createdRegisterIds = array_filter(
            $this->createdRegisterIds,
            fn($rid) => $rid !== $id
        );
    }

    // =========================================================================
    // getIdToSlugMap / getSlugToIdMap tests
    // =========================================================================

    public function testGetIdToSlugMap(): void
    {
        $register = $this->createTestRegister();

        $map = $this->mapper->getIdToSlugMap();
        $this->assertIsArray($map);
        $this->assertArrayHasKey($register->getId(), $map);
        $this->assertSame($register->getSlug(), $map[$register->getId()]);
    }

    public function testGetSlugToIdMap(): void
    {
        $register = $this->createTestRegister();

        $map = $this->mapper->getSlugToIdMap();
        $this->assertIsArray($map);
        $this->assertArrayHasKey($register->getSlug(), $map);
    }

    // =========================================================================
    // getSchemasByRegisterId tests
    // =========================================================================

    public function testGetSchemasByRegisterIdEmptySchemas(): void
    {
        $register = $this->createTestRegister();

        $schemas = $this->mapper->getSchemasByRegisterId(
            $register->getId(),
            null,
            false,
            false
        );

        $this->assertIsArray($schemas);
        $this->assertEmpty($schemas);
    }
}
