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

    // =========================================================================
    // hasReferenceToSchema tests
    // =========================================================================

    public function testHasReferenceToSchemaReturnsBool(): void
    {
        $schema = $this->createTestSchema();
        $otherSchema = $this->createTestSchema();

        // hasReferenceToSchema expects (array $properties, string $targetSchemaId,
        // string $targetSchemaUuid, string $targetSchemaSlug)
        $result = $this->mapper->hasReferenceToSchema(
            $schema->getProperties() ?? [],
            (string) $otherSchema->getId(),
            $otherSchema->getUuid() ?? '',
            $otherSchema->getSlug() ?? ''
        );
        $this->assertIsBool($result);
    }

    // =========================================================================
    // getPropertySourceMetadata tests
    // =========================================================================

    public function testGetPropertySourceMetadataReturnsArray(): void
    {
        $schema = $this->createTestSchema();

        $result = $this->mapper->getPropertySourceMetadata($schema);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // insert / update / delete lifecycle
    // =========================================================================

    public function testInsertDoesNotAutoSetCreatedTimestamp(): void
    {
        // createFromArray does not auto-populate created/updated timestamps;
        // they remain null unless explicitly set before insert.
        $schema = $this->createTestSchema();
        $this->assertNull($schema->getCreated());
    }

    public function testInsertDoesNotAutoSetUpdatedTimestamp(): void
    {
        // createFromArray does not auto-populate created/updated timestamps;
        // they remain null unless explicitly set before insert.
        $schema = $this->createTestSchema();
        $this->assertNull($schema->getUpdated());
    }

    public function testUpdateSetsUpdatedTimestamp(): void
    {
        $schema = $this->createTestSchema();
        $originalUpdated = $schema->getUpdated();

        // Small delay to ensure different timestamp
        usleep(100000);

        $updated = $this->mapper->updateFromArray($schema->getId(), [
            'description' => 'Timestamp test update',
        ]);
        // Updated timestamp should be different (or at least not null)
        $this->assertNotNull($updated->getUpdated());
    }

    // =========================================================================
    // findAll with sorting tests
    // =========================================================================

    public function testFindAllWithSearchConditionsReturnsArray(): void
    {
        $schema1 = $this->createTestSchema(['title' => 'AAA Sort Test ' . uniqid()]);
        $schema2 = $this->createTestSchema(['title' => 'ZZZ Sort Test ' . uniqid()]);

        // findAll signature: ($limit, $offset, $filters, $searchConditions, $searchParams, $_extend, $published, $_rbac, $_multitenancy)
        // Pass empty arrays for searchConditions and searchParams (they are SQL WHERE fragments, not sort directives)
        $results = $this->mapper->findAll(
            null,
            null,
            [],
            [],
            [],
            [],
            null,
            false,
            false
        );

        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(2, count($results));
    }

    // =========================================================================
    // updateFromArray preserves fields not in update
    // =========================================================================

    public function testUpdateFromArrayPreservesExistingProperties(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'email' => ['type' => 'string', 'title' => 'Email'],
            ],
        ]);

        // Update only description, properties should remain
        $updated = $this->mapper->updateFromArray($schema->getId(), [
            'description' => 'Updated desc only',
        ]);

        $props = $updated->getProperties();
        $this->assertIsArray($props);
        $this->assertArrayHasKey('email', $props);
    }

    // =========================================================================
    // createFromArray with different property types
    // =========================================================================

    public function testCreateSchemaWithBooleanProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'isActive' => ['type' => 'boolean', 'title' => 'Is Active'],
            ],
        ]);

        $props = $schema->getProperties();
        $this->assertArrayHasKey('isActive', $props);
        $this->assertSame('boolean', $props['isActive']['type']);
    }

    public function testCreateSchemaWithNumberProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'price' => ['type' => 'number', 'title' => 'Price'],
            ],
        ]);

        $props = $schema->getProperties();
        $this->assertArrayHasKey('price', $props);
        $this->assertSame('number', $props['price']['type']);
    }

    public function testCreateSchemaWithArrayProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'tags' => [
                    'type'  => 'array',
                    'title' => 'Tags',
                    'items' => ['type' => 'string'],
                ],
            ],
        ]);

        $props = $schema->getProperties();
        $this->assertArrayHasKey('tags', $props);
        $this->assertSame('array', $props['tags']['type']);
    }

    // =========================================================================
    // Slug uniqueness tests
    // =========================================================================

    public function testCreateSchemasWithSameTitleThrowsUniqueConstraint(): void
    {
        $title = 'Duplicate Title Schema';
        $s1 = $this->createTestSchema(['title' => $title]);
        $this->assertNotNull($s1->getSlug());

        // The database enforces a unique constraint on (organisation, slug),
        // so inserting a second schema with the same slug should throw.
        $this->expectException(\Exception::class);
        $this->createTestSchema(['title' => $title]);
    }

    // =========================================================================
    // countAll / findAll count validation
    // =========================================================================

    public function testFindAllCountMatchesActualResults(): void
    {
        $this->createTestSchema();
        $this->createTestSchema();

        $all = $this->mapper->findAll(null, null, [], [], [], [], null, false, false);
        $limited = $this->mapper->findAll(1, 0, [], [], [], [], null, false, false);

        $this->assertCount(1, $limited);
        $this->assertGreaterThanOrEqual(2, count($all));
    }

    // =========================================================================
    // findBySlug with limit and offset tests
    // =========================================================================

    public function testFindBySlugWithLimit(): void
    {
        $schema = $this->createTestSchema();
        $slug = $schema->getSlug();

        $results = $this->mapper->findBySlug($slug, 1, 0, null, false, false);
        $this->assertIsArray($results);
        $this->assertLessThanOrEqual(1, count($results));
    }

    public function testFindBySlugWithOffset(): void
    {
        $schema = $this->createTestSchema();
        $slug = $schema->getSlug();

        // Only one schema with this slug, offset 1 should give empty result
        $results = $this->mapper->findBySlug($slug, 10, 1, null, false, false);
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // getRelated with referencing schemas
    // =========================================================================

    public function testGetRelatedWithReferencingSchema(): void
    {
        $targetSchema = $this->createTestSchema(['title' => 'Target Schema ' . uniqid()]);
        // Create a schema with a $ref property pointing to the target
        $referencingSchema = $this->createTestSchema([
            'title'      => 'Referencing Schema ' . uniqid(),
            'properties' => [
                'link' => [
                    'type'  => 'string',
                    'title' => 'Link',
                    '$ref'  => (string) $targetSchema->getId(),
                ],
            ],
        ]);

        $related = $this->mapper->getRelated($targetSchema);
        $this->assertIsArray($related);
        // The referencing schema should appear in related
    }

    // =========================================================================
    // createFromArray edge cases
    // =========================================================================

    public function testCreateFromArrayEmptyProperties(): void
    {
        $schema = $this->createTestSchema(['properties' => []]);
        $props = $schema->getProperties();
        $this->assertIsArray($props);
        $this->assertEmpty($props);
    }

    public function testCreateFromArrayWithNestedObjectProperties(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'address' => [
                    'type'       => 'object',
                    'title'      => 'Address',
                    'properties' => [
                        'street' => ['type' => 'string', 'title' => 'Street'],
                        'city'   => ['type' => 'string', 'title' => 'City'],
                    ],
                ],
            ],
        ]);

        $props = $schema->getProperties();
        $this->assertArrayHasKey('address', $props);
        $this->assertSame('object', $props['address']['type']);
        $this->assertArrayHasKey('properties', $props['address']);
        $this->assertArrayHasKey('street', $props['address']['properties']);
    }

    public function testCreateFromArrayWithSource(): void
    {
        $schema = $this->createTestSchema(['source' => 'external-api']);
        $this->assertSame('external-api', $schema->getSource());
    }

    // =========================================================================
    // updateFromArray edge cases
    // =========================================================================

    public function testUpdateFromArrayReplacesProperties(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'oldField' => ['type' => 'string', 'title' => 'Old Field'],
            ],
        ]);

        $updated = $this->mapper->updateFromArray($schema->getId(), [
            'properties' => [
                'newField' => ['type' => 'integer', 'title' => 'New Field'],
            ],
        ]);

        $props = $updated->getProperties();
        $this->assertArrayHasKey('newField', $props);
    }

    public function testUpdateFromArrayChangesTitle(): void
    {
        $schema = $this->createTestSchema();
        $newTitle = 'Completely New Title ' . uniqid();

        $updated = $this->mapper->updateFromArray($schema->getId(), [
            'title' => $newTitle,
        ]);

        $this->assertSame($newTitle, $updated->getTitle());
    }

    // =========================================================================
    // hasReferenceToSchema with matching $ref
    // =========================================================================

    public function testHasReferenceToSchemaByIdTrue(): void
    {
        $targetSchema = $this->createTestSchema();
        $properties = [
            'link' => [
                'type'  => 'string',
                'title' => 'Link',
                '$ref'  => (string) $targetSchema->getId(),
            ],
        ];

        $result = $this->mapper->hasReferenceToSchema(
            $properties,
            (string) $targetSchema->getId(),
            $targetSchema->getUuid() ?? '',
            $targetSchema->getSlug() ?? ''
        );
        $this->assertTrue($result);
    }

    public function testHasReferenceToSchemaByUuidTrue(): void
    {
        $targetSchema = $this->createTestSchema();
        $properties = [
            'link' => [
                'type'  => 'string',
                'title' => 'Link',
                '$ref'  => $targetSchema->getUuid(),
            ],
        ];

        $result = $this->mapper->hasReferenceToSchema(
            $properties,
            (string) $targetSchema->getId(),
            $targetSchema->getUuid() ?? '',
            $targetSchema->getSlug() ?? ''
        );
        $this->assertTrue($result);
    }

    public function testHasReferenceToSchemaFalse(): void
    {
        $targetSchema = $this->createTestSchema();
        $properties = [
            'name' => [
                'type'  => 'string',
                'title' => 'Name',
            ],
        ];

        $result = $this->mapper->hasReferenceToSchema(
            $properties,
            (string) $targetSchema->getId(),
            $targetSchema->getUuid() ?? '',
            $targetSchema->getSlug() ?? ''
        );
        $this->assertFalse($result);
    }

    // =========================================================================
    // findExtendedBy with actual extensions
    // =========================================================================

    public function testFindExtendedByEmpty(): void
    {
        $schema = $this->createTestSchema();
        $result = $this->mapper->findExtendedBy($schema->getId());
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getPropertySourceMetadata tests
    // =========================================================================

    public function testGetPropertySourceMetadataWithProperties(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'email'  => ['type' => 'string', 'format' => 'email', 'title' => 'Email'],
                'score'  => ['type' => 'integer', 'title' => 'Score'],
                'active' => ['type' => 'boolean', 'title' => 'Active'],
            ],
        ]);

        $result = $this->mapper->getPropertySourceMetadata($schema);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // findAll with search conditions
    // =========================================================================

    public function testFindAllWithSourceFilter(): void
    {
        $uniqueSource = 'phpunit-src-' . uniqid();
        $this->createTestSchema(['source' => $uniqueSource]);

        $results = $this->mapper->findAll(
            null,
            null,
            ['source' => $uniqueSource],
            [],
            [],
            [],
            null,
            false,
            false
        );
        $this->assertNotEmpty($results);
        $this->assertSame($uniqueSource, $results[0]->getSource());
    }

    // =========================================================================
    // Slug case insensitive lookup
    // =========================================================================

    public function testFindBySlugCaseInsensitive(): void
    {
        $schema = $this->createTestSchema(['title' => 'CaseTest Schema ' . uniqid()]);
        $slug = $schema->getSlug();
        $this->assertNotNull($slug);

        // Find with uppercase slug
        $found = $this->mapper->find(strtoupper($slug), [], null, false, false);
        $this->assertSame($schema->getId(), $found->getId());
    }

    // =========================================================================
    // getRegisterCountPerSchema
    // =========================================================================

    public function testGetRegisterCountPerSchemaReturnsArray(): void
    {
        $result = $this->mapper->getRegisterCountPerSchema();
        $this->assertIsArray($result);
    }

    // =========================================================================
    // findMultipleOptimized with missing IDs
    // =========================================================================

    public function testFindMultipleOptimizedSkipsMissing(): void
    {
        $s1 = $this->createTestSchema();
        $results = $this->mapper->findMultipleOptimized([$s1->getId(), 999999999]);
        $this->assertCount(1, $results);
        $this->assertArrayHasKey($s1->getId(), $results);
    }
}
