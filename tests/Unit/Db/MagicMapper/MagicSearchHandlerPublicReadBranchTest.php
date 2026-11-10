<?php

/**
 * A `public` read rule and the caller's other rules.
 *
 * For a signed-in caller who qualifies for `public`, a public rule keeps the
 * organisation filter unless it is marked `crossOrganisation: true`. A marked
 * conditional public rule widens the filter by the rows it admits, so the
 * caller's other rules keep to their own organisation; a marked public rule
 * without match reads every row. An anonymous caller and an admin read as before.
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
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\OrganisationScopeCondition;
use OCA\OpenRegister\Db\MagicMapper\WhereClauseRequest;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PDO;
use RuntimeException;

/**
 * Tests that a marked public rule widens the organisation filter by its own rows only.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacFilterCall
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\OrganisationScopeCondition
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses   \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses   \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Db\MagicMapper\ArrayElementFilter
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 */
class MagicSearchHandlerPublicReadBranchTest extends TestCase {

	/**
	 * A handling-team rule on a static case type.
	 */
	private const TEAM_RULE = ['group' => 'team-toezicht', 'match' => ['caseType' => ['$in' => ['ct-permit']]]];

	/**
	 * A conditional public rule: open records are public.
	 */
	private const PUBLIC_OPEN = ['group' => 'public', 'match' => ['status' => 'open']];

	/**
	 * The same public rule, marked as crossing organisations.
	 */
	private const PUBLIC_OPEN_MARKED = self::PUBLIC_OPEN + ['crossOrganisation' => true];

	/**
	 * A public rule without match, marked as crossing organisations.
	 */
	private const PUBLIC_ALL_MARKED = ['group' => 'public', 'crossOrganisation' => true];

	/**
	 * stackiq's `connection` read rules, exactly as its register ships them.
	 */
	private const STACKIQ_CONNECTION = [
		['group' => 'gebruik-beheerder', 'match' => ['_organisation' => '$organisation']],
		['group' => 'public', 'match' => ['status' => 'open']],
		['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation']],
		['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']],
	];

	/**
	 * stackiq's connection read rules with the markers its app PR adds.
	 */
	private const STACKIQ_CONNECTION_MARKED = [
		['group' => 'gebruik-beheerder', 'match' => ['_organisation' => '$organisation']],
		['group' => 'public', 'match' => ['status' => 'open'], 'crossOrganisation' => true],
		['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation']],
		['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation'], 'crossOrganisation' => true],
	];

	/**
	 * Every row of the fixture table.
	 */
	private const ALL_ROWS = ['other-open', 'other-permit', 'other-plain', 'other-provided', 'own-open', 'own-permit'];

	/**
	 * Subject under test.
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
	 * Build the real RBAC handler over a real PermissionHandler.
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
		$conditionMatcher->method('resolveDynamicValue')->willReturnCallback(
			static function (mixed $value): mixed {
				if ($value === '$organisation') {
					return 'org-home';
				}

				return $value;
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);

		$container = $this->createMock(originalClassName: ContainerInterface::class);

		$permissionHandler = \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
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
	 * Rules, inheritance, caller, and the rows the list must return.
	 *
	 * @return array<string, array{0: array, 1: bool, 2: string, 3: string[]}>
	 */
	public static function rowCases(): array {
		$publicPermit = ['group' => 'public', 'match' => ['caseType' => 'ct-permit']];
		$own = ['own-open', 'own-permit'];

		return [
			'team member: an unmarked public rule stays inside the organisation' => [[self::PUBLIC_OPEN, self::TEAM_RULE], true, 'team', $own],
			'team member: marked public rows of every organisation, team rows of her own' => [
				[self::PUBLIC_OPEN_MARKED, self::TEAM_RULE],
				true,
				'team',
				['other-open', 'own-open', 'own-permit'],
			],
			'signed-in caller with only the unmarked public rule'     => [[self::PUBLIC_OPEN], true, 'plain', ['own-open']],
			'signed-in caller with only the marked public rule'       => [[self::PUBLIC_OPEN_MARKED], true, 'plain', ['other-open', 'own-open']],
			'two marked public rules each widen the filter'           => [
				[self::PUBLIC_OPEN_MARKED, $publicPermit + ['crossOrganisation' => true], self::TEAM_RULE],
				true,
				'team',
				['other-open', 'other-permit', 'own-open', 'own-permit'],
			],
			'an unconditional public rule stays inside the organisation' => [['public', self::TEAM_RULE], true, 'team', $own],
			'a public rule without a match stays inside the organisation' => [[['group' => 'public'], self::TEAM_RULE], true, 'team', $own],
			'a marked public rule without a match reads every row'    => [[self::PUBLIC_ALL_MARKED, self::TEAM_RULE], true, 'team', self::ALL_ROWS],
			'a marked matchless public rule beside a conditional one reads every row' => [
				[self::PUBLIC_ALL_MARKED, self::PUBLIC_OPEN_MARKED, self::TEAM_RULE],
				true,
				'team',
				self::ALL_ROWS,
			],
			'anonymous reads the public rows of every organisation'   => [
				[self::PUBLIC_OPEN, self::TEAM_RULE],
				true,
				'anonymous',
				['other-open', 'own-open'],
			],
			'anonymous reads the marked public rows of every organisation' => [
				[self::PUBLIC_OPEN_MARKED, self::TEAM_RULE],
				true,
				'anonymous',
				['other-open', 'own-open'],
			],
			'an admin reads every row'                                => [[self::PUBLIC_OPEN, self::TEAM_RULE], true, 'admin', self::ALL_ROWS],
			'stackiq connection unmarked: a provider reads its own organisation' => [self::STACKIQ_CONNECTION, true, 'provider', $own],
			'stackiq connection marked: a provider keeps the rows that name it' => [
				self::STACKIQ_CONNECTION_MARKED,
				true,
				'provider',
				['other-open', 'other-provided', 'own-open', 'own-permit'],
			],
			'stackiq connection unmarked: a consumer reads its own organisation' => [self::STACKIQ_CONNECTION, true, 'consumer', $own],
			'stackiq connection marked: a consumer reads its own rows and the public ones' => [
				self::STACKIQ_CONNECTION_MARKED,
				true,
				'consumer',
				['other-open', 'own-open', 'own-permit'],
			],
			'without inheritance a team row matching the marked public rule stays in organisation' => [
				[$publicPermit + ['crossOrganisation' => true], self::TEAM_RULE],
				false,
				'team',
				['own-permit'],
			],
			'without public inheritance the marked public rule does not apply' => [
				[self::PUBLIC_OPEN_MARKED, self::TEAM_RULE],
				false,
				'team',
				['own-permit'],
			],
		];

	}//end rowCases()

	/**
	 * The list and union SQL return exactly the expected rows.
	 *
	 * @param array    $read     The schema's read rules.
	 * @param bool     $inherit  Whether signed-in users inherit public rights.
	 * @param string   $caller   Who reads.
	 * @param string[] $expected The visible rows.
	 *
	 * @return void
	 */
	#[DataProvider('rowCases')]
	public function testListReturnsTheExpectedRows(array $read, bool $inherit, string $caller, array $expected): void {
		$this->logInAs(caller: $caller);

		$this->assertSame(expected: $expected, actual: $this->visibleRows(schema: $this->schema(read: $read, inherit: $inherit)));

	}//end testListReturnsTheExpectedRows()

	/**
	 * An explicit `_multi` keeps the strict filter, without the public branch.
	 *
	 * @return void
	 */
	public function testExplicitMultitenancyKeepsTheStrictFilter(): void {
		$this->logInAs(caller: 'team');

		$this->assertSame(
			expected: ['own-open', 'own-permit'],
			actual: $this->visibleRows(
				schema: $this->schema(read: [self::PUBLIC_OPEN_MARKED, self::TEAM_RULE], inherit: true),
				query: ['_multitenancy' => true, '_multitenancy_explicit' => true]
			)
		);

	}//end testExplicitMultitenancyKeepsTheStrictFilter()

	/**
	 * The aggregation decision: the boundary is not waived, and carries a cross-organisation branch.
	 *
	 * @return void
	 */
	public function testTheAggregationDecisionSeesTheCrossOrganisationBranch(): void {
		$this->logInAs(caller: 'team');
		$schema = $this->schema(read: [self::PUBLIC_OPEN_MARKED, self::TEAM_RULE], inherit: true);

		$this->assertFalse(condition: $this->searchHandler()->organisationBoundaryWaivedByRbac(schema: $schema));
		$this->assertTrue(condition: $this->searchHandler()->organisationBoundaryHasCrossOrganisationBranch(schema: $schema));

	}//end testTheAggregationDecisionSeesTheCrossOrganisationBranch()

	/**
	 * A marked public rule without match beside a marked conditional one
	 * lifts the filter and gives no branch, so the aggregation keeps its
	 * native path; unmarked public rules give no branch either.
	 *
	 * @return void
	 */
	public function testAnUnconditionalPublicRuleGivesNoBranch(): void {
		$this->logInAs(caller: 'team');
		$cases = [
			'marked matchless' => [self::PUBLIC_ALL_MARKED, self::PUBLIC_OPEN_MARKED, self::TEAM_RULE],
			'unmarked'         => ['public', ['group' => 'public'], self::PUBLIC_OPEN, self::TEAM_RULE],
		];

		foreach ($cases as $name => $read) {
			$this->assertFalse(
				condition: $this->searchHandler()->organisationBoundaryHasCrossOrganisationBranch(
					schema: $this->schema(read: $read, inherit: true)
				),
				message: $name
			);
		}

	}//end testAnUnconditionalPublicRuleGivesNoBranch()

	/**
	 * Anonymous and admin callers keep the lifted filter and get no branch.
	 *
	 * @return void
	 */
	public function testAnonymousAndAdminGetNoBranch(): void {
		foreach (['anonymous', 'admin'] as $caller) {
			$this->setUp();
			$this->logInAs(caller: $caller);
			$schema = $this->schema(read: [self::PUBLIC_OPEN_MARKED, self::TEAM_RULE], inherit: true);

			$this->assertTrue(condition: $this->searchHandler()->organisationBoundaryWaivedByRbac(schema: $schema), message: $caller);
			$this->assertFalse(condition: $this->searchHandler()->organisationBoundaryHasCrossOrganisationBranch(schema: $schema), message: $caller);
		}

	}//end testAnonymousAndAdminGetNoBranch()

	/**
	 * A single-object read by a team member applies the organisation filter with the cross-organisation branch.
	 *
	 * @return void
	 */
	public function testSingleObjectReadByATeamMemberKeepsTheFilterWithACrossOrganisationBranch(): void {
		$this->logInAs(caller: 'team');

		$organisation = $this->organisationHandler();
		$organisation->expects($this->never())->method('applyOrganizationFilter');
		$organisation->expects($this->atLeastOnce())->method('resolveOrganizationScope');

		$qb = $this->queryBuilder();
		$qb->expr()->expects($this->atLeastOnce())->method('orX');
		$qb->expects($this->atLeastOnce())->method('andWhere');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $qb,
			schema: $this->schema(read: [self::PUBLIC_OPEN_MARKED, self::TEAM_RULE], inherit: true)
		);

	}//end testSingleObjectReadByATeamMemberKeepsTheFilterWithACrossOrganisationBranch()

	/**
	 * A list that asks for the strict filter gets it without the public branch.
	 *
	 * @return void
	 */
	public function testAnExplicitListOnTheQueryBuilderPathGetsNoBranch(): void {
		$this->logInAs(caller: 'team');

		$organisation = $this->organisationHandler();
		$organisation->expects($this->once())->method('applyOrganizationFilter');
		$organisation->expects($this->never())->method('resolveOrganizationScope');

		$this->searchHandler(organisation: $organisation)->buildFilteredQuery(query: ['_multitenancy' => true, '_multitenancy_explicit' => true], table: new MagicReadTable(schema: $this->schema(read: [self::PUBLIC_OPEN_MARKED, self::TEAM_RULE], inherit: true), tableName: 'openregister_table_1_7'));

	}//end testAnExplicitListOnTheQueryBuilderPathGetsNoBranch()

	/**
	 * Without a public branch the single-object read applies the plain filter.
	 *
	 * @return void
	 */
	public function testSingleObjectReadWithoutAPublicRuleAppliesThePlainFilter(): void {
		$this->logInAs(caller: 'team');

		$organisation = $this->organisationHandler();
		$organisation->expects($this->once())->method('applyOrganizationFilter');
		$organisation->expects($this->never())->method('resolveOrganizationScope');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $this->queryBuilder(),
			schema: $this->schema(read: [self::TEAM_RULE], inherit: true)
		);

	}//end testSingleObjectReadWithoutAPublicRuleAppliesThePlainFilter()

	/**
	 * The scope condition ORs the branch into the scope, and adds nothing for an unbounded scope.
	 *
	 * @return void
	 */
	public function testTheOrganisationConditionTakesTheBranch(): void {
		$handler = new OrganisationScopeCondition();

		$ors = [];
		$expr = $this->createMock(originalClassName: IExpressionBuilder::class);
		$expr->method('eq')->willReturn('ORG');
		$expr->method('isNull')->willReturn('NULLORG');
		$expr->method('orX')->willReturnCallback(
			function (...$parts) use (&$ors): ICompositeExpression {
				$composite = $this->createMock(originalClassName: ICompositeExpression::class);
				$ors[] = [$composite, $parts];
				return $composite;
			}
		);
		$render = static function ($part) use (&$ors, &$render): string {
			foreach ($ors as [$composite, $parts]) {
				if ($composite === $part) {
					return 'OR(' . implode(',', array_map($render, $parts)) . ')';
				}
			}

			return (string)$part;
		};

		$added = [];
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn($this->createMock(originalClassName: IParameter::class));
		$qb->method('andWhere')->willReturnCallback(
			static function ($condition) use (&$added, $qb) {
				$added[] = $condition;
				return $qb;
			}
		);

		$in = ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-home']];
		$handler->apply(qb: $qb, scope: $in, orAlso: 'PUBLIC');
		$handler->apply(qb: $qb, scope: $in);
		$handler->apply(qb: $qb, scope: ['mode' => MagicOrganizationHandler::SCOPE_NONE, 'uuids' => []], orAlso: 'PUBLIC');
		$handler->apply(qb: $qb, scope: ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []], orAlso: 'PUBLIC');

		$this->assertSame(expected: ['OR(OR(ORG),PUBLIC)', 'OR(ORG)', 'OR(1 = 0,PUBLIC)'], actual: array_map($render, $added));

	}//end testTheOrganisationConditionTakesTheBranch()

	/**
	 * Log a caller in: `team` (in team-toezicht), `plain` (no groups), `provider`
	 * (aanbod-beheerder), `consumer` (gebruik-beheerder), `admin` or `anonymous`.
	 *
	 * @param string $caller Who reads.
	 *
	 * @return void
	 */
	private function logInAs(string $caller): void {
		if ($caller === 'anonymous') {
			$this->userSession->method('getUser')->willReturn(null);
			$this->groupManager->method('getUserGroupIds')->willReturn([]);
			$this->groupManager->method('isAdmin')->willReturn(false);
			return;
		}

		$groups = [
			'team'     => ['team-toezicht'],
			'plain'    => [],
			'admin'    => ['admin'],
			'provider' => ['aanbod-beheerder'],
			'consumer' => ['gebruik-beheerder'],
		][$caller];
		$this->logIn(groups: $groups);
		$this->groupManager->method('isAdmin')->willReturn($caller === 'admin');

	}//end logInAs()

	/**
	 * Log `femke` in with the given groups.
	 *
	 * @param string[] $groups The caller's groups.
	 *
	 * @return void
	 */
	private function logIn(array $groups): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('femke');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);

	}//end logIn()

	/**
	 * A query builder double for the single-object path.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function queryBuilder(): IQueryBuilder&MockObject {
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(originalClassName: IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturn($this->createMock(originalClassName: IParameter::class));

		return $qb;

	}//end queryBuilder()

	/**
	 * A case schema with the given read rules.
	 *
	 * @param array $read    The read rules.
	 * @param bool  $inherit Whether signed-in users inherit public rights.
	 *
	 * @return Schema
	 */
	private function schema(array $read, bool $inherit): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Case');
		$schema->setProperties(
			[
				'caseType' => ['type' => 'string'],
				'provider' => ['type' => 'string'],
				'status'   => ['type' => 'string'],
			]
		);
		$schema->setAuthorization(['read' => $read, 'inheritFromPublic' => $inherit]);

		return $schema;

	}//end schema()

	/**
	 * An organisation handler that scopes the caller to `org-home`.
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
	 * A search handler over the real RBAC handler.
	 *
	 * @param MagicOrganizationHandler|null $organisation The organisation handler, a default one when null.
	 *
	 * @return MagicSearchHandler
	 */
	private function searchHandler(?MagicOrganizationHandler $organisation = null): MagicSearchHandler {
		$connection = $this->createMock(originalClassName: IDBConnection::class);
		$connection->method('quote')->willReturnCallback(
			static fn ($value): string => "'" . str_replace("'", "''", (string) $value) . "'"
		);

		$queryBuilder = $this->queryBuilder();
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
	 * Run the list's organisation and RBAC conditions against a real table.
	 *
	 * @param Schema $schema The schema read.
	 * @param array  $query  The list query.
	 *
	 * @return array<int, string> The uuids the list returns, sorted.
	 */
	private function visibleRows(Schema $schema, array $query = []): array {
		$conditions = $this->searchHandler()->buildWhereClause(request: new WhereClauseRequest(query: $query, table: new MagicReadTable(schema: $schema)));

		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$pdo->exec(
			'CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT,'
			. ' _deleted TEXT, _archived TEXT, _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT,'
			. ' case_type TEXT, provider TEXT, status TEXT)'
		);
		$pdo->exec(
			"INSERT INTO t (_uuid, _owner, _organisation, case_type, provider, status) VALUES"
			. " ('own-permit', 'importer', 'org-home', 'ct-permit', NULL, NULL),"
			. " ('other-permit', 'importer', 'org-other', 'ct-permit', NULL, NULL),"
			. " ('other-provided', 'importer', 'org-other', NULL, 'org-home', NULL),"
			. " ('other-plain', 'importer', 'org-other', NULL, NULL, NULL),"
			. " ('own-open', 'importer', 'org-home', NULL, NULL, 'open'),"
			. " ('other-open', 'importer', 'org-other', NULL, NULL, 'open')"
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
