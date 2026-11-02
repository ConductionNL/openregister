<?php

/**
 * Matrix rows with `where`, `until` and a group-id user source, on both paths.
 *
 * The chain is real except storage: PermissionHandler compiles the matrix, and
 * MagicRbacHandler and MagicSearchHandler build the list's WHERE conditions
 * through it. The conditions run against an SQLite table; the single-object
 * verdict runs through PermissionHandler::hasPermission(). Each case asserts the
 * two answers are the same set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Service\Rbac\MemberOfResolver;
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

/**
 * Both enforcement paths give the same answer for the new row keys.
 */
class MatrixRowConditionsBothPathsTest extends TestCase {

	/**
	 * Cases by uuid: team, status.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const CASES = [
		'c-1' => ['team-a', 's1'],
		'c-2' => ['team-a', 's2'],
		'c-3' => ['team-b', 's1'],
		'c-4' => ['team-b', 's2'],
	];

	/**
	 * Groups per user.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const GROUPS = [
		'anna' => ['intakers', 'team-a'],
		'bram' => ['intakers', 'team-b'],
		'carla' => ['inspectors', 'team-a'],
		'dirk' => ['intakers'],
	];

	/**
	 * The case schema with a matrix on `assignedGroup`.
	 *
	 * Intakers read their own team's cases and edit them in status `s1` only.
	 * Inspectors had a read row on team-a that ended yesterday.
	 *
	 * @return Schema
	 */
	public static function caseSchema(): Schema {
		$schema = new Schema();
		$schema->setId(71);
		$schema->setSlug('case');
		$schema->setProperties(
			[
				'assignedGroup' => ['type' => 'string'],
				'status' => ['type' => 'string'],
			]
		);
		$schema->setAuthorization(
			[
				'matrix' => [
					'field' => 'assignedGroup',
					'userSource' => ['groups' => true],
					'rows' => [
						['value' => '$self', 'group' => 'intakers', 'actions' => ['read']],
						['value' => '$self', 'group' => 'intakers', 'actions' => ['update'], 'where' => ['status' => ['s1']]],
						[
							'value' => 'team-a',
							'group' => 'inspectors',
							'actions' => ['read'],
							'until' => (new \DateTimeImmutable('-1 day'))->format(DATE_ATOM),
						],
					],
				],
			]
		);

		return $schema;
	}//end caseSchema()

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
		$groups->method('getUserGroupIds')->willReturn(self::GROUPS[$uid]);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => in_array($group, self::GROUPS[$uid], true)
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
			$session,
			$container,
			$evaluator,
			new NullLogger(),
			$groups,
			new MemberOfResolver($schemas, $registers, $container, $evaluator, new NullLogger())
		);

		$permissions = new PermissionHandler(
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
				if ($id === PermissionHandler::class) {
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

		$rbac = new MagicRbacHandler($session, $groups, $users, $appConfig, $matcher, $container, new NullLogger());

		return [$permissions, $rbac];
	}//end handlers()

	/**
	 * The uuids the list path returns for read.
	 *
	 * @param MagicRbacHandler $rbac   The list handler.
	 * @param Schema           $schema The schema.
	 * @param array            $cases  The rows of the table, uuid => [team, status].
	 *
	 * @return array<int, string>
	 */
	public static function listed(MagicRbacHandler $rbac, Schema $schema, array $cases = self::CASES): array {
		$test = new class('listed') extends TestCase {
		};
		$connection = $test->getMockBuilder(IDBConnection::class)->getMock();
		$connection->method('quote')->willReturnCallback(
			static fn ($value): string => "'" . str_replace("'", "''", (string)$value) . "'"
		);
		$queryBuilder = $test->getMockBuilder(\OCP\DB\QueryBuilder\IQueryBuilder::class)->getMock();
		$queryBuilder->method('getConnection')->willReturn($connection);
		$db = $test->getMockBuilder(IDBConnection::class)->getMock();
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());

		$organisation = $test->getMockBuilder(MagicOrganizationHandler::class)->disableOriginalConstructor()->getMock();
		$organisation->method('resolveOrganizationScope')->willReturn(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-home']]);
		$organisation->method('isAdminOverrideEnabled')->willReturn(false);

		$search = new MagicSearchHandler(
			$db,
			new NullLogger(),
			$rbac,
			$organisation,
			$test->getMockBuilder(SchemaTypeConverter::class)->disableOriginalConstructor()->getMock(),
			$test->getMockBuilder(DateTimeNormalizer::class)->disableOriginalConstructor()->getMock(),
			relatedRows: $test->getMockBuilder(RelatedRowQueryApplier::class)->disableOriginalConstructor()->getMock()
		);
		$conditions = $search->buildWhereConditionsSql(query: [], schema: $schema);

		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$pdo->exec(
			'CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT,'
			. ' _deleted TEXT, _archived TEXT, _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT,'
			. ' assigned_group TEXT, status TEXT)'
		);
		$insert = $pdo->prepare("INSERT INTO t (_uuid, _owner, _organisation, assigned_group, status) VALUES (?, 'importer', 'org-home', ?, ?)");
		foreach ($cases as $uuid => [$team, $status]) {
			$insert->execute([$uuid, $team, $status]);
		}

		$where = '';
		if ($conditions !== []) {
			$where = ' WHERE ' . implode(' AND ', $conditions);
		}

		return $pdo->query('SELECT _uuid FROM t' . $where . ' ORDER BY _uuid')->fetchAll(PDO::FETCH_COLUMN);
	}//end listed()

	/**
	 * The uuids the single-object verdict admits for one action.
	 *
	 * @param PermissionHandler $permissions The handler.
	 * @param Schema            $schema      The schema.
	 * @param string            $uid         The user.
	 * @param string            $action      The action.
	 * @param array             $cases       uuid => [team, status].
	 *
	 * @return array<int, string>
	 */
	public static function admitted(PermissionHandler $permissions, Schema $schema, string $uid, string $action, array $cases = self::CASES): array {
		$admitted = [];
		foreach ($cases as $uuid => [$team, $status]) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setOwner('importer');
			$object->setObject(['assignedGroup' => $team, 'status' => $status]);
			if ($permissions->hasPermission(schema: $schema, action: $action, userId: $uid, objectOwner: 'importer', object: $object) === true) {
				$admitted[] = $uuid;
			}
		}

		sort($admitted);
		return $admitted;
	}//end admitted()

	/**
	 * `$self` on a group-id field: a member reads their own team's cases.
	 *
	 * @return void
	 */
	public function testOwnTeamReadsOnlyItsOwnCasesOnBothPaths(): void {
		foreach (['anna' => ['c-1', 'c-2'], 'bram' => ['c-3', 'c-4']] as $uid => $expected) {
			[$permissions, $rbac] = $this->handlers(uid: $uid);
			$schema = self::caseSchema();

			$this->assertSame($expected, self::admitted($permissions, $schema, $uid, 'read'), $uid . ' on the PHP path');
			$this->assertSame($expected, self::listed($rbac, $schema), $uid . ' on the SQL path');
		}
	}//end testOwnTeamReadsOnlyItsOwnCasesOnBothPaths()

	/**
	 * A caller in no team gets nothing through `$self`, on both paths.
	 *
	 * @return void
	 */
	public function testNoTeamReadsNothingOnBothPaths(): void {
		[$permissions, $rbac] = $this->handlers(uid: 'dirk');
		$schema = self::caseSchema();

		$this->assertSame([], self::admitted($permissions, $schema, 'dirk', 'read'));
		$this->assertSame([], self::listed($rbac, $schema));
	}//end testNoTeamReadsNothingOnBothPaths()

	/**
	 * `where` limits the edit row to status s1; reading is unaffected.
	 *
	 * @return void
	 */
	public function testEditIsLimitedToTheRowsStatuses(): void {
		[$permissions, $rbac] = $this->handlers(uid: 'anna');
		$schema = self::caseSchema();

		$this->assertSame(['c-1'], self::admitted($permissions, $schema, 'anna', 'update'));
		$this->assertSame(['c-1', 'c-2'], self::admitted($permissions, $schema, 'anna', 'read'));

		foreach (self::CASES as $uuid => [$team, $status]) {
			$this->assertSame(
				$permissions->hasPermission(
					schema: $schema,
					action: 'update',
					userId: 'anna',
					objectOwner: 'importer',
					object: (static function () use ($uuid, $team, $status): ObjectEntity {
						$object = new ObjectEntity();
						$object->setUuid($uuid);
						$object->setOwner('importer');
						$object->setObject(['assignedGroup' => $team, 'status' => $status]);
						return $object;
					})()
				),
				$rbac->hasPermission(schema: $schema, action: 'update', objectOwner: 'importer', objectData: ['assignedGroup' => $team, 'status' => $status]),
				'the row-level verdict agrees for ' . $uuid
			);
		}
	}//end testEditIsLimitedToTheRowsStatuses()

	/**
	 * A row past its end admits nobody, on both paths.
	 *
	 * @return void
	 */
	public function testARowPastItsEndAdmitsNobody(): void {
		[$permissions, $rbac] = $this->handlers(uid: 'carla');
		$schema = self::caseSchema();

		$this->assertSame([], self::admitted($permissions, $schema, 'carla', 'read'));
		$this->assertSame([], self::listed($rbac, $schema));
	}//end testARowPastItsEndAdmitsNobody()
}//end class
