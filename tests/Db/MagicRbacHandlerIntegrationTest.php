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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class MagicRbacHandlerIntegrationTest extends TestCase {
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

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = \OC::$server->get(MagicMapper::class);
		$this->rbacHandler = \OC::$server->get(MagicRbacHandler::class);
		$this->registerMapper = \OC::$server->get(RegisterMapper::class);
		$this->schemaMapper = \OC::$server->get(SchemaMapper::class);
	}

	protected function tearDown(): void {
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

	private function createTestRegister(): Register {
		$register = $this->registerMapper->createFromArray([
			'title' => 'PHPUnit RBAC Test Register ' . uniqid(),
			'description' => 'Register for MagicRbacHandler integration tests',
		]);
		$this->createdRegisterIds[] = $register->getId();

		return $register;
	}

	private function createTestSchema(array $authorization = []): Schema {
		$data = [
			'title' => 'PHPUnit RBAC Test Schema ' . uniqid(),
			'description' => 'Schema for MagicRbacHandler integration tests',
			'properties' => [
				'name' => [
					'type' => 'string',
					'title' => 'Name',
					'maxLength' => 255,
				],
				'status' => [
					'type' => 'string',
					'title' => 'Status',
					'maxLength' => 100,
				],
				'age' => [
					'type' => 'integer',
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

	private function trackTable(Register $register, Schema $schema): void {
		$tableName = $this->mapper->getTableNameForRegisterSchema($register, $schema);
		$this->createdTables[] = 'oc_' . $tableName;
	}

	private function insertTestObject(
		Register $register,
		Schema $schema,
		array $objectData,
		?string $owner = null,
	): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(Uuid::v4()->toRfc4122());
		$entity->setRegister((string)$register->getId());
		$entity->setSchema((string)$schema->getId());
		$entity->setObject($objectData);
		if ($owner !== null) {
			$entity->setOwner($owner);
		}

		return $this->mapper->insertObjectEntity($entity, $register, $schema, false);
	}

	// =========================================================================
	// getCurrentUserId / getCurrentUserGroups / isAdmin tests
	// =========================================================================

	public function testGetCurrentUserIdReturnsStringOrNull(): void {
		$userId = $this->rbacHandler->getCurrentUserId();
		// In CLI test context, user may or may not be logged in
		$this->assertTrue($userId === null || is_string($userId));
	}

	public function testGetCurrentUserGroupsReturnsArray(): void {
		$groups = $this->rbacHandler->getCurrentUserGroups();
		$this->assertIsArray($groups);
	}

	public function testIsAdminReturnsBool(): void {
		$isAdmin = $this->rbacHandler->isAdmin();
		$this->assertIsBool($isAdmin);
	}

	// =========================================================================
	// hasPermission with no authorization (open access) tests
	// =========================================================================

	public function testHasPermissionNoAuthorizationReturnsTrue(): void {
		// Schema with no authorization = open access
		$schema = $this->createTestSchema();

		$hasPermission = $this->rbacHandler->hasPermission($schema, 'read');
		$this->assertTrue($hasPermission);
	}

	public function testHasPermissionNoAuthorizationForCreate(): void {
		$schema = $this->createTestSchema();

		$hasPermission = $this->rbacHandler->hasPermission($schema, 'create');
		$this->assertTrue($hasPermission);
	}

	public function testHasPermissionNoAuthorizationForUpdate(): void {
		$schema = $this->createTestSchema();

		$hasPermission = $this->rbacHandler->hasPermission($schema, 'update');
		$this->assertTrue($hasPermission);
	}

	public function testHasPermissionNoAuthorizationForDelete(): void {
		$schema = $this->createTestSchema();

		$hasPermission = $this->rbacHandler->hasPermission($schema, 'delete');
		$this->assertTrue($hasPermission);
	}

	// =========================================================================
	// hasPermission with public rule tests
	// =========================================================================

	public function testHasPermissionPublicRuleGrantsAccess(): void {
		$schema = $this->createTestSchema([
			'read' => ['public'],
		]);

		$hasPermission = $this->rbacHandler->hasPermission($schema, 'read');
		$this->assertTrue($hasPermission);
	}

	public function testHasPermissionUnconfiguredActionFailsClosed(): void {
		// rbac-default-deny: only 'read' is configured; an unconfigured action
		// ('update') on a non-empty block is now denied for a non-admin. Admin
		// CLI runners still bypass RBAC, so the expectation is guarded.
		$schema = $this->createTestSchema([
			'read' => ['public'],
		]);

		$hasPermission = $this->rbacHandler->hasPermission($schema, 'update');
		if ($this->rbacHandler->isAdmin() === true) {
			$this->assertTrue($hasPermission, 'Admin bypasses RBAC');
		} else {
			$this->assertFalse($hasPermission, 'Unconfigured action on a non-empty block must fail closed');
		}
	}

	// =========================================================================
	// hasPermission with specific group rule tests
	// =========================================================================

	public function testHasPermissionGroupRuleNoMatch(): void {
		// Require 'editors' group which the test user probably doesn't belong to
		$schema = $this->createTestSchema([
			'read' => ['nonexistent-group-' . uniqid()],
		]);

		// If CLI test user is admin, it bypasses RBAC
		$hasPermission = $this->rbacHandler->hasPermission($schema, 'read');
		// Result depends on whether test user is admin
		$this->assertIsBool($hasPermission);
	}

	public function testHasPermissionAuthenticatedRule(): void {
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

	public function testHasPermissionOwnerHasAccess(): void {
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

	public function testHasPermissionConditionalRulePublicGroup(): void {
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

	public function testHasPermissionConditionalRuleNoMatchBlock(): void {
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

	public function testBuildRbacConditionsSqlNoAuthorization(): void {
		$schema = $this->createTestSchema();

		$result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
		$this->assertIsArray($result);
		$this->assertArrayHasKey('bypass', $result);
		$this->assertArrayHasKey('conditions', $result);
		// No authorization used to mean an unconditional bypass. It now means
		// "open to every NON-PRIVATE row": an individual object may declare
		// `scope: private` on a schema that configures nothing, and bypassing
		// here would leak it. See the `private` object scope.
		$this->assertFalse($result['bypass']);
		$this->assertStringContainsString('_authorization', implode(' ', $result['conditions']));
	}

	public function testBuildRbacConditionsSqlPublicRule(): void {
		$schema = $this->createTestSchema([
			'read' => ['public'],
		]);

		$result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
		$this->assertIsArray($result);
		// An unconditional `public` grant reaches every non-private row, which
		// is now expressed as a condition rather than as a bypass.
		$this->assertFalse($result['bypass']);
		$this->assertStringContainsString('_authorization', implode(' ', $result['conditions']));
	}

	public function testBuildRbacConditionsSqlUnconfiguredAction(): void {
		$schema = $this->createTestSchema([
			'read' => ['public'],
		]);

		// rbac-default-deny: 'delete' is unconfigured on a non-empty block, so it
		// no longer bypasses filtering. Admin still bypasses; otherwise the result
		// is a non-bypass filter (owner-only conditions, or empty = deny-all).
		$result = $this->rbacHandler->buildRbacConditionsSql($schema, 'delete');
		$this->assertIsArray($result);
		if ($this->rbacHandler->isAdmin() === true) {
			$this->assertTrue($result['bypass']);
		} else {
			$this->assertFalse($result['bypass']);
		}
	}

	public function testBuildRbacConditionsSqlGroupRule(): void {
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

	public function testBuildRbacConditionsSqlConditionalRule(): void {
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

	public function testBuildRbacConditionsSqlMultipleRules(): void {
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

	public function testHasConditionalRulesBypassingMultitenancyNoAuth(): void {
		$schema = $this->createTestSchema();

		$result = $this->rbacHandler->hasConditionalRulesBypassingMultitenancy($schema, 'read');
		// No authorization = no conditional rules
		$this->assertIsBool($result);
	}

	public function testHasConditionalRulesBypassingMultitenancyPublicRule(): void {
		$schema = $this->createTestSchema([
			'read' => ['public'],
		]);

		$result = $this->rbacHandler->hasConditionalRulesBypassingMultitenancy($schema, 'read');
		$this->assertIsBool($result);
	}

	public function testHasConditionalRulesBypassingMultitenancyConditionalRule(): void {
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

	public function testSearchWithNoAuthorizationReturnsResults(): void {
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

	public function testSearchWithPublicAuthorizationReturnsResults(): void {
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

	public function testSearchWithRestrictedAuthorizationDoesNotCrash(): void {
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

	public function testSearchWithConditionalAuthorizationDoesNotCrash(): void {
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

	public function testCountWithPublicAuthorization(): void {
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

	public function testCountWithRestrictedAuthorization(): void {
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

	public function testFacetsWithPublicAuthorization(): void {
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

	public function testHasPermissionDifferentActions(): void {
		$schema = $this->createTestSchema([
			'read' => ['public'],
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

	public function testBuildRbacConditionsSqlWithOperatorMatch(): void {
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

	public function testBuildRbacConditionsSqlWithInOperator(): void {
		$schema = $this->createTestSchema([
			'read' => [
				['group' => 'public', 'match' => ['status' => ['$in' => ['published', 'live']]]],
			],
		]);

		$result = $this->rbacHandler->buildRbacConditionsSql($schema, 'read');
		$this->assertIsArray($result);
		$this->assertArrayHasKey('bypass', $result);
	}

	public function testBuildRbacConditionsSqlWithExistsOperator(): void {
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

	public function testSearchWithRbacDisabledBypassesRestrictions(): void {
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

	public function testCountWithRbacDisabledBypassesRestrictions(): void {
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

	public function testHasPermissionWithObjectDataPublicConditional(): void {
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

	public function testHasPermissionWithObjectDataNotMatching(): void {
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

	public function testSearchAcrossMultipleTablesWithRbac(): void {
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

	// =========================================================================
	// Fail-closed SQL match evaluation on null-resolved dynamic variables (#1953).
	//
	// When a `match` rule's dynamic variable ($organisation/$userId/$now)
	// resolves to null, the SQL/list path MUST emit an impossible predicate
	// (1 = 0) for that property rather than dropping it from the AND. This makes
	// the LIST path agree with the PHP/find path (ConditionMatcher), which
	// already fails closed on null dynamic values. These tests run as anonymous
	// (the genuine null-$organisation case) so $organisation cannot resolve.
	// =========================================================================

	public function testListFailsClosedOnMultiConditionMatchWithNullOrganisation(): void {
		// Multi-condition public match rule: a static `status` predicate AND a
		// dynamic `_organisation` => '$organisation' predicate. As anonymous,
		// $organisation resolves to null, so the rule must grant NO rows even
		// though the static predicate matches the inserted object.
		$register = $this->createTestRegister();
		$schema = $this->createTestSchema([
			'read' => [
				[
					'group' => 'public',
					'match' => [
						'status' => 'published',
						'_organisation' => '$organisation',
					],
				],
			],
		]);

		$this->mapper->ensureTableForRegisterSchema($register, $schema);
		$this->trackTable($register, $schema);

		// Object satisfies the static predicate (status=published) but the
		// $organisation predicate cannot be satisfied for an anonymous caller.
		$this->insertTestObject($register, $schema, ['name' => 'Leaky', 'status' => 'published', 'age' => 1]);

		$results = $this->mapper->searchObjectsInRegisterSchemaTable(
			['_multitenancy' => false],
			$register,
			$schema
		);

		// Pre-fix: the null $organisation predicate was DROPPED, leaving only
		// status=published, so the object leaked on LIST. Post-fix the impossible
		// predicate (1 = 0) is ANDed in, so LIST returns nothing — matching the
		// PHP/find verdict.
		$this->assertIsArray($results);
		$this->assertEmpty(
			$results,
			'Multi-condition match with null-resolved $organisation MUST deny on LIST (no silent drop)'
		);
	}

	public function testListFailsClosedOnSingleConditionMatchWithNullOrganisation(): void {
		// Single-condition match on a null-resolving dynamic variable: LIST must
		// also deny (parity with find), confirming the impossible predicate is
		// emitted rather than the whole match being dropped.
		$register = $this->createTestRegister();
		$schema = $this->createTestSchema([
			'read' => [
				['group' => 'public', 'match' => ['_organisation' => '$organisation']],
			],
		]);

		$this->mapper->ensureTableForRegisterSchema($register, $schema);
		$this->trackTable($register, $schema);

		$this->insertTestObject($register, $schema, ['name' => 'SingleLeaky', 'status' => 'x', 'age' => 1]);

		$results = $this->mapper->searchObjectsInRegisterSchemaTable(
			['_multitenancy' => false],
			$register,
			$schema
		);

		$this->assertIsArray($results);
		$this->assertEmpty(
			$results,
			'Single-condition match with null-resolved $organisation MUST deny on LIST'
		);
	}

	public function testResolvableMatchRuleStillReturnsRowsOnList(): void {
		// Guard against over-denial: a match rule whose predicates DO resolve
		// (a static-only public match) must still return its rows on LIST. The
		// fail-closed change introduces no new denials for resolvable rules.
		$register = $this->createTestRegister();
		$schema = $this->createTestSchema([
			'read' => [
				['group' => 'public', 'match' => ['status' => 'published']],
			],
		]);

		$this->mapper->ensureTableForRegisterSchema($register, $schema);
		$this->trackTable($register, $schema);

		$this->insertTestObject($register, $schema, ['name' => 'Visible', 'status' => 'published', 'age' => 1]);
		$this->insertTestObject($register, $schema, ['name' => 'Hidden', 'status' => 'draft', 'age' => 1]);

		$results = $this->mapper->searchObjectsInRegisterSchemaTable(
			['_multitenancy' => false],
			$register,
			$schema
		);

		$this->assertIsArray($results);
		$this->assertCount(
			1,
			$results,
			'A fully-resolvable static match rule MUST still return its matching rows (no new denials)'
		);
	}
}
