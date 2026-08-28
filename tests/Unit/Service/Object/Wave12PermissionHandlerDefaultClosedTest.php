<?php

/**
 * Wave-12 Fix 2 regression tests for PermissionHandler default-closed flag
 * AND Fix 5 per-object _authorization override.
 *
 * Tests the new BC-aware safety policy added in Wave-12 to close the
 * default-OPEN write hole documented at
 * `/tmp/wave11-or-engine-primitives.md` Section B1 (openbuilt-class
 * authenticated-write-open vulnerability) — plus the per-object
 * `_authorization` override path that closes the dead-storage gap in
 * Section F.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class Wave12PermissionHandlerDefaultClosedTest extends TestCase {

	private IUserSession&MockObject $userSession;

	private IUserManager&MockObject $userManager;

	private IGroupManager&MockObject $groupManager;

	private SchemaMapper&MockObject $schemaMapper;

	private MagicMapper&MockObject $objectEntityMapper;

	private ConditionMatcher&MockObject $conditionMatcher;

	private LoggerInterface&MockObject $logger;

	private ContainerInterface&MockObject $container;

	private RegisterMapper&MockObject $registerMapper;

	private IAppConfig&MockObject $appConfig;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectEntityMapper = $this->createMock(MagicMapper::class);
		$this->conditionMatcher = $this->createMock(ConditionMatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}//end setUp()

	private function newHandler(bool $enforceDefaultClosed): PermissionHandler {
		$this->appConfig
			->method('getValueBool')
			->with('openregister', PermissionHandler::CONFIG_ENFORCE_DEFAULT_CLOSED, false)
			->willReturn($enforceDefaultClosed);

		// Named arguments: wave-12 injected IAppConfig as an OPTIONAL trailing arg,
		// but on this lineage IAppConfig is already a REQUIRED constructor
		// dependency sitting at position 7 (before $logger). The positional list
		// wave-12 shipped would bind $logger into $appConfig here.
		return new PermissionHandler(
			userSession: $this->userSession,
			userManager: $this->userManager,
			groupManager: $this->groupManager,
			schemaMapper: $this->schemaMapper,
			objectEntityMapper: $this->objectEntityMapper,
			conditionMatcher: $this->conditionMatcher,
			appConfig: $this->appConfig,
			logger: $this->logger,
			container: $this->container,
			eventDispatcher: null
		);
	}//end newHandler()

	private function mockUser(string $uid, array $groups): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
		return $user;
	}//end mockUser()

	private function createSchema(int $id, ?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setAuthorization($authorization);
		$schema->setTitle('Test Schema ' . $id);
		return $schema;
	}//end createSchema()

	private function createRegister(int $id, ?array $authorization): Register {
		$register = new Register();
		$register->setId($id);
		$register->setAuthorization($authorization);
		$register->setConfiguration([]);
		return $register;
	}//end createRegister()

	private function setupRegisterForSchema(Register $register): void {
		$this->container->method('get')
			->willReturnCallback(
				function (string $class) use ($register) {
					if ($class === RegisterMapper::class) {
						return $this->registerMapper;
					}

					if ($class === 'OCA\OpenRegister\Service\OrganisationService') {
						throw new \RuntimeException('Not available');
					}

					throw new \RuntimeException('Unknown class: ' . $class);
				}
			);

		$this->registerMapper->method('getFirstRegisterWithSchema')
			->willReturn($register->getId());
		$this->registerMapper->method('find')
			->willReturn($register);
	}//end setupRegisterForSchema()

	// === Fix 2: default-closed flag (BC behaviour) ===
	public function testFlagOffPreservesDefaultOpen(): void {
		// BC contract: with the flag off (the default), a schema with no
		// authorization block grants every authenticated user every action.
		// This is what the fleet of ~15 leaf apps currently relies on.
		$handler = $this->newHandler(enforceDefaultClosed: false);
		$this->mockUser('user1', ['users']);

		$schema = $this->createSchema(1, null);
		$register = $this->createRegister(10, null);
		$this->setupRegisterForSchema($register);

		$this->assertTrue($handler->hasPermission($schema, 'create'));
		$this->assertTrue($handler->hasPermission($schema, 'update'));
		$this->assertTrue($handler->hasPermission($schema, 'delete'));
		$this->assertTrue($handler->hasPermission($schema, 'read'));
	}//end testFlagOffPreservesDefaultOpen()

	public function testFlagOnRejectsWritesOnSchemaWithoutAuth(): void {
		// Flag opted-in: schema with no authorization block AND no
		// `public: true` opt-in default-CLOSES write actions for
		// authenticated non-admins.
		$handler = $this->newHandler(enforceDefaultClosed: true);
		$this->mockUser('user1', ['users']);

		$schema = $this->createSchema(1, null);
		$register = $this->createRegister(10, null);
		$this->setupRegisterForSchema($register);

		$this->assertFalse($handler->hasPermission($schema, 'create'));
		$this->assertFalse($handler->hasPermission($schema, 'update'));
		$this->assertFalse($handler->hasPermission($schema, 'delete'));

		// Reads stay default-open — @PublicPage is the OR-wide read model.
		$this->assertTrue($handler->hasPermission($schema, 'read'));
		$this->assertTrue($handler->hasPermission($schema, 'list'));
	}//end testFlagOnRejectsWritesOnSchemaWithoutAuth()

	public function testFlagOnWithPublicOptInGrantsWrites(): void {
		// Authors that explicitly want anonymous-write semantics under the
		// flag can opt in via `public: true` on the authorization block.
		$handler = $this->newHandler(enforceDefaultClosed: true);
		$this->mockUser('user1', ['users']);

		$schema = $this->createSchema(1, ['public' => true]);
		$register = $this->createRegister(10, null);
		$this->setupRegisterForSchema($register);

		$this->assertTrue($handler->hasPermission($schema, 'create'));
		$this->assertTrue($handler->hasPermission($schema, 'update'));
		$this->assertTrue($handler->hasPermission($schema, 'delete'));
	}//end testFlagOnWithPublicOptInGrantsWrites()

	public function testFlagOnRespectsExplicitAuthBlock(): void {
		// When the schema DOES carry an explicit authorization block,
		// the flag has no effect — the block drives behaviour as before.
		$handler = $this->newHandler(enforceDefaultClosed: true);
		$this->mockUser('user1', ['editors']);

		$schema = $this->createSchema(
			1,
			[
				'create' => ['editors'],
				'update' => ['admin'],
			]
		);
		$register = $this->createRegister(10, null);
		$this->setupRegisterForSchema($register);

		$this->assertTrue($handler->hasPermission($schema, 'create'));
		$this->assertFalse($handler->hasPermission($schema, 'update'));
	}//end testFlagOnRespectsExplicitAuthBlock()

	public function testFlagOnAdminStillBypasses(): void {
		// Admin group ALWAYS has all permissions — the flag must not
		// change that.
		$handler = $this->newHandler(enforceDefaultClosed: true);
		$this->mockUser('admin1', ['admin']);

		$schema = $this->createSchema(1, null);
		$register = $this->createRegister(10, null);
		$this->setupRegisterForSchema($register);

		$this->assertTrue($handler->hasPermission($schema, 'create'));
		$this->assertTrue($handler->hasPermission($schema, 'update'));
		$this->assertTrue($handler->hasPermission($schema, 'delete'));
	}//end testFlagOnAdminStillBypasses()

	public function testFlagOffEmitsDeprecationWarning(): void {
		// Flag off + write action on a schema with no auth block ought to
		// log a deprecation warning the FIRST time it happens (one entry
		// per action per request to avoid log noise).
		$handler = $this->newHandler(enforceDefaultClosed: false);
		$this->mockUser('user1', ['users']);

		$this->logger
			->expects($this->atLeastOnce())
			->method('warning')
			->with(
				$this->stringContains('DEPRECATION: schema without an authorization block')
			);

		$schema = $this->createSchema(1, null);
		$register = $this->createRegister(10, null);
		$this->setupRegisterForSchema($register);

		$handler->hasPermission($schema, 'create');
	}//end testFlagOffEmitsDeprecationWarning()

	// === Fix 5: per-object _authorization ===
	public function testPerObjectAuthorizationOverridesSchema(): void {
		// Per-object _authorization with stricter rules than the schema must
		// be honoured: schema grants 'update' to 'users', the object pins
		// 'update' to 'admin' → non-admin user denied.
		$handler = $this->newHandler(enforceDefaultClosed: false);
		$this->mockUser('user1', ['users']);

		$schema = $this->createSchema(
			1,
			[
				'update' => ['users'],
				'read' => ['users'],
			]
		);
		$register = $this->createRegister(10, null);
		$this->setupRegisterForSchema($register);

		$object = new ObjectEntity();
		$object->setUuid('00000000-0000-0000-0000-000000000001');
		$object->setAuthorization(['update' => ['admin']]);

		$this->assertFalse($handler->hasPermission($schema, 'update', null, null, true, $object));

		// Actions NOT overridden by the object still inherit from the schema.
		$this->assertTrue($handler->hasPermission($schema, 'read', null, null, true, $object));
	}//end testPerObjectAuthorizationOverridesSchema()

	public function testPerObjectAuthorizationGrantsWhenSchemaDenies(): void {
		// Per-object _authorization can also LOOSEN: schema denies update to
		// non-admins, but the object grants it to 'users' specifically.
		$handler = $this->newHandler(enforceDefaultClosed: false);
		$this->mockUser('user1', ['users']);

		$schema = $this->createSchema(
			1,
			[
				'update' => ['admin'],
			]
		);
		$register = $this->createRegister(10, null);
		$this->setupRegisterForSchema($register);

		$object = new ObjectEntity();
		$object->setUuid('00000000-0000-0000-0000-000000000002');
		$object->setAuthorization(['update' => ['users']]);

		$this->assertTrue($handler->hasPermission($schema, 'update', null, null, true, $object));
	}//end testPerObjectAuthorizationGrantsWhenSchemaDenies()

	public function testNoPerObjectAuthorizationFallsThroughToSchema(): void {
		// Object with empty _authorization (the wave-11 status quo) must
		// behave exactly like the schema's rules — no change in verdict.
		$handler = $this->newHandler(enforceDefaultClosed: false);
		$this->mockUser('user1', ['users']);

		$schema = $this->createSchema(
			1,
			[
				'update' => ['users'],
			]
		);
		$register = $this->createRegister(10, null);
		$this->setupRegisterForSchema($register);

		$object = new ObjectEntity();
		$object->setUuid('00000000-0000-0000-0000-000000000003');
		// No setAuthorization — column stays as the entity default ([]).
		$this->assertTrue($handler->hasPermission($schema, 'update', null, null, true, $object));
	}//end testNoPerObjectAuthorizationFallsThroughToSchema()

	public function testPerObjectAuthorizationActionByActionMerge(): void {
		// The override is action-by-action: actions present on the object
		// replace the schema rules for those keys ONLY. Other actions
		// continue to inherit from the schema.
		$handler = $this->newHandler(enforceDefaultClosed: false);
		$this->mockUser('user1', ['users']);

		$schema = $this->createSchema(
			1,
			[
				'create' => ['users'],
				'update' => ['users'],
				'delete' => ['users'],
			]
		);
		$register = $this->createRegister(10, null);
		$this->setupRegisterForSchema($register);

		$object = new ObjectEntity();
		$object->setUuid('00000000-0000-0000-0000-000000000004');
		// Object seals only `delete`. Update + create stay schema-driven.
		$object->setAuthorization(['delete' => ['admin']]);

		$this->assertTrue($handler->hasPermission($schema, 'create', null, null, true, $object));
		$this->assertTrue($handler->hasPermission($schema, 'update', null, null, true, $object));
		$this->assertFalse($handler->hasPermission($schema, 'delete', null, null, true, $object));
	}//end testPerObjectAuthorizationActionByActionMerge()
}//end class
