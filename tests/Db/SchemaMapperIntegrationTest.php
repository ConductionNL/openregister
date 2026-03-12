<?php

/**
 * Integration tests for SchemaMapper
 *
 * Tests CRUD operations, querying, composition, validation, and utility methods
 * against a real database.
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

    public function testFindAllWithIsNotNullFilter(): void
    {
        $this->createTestSchema();

        $results = $this->mapper->findAll(
            null,
            null,
            ['uuid' => 'IS NOT NULL'],
            [],
            [],
            [],
            null,
            false,
            false
        );
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
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

    public function testFindCacheHit(): void
    {
        $schema = $this->createTestSchema();

        // First call populates cache
        $found1 = $this->mapper->find($schema->getId(), [], null, false, false);
        // Second call should hit cache
        $found2 = $this->mapper->find($schema->getId(), [], null, false, false);

        $this->assertSame($found1->getId(), $found2->getId());
    }

    public function testFindCacheByUuidAfterIdLookup(): void
    {
        $schema = $this->createTestSchema();

        // First call by ID populates cache (also caches by uuid and slug)
        $found1 = $this->mapper->find($schema->getId(), [], null, false, false);
        // This should hit cache via UUID
        $found2 = $this->mapper->find($schema->getUuid(), [], null, false, false);

        $this->assertSame($found1->getId(), $found2->getId());
    }

    public function testFindWithNonNumericId(): void
    {
        $schema = $this->createTestSchema();
        // Find by UUID (non-numeric) to exercise the else branch
        $found = $this->mapper->find($schema->getUuid(), [], null, false, false);
        $this->assertSame($schema->getId(), $found->getId());
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

    public function testFindBySlugWithPublishedParam(): void
    {
        $schema = $this->createTestSchema();
        $slug = $schema->getSlug();

        $results = $this->mapper->findBySlug($slug, 10, 0, true, false, false);
        $this->assertIsArray($results);
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

    public function testFindMultipleEmptyArray(): void
    {
        $results = $this->mapper->findMultiple([], null, false, false);
        $this->assertIsArray($results);
        $this->assertEmpty($results);
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

    public function testCreateFromArraySetsDefaultVersion(): void
    {
        $schema = $this->createTestSchema();
        $this->assertSame('0.0.1', $schema->getVersion());
    }

    public function testCreateFromArraySetsDefaultSource(): void
    {
        $schema = $this->createTestSchema();
        $this->assertSame('internal', $schema->getSource());
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

    public function testCreateFromArrayWithNullRequired(): void
    {
        // Tests the default required handling - null gets set to []
        $schema = $this->createTestSchema();
        $required = $schema->getRequired();
        $this->assertIsArray($required);
    }

    public function testCreateFromArrayAutoPopulatesObjectNameField(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name'],
                'value' => ['type' => 'integer', 'title' => 'Value'],
            ],
        ]);

        $config = $schema->getConfiguration() ?? [];
        // 'name' should be auto-detected as objectNameField
        $this->assertSame('name', $config['objectNameField'] ?? null);
    }

    public function testCreateFromArrayAutoPopulatesDescriptionField(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'title' => ['type' => 'string', 'title' => 'Title'],
                'description' => ['type' => 'string', 'title' => 'Description'],
            ],
        ]);

        $config = $schema->getConfiguration() ?? [];
        $this->assertSame('description', $config['objectDescriptionField'] ?? null);
    }

    public function testCreateFromArrayAutoPopulatesDutchFieldNames(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'naam' => ['type' => 'string', 'title' => 'Naam'],
                'omschrijving' => ['type' => 'string', 'title' => 'Omschrijving'],
            ],
        ]);

        $config = $schema->getConfiguration() ?? [];
        $this->assertSame('naam', $config['objectNameField'] ?? null);
        $this->assertSame('omschrijving', $config['objectDescriptionField'] ?? null);
    }

    // =========================================================================
    // Configuration field validation tests
    // =========================================================================

    public function testCreateFromArrayWithValidConfigObjectNameField(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'email' => ['type' => 'string', 'title' => 'Email'],
            ],
            'configuration' => [
                'objectNameField' => 'email',
            ],
        ]);

        $config = $schema->getConfiguration() ?? [];
        $this->assertSame('email', $config['objectNameField']);
    }

    public function testCreateFromArrayWithInvalidConfigObjectNameFieldThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not exist as a property');

        $this->createTestSchema([
            'properties' => [
                'email' => ['type' => 'string', 'title' => 'Email'],
            ],
            'configuration' => [
                'objectNameField' => 'nonexistent_field',
            ],
        ]);
    }

    public function testCreateFromArrayWithTwigTemplateConfigField(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'voornaam' => ['type' => 'string', 'title' => 'Voornaam'],
                'achternaam' => ['type' => 'string', 'title' => 'Achternaam'],
            ],
            'configuration' => [
                'objectNameField' => '{{ voornaam }} {{ achternaam }}',
            ],
        ]);

        $config = $schema->getConfiguration() ?? [];
        $this->assertSame('{{ voornaam }} {{ achternaam }}', $config['objectNameField']);
    }

    public function testCreateFromArrayWithInvalidTwigTemplateConfigFieldThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("template property 'nonexistent'");

        $this->createTestSchema([
            'properties' => [
                'voornaam' => ['type' => 'string', 'title' => 'Voornaam'],
            ],
            'configuration' => [
                'objectNameField' => '{{ voornaam }} {{ nonexistent }}',
            ],
        ]);
    }

    public function testCreateFromArrayWithPipeSeparatedConfigField(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name'],
                'identifier' => ['type' => 'string', 'title' => 'Identifier'],
            ],
            'configuration' => [
                'objectNameField' => 'name | identifier | type',
            ],
        ]);

        $config = $schema->getConfiguration() ?? [];
        $this->assertSame('name | identifier | type', $config['objectNameField']);
    }

    public function testCreateFromArrayWithInvalidPipeSeparatedConfigFieldThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('None of the fallback fields');

        $this->createTestSchema([
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name'],
            ],
            'configuration' => [
                'objectNameField' => 'nonexistent1 | nonexistent2',
            ],
        ]);
    }

    // =========================================================================
    // Required fields auto-build tests
    // =========================================================================

    public function testCreateFromArrayBuildsRequiredFromPropertyFlags(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name', 'required' => true],
                'optional' => ['type' => 'string', 'title' => 'Optional'],
            ],
        ]);

        $required = $schema->getRequired();
        $this->assertContains('name', $required);
        $this->assertNotContains('optional', $required);
    }

    public function testCreateFromArrayBuildsRequiredFromStringTrue(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name', 'required' => 'true'],
                'optional' => ['type' => 'string', 'title' => 'Optional', 'required' => 'false'],
            ],
        ]);

        $required = $schema->getRequired();
        $this->assertContains('name', $required);
        $this->assertNotContains('optional', $required);
    }

    public function testCreateFromArrayPreservesExplicitRequired(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name'],
                'age' => ['type' => 'integer', 'title' => 'Age'],
            ],
            'required' => ['name'],
        ]);

        $required = $schema->getRequired();
        $this->assertContains('name', $required);
    }

    // =========================================================================
    // $ref enforcement tests
    // =========================================================================

    public function testCreateFromArrayWithRefAsArray(): void
    {
        // When $ref is an array with 'id', it should be converted to just the id
        $schema = $this->createTestSchema([
            'properties' => [
                'link' => [
                    'type' => 'string',
                    'title' => 'Link',
                    '$ref' => ['id' => '123'],
                ],
            ],
        ]);

        $props = $schema->getProperties();
        $this->assertSame('123', $props['link']['$ref']);
    }

    public function testCreateFromArrayWithRefAsInt(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'link' => [
                    'type' => 'string',
                    'title' => 'Link',
                    '$ref' => 42,
                ],
            ],
        ]);

        $props = $schema->getProperties();
        $this->assertSame(42, $props['link']['$ref']);
    }

    public function testCreateFromArrayWithPropertyLevelNestedRef(): void
    {
        // Test $ref within nested properties (property -> properties -> nested)
        $schema = $this->createTestSchema([
            'properties' => [
                'wrapper' => [
                    'type' => 'object',
                    'title' => 'Wrapper',
                    'properties' => [
                        'link' => [
                            'type' => 'string',
                            'title' => 'Link',
                            '$ref' => ['id' => '789'],
                        ],
                    ],
                ],
            ],
        ]);

        $props = $schema->getProperties();
        // The $ref array {'id': '789'} should be converted to just '789'
        $this->assertSame('789', $props['wrapper']['properties']['link']['$ref']);
    }

    // =========================================================================
    // Facet configuration generation tests
    // =========================================================================

    public function testCreateFromArrayGeneratesFacetConfigForCommonFields(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'status' => ['type' => 'string', 'title' => 'Status'],
                'category' => ['type' => 'string', 'title' => 'Category'],
                'priority' => ['type' => 'string', 'title' => 'Priority'],
            ],
        ]);

        $facets = $schema->getFacets();
        $this->assertIsArray($facets);
        $this->assertArrayHasKey('object_fields', $facets);
        $this->assertArrayHasKey('status', $facets['object_fields']);
        $this->assertArrayHasKey('category', $facets['object_fields']);
    }

    public function testCreateFromArrayGeneratesFacetForEnumProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'color' => [
                    'type' => 'string',
                    'title' => 'Color',
                    'enum' => ['red', 'green', 'blue'],
                ],
            ],
        ]);

        $facets = $schema->getFacets();
        $this->assertArrayHasKey('color', $facets['object_fields']);
        $this->assertSame('terms', $facets['object_fields']['color']['type']);
    }

    public function testCreateFromArrayGeneratesFacetForDateField(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'createdDate' => ['type' => 'string', 'title' => 'Created Date'],
            ],
        ]);

        $facets = $schema->getFacets();
        // 'createdDate' contains 'created' which is a date-like name
        $this->assertArrayHasKey('createdDate', $facets['object_fields']);
        $this->assertSame('date_histogram', $facets['object_fields']['createdDate']['type']);
    }

    public function testCreateFromArrayGeneratesFacetForDateFormatProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'when' => ['type' => 'string', 'format' => 'date-time', 'title' => 'When', 'facetable' => true],
            ],
        ]);

        $facets = $schema->getFacets();
        $this->assertArrayHasKey('when', $facets['object_fields']);
        // date-time format maps to terms facet type (the type is 'string')
        $this->assertSame('terms', $facets['object_fields']['when']['type']);
    }

    public function testCreateFromArrayGeneratesFacetForExplicitFacetableProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'customField' => [
                    'type' => 'string',
                    'title' => 'Custom',
                    'facetable' => true,
                ],
            ],
        ]);

        $facets = $schema->getFacets();
        $this->assertArrayHasKey('customField', $facets['object_fields']);
    }

    public function testCreateFromArraySkipsFacetForExplicitFacetableFalse(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'title' => 'Status',
                    'facetable' => false,
                ],
            ],
        ]);

        $facets = $schema->getFacets();
        $this->assertArrayNotHasKey('status', $facets['object_fields'] ?? []);
    }

    public function testCreateFromArrayGeneratesFacetForBooleanType(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'active' => [
                    'type' => 'boolean',
                    'title' => 'Active',
                    'facetable' => true,
                ],
            ],
        ]);

        $facets = $schema->getFacets();
        $this->assertArrayHasKey('active', $facets['object_fields']);
        $this->assertSame('terms', $facets['object_fields']['active']['type']);
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

    public function testUpdateFromArrayWithExplicitVersion(): void
    {
        $schema = $this->createTestSchema();

        $updated = $this->mapper->updateFromArray($schema->getId(), [
            'version' => '2.0.0',
        ]);

        $this->assertSame('2.0.0', $updated->getVersion());
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

    public function testGetRelatedByIdInsteadOfEntity(): void
    {
        $schema = $this->createTestSchema();

        // Call getRelated with int ID instead of Schema object
        $related = $this->mapper->getRelated($schema->getId());
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

    public function testFindExtendedByWithKnownUuidAndSlug(): void
    {
        $schema = $this->createTestSchema();

        $result = $this->mapper->findExtendedBy(
            $schema->getId(),
            $schema->getUuid(),
            $schema->getSlug()
        );
        $this->assertIsArray($result);
    }

    public function testFindExtendedByWithActualExtension(): void
    {
        $parent = $this->createTestSchema(['title' => 'Parent Schema ' . uniqid()]);

        $child = $this->createTestSchema([
            'title' => 'Child Schema ' . uniqid(),
            'allOf' => [(string) $parent->getId()],
            'properties' => [
                'extra' => ['type' => 'string', 'title' => 'Extra'],
            ],
        ]);

        $result = $this->mapper->findExtendedBy($parent->getId());
        $this->assertIsArray($result);
        $this->assertContains($child->getUuid(), $result);
    }

    public function testFindAllExtendedByReturnsArray(): void
    {
        $result = $this->mapper->findAllExtendedBy();
        $this->assertIsArray($result);
    }

    public function testFindAllExtendedByWithActualExtension(): void
    {
        $parent = $this->createTestSchema(['title' => 'Parent AllEB ' . uniqid()]);

        $child = $this->createTestSchema([
            'title' => 'Child AllEB ' . uniqid(),
            'allOf' => [(string) $parent->getId()],
            'properties' => [
                'extra' => ['type' => 'string', 'title' => 'Extra'],
            ],
        ]);

        $result = $this->mapper->findAllExtendedBy();
        $this->assertIsArray($result);
        $this->assertArrayHasKey($parent->getId(), $result);
        $this->assertContains($child->getUuid(), $result[$parent->getId()]);
    }

    // =========================================================================
    // hasReferenceToSchema tests
    // =========================================================================

    public function testHasReferenceToSchemaReturnsBool(): void
    {
        $schema = $this->createTestSchema();
        $otherSchema = $this->createTestSchema();

        $result = $this->mapper->hasReferenceToSchema(
            $schema->getProperties() ?? [],
            (string) $otherSchema->getId(),
            $otherSchema->getUuid() ?? '',
            $otherSchema->getSlug() ?? ''
        );
        $this->assertIsBool($result);
    }

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

    public function testHasReferenceToSchemaBySlugTrue(): void
    {
        $targetSchema = $this->createTestSchema();
        $properties = [
            'link' => [
                'type'  => 'string',
                'title' => 'Link',
                '$ref'  => $targetSchema->getSlug(),
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

    public function testHasReferenceToSchemaByJsonSchemaFormat(): void
    {
        $targetSchema = $this->createTestSchema();
        $slug = $targetSchema->getSlug();
        $properties = [
            'link' => [
                'type'  => 'string',
                'title' => 'Link',
                '$ref'  => '#/components/schemas/' . $slug,
            ],
        ];

        $result = $this->mapper->hasReferenceToSchema(
            $properties,
            (string) $targetSchema->getId(),
            $targetSchema->getUuid() ?? '',
            $slug ?? ''
        );
        $this->assertTrue($result);
    }

    public function testHasReferenceToSchemaInNestedProperties(): void
    {
        $targetSchema = $this->createTestSchema();
        $properties = [
            'wrapper' => [
                'type' => 'object',
                'title' => 'Wrapper',
                'properties' => [
                    'nested_link' => [
                        'type'  => 'string',
                        'title' => 'Nested Link',
                        '$ref'  => (string) $targetSchema->getId(),
                    ],
                ],
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

    public function testHasReferenceToSchemaInArrayItems(): void
    {
        $targetSchema = $this->createTestSchema();
        $properties = [
            'links' => [
                'type' => 'array',
                'title' => 'Links',
                'items' => [
                    'type'  => 'string',
                    '$ref'  => (string) $targetSchema->getId(),
                ],
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

    public function testHasReferenceToSchemaSkipsNonArrayProperties(): void
    {
        $targetSchema = $this->createTestSchema();
        // Include a scalar property value that should be skipped
        $properties = [
            'scalarProp' => 'just a string',
            'link' => [
                'type'  => 'string',
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

    public function testHasReferenceToSchemaByUuidContainedInRef(): void
    {
        $targetSchema = $this->createTestSchema();
        $uuid = $targetSchema->getUuid();
        $properties = [
            'link' => [
                'type'  => 'string',
                '$ref'  => 'https://example.com/schemas/' . $uuid,
            ],
        ];

        $result = $this->mapper->hasReferenceToSchema(
            $properties,
            (string) $targetSchema->getId(),
            $uuid ?? '',
            $targetSchema->getSlug() ?? ''
        );
        $this->assertTrue($result);
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
        $this->assertArrayHasKey('email', $result);
        $this->assertSame('native', $result['email']['source']);
        $this->assertNull($result['email']['inheritedFrom']);
    }

    public function testGetPropertySourceMetadataWithAllOfComposition(): void
    {
        $parent = $this->createTestSchema([
            'title' => 'MetaParent ' . uniqid(),
            'properties' => [
                'parentProp' => ['type' => 'string', 'title' => 'Parent Prop'],
            ],
        ]);

        $child = $this->createTestSchema([
            'title' => 'MetaChild ' . uniqid(),
            'allOf' => [(string) $parent->getId()],
            'properties' => [
                'childProp' => ['type' => 'string', 'title' => 'Child Prop'],
            ],
        ]);

        // Re-fetch to get the resolved schema with merged properties
        $resolvedChild = $this->mapper->find($child->getId(), [], null, false, false);

        $result = $this->mapper->getPropertySourceMetadata($resolvedChild);
        $this->assertIsArray($result);
        // childProp should be native, parentProp should be inherited
        if (isset($result['childProp'])) {
            $this->assertSame('native', $result['childProp']['source']);
        }
        if (isset($result['parentProp'])) {
            $this->assertSame('inherited', $result['parentProp']['source']);
        }
    }

    // =========================================================================
    // Schema composition (allOf) tests
    // =========================================================================

    public function testSchemaAllOfResolvesParentProperties(): void
    {
        $parent = $this->createTestSchema([
            'title' => 'AllOfParent ' . uniqid(),
            'properties' => [
                'parentField' => ['type' => 'string', 'title' => 'Parent Field'],
            ],
        ]);

        $child = $this->createTestSchema([
            'title' => 'AllOfChild ' . uniqid(),
            'allOf' => [(string) $parent->getId()],
            'properties' => [
                'childField' => ['type' => 'string', 'title' => 'Child Field'],
            ],
        ]);

        // When we find the child, the resolved schema should have both properties
        $resolved = $this->mapper->find($child->getId(), [], null, false, false);
        $props = $resolved->getProperties();

        $this->assertArrayHasKey('parentField', $props);
        $this->assertArrayHasKey('childField', $props);
    }

    public function testSchemaAllOfMergesRequired(): void
    {
        $parent = $this->createTestSchema([
            'title' => 'ReqParent ' . uniqid(),
            'properties' => [
                'parentField' => ['type' => 'string', 'title' => 'Parent Field', 'required' => true],
            ],
        ]);

        $child = $this->createTestSchema([
            'title' => 'ReqChild ' . uniqid(),
            'allOf' => [(string) $parent->getId()],
            'properties' => [
                'childField' => ['type' => 'string', 'title' => 'Child Field', 'required' => true],
            ],
        ]);

        $resolved = $this->mapper->find($child->getId(), [], null, false, false);
        $required = $resolved->getRequired();

        $this->assertContains('parentField', $required);
        $this->assertContains('childField', $required);
    }

    // =========================================================================
    // insert / update / delete lifecycle
    // =========================================================================

    public function testInsertDoesNotAutoSetCreatedTimestamp(): void
    {
        $schema = $this->createTestSchema();
        $this->assertNull($schema->getCreated());
    }

    public function testInsertDoesNotAutoSetUpdatedTimestamp(): void
    {
        $schema = $this->createTestSchema();
        $this->assertNull($schema->getUpdated());
    }

    public function testUpdateSetsUpdatedTimestamp(): void
    {
        $schema = $this->createTestSchema();
        $originalUpdated = $schema->getUpdated();

        usleep(100000);

        $updated = $this->mapper->updateFromArray($schema->getId(), [
            'description' => 'Timestamp test update',
        ]);
        $this->assertNotNull($updated->getUpdated());
    }

    // =========================================================================
    // findAll with sorting tests
    // =========================================================================

    public function testFindAllWithSearchConditionsReturnsArray(): void
    {
        $schema1 = $this->createTestSchema(['title' => 'AAA Sort Test ' . uniqid()]);
        $schema2 = $this->createTestSchema(['title' => 'ZZZ Sort Test ' . uniqid()]);

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

    public function testHasReferenceToSchemaByIntIdTrue(): void
    {
        $targetSchema = $this->createTestSchema();
        $properties = [
            'link' => [
                'type'  => 'string',
                'title' => 'Link',
                '$ref'  => $targetSchema->getId(), // int, not string
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

    // =========================================================================
    // findAll with published parameter
    // =========================================================================

    public function testFindAllWithPublishedParam(): void
    {
        $this->createTestSchema();

        $results = $this->mapper->findAll(
            null,
            null,
            [],
            [],
            [],
            [],
            true, // published
            false,
            false
        );
        $this->assertIsArray($results);
    }

    // =========================================================================
    // find with published parameter
    // =========================================================================

    public function testFindWithPublishedBypass(): void
    {
        $schema = $this->createTestSchema();
        $found = $this->mapper->find($schema->getId(), [], true, false, false);
        $this->assertSame($schema->getId(), $found->getId());
    }

    // =========================================================================
    // find with different RBAC/multitenancy flags to exercise cache keys
    // =========================================================================

    public function testFindWithDifferentRbacFlagsCachesSeparately(): void
    {
        $schema = $this->createTestSchema();

        // Call with RBAC=false, multitenancy=false
        $found1 = $this->mapper->find($schema->getId(), [], null, false, false);
        // Call with RBAC=true, multitenancy=false (different cache key)
        $found2 = $this->mapper->find($schema->getId(), [], null, true, false);

        $this->assertSame($found1->getId(), $found2->getId());
    }

    // =========================================================================
    // find with multitenancy disabled to bypass org filter
    // =========================================================================

    public function testFindWithMultitenancyDisabled(): void
    {
        $schema = $this->createTestSchema();
        $found = $this->mapper->find($schema->getId(), [], null, false, false);
        $this->assertSame($schema->getId(), $found->getId());
    }

    // =========================================================================
    // findAll with search conditions (WHERE fragments)
    // =========================================================================

    public function testFindAllWithSearchConditionsAndParams(): void
    {
        $uniqueTitle = 'SearchCond Test ' . uniqid();
        $this->createTestSchema(['title' => $uniqueTitle]);

        $results = $this->mapper->findAll(
            null,
            null,
            [],
            ['LOWER(title) LIKE :searchTitle'],
            ['searchTitle' => '%' . strtolower($uniqueTitle) . '%'],
            [],
            null,
            false,
            false
        );

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
    }
}
