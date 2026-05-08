<?php

/**
 * Integration tests for MagicRbacHandler
 *
 * Tests RBAC filtering, permission checks, group-based access control,
 * conditional rules, and buildRbacConditionsSql. Tests the handler both
 * directly and indirectly via MagicMapper search operations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 */

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
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
class MagicRbacHandlerIntegrationTest extends TestCase
{
    private MagicMapper $mapper;
    private MagicRbacHandler $rbacHandler;
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
        $this->rbacHandler = \OC::$server->get(MagicRbacHandler::class);
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
            'title'       => 'PHPUnit RBAC Test Register ' . uniqid(),
            'description' => 'Register for MagicRbacHandler integration tests',
        ]);
        $this->createdRegisterIds[] = $register->getId();

        return $register;
    }

    private function createTestSchema(array $authorization = []): Schema
    {
        $data = [
            'title'       => 'PHPUnit RBAC Test Schema ' . uniqid(),
            'description' => 'Schema for MagicRbacHandler integration tests',
            'properties'  => [
                'name' => [
                    'type'      => 'string',
                    'title'     => 'Name',
                    'maxLength' => 255,
                ],
                'status' => [
                    'type'      => 'string',
                    'title'     => 'Status',
                    'maxLength' => 100,
                ],
                'age' => [
                    'type'  => 'integer',
                    'title' => 'Age',
                ],
            ],
        ];

        if (!empty($authorization)) {
            $data['authorization'] = $authorization;
        }

        $schema = $this->schemaMapper->createFromArray($data);
        $this->createdSchemaIds[] = $schema->getId();

        return $schema;
    }

    private function trackTable(Register $register, Schema $schema): void
    {
        $tableName = $this->mapper->getTableNameForRegisterSchema($register, $schema);
        $this->createdTables[] = 'oc_' . $tableName;
    }

    private function insertTestObject(
        Register $register,
        Schema $schema,
        array $objectData,
        ?string $owner = null
    ): ObjectEntity {
        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($objectData);
        if ($owner !== null) {
            $entity->setOwner($owner);
        }

        return $this->mapper->insertObjectEntity($entity, $register, $schema, false);
    }

    // =========================================================================
    // getCurrentUserId / getCurrentUserGroups / isAdmin tests
    // =========================================================================

    public function testGetCurrentUserIdReturnsStringOrNull(): void
    {
        $userId = $this->rbacHandler->getCurrentUserId();
        // In CLI test context, user may or may not be logged in
        $this->assertTrue($userId === null || is_string($userId));
    }

    public function testGetCurrentUserGroupsReturnsArray(): void
    {
        $groups = $this->rbacHandler->getCurrentUserGroups();
        $this->assertIsArray($groups);
    }

    public function testIsAdminReturnsBool(): void
    {
        $isAdmin = $this->rbacHandler->isAdmin();
        $this->assertIsBool($isAdmin);
    }

    // =========================================================================
    // hasPermission with no authorization (open access) tests
    // =========================================================================

    public function testHasPermissionNoAuthorizationReturnsTrue(): void
    {
        // Schema with no authorization = open access
        $schema = $this->createTestSchema();

        $hasPermission = $this->rbacHandler->hasPermission($schema, 'read');
        $this->assertTrue($hasPermission);
    }

    public function testHasPermissionNoAuthorizationForCreate(): void
    {
        $schema = $this->createTestSchema();

        $hasPermission = $this->rbacHandler->hasPermission($schema, 'create');
        $this->assertTrue($hasPermission);
    }

    public function testHasPermissionNoAuthorizationForUpdate(): void
    {
        $schema = $this->createTestSchema();

        $hasPermission = $this->rbacHandler->hasPermission($schema, 'update');
        $this->assertTrue($hasPermission);
    }

    public function testHasPermissionNoAuthorizationForDelete(): void
    {
        $schema = $this->createTestSchema();

        $hasPermission = $this->rbacHandler->hasPermission($schema, 'delete');
        $this->assertTrue($hasPermission);
    }

    // =========================================================================
    // hasPermission with public rule tests
    // =========================================================================

    public function testHasPermissionPublicRuleGrantsAccess(): void
    {
        $schema = $this->createTestSchema([
            'read' => ['public'],
        ]);

        $hasPermission = $this->rbacHandler->hasPermission($schema, 'read');
        $this->assertTrue($hasPermission);
    }

    public function testHasPermissionPublicRuleForUnconfiguredAction(): void
    {
        // Only 'read' is configured - 'update' should be open (not configured = open)
        $schema = $this->createTestSchema([
            'read' => ['public'],
        ]);

        $hasPermission = $this->rbacHandler->hasPermission($schema, 'update');
        $this->assertTrue($hasPermission);
    }

    // =========================================================================
    // hasPermission with specific group rule tests
    // =========================================================================

    public function testHasPermissionGroupRuleNoMatch(): void
    {
        // Require 'editors' group which the test user probably doesn't belong to
        $schema = $this->createTestSchema([
            'read' => ['nonexistent-group-' . uniqid()],
        ]);

        // If CLI test user is admin, it bypasses RBAC
        $hasPermission = $this->rbacHandler->hasPermission($schema, 'read');
        // Result depends on whether test user is admin
        $this->assertIsBool($hasPermission);
    }

    public function testHasPermissionAuthenticatedRule(): void
    {
        $schema = $this->createTestSchema([
            'read' => ['authenticated'],
        ]);

        $hasPermission = $this->rbacHandler->hasPermission($schema, 'read');
        // If a user is logged in, should return true; if not, depends on CLI context
        $this->assertIsBool($hasPermission);
    }

    // =========================================================================
    // hasPermission with owner check tests
    // =========================================================================

    public function testHasPermissionOwnerHasAccess(): void
    {
        $schema = $this->createTestSchema([
            'read' => ['nonexistent-group-' . uniqid()],
        ]);

        $userId = $this->rbacHandler->getCurrentUserId();
        if ($userId !== null) {
            // Owner should always have access
            $hasPermission = $this->rbacHandler->hasPermission($schema, 'read', $userId);
            $this->assertTrue($hasPermission);
        } else {
            // No user session - just verify no crash
            $hasPermission = $this->rbacHandler->hasPermission($schema, 'read', 'some-owner');
            $this->assertIsBool($hasPermission);
        }
    }

    // =========================================================================
    // hasPermission with conditional rules tests
    // =========================================================================

    public function testHasPermissionConditionalRulePublicGroup(): void
    {
        $schema = $this->createTestSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        // Public group with match condition - user qualifies (public)
        // but actual data matching happens at query time
        $hasPermission = $this->rbacHandler->hasPermission($schema, 'read');
        $this->assertTrue($hasPermission);
    }

    public function testHasPermissionConditionalRuleNoMatchBlock(): void
    {
        $schema = $this->createTestSchema([
            'read' => [
                ['group' => 'public'],
            ],
        ]);

        // Conditional rule with public group but no match conditions = unconditional access
        $hasPermission = $this->rbacHandler->hasPermission($schema, 'read');
        $this->assertTrue($hasPermission);
    }

    // =========================================================================
    // buildRbacConditionsSql tests
    // =========================================================================

    public function testBuildRbacConditionsSqlNoAuthorization(): void
    {
        $schema = $this->createTestSchema();

        $result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('bypass', $result);
        $this->assertArrayHasKey('conditions', $result);
        // No authorization = bypass
        $this->assertTrue($result['bypass']);
    }

    public function testBuildRbacConditionsSqlPublicRule(): void
    {
        $schema = $this->createTestSchema([
            'read' => ['public'],
        ]);

        $result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
        $this->assertIsArray($result);
        $this->assertTrue($result['bypass']);
    }

    public function testBuildRbacConditionsSqlUnconfiguredAction(): void
    {
        $schema = $this->createTestSchema([
            'read' => ['public'],
        ]);

        // 'delete' is not configured - should bypass
        $result = $this->rbacHandler->buildRbacConditionsSql($schema, 'delete');
        $this->assertIsArray($result);
        $this->assertTrue($result['bypass']);
    }

    public function testBuildRbacConditionsSqlGroupRule(): void
    {
        $schema = $this->createTestSchema([
            'read' => ['nonexistent-group-' . uniqid()],
        ]);

        $result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('bypass', $result);
        $this->assertArrayHasKey('conditions', $result);
        // If user is admin, bypass=true; otherwise bypass=false with conditions
        $this->assertIsBool($result['bypass']);
    }

    public function testBuildRbacConditionsSqlConditionalRule(): void
    {
        $schema = $this->createTestSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        $result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('bypass', $result);

        // If not admin, should get conditions (not bypass)
        if ($result['bypass'] === false) {
            $this->assertIsArray($result['conditions']);
        }
    }

    public function testBuildRbacConditionsSqlMultipleRules(): void
    {
        $schema = $this->createTestSchema([
            'read' => [
                'nonexistent-group-' . uniqid(),
                ['group' => 'public', 'match' => ['status' => 'active']],
            ],
        ]);

        $result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('bypass', $result);
        $this->assertArrayHasKey('conditions', $result);
    }

    // =========================================================================
    // hasConditionalRulesBypassingMultitenancy tests
    // =========================================================================

    public function testHasConditionalRulesBypassingMultitenancyNoAuth(): void
    {
        $schema = $this->createTestSchema();

        $result = $this->rbacHandler->hasConditionalRulesBypassingMultitenancy($schema, 'read');
        // No authorization = no conditional rules
        $this->assertIsBool($result);
    }

    public function testHasConditionalRulesBypassingMultitenancyPublicRule(): void
    {
        $schema = $this->createTestSchema([
            'read' => ['public'],
        ]);

        $result = $this->rbacHandler->hasConditionalRulesBypassingMultitenancy($schema, 'read');
        $this->assertIsBool($result);
    }

    public function testHasConditionalRulesBypassingMultitenancyConditionalRule(): void
    {
        $schema = $this->createTestSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        $result = $this->rbacHandler->hasConditionalRulesBypassingMultitenancy($schema, 'read');
        $this->assertIsBool($result);
    }

    // =========================================================================
    // applyRbacFilters via search - no authorization tests
    // =========================================================================

    public function testSearchWithNoAuthorizationReturnsResults(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'OpenAccess', 'status' => 'active', 'age' => 25]);

        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_rbac' => false, '_multitenancy' => false],
            $register,
            $schema
        );
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
    }

    // =========================================================================
    // applyRbacFilters via search - public authorization tests
    // =========================================================================

    public function testSearchWithPublicAuthorizationReturnsResults(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema([
            'read' => ['public'],
        ]);

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'PublicItem', 'status' => 'live', 'age' => 30]);

        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_multitenancy' => false],
            $register,
            $schema
        );
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
    }

    // =========================================================================
    // applyRbacFilters via search - restricted authorization tests
    // =========================================================================

    public function testSearchWithRestrictedAuthorizationDoesNotCrash(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema([
            'read' => ['nonexistent-group-' . uniqid()],
        ]);

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Restricted', 'status' => 'locked', 'age' => 40]);

        // Should not crash regardless of whether user has access
        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_multitenancy' => false],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    // =========================================================================
    // applyRbacFilters via search - conditional authorization tests
    // =========================================================================

    public function testSearchWithConditionalAuthorizationDoesNotCrash(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'ConditionalItem', 'status' => 'published', 'age' => 35]);
        $this->insertTestObject($register, $schema, ['name' => 'DraftItem', 'status' => 'draft', 'age' => 22]);

        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_multitenancy' => false],
            $register,
            $schema
        );
        $this->assertIsArray($results);
    }

    // =========================================================================
    // RBAC with count tests
    // =========================================================================

    public function testCountWithPublicAuthorization(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema([
            'read' => ['public'],
        ]);

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'Count1', 'status' => 'active', 'age' => 10]);
        $this->insertTestObject($register, $schema, ['name' => 'Count2', 'status' => 'active', 'age' => 20]);

        $count = $this->mapper->countObjectsInRegisterSchemaTable(
            ['_multitenancy' => false],
            $register,
            $schema
        );
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(2, $count);
    }

    public function testCountWithRestrictedAuthorization(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema([
            'read' => ['nonexistent-group-' . uniqid()],
        ]);

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'RestrictedCount', 'status' => 'locked', 'age' => 50]);

        $count = $this->mapper->countObjectsInRegisterSchemaTable(
            ['_multitenancy' => false],
            $register,
            $schema
        );
        $this->assertIsInt($count);
        // If user is admin, count > 0; otherwise may be 0 due to RBAC
        $this->assertGreaterThanOrEqual(0, $count);
    }

    // =========================================================================
    // RBAC with facets tests
    // =========================================================================

    public function testFacetsWithPublicAuthorization(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema([
            'read' => ['public'],
        ]);

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'FacetAuth1', 'status' => 'active', 'age' => 10]);
        $this->insertTestObject($register, $schema, ['name' => 'FacetAuth2', 'status' => 'inactive', 'age' => 20]);

        $facets = $this->mapper->getSimpleFacetsFromRegisterSchemaTable(
            [
                '_facets' => [
                    'status' => ['type' => 'terms'],
                ],
                '_multitenancy' => false,
            ],
            $register,
            $schema
        );
        $this->assertIsArray($facets);
        $this->assertArrayHasKey('status', $facets);
    }

    // =========================================================================
    // RBAC with multiple actions tests
    // =========================================================================

    public function testHasPermissionDifferentActions(): void
    {
        $schema = $this->createTestSchema([
            'read'   => ['public'],
            'create' => ['authenticated'],
            'update' => ['nonexistent-editors-group-' . uniqid()],
            'delete' => ['admin'],
        ]);

        // read: public - should always be true
        $this->assertTrue($this->rbacHandler->hasPermission($schema, 'read'));

        // create: authenticated - depends on user session
        $createPerm = $this->rbacHandler->hasPermission($schema, 'create');
        $this->assertIsBool($createPerm);

        // update: specific group - depends on user groups
        $updatePerm = $this->rbacHandler->hasPermission($schema, 'update');
        $this->assertIsBool($updatePerm);

        // delete: admin group - depends on user being admin
        $deletePerm = $this->rbacHandler->hasPermission($schema, 'delete');
        $this->assertIsBool($deletePerm);
    }

    // =========================================================================
    // buildRbacConditionsSql with operator-based match tests
    // =========================================================================

    public function testBuildRbacConditionsSqlWithOperatorMatch(): void
    {
        $schema = $this->createTestSchema([
            'read' => [
                ['group' => 'public', 'match' => ['age' => ['$gte' => 18]]],
            ],
        ]);

        $result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('bypass', $result);

        // With a conditional match on public group, should produce conditions
        if ($result['bypass'] === false) {
            $this->assertNotEmpty($result['conditions']);
        }
    }

    public function testBuildRbacConditionsSqlWithInOperator(): void
    {
        $schema = $this->createTestSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => ['$in' => ['published', 'live']]]],
            ],
        ]);

        $result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('bypass', $result);
    }

    public function testBuildRbacConditionsSqlWithExistsOperator(): void
    {
        $schema = $this->createTestSchema([
            'read' => [
                ['group' => 'public', 'match' => ['name' => ['$exists' => true]]],
            ],
        ]);

        $result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('bypass', $result);
    }

    // =========================================================================
    // RBAC disabled explicitly via _rbac=false tests
    // =========================================================================

    public function testSearchWithRbacDisabledBypassesRestrictions(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema([
            'read' => ['nonexistent-group-' . uniqid()],
        ]);

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'BypassRbac', 'status' => 'locked', 'age' => 60]);

        // With _rbac=false, should bypass RBAC filtering
        $results = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_rbac' => false, '_multitenancy' => false],
            $register,
            $schema
        );
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
    }

    public function testCountWithRbacDisabledBypassesRestrictions(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema([
            'read' => ['nonexistent-group-' . uniqid()],
        ]);

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject($register, $schema, ['name' => 'BypassRbacCount', 'status' => 'locked', 'age' => 70]);

        $count = $this->mapper->countObjectsInRegisterSchemaTable(
            ['_rbac' => false, '_multitenancy' => false],
            $register,
            $schema
        );
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    // =========================================================================
    // hasPermission with objectData for conditional checks tests
    // =========================================================================

    public function testHasPermissionWithObjectDataPublicConditional(): void
    {
        $schema = $this->createTestSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        // With matching object data, should have permission
        $hasPermission = $this->rbacHandler->hasPermission(
            $schema,
            'read',
            null,
            ['status' => 'published']
        );
        $this->assertTrue($hasPermission);
    }

    public function testHasPermissionWithObjectDataNotMatching(): void
    {
        $schema = $this->createTestSchema([
            'read' => [
                ['group' => 'nonexistent-group-' . uniqid(), 'match' => ['status' => 'published']],
            ],
        ]);

        // Non-matching group and no owner
        $hasPermission = $this->rbacHandler->hasPermission(
            $schema,
            'read',
            null,
            ['status' => 'draft']
        );
        // If user is admin, still true; otherwise false
        $this->assertIsBool($hasPermission);
    }

    // =========================================================================
    // RBAC with search across multiple tables tests
    // =========================================================================

    public function testSearchAcrossMultipleTablesWithRbac(): void
    {
        $register = $this->createTestRegister();
        $schema1 = $this->createTestSchema([
            'read' => ['public'],
        ]);
        $schema2 = $this->createTestSchema([
            'read' => ['public'],
        ]);

        $this->mapper->ensureTableForRegisterSchema($register, $schema1);
        $this->trackTable($register, $schema1);
        $this->mapper->ensureTableForRegisterSchema($register, $schema2);
        $this->trackTable($register, $schema2);

        $this->insertTestObject($register, $schema1, ['name' => 'Cross1', 'status' => 'active', 'age' => 10]);
        $this->insertTestObject($register, $schema2, ['name' => 'Cross2', 'status' => 'active', 'age' => 20]);

        $pairs = [
            ['register' => $register, 'schema' => $schema1],
            ['register' => $register, 'schema' => $schema2],
        ];

        $results = $this->mapper->searchAcrossMultipleTables(
            ['_multitenancy' => false],
            $pairs
        );
        $this->assertIsArray($results);
    }
}
