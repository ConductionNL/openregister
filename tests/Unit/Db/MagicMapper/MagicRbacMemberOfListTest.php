<?php

/**
 * A `$memberOf` rule on the list path: rows, count and pages over a real table.
 *
 * The whole chain is real except storage: MagicSearchHandler builds the list's
 * WHERE conditions over the real MagicRbacHandler, ConditionMatcher and
 * MemberOfResolver, whose membership rows come from a mocked mapper. The
 * conditions then run against an SQLite table holding rows of three
 * administrations.
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
 * @spec openspec/changes/access-follows-membership/specs/rbac-scopes/spec.md#requirement-a-match-can-admit-by-the-callers-membership-records
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\MagicMapperInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Service\Rbac\MemberOfResolver;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
 * Another administration's rows are excluded, and the count and pages agree.
 */
class MagicRbacMemberOfListTest extends TestCase {

	/**
	 * The `boeking` rows: uuid => administration. adm-1 has 3, adm-2 has 2, adm-3 has 4.
	 *
	 * @var array<string, string>
	 */
	private const BOOKINGS = [
		'b-01' => 'adm-1',
		'b-02' => 'adm-3',
		'b-03' => 'adm-2',
		'b-04' => 'adm-1',
		'b-05' => 'adm-3',
		'b-06' => 'adm-3',
		'b-07' => 'adm-2',
		'b-08' => 'adm-1',
		'b-09' => 'adm-3',
	];

	/**
	 * The membership rows: anna books in adm-1 and adm-2 and only views adm-3; bram has none.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const MEMBERSHIPS = [
		['userId' => 'anna', 'administrationId' => 'adm-1', 'role' => 'boekhouder'],
		['userId' => 'anna', 'administrationId' => 'adm-2', 'role' => 'controller'],
		['userId' => 'anna', 'administrationId' => 'adm-3', 'role' => 'inkijker'],
		['userId' => 'carla', 'administrationId' => 'adm-3', 'role' => 'boekhouder'],
	];

	/**
	 * How often the membership rows were read.
	 *
	 * @var int
	 */
	private int $membershipReads = 0;

	/**
	 * The `boeking` schema with the shillinq rule.
	 *
	 * @return Schema
	 */
	private function bookingSchema(): Schema {
		$schema = new Schema();
		$schema->setId(61);
		$schema->setSlug('boeking');
		$schema->setAuthorization(
			[
				'read' => [
					[
						'group' => 'shillinq_bookkeeper',
						'match' => [
							'administrationId' => [
								'$memberOf' => [
									'schema'   => 'administration-membership',
									'property' => 'administrationId',
									'where'    => ['role' => ['$in' => ['boekhouder', 'controller']]],
								],
							],
						],
					],
				],
			]
		);

		return $schema;
	}//end bookingSchema()

	/**
	 * The real rbac handler for a caller in `shillinq_bookkeeper`.
	 *
	 * @param string $uid The caller.
	 *
	 * @return MagicRbacHandler
	 */
	private function rbacHandler(string $uid): MagicRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['shillinq_bookkeeper']);

		$membershipSchema = new Schema();
		$membershipSchema->setId(60);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($membershipSchema);

		$register = new Register();
		$register->setId(8);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('getFirstRegisterWithSchema')->willReturn(8);
		$registers->method('find')->willReturn($register);

		$objects = $this->createMock(MagicMapper::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (): array {
				$this->membershipReads++;
				return array_map(
					static function (array $data): ObjectEntity {
						$row = new ObjectEntity();
						$row->setObject($data);
						return $row;
					},
					self::MEMBERSHIPS
				);
			}
		);

		$evaluator = new OperatorEvaluator(new NullLogger());
		$matcher = new ConditionMatcher(
			$session,
			$this->createMock(ContainerInterface::class),
			$evaluator,
			new NullLogger(),
			$groups,
			new MemberOfResolver($schemas, $registers, $this->containerGiving($objects), $evaluator, new NullLogger())
		);

		return new MagicRbacHandler(
			$session,
			$groups,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$matcher,
			$this->createMock(ContainerInterface::class),
			new NullLogger()
		);
	}//end rbacHandler()

	/**
	 * The WHERE conditions the list builds for the caller.
	 *
	 * @param MagicRbacHandler $rbac The rbac handler.
	 *
	 * @return string[] The conditions.
	 */
	private function listConditions(MagicRbacHandler $rbac): array {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(
			static fn ($value): string => "'" . str_replace("'", "''", (string)$value) . "'"
		);
		$queryBuilder = $this->createMock(IQueryBuilder::class);
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

		return $search->buildWhereConditionsSql(query: [], schema: $this->bookingSchema());
	}//end listConditions()

	/**
	 * A table of `boeking` rows, all in the caller's organisation and owned by an importer.
	 *
	 * @return PDO
	 */
	private function table(): PDO {
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$pdo->exec(
			'CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT,'
			. ' _deleted TEXT, _archived TEXT, _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT,'
			. ' administration_id TEXT)'
		);
		$insert = $pdo->prepare("INSERT INTO t (_uuid, _owner, _organisation, administration_id) VALUES (?, 'importer', 'org-home', ?)");
		foreach (self::BOOKINGS as $uuid => $administration) {
			$insert->execute([$uuid, $administration]);
		}

		return $pdo;
	}//end table()

	/**
	 * The WHERE clause of the conditions.
	 *
	 * @param string[] $conditions The conditions.
	 *
	 * @return string The clause, or '' when there are none.
	 */
	private function where(array $conditions): string {
		if ($conditions === []) {
			return '';
		}

		return ' WHERE ' . implode(' AND ', $conditions);
	}//end where()

	/**
	 * The uuids of an administration's bookings, sorted.
	 *
	 * @param string ...$administrations The administrations.
	 *
	 * @return string[]
	 */
	private function bookingsOf(string ...$administrations): array {
		$uuids = array_keys(array_filter(self::BOOKINGS, static fn (string $adm): bool => in_array($adm, $administrations, true)));
		sort($uuids);
		return $uuids;
	}//end bookingsOf()

	/**
	 * The member lists only their administrations' rows, the count is exact,
	 * and the pages add up to the list.
	 *
	 * @return void
	 */
	public function testAMemberListsOnlyTheirAdministrationsAndTheCountAndPagesAgree(): void {
		$where = $this->where($this->listConditions($this->rbacHandler('anna')));
		$pdo = $this->table();

		$rows = $pdo->query('SELECT _uuid FROM t' . $where . ' ORDER BY _uuid')->fetchAll(PDO::FETCH_COLUMN);
		$this->assertSame($this->bookingsOf('adm-1', 'adm-2'), $rows, 'adm-3, where anna is only inkijker, is excluded');

		$count = (int)$pdo->query('SELECT COUNT(*) FROM t' . $where)->fetchColumn();
		$this->assertSame(5, $count);

		$paged = [];
		for ($offset = 0; $offset < 10; $offset += 2) {
			$page = $pdo->query('SELECT _uuid FROM t' . $where . ' ORDER BY _uuid LIMIT 2 OFFSET ' . $offset)->fetchAll(PDO::FETCH_COLUMN);
			$this->assertLessThanOrEqual(2, count($page));
			$paged = array_merge($paged, $page);
		}

		$this->assertSame($rows, $paged, 'the pages hold exactly the listed rows');
	}//end testAMemberListsOnlyTheirAdministrationsAndTheCountAndPagesAgree()

	/**
	 * A bookkeeper without memberships lists nothing and counts zero.
	 *
	 * @return void
	 */
	public function testABookkeeperWithoutMembershipsListsNothing(): void {
		$where = $this->where($this->listConditions($this->rbacHandler('bram')));
		$pdo = $this->table();

		$this->assertNotSame('', $where, 'the list must be filtered, not open');
		$this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM t' . $where)->fetchColumn());
	}//end testABookkeeperWithoutMembershipsListsNothing()

	/**
	 * The single-object verdict agrees with the list, and the set is read once.
	 *
	 * @return void
	 */
	public function testTheSingleObjectVerdictAgreesWithTheList(): void {
		$rbac = $this->rbacHandler('anna');
		$schema = $this->bookingSchema();

		$admitted = [];
		foreach (self::BOOKINGS as $uuid => $administration) {
			if ($rbac->hasPermission(schema: $schema, action: 'read', objectOwner: 'importer', objectData: ['administrationId' => $administration]) === true) {
				$admitted[] = $uuid;
			}
		}

		sort($admitted);
		$this->assertSame($this->bookingsOf('adm-1', 'adm-2'), $admitted);

		$this->listConditions($rbac);
		$this->assertSame(1, $this->membershipReads, 'nine verdicts and a list read the membership rows once');
	}//end testTheSingleObjectVerdictAgreesWithTheList()

	/**
	 * The QueryBuilder path (page and `_count` of a single-table search) emits
	 * an `in` over the same set.
	 *
	 * @return void
	 */
	public function testTheQueryBuilderPathFiltersOnTheSameSet(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$text = static fn (array $args): string => implode(',', array_map(static fn ($arg): string => (string)$arg, $args));
		foreach (['eq', 'neq', 'in', 'notIn', 'isNull', 'isNotNull', 'like', 'gt', 'gte', 'lt', 'lte'] as $method) {
			$expr->method($method)->willReturnCallback(
				static fn (...$args): string => $method . '(' . $text($args) . ')'
			);
		}

		foreach (['orX', 'andX'] as $method) {
			$expr->method($method)->willReturnCallback(
				static fn (...$args): ICompositeExpression => new class($method . '(' . $text($args) . ')') implements ICompositeExpression {
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
						return 'composite';
					}

					public function __toString(): string {
						return $this->sql;
					}
				}
			);
		}

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): IParameter => new class(json_encode($value)) implements IParameter {
				public function __construct(private readonly string $sql) {
				}

				public function __toString(): string {
					return $this->sql;
				}
			}
		);
		$qb->method('createFunction')->willReturnCallback(static fn (string $sql) => $sql);

		$wheres = [];
		$qb->method('andWhere')->willReturnCallback(
			static function (...$predicates) use (&$wheres, $qb) {
				$wheres = array_merge($wheres, $predicates);
				return $qb;
			}
		);

		$this->rbacHandler('anna')->applyRbacFilters(qb: $qb, schema: $this->bookingSchema(), action: 'read');

		$sql = implode(' AND ', array_map(static fn ($predicate): string => (string)$predicate, $wheres));
		$this->assertStringContainsString('in(t.administration_id,["adm-1","adm-2"]', $sql);
		$this->assertStringNotContainsString('adm-3', $sql);
	}//end testTheQueryBuilderPathFiltersOnTheSameSet()

	/**
	 * A container that hands out the given MagicMapper, as the real one does on first read.
	 *
	 * @param MagicMapper $objects The mapper to hand out.
	 *
	 * @return ContainerInterface
	 */
	private function containerGiving(MagicMapper $objects): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => ($id === MagicMapperInterface::class) ? $objects : null
		);
		return $container;
	}//end containerGiving()
}//end class
