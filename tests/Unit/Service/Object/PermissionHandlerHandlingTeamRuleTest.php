<?php

/**
 * A team rule over a list of field values and an assignee rule, evaluated for real.
 *
 * The shape a leaf app writes when the people who handle a kind of record read
 * those records, and the person a record is assigned to reads that one:
 *
 *     {"group": "vergunningen", "match": {"caseType": {"$in": ["ct-permit"]}}}
 *     {"group": "authenticated", "match": {"assignee": "$userId"}}
 *
 * Asserted through `hasPermission()` with the REAL ConditionMatcher and
 * OperatorEvaluator, and through `ObjectFileAccess::mayUpdate()`, which is what
 * the object folder endpoint answers `canChange` from. Found live on 2026-10-11:
 * dossiq's case schema carried `roles` only, the register had no role
 * definitions, and a user in no group read every case and got `canChange: true`
 * on its folder. The last two tests pin the closed reading of that block.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/roles-block-that-resolves-to-nothing-grants-nothing/specs/rbac-scopes/spec.md
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
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @covers \OCA\OpenRegister\Service\File\ObjectFileAccess
 *
 * @spec openspec/changes/roles-block-that-resolves-to-nothing-grants-nothing/specs/rbac-scopes/spec.md
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\ConditionMatcher
 * @uses \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class PermissionHandlerHandlingTeamRuleTest extends TestCase {

	/**
	 * Group membership per user id.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const GROUPS = [
		'outsider' => [],
		'bea' => ['vergunningen'],
		'piet' => [],
		'admin' => ['admin'],
	];

	/**
	 * The assignee rule.
	 *
	 * @var array<string, mixed>
	 */
	private const ASSIGNEE = ['group' => 'authenticated', 'match' => ['assignee' => '$userId']];

	/**
	 * The team rule.
	 *
	 * @var array<string, mixed>
	 */
	private const TEAM = ['group' => 'vergunningen', 'match' => ['caseType' => ['$in' => ['ct-permit']]]];

	/**
	 * Who is signed in.
	 *
	 * @var string
	 */
	private string $current = 'outsider';

	/**
	 * The handler under test.
	 *
	 * @var PermissionHandler
	 */
	private PermissionHandler $handler;

	/**
	 * The schema mapper, answering the schema under test.
	 *
	 * @var SchemaMapper
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * Build a handler with the real matcher and a register without role definitions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(fn (): IUser => $this->user(uid: $this->current));

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user(uid: $uid));

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => self::GROUPS[$user->getUID()] ?? []
		);

		$register = new Register();
		$register->setId(20);
		$register->setAuthorization(null);
		$register->setConfiguration([]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(20);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($registerMapper) {
				if ($class === RegisterMapper::class) {
					return $registerMapper;
				}

				throw new \RuntimeException('Not available: ' . $class);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $default
		);

		$matcher = new ConditionMatcher(
			$userSession,
			$this->createMock(ContainerInterface::class),
			new OperatorEvaluator(new NullLogger()),
			new NullLogger(),
			$groupManager
		);

		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->handler = new PermissionHandler(
			$userSession,
			$userManager,
			$groupManager,
			$this->schemaMapper,
			$this->createMock(MagicMapper::class),
			$matcher,
			$appConfig,
			new NullLogger(),
			$container
		);
	}//end setUp()

	/**
	 * An account.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser The account.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * A schema with the given block.
	 *
	 * @param array<string, mixed> $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(35);
		$schema->setTitle('Case');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * The block a leaf app ships: roles for routing, plus the team and assignee rules.
	 *
	 * @return Schema The schema.
	 */
	private function caseSchema(): Schema {
		return $this->schema(
			[
				'roles' => ['behandelaar' => ['behandelaars']],
				'read' => [self::ASSIGNEE, 'dossiq-background-service', self::TEAM],
				'create' => ['dossiq-medewerkers', 'dossiq-background-service'],
				'update' => [self::ASSIGNEE, 'dossiq-background-service', self::TEAM],
				'delete' => ['admin'],
			]
		);
	}//end caseSchema()

	/**
	 * An object owned by admin.
	 *
	 * @param string $caseType The type field.
	 * @param string $assignee The assignee field.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $caseType, string $assignee): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-' . $caseType . '-' . $assignee);
		$object->setSchema('35');
		$object->setOwner('admin');
		$object->setObject(['caseType' => $caseType, 'assignee' => $assignee]);

		return $object;
	}//end object()

	/**
	 * Ask as one user.
	 *
	 * @param string       $uid    The user.
	 * @param Schema       $schema The schema.
	 * @param string       $action The verb.
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool The verdict.
	 */
	private function may(string $uid, Schema $schema, string $action, ObjectEntity $object): bool {
		$this->current = $uid;
		$this->handler->clearPermissionCache();

		return $this->handler->hasPermission(
			schema: $schema,
			action: $action,
			objectOwner: $object->getOwner(),
			object: $object
		);
	}//end may()

	/**
	 * A user in no group and not assigned is refused every verb.
	 *
	 * @return void
	 */
	public function testAUserInNoGroupIsRefused(): void {
		$case = $this->object(caseType: 'ct-permit', assignee: 'piet');

		foreach (['read', 'update', 'delete'] as $verb) {
			$this->assertFalse($this->may(uid: 'outsider', schema: $this->caseSchema(), action: $verb, object: $case), $verb);
		}
	}//end testAUserInNoGroupIsRefused()

	/**
	 * A team member reads and updates the records of the types the team handles, and deletes none.
	 *
	 * @return void
	 */
	public function testATeamMemberReadsAndUpdatesItsTypesOnly(): void {
		$permit = $this->object(caseType: 'ct-permit', assignee: 'piet');
		$other = $this->object(caseType: 'ct-other', assignee: 'piet');
		$schema = $this->caseSchema();

		$this->assertTrue($this->may(uid: 'bea', schema: $schema, action: 'read', object: $permit));
		$this->assertTrue($this->may(uid: 'bea', schema: $schema, action: 'update', object: $permit));
		$this->assertFalse($this->may(uid: 'bea', schema: $schema, action: 'delete', object: $permit));
		$this->assertFalse($this->may(uid: 'bea', schema: $schema, action: 'read', object: $other));
	}//end testATeamMemberReadsAndUpdatesItsTypesOnly()

	/**
	 * The assignee reads their record outside every team, and no other.
	 *
	 * @return void
	 */
	public function testTheAssigneeReadsTheirOwnRecord(): void {
		$schema = $this->caseSchema();

		$this->assertTrue($this->may(uid: 'piet', schema: $schema, action: 'read', object: $this->object(caseType: 'ct-other', assignee: 'piet')));
		$this->assertFalse($this->may(uid: 'piet', schema: $schema, action: 'read', object: $this->object(caseType: 'ct-other', assignee: 'bea')));
	}//end testTheAssigneeReadsTheirOwnRecord()

	/**
	 * An administrator keeps every verb.
	 *
	 * @return void
	 */
	public function testAnAdministratorKeepsEverything(): void {
		$case = $this->object(caseType: 'ct-other', assignee: 'piet');

		foreach (['read', 'update', 'delete'] as $verb) {
			$this->assertTrue($this->may(uid: 'admin', schema: $this->caseSchema(), action: $verb, object: $case), $verb);
		}
	}//end testAnAdministratorKeepsEverything()

	/**
	 * The folder answers `canChange` from the same rule.
	 *
	 * @return void
	 */
	public function testTheFolderAnswersCanChangeFromTheSameRule(): void {
		$schema = $this->caseSchema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$access = new ObjectFileAccess(
			$this->createMock(ObjectService::class),
			$this->handler,
			$this->schemaMapper
		);
		$case = $this->object(caseType: 'ct-permit', assignee: 'piet');

		$this->current = 'outsider';
		$this->handler->clearPermissionCache();
		$this->assertFalse($access->mayUpdate(object: $case));

		$this->current = 'bea';
		$this->handler->clearPermissionCache();
		$this->assertTrue($access->mayUpdate(object: $case));
	}//end testTheFolderAnswersCanChangeFromTheSameRule()

	/**
	 * A roles-only block on a register without role definitions closes the schema.
	 *
	 * The live defect: this block used to expand to nothing and read as open.
	 *
	 * @return void
	 */
	public function testARolesOnlyBlockThatResolvesToNothingIsClosed(): void {
		$schema = $this->schema(['roles' => ['behandelaar' => ['behandelaars']]]);
		$case = $this->object(caseType: 'ct-permit', assignee: 'piet');

		foreach (['read', 'create', 'update', 'delete'] as $verb) {
			$this->assertFalse($this->may(uid: 'outsider', schema: $schema, action: $verb, object: $case), $verb);
		}

		$this->assertTrue($this->may(uid: 'admin', schema: $schema, action: 'read', object: $case));
	}//end testARolesOnlyBlockThatResolvesToNothingIsClosed()

	/**
	 * The folder of such a schema does not offer a change.
	 *
	 * @return void
	 */
	public function testARolesOnlyBlockGivesNoCanChange(): void {
		$schema = $this->schema(['roles' => ['behandelaar' => ['behandelaars']]]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$access = new ObjectFileAccess($this->createMock(ObjectService::class), $this->handler, $this->schemaMapper);

		$this->current = 'outsider';
		$this->handler->clearPermissionCache();
		$this->assertFalse($access->mayUpdate(object: $this->object(caseType: 'ct-permit', assignee: 'piet')));
	}//end testARolesOnlyBlockGivesNoCanChange()
}//end class
