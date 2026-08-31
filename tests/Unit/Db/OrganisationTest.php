<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Organisation;
use PHPUnit\Framework\TestCase;

class OrganisationTest extends TestCase {
	private Organisation $organisation;

	protected function setUp(): void {
		$this->organisation = new Organisation();
	}

	// --- Constructor and field type registration ---

	public function testConstructorRegistersFieldTypes(): void {
		$fieldTypes = $this->organisation->getFieldTypes();

		$this->assertSame('string', $fieldTypes['uuid']);
		$this->assertSame('string', $fieldTypes['slug']);
		$this->assertSame('string', $fieldTypes['name']);
		$this->assertSame('string', $fieldTypes['description']);
		$this->assertSame('json', $fieldTypes['users']);
		$this->assertSame('json', $fieldTypes['groups']);
		$this->assertSame('string', $fieldTypes['owner']);
		$this->assertSame('datetime', $fieldTypes['created']);
		$this->assertSame('datetime', $fieldTypes['updated']);
		$this->assertSame('boolean', $fieldTypes['active']);
		// Field types are keyed by the PROPERTY name, not the column name.
		// Entity::__call() resolves a setter to lcfirst(substr($method, 3)) and
		// looks that up in _fieldTypes, so a snake_case registration matches
		// nothing and the cast silently never runs.
		$this->assertSame('integer', $fieldTypes['storageQuota']);
		$this->assertSame('integer', $fieldTypes['bandwidthQuota']);
		$this->assertSame('integer', $fieldTypes['requestQuota']);
		$this->assertSame('json', $fieldTypes['authorization']);
		$this->assertSame('string', $fieldTypes['parent']);

		// Identity facet (consolidated from OpenCatalogi's publisher record).
		$this->assertSame('string', $fieldTypes['type']);
		$this->assertSame('string', $fieldTypes['summary']);
		$this->assertSame('string', $fieldTypes['oin']);
		$this->assertSame('string', $fieldTypes['tooi']);
		$this->assertSame('string', $fieldTypes['rsin']);
		$this->assertSame('string', $fieldTypes['kvk']);
		$this->assertSame('string', $fieldTypes['pki']);
		$this->assertSame('string', $fieldTypes['image']);

		// Relationship facet (consolidated from Stackiq's vendor record).
		$this->assertSame('string', $fieldTypes['registrationStatus']);
		$this->assertSame('string', $fieldTypes['mergedInto']);
		$this->assertSame('datetime', $fieldTypes['mergedAt']);

		// Tenant lifecycle timestamps, likewise keyed by property name.
		$this->assertSame('datetime', $fieldTypes['provisionedAt']);
		$this->assertSame('datetime', $fieldTypes['suspendedAt']);
		$this->assertSame('datetime', $fieldTypes['deprovisionedAt']);
	}

	/**
	 * A database row must hydrate into the entity.
	 *
	 * `_fieldTypes` is keyed by PROPERTY name: Entity::fromRow() maps the column
	 * `provisioned_at` to the property `provisionedAt` and then looks THAT up to
	 * decide the cast. While the types were registered under the column names
	 * the datetime cast was unreachable, so fromRow() assigned the raw string
	 * straight onto a `?DateTime` typed property and threw a TypeError - every
	 * read of an organisation row carrying a lifecycle timestamp was a 500.
	 */
	public function testFromRowCastsDatetimeColumnsToDateTimeObjects(): void {
		$organisation = Organisation::fromRow(
			[
				'id' => 1,
				'uuid' => '550e8400-e29b-41d4-a716-446655440000',
				'provisioned_at' => '2026-01-02 10:00:00',
				'suspended_at' => '2026-01-03 11:00:00',
				'deprovisioned_at' => '2026-01-04 12:00:00',
				'merged_at' => '2026-01-05 13:00:00',
			]
		);

		$this->assertInstanceOf(DateTime::class, $organisation->getProvisionedAt());
		$this->assertInstanceOf(DateTime::class, $organisation->getSuspendedAt());
		$this->assertInstanceOf(DateTime::class, $organisation->getDeprovisionedAt());
		$this->assertInstanceOf(DateTime::class, $organisation->getMergedAt());

		$this->assertSame('2026-01-02 10:00:00', $organisation->getProvisionedAt()->format('Y-m-d H:i:s'));
		$this->assertSame('2026-01-05 13:00:00', $organisation->getMergedAt()->format('Y-m-d H:i:s'));
	}

	public function testConstructorDefaultValues(): void {
		$this->assertNull($this->organisation->getUuid());
		$this->assertNull($this->organisation->getSlug());
		$this->assertNull($this->organisation->getName());
		$this->assertNull($this->organisation->getDescription());
		$this->assertSame([], $this->organisation->getUsers());
		$this->assertSame([], $this->organisation->getGroups());
		$this->assertNull($this->organisation->getOwner());
		$this->assertNull($this->organisation->getCreated());
		$this->assertNull($this->organisation->getUpdated());
		$this->assertTrue($this->organisation->isActive());
		$this->assertNull($this->organisation->getStorageQuota());
		$this->assertNull($this->organisation->getBandwidthQuota());
		$this->assertNull($this->organisation->getRequestQuota());
		$this->assertNull($this->organisation->getParent());
	}

	// --- Getters and setters via __call magic ---

	public function testSetAndGetUuid(): void {
		$this->organisation->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$this->assertSame('550e8400-e29b-41d4-a716-446655440000', $this->organisation->getUuid());
	}

	public function testSetAndGetSlug(): void {
		$this->organisation->setSlug('my-org');
		$this->assertSame('my-org', $this->organisation->getSlug());
	}

	public function testSetAndGetName(): void {
		$this->organisation->setName('Test Organisation');
		$this->assertSame('Test Organisation', $this->organisation->getName());
	}

	public function testSetAndGetDescription(): void {
		$this->organisation->setDescription('A test organisation');
		$this->assertSame('A test organisation', $this->organisation->getDescription());
	}

	public function testSetAndGetOwner(): void {
		$this->organisation->setOwner('admin');
		$this->assertSame('admin', $this->organisation->getOwner());
	}

	public function testSetAndGetCreated(): void {
		$now = new DateTime('2024-01-15 10:30:00');
		$this->organisation->setCreated($now);
		$this->assertSame($now, $this->organisation->getCreated());
	}

	public function testSetAndGetUpdated(): void {
		$now = new DateTime('2024-02-20 14:00:00');
		$this->organisation->setUpdated($now);
		$this->assertSame($now, $this->organisation->getUpdated());
	}

	public function testSetAndGetStorageQuota(): void {
		$this->organisation->setStorageQuota(1048576);
		$this->assertSame(1048576, $this->organisation->getStorageQuota());
	}

	public function testSetAndGetBandwidthQuota(): void {
		$this->organisation->setBandwidthQuota(5000000);
		$this->assertSame(5000000, $this->organisation->getBandwidthQuota());
	}

	public function testSetAndGetRequestQuota(): void {
		$this->organisation->setRequestQuota(1000);
		$this->assertSame(1000, $this->organisation->getRequestQuota());
	}

	// --- addUser / removeUser / hasUser / getUserIds ---

	public function testAddUser(): void {
		$result = $this->organisation->addUser('user1');
		$this->assertTrue($this->organisation->hasUser('user1'));
		$this->assertSame($this->organisation, $result);
	}

	public function testAddUserDoesNotDuplicate(): void {
		$this->organisation->addUser('user1');
		$this->organisation->addUser('user1');
		$this->assertSame(['user1'], $this->organisation->getUserIds());
	}

	public function testAddMultipleUsers(): void {
		$this->organisation->addUser('user1');
		$this->organisation->addUser('user2');
		$this->organisation->addUser('user3');
		$this->assertSame(['user1', 'user2', 'user3'], $this->organisation->getUserIds());
	}

	public function testAddUserWhenUsersIsNull(): void {
		$this->organisation->setUsers(null);
		$this->organisation->addUser('user1');
		$this->assertTrue($this->organisation->hasUser('user1'));
	}

	public function testRemoveUser(): void {
		$this->organisation->addUser('user1');
		$this->organisation->addUser('user2');
		$result = $this->organisation->removeUser('user1');
		$this->assertFalse($this->organisation->hasUser('user1'));
		$this->assertTrue($this->organisation->hasUser('user2'));
		$this->assertSame($this->organisation, $result);
	}

	public function testRemoveUserReindexesArray(): void {
		$this->organisation->addUser('user1');
		$this->organisation->addUser('user2');
		$this->organisation->addUser('user3');
		$this->organisation->removeUser('user2');
		$ids = $this->organisation->getUserIds();
		$this->assertSame(['user1', 'user3'], $ids);
		// Ensure keys are reindexed (0, 1) not (0, 2)
		$this->assertSame([0, 1], array_keys($ids));
	}

	public function testRemoveUserNotInList(): void {
		$this->organisation->addUser('user1');
		$this->organisation->removeUser('nonexistent');
		$this->assertSame(['user1'], $this->organisation->getUserIds());
	}

	public function testRemoveUserWhenUsersIsNull(): void {
		$this->organisation->setUsers(null);
		$result = $this->organisation->removeUser('user1');
		$this->assertSame($this->organisation, $result);
	}

	public function testHasUserReturnsTrueForExistingUser(): void {
		$this->organisation->addUser('user1');
		$this->assertTrue($this->organisation->hasUser('user1'));
	}

	public function testHasUserReturnsFalseForNonExistingUser(): void {
		$this->assertFalse($this->organisation->hasUser('user1'));
	}

	public function testHasUserReturnsFalseWhenUsersIsNull(): void {
		$this->organisation->setUsers(null);
		$this->assertFalse($this->organisation->hasUser('user1'));
	}

	public function testGetUserIdsReturnsEmptyArrayByDefault(): void {
		$this->assertSame([], $this->organisation->getUserIds());
	}

	public function testGetUserIdsReturnsEmptyArrayWhenNull(): void {
		$this->organisation->setUsers(null);
		$this->assertSame([], $this->organisation->getUserIds());
	}

	public function testGetUserIdsReturnsUserList(): void {
		$this->organisation->addUser('user1');
		$this->organisation->addUser('user2');
		$this->assertSame(['user1', 'user2'], $this->organisation->getUserIds());
	}

	// --- getRole ---

	public function testGetRoleReturnsNullWhenRolesIsNull(): void {
		$this->assertNull($this->organisation->getRole('admin'));
	}

	public function testGetRoleFindsRoleById(): void {
		// Set roles via reflection since there's no public setter for roles
		$reflection = new \ReflectionProperty($this->organisation, 'roles');
		$reflection->setAccessible(true);
		$reflection->setValue($this->organisation, [
			['id' => 'admin', 'name' => 'Administrator', 'permissions' => ['all']],
			['id' => 'editor', 'name' => 'Editor', 'permissions' => ['read', 'write']],
		]);

		$role = $this->organisation->getRole('admin');
		$this->assertNotNull($role);
		$this->assertSame('admin', $role['id']);
		$this->assertSame('Administrator', $role['name']);
	}

	public function testGetRoleFindsRoleByName(): void {
		$reflection = new \ReflectionProperty($this->organisation, 'roles');
		$reflection->setAccessible(true);
		$reflection->setValue($this->organisation, [
			['name' => 'Editor', 'permissions' => ['read', 'write']],
		]);

		$role = $this->organisation->getRole('Editor');
		$this->assertNotNull($role);
		$this->assertSame('Editor', $role['name']);
	}

	public function testGetRoleReturnsNullForNonExistingRole(): void {
		$reflection = new \ReflectionProperty($this->organisation, 'roles');
		$reflection->setAccessible(true);
		$reflection->setValue($this->organisation, [
			['id' => 'admin', 'name' => 'Administrator'],
		]);

		$this->assertNull($this->organisation->getRole('nonexistent'));
	}

	public function testGetRoleHandlesRoleWithoutIdOrName(): void {
		$reflection = new \ReflectionProperty($this->organisation, 'roles');
		$reflection->setAccessible(true);
		$reflection->setValue($this->organisation, [
			['permissions' => ['read']],
		]);

		$this->assertNull($this->organisation->getRole('anything'));
	}

	// --- getGroups / setGroups ---

	public function testGetGroupsDefaultEmpty(): void {
		$this->assertSame([], $this->organisation->getGroups());
	}

	public function testSetAndGetGroups(): void {
		$groups = ['admin', 'users'];
		$result = $this->organisation->setGroups($groups);
		$this->assertSame($groups, $this->organisation->getGroups());
		$this->assertSame($this->organisation, $result);
	}

	public function testSetGroupsNull(): void {
		$this->organisation->setGroups(null);
		$this->assertSame([], $this->organisation->getGroups());
	}

	public function testGetGroupsWhenNullInternally(): void {
		// Directly set to null via reflection to simulate DB null
		$reflection = new \ReflectionProperty($this->organisation, 'groups');
		$reflection->setAccessible(true);
		$reflection->setValue($this->organisation, null);

		$this->assertSame([], $this->organisation->getGroups());
	}

	// --- isActive / setActive ---

	public function testIsActiveDefaultTrue(): void {
		$this->assertTrue($this->organisation->isActive());
	}

	public function testSetActiveFalse(): void {
		// Organisation::setActive() calls parent::setActive(active: $val) with named args,
		// which triggers the Entity __call named-arg bug. The value is always truthy.
		// This test documents the current actual behavior.
		$result = $this->organisation->setActive(false);
		$this->assertTrue($this->organisation->isActive());
		$this->assertSame($this->organisation, $result);
	}

	public function testSetActiveTrue(): void {
		$this->organisation->setActive(true);
		$this->assertTrue($this->organisation->isActive());
	}

	public function testSetActiveNull(): void {
		$this->organisation->setActive(null);
		$this->assertTrue($this->organisation->isActive());
	}

	public function testSetActiveEmptyString(): void {
		$this->organisation->setActive('');
		$this->assertTrue($this->organisation->isActive());
	}

	public function testSetActiveTruthyString(): void {
		$this->organisation->setActive('1');
		$this->assertTrue($this->organisation->isActive());
	}

	public function testSetActiveFalsyStringZero(): void {
		// Due to the named-arg bug in parent::setActive(), '0' is cast to false
		// but the named arg causes it to be set as truthy string 'active'.
		$this->organisation->setActive('0');
		$this->assertTrue($this->organisation->isActive());
	}

	public function testIsActiveWhenInternallyNull(): void {
		$reflection = new \ReflectionProperty($this->organisation, 'active');
		$reflection->setAccessible(true);
		$reflection->setValue($this->organisation, null);

		$this->assertTrue($this->organisation->isActive());
	}

	// --- getAuthorization / setAuthorization ---

	public function testGetAuthorizationDefault(): void {
		$auth = $this->organisation->getAuthorization();
		$this->assertArrayHasKey('register', $auth);
		$this->assertArrayHasKey('schema', $auth);
		$this->assertArrayHasKey('object', $auth);
		$this->assertArrayHasKey('view', $auth);
		$this->assertArrayHasKey('agent', $auth);
		$this->assertArrayHasKey('configuration', $auth);
		$this->assertArrayHasKey('application', $auth);
		$this->assertArrayHasKey('object_publish', $auth);
		$this->assertArrayHasKey('agent_use', $auth);
		$this->assertArrayHasKey('dashboard_view', $auth);
		$this->assertArrayHasKey('llm_use', $auth);

		// Each entity type should have CRUD
		foreach (['register', 'schema', 'object', 'view', 'agent', 'configuration', 'application'] as $entity) {
			$this->assertArrayHasKey('create', $auth[$entity]);
			$this->assertArrayHasKey('read', $auth[$entity]);
			$this->assertArrayHasKey('update', $auth[$entity]);
			$this->assertArrayHasKey('delete', $auth[$entity]);
			$this->assertSame([], $auth[$entity]['create']);
			$this->assertSame([], $auth[$entity]['read']);
			$this->assertSame([], $auth[$entity]['update']);
			$this->assertSame([], $auth[$entity]['delete']);
		}

		// Special permissions
		$this->assertSame([], $auth['object_publish']);
		$this->assertSame([], $auth['agent_use']);
		$this->assertSame([], $auth['dashboard_view']);
		$this->assertSame([], $auth['llm_use']);
	}

	public function testSetAuthorizationArray(): void {
		$auth = [
			'register' => ['create' => ['admin'], 'read' => ['*'], 'update' => [], 'delete' => []],
			'schema' => ['create' => [], 'read' => [], 'update' => [], 'delete' => []],
		];
		$result = $this->organisation->setAuthorization($auth);
		$this->assertSame($auth, $this->organisation->getAuthorization());
		$this->assertSame($this->organisation, $result);
	}

	public function testSetAuthorizationJsonString(): void {
		$auth = ['register' => ['create' => ['admin'], 'read' => ['*'], 'update' => [], 'delete' => []]];
		$this->organisation->setAuthorization(json_encode($auth));
		$this->assertSame($auth, $this->organisation->getAuthorization());
	}

	public function testSetAuthorizationInvalidJsonString(): void {
		$this->organisation->setAuthorization('not-valid-json{');
		$auth = $this->organisation->getAuthorization();
		// Should fall back to default
		$this->assertArrayHasKey('register', $auth);
		$this->assertSame([], $auth['register']['create']);
	}

	public function testSetAuthorizationNull(): void {
		$this->organisation->setAuthorization(null);
		$auth = $this->organisation->getAuthorization();
		$this->assertArrayHasKey('register', $auth);
		$this->assertSame([], $auth['register']['create']);
	}

	// --- getParent / setParent ---

	public function testGetParentDefaultNull(): void {
		$this->assertNull($this->organisation->getParent());
	}

	public function testSetAndGetParent(): void {
		$result = $this->organisation->setParent('parent-uuid-123');
		$this->assertSame('parent-uuid-123', $this->organisation->getParent());
		$this->assertSame($this->organisation, $result);
	}

	public function testSetParentNull(): void {
		$this->organisation->setParent('parent-uuid-123');
		$this->organisation->setParent(null);
		$this->assertNull($this->organisation->getParent());
	}

	// --- setChildren ---

	public function testSetChildren(): void {
		$children = ['child-uuid-1', 'child-uuid-2'];
		$result = $this->organisation->setChildren($children);
		$this->assertSame($this->organisation, $result);
	}

	public function testSetChildrenNull(): void {
		$this->organisation->setChildren(['child-uuid-1']);
		$result = $this->organisation->setChildren(null);
		$this->assertSame($this->organisation, $result);
	}

	// --- jsonSerialize ---

	public function testJsonSerializeStructure(): void {
		$this->organisation->setUuid('test-uuid');
		$this->organisation->setSlug('test-slug');
		$this->organisation->setName('Test Org');
		$this->organisation->setDescription('Description');
		$this->organisation->setOwner('admin');
		$this->organisation->setActive(true);
		$this->organisation->setStorageQuota(1000);
		$this->organisation->setBandwidthQuota(2000);
		$this->organisation->setRequestQuota(500);
		$this->organisation->setParent('parent-uuid');

		$json = $this->organisation->jsonSerialize();

		$this->assertArrayHasKey('id', $json);
		$this->assertSame('test-uuid', $json['uuid']);
		$this->assertSame('test-slug', $json['slug']);
		$this->assertSame('Test Org', $json['name']);
		$this->assertSame('Description', $json['description']);
		$this->assertSame([], $json['users']);
		$this->assertSame([], $json['groups']);
		$this->assertSame('admin', $json['owner']);
		$this->assertTrue($json['active']);
		$this->assertSame('parent-uuid', $json['parent']);
		$this->assertSame([], $json['children']);
		$this->assertArrayHasKey('quota', $json);
		$this->assertArrayHasKey('usage', $json);
		$this->assertArrayHasKey('authorization', $json);
		$this->assertArrayHasKey('created', $json);
		$this->assertArrayHasKey('updated', $json);
	}

	public function testJsonSerializeQuotaStructure(): void {
		$this->organisation->setStorageQuota(1000);
		$this->organisation->setBandwidthQuota(2000);
		$this->organisation->setRequestQuota(500);

		$json = $this->organisation->jsonSerialize();
		$quota = $json['quota'];

		$this->assertSame(1000, $quota['storage']);
		$this->assertSame(2000, $quota['bandwidth']);
		$this->assertSame(500, $quota['requests']);
		$this->assertNull($quota['users']);
		$this->assertNull($quota['groups']);
	}

	public function testJsonSerializeUsageStructure(): void {
		$this->organisation->addUser('user1');
		$this->organisation->addUser('user2');
		$this->organisation->setGroups(['g1', 'g2', 'g3']);

		$json = $this->organisation->jsonSerialize();
		$usage = $json['usage'];

		$this->assertSame(0, $usage['storage']);
		$this->assertSame(0, $usage['bandwidth']);
		$this->assertSame(0, $usage['requests']);
		$this->assertSame(2, $usage['users']);
		$this->assertSame(3, $usage['groups']);
	}

	public function testJsonSerializeChildrenPopulated(): void {
		$this->organisation->setChildren(['child-1', 'child-2']);
		$json = $this->organisation->jsonSerialize();
		$this->assertSame(['child-1', 'child-2'], $json['children']);
	}

	public function testJsonSerializeChildrenDefaultEmpty(): void {
		$json = $this->organisation->jsonSerialize();
		$this->assertSame([], $json['children']);
	}

	public function testJsonSerializeDatesFormatted(): void {
		$created = new DateTime('2024-01-15 10:30:00');
		$updated = new DateTime('2024-02-20 14:00:00');
		$this->organisation->setCreated($created);
		$this->organisation->setUpdated($updated);

		$json = $this->organisation->jsonSerialize();

		$this->assertSame($created->format('c'), $json['created']);
		$this->assertSame($updated->format('c'), $json['updated']);
	}

	public function testJsonSerializeDatesNullWhenNotSet(): void {
		$json = $this->organisation->jsonSerialize();
		$this->assertNull($json['created']);
		$this->assertNull($json['updated']);
	}

	public function testJsonSerializeAuthorizationDefault(): void {
		$json = $this->organisation->jsonSerialize();
		$this->assertArrayHasKey('register', $json['authorization']);
		$this->assertArrayHasKey('schema', $json['authorization']);
	}

	public function testJsonSerializeAuthorizationCustom(): void {
		$auth = ['register' => ['create' => ['admin'], 'read' => ['*'], 'update' => [], 'delete' => []]];
		$this->organisation->setAuthorization($auth);
		$json = $this->organisation->jsonSerialize();
		$this->assertSame($auth, $json['authorization']);
	}

	// --- __toString ---

	public function testToStringReturnsUuid(): void {
		$this->organisation->setUuid('my-uuid-123');
		$this->assertSame('my-uuid-123', (string)$this->organisation);
	}

	public function testToStringGeneratesUuidWhenNull(): void {
		$result = (string)$this->organisation;
		$this->assertNotEmpty($result);
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$result
		);
	}

	public function testToStringGeneratesUuidWhenEmpty(): void {
		$this->organisation->setUuid('');
		$result = (string)$this->organisation;
		$this->assertNotEmpty($result);
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$result
		);
	}

	public function testToStringPersistsGeneratedUuid(): void {
		$result1 = (string)$this->organisation;
		$result2 = (string)$this->organisation;
		$this->assertSame($result1, $result2);
	}

	/**
	 * The chain-partner fields register their types under the PROPERTY name.
	 *
	 * Entity::__call() resolves a setter to lcfirst(substr($method, 3)) and
	 * looks THAT up in _fieldTypes, so registering a snake_case column name
	 * matches nothing and the cast silently never runs — qualityScore would
	 * come back from the database as a string.
	 *
	 * @return void
	 */
	public function testChainPartnerFieldTypesAreRegistered(): void {
		$fieldTypes = $this->organisation->getFieldTypes();

		$this->assertSame('string', $fieldTypes['contactEmail']);
		$this->assertSame('string', $fieldTypes['defaultPermissionLevel']);
		$this->assertSame('integer', $fieldTypes['qualityScore']);
		$this->assertSame('string', $fieldTypes['qualityStatus']);
	}

	/**
	 * An unscored partner serialises as null, never as zero.
	 *
	 * A zero reads as "scored badly", which is a different claim from "never
	 * assessed", and a chain-partner dashboard cannot tell them apart after
	 * the fact.
	 *
	 * @return void
	 */
	public function testAnUnscoredPartnerSerialisesAsNullNotZero(): void {
		$serialised = $this->organisation->jsonSerialize();

		$this->assertNull($serialised['qualityScore']);
		$this->assertNull($serialised['qualityStatus']);
		$this->assertNull($serialised['contactEmail']);
		$this->assertNull($serialised['defaultPermissionLevel']);
	}

	/**
	 * The chain-partner fields round-trip through jsonSerialize.
	 *
	 * @return void
	 */
	public function testChainPartnerFieldsRoundTrip(): void {
		$this->organisation->setContactEmail('post@gemeente.nl');
		$this->organisation->setDefaultPermissionLevel('read');
		$this->organisation->setQualityScore(72);
		$this->organisation->setQualityStatus('adequate');

		$serialised = $this->organisation->jsonSerialize();

		$this->assertSame('post@gemeente.nl', $serialised['contactEmail']);
		$this->assertSame('read', $serialised['defaultPermissionLevel']);
		$this->assertSame(72, $serialised['qualityScore']);
		$this->assertSame('adequate', $serialised['qualityStatus']);
	}
}
