<?php

/**
 * Reads inside an organisation context stay inside that organisation.
 *
 * Runs the real RBAC handler, the real organisation handler and the real
 * condition matcher against an SQLite table holding rows of two
 * organisations, on every read path: the union SQL, the filtered QueryBuilder
 * query the list, count and facets use, the single-object read, and the
 * aggregation decision. The context acts for `org-home`; no `org-other` row
 * may come back for any rule shape or flag combination.
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

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\WhereClauseRequest;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses   \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses   \OCA\OpenRegister\Db\MagicMapper\OrganisationScopeCondition
 * @uses   \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Db\MagicMapper\ArrayElementFilter
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses   \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacFilterCall
 */
class OrganisationContextTenancyTest extends TestCase {

	/**
	 * The rows in the table: uuid, organisation, case type, provider, status.
	 */
	private const ROWS = [
		['own-permit', 'org-home', 'ct-permit', null, null],
		['own-open', 'org-home', null, null, 'open'],
		['other-permit', 'org-other', 'ct-permit', null, null],
		['other-provided', 'org-other', null, 'org-home', null],
		['other-plain', 'org-other', null, null, null],
		['other-open', 'org-other', null, null, 'open'],
		['none-open', null, null, null, 'open'],
	];

	/**
	 * The Nextcloud session user, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The session user's groups.
	 *
	 * @var string[]
	 */
	private array $groups = [];

	private MagicRbacHandler $rbac;

	private MagicOrganizationHandler $organisations;

	private IDBConnection $db;

	/**
	 * The SQLite connection under the bridged one, for raw SQL.
	 *
	 * @var Connection
	 */
	private Connection $doctrine;

	protected function setUp(): void {
		parent::setUp();

		$this->user = null;
		$this->groups = [];

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(fn (): array => $this->groups);
		$groupManager->method('isAdmin')->willReturnCallback(fn (): bool => in_array('admin', $this->groups, true));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);
		$appConfig->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getUserActiveOrganisations')->willReturnCallback(
			static fn (): array => [OrganisationContext::current() ?? 'org-home']
		);
		$organisationService->method('getActiveOrganisation')->willReturnCallback(
			static function (): Organisation {
				$organisation = new Organisation();
				$organisation->setUuid(OrganisationContext::current() ?? 'org-home');
				return $organisation;
			}
		);
		$organisationService->method('getSystemReaderGroups')->willReturn([]);
		$organisationService->method('getSystemUserId')->willReturn('system');

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getAllRegisterIdsWithSchema')->willReturn([]);

		$container = $this->createMock(ContainerInterface::class);
		$conditionMatcher = new ConditionMatcher(
			new ConditionUserResolvers($userSession, $groupManager),
			$container,
			$this->createMock(OperatorEvaluator::class),
			$logger
		);
		$permissionHandler = \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			$userSession,
			$this->createMock(IUserManager::class),
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$conditionMatcher,
			$appConfig,
			$logger,
			$container
		);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($permissionHandler, $registerMapper, $organisationService) {
				return match ($id) {
					PermissionHandler::class => $permissionHandler,
					RegisterMapperInterface::class => $registerMapper,
					OrganisationServiceInterface::class => $organisationService,
					default => throw new RuntimeException('Unexpected service ' . $id),
				};
			}
		);

		$this->rbac = new MagicRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(IUserManager::class),
			$appConfig,
			$conditionMatcher,
			$container,
			$logger
		);
		$this->organisations = new MagicOrganizationHandler(new MagicMapperIdentity($userSession, $groupManager, $this->createMock(IUserManager::class)), $appConfig, $container, $logger);
		$this->db = $this->sqlite();
	}//end setUp()

	/**
	 * Rule shapes a portal schema may carry, with the rows the context must return.
	 *
	 * @return array<string, array{0: array, 1: string[]}>
	 */
	public static function rules(): array {
		$open = ['status' => 'open'];
		return [
			'plain public'                     => [['public'], ['own-open', 'own-permit']],
			'public object without match'      => [[['group' => 'public']], ['own-open', 'own-permit']],
			'conditional public'               => [[['group' => 'public', 'match' => $open]], ['own-open']],
			'marked public'                    => [[['group' => 'public', 'crossOrganisation' => true]], ['own-open', 'own-permit']],
			'marked conditional public'        => [[['group' => 'public', 'match' => $open, 'crossOrganisation' => true]], ['own-open']],
			'marked public bound to the org'   => [[['group' => 'public', 'match' => ['provider' => '$organisation'], 'crossOrganisation' => true]], []],
			'public pinned to the org'         => [[['group' => 'public', 'match' => ['_organisation' => '$organisation']]], ['own-open', 'own-permit']],
			'plain group'                      => [['case-admins'], []],
			'marked group'                     => [[['group' => 'case-admins', 'crossOrganisation' => true]], []],
			'authenticated'                    => [['authenticated'], []],
			'public beside a group'            => [['public', 'case-admins'], ['own-open', 'own-permit']],
		];
	}//end rules()

	/**
	 * The flags an app may pass, and who holds the session.
	 *
	 * @return array<string, array{0: array, 1: string[]|null, 2: bool}>
	 */
	public static function postures(): array {
		return [
			'anonymous, defaults'                    => [[], null, true],
			'anonymous, multitenancy off'            => [['_multitenancy' => false], null, true],
			'anonymous, rbac and multitenancy off'   => [['_rbac' => false, '_multitenancy' => false], null, true],
			'admin session not cleared, defaults'    => [[], ['admin'], false],
			'admin session not cleared, both off'    => [['_rbac' => false, '_multitenancy' => false], ['admin'], false],
			'group member session not cleared'       => [['_multitenancy' => false], ['case-admins'], false],
		];
	}//end postures()

	/**
	 * Every rule shape crossed with every posture.
	 *
	 * @return array<string, array{0: array, 1: string[], 2: array, 3: string[]|null, 4: bool}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (self::rules() as $ruleName => [$read, $expected]) {
			foreach (self::postures() as $postureName => [$query, $groups, $anonymous]) {
				$cases[$ruleName . ' / ' . $postureName] = [$read, $expected, $query, $groups, $anonymous];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * The union SQL returns no row of another organisation.
	 *
	 * @param array         $read      The read rules.
	 * @param string[]      $expected  The rows RBAC admits when it is on.
	 * @param array         $query     The flags.
	 * @param string[]|null $groups    The session user's groups, null for no user.
	 * @param bool          $anonymous Whether the context runs as anonymous.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheUnionSqlStaysInTheOrganisation(array $read, array $expected, array $query, ?array $groups, bool $anonymous): void {
		$rows = $this->inContext(
			groups: $groups,
			anonymous: $anonymous,
			read: fn (): array => $this->unionRows(schema: $this->schema(read: $read), query: $query)
		);

		$this->assertInOrganisation(rows: $rows, read: $read, expected: $expected, query: $query, anonymous: $anonymous);
	}//end testTheUnionSqlStaysInTheOrganisation()

	/**
	 * The filtered query the list, count and facets run returns no row of another organisation.
	 *
	 * @param array         $read      The read rules.
	 * @param string[]      $expected  The rows RBAC admits when it is on.
	 * @param array         $query     The flags.
	 * @param string[]|null $groups    The session user's groups, null for no user.
	 * @param bool          $anonymous Whether the context runs as anonymous.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheFilteredQueryStaysInTheOrganisation(array $read, array $expected, array $query, ?array $groups, bool $anonymous): void {
		$rows = $this->inContext(
			groups: $groups,
			anonymous: $anonymous,
			read: fn (): array => $this->uuids(qb: $this->searchHandler()->buildFilteredQuery(query: $query, table: new MagicReadTable(schema: $this->schema(read: $read), tableName: 't'))->select('t._uuid'))
		);

		$this->assertInOrganisation(rows: $rows, read: $read, expected: $expected, query: $query, anonymous: $anonymous);
	}//end testTheFilteredQueryStaysInTheOrganisation()

	/**
	 * The single-object read returns no row of another organisation.
	 *
	 * @param array         $read      The read rules.
	 * @param string[]      $expected  The rows RBAC admits when it is on.
	 * @param array         $query     The flags.
	 * @param string[]|null $groups    The session user's groups, null for no user.
	 * @param bool          $anonymous Whether the context runs as anonymous.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheSingleReadStaysInTheOrganisation(array $read, array $expected, array $query, ?array $groups, bool $anonymous): void {
		$rows = $this->inContext(
			groups: $groups,
			anonymous: $anonymous,
			read: function () use ($read, $query): array {
				$qb = $this->db->getQueryBuilder();
				$qb->select('t._uuid')->from('t', 't');
				$this->searchHandler()->applyAccessControlToQuery(
					qb: $qb,
					schema: $this->schema(read: $read),
					_rbac: ($query['_rbac'] ?? true),
					_multitenancy: ($query['_multitenancy'] ?? true)
				);
				return $this->uuids(qb: $qb);
			}
		);

		$this->assertInOrganisation(rows: $rows, read: $read, expected: $expected, query: $query, anonymous: $anonymous);
	}//end testTheSingleReadStaysInTheOrganisation()

	/**
	 * The aggregation fast path keeps the boundary and scopes it to the context.
	 *
	 * @param array         $read      The read rules.
	 * @param string[]      $expected  The rows RBAC admits when it is on.
	 * @param array         $query     The flags.
	 * @param string[]|null $groups    The session user's groups, null for no user.
	 * @param bool          $anonymous Whether the context runs as anonymous.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Same provider as the row paths.
	 */
	#[DataProvider('cases')]
	public function testTheAggregationDecisionKeepsTheBoundary(array $read, array $expected, array $query, ?array $groups, bool $anonymous): void {
		$decision = $this->inContext(
			groups: $groups,
			anonymous: $anonymous,
			read: function () use ($read): array {
				$schema = $this->schema(read: $read);
				$handler = $this->searchHandler();
				return [
					$handler->organisationBoundaryWaivedByRbac(schema: $schema),
					$handler->organisationBoundaryHasCrossOrganisationBranch(schema: $schema),
					$this->organisations->resolveOrganizationScope(adminBypassEnabled: true),
				];
			}
		);

		$this->assertSame(
			[false, false, ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-home']]],
			$decision
		);
	}//end testTheAggregationDecisionKeepsTheBoundary()

	/**
	 * Each decision keeps the boundary on its own, not only through the next one.
	 *
	 * @param array         $read      The read rules.
	 * @param string[]      $expected  The rows RBAC admits when it is on.
	 * @param array         $query     The flags.
	 * @param string[]|null $groups    The session user's groups, null for no user.
	 * @param bool          $anonymous Whether the context runs as anonymous.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Same provider as the row paths.
	 */
	#[DataProvider('cases')]
	public function testEveryDecisionKeepsTheBoundary(array $read, array $expected, array $query, ?array $groups, bool $anonymous): void {
		$decisions = $this->inContext(
			groups: $groups,
			anonymous: $anonymous,
			read: function () use ($read, $query): array {
				$schema = $this->schema(read: $read);
				$handler = $this->searchHandler();
				$resolve = new \ReflectionMethod($handler, 'resolveMultitenancyFlag');
				$applies = new \ReflectionMethod($handler, 'multitenancyApplies');
				return [
					'public lifts' => $this->rbac->publicReadLiftsOrganisationFilter(schema: $schema),
					'rules bypass' => $this->rbac->hasConditionalRulesBypassingMultitenancy(schema: $schema),
					'cross branch' => $this->rbac->hasCrossOrganisationBranch(schema: $schema),
					'flag' => $resolve->invoke($handler, ($query['_multitenancy'] ?? true), false, $schema),
					'applies' => $applies->invoke($handler, $schema, ($query['_rbac'] ?? true), ($query['_multitenancy'] ?? true), false),
				];
			}
		);

		$this->assertSame(
			['public lifts' => false, 'rules bypass' => false, 'cross branch' => false, 'flag' => true, 'applies' => true],
			$decisions
		);
	}//end testEveryDecisionKeepsTheBoundary()

	/**
	 * Without a context the same plain public rule reads every organisation, so the probe can see a leak.
	 *
	 * @return void
	 */
	public function testOutsideAContextAPublicRuleReadsEveryOrganisation(): void {
		$rows = AnonymousEvaluationContext::run(fn (): array => $this->unionRows(schema: $this->schema(read: ['public']), query: []));

		$this->assertContains('other-plain', $rows);
	}//end testOutsideAContextAPublicRuleReadsEveryOrganisation()

	/**
	 * Run a read inside a context for `org-home`.
	 *
	 * @param string[]|null $groups    The session user's groups, null for no user.
	 * @param bool          $anonymous Whether to run as runForOrganisation() does.
	 * @param callable      $read      The read.
	 *
	 * @return mixed What the read returns.
	 */
	private function inContext(?array $groups, bool $anonymous, callable $read): mixed {
		$this->user = null;
		$this->groups = [];
		if ($groups !== null) {
			$this->user = $this->createMock(IUser::class);
			$this->user->method('getUID')->willReturn('femke');
			$this->groups = $groups;
		}

		if ($anonymous === true) {
			return OrganisationContext::run('org-home', static fn () => AnonymousEvaluationContext::run($read));
		}

		return OrganisationContext::run('org-home', $read);
	}//end inContext()

	/**
	 * No row of another organisation; and with RBAC on and an anonymous caller, exactly the expected rows.
	 *
	 * @param string[] $rows      The rows returned.
	 * @param array    $read      The read rules.
	 * @param string[] $expected  The rows RBAC admits.
	 * @param array    $query     The flags.
	 * @param bool     $anonymous Whether the context ran as anonymous.
	 *
	 * @return void
	 */
	private function assertInOrganisation(array $rows, array $read, array $expected, array $query, bool $anonymous): void {
		$foreign = array_values(array_filter($rows, static fn (string $uuid): bool => str_starts_with($uuid, 'other-')));
		$this->assertSame([], $foreign, 'rules ' . json_encode($read) . ' returned ' . json_encode($rows));

		if ($anonymous === true && ($query['_rbac'] ?? true) === true) {
			$own = array_values(array_filter($rows, static fn (string $uuid): bool => str_starts_with($uuid, 'own-')));
			$this->assertSame($expected, $own);
		}
	}//end assertInOrganisation()

	/**
	 * A schema with the given read rules.
	 *
	 * @param array $read The read rules.
	 *
	 * @return Schema
	 */
	private function schema(array $read): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Vacancy');
		$schema->setProperties(
			[
				'caseType' => ['type' => 'string'],
				'provider' => ['type' => 'string'],
				'status'   => ['type' => 'string'],
			]
		);
		$schema->setAuthorization(['read' => $read, 'inheritFromPublic' => true]);

		return $schema;
	}//end schema()

	/**
	 * A search handler over the real RBAC and organisation handlers.
	 *
	 * @param IDBConnection|null $db The connection, the SQLite one when null.
	 *
	 * @return MagicSearchHandler
	 */
	private function searchHandler(?IDBConnection $db = null): MagicSearchHandler {
		return new MagicSearchHandler(
			($db ?? $this->db),
			$this->createMock(LoggerInterface::class),
			$this->rbac,
			$this->organisations,
			$this->createMock(SchemaTypeConverter::class),
			$this->createMock(DateTimeNormalizer::class),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
	}//end searchHandler()

	/**
	 * Run the union path's WHERE conditions against the table.
	 *
	 * @param Schema $schema The schema read.
	 * @param array  $query  The flags.
	 *
	 * @return string[] The uuids, sorted.
	 */
	private function unionRows(Schema $schema, array $query): array {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(
			static fn ($value): string => "'" . str_replace("'", "''", (string) $value) . "'"
		);
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());

		$conditions = $this->searchHandler(db: $db)->buildWhereClause(request: new WhereClauseRequest(query: $query, table: new MagicReadTable(schema: $schema)));
		$sql = 'SELECT _uuid FROM t';
		if ($conditions !== []) {
			$sql .= ' WHERE ' . implode(' AND ', $conditions);
		}

		$uuids = $this->doctrine->fetchFirstColumn($sql);
		sort($uuids);

		return $uuids;
	}//end unionRows()

	/**
	 * The uuids a query builder returns, sorted.
	 *
	 * @param mixed $qb The query builder.
	 *
	 * @return string[]
	 */
	private function uuids(mixed $qb): array {
		$uuids = array_map(static fn (array $row): string => $row['_uuid'], $qb->executeQuery()->fetchAll());
		sort($uuids);

		return $uuids;
	}//end uuids()

	/**
	 * An in-memory SQLite connection holding the rows.
	 *
	 * @return IDBConnection
	 */
	private function sqlite(): IDBConnection {
		$database = (new ReflectionClass(MigratedSqliteDatabase::class))->newInstanceWithoutConstructor();
		$test = $this;
		\Closure::bind(
			function () use ($test): void {
				$this->test = $test;
				$this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
			},
			$database,
			MigratedSqliteDatabase::class
		)();

		$connection = $database->connection();
		$connection->getNativeConnection()->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$connection->executeStatement(
			'CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT, _deleted TEXT,'
			. ' _archived TEXT, _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT,'
			. ' case_type TEXT, provider TEXT, status TEXT)'
		);
		foreach (self::ROWS as [$uuid, $organisation, $caseType, $provider, $status]) {
			$connection->insert(
				't',
				['_uuid' => $uuid, '_owner' => 'importer', '_organisation' => $organisation, 'case_type' => $caseType, 'provider' => $provider, 'status' => $status]
			);
		}

		$this->doctrine = $connection;
		$bridged = $database->idbConnection();
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(static fn () => $bridged->getQueryBuilder());
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());

		return $db;
	}//end sqlite()
}//end class
