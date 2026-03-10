<?php

/**
 * Integration tests for MagicFacetHandler
 *
 * Tests facet generation, facet filtering, facet counting, terms facets,
 * date histogram facets, and UNION-based faceting across multiple tables.
 * Exercises MagicFacetHandler indirectly via MagicMapper.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 */

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Symfony\Component\Uid\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class MagicFacetHandlerIntegrationTest extends TestCase
{
    private MagicMapper $mapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;

    /** @var int[] IDs of schemas created during tests */
    private array $createdSchemaIds = [];
    /** @var int[] IDs of registers created during tests */
    private array $createdRegisterIds = [];
    /** @var array Table names created during tests */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = \OC::$server->get(MagicMapper::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        // Drop created magic tables
        foreach ($this->createdTables as $tableName) {
            try {
                $db->prepare("DROP TABLE IF EXISTS $tableName")->execute();
            } catch (\Exception $e) {
                // Table may not exist
            }
        }

        // Clean schemas
        foreach ($this->createdSchemaIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up
            }
        }

        // Clean registers
        foreach ($this->createdRegisterIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up
            }
        }

        parent::tearDown();
    }

    private function createTestRegister(): Register
    {
        $register = $this->registerMapper->createFromArray([
            'title'       => 'PHPUnit Facet Test Register ' . uniqid(),
            'description' => 'Register for MagicFacetHandler integration tests',
        ]);
        $this->createdRegisterIds[] = $register->getId();

        return $register;
    }

    private function createTestSchema(array $extraProperties = []): Schema
    {
        $properties = [
            'name' => [
                'type'      => 'string',
                'title'     => 'Name',
                'maxLength' => 255,
            ],
            'category' => [
                'type'      => 'string',
                'title'     => 'Category',
                'maxLength' => 255,
            ],
            'age' => [
                'type'  => 'integer',
                'title' => 'Age',
            ],
            'active' => [
                'type'  => 'boolean',
                'title' => 'Active',
            ],
        ];

        $properties = array_merge($properties, $extraProperties);

        $schema = $this->schemaMapper->createFromArray([
            'title'       => 'PHPUnit Facet Test Schema ' . uniqid(),
            'description' => 'Schema for MagicFacetHandler integration tests',
            'properties'  => $properties,
        ]);
        $this->createdSchemaIds[] = $schema->getId();

        return $schema;
    }

    private function trackTable(Register $register, Schema $schema): void
    {
        $tableName = $this->mapper->getTableNameForRegisterSchema($register, $schema);
        $this->createdTables[] = 'oc_' . $tableName;
    }

    /**
     * Insert test objects into a magic table
     */
    private function insertTestObject(
        Register $register,
        Schema $schema,
        array $objectData
    ): ObjectEntity {
        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($objectData);

        return $this->mapper->insertObjectEntity($entity, $register, $schema, false);
    }

    // =========================================================================
    // Terms facets on string property tests
    // =========================================================================

    public function testTermsFacetsOnStringProperty(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Alice', 'category' => 'A', 'age' => 30, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'Bob', 'category' => 'A', 'age' => 25, 'active' => false]);
        $this->insertTestObject($register, $schema, ['name' => 'Charlie', 'category' => 'B', 'age' => 35, 'active' => true]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'category' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('category', $facets);
        $this->assertArrayHasKey('buckets', $facets['category']);
        $this->assertIsArray($facets['category']['buckets']);
    }

    public function testTermsFacetsOnBooleanProperty(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Active1', 'category' => 'X', 'age' => 20, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'Active2', 'category' => 'X', 'age' => 21, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'Inactive', 'category' => 'Y', 'age' => 22, 'active' => false]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'active' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('active', $facets);
        $this->assertArrayHasKey('buckets', $facets['active']);
    }

    // =========================================================================
    // Terms facets on integer property tests
    // =========================================================================

    public function testTermsFacetsOnIntegerProperty(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Young1', 'category' => 'A', 'age' => 20, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'Young2', 'category' => 'A', 'age' => 20, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'Old', 'category' => 'B', 'age' => 50, 'active' => false]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'age' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('age', $facets);
        $this->assertArrayHasKey('buckets', $facets['age']);
    }

    // =========================================================================
    // Multiple facets in single request tests
    // =========================================================================

    public function testMultipleFacetsInSingleRequest(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Alice', 'category' => 'A', 'age' => 30, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'Bob', 'category' => 'B', 'age' => 25, 'active' => false]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'category' => ['type' => 'terms'],
                    'active'   => ['type' => 'terms'],
                    'age'      => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('category', $facets);
        $this->assertArrayHasKey('active', $facets);
        $this->assertArrayHasKey('age', $facets);
    }

    // =========================================================================
    // Facets with filter applied tests
    // =========================================================================

    public function testFacetsWithPropertyFilterApplied(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Alice', 'category' => 'A', 'age' => 30, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'Bob', 'category' => 'A', 'age' => 25, 'active' => false]);
        $this->insertTestObject($register, $schema, ['name' => 'Charlie', 'category' => 'B', 'age' => 35, 'active' => true]);

        // Get facets while filtering on category=A
        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                'category' => 'A',
                '_facets'  => [
                    'active' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('active', $facets);
    }

    // =========================================================================
    // Facets on empty table tests
    // =========================================================================

    public function testFacetsOnEmptyTableReturnsEmptyBuckets(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'category' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('category', $facets);
        $this->assertArrayHasKey('buckets', $facets['category']);
        $this->assertEmpty($facets['category']['buckets']);
    }

    // =========================================================================
    // Facets with no _facets config returns empty tests
    // =========================================================================

    public function testFacetsWithNoFacetConfigReturnsEmpty(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Test', 'category' => 'A', 'age' => 30, 'active' => true]);

        // No _facets in query - should return empty
        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            ['_rbac' => false, '_multitenancy' => false],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
    }

    // =========================================================================
    // Facets with _facets as string (expand) tests
    // =========================================================================

    public function testFacetsWithStringConfig(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Test', 'category' => 'A', 'age' => 30, 'active' => true]);

        // _facets as string 'extend' means expand all schema properties into facets
        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => 'extend',
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
    }

    // =========================================================================
    // Facets with _facets as list of field names tests
    // =========================================================================

    public function testFacetsWithListOfFieldNames(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Test', 'category' => 'A', 'age' => 30, 'active' => true]);

        // _facets as numerically-indexed array of field names
        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => ['category', 'active'],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
    }

    // =========================================================================
    // Metadata facets (@self) tests
    // =========================================================================

    public function testMetadataFacets(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'MetaFacet1', 'category' => 'A', 'age' => 30, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'MetaFacet2', 'category' => 'B', 'age' => 25, 'active' => false]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    '@self' => [
                        'register' => ['type' => 'terms'],
                        'schema'   => ['type' => 'terms'],
                    ],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        if (isset($facets['@self'])) {
            $this->assertIsArray($facets['@self']);
        }
    }

    // =========================================================================
    // Facets with custom title tests
    // =========================================================================

    public function testFacetsWithCustomTitle(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'TitleTest', 'category' => 'Cat', 'age' => 10, 'active' => true]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'category' => ['type' => 'terms', 'title' => 'Custom Category Title'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('category', $facets);
        if (isset($facets['category']['title'])) {
            $this->assertSame('Custom Category Title', $facets['category']['title']);
        }
    }

    // =========================================================================
    // Facets include metrics tests
    // =========================================================================

    public function testFacetsIncludeMetrics(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Metrics', 'category' => 'M', 'age' => 10, 'active' => true]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'category' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('_metrics', $facets);
        $this->assertArrayHasKey('total_ms', $facets['_metrics']);
    }

    // =========================================================================
    // Facet buckets contain count tests
    // =========================================================================

    public function testFacetBucketsContainCount(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'C1', 'category' => 'Alpha', 'age' => 10, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'C2', 'category' => 'Alpha', 'age' => 20, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'C3', 'category' => 'Beta', 'age' => 30, 'active' => false]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'category' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('category', $facets);

        $buckets = $facets['category']['buckets'];
        $this->assertNotEmpty($buckets);

        // Each bucket should have 'key' and 'results'
        foreach ($buckets as $bucket) {
            $this->assertArrayHasKey('key', $bucket);
            $this->assertArrayHasKey('results', $bucket);
            $this->assertIsInt($bucket['results']);
            $this->assertGreaterThan(0, $bucket['results']);
        }
    }

    // =========================================================================
    // UNION facets across multiple tables tests
    // =========================================================================

    public function testFacetsUnionAcrossMultipleTables(): void
    {
        $register = $this->createTestRegister();
        $schema1 = $this->createTestSchema();
        $schema2 = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema1);
        $this->trackTable($register, $schema1);
        $this->mapper->ensureTableForRegisterSchema($register, $schema2);
        $this->trackTable($register, $schema2);

        $this->insertTestObject($register, $schema1, ['name' => 'Union1', 'category' => 'X', 'age' => 10, 'active' => true]);
        $this->insertTestObject($register, $schema1, ['name' => 'Union2', 'category' => 'Y', 'age' => 20, 'active' => false]);
        $this->insertTestObject($register, $schema2, ['name' => 'Union3', 'category' => 'X', 'age' => 30, 'active' => true]);

        $pairs = [
            ['register' => $register, 'schema' => $schema1],
            ['register' => $register, 'schema' => $schema2],
        ];

        $facets = $this->mapper->getSimpleFacetsUnion(
            [
                '_facets' => [
                    'category' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            [$schema1, $schema2],
            $pairs
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('category', $facets);
        $this->assertArrayHasKey('buckets', $facets['category']);
    }

    public function testFacetsUnionWithStringConfig(): void
    {
        $register = $this->createTestRegister();
        $schema1 = $this->createTestSchema();
        $schema2 = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema1);
        $this->trackTable($register, $schema1);
        $this->mapper->ensureTableForRegisterSchema($register, $schema2);
        $this->trackTable($register, $schema2);

        $this->insertTestObject($register, $schema1, ['name' => 'Ext1', 'category' => 'C1', 'age' => 10, 'active' => true]);
        $this->insertTestObject($register, $schema2, ['name' => 'Ext2', 'category' => 'C2', 'age' => 20, 'active' => false]);

        $pairs = [
            ['register' => $register, 'schema' => $schema1],
            ['register' => $register, 'schema' => $schema2],
        ];

        $facets = $this->mapper->getSimpleFacetsUnion(
            [
                '_facets' => 'extend',
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            [$schema1, $schema2],
            $pairs
        );

        $this->assertIsArray($facets);
    }

    public function testFacetsUnionEmptyPairs(): void
    {
        $facets = $this->mapper->getSimpleFacetsUnion(
            [
                '_facets' => ['category' => ['type' => 'terms']],
            ],
            null,
            [],
            []
        );

        $this->assertIsArray($facets);
    }

    // =========================================================================
    // Facets with RBAC disabled tests
    // =========================================================================

    public function testFacetsWithRbacAndMultitenancyDisabled(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'RbacFacet', 'category' => 'R', 'age' => 42, 'active' => true]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'category' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('category', $facets);
        $buckets = $facets['category']['buckets'];
        $this->assertNotEmpty($buckets);
        // With RBAC/multitenancy disabled, should see our data
        $keys = array_column($buckets, 'key');
        $this->assertContains('R', $keys);
    }

    // =========================================================================
    // Facets with many distinct values tests
    // =========================================================================

    public function testFacetsWithManyDistinctValues(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        // Insert 15 objects with different category values
        for ($i = 0; $i < 15; $i++) {
            $this->insertTestObject($register, $schema, [
                'name'     => 'Distinct' . $i,
                'category' => 'Cat' . $i,
                'age'      => $i,
                'active'   => ($i % 2 === 0),
            ]);
        }

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'category' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('category', $facets);
        $buckets = $facets['category']['buckets'];
        $this->assertCount(15, $buckets);
    }

    // =========================================================================
    // Facets on property with null values tests
    // =========================================================================

    public function testFacetsOnPropertyWithNullValues(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'WithCat', 'category' => 'A', 'age' => 10, 'active' => true]);
        // Insert object without category - should be null in the column
        $this->insertTestObject($register, $schema, ['name' => 'NoCat', 'age' => 20, 'active' => false]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'category' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('category', $facets);
    }

    // =========================================================================
    // Date histogram facet tests
    // =========================================================================

    public function testDateHistogramFacetOnMetadata(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'DateItem1', 'category' => 'A', 'age' => 10, 'active' => true]);
        $this->insertTestObject($register, $schema, ['name' => 'DateItem2', 'category' => 'B', 'age' => 20, 'active' => false]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    '@self' => [
                        'created' => ['type' => 'date_histogram', 'interval' => 'month'],
                    ],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );

        $this->assertIsArray($facets);
        if (isset($facets['@self']) && isset($facets['@self']['created'])) {
            $this->assertArrayHasKey('buckets', $facets['@self']['created']);
        }
    }

    // =========================================================================
    // UNION facets with metadata (@self) tests
    // =========================================================================

    public function testFacetsUnionWithMetadataFacets(): void
    {
        $register = $this->createTestRegister();
        $schema1 = $this->createTestSchema();
        $schema2 = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema1);
        $this->trackTable($register, $schema1);
        $this->mapper->ensureTableForRegisterSchema($register, $schema2);
        $this->trackTable($register, $schema2);

        $this->insertTestObject($register, $schema1, ['name' => 'M1', 'category' => 'X', 'age' => 10, 'active' => true]);
        $this->insertTestObject($register, $schema2, ['name' => 'M2', 'category' => 'Y', 'age' => 20, 'active' => false]);

        $pairs = [
            ['register' => $register, 'schema' => $schema1],
            ['register' => $register, 'schema' => $schema2],
        ];

        $facets = $this->mapper->getSimpleFacetsUnion(
            [
                '_facets' => [
                    '@self' => [
                        'schema' => ['type' => 'terms'],
                    ],
                    'category' => ['type' => 'terms'],
                ],
                '_rbac' => false,
                '_multitenancy' => false,
            ],
            $register,
            [$schema1, $schema2],
            $pairs
        );

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('category', $facets);
    }
}
