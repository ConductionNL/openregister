<?php

/**
 * MagicSearchHandler: a `public` read rule and the organisation filter
 *
 * A schema whose read rule lists `public` drops the organisation filter so a
 * public read sees every organisation's rows. With `inheritFromPublic` off, a
 * signed-in caller does not qualify for `public`, so for that caller the rule
 * must not drop the filter either: the remaining rules (e.g. `authenticated`)
 * keep reading inside the caller's organisation.
 *
 * The rbac handler and the PermissionHandler behind it are REAL; only the
 * register lookup and the organisation scope are doubles.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PDO;
use RuntimeException;

/**
 * Tests the organisation filter decision for schemas with a `public` read rule:
 * it reads every organisation for anonymous callers and admins, and for a
 * signed-in caller only when the rule is marked `crossOrganisation: true`.
 */
class MagicSearchHandlerPublicReadOrganisationTest extends TestCase {

	private const ALL_ROWS = ['no-org', 'other-org', 'own-org'];

	private const OWN_ROW = ['own-org'];

	/**
	 * Real rbac handler.
	 *
	 * @var MagicRbacHandler
	 */
	private MagicRbacHandler $rbac;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Build the real PermissionHandler and rbac handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('getAllRegisterIdsWithSchema')->willReturn([]);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$conditionMatcher = $this->createMock(originalClassName: ConditionMatcher::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);

		$container = $this->createMock(originalClassName: ContainerInterface::class);

		$permissionHandler = new PermissionHandler(
			$this->userSession,
			$userManager,
			$this->groupManager,
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: MagicMapper::class),
			$conditionMatcher,
			$appConfig,
			$logger,
			$container
		);

		$container->method('get')->willReturnCallback(
			static function (string $service) use ($permissionHandler, $registerMapper) {
				if ($service === PermissionHandlerInterface::class) {
					return $permissionHandler;
				}

				if ($service === RegisterMapper::class) {
					return $registerMapper;
				}

				throw new RuntimeException(message: 'Unexpected service ' . $service);
			}
		);

		$this->rbac = new MagicRbacHandler(
			$this->userSession,
			$this->groupManager,
			$userManager,
			$appConfig,
			$conditionMatcher,
			$container,
			$logger
		);

	}//end setUp()

	/**
	 * Every read rule x inheritFromPublic x caller, with the rows the list returns.
	 *
	 * @return array<string, array{0: array, 1: bool, 2: string, 3: array<int, string>}>
	 */
	public static function matrix(): array {
		$cases = [];
		foreach ([false, true] as $inherit) {
			$label = 'inherit-' . ($inherit === true ? 'on' : 'off');

			$cases[$label . ' public anonymous'] = [['public'], $inherit, 'anonymous', self::ALL_ROWS];
			$cases[$label . ' public+authenticated anonymous'] = [['public', 'authenticated'], $inherit, 'anonymous', self::ALL_ROWS];
			$cases[$label . ' authenticated anonymous'] = [['authenticated'], $inherit, 'anonymous', []];

			$cases[$label . ' public admin'] = [['public'], $inherit, 'admin', self::ALL_ROWS];
			$cases[$label . ' public+authenticated admin'] = [['public', 'authenticated'], $inherit, 'admin', self::ALL_ROWS];
			$cases[$label . ' authenticated admin'] = [['authenticated'], $inherit, 'admin', self::ALL_ROWS];

			$cases[$label . ' authenticated user'] = [['authenticated'], $inherit, 'user', self::OWN_ROW];
		}

		$marked = ['group' => 'public', 'crossOrganisation' => true];
		$cases['inherit-on public user'] = [['public'], true, 'user', self::OWN_ROW];
		$cases['inherit-on public+authenticated user'] = [['public', 'authenticated'], true, 'user', self::OWN_ROW];
		$cases['inherit-on marked public user'] = [[$marked], true, 'user', self::ALL_ROWS];
		$cases['inherit-on marked public+authenticated user'] = [[$marked, 'authenticated'], true, 'user', self::ALL_ROWS];
		$cases['inherit-off marked public user'] = [[$marked], false, 'user', []];
		$cases['inherit-off marked public anonymous'] = [[$marked], false, 'anonymous', self::ALL_ROWS];
		$cases['inherit-off public user'] = [['public'], false, 'user', []];
		$cases['inherit-off public+authenticated user'] = [['public', 'authenticated'], false, 'user', self::OWN_ROW];
		$cases['inherit-off conditional public+authenticated user'] = [
			[['group' => 'public', 'match' => ['_organisation' => 'org-other']], 'authenticated'],
			false,
			'user',
			self::OWN_ROW,
		];

		return $cases;

	}//end matrix()

	/**
	 * The cases in which the caller can read at least one row.
	 *
	 * @return array<string, array{0: array, 1: bool, 2: string, 3: array<int, string>}>
	 */
	public static function readableMatrix(): array {
		return array_filter(self::matrix(), static fn (array $case): bool => $case[3] !== []);

	}//end readableMatrix()

	/**
	 * The list's own SQL returns the expected rows for each case.
	 *
	 * @param array             $read     The schema's read rules.
	 * @param bool              $inherit  The schema's inheritFromPublic.
	 * @param string            $caller   anonymous, user or admin.
	 * @param array<int,string> $expected The rows the list returns.
	 *
	 * @return void
	 */
	#[DataProvider('matrix')]
	public function testListReturnsTheExpectedRows(array $read, bool $inherit, string $caller, array $expected): void {
		$this->logIn(caller: $caller);

		$this->assertSame(
			expected: $expected,
			actual: $this->visibleRows(schema: $this->schema(read: $read, inherit: $inherit))
		);

	}//end testListReturnsTheExpectedRows()

	/**
	 * The default-read boundary decision agrees with the rows above: it is
	 * waived exactly when another organisation's row is returned.
	 *
	 * @param array             $read     The schema's read rules.
	 * @param bool              $inherit  The schema's inheritFromPublic.
	 * @param string            $caller   anonymous, user or admin.
	 * @param array<int,string> $expected The rows the list returns.
	 *
	 * @return void
	 */
	#[DataProvider('readableMatrix')]
	public function testBoundaryDecisionMatchesTheRows(array $read, bool $inherit, string $caller, array $expected): void {
		$this->logIn(caller: $caller);

		$this->assertSame(
			expected: in_array('other-org', $expected, true),
			actual: $this->searchHandler()->organisationBoundaryWaivedByRbac(
				schema: $this->schema(read: $read, inherit: $inherit)
			)
		);

	}//end testBoundaryDecisionMatchesTheRows()

	/**
	 * The single-object read keeps the organisation filter for a signed-in
	 * caller when `public` is not inherited.
	 *
	 * @return void
	 */
	public function testSingleObjectReadKeepsTheOrganisationFilterWhenPublicIsNotInherited(): void {
		$this->logIn(caller: 'user');

		$organisation = $this->organisationHandler();
		$organisation->expects($this->once())->method('applyOrganizationFilter');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $this->queryBuilder(),
			schema: $this->schema(read: ['public', 'authenticated'], inherit: false)
		);

	}//end testSingleObjectReadKeepsTheOrganisationFilterWhenPublicIsNotInherited()

	/**
	 * The single-object read still drops the organisation filter for an
	 * anonymous caller on a public schema.
	 *
	 * @return void
	 */
	public function testSingleObjectReadDropsTheOrganisationFilterForAnonymous(): void {
		$this->logIn(caller: 'anonymous');

		$organisation = $this->organisationHandler();
		$organisation->expects($this->never())->method('applyOrganizationFilter');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $this->queryBuilder(),
			schema: $this->schema(read: ['public', 'authenticated'], inherit: false)
		);

	}//end testSingleObjectReadDropsTheOrganisationFilterForAnonymous()

	/**
	 * The single-object read keeps the organisation filter for a signed-in
	 * caller when an unmarked `public` is inherited.
	 *
	 * @return void
	 */
	public function testSingleObjectReadKeepsTheOrganisationFilterWhenAnUnmarkedPublicIsInherited(): void {
		$this->logIn(caller: 'user');

		$organisation = $this->organisationHandler();
		$organisation->expects($this->once())->method('applyOrganizationFilter');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $this->queryBuilder(),
			schema: $this->schema(read: ['public', 'authenticated'], inherit: true)
		);

	}//end testSingleObjectReadKeepsTheOrganisationFilterWhenAnUnmarkedPublicIsInherited()

	/**
	 * The single-object read drops the organisation filter for a signed-in
	 * caller when a marked `public` is inherited.
	 *
	 * @return void
	 */
	public function testSingleObjectReadDropsTheOrganisationFilterWhenAMarkedPublicIsInherited(): void {
		$this->logIn(caller: 'user');

		$organisation = $this->organisationHandler();
		$organisation->expects($this->never())->method('applyOrganizationFilter');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $this->queryBuilder(),
			schema: $this->schema(read: [['group' => 'public', 'crossOrganisation' => true], 'authenticated'], inherit: true)
		);

	}//end testSingleObjectReadDropsTheOrganisationFilterWhenAMarkedPublicIsInherited()

	/**
	 * Who a `public` read rule lifts the organisation filter for.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool}>
	 */
	public static function liftCases(): array {
		return [
			'anonymous, inherit off' => ['anonymous', false, true],
			'anonymous, inherit on'  => ['anonymous', true, true],
			'admin, inherit off'     => ['admin', false, true],
			'admin, inherit on'      => ['admin', true, true],
			'user, inherit off'      => ['user', false, false],
			'user, inherit on'       => ['user', true, false],
		];

	}//end liftCases()

	/**
	 * The rbac handler answers per caller and inheritFromPublic.
	 *
	 * @param string $caller   anonymous, user or admin.
	 * @param bool   $inherit  The schema's inheritFromPublic.
	 * @param bool   $expected Whether the filter is lifted.
	 *
	 * @return void
	 */
	#[DataProvider('liftCases')]
	public function testPublicReadLiftsOrganisationFilterPerCaller(string $caller, bool $inherit, bool $expected): void {
		$this->logIn(caller: $caller);

		$this->assertSame(
			expected: $expected,
			actual: $this->rbac->publicReadLiftsOrganisationFilter(
				schema: $this->schema(read: ['public', 'authenticated'], inherit: $inherit)
			)
		);

	}//end testPublicReadLiftsOrganisationFilterPerCaller()

	/**
	 * Log in as the given kind of caller.
	 *
	 * @param string $caller anonymous, user or admin.
	 *
	 * @return void
	 */
	private function logIn(string $caller): void {
		if ($caller === 'anonymous') {
			$this->userSession->method('getUser')->willReturn(null);
			$this->groupManager->method('getUserGroupIds')->willReturn([]);
			return;
		}

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($caller . '-01');
		$this->userSession->method('getUser')->willReturn($user);

		$groups = ['staff'];
		if ($caller === 'admin') {
			$groups = ['admin'];
		}

		$this->groupManager->method('getUserGroupIds')->willReturn($groups);

	}//end logIn()

	/**
	 * A query builder double that hands out an expression builder.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function queryBuilder(): IQueryBuilder&MockObject {
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(originalClassName: IExpressionBuilder::class));

		return $qb;

	}//end queryBuilder()

	/**
	 * Build a schema fixture.
	 *
	 * @param array $read    The read rules.
	 * @param bool  $inherit The inheritFromPublic value.
	 *
	 * @return Schema
	 */
	private function schema(array $read, bool $inherit): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Notice');
		$schema->setAuthorization(['read' => $read, 'inheritFromPublic' => $inherit]);

		return $schema;

	}//end schema()

	/**
	 * An organisation handler double that scopes to `org-home`.
	 *
	 * @return MagicOrganizationHandler&MockObject
	 */
	private function organisationHandler(): MagicOrganizationHandler&MockObject {
		$organisation = $this->createMock(originalClassName: MagicOrganizationHandler::class);
		$organisation->method('resolveOrganizationScope')->willReturn(
			['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-home']]
		);
		$organisation->method('isAdminOverrideEnabled')->willReturn(false);

		return $organisation;

	}//end organisationHandler()

	/**
	 * A search handler over the real rbac handler.
	 *
	 * @param MagicOrganizationHandler|null $organisation The organisation handler, default scopes to `org-home`.
	 *
	 * @return MagicSearchHandler
	 */
	private function searchHandler(?MagicOrganizationHandler $organisation = null): MagicSearchHandler {
		$connection = $this->createMock(originalClassName: IDBConnection::class);
		$connection->method('quote')->willReturnCallback(
			static fn ($value): string => "'" . str_replace("'", "''", (string) $value) . "'"
		);

		$queryBuilder = $this->createMock(originalClassName: IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());

		return new MagicSearchHandler(
			$db,
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->rbac,
			($organisation ?? $this->organisationHandler()),
			$this->createMock(originalClassName: SchemaTypeConverter::class),
			$this->createMock(originalClassName: DateTimeNormalizer::class),
			relatedRows: $this->createMock(originalClassName: RelatedRowQueryApplier::class)
		);

	}//end searchHandler()

	/**
	 * Run the list's organisation and RBAC conditions against a table holding a
	 * row of the caller's organisation, one with no organisation and one of
	 * another organisation.
	 *
	 * @param Schema $schema The schema read.
	 *
	 * @return array<int, string> The uuids the list returns, sorted.
	 */
	private function visibleRows(Schema $schema): array {
		$conditions = $this->searchHandler()->buildWhereConditionsSql(query: [], schema: $schema);

		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$pdo->exec(
			'CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT,'
			. ' _deleted TEXT, _archived TEXT, _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT)'
		);
		$pdo->exec(
			"INSERT INTO t (_uuid, _owner, _organisation) VALUES"
			. " ('own-org', 'importer', 'org-home'),"
			. " ('no-org', 'importer', NULL),"
			. " ('other-org', 'importer', 'org-other')"
		);

		$where = '';
		if ($conditions !== []) {
			$where = ' WHERE ' . implode(' AND ', $conditions);
		}

		$uuids = $pdo->query('SELECT _uuid FROM t' . $where)->fetchAll(PDO::FETCH_COLUMN);
		sort($uuids);

		return $uuids;

	}//end visibleRows()
}//end class
