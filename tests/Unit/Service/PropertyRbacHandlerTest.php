<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PropertyRbacHandlerTest extends TestCase {
	private PropertyRbacHandler $handler;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private ConditionMatcher&MockObject $conditionMatcher;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->conditionMatcher = $this->createMock(ConditionMatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new PropertyRbacHandler(
			$this->userSession,
			$this->groupManager,
			$this->conditionMatcher,
			$this->logger
		);
	}

	private function mockUser(string $uid, array $groups): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
		return $user;
	}

	private function createSchema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}

	// ── isAdmin ──

	public function testIsAdminReturnsTrueForAdminUser(): void {
		$this->mockUser('admin', ['admin']);
		$this->assertTrue($this->handler->isAdmin());
	}

	public function testIsAdminReturnsFalseForRegularUser(): void {
		$this->mockUser('user1', ['users']);
		$this->assertFalse($this->handler->isAdmin());
	}

	public function testIsAdminReturnsFalseWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->assertFalse($this->handler->isAdmin());
	}

	// ── filterReadableProperties ──

	public function testFilterReadablePropertiesReturnsAllForAdmin(): void {
		$this->mockUser('admin', ['admin']);
		$schema = $this->createSchema([
			'field1' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'editors']]]],
		]);
		$object = ['field1' => 'value1', 'field2' => 'value2'];

		$result = $this->handler->filterReadableProperties($schema, $object);
		$this->assertSame($object, $result);
	}

	public function testFilterReadablePropertiesReturnsAllWhenNoPropertyAuth(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'field1' => ['type' => 'string'],
			'field2' => ['type' => 'string'],
		]);
		$object = ['field1' => 'value1', 'field2' => 'value2'];

		$result = $this->handler->filterReadableProperties($schema, $object);
		$this->assertSame($object, $result);
	}

	// ── canReadProperty (no authorization) ──

	public function testCanReadPropertyReturnsTrueWhenNoAuthorizationDefined(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'field1' => ['type' => 'string'],
		]);

		$this->assertTrue($this->handler->canReadProperty($schema, 'field1', []));
	}

	// ── canReadProperty with public group ──

	public function testCanReadPropertyAllowsPublicGroup(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'field1' => [
				'type' => 'string',
				'authorization' => ['read' => [['group' => 'public']]],
			],
		]);

		$this->assertTrue($this->handler->canReadProperty($schema, 'field1', []));
	}

	// ── canReadProperty with authenticated group ──

	public function testCanReadPropertyAllowsAuthenticatedUserForAuthenticatedGroup(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'field1' => [
				'type' => 'string',
				'authorization' => ['read' => [['group' => 'authenticated']]],
			],
		]);

		$this->assertTrue($this->handler->canReadProperty($schema, 'field1', []));
	}

	// ── canReadProperty denied ──

	public function testCanReadPropertyDeniedWhenUserNotInGroup(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'field1' => [
				'type' => 'string',
				'authorization' => ['read' => [['group' => 'editors']]],
			],
		]);

		$this->assertFalse($this->handler->canReadProperty($schema, 'field1', []));
	}

	// ── getUnauthorizedProperties ──

	public function testGetUnauthorizedPropertiesReturnsEmptyForAdmin(): void {
		$this->mockUser('admin', ['admin']);
		$schema = $this->createSchema([
			'secret' => [
				'type' => 'string',
				'authorization' => ['update' => [['group' => 'editors']]],
			],
		]);

		$result = $this->handler->getUnauthorizedProperties($schema, [], ['secret' => 'new'], false);
		$this->assertSame([], $result);
	}

	public function testGetUnauthorizedPropertiesReturnsEmptyWhenNoPropertyAuth(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'field1' => ['type' => 'string'],
		]);

		$result = $this->handler->getUnauthorizedProperties($schema, [], ['field1' => 'val'], false);
		$this->assertSame([], $result);
	}

	public function testGetUnauthorizedPropertiesSkipsUnchangedFields(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'secret' => [
				'type' => 'string',
				'authorization' => ['update' => [['group' => 'editors']]],
			],
		]);

		$existing = ['secret' => 'same'];
		$incoming = ['secret' => 'same'];

		$result = $this->handler->getUnauthorizedProperties($schema, $existing, $incoming, false);
		$this->assertSame([], $result);
	}

	public function testGetUnauthorizedPropertiesReturnsUnauthorizedFields(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'secret' => [
				'type' => 'string',
				'authorization' => ['update' => [['group' => 'editors']]],
			],
		]);

		$result = $this->handler->getUnauthorizedProperties($schema, ['secret' => 'old'], ['secret' => 'new'], false);
		$this->assertSame(['secret'], $result);
	}

	public function testGetUnauthorizedPropertiesSkipsFieldsNotInIncoming(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'secret' => [
				'type' => 'string',
				'authorization' => ['update' => [['group' => 'editors']]],
			],
		]);

		$result = $this->handler->getUnauthorizedProperties($schema, [], ['other_field' => 'val'], false);
		$this->assertSame([], $result);
	}

	// ── canUpdateProperty ──

	public function testCanUpdatePropertyAllowsAdminGroup(): void {
		$this->mockUser('admin', ['admin']);
		$schema = $this->createSchema([
			'field1' => [
				'type' => 'string',
				'authorization' => ['update' => [['group' => 'editors']]],
			],
		]);

		$this->assertTrue($this->handler->canUpdateProperty($schema, 'field1', [], false));
	}

	// ── Conditional rule with match ──

	public function testCanReadPropertyWithConditionalMatchPassing(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'field1' => [
				'type' => 'string',
				'authorization' => [
					'read' => [
						['group' => 'public', 'match' => ['_organisation' => '$organisation']],
					],
				],
			],
		]);

		$this->conditionMatcher->method('objectMatchesConditions')->willReturn(true);

		$this->assertTrue($this->handler->canReadProperty($schema, 'field1', ['_organisation' => 'org1']));
	}

	public function testCanReadPropertyWithConditionalMatchFailing(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'field1' => [
				'type' => 'string',
				'authorization' => [
					'read' => [
						['group' => 'public', 'match' => ['_organisation' => '$organisation']],
					],
				],
			],
		]);

		$this->conditionMatcher->method('objectMatchesConditions')->willReturn(false);

		$this->assertFalse($this->handler->canReadProperty($schema, 'field1', ['_organisation' => 'wrong']));
	}

	// ── writeOnly stripping (property-level-read-rbac / closes #380) ──

	public function testWriteOnlyPropertyStrippedForNonAdmin(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'name' => ['type' => 'string'],
			'apiToken' => ['type' => 'string', 'writeOnly' => true],
		]);
		$object = ['name' => 'prod', 'apiToken' => 's3cr3t'];

		$result = $this->handler->filterReadableProperties($schema, $object);
		$this->assertArrayHasKey('name', $result);
		$this->assertArrayNotHasKey('apiToken', $result);
	}

	public function testWriteOnlyPropertyStrippedForAdmin(): void {
		// Admin is NOT exempt from writeOnly stripping — a write-only secret
		// is never returned on read to anyone.
		$this->mockUser('admin', ['admin']);
		$schema = $this->createSchema([
			'name' => ['type' => 'string'],
			'apiToken' => ['type' => 'string', 'writeOnly' => true],
		]);
		$object = ['name' => 'prod', 'apiToken' => 's3cr3t'];

		$result = $this->handler->filterReadableProperties($schema, $object);
		$this->assertArrayHasKey('name', $result);
		$this->assertArrayNotHasKey('apiToken', $result);
	}

	public function testWriteOnlyStrippedEvenWhenExplicitlyPresent(): void {
		// Field re-widening defence: even if the caller selected the writeOnly
		// property (so it is present in the object handed to the filter), it is
		// still removed because stripping is applied after field selection.
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'apiToken' => ['type' => 'string', 'writeOnly' => true],
		]);

		$result = $this->handler->filterReadableProperties($schema, ['apiToken' => 'leak']);
		$this->assertSame([], $result);
	}

	public function testStripWriteOnlyPropertiesShortCircuitsWhenNone(): void {
		$schema = $this->createSchema([
			'name' => ['type' => 'string'],
		]);
		$object = ['name' => 'prod', 'extra' => 'x'];

		$result = $this->handler->stripWriteOnlyProperties($schema, $object);
		$this->assertSame($object, $result);
	}

	public function testWriteOnlyPropertyRemainsWritable(): void {
		// writeOnly restricts READ only; a writeOnly property with no update
		// authorization is freely writable (not reported as unauthorized).
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'apiToken' => ['type' => 'string', 'writeOnly' => true],
		]);

		$result = $this->handler->getUnauthorizedProperties($schema, [], ['apiToken' => 'new'], false);
		$this->assertSame([], $result);
	}

	// ── property authorization.read: member vs non-member vs admin ──

	public function testPropertyAuthReadReturnedForGroupMember(): void {
		$this->mockUser('user1', ['secret-readers']);
		$schema = $this->createSchema([
			'internalNote' => [
				'type' => 'string',
				'authorization' => ['read' => ['secret-readers']],
			],
		]);
		$object = ['internalNote' => 'sensitive'];

		$result = $this->handler->filterReadableProperties($schema, $object);
		$this->assertArrayHasKey('internalNote', $result);
	}

	public function testPropertyAuthReadStrippedForNonMember(): void {
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'internalNote' => [
				'type' => 'string',
				'authorization' => ['read' => ['secret-readers']],
			],
		]);
		$object = ['internalNote' => 'sensitive'];

		$result = $this->handler->filterReadableProperties($schema, $object);
		$this->assertArrayNotHasKey('internalNote', $result);
	}

	public function testPropertyAuthReadReturnedForAdmin(): void {
		$this->mockUser('admin', ['admin']);
		$schema = $this->createSchema([
			'internalNote' => [
				'type' => 'string',
				'authorization' => ['read' => ['secret-readers']],
			],
		]);
		$object = ['internalNote' => 'sensitive'];

		$result = $this->handler->filterReadableProperties($schema, $object);
		$this->assertArrayHasKey('internalNote', $result);
	}

	public function testObjectWithNeitherMechanismUnchanged(): void {
		// Regression: a schema with neither writeOnly nor read-authorization
		// serialises identically for a plain user.
		$this->mockUser('user1', ['users']);
		$schema = $this->createSchema([
			'name' => ['type' => 'string'],
			'count' => ['type' => 'integer'],
		]);
		$object = ['name' => 'prod', 'count' => 3];

		$result = $this->handler->filterReadableProperties($schema, $object);
		$this->assertSame($object, $result);
	}
}
