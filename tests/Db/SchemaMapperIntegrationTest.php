<?php

/**
 * Integration tests for SchemaMapper
 *
 * Tests CRUD operations, querying, and utility methods against a real database.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 */

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class SchemaMapperIntegrationTest extends TestCase
{
    private SchemaMapper $mapper;

    /** @var int[] IDs of schemas created during tests, for tearDown cleanup */
    private array $createdSchemaIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = \OC::$server->get(SchemaMapper::class);
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ($this->createdSchemaIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up
            }
        }

        parent::tearDown();
    }

    /**
     * Helper to create a test schema via createFromArray
     */
    private function createTestSchema(array $overrides = []): Schema
    {
        $data = array_merge([
            'title'       => 'PHPUnit Test Schema ' . uniqid(),
            'description' => 'Created by integration test',
            'properties'  => [
                'name' => [
                    'type'  => 'string',
                    'title' => 'Name',
                ],
                'age' => [
                    'type'  => 'integer',
                    'title' => 'Age',
                ],
            ],
        ], $overrides);

        $schema = $this->mapper->createFromArray($data);
        $this->createdSchemaIds[] = $schema->getId();

        return $schema;
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
        $results = $this->mapper->findAll(2, 0, [], [], [], [], null, false, false);
        $this->assertLessThanOrEqual(2, count($results));
    }

    public function testFindAllRespectsOffset(): void
    {
        $all = $this->mapper->findAll(null, null, [], [], [], [], null, false, false);
        if (count($all) < 2) {
            $this->markTestSkipped('Need at least 2 schemas for offset test');
        }

        $offset = $this->mapper->findAll(null, 1, [], [], [], [], null, false, false);
        $this->assertCount(count($all) - 1, $offset);
    }

    public function testFindAllWithFilter(): void
    {
        $schema = $this->createTestSchema(['source' => 'internal']);

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

    // =========================================================================
    // find tests
    // =========================================================================

    public function testFindById(): void
    {
        $schema = $this->createTestSchema();
        $found = $this->mapper->find($schema->getId(), [], null, false, false);

        $this->assertInstanceOf(Schema::class, $found);
        $this->assertSame($schema->getId(), $found->getId());
    }

    public function testFindByUuid(): void
    {
        $schema = $this->createTestSchema();
        $found = $this->mapper->find($schema->getUuid(), [], null, false, false);

        $this->assertSame($schema->getId(), $found->getId());
    }

    public function testFindBySlug(): void
    {
        $schema = $this->createTestSchema(['title' => 'FindSlugTest ' . uniqid()]);
        $slug = $schema->getSlug();
        $this->assertNotNull($slug);

        $found = $this->mapper->find($slug, [], null, false, false);
        $this->assertSame($schema->getId(), $found->getId());
    }

    public function testFindNonExistentThrowsException(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->mapper->find(999999999, [], null, false, false);
    }

    // =========================================================================
    // findBySlug (the dedicated method) tests
    // =========================================================================

    public function testFindBySlugMethod(): void
    {
        $schema = $this->createTestSchema();
        $slug = $schema->getSlug();

        $results = $this->mapper->findBySlug($slug, 10, 0, null, false, false);
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertSame($slug, $results[0]->getSlug());
    }

    public function testFindBySlugNoResults(): void
    {
        $results = $this->mapper->findBySlug('nonexistent-slug-' . uniqid(), 10, 0, null, false, false);
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // findMultiple tests
    // =========================================================================

    public function testFindMultiple(): void
    {
        $s1 = $this->createTestSchema();
        $s2 = $this->createTestSchema();

        $results = $this->mapper->findMultiple(
            [$s1->getId(), $s2->getId()],
            null,
            false,
            false
        );

        $this->assertCount(2, $results);
    }

    public function testFindMultipleSkipsMissing(): void
    {
        $s1 = $this->createTestSchema();
        $results = $this->mapper->findMultiple(
            [$s1->getId(), 999999999],
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
        $s1 = $this->createTestSchema();
        $s2 = $this->createTestSchema();

        $results = $this->mapper->findMultipleOptimized([$s1->getId(), $s2->getId()]);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey($s1->getId(), $results);
    }

    // =========================================================================
    // createFromArray tests
    // =========================================================================

    public function testCreateFromArraySetsUuid(): void
    {
        $schema = $this->createTestSchema();

        $this->assertNotNull($schema->getUuid());
        $this->assertNotEmpty($schema->getUuid());
    }

    public function testCreateFromArraySetsSlug(): void
    {
        $schema = $this->createTestSchema(['title' => 'My Test Schema']);
        $this->assertNotNull($schema->getSlug());
        $this->assertStringContainsString('my-test-schema', $schema->getSlug());
    }

    public function testCreateFromArraySetsVersion(): void
    {
        $schema = $this->createTestSchema();
        $this->assertNotNull($schema->getVersion());
    }

    public function testCreateFromArrayPreservesProperties(): void
    {
        $props = [
            'email' => ['type' => 'string', 'format' => 'email', 'title' => 'Email'],
            'count' => ['type' => 'integer', 'title' => 'Count'],
        ];

        $schema = $this->createTestSchema(['properties' => $props]);
        $storedProps = $schema->getProperties();

        $this->assertIsArray($storedProps);
        $this->assertArrayHasKey('email', $storedProps);
        $this->assertArrayHasKey('count', $storedProps);
    }

    // =========================================================================
    // updateFromArray tests
    // =========================================================================

    public function testUpdateFromArray(): void
    {
        $schema = $this->createTestSchema();

        $updated = $this->mapper->updateFromArray($schema->getId(), [
            'title'       => 'Updated Schema Title ' . uniqid(),
            'description' => 'Updated schema description',
        ]);

        $this->assertStringContainsString('Updated', $updated->getTitle());
        $this->assertSame('Updated schema description', $updated->getDescription());
    }

    public function testUpdateFromArrayIncrementsVersion(): void
    {
        $schema = $this->createTestSchema();
        $originalVersion = $schema->getVersion();

        $updated = $this->mapper->updateFromArray($schema->getId(), [
            'description' => 'Version bump test',
        ]);

        $this->assertNotSame($originalVersion, $updated->getVersion());
    }

    // =========================================================================
    // delete tests
    // =========================================================================

    public function testDeleteSucceeds(): void
    {
        $schema = $this->createTestSchema();
        $id = $schema->getId();

        $result = $this->mapper->delete($schema);
        $this->assertInstanceOf(Schema::class, $result);

        // Remove from cleanup list since already deleted
        $this->createdSchemaIds = array_filter(
            $this->createdSchemaIds,
            fn($sid) => $sid !== $id
        );
    }

    // =========================================================================
    // getIdToSlugMap / getSlugToIdMap tests
    // =========================================================================

    public function testGetIdToSlugMap(): void
    {
        $schema = $this->createTestSchema();

        $map = $this->mapper->getIdToSlugMap();
        $this->assertIsArray($map);
        $this->assertArrayHasKey($schema->getId(), $map);
        $this->assertSame($schema->getSlug(), $map[$schema->getId()]);
    }

    public function testGetSlugToIdMap(): void
    {
        $schema = $this->createTestSchema();

        $map = $this->mapper->getSlugToIdMap();
        $this->assertIsArray($map);
        $this->assertArrayHasKey($schema->getSlug(), $map);
    }

    // =========================================================================
    // getRegisterCountPerSchema tests
    // =========================================================================

    public function testGetRegisterCountPerSchema(): void
    {
        $result = $this->mapper->getRegisterCountPerSchema();
        $this->assertIsArray($result);
    }

    // =========================================================================
    // getRelated tests
    // =========================================================================

    public function testGetRelatedReturnsArray(): void
    {
        $schema = $this->createTestSchema();

        $related = $this->mapper->getRelated($schema);
        $this->assertIsArray($related);
    }

    // =========================================================================
    // findExtendedBy tests
    // =========================================================================

    public function testFindExtendedByReturnsArray(): void
    {
        $schema = $this->createTestSchema();

        $result = $this->mapper->findExtendedBy($schema->getId());
        $this->assertIsArray($result);
    }

    public function testFindAllExtendedByReturnsArray(): void
    {
        $result = $this->mapper->findAllExtendedBy();
        $this->assertIsArray($result);
    }
}
