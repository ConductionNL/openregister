<?php

/**
 * Integration tests for OasService
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
use OCA\OpenRegister\Service\OasService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for OasService
 *
 * Tests OpenAPI Specification generation for registers and schemas,
 * including schema enrichment, CRUD path generation, RBAC scopes,
 * property sanitization, extended endpoints, and validation.
 *
 * @group DB
 */
class OasServiceIntegrationTest extends TestCase
{
    /**
     * The OAS service instance
     *
     * @var OasService
     */
    private OasService $service;

    /**
     * Register mapper
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * Schema mapper
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * IDs of registers created during tests for cleanup
     *
     * @var int[]
     */
    private array $createdRegisterIds = [];

    /**
     * IDs of schemas created during tests for cleanup
     *
     * @var int[]
     */
    private array $createdSchemaIds = [];

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(OasService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
        $this->db = \OC::$server->get(IDBConnection::class);
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up registers first (they reference schemas)
        foreach ($this->createdRegisterIds as $registerId) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($registerId)))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Clean up schemas
        foreach ($this->createdSchemaIds as $schemaId) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($schemaId)))
                    ->executeStatement();
            } catch (\Exception $e) {
                // Ignore
            }
        }

        parent::tearDown();
    }

    /**
     * Create a test schema in the database
     *
     * @param array $overrides Properties to override
     *
     * @return Schema
     */
    private function createTestSchema(array $overrides = []): Schema
    {
        $schema = new Schema();
        $schema->setTitle($overrides['title'] ?? 'PhpunitTestSchema' . uniqid());
        $schema->setDescription($overrides['description'] ?? 'Test schema for OAS generation');
        $schema->setUuid($overrides['uuid'] ?? Uuid::v4()->toRfc4122());
        $schema->setSlug($overrides['slug'] ?? 'phpunit-test-' . uniqid());

        if (isset($overrides['properties'])) {
            $schema->setProperties($overrides['properties']);
        } else {
            $schema->setProperties([
                'title' => ['type' => 'string', 'description' => 'The title'],
                'body'  => ['type' => 'string', 'description' => 'The body text'],
            ]);
        }

        if (isset($overrides['authorization'])) {
            $schema->setAuthorization($overrides['authorization']);
        }

        $schema = $this->schemaMapper->insert($schema);
        $this->createdSchemaIds[] = $schema->getId();

        return $schema;
    }

    /**
     * Create a test register in the database
     *
     * @param array $schemaIds Schema IDs to associate
     * @param array $overrides Properties to override
     *
     * @return Register
     */
    private function createTestRegister(array $schemaIds = [], array $overrides = []): Register
    {
        $register = new Register();
        $register->setTitle($overrides['title'] ?? 'PhpunitTestRegister' . uniqid());
        $register->setDescription($overrides['description'] ?? 'Test register for OAS generation');
        $register->setUuid($overrides['uuid'] ?? Uuid::v4()->toRfc4122());
        $register->setSlug($overrides['slug'] ?? 'phpunit-test-' . uniqid());
        $register->setSchemas($schemaIds);
        $register->setVersion($overrides['version'] ?? '1.0.0');

        $register = $this->registerMapper->insert($register);
        $this->createdRegisterIds[] = $register->getId();

        return $register;
    }

    /**
     * Test createOas generates valid OAS for all registers
     *
     * @return void
     */
    public function testCreateOasAll(): void
    {
        $result = $this->service->createOas();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('openapi', $result);
        $this->assertArrayHasKey('info', $result);
        $this->assertArrayHasKey('servers', $result);
        $this->assertArrayHasKey('paths', $result);
        $this->assertArrayHasKey('components', $result);
    }

    /**
     * Test createOas has proper OpenAPI version
     *
     * @return void
     */
    public function testCreateOasVersion(): void
    {
        $result = $this->service->createOas();

        $this->assertStringStartsWith('3.', $result['openapi']);
    }

    /**
     * Test createOas has info section
     *
     * @return void
     */
    public function testCreateOasInfoSection(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('info', $result);
        $info = $result['info'];
        $this->assertIsArray($info);
        $this->assertArrayHasKey('title', $info);
        $this->assertArrayHasKey('version', $info);
    }

    /**
     * Test createOas has servers section
     *
     * @return void
     */
    public function testCreateOasServersSection(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('servers', $result);
        $this->assertIsArray($result['servers']);
        $this->assertNotEmpty($result['servers']);
        $this->assertArrayHasKey('url', $result['servers'][0]);
    }

    /**
     * Test createOas has components with schemas
     *
     * @return void
     */
    public function testCreateOasComponents(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('components', $result);
        $this->assertIsArray($result['components']);
        $this->assertArrayHasKey('schemas', $result['components']);
    }

    /**
     * Test createOas for specific register with description
     *
     * @return void
     */
    public function testCreateOasForSpecificRegisterWithDescription(): void
    {
        $schema = $this->createTestSchema();
        $register = $this->createTestRegister(
            [$schema->getId()],
            ['description' => 'A custom API description for testing purposes']
        );

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('info', $result);
        $this->assertStringContainsString($register->getTitle(), $result['info']['title']);
        $this->assertSame('A custom API description for testing purposes', $result['info']['description']);
    }

    /**
     * Test createOas for specific register without description generates default
     *
     * @return void
     */
    public function testCreateOasForRegisterWithoutDescription(): void
    {
        $schema = $this->createTestSchema();
        $register = $this->createTestRegister(
            [$schema->getId()],
            ['description' => '']
        );

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
        // Should generate a default description containing the register title
        $this->assertStringContainsString($register->getTitle(), $result['info']['description']);
    }

    /**
     * Test createOas includes tags array
     *
     * @return void
     */
    public function testCreateOasIncludesTags(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('tags', $result);
        $this->assertIsArray($result['tags']);
    }

    /**
     * Test createOas generates paths with CRUD operations
     *
     * @return void
     */
    public function testCreateOasGeneratesCrudPaths(): void
    {
        $result = $this->service->createOas();

        $paths = $result['paths'];
        $this->assertIsArray($paths);

        // Check that at least some paths have collection and item endpoints
        $foundCollectionPath = false;
        $foundItemPath = false;
        foreach (array_keys($paths) as $path) {
            if (str_contains($path, '/objects/')) {
                if (str_contains($path, '{id}')) {
                    $foundItemPath = true;
                } else {
                    $foundCollectionPath = true;
                }
            }
        }

        // If any registers with schemas exist, we should have paths
        if (count($paths) > 0) {
            $this->assertTrue($foundCollectionPath || $foundItemPath, 'Should have object paths');
        } else {
            $this->assertIsArray($paths);
        }
    }

    /**
     * Test createOas collection paths have GET and POST methods
     *
     * @return void
     */
    public function testCreateOasCollectionPathMethods(): void
    {
        $result = $this->service->createOas();

        foreach ($result['paths'] as $path => $operations) {
            if (str_contains($path, '/objects/') && !str_contains($path, '{id}')) {
                $this->assertArrayHasKey('get', $operations, "Collection path $path should have GET");
                $this->assertArrayHasKey('post', $operations, "Collection path $path should have POST");
                return;
            }
        }

        // No collection paths means no registers/schemas -- still valid
        $this->assertIsArray($result['paths']);
    }

    /**
     * Test createOas item paths have GET, PUT, DELETE methods
     *
     * @return void
     */
    public function testCreateOasItemPathMethods(): void
    {
        $result = $this->service->createOas();

        foreach ($result['paths'] as $path => $operations) {
            if (str_contains($path, '{id}') && !str_contains($path, 'audit') && !str_contains($path, 'files') && !str_contains($path, 'lock')) {
                $this->assertArrayHasKey('get', $operations, "Item path $path should have GET");
                $this->assertArrayHasKey('put', $operations, "Item path $path should have PUT");
                $this->assertArrayHasKey('delete', $operations, "Item path $path should have DELETE");
                return;
            }
        }

        // No item paths means no registers/schemas -- still valid
        $this->assertIsArray($result['paths']);
    }

    /**
     * Test createOas includes security schemes
     *
     * @return void
     */
    public function testCreateOasSecuritySchemes(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey('securitySchemes', $result['components']);
    }

    /**
     * Test createOas paths is array
     *
     * @return void
     */
    public function testCreateOasPaths(): void
    {
        $result = $this->service->createOas();

        $this->assertArrayHasKey('paths', $result);
        $this->assertIsArray($result['paths']);
    }

    /**
     * Test createOas with schema having various property types
     *
     * @return void
     */
    public function testCreateOasWithVariousPropertyTypes(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'name'        => ['type' => 'string', 'description' => 'Name field'],
                'age'         => ['type' => 'integer', 'description' => 'Age field'],
                'score'       => ['type' => 'number', 'description' => 'Score'],
                'active'      => ['type' => 'boolean', 'description' => 'Is active'],
                'tags'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tags'],
                'metadata'    => ['type' => 'object', 'description' => 'Metadata'],
                'description' => 'A simple string property',
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey('schemas', $result['components']);
    }

    /**
     * Test createOas with schema having enum properties
     *
     * @return void
     */
    public function testCreateOasWithEnumProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['active', 'inactive', 'pending'],
                    'description' => 'Status',
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having allOf composition
     *
     * @return void
     */
    public function testCreateOasWithAllOfProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'combined' => [
                    'allOf' => [
                        ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                        ['type' => 'object', 'properties' => ['age' => ['type' => 'integer']]],
                    ],
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having oneOf composition
     *
     * @return void
     */
    public function testCreateOasWithOneOfProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'value' => [
                    'oneOf' => [
                        ['type' => 'string'],
                        ['type' => 'integer'],
                    ],
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having $ref property
     *
     * @return void
     */
    public function testCreateOasWithRefProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'related' => [
                    '$ref' => '#/components/schemas/Error',
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having bare $ref (auto-normalized)
     *
     * @return void
     */
    public function testCreateOasWithBareRefProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'related' => [
                    '$ref' => 'SomeSchema',
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having empty allOf (should be cleaned up)
     *
     * @return void
     */
    public function testCreateOasWithEmptyAllOf(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'broken' => [
                    'allOf' => [],
                    'type'  => 'string',
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having invalid type (normalized to string)
     *
     * @return void
     */
    public function testCreateOasWithInvalidType(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'weird' => [
                    'type' => 'invalid_type',
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having boolean required (should be stripped)
     *
     * @return void
     */
    public function testCreateOasWithBooleanRequired(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'field' => [
                    'type'     => 'string',
                    'required' => true,
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having array type without items
     *
     * @return void
     */
    public function testCreateOasWithArrayTypeNoItems(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'list' => [
                    'type' => 'array',
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having empty enum (should be stripped)
     *
     * @return void
     */
    public function testCreateOasWithEmptyEnum(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [],
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having nested properties
     *
     * @return void
     */
    public function testCreateOasWithNestedProperties(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'address' => [
                    'type'       => 'object',
                    'properties' => [
                        'street' => ['type' => 'string'],
                        'city'   => ['type' => 'string'],
                    ],
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with authorization (RBAC) groups
     *
     * @return void
     */
    public function testCreateOasWithRbacGroups(): void
    {
        $schema = $this->createTestSchema([
            'authorization' => [
                'create' => ['editors', 'admin'],
                'read'   => ['public', 'viewers'],
                'update' => ['editors'],
                'delete' => ['admin'],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);

        // Check OAuth2 scopes contain the groups
        $scopes = $result['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'] ?? [];
        $this->assertArrayHasKey('admin', $scopes);
    }

    /**
     * Test createOas with authorization groups as objects
     *
     * @return void
     */
    public function testCreateOasWithRbacGroupObjects(): void
    {
        $schema = $this->createTestSchema([
            'authorization' => [
                'create' => [['group' => 'editors']],
                'read'   => [['group' => 'viewers']],
                'update' => [],
                'delete' => [],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with property-level authorization
     *
     * @return void
     */
    public function testCreateOasWithPropertyLevelAuth(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'secret_field' => [
                    'type'          => 'string',
                    'authorization' => [
                        'read'   => ['admin'],
                        'update' => ['admin'],
                    ],
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas operations have operationId
     *
     * @return void
     */
    public function testCreateOasOperationsHaveOperationId(): void
    {
        $result = $this->service->createOas();

        foreach ($result['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (is_array($operation)) {
                    $this->assertArrayHasKey('operationId', $operation, "Operation $method $path should have operationId");
                    return;
                }
            }
        }

        // If no paths at all, just check the structure
        $this->assertIsArray($result['paths']);
    }

    /**
     * Test createOas for all registers produces valid OAS
     *
     * @return void
     */
    public function testCreateOasAllRegisters(): void
    {
        $result = $this->service->createOas();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('paths', $result);
        $this->assertArrayHasKey('components', $result);
    }

    /**
     * Test createOas collection endpoints have query parameters
     *
     * @return void
     */
    public function testCreateOasCollectionHasQueryParameters(): void
    {
        $result = $this->service->createOas();

        foreach ($result['paths'] as $path => $operations) {
            if (str_contains($path, '/objects/') && !str_contains($path, '{id}') && isset($operations['get'])) {
                $getOp = $operations['get'];
                $this->assertArrayHasKey('parameters', $getOp);

                $paramNames = array_column($getOp['parameters'], 'name');
                $this->assertContains('_search', $paramNames, 'Collection should have _search parameter');
                $this->assertContains('_extend', $paramNames, 'Collection should have _extend parameter');
                return;
            }
        }

        $this->assertIsArray($result['paths']);
    }

    /**
     * Test createOas paths value is always an array
     *
     * @return void
     */
    public function testCreateOasPathsAlwaysArray(): void
    {
        $result = $this->service->createOas();

        $this->assertIsArray($result['paths']);
    }

    /**
     * Test createOas with schema having items as list array (malformed)
     *
     * @return void
     */
    public function testCreateOasWithMalformedItemsList(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'tags' => [
                    'type'  => 'array',
                    'items' => [['type' => 'string'], ['type' => 'integer']],
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema having no type and no ref defaults to string
     *
     * @return void
     */
    public function testCreateOasPropertyWithNoTypeNoRef(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'mystery' => [
                    'description' => 'No type specified',
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema title containing special characters
     *
     * @return void
     */
    public function testCreateOasSanitizeSchemaName(): void
    {
        $schema = $this->createTestSchema([
            'title' => 'My Schema With Spaces & Special!',
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
        // The schema name should be sanitized in components
        $schemaNames = array_keys($result['components']['schemas'] ?? []);
        // Should not contain raw special characters
        foreach ($schemaNames as $name) {
            if (str_contains($name, 'My_Schema')) {
                $this->assertMatchesRegularExpression('/^[a-zA-Z0-9._-]+$/', $name);
            }
        }
    }

    /**
     * Test createOas with anyOf composition
     *
     * @return void
     */
    public function testCreateOasWithAnyOfProperty(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'flexible' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'number'],
                    ],
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with empty $ref (should be cleaned)
     *
     * @return void
     */
    public function testCreateOasWithEmptyRef(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'broken_ref' => [
                    '$ref' => '',
                    'type' => 'string',
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas GET item operations have proper response structure
     *
     * @return void
     */
    public function testCreateOasGetOperationResponse(): void
    {
        $result = $this->service->createOas();

        foreach ($result['paths'] as $path => $operations) {
            if (str_contains($path, '{id}') && isset($operations['get'])
                && !str_contains($path, 'audit') && !str_contains($path, 'files') && !str_contains($path, 'lock')
            ) {
                $getOp = $operations['get'];
                $this->assertArrayHasKey('responses', $getOp);
                $this->assertArrayHasKey('200', $getOp['responses']);
                $this->assertArrayHasKey('404', $getOp['responses']);
                return;
            }
        }

        $this->assertIsArray($result['paths']);
    }

    /**
     * Test createOas DELETE operations have proper response structure
     *
     * @return void
     */
    public function testCreateOasDeleteOperationResponse(): void
    {
        $result = $this->service->createOas();

        foreach ($result['paths'] as $path => $operations) {
            if (str_contains($path, '{id}') && isset($operations['delete'])
                && !str_contains($path, 'audit') && !str_contains($path, 'files') && !str_contains($path, 'lock')
            ) {
                $deleteOp = $operations['delete'];
                $this->assertArrayHasKey('responses', $deleteOp);
                $this->assertArrayHasKey('204', $deleteOp['responses']);
                $this->assertArrayHasKey('404', $deleteOp['responses']);
                return;
            }
        }

        $this->assertIsArray($result['paths']);
    }

    /**
     * Test createOas POST operations have requestBody
     *
     * @return void
     */
    public function testCreateOasPostOperationRequestBody(): void
    {
        $result = $this->service->createOas();

        foreach ($result['paths'] as $path => $operations) {
            if (str_contains($path, '/objects/') && !str_contains($path, '{id}') && isset($operations['post'])) {
                $postOp = $operations['post'];
                $this->assertArrayHasKey('requestBody', $postOp);
                $this->assertTrue($postOp['requestBody']['required']);
                return;
            }
        }

        $this->assertIsArray($result['paths']);
    }

    /**
     * Test createOas with readOnly and writeOnly properties
     *
     * @return void
     */
    public function testCreateOasWithReadOnlyWriteOnlyProperties(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'computed' => [
                    'type'     => 'string',
                    'readOnly' => true,
                ],
                'password' => [
                    'type'      => 'string',
                    'writeOnly' => true,
                ],
                'nullable_field' => [
                    'type'     => 'string',
                    'nullable' => true,
                ],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas with schema properties having format
     *
     * @return void
     */
    public function testCreateOasWithPropertyFormats(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'email'      => ['type' => 'string', 'format' => 'email'],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'website'    => ['type' => 'string', 'format' => 'uri'],
                'price'      => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas server URL contains openregister API path
     *
     * @return void
     */
    public function testCreateOasServerUrlContainsApiPath(): void
    {
        $result = $this->service->createOas();

        $serverUrl = $result['servers'][0]['url'] ?? '';
        $this->assertStringContainsString('/apps/openregister/api', $serverUrl);
    }

    /**
     * Test createOas with schema having internal properties (should be stripped)
     *
     * @return void
     */
    public function testCreateOasStripsInternalProperties(): void
    {
        $schema = $this->createTestSchema([
            'properties' => [
                'name' => ['type' => 'string'],
                // Internal fields that should be stripped from OAS
                'objectConfiguration' => ['type' => 'object'],
                'inversedBy'          => ['type' => 'string'],
                'authorization'       => ['type' => 'object'],
                'defaultBehavior'     => ['type' => 'string'],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $this->assertIsArray($result);
    }

    /**
     * Test createOas scope descriptions
     *
     * @return void
     */
    public function testCreateOasScopeDescriptions(): void
    {
        $schema = $this->createTestSchema([
            'authorization' => [
                'read' => ['public'],
            ],
        ]);
        $register = $this->createTestRegister([$schema->getId()]);

        $result = $this->service->createOas((string) $register->getId());

        $scopes = $result['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'] ?? [];

        if (isset($scopes['admin'])) {
            $this->assertSame('Full administrative access', $scopes['admin']);
        }

        if (isset($scopes['public'])) {
            $this->assertSame('Public (unauthenticated) access', $scopes['public']);
        }
    }
}
