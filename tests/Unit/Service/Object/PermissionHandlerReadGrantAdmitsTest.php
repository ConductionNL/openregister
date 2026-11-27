<?php

/**
 * A per-object READ grant admits the object on its own (REQ-RIC-002).
 *
 * Decision (Ruben, 25 Nov 2026): a read grant admits the row by itself,
 * whatever the schema's conditional read rules say, so a launchpad dashboard
 * shared with one colleague opens for them although the schema's audience
 * rules do not list them. A write grant does not: writes stay within the
 * schema's rules. A deny still beats the grant.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionAsk;
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
 * A read grant admits on its own; a write grant does not.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\RoleBlockExpander
 * @uses   \OCA\OpenRegister\Service\Object\DenialQuestion
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses   \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses   \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyQuestion
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantLookup
 * @uses   \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses \OCA\OpenRegister\Service\Rbac\DenyQuestion
 * @uses \OCA\OpenRegister\Service\Rbac\GrantLookup
 * @uses \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Service\Rbac\UndecidablePosture
 */
class PermissionHandlerReadGrantAdmitsTest extends TestCase {

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
		$this->schemaCounter = 700;
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

		return \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
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
			new DenyResolver(matcher: new DenyEntryMatcher()),
			new DenyEnforcementMode(appConfig: $appConfig, logger: $logger)
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
				if ($id === RegisterMapperInterface::class) {
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
	 * A grant resolver answering one verdict per action.
	 *
	 * @param array<string, bool> $byAction The verdict per action.
	 *
	 * @return ObjectGrantResolver The resolver.
	 */
	private function grantsFor(array $byAction): ObjectGrantResolver {
		$grants = $this->createMock(originalClassName: ObjectGrantResolver::class);
		$grants->method('isGranted')->willReturnCallback(
			static fn (mixed $lookup, string $action): bool => ($byAction[$action] ?? false)
		);

		return $grants;
	}//end grantsFor()

	/**
	 * A schema whose read rule admits only an audience the caller is not in.
	 *
	 * @return Schema The schema.
	 */
	private function audienceSchema(): Schema {
		return $this->schemaWith(authorization: ['read' => ['redactie'], 'update' => ['redactie']]);
	}//end audienceSchema()

	/**
	 * The floor: without a grant, the schema's rules refuse the caller.
	 *
	 * @return void
	 */
	public function testWithoutAGrantTheSchemaRefuses(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], grants: $this->grantsFor(byAction: []));

		$this->assertFalse(
			condition: $handler->hasPermission(
				ask: (new PermissionAsk(schema: $this->audienceSchema(), action: 'read'))
					->askedBy(userId: 'ana', objectOwner: null)
					->about(object: $this->objectWith(authorization: null), register: null)
			)
		);
	}//end testWithoutAGrantTheSchemaRefuses()

	/**
	 * A read grant admits the object although the schema's rules do not list the caller.
	 *
	 * @return void
	 */
	public function testAReadGrantAdmitsTheObjectOnItsOwn(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], grants: $this->grantsFor(byAction: ['read' => true]));

		$this->assertTrue(
			condition: $handler->hasPermission(
				ask: (new PermissionAsk(schema: $this->audienceSchema(), action: 'read'))
					->askedBy(userId: 'ana', objectOwner: null)
					->about(object: $this->objectWith(authorization: null), register: null)
			)
		);
	}//end testAReadGrantAdmitsTheObjectOnItsOwn()

	/**
	 * A read grant opens a private object the schema's rules refuse too.
	 *
	 * @return void
	 */
	public function testAReadGrantAdmitsAPrivateObjectOutsideTheRules(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], grants: $this->grantsFor(byAction: ['read' => true]));

		$this->assertTrue(
			condition: $handler->hasPermission(
				ask: (new PermissionAsk(schema: $this->audienceSchema(), action: 'read'))
					->askedBy(userId: 'ana', objectOwner: null)
					->about(object: $this->objectWith(authorization: ['scope' => ObjectScopeResolver::SCOPE_PRIVATE]), register: null)
			)
		);
	}//end testAReadGrantAdmitsAPrivateObjectOutsideTheRules()

	/**
	 * An update grant does not admit a write the schema's rules refuse.
	 *
	 * @return void
	 */
	public function testAnUpdateGrantStaysWithinTheSchemaRules(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], grants: $this->grantsFor(byAction: ['read' => true, 'update' => true]));

		$this->assertFalse(
			condition: $handler->hasPermission(
				ask: (new PermissionAsk(schema: $this->audienceSchema(), action: 'update'))
					->askedBy(userId: 'ana', objectOwner: null)
					->about(object: $this->objectWith(authorization: null), register: null)
			)
		);
	}//end testAnUpdateGrantStaysWithinTheSchemaRules()

	/**
	 * A deny still beats a read grant.
	 *
	 * @return void
	 */
	public function testADenyBeatsAReadGrant(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], grants: $this->grantsFor(byAction: ['read' => true]));

		$this->assertFalse(
			condition: $handler->hasPermission(
				ask: (new PermissionAsk(schema: $this->schemaWith(authorization: ['read' => ['redactie'], 'deny' => ['read' => ['gasten']]]), action: 'read'))
					->askedBy(userId: 'ana', objectOwner: null)
					->about(object: $this->objectWith(authorization: null), register: null)
			)
		);
	}//end testADenyBeatsAReadGrant()

	/**
	 * A read grant names an object: a schema-level question without one is not widened.
	 *
	 * @return void
	 */
	public function testAReadGrantDoesNotWidenASchemaLevelQuestion(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten'], grants: $this->grantsFor(byAction: ['read' => true]));

		$this->assertFalse(
			condition: $handler->hasPermission(
				ask: (new PermissionAsk(schema: $this->audienceSchema(), action: 'read'))
					->askedBy(userId: 'ana', objectOwner: null)
			)
		);
	}//end testAReadGrantDoesNotWidenASchemaLevelQuestion()
}//end class
