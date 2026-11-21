<?php

/**
 * The owner sentinel and condition scopes on both enforcement paths.
 *
 * `@creator` and `conditions` are read by AuthorizationBlock in
 * PermissionHandler::resolveAuthorization(), the step the single-object check
 * and the list emitter share. Each case here asks both paths the same question
 * and expects the same rows (access-owner-and-condition-scopes task 1.2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-owner-and-condition-scopes/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacPermissionAsk;
use OCA\OpenRegister\Db\MagicMapper\WhereClauseRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Service\Rbac\MemberOfResolver;
use OCA\OpenRegister\Service\Rbac\SchemaRegisterLookup;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;

/**
 * Both enforcement paths give the same answer for the new row keys.
 */
/**
 * Tests for `@creator` and condition scopes on the PHP and SQL paths.
 */
class OwnerAndConditionScopesBothPathsTest extends TestCase {

	/**
	 * The rows: owner and behandelaar per uuid.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const ROWS = [
		'v-1' => ['anna', 'carla'],
		'v-2' => ['bram', 'anna'],
		'v-3' => ['carla', 'bram'],
	];

	/**
	 * Nobody holds a group that the blocks below name.
	 *
	 * @var array<string, list<string>>
	 */
	private const GROUPS = [
		'anna' => ['medewerkers'],
		'bram' => ['medewerkers'],
		'carla' => ['medewerkers'],
	];

	/**
	 * A schema with the given authorization block and a `behandelaar` property.
	 *
	 * @param array<string, mixed> $authorization The block as a designer stores it.
	 *
	 * @return Schema
	 */
	private static function schema(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(72);
		$schema->setSlug('verzoek');
		$schema->setProperties(['behandelaar' => ['type' => 'string']]);
		$schema->setAuthorization($authorization);
		return $schema;
	}//end schema()

	/**
	 * A user session, group manager and user manager for one user.
	 *
	 * @param string $uid The user.
	 *
	 * @return array{0: IUserSession, 1: IGroupManager, 2: IUserManager}
	 */
	private function identity(string $uid): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn((self::GROUPS[$uid] ?? []));
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => in_array($group, (self::GROUPS[$uid] ?? []), true)
		);

		return [$session, $groups, $users];
	}//end identity()

	/**
	 * The real permission handler and the real list handler for one user.
	 *
	 * @param string $uid The user.
	 *
	 * @return array{0: PermissionHandler, 1: MagicRbacHandler}
	 */
	public function handlers(string $uid, ?\OCP\EventDispatcher\IEventDispatcher $dispatcher = null): array {
		[$session, $groups, $users] = $this->identity(uid: $uid);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $default
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$registers = $this->createMock(RegisterMapper::class);
		$objects = $this->createMock(MagicMapper::class);
		$evaluator = new OperatorEvaluator(new NullLogger());

		$container = $this->createMock(ContainerInterface::class);
		$matcher = new ConditionMatcher(
			new ConditionUserResolvers($session, $groups, new MemberOfResolver(new SchemaRegisterLookup($schemas, $registers), $container, $evaluator, new NullLogger())),
			$container,
			$evaluator,
			new NullLogger()
		);

		$permissions = \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			$session,
			$users,
			$groups,
			$schemas,
			$objects,
			$matcher,
			$appConfig,
			new NullLogger(),
			$container,
			$dispatcher
		);

		$container->method('get')->willReturnCallback(
			static function (string $id) use ($permissions, $registers, $objects) {
				if ($id === PermissionHandlerInterface::class) {
					return $permissions;
				}

				if ($id === RegisterMapper::class) {
					return $registers;
				}

				if ($id === MagicMapper::class) {
					return $objects;
				}

				throw new \RuntimeException('Not in this test: ' . $id);
			}
		);

		$rbac = new MagicRbacHandler(
			identity: new MagicMapperIdentity(
				userSession: $session,
				groupManager: $groups,
				userManager: $users
			),
			settings: new MagicRbacSettings(
				appConfig: $appConfig
			),
			support: new MagicRbacSupport(
				conditionMatcher: $matcher,
				container: $container,
				logger: new NullLogger()
			)
		);

		return [$permissions, $rbac];
	}//end handlers()

	/**
	 * The rows the SQL path lists for the caller.
	 *
	 * @param MagicRbacHandler $rbac   The handler for the caller.
	 * @param Schema           $schema The schema.
	 *
	 * @return list<string> The uuids.
	 */
	private function listed(MagicRbacHandler $rbac, Schema $schema): array {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(
			static fn ($value): string => "'" . str_replace("'", "''", (string)$value) . "'"
		);
		$queryBuilder = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());

		$organisation = $this->createMock(MagicOrganizationHandler::class);
		$organisation->method('resolveOrganizationScope')->willReturn(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-home']]);
		$organisation->method('isAdminOverrideEnabled')->willReturn(false);

		$search = new MagicSearchHandler(
			$db,
			new NullLogger(),
			$rbac,
			$organisation,
			$this->createMock(SchemaTypeConverter::class),
			$this->createMock(DateTimeNormalizer::class),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
		$conditions = $search->buildWhereClause(request: new WhereClauseRequest(query: [], table: new MagicReadTable(schema: $schema)));

		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$pdo->exec(
			'CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT,'
			. ' _deleted TEXT, _archived TEXT, _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT,'
			. ' behandelaar TEXT)'
		);
		$insert = $pdo->prepare("INSERT INTO t (_uuid, _owner, _organisation, behandelaar) VALUES (?, ?, 'org-home', ?)");
		foreach (self::ROWS as $uuid => [$owner, $handler]) {
			$insert->execute([$uuid, $owner, $handler]);
		}

		$where = '';
		if ($conditions !== []) {
			$where = ' WHERE ' . implode(' AND ', $conditions);
		}

		return $pdo->query('SELECT _uuid FROM t' . $where . ' ORDER BY _uuid')->fetchAll(PDO::FETCH_COLUMN);
	}//end listed()

	/**
	 * The rows the PHP path admits for the caller and action.
	 *
	 * @param PermissionHandler $permissions The handler.
	 * @param Schema            $schema      The schema.
	 * @param string            $uid         The caller.
	 * @param string            $action      The action.
	 *
	 * @return list<string> The uuids.
	 */
	private static function admitted(PermissionHandler $permissions, Schema $schema, string $uid, string $action = 'read'): array {
		$admitted = [];
		foreach (self::ROWS as $uuid => [$owner, $handler]) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setOwner($owner);
			$object->setObject(['behandelaar' => $handler]);
			if ($permissions->hasPermission(schema: $schema, action: $action, userId: $uid, objectOwner: $owner, object: $object) === true) {
				$admitted[] = $uuid;
			}
		}

		sort($admitted);
		return $admitted;
	}//end admitted()

	/**
	 * `read: ["@creator"]`: each user lists and reads only the record they created.
	 *
	 * @return void
	 */
	public function testEachUserSeesOnlyTheirOwnRecordOnBothPaths(): void {
		$schema = self::schema(authorization: ['read' => ['@creator'], 'update' => ['@creator']]);
		foreach (['anna' => ['v-1'], 'bram' => ['v-2'], 'carla' => ['v-3']] as $uid => $expected) {
			[$permissions, $rbac] = $this->handlers(uid: $uid);

			$this->assertSame($expected, self::admitted($permissions, $schema, $uid), $uid . ' on the PHP path');
			$this->assertSame($expected, $this->listed($rbac, $schema), $uid . ' on the SQL path');
			$this->assertSame($expected, self::admitted($permissions, $schema, $uid, 'update'), $uid . ' writes only their own');
		}
	}

	/**
	 * A condition on the caller: each user also sees the rows they handle.
	 *
	 * @return void
	 */
	public function testAConditionOnTheCallerAdmitsTheRowsTheyHandle(): void {
		$schema = self::schema(
			authorization: [
				'read' => ['@creator'],
				'conditions' => ['read' => ['field' => 'behandelaar', 'operator' => 'equals', 'value' => '@user.uid']],
			]
		);
		foreach (['anna' => ['v-1', 'v-2'], 'carla' => ['v-1', 'v-3']] as $uid => $expected) {
			[$permissions, $rbac] = $this->handlers(uid: $uid);

			$this->assertSame($expected, self::admitted($permissions, $schema, $uid), $uid . ' on the PHP path');
			$this->assertSame($expected, $this->listed($rbac, $schema), $uid . ' on the SQL path');
		}
	}

	/**
	 * A condition with a literal admits the matching row to every signed-in user.
	 *
	 * @return void
	 */
	public function testALiteralConditionAdmitsTheMatchingRow(): void {
		$schema = self::schema(
			authorization: [
				'read' => [],
				'conditions' => ['read' => ['field' => 'behandelaar', 'operator' => 'equals', 'value' => 'bram']],
			]
		);
		[$permissions, $rbac] = $this->handlers(uid: 'anna');

		$this->assertSame(['v-1', 'v-3'], self::admitted($permissions, $schema, 'anna'));
		$this->assertSame(['v-1', 'v-3'], $this->listed($rbac, $schema));
	}

	/**
	 * An unreadable condition admits nobody beyond the owner.
	 *
	 * @return void
	 */
	public function testAnUnreadableConditionAdmitsOnlyTheOwner(): void {
		$schema = self::schema(
			authorization: [
				'read' => ['@creator'],
				'conditions' => ['read' => ['field' => 'behandelaar', 'operator' => 'contains', 'value' => 'a']],
			]
		);
		[$permissions, $rbac] = $this->handlers(uid: 'anna');

		$this->assertSame(['v-1'], self::admitted($permissions, $schema, 'anna'));
		$this->assertSame(['v-1'], $this->listed($rbac, $schema));
	}
}//end class
