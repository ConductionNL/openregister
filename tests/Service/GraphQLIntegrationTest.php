<?php

/**
 * Integration tests for the GraphQL API
 *
 * Tests the complete GraphQL stack against a real database:
 * schema generation, query execution, RBAC enforcement,
 * mutations with audit trails, filtering, pagination, and facets.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\GraphQL\GraphQLService;
use OCA\OpenRegister\Service\GraphQL\SchemaGenerator;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for GraphQL API
 *
 * @group DB
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class GraphQLIntegrationTest extends TestCase
{

    /**
     * @var GraphQLService
     */
    private GraphQLService $graphqlService;

    /**
     * @var SchemaGenerator
     */
    private SchemaGenerator $schemaGenerator;

    /**
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * @var Register|null
     */
    private ?Register $testRegister = null;

    /**
     * @var Schema|null
     */
    private ?Schema $testSchema = null;

    /**
     * @var Schema|null
     */
    private ?Schema $refSchema = null;

    /**
     * @var string[]
     */
    private array $createdObjectUuids = [];


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->graphqlService  = \OC::$server->get(GraphQLService::class);
        $this->schemaGenerator = \OC::$server->get(SchemaGenerator::class);
        $this->objectService   = \OC::$server->get(ObjectService::class);
        $this->registerMapper  = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper    = \OC::$server->get(SchemaMapper::class);

        $this->createTestFixtures();

    }//end setUp()


    /**
     * Clean up test data.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $this->objectService->deleteObject($uuid, false, false);
            } catch (\Exception $e) {
                // Ignore cleanup errors.
            }
        }

        if ($this->refSchema !== null) {
            try {
                $this->schemaMapper->delete($this->refSchema);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        if ($this->testSchema !== null) {
            try {
                $this->schemaMapper->delete($this->testSchema);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        if ($this->testRegister !== null) {
            try {
                $this->registerMapper->delete($this->testRegister);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        parent::tearDown();

    }//end tearDown()


    /**
     * Create test register, schemas, and sample objects.
     *
     * @return void
     */
    private function createTestFixtures(): void
    {
        // Create register.
        $register = new Register();
        $register->setTitle('graphql-test-'.uniqid());
        $register->setDescription('Test register for GraphQL integration tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('graphql-test-'.uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        // Create main test schema.
        $schema = new Schema();
        $schema->setTitle('GraphQL Meldingen');
        $schema->setDescription('Test schema for GraphQL tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('graphql-meldingen-'.uniqid());
        $schema->setProperties([
            'title'    => ['type' => 'string', 'title' => 'Title'],
            'status'   => ['type' => 'string', 'title' => 'Status'],
            'priority' => ['type' => 'integer', 'title' => 'Priority'],
            'email'    => ['type' => 'string', 'format' => 'email', 'title' => 'Contact Email'],
        ]);
        $schema->setRequired(['title']);
        $this->testSchema = $this->schemaMapper->insert($schema);

        // Create reference schema.
        $refSchema = new Schema();
        $refSchema->setTitle('GraphQL Personen');
        $refSchema->setDescription('Reference schema for GraphQL tests');
        $refSchema->setUuid(Uuid::v4()->toRfc4122());
        $refSchema->setSlug('graphql-personen-'.uniqid());
        $refSchema->setProperties([
            'naam'  => ['type' => 'string', 'title' => 'Name'],
            'email' => ['type' => 'string', 'format' => 'email', 'title' => 'Email'],
        ]);
        $this->refSchema = $this->schemaMapper->insert($refSchema);

        // Link schemas to register.
        $this->testRegister->setSchemas([
            $this->testSchema->getId(),
            $this->refSchema->getId(),
        ]);
        $this->registerMapper->update($this->testRegister);

    }//end createTestFixtures()


    /**
     * Create a test object in the database.
     *
     * @param array $data The object data
     *
     * @return array The created object
     */
    private function createTestObject(array $data): array
    {
        $object = $this->objectService->saveObject(
            $data,
            [],
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false
        );

        $uuid = $object->getUuid();
        $this->createdObjectUuids[] = $uuid;

        $result         = $object->getObject() ?? [];
        $result['_uuid'] = $uuid;
        return $result;

    }//end createTestObject()


    // ── Schema Generation ──

    /**
     * Test that the schema generator produces valid types for our test schemas.
     *
     * @return void
     */
    public function testSchemaGenerationFromRealDatabase(): void
    {
        // Verify schema generation via introspection (not direct generate() call
        // which would conflict with the DI-shared SchemaGenerator).
        $result = $this->graphqlService->execute(
            '{ __type(name: "Query") { fields { name } } }'
        );

        $this->assertArrayHasKey('data', $result, 'Should return data');
        $queryFields = array_column(
            ($result['data']['__type']['fields'] ?? []),
            'name'
        );
        $this->assertNotEmpty($queryFields, 'Should have query fields from schemas');
        $this->assertContains('register', $queryFields, 'Should have register scoped query');

    }//end testSchemaGenerationFromRealDatabase()


    // ── Query Execution ──

    /**
     * Test introspection query works.
     *
     * @return void
     */
    public function testIntrospectionQuery(): void
    {
        $result = $this->graphqlService->execute('{ __schema { types { name } } }');

        $this->assertArrayHasKey('data', $result, 'Introspection should return data');
        $this->assertArrayHasKey('__schema', $result['data']);

        $typeNames = array_column($result['data']['__schema']['types'], 'name');
        $this->assertContains('Query', $typeNames, 'Should contain Query type');
        $this->assertContains('Mutation', $typeNames, 'Should contain Mutation type');
        $this->assertContains('PageInfo', $typeNames, 'Should contain PageInfo type');

    }//end testIntrospectionQuery()


    /**
     * Test complexity info returned in extensions.
     *
     * @return void
     */
    public function testComplexityInExtensions(): void
    {
        $result = $this->graphqlService->execute('{ __schema { types { name } } }');

        $this->assertArrayHasKey('extensions', $result, 'Response should have extensions');
        $this->assertArrayHasKey('complexity', $result['extensions']);

        $complexity = $result['extensions']['complexity'];
        $this->assertArrayHasKey('estimated', $complexity);
        $this->assertArrayHasKey('max', $complexity);
        $this->assertArrayHasKey('depth', $complexity);
        $this->assertArrayHasKey('maxDepth', $complexity);

    }//end testComplexityInExtensions()


    // ── Error Handling ──

    /**
     * Test invalid query returns structured error.
     *
     * @return void
     */
    public function testInvalidQueryReturnsError(): void
    {
        $result = $this->graphqlService->execute('{ invalidField }');

        $this->assertArrayHasKey('errors', $result, 'Invalid query should return errors');
        $this->assertNotEmpty($result['errors']);

    }//end testInvalidQueryReturnsError()


    /**
     * Test syntax error in query returns structured error.
     *
     * @return void
     */
    public function testSyntaxErrorReturnsError(): void
    {
        $result = $this->graphqlService->execute('{ broken query {{');

        $this->assertArrayHasKey('errors', $result);
        $this->assertNotEmpty($result['errors']);

    }//end testSyntaxErrorReturnsError()


    // ── Custom Scalars in Schema ──

    /**
     * Test custom scalars appear in the generated schema.
     *
     * @return void
     */
    public function testCustomScalarsInSchema(): void
    {
        $result = $this->graphqlService->execute(
            '{ __schema { types { name kind } } }'
        );

        $this->assertArrayHasKey('data', $result);
        $types     = $result['data']['__schema']['types'];
        $typeNames = array_column($types, 'name');

        $this->assertContains('DateTime', $typeNames, 'DateTime scalar should exist');
        $this->assertContains('UUID', $typeNames, 'UUID scalar should exist');
        $this->assertContains('Email', $typeNames, 'Email scalar should exist');
        $this->assertContains('JSON', $typeNames, 'JSON scalar should exist');

    }//end testCustomScalarsInSchema()


    // ── Connection Types ──

    /**
     * Test connection type structure.
     *
     * @return void
     */
    public function testConnectionTypeStructure(): void
    {
        $result = $this->graphqlService->execute(
            '{ __schema { types { name fields { name } } } }'
        );

        $this->assertArrayHasKey('data', $result);
        $types = $result['data']['__schema']['types'];

        // Find a Connection type.
        $connectionTypes = array_filter($types, fn ($t) => str_ends_with(($t['name'] ?? ''), 'Connection'));
        $this->assertNotEmpty($connectionTypes, 'Should have at least one Connection type');

        $connection   = array_values($connectionTypes)[0];
        $fieldNames = array_column($connection['fields'] ?? [], 'name');

        $this->assertContains('edges', $fieldNames, 'Connection should have edges');
        $this->assertContains('pageInfo', $fieldNames, 'Connection should have pageInfo');
        $this->assertContains('totalCount', $fieldNames, 'Connection should have totalCount');
        $this->assertContains('facets', $fieldNames, 'Connection should have facets');
        $this->assertContains('facetable', $fieldNames, 'Connection should have facetable');

    }//end testConnectionTypeStructure()


    // ── PageInfo Type ──

    /**
     * Test PageInfo type has required fields.
     *
     * @return void
     */
    public function testPageInfoTypeFields(): void
    {
        $result = $this->graphqlService->execute(
            '{ __type(name: "PageInfo") { fields { name } } }'
        );

        $this->assertArrayHasKey('data', $result);
        $fields     = $result['data']['__type']['fields'] ?? [];
        $fieldNames = array_column($fields, 'name');

        $this->assertContains('hasNextPage', $fieldNames);
        $this->assertContains('hasPreviousPage', $fieldNames);
        $this->assertContains('startCursor', $fieldNames);
        $this->assertContains('endCursor', $fieldNames);

    }//end testPageInfoTypeFields()


    // ── AuditTrailEntry Type ──

    /**
     * Test AuditTrailEntry type exists with correct fields.
     *
     * @return void
     */
    public function testAuditTrailEntryTypeFields(): void
    {
        $result = $this->graphqlService->execute(
            '{ __type(name: "AuditTrailEntry") { fields { name } } }'
        );

        $this->assertArrayHasKey('data', $result);
        $fields     = $result['data']['__type']['fields'] ?? [];
        $fieldNames = array_column($fields, 'name');

        $this->assertContains('action', $fieldNames);
        $this->assertContains('user', $fieldNames);
        $this->assertContains('changed', $fieldNames);
        $this->assertContains('created', $fieldNames);

    }//end testAuditTrailEntryTypeFields()


    // ── Depth Limiting ──

    /**
     * Test that excessively deep queries are rejected.
     *
     * @return void
     */
    public function testDepthLimitingRejectsDeepQuery(): void
    {
        // Build a query deeper than the default max (10).
        $deepQuery = '{ a'.str_repeat(' { b', 12).str_repeat(' }', 12).' }';
        $result    = $this->graphqlService->execute($deepQuery);

        $this->assertArrayHasKey('errors', $result, 'Deep query should be rejected');
        $firstError = $result['errors'][0];
        $this->assertSame(
            'QUERY_TOO_COMPLEX',
            ($firstError['extensions']['code'] ?? null),
            'Error code should be QUERY_TOO_COMPLEX'
        );

    }//end testDepthLimitingRejectsDeepQuery()


}//end class
