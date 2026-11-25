<?php

/**
 * AssessmentOwnershipTest: an assessment record limited to its author and a supervisor group.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-an-assessment-record-can-be-limited-to-its-author-and-a-supervisor-req-rod-003
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

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
use OCA\OpenRegister\Service\Object\PermissionAsk;
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
 * REQ-ROD-003 on a fixture schema shaped like `wooAssessment`: `update` and `read` are
 * `["@creator", "woo-coordinator"]`, so a second reviewer can neither edit nor see the first
 * reviewer's assessment, and a coordinator can do both. Both paths are driven: the PHP verdict
 * a write and a single read ask, and the SQL a list builds (run on SQLite).
 *
 * The scopes are `access-owner-and-condition-scopes`, which has landed; this pins them on the
 * assessment shape this change hands to the lane that ships `wooAssessment`.
 *
 * @coversNothing
 */
class AssessmentOwnershipTest extends TestCase {

	/**
	 * The assessments: their creator per uuid.
	 *
	 * @var array<string, string>
	 */
	public const ROWS = [
		'a-1' => 'j.devries',
		'a-2' => 'p.bakker',
	];

	/**
	 * Both reviewers are in the reviewer group; only the coordinator is in the supervisor group.
	 *
	 * @var array<string, list<string>>
	 */
	private const GROUPS = [
		'j.devries' => ['woo-reviewers'],
		'p.bakker' => ['woo-reviewers'],
		'm.jansen' => ['woo-reviewers', 'woo-coordinator'],
	];

	/**
	 * The fixture schema, shaped like `wooAssessment`.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setId(91);
		$schema->setSlug('wooAssessment');
		$schema->setProperties(['titel' => ['type' => 'string']]);
		$schema->setAuthorization(
			[
				'create' => ['woo-reviewers'],
				'read' => ['@creator', 'woo-coordinator'],
				'update' => ['@creator', 'woo-coordinator'],
			]
		);
		return $schema;
	}//end schema()

	public function testASecondReviewerCannotEditTheFirstsAssessment(): void {
		[$permissions] = $this->handlers(uid: 'p.bakker');

		// p.bakker updates only the assessment they created, never j.devries's a-1.
		$this->assertSame(['a-2'], self::admitted($permissions, self::schema(), 'p.bakker', 'update'));

		[$permissions] = $this->handlers(uid: 'j.devries');
		$this->assertSame(['a-1'], self::admitted($permissions, self::schema(), 'j.devries', 'update'));
	}//end testASecondReviewerCannotEditTheFirstsAssessment()

	public function testTheCoordinatorCan(): void {
		[$permissions, $rbac] = $this->handlers(uid: 'm.jansen');

		$this->assertSame(['a-1', 'a-2'], self::admitted($permissions, self::schema(), 'm.jansen', 'update'));
		$this->assertSame(['a-1', 'a-2'], self::admitted($permissions, self::schema(), 'm.jansen', 'read'));
		$this->assertSame(['a-1', 'a-2'], $this->listed($rbac, self::schema()));
	}//end testTheCoordinatorCan()

	public function testASecondReviewerDoesNotSeeTheFirstsAssessment(): void {
		[$permissions, $rbac] = $this->handlers(uid: 'p.bakker');

		$this->assertSame(['a-2'], self::admitted($permissions, self::schema(), 'p.bakker', 'read'));
		$this->assertSame(['a-2'], $this->listed($rbac, self::schema()));
	}//end testASecondReviewerDoesNotSeeTheFirstsAssessment()

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
	private function handlers(string $uid, ?\OCP\EventDispatcher\IEventDispatcher $dispatcher = null): array {
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
			. ' titel TEXT)'
		);
		$insert = $pdo->prepare("INSERT INTO t (_uuid, _owner, _organisation, titel) VALUES (?, ?, 'org-home', ?)");
		foreach (self::ROWS as $uuid => $owner) {
			$insert->execute([$uuid, $owner, 'Beoordeling ' . $uuid]);
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
		foreach (self::ROWS as $uuid => $owner) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setOwner($owner);
			$object->setObject(['titel' => 'Beoordeling ' . $uuid]);
			if ($permissions->hasPermission(ask: (new PermissionAsk(schema: $schema, action: $action))->askedBy(userId: $uid, objectOwner: $owner)->about(object: $object, register: null)) === true) {
				$admitted[] = $uuid;
			}
		}

		sort($admitted);
		return $admitted;
	}//end admitted()
}//end class
