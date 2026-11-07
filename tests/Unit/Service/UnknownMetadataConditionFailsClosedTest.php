<?php

/**
 * A stored rule conditioned on metadata no record carries fails closed.
 *
 * A deny on such a key denies, an allow on it grants nothing, on the single
 * object path and on the list query alike.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
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
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unknown metadata in a stored rule: deny denies, allow does not grant, logged once.
 *
 * @covers \OCA\OpenRegister\Service\ConditionMatcher
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacPermissionCheck
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacResolvers
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 */
class UnknownMetadataConditionFailsClosedTest extends TestCase {

	/**
	 * The caller's session: `alice`, in group `editors`.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The caller's groups.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The logger the matcher writes to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The matcher under test, wired to that logger.
	 *
	 * @var ConditionMatcher
	 */
	private ConditionMatcher $matcher;

	/**
	 * Build the caller and a real matcher.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroupIds')->willReturn(['editors']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not available'));

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->matcher = new ConditionMatcher(
			userSession: $this->userSession,
			container: $container,
			operatorEvaluator: new OperatorEvaluator(logger: new NullLogger()),
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * A record of schema 7 in `org-a`, owned by `bob`.
	 *
	 * @param array<string, mixed> $data The record's data.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(array $data = ['title' => 'x']): ObjectEntity {
		$record = new ObjectEntity();
		$record->setUuid('rec-1');
		$record->setSchema('7');
		$record->setOrganisation('org-a');
		$record->setOwner('bob');
		$record->setObject($data);

		return $record;
	}//end record()

	/**
	 * Schema 7 with one block.
	 *
	 * @param array $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * The single-object handler, enforcing denies.
	 *
	 * @return PermissionHandler The handler.
	 */
	private function permissionHandler(): PermissionHandler {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->userSession->getUser());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not available'));

		return new PermissionHandler(
			userSession: $this->userSession,
			userManager: $userManager,
			groupManager: $this->groupManager,
			schemaMapper: $this->createMock(SchemaMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			conditionMatcher: $this->matcher,
			appConfig: $this->enforcingConfig(),
			logger: new NullLogger(),
			container: $container
		);
	}//end permissionHandler()

	/**
	 * The list-query handler, enforcing denies.
	 *
	 * @return MagicRbacHandler The handler.
	 */
	private function rbacHandler(): MagicRbacHandler {
		return new MagicRbacHandler(
			$this->userSession,
			$this->groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->matcher,
			$this->createMock(ContainerInterface::class),
			new NullLogger(),
			new RbacResolvers(objectScopeResolver: null, objectGrantResolver: null, denyResolver: new DenyResolver(new DenyEntryMatcher())),
			new DenyEnforcementMode($this->enforcingConfig(), new NullLogger())
		);
	}//end rbacHandler()

	/**
	 * An app config that sets denies to enforcing and leaves the rest at default.
	 *
	 * @return IAppConfig The config.
	 */
	private function enforcingConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $default
		);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === DenyEnforcementMode::CONFIG_KEY) {
					return DenyEnforcementMode::MODE_ENFORCING;
				}

				return $default;
			}
		);

		return $appConfig;
	}//end enforcingConfig()

	/**
	 * Conditions on `_published` that a missing value would satisfy.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function conditionsANullSatisfies(): array {
		return [
			'not equal to true' => [['_published' => ['$ne' => true]]],
			'null'              => [['_published' => null]],
			'does not exist'    => [['_published' => ['$exists' => false]]],
			'not in a list'     => [['_published' => ['$nin' => ['2020-01-01']]]],
		];
	}//end conditionsANullSatisfies()

	/**
	 * An allow condition on unknown metadata is never met, even one that a missing value satisfies.
	 *
	 * @param array<string, mixed> $match The condition.
	 *
	 * @return void
	 *
	 * @dataProvider conditionsANullSatisfies
	 */
	public function testAnUnknownMetadataConditionIsNeverMet(array $match): void {
		$this->assertFalse($this->matcher->objectMatchesConditions(object: $this->record()->getMatchEnvelope(), match: $match));
		$this->assertFalse($this->matcher->objectMatchesConditions(object: ['title' => 'x'], match: $match));
	}//end testAnUnknownMetadataConditionIsNeverMet()

	/**
	 * The control: the same shapes on a body property are met by a missing value.
	 *
	 * @return void
	 */
	public function testTheSameConditionOnABodyPropertyIsMet(): void {
		$this->assertTrue($this->matcher->objectMatchesConditions(object: $this->record()->getMatchEnvelope(), match: ['published' => null]));
		$this->assertTrue($this->matcher->objectMatchesConditions(object: $this->record()->getMatchEnvelope(), match: ['published' => ['$exists' => false]]));
		$this->assertFalse($this->matcher->namesUnknownMetadata(match: ['_organisation' => 'org-a', 'published' => null]));
	}//end testTheSameConditionOnABodyPropertyIsMet()

	/**
	 * The refusal is logged once per schema and key, with both named.
	 *
	 * @return void
	 */
	public function testTheRefusalIsLoggedOncePerSchemaAndKey(): void {
		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('_published'),
				$this->callback(static fn (array $context): bool => $context['schema'] === '7' && $context['key'] === '_published')
			);

		$envelope = $this->record()->getMatchEnvelope();
		$this->assertTrue($this->matcher->namesUnknownMetadata(match: ['_published' => true], object: $envelope));
		$this->matcher->objectMatchesConditions(object: $envelope, match: ['_published' => ['$ne' => true]]);
		$this->matcher->objectMatchesConditions(object: $envelope, match: ['_published' => null]);
	}//end testTheRefusalIsLoggedOncePerSchemaAndKey()

	/**
	 * A stored conditional deny on `_published` denies on the single-object path.
	 *
	 * @return void
	 */
	public function testAConditionalDenyOnUnknownMetadataDenies(): void {
		$denied = $this->schemaWith(
			['update' => [['group' => 'editors', 'match' => ['_organisation' => 'org-a']]], 'deny' => ['update' => [['group' => 'editors', 'match' => ['_published' => ['$lte' => '$now']]]]]]
		);
		$control = $this->schemaWith(['update' => [['group' => 'editors', 'match' => ['_organisation' => 'org-a']]]]);
		$otherDeny = $this->schemaWith(
			['update' => [['group' => 'editors', 'match' => ['_organisation' => 'org-a']]], 'deny' => ['update' => [['group' => 'editors', 'match' => ['_organisation' => 'org-z']]]]]
		);

		$this->assertFalse($this->permissionHandler()->hasPermission(schema: $denied, action: 'update', userId: 'alice', objectOwner: 'bob', object: $this->record()));
		$this->assertTrue($this->permissionHandler()->hasPermission(schema: $control, action: 'update', userId: 'alice', objectOwner: 'bob', object: $this->record()));
		$this->assertTrue($this->permissionHandler()->hasPermission(schema: $otherDeny, action: 'update', userId: 'alice', objectOwner: 'bob', object: $this->record()));
	}//end testAConditionalDenyOnUnknownMetadataDenies()

	/**
	 * A stored allow on `_published` grants nothing on the single-object path.
	 *
	 * @return void
	 */
	public function testAnAllowOnUnknownMetadataDoesNotGrant(): void {
		$allow = $this->schemaWith(['update' => [['group' => 'editors', 'match' => ['_published' => ['$exists' => false]]]]]);
		$control = $this->schemaWith(['update' => [['group' => 'editors', 'match' => ['_organisation' => 'org-a']]]]);

		$this->assertFalse($this->permissionHandler()->hasPermission(schema: $allow, action: 'update', userId: 'alice', objectOwner: 'bob', object: $this->record()));
		$this->assertTrue($this->permissionHandler()->hasPermission(schema: $control, action: 'update', userId: 'alice', objectOwner: 'bob', object: $this->record()));
	}//end testAnAllowOnUnknownMetadataDoesNotGrant()

	/**
	 * On the relation path, a conditional deny on unknown metadata denies the row.
	 *
	 * @return void
	 */
	public function testTheRelationPathDeniesTheRow(): void {
		$handler = $this->rbacHandler();
		$schema = $this->schemaWith(['read' => ['editors']]);

		$this->assertFalse(
			$handler->hasPermission(
				schema: $schema,
				action: 'read',
				objectOwner: 'bob',
				objectData: $this->record()->getMatchEnvelope(),
				objectAuthorization: ['deny' => ['read' => [['group' => 'editors', 'match' => ['_published' => true]]]]],
				objectUuid: 'rec-1'
			)
		);
		$this->assertTrue(
			$handler->hasPermission(
				schema: $schema,
				action: 'read',
				objectOwner: 'bob',
				objectData: $this->record()->getMatchEnvelope(),
				objectAuthorization: ['deny' => ['read' => [['group' => 'editors', 'match' => ['_organisation' => 'org-z']]]]],
				objectUuid: 'rec-1'
			)
		);
	}//end testTheRelationPathDeniesTheRow()

	/**
	 * The control on the relation path: a deny on a known key that selects the row denies it.
	 *
	 * @return void
	 */
	public function testTheRelationPathDeniesOnAKnownKeyThatMatches(): void {
		$this->assertFalse(
			$this->rbacHandler()->hasPermission(
				schema: $this->schemaWith(['read' => ['editors']]),
				action: 'read',
				objectOwner: 'bob',
				objectData: $this->record()->getMatchEnvelope(),
				objectAuthorization: ['deny' => ['read' => [['group' => 'editors', 'match' => ['_organisation' => 'org-a']]]]],
				objectUuid: 'rec-1'
			)
		);
	}//end testTheRelationPathDeniesOnAKnownKeyThatMatches()

	/**
	 * A `@self.` key outside the envelope is refused like its underscore spelling.
	 *
	 * @return void
	 */
	public function testAnUnknownSelfKeyFailsClosed(): void {
		$envelope = $this->record()->getMatchEnvelope();
		$match = ['@self.published' => null];

		$this->assertFalse($this->matcher->objectMatchesConditions(object: $envelope, match: $match));
		$this->assertFalse(
			$this->permissionHandler()->hasPermission(
				schema: $this->schemaWith(['update' => [['group' => 'editors', 'match' => $match]]]),
				action: 'update',
				userId: 'alice',
				objectOwner: 'bob',
				object: $this->record()
			)
		);
		$this->assertFalse(
			$this->permissionHandler()->hasPermission(
				schema: $this->schemaWith(
					[
						'update' => [['group' => 'editors', 'match' => ['_organisation' => 'org-a']]],
						'deny'   => ['update' => [['group' => 'editors', 'match' => ['@self.published' => true]]]],
					]
				),
				action: 'update',
				userId: 'alice',
				objectOwner: 'bob',
				object: $this->record()
			)
		);
	}//end testAnUnknownSelfKeyFailsClosed()

	/**
	 * A `@self.` key inside the envelope reads the record, so `{@self.owner: null}` is not met and a deny on it fires.
	 *
	 * @return void
	 */
	public function testAKnownSelfKeyReadsTheRecord(): void {
		$envelope = $this->record()->getMatchEnvelope();

		$this->assertFalse($this->matcher->objectMatchesConditions(object: $envelope, match: ['@self.owner' => null]));
		$this->assertTrue($this->matcher->objectMatchesConditions(object: $envelope, match: ['@self.owner' => 'bob']));
		$this->assertTrue($this->matcher->objectMatchesConditions(object: $envelope, match: ['@self.uuid' => 'rec-1']));
		$this->assertFalse(
			$this->permissionHandler()->hasPermission(
				schema: $this->schemaWith(
					[
						'update' => [['group' => 'editors', 'match' => ['_organisation' => 'org-a']]],
						'deny'   => ['update' => [['group' => 'editors', 'match' => ['@self.owner' => 'bob']]]],
					]
				),
				action: 'update',
				userId: 'alice',
				objectOwner: 'bob',
				object: $this->record()
			)
		);
	}//end testAKnownSelfKeyReadsTheRecord()

	/**
	 * On the list query a known `@self.` key names its column, and `_expires` its real column.
	 *
	 * @return void
	 */
	public function testTheListNamesTheColumnOfAKnownKey(): void {
		$sql = fn (array $match): string => implode(
			' OR ',
			$this->rbacHandler()->buildRbacConditionsSql(
				$this->schemaWith(['read' => [['group' => 'editors', 'match' => $match]]]),
				'read'
			)['conditions']
		);

		$this->assertStringContainsString("`_owner` = 'bob'", $sql(['@self.owner' => 'bob']));
		$this->assertStringContainsString("`_uuid` = 'rec-1'", $sql(['@self.id' => 'rec-1']));
		$this->assertStringContainsString("`_expires` > '2030-01-01'", $sql(['_expires' => ['$gt' => '2030-01-01']]));
		$this->assertStringNotContainsString('1 = 0', $sql(['_expires' => ['$gt' => '2030-01-01']]));
	}//end testTheListNamesTheColumnOfAKnownKey()

	/**
	 * On the list query, a conditional deny on unknown metadata empties the list for the caller.
	 *
	 * @return void
	 */
	public function testTheListDeniesEveryRow(): void {
		$handler = $this->rbacHandler();
		$denied = $handler->buildRbacConditionsSql(
			$this->schemaWith(['read' => ['editors'], 'deny' => ['read' => [['group' => 'editors', 'match' => ['_published' => true]]]]]),
			'read'
		);
		$control = $handler->buildRbacConditionsSql(
			$this->schemaWith(['read' => ['editors'], 'deny' => ['read' => [['group' => 'editors', 'match' => ['_organisation' => 'org-z']]]]]),
			'read'
		);

		$this->assertFalse($denied['bypass']);
		$this->assertSame([], $denied['conditions']);
		$this->assertNotSame([], $control['conditions']);
	}//end testTheListDeniesEveryRow()

	/**
	 * On the list query, an allow on unknown metadata admits no row and names no such column.
	 *
	 * @return void
	 */
	public function testTheListAllowAdmitsNoRow(): void {
		$result = $this->rbacHandler()->buildRbacConditionsSql(
			$this->schemaWith(['read' => [['group' => 'editors', 'match' => ['_published' => ['$ne' => true]]]]]),
			'read'
		);

		$sql = implode(' OR ', $result['conditions']);
		$this->assertStringNotContainsString('_published', $sql);
		$this->assertStringContainsString('1 = 0', $sql);
	}//end testTheListAllowAdmitsNoRow()

	/**
	 * On the QueryBuilder path, a rule on unknown metadata admits no row.
	 *
	 * @return void
	 */
	public function testTheQueryBuilderPathAdmitsNoRow(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($left, $right): string => 'eq('.$left.','.$right.')');
		$expr->method('orX')->willReturnCallback(
			static fn (...$parts): ICompositeExpression => new class(implode(' OR ', $parts)) implements ICompositeExpression {
				public function __construct(private readonly string $sql) {
				}

				public function addMultiple(array $parts = []): ICompositeExpression {
					return $this;
				}

				public function add($part): ICompositeExpression {
					return $this;
				}

				public function count(): int {
					return 1;
				}

				public function getType(): string {
					return 'OR';
				}

				public function __toString(): string {
					return $this->sql;
				}
			}
		);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): IParameter => new class((string)json_encode($value)) implements IParameter {
				public function __construct(private readonly string $sql) {
				}

				public function __toString(): string {
					return $this->sql;
				}
			}
		);

		$handler = $this->rbacHandler();
		$rule = static fn (array $match): array => ['read' => [['group' => 'editors', 'crossOrganisation' => true, 'match' => $match]]];
		$unknown = $handler->getCrossOrganisationBranch($qb, $this->schemaWith($rule(['_published' => true])));
		$control = $handler->getCrossOrganisationBranch($qb, $this->schemaWith($rule(['_organisation' => 'org-a'])));

		$this->assertSame('eq(1,0)', (string)$unknown);
		$this->assertSame('eq(t._organisation,"org-a")', (string)$control);
	}//end testTheQueryBuilderPathAdmitsNoRow()
}//end class
