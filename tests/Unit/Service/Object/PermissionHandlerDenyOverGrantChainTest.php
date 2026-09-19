<?php

/**
 * A deny beats the two grants that do not come from the block being read.
 *
 * The cases already pinned elsewhere all take their grant from a rule written in
 * the same block the deny is written in: a schema rule, the `authenticated`
 * pseudo-group, a per-object override, the owner. Two grant sources reach a
 * caller from somewhere else entirely, and they are the two a deny is most
 * likely to be quietly wrong about:
 *
 *  - A NAMED ROLE, whose actions live in the register's configuration and are
 *    expanded into the block before the rules are read. An administrator editing
 *    the role never sees the deny, and vice versa.
 *  - A PER-OBJECT GRANT held outside the block altogether, as a share on the
 *    object's folder. This is the seam an ancestor's grant arrives on once
 *    `rbac-inherits-to-children` lands: it is resolved AFTER the deny pass, and
 *    that ordering is the whole of "a deny is not overridden by a grant
 *    inherited from an ancestor object".
 *
 * WHAT THIS FILE DOES NOT CLAIM. The ancestor WALK does not exist yet; every
 * task in `rbac-inherits-to-children` is open. What is pinned here is the
 * precedence an inherited grant will meet, at the seam it will arrive on, not
 * the inheritance itself.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Task 4.2: the role grant and the grant that arrives from outside the block.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerDenyOverGrantChainTest extends TestCase {

	/**
	 * Hands out a distinct schema id per schema built in a case.
	 *
	 * The verdict is memoised per request on (user, schema, action, owner,
	 * uuid), so two schemas sharing an id inside one case would have the first
	 * answer for the second and the case would be measuring the memo.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 0;

	/**
	 * Hands out a distinct uuid per object built in a case.
	 *
	 * @var integer
	 */
	private int $objectCounter = 0;

	/**
	 * Reset the counters before every case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->schemaCounter = 300;
		$this->objectCounter = 0;
	}//end setUp()

	/**
	 * A handler for one caller, over one register configuration.
	 *
	 * @param string                   $userId        The caller.
	 * @param string[]                 $groups        The caller's group IDs.
	 * @param array                    $registerRoles The register's role definitions.
	 * @param ObjectGrantResolver|null $grants        The per-object grant resolver, when a case needs one.
	 *
	 * @return PermissionHandler The handler under test, enforcing.
	 */
	private function handlerFor(
		string $userId,
		array $groups,
		array $registerRoles = [],
		?ObjectGrantResolver $grants = null,
	): PermissionHandler {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($userId);

		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		$logger = new NullLogger();

		return new PermissionHandler(
			$userSession,
			$userManager,
			$groupManager,
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: MagicMapper::class),
			$this->createMock(originalClassName: ConditionMatcher::class),
			$appConfig,
			$logger,
			$this->containerWith(registerRoles: $registerRoles),
			null,
			new ObjectScopeResolver(),
			$grants,
			new DenyResolver(new DenyEntryMatcher()),
			new DenyEnforcementMode($appConfig, $logger)
		);
	}//end handlerFor()

	/**
	 * A container answering with a register that declares these roles.
	 *
	 * @param array $registerRoles The register's role definitions.
	 *
	 * @return ContainerInterface The container.
	 */
	private function containerWith(array $registerRoles): ContainerInterface {
		$register = new Register();
		$register->setId(9);
		$register->setTitle('Zaken');
		$register->setSlug('zaken');
		$register->setConfiguration(['roles' => $registerRoles]);

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(9);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registerMapper): mixed {
				if ($id === RegisterMapper::class) {
					return $registerMapper;
				}

				throw new \RuntimeException(sprintf('nothing registered for %s', $id));
			}
		);

		return $container;
	}//end containerWith()

	/**
	 * A schema carrying one authorization block.
	 *
	 * @param array|null $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(?array $authorization): Schema {
		$this->schemaCounter++;

		$schema = new Schema();
		$schema->setId($this->schemaCounter);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * An object carrying its own block.
	 *
	 * @param array|null $authorization The object's `_authorization`.
	 * @param string     $owner         The object's owner.
	 *
	 * @return ObjectEntity The object.
	 */
	private function objectWith(?array $authorization, string $owner = 'bea'): ObjectEntity {
		$this->objectCounter++;

		$object = new ObjectEntity();
		$object->setUuid(sprintf('33333333-4444-5555-6666-%012d', $this->objectCounter));
		$object->setObject(['title' => 'een zaak']);
		$object->setOwner($owner);
		$object->setAuthorization($authorization);

		return $object;
	}//end objectWith()

	/**
	 * The control: a role grant is a grant, before any deny is written.
	 *
	 * Without this, the refusal below could be the role expansion failing rather
	 * than the deny biting.
	 *
	 * @return void
	 */
	public function testARoleGrantIsAGrant(): void {
		$handler = $this->handlerFor(
			userId: 'ana',
			groups: ['behandelaars'],
			registerRoles: [['name' => 'behandelaar', 'actions' => ['read', 'update']]]
		);

		$this->assertTrue(
			$handler->hasPermission(
				schema: $this->schemaWith(['roles' => ['behandelaar' => ['behandelaars']]]),
				action: 'update',
				userId: 'ana'
			)
		);
	}//end testARoleGrantIsAGrant()

	/**
	 * 🔴 A deny beats a grant that reaches the caller through a named role.
	 *
	 * The role's actions live in the register's configuration, one screen away
	 * from the block carrying the deny. An administrator reading either one
	 * sees half the answer, which is why this direction has to be pinned rather
	 * than reasoned about.
	 *
	 * @return void
	 */
	public function testADenyBeatsARoleGrant(): void {
		$handler = $this->handlerFor(
			userId: 'ana',
			groups: ['behandelaars'],
			registerRoles: [['name' => 'behandelaar', 'actions' => ['read', 'update']]]
		);

		$schema = $this->schemaWith(
			[
				'roles' => ['behandelaar' => ['behandelaars']],
				'deny' => ['update' => ['behandelaars']],
			]
		);

		$this->assertFalse($handler->hasPermission(schema: $schema, action: 'update', userId: 'ana'));

		// And the verb the deny does not name is untouched, so the refusal is
		// about `update` and not about the caller.
		$this->assertTrue($handler->hasPermission(schema: $schema, action: 'read', userId: 'ana'));
	}//end testADenyBeatsARoleGrant()

	/**
	 * 🔴 A deny beats a role grant the role INHERITED through `extends`.
	 *
	 * Two indirections instead of one: the verb is not written on the role the
	 * block names, it is written on the role that role extends.
	 *
	 * @return void
	 */
	public function testADenyBeatsAGrantInheritedThroughExtends(): void {
		$roles = [
			['name' => 'behandelaar', 'actions' => ['read', 'update']],
			['name' => 'senior behandelaar', 'extends' => 'behandelaar', 'actions' => ['delete']],
		];

		$granting = $this->handlerFor(userId: 'ana', groups: ['senioren'], registerRoles: $roles);
		$this->assertTrue(
			$granting->hasPermission(
				schema: $this->schemaWith(['roles' => ['senior behandelaar' => ['senioren']]]),
				action: 'update',
				userId: 'ana'
			)
		);

		$denying = $this->handlerFor(userId: 'ana', groups: ['senioren'], registerRoles: $roles);
		$this->assertFalse(
			$denying->hasPermission(
				schema: $this->schemaWith(
					[
						'roles' => ['senior behandelaar' => ['senioren']],
						'deny' => ['update' => ['senioren']],
					]
				),
				action: 'update',
				userId: 'ana'
			)
		);
	}//end testADenyBeatsAGrantInheritedThroughExtends()

	/**
	 * The floor: a private object refuses a caller the schema admits.
	 *
	 * This is what makes the case below mean anything. Without it, "the grant
	 * opened it" could be the schema rule opening it and the private scope never
	 * closing anything at all.
	 *
	 * @return void
	 */
	public function testAPrivateObjectRefusesACallerTheSchemaAdmits(): void {
		$grants = $this->createMock(originalClassName: ObjectGrantResolver::class);
		$grants->method('isGranted')->willReturn(false);

		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], grants: $grants);
		$schema = $this->schemaWith(['read' => ['gasten']]);

		// The schema admits this caller on an ordinary row.
		$this->assertTrue($handler->hasPermission(schema: $schema, action: 'read', userId: 'ana'));

		// And refuses them the private one, because they hold no grant on it.
		$this->assertFalse(
			$handler->hasPermission(
				schema: $schema,
				action: 'read',
				userId: 'ana',
				object: $this->objectWith(['scope' => ObjectScopeResolver::SCOPE_PRIVATE])
			)
		);
	}//end testAPrivateObjectRefusesACallerTheSchemaAdmits()

	/**
	 * The control: a per-object grant opens a private object.
	 *
	 * @return void
	 */
	public function testAPerObjectGrantOpensAPrivateObject(): void {
		$grants = $this->createMock(originalClassName: ObjectGrantResolver::class);
		$grants->method('isGranted')->willReturn(true);

		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], grants: $grants);

		$this->assertTrue(
			$handler->hasPermission(
				schema: $this->schemaWith(['read' => ['gasten']]),
				action: 'read',
				userId: 'ana',
				object: $this->objectWith(['scope' => ObjectScopeResolver::SCOPE_PRIVATE])
			)
		);
	}//end testAPerObjectGrantOpensAPrivateObject()

	/**
	 * 🔴 A deny beats a grant held outside the block, on the object itself.
	 *
	 * This is the ordering that makes "a deny is not overridden by a grant
	 * inherited from an ancestor object" true: the deny pass runs before the
	 * per-object grant is resolved at all, so a grant arriving from outside the
	 * cascade cannot put the verb back. The ancestor WALK is not implemented
	 * yet; the seam it will arrive on is, and this is that seam.
	 *
	 * @return void
	 */
	public function testADenyBeatsAGrantHeldOutsideTheBlock(): void {
		$grants = $this->createMock(originalClassName: ObjectGrantResolver::class);
		$grants->method('isGranted')->willReturn(true);

		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], grants: $grants);

		$this->assertFalse(
			$handler->hasPermission(
				schema: $this->schemaWith(['read' => ['gasten'], 'deny' => ['read' => ['gasten']]]),
				action: 'read',
				userId: 'ana',
				object: $this->objectWith(['scope' => ObjectScopeResolver::SCOPE_PRIVATE])
			)
		);
	}//end testADenyBeatsAGrantHeldOutsideTheBlock()

	/**
	 * The deny written on the object beats the grant held outside it too.
	 *
	 * The mirror of the case above, with the rule on the row rather than on the
	 * schema: an object shared with somebody and denied to them is refused, and
	 * the share is not the more specific rule.
	 *
	 * @return void
	 */
	public function testADenyOnTheObjectBeatsTheGrantOnTheObject(): void {
		$grants = $this->createMock(originalClassName: ObjectGrantResolver::class);
		$grants->method('isGranted')->willReturn(true);

		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], grants: $grants);

		$this->assertFalse(
			$handler->hasPermission(
				schema: $this->schemaWith(['read' => ['gasten']]),
				action: 'read',
				userId: 'ana',
				object: $this->objectWith(
					[
						'scope' => ObjectScopeResolver::SCOPE_PRIVATE,
						'deny' => ['read' => ['gasten']],
					]
				)
			)
		);
	}//end testADenyOnTheObjectBeatsTheGrantOnTheObject()
}//end class
