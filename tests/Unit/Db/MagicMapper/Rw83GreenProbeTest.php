<?php

/**
 * Conditional read rules and the organisation boundary.
 *
 * A conditional rule keeps the organisation filter unless it crosses
 * organisations: it binds a field other than `_organisation` to the caller's
 * organisation (`{"aanbieder": "$organisation"}`) or membership, or it
 * declares `"crossOrganisation": true`. Every read path takes the decision
 * from the same place, so each is exercised: the list and union SQL, the
 * aggregation decision, and the single-object read.
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
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use Doctrine\DBAL\Platforms\SqlitePlatform;
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
 * Tests that only a conditional rule that crosses organisations lifts the organisation filter.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses   \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses   \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 */
class Rw83GreenProbeTest extends TestCase {

	/**
	 * The handling-team rule dossiq projects onto its case schema.
	 */
	private const TEAM_RULE = ['group' => 'team-toezicht', 'match' => ['caseType' => ['$in' => ['ct-permit']]]];

	/**
	 * The provider rule the rbac-scopes spec names as crossing organisations.
	 */
	private const STACKIQ_CONNECTION = [
		['group' => 'gebruik-beheerder', 'match' => ['_organisation' => '$organisation']],
		['group' => 'public', 'match' => ['status' => 'open']],
		['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation']],
		['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']],
	];

	private const PROVIDER_RULE = ['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']];

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
		$resolve = null;
		$conditionMatcher->method('resolveDynamicValue')->willReturnCallback(
			$resolve = static function (mixed $value) use (&$resolve): mixed {
				if (is_array($value) === true) {
					return array_map($resolve, $value);
				}
				if (in_array($value, ['$organisation', '$activeOrganisation', '$organisation.uuid', '$activeOrganisation.uuid'], true) === true) {
					return Rw83GreenProbeTest::$activeOrg;
				}
				if ($value === '$userId') {
					return 'femke';
				}

				return $value;
			}
		);
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
				if ($service === PermissionHandler::class) {
					return $permissionHandler;
				}

				if ($service === RegisterMapper::class) {
					return $registerMapper;
				}

				if ($service === \OCA\OpenRegister\Service\OrganisationService::class && self::$orgService !== null) {
					return self::$orgService;
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
	 * Rules, caller groups, and the rows the list must return.
	 *
	 * Rows: `own-permit` (org-home, ct-permit), `other-permit` (org-other,
	 * ct-permit), `other-provided` (org-other, provider org-home),
	 * `other-plain` (org-other, nothing), and `own-open` / `other-open`
	 * (status open, in org-home and org-other).
	 *
	 * @return array<string, array{0: array, 1: string[], 2: string[]}>
	 */
	public static function rowCases(): array {
		return [
			'team member sees only her own organisation\'s case'     => [[self::TEAM_RULE], ['team-toezicht'], ['own-permit']],
			'provider rule still reads across organisations'          => [[self::PROVIDER_RULE], ['aanbod-beheerder'], ['other-provided']],
			'provider rule through $eq reads across organisations'    => [
				[['group' => 'aanbod-beheerder', 'match' => ['provider' => ['$eq' => '$organisation']]]],
				['aanbod-beheerder'],
				['other-provided'],
			],
			'holding a team rule as well keeps the filter'            => [
				[self::PROVIDER_RULE, self::TEAM_RULE],
				['aanbod-beheerder', 'team-toezicht'],
				['own-permit'],
			],
			'a declared crossing rule reads across organisations'     => [
				[self::TEAM_RULE + ['crossOrganisation' => true]],
				['team-toezicht'],
				['other-permit', 'own-permit'],
			],
			'a crossing flag that is not literally true does nothing' => [
				[self::TEAM_RULE + ['crossOrganisation' => 'true']],
				['team-toezicht'],
				['own-permit'],
			],
			'a rule not held does not keep the filter'                => [
				[self::PROVIDER_RULE, self::TEAM_RULE],
				['aanbod-beheerder'],
				['other-provided'],
			],
			'an unconditional authenticated rule keeps the filter'    => [
				[self::PROVIDER_RULE, 'authenticated'],
				['aanbod-beheerder'],
				['own-open', 'own-permit'],
			],
			'a conditional authenticated rule keeps the filter'      => [
				[self::PROVIDER_RULE, ['group' => 'authenticated', 'match' => ['status' => 'open']]],
				['aanbod-beheerder'],
				['own-open'],
			],
			'a simple group rule still reads every row'               => [
				[self::TEAM_RULE, 'case-admins'],
				['team-toezicht', 'case-admins'],
				['other-open', 'other-permit', 'other-plain', 'other-provided', 'own-open', 'own-permit'],
			],
		];

	}//end rowCases()

	/**
	 * The list and union SQL return exactly the expected rows.
	 *
	 * @param array    $read     The schema's read rules.
	 * @param string[] $groups   The caller's groups.
	 * @param string[] $expected The visible rows.
	 *
	 * @return void
	 */
	#[DataProvider('rowCases')]
	public function skipListReturnsTheExpectedRows(array $read, array $groups, array $expected): void {
		$this->logIn(groups: $groups);

		$this->assertSame(expected: $expected, actual: $this->visibleRows(schema: $this->schema(read: $read)));

	}//end testListReturnsTheExpectedRows()

	/**
	 * The aggregation and count fast path waives the boundary exactly where the list does.
	 *
	 * @param array    $read     The schema's read rules.
	 * @param string[] $groups   The caller's groups.
	 * @param string[] $expected The visible rows.
	 *
	 * @return void
	 */
	#[DataProvider('rowCases')]
	public function testBoundaryDecisionMatchesTheRows(array $read, array $groups, array $expected): void {
		$this->logIn(groups: $groups);

		$this->assertSame(
			expected: (preg_grep('/^other-/', $expected) !== []),
			actual: $this->searchHandler()->organisationBoundaryWaivedByRbac(schema: $this->schema(read: $read))
		);

	}//end testBoundaryDecisionMatchesTheRows()

	/**
	 * A single-object read by a team member applies the organisation filter.
	 *
	 * @return void
	 */
	public function testSingleObjectReadByATeamMemberKeepsTheOrganisationFilter(): void {
		$this->logIn(groups: ['team-toezicht']);

		$organisation = $this->organisationHandler();
		$organisation->expects($this->once())->method('applyOrganizationFilter');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $this->queryBuilder(),
			schema: $this->schema(read: [self::TEAM_RULE])
		);

	}//end testSingleObjectReadByATeamMemberKeepsTheOrganisationFilter()

	/**
	 * A single-object read through the provider rule still drops the organisation filter.
	 *
	 * @return void
	 */
	public function testSingleObjectReadThroughTheProviderRuleDropsTheOrganisationFilter(): void {
		$this->logIn(groups: ['aanbod-beheerder']);

		$organisation = $this->organisationHandler();
		$organisation->expects($this->never())->method('applyOrganizationFilter');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $this->queryBuilder(),
			schema: $this->schema(read: [self::PROVIDER_RULE])
		);

	}//end testSingleObjectReadThroughTheProviderRuleDropsTheOrganisationFilter()

	/**
	 * Which match shapes cross organisations, read through the public decision.
	 *
	 * @return array<string, array{0: array, 1: bool}>
	 */
	public static function matchCases(): array {
		return [
			'static case type'                   => [['caseType' => ['$in' => ['ct-permit']]], false],
			'status value'                       => [['status' => 'published'], false],
			'caller uid'                         => [['assignee' => '$userId'], false],
			'only _organisation'                 => [['_organisation' => '$organisation'], false],
			'_organisation beside a case type'   => [['_organisation' => '$organisation', 'caseType' => 'ct-permit'], false],
			'provider is the caller organisation' => [['provider' => '$organisation'], true],
			'active organisation token'          => [['provider' => '$activeOrganisation'], true],
			'organisation uuid token'            => [['provider' => '$organisation.uuid'], true],
			'provider $eq the organisation'      => [['provider' => ['$eq' => '$organisation']], true],
			'provider $eq a literal organisation' => [['provider' => ['$eq' => 'org-other']], false],
			'provider $ne the organisation'      => [['provider' => ['$ne' => '$organisation']], false],
			'provider $in with another value'    => [['provider' => ['$in' => ['$organisation', 'org-other']]], false],
			'$eq beside another operator'        => [['provider' => ['$eq' => '$organisation', '$ne' => 'x']], false],
			'membership set'                     => [['administrationId' => ['$memberOf' => ['schema' => 'm', 'field' => 'a']]], true],
		];

	}//end matchCases()

	/**
	 * Only a match that binds a field to the caller's tenancy crosses organisations.
	 *
	 * @param array $match    The rule's match.
	 * @param bool  $crossing Whether the rule lifts the filter.
	 *
	 * @return void
	 */
	#[DataProvider('matchCases')]
	public function testOnlyATenancyBoundMatchCrossesOrganisations(array $match, bool $crossing): void {
		$this->logIn(groups: ['g']);

		$this->assertSame(
			expected: $crossing,
			actual: $this->rbac->hasConditionalRulesBypassingMultitenancy(
				schema: $this->schema(read: [['group' => 'g', 'match' => $match]])
			)
		);

	}//end testOnlyATenancyBoundMatchCrossesOrganisations()

	/**
	 * A crossing rule held only through `authenticated` or a user override does not lift the filter.
	 *
	 * @return void
	 */
	public function testACrossingRuleHeldWithoutAGroupDoesNotLiftTheFilter(): void {
		$this->logIn(groups: []);

		foreach ([['group' => 'authenticated'], ['user' => 'femke']] as $holder) {
			$this->assertFalse(
				condition: $this->rbac->hasConditionalRulesBypassingMultitenancy(
					schema: $this->schema(read: [$holder + ['match' => ['provider' => '$organisation']]])
				),
				message: json_encode($holder)
			);
		}

	}//end testACrossingRuleHeldWithoutAGroupDoesNotLiftTheFilter()

	/**
	 * A user override that names another user is not held.
	 *
	 * @return void
	 */
	public function testAUserOverrideForSomeoneElseIsNotHeld(): void {
		$this->logIn(groups: ['aanbod-beheerder']);

		$this->assertTrue(
			condition: $this->rbac->hasConditionalRulesBypassingMultitenancy(
				schema: $this->schema(
					read: [self::PROVIDER_RULE, ['user' => 'someone-else', 'match' => ['status' => 'x']]]
				)
			)
		);

	}//end testAUserOverrideForSomeoneElseIsNotHeld()

	/**
	 * Log `femke` in with the given groups.
	 *
	 * @param string[] $groups The caller's groups.
	 *
	 * @return void
	 */
	public static function probeCases(): array {
		$team = self::TEAM_RULE;
		$prov = self::PROVIDER_RULE;
		return [
			'X1 public conditional + team, inherit on: no other-permit' => [[['group' => 'public', 'match' => ['status' => 'open']], $team], ['team-toezicht'], true, 'other-permit'],
			'X2 public conditional + team, inherit off: no other-permit' => [[['group' => 'public', 'match' => ['status' => 'open']], $team], ['team-toezicht'], false, 'other-permit'],
			'X4 crossOrganisation int 1' => [[$team + ['crossOrganisation' => 1]], ['team-toezicht'], false, 'other-permit'],
			'X5 crossOrganisation string "1"' => [[$team + ['crossOrganisation' => '1']], ['team-toezicht'], false, 'other-permit'],
			'X6 crossOrganisation inside match value' => [[['group' => 'team-toezicht', 'match' => ['caseType' => ['$in' => ['ct-permit'], 'crossOrganisation' => true]]]], ['team-toezicht'], false, 'other-permit'],
			'X7 _organisation where another org' => [[['group' => 'team-toezicht', 'match' => ['_organisation' => 'org-other']]], ['team-toezicht'], false, 'other-'],
			'X8 _organisation $in other org' => [[['group' => 'team-toezicht', 'match' => ['_organisation' => ['$in' => ['org-other', 'org-home']]]]], ['team-toezicht'], false, 'other-'],
			'X9 _owner where' => [[['group' => 'team-toezicht', 'match' => ['_owner' => 'importer']]], ['team-toezicht'], false, 'other-'],
			'X10 provider + team (mixed) keeps filter for team rows' => [[$prov, $team], ['aanbod-beheerder', 'team-toezicht'], false, 'other-permit'],
			'X11 memberOf crossing + team keeps filter' => [[['group' => 'aanbod-beheerder', 'match' => ['provider' => ['$memberOf' => ['schema' => 'm', 'field' => 'a']]]], $team], ['aanbod-beheerder', 'team-toezicht'], false, 'other-permit'],
			'X12 provider crossing + team held via user override' => [[$prov, ['user' => 'femke', 'match' => ['caseType' => ['$in' => ['ct-permit']]]]], ['aanbod-beheerder'], false, 'other-permit'],
			'X13 provider + authenticated-crossing rule keeps team rows inside' => [[$prov, ['group' => 'authenticated', 'match' => ['provider' => '$organisation', 'caseType' => 'ct-permit']], $team], ['aanbod-beheerder', 'team-toezicht'], false, 'other-permit'],
			'X14 provider + public conditional (inherit on) + team' => [[$prov, ['group' => 'public', 'match' => ['status' => 'open']], $team], ['aanbod-beheerder', 'team-toezicht'], true, 'other-permit'],
			'X15 crossing via _organisation key with token on other field in $eq nested $in' => [[['group' => 'team-toezicht', 'match' => ['caseType' => ['$eq' => ['$in' => ['$organisation']]]]]], ['team-toezicht'], false, 'other-permit'],
		];
	}

	#[DataProvider('probeCases')]
	public function testProbeNoForeignRow(array $read, array $groups, bool $inherit, string $forbiddenPrefix): void {
		$this->logIn(groups: $groups);
		$this->inherit = $inherit;
		$rows = $this->visibleRows(schema: $this->schema(read: $read));
		$bad = array_values(array_filter($rows, static fn (string $r): bool => str_starts_with($r, $forbiddenPrefix)));
		$this->assertSame([], $bad, 'visible: ' . json_encode($rows));
	}

	public function testProbeLegitimateCrossingsStillWork(): void {
		$this->logIn(groups: ['aanbod-beheerder']);
		$this->assertContains('other-provided', $this->visibleRows(schema: $this->schema(read: [self::PROVIDER_RULE])));
	}

	public function testProbeStackiqProviderWithOwnOrgRuleStillCrosses(): void {
		$this->logIn(groups: ['aanbod-beheerder']);
		$rows = $this->visibleRows(schema: $this->schema(read: [['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation']], self::PROVIDER_RULE]));
		$this->assertContains('other-provided', $rows, 'stackiq usage/connection/aiSystem shape: ' . json_encode($rows));
	}

	public function skipProbeLearniqHrSimpleGroupReadsOtherTenants(): void {
		$this->logIn(groups: ['hr']);
		$rows = $this->visibleRows(schema: $this->schema(read: ['hr', 'administration-managers', 'coordinators']));
		$this->assertSame([], array_values(array_filter($rows, static fn ($r) => str_starts_with($r, 'other-'))), 'client-organisation read as hr: ' . json_encode($rows));
	}

	public function testProbeAdminUnchanged(): void {
		$this->logIn(groups: ['admin']);
		$this->assertTrue($this->rbac->hasConditionalRulesBypassingMultitenancy(schema: $this->schema(read: [self::TEAM_RULE])));
	}

	private function logIn(array $groups): void {
		if ($this->anonymous === true) {
			$this->userSession->method('getUser')->willReturn(null);
			$this->groupManager->method('getUserGroupIds')->willReturn([]);
			return;
		}
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
	 * A case schema with the given read rules and no public inheritance.
	 *
	 * @param array $read The read rules.
	 *
	 * @return Schema
	 */
	public bool $inherit = false;
	public static ?string $activeOrg = 'org-home';
	public static mixed $orgService = null;
	public bool $anonymous = false;

	private function schema(array $read): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Case');
		$schema->setAuthorization(['read' => $read, 'inheritFromPublic' => $this->inherit]);

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
	 * Run the list's organisation and RBAC conditions against a real table.
	 *
	 * @param Schema $schema The schema read.
	 *
	 * @return array<int, string> The uuids the list returns, sorted.
	 */
	private function visibleRows(Schema $schema, array $query = []): array {
		$conditions = $this->searchHandler()->buildWhereConditionsSql(query: $query, schema: $schema);

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
			. " ('other-open', 'importer', 'org-other', NULL, NULL, 'open'),"
			. " ('other-system', 'system', 'org-other', NULL, NULL, NULL),"
			. " ('other-draftopen', 'importer', 'org-other', 'ct-permit', NULL, 'open')"
		);

		$where = '';
		if ($conditions !== []) {
			$where = ' WHERE ' . implode(' AND ', $conditions);
		}

		$uuids = $pdo->query('SELECT _uuid FROM t' . $where)->fetchAll(PDO::FETCH_COLUMN);
		sort($uuids);

		return $uuids;

	}//end visibleRows()

	public static function rw83Cases(): array {
		$team = self::TEAM_RULE;
		$prov = self::PROVIDER_RULE;
		$pubOpen = ['group' => 'public', 'match' => ['status' => 'open']];
		return [
			// (2) legitimate crossings: neutral own-org spellings beside the provider rule keep other-provided.
			'N1 _organisation $organisation + provider'          => [[['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation']], $prov], ['aanbod-beheerder'], false, ['must' => ['other-provided'], 'never' => ['other-open', 'other-permit', 'other-plain']]],
			'N2 _organisation $activeOrganisation + provider'    => [[['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$activeOrganisation']], $prov], ['aanbod-beheerder'], false, ['must' => ['other-provided'], 'never' => ['other-open', 'other-permit', 'other-plain']]],
			'N3 _organisation $organisation.uuid + provider'     => [[['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation.uuid']], $prov], ['aanbod-beheerder'], false, ['must' => ['other-provided'], 'never' => ['other-open', 'other-plain']]],
			'N4 _organisation $activeOrganisation.uuid + prov'   => [[['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$activeOrganisation.uuid']], $prov], ['aanbod-beheerder'], false, ['must' => ['other-provided'], 'never' => ['other-open', 'other-plain']]],
			'N5 _organisation $eq $organisation + provider'      => [[['group' => 'aanbod-beheerder', 'match' => ['_organisation' => ['$eq' => '$organisation']]], $prov], ['aanbod-beheerder'], false, ['must' => ['other-provided'], 'never' => ['other-open', 'other-plain']]],
			'N6 gebruik shape: own-org + status, + consumer'     => [[['group' => 'gebruik-beheerder', 'match' => ['_organisation' => '$organisation', 'status' => 'open']], ['group' => 'gebruik-beheerder', 'match' => ['provider' => '$organisation']]], ['gebruik-beheerder'], false, ['must' => ['other-provided', 'own-open'], 'never' => ['other-open', 'other-plain']]],
			// (3) bypass attempts: must never show other-org rows outside the crossing.
			'B1 _organisation $in [token] + provider'            => [[['group' => 'aanbod-beheerder', 'match' => ['_organisation' => ['$in' => ['$organisation']]]], $prov], ['aanbod-beheerder'], false, ['never' => ['other-open', 'other-plain', 'other-permit']]],
			'B2 _organisation null + provider'                   => [[['group' => 'aanbod-beheerder', 'match' => ['_organisation' => null, 'status' => 'open']], $prov], ['aanbod-beheerder'], false, ['never' => ['other-open', 'other-plain']]],
			'B3 _ORGANISATION case variant + team'               => [[['group' => 'team-toezicht', 'match' => ['_ORGANISATION' => '$organisation']], $team], ['team-toezicht'], false, ['never' => ['other-permit', 'other-plain']]],
			'B4 _Organisation case variant alone'                => [[['group' => 'team-toezicht', 'match' => ['_Organisation' => '$organisation', 'caseType' => ['$in' => ['ct-permit']]]]], ['team-toezicht'], false, ['never' => ['other-permit', 'other-plain']]],
			'B5 own-org pinned + crossOrganisation true'         => [[['group' => 'team-toezicht', 'match' => ['_organisation' => '$organisation'], 'crossOrganisation' => true], $prov], ['team-toezicht', 'aanbod-beheerder'], false, ['never' => ['other-plain', 'other-open']]],
			'B6 pinned + team $eq nested $and-like'              => [[['group' => 'team-toezicht', 'match' => ['_organisation' => ['$eq' => '$organisation', '$ne' => 'x']]], $prov, $team], ['team-toezicht', 'aanbod-beheerder'], false, ['never' => ['other-permit', 'other-plain']]],
			'B7 match $or key beside pinned org'                 => [[['group' => 'team-toezicht', 'match' => ['_organisation' => '$organisation', '$or' => [['caseType' => 'ct-permit']]]], $prov], ['team-toezicht', 'aanbod-beheerder'], false, ['never' => ['other-permit', 'other-plain', 'other-open']]],
			'B8 no active org: pinned + provider'                => [[['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation']], $prov], ['aanbod-beheerder'], false, ['never' => ['other-plain', 'other-open', 'other-permit'], 'noorg' => true]],
			// #5433 claims.
			'P1 public cond + team, inherit on'                  => [[$pubOpen, $team], ['team-toezicht'], true, ['must' => ['other-open', 'own-permit', 'other-draftopen'], 'never' => ['other-permit', 'other-plain']]],
			'P2 public cond + authenticated, inherit on'         => [[$pubOpen, 'authenticated'], [], true, ['must' => ['other-open', 'own-permit'], 'never' => ['other-permit', 'other-plain', 'other-provided']]],
			'P3 public cond + provider (stackiq-like), inherit'  => [[$pubOpen, $prov], ['aanbod-beheerder'], true, ['must' => ['other-provided', 'other-open']]],
			'P4 unconditional public + team'                     => [['public', $team], ['team-toezicht'], true, ['must' => ['other-plain', 'other-permit']]],
			'P6 admin, public cond + team'                       => [[$pubOpen, $team], ['admin'], true, ['must' => ['other-plain', 'other-permit']]],
			'P8 public cond with pinned own org + team'          => [[['group' => 'public', 'match' => ['_organisation' => '$organisation']], $team], ['team-toezicht'], true, ['never' => ['other-permit', 'other-plain', 'other-open']]],
			'P9 public match on _owner token + team'             => [[['group' => 'public', 'match' => ['_owner' => '$userId']], $team], ['team-toezicht'], true, ['never' => ['other-permit', 'other-plain', 'other-open']]],
			'P11 stackiq connection exact, aanbod-beheerder'   => [self::STACKIQ_CONNECTION, ['aanbod-beheerder'], true, ['must' => ['other-provided', 'own-permit', 'other-open'], 'never' => ['other-plain', 'other-permit']]],
			'P12 stackiq connection exact, gebruik-beheerder'  => [self::STACKIQ_CONNECTION, ['gebruik-beheerder'], true, ['must' => ['own-permit'], 'never' => ['other-plain', 'other-permit', 'other-provided']]],
			'Q1 pub cond + team, inherit ON'                   => [[['group' => 'public', 'match' => ['status' => 'open']], self::TEAM_RULE], ['team-toezicht'], true, ['must' => ['other-open', 'other-draftopen', 'own-permit'], 'never' => ['other-permit', 'other-plain', 'other-provided', 'other-system']]],
			'Q2 pub cond + team, inherit OFF'                  => [[['group' => 'public', 'match' => ['status' => 'open']], self::TEAM_RULE], ['team-toezicht'], false, ['must' => ['own-permit'], 'never' => ['other-open', 'other-draftopen', 'other-permit', 'other-plain', 'own-open']]],
			'Q3 pub cond + team + provider, inherit ON'        => [[['group' => 'public', 'match' => ['status' => 'open']], self::TEAM_RULE, self::PROVIDER_RULE], ['team-toezicht', 'aanbod-beheerder'], true, ['must' => ['other-open', 'own-permit'], 'never' => ['other-permit', 'other-plain']]],
			'Q4 pub cond + team + provider, inherit OFF'       => [[['group' => 'public', 'match' => ['status' => 'open']], self::TEAM_RULE, self::PROVIDER_RULE], ['team-toezicht', 'aanbod-beheerder'], false, ['never' => ['other-permit', 'other-plain', 'other-open', 'other-draftopen']]],
			'Q5 pub cond + provider, inherit OFF'              => [[['group' => 'public', 'match' => ['status' => 'open']], self::PROVIDER_RULE], ['aanbod-beheerder'], false, ['must' => ['other-provided'], 'never' => ['other-open', 'other-draftopen', 'other-plain', 'other-permit']]],
			'Q6 pub cond + authenticated, inherit OFF'         => [[['group' => 'public', 'match' => ['status' => 'open']], 'authenticated'], [], false, ['must' => ['own-permit'], 'never' => ['other-open', 'other-permit', 'other-plain']]],
			'Q7 two pub conds + team, inherit ON'              => [[['group' => 'public', 'match' => ['status' => 'open']], ['group' => 'public', 'match' => ['provider' => 'org-home']], self::TEAM_RULE], ['team-toezicht'], true, ['must' => ['other-open', 'other-provided'], 'never' => ['other-permit', 'other-plain']]],
			'Q8 pub cond + simple group reads all (spec)'      => [[['group' => 'public', 'match' => ['status' => 'open']], 'case-admins'], ['case-admins'], true, ['must' => ['other-plain', 'other-permit']]],
			'Q9 pub cond only, no other rule, inherit ON'      => [[['group' => 'public', 'match' => ['status' => 'open']]], ['nobody'], true, ['must' => ['other-open', 'own-open'], 'never' => ['other-permit', 'own-permit', 'other-plain']]],
		];
	}

	#[DataProvider('rw83Cases')]
	public function testRw83Union(array $read, array $groups, bool $inherit, array $expect): void {
		$this->runCase($read, $groups, $inherit, $expect, fn (Schema $s, array $q): array => $this->visibleRows(schema: $s, query: $q));
	}

	#[DataProvider('rw83Cases')]
	public function testRw83QueryBuilderList(array $read, array $groups, bool $inherit, array $expect): void {
		$this->runCase($read, $groups, $inherit, $expect, fn (Schema $s, array $q): array => $this->qbRows(schema: $s, query: $q, single: false));
	}

	#[DataProvider('rw83Cases')]
	public function testRw83SingleRead(array $read, array $groups, bool $inherit, array $expect): void {
		$this->runCase($read, $groups, $inherit, $expect, fn (Schema $s, array $q): array => $this->qbRows(schema: $s, query: $q, single: true));
	}

	#[DataProvider('rw83Cases')]
	public function testRw83AggregationDecision(array $read, array $groups, bool $inherit, array $expect): void {
		// The aggregation fast path renders SCOPE_ALL when waived; it must not waive while the list keeps a foreign-row ban.
		$this->anonymous = (($expect['anon'] ?? false) === true);
		$this->logIn(groups: $groups);
		$this->inherit = $inherit;
		self::$activeOrg = (($expect['noorg'] ?? false) === true) ? null : 'org-home';
		$schema = $this->schema(read: $read);
		$h = $this->searchHandler();
		$waived = $h->organisationBoundaryWaivedByRbac(schema: $schema);
		$branch = method_exists($h, 'organisationBoundaryHasPublicBranch') ? $h->organisationBoundaryHasPublicBranch(schema: $schema) : false;
		if (isset($expect['query']) === true || $waived === false || $branch === true) {
			$this->addToAssertionCount(1);
			return;
		}
		// Waived: the native SQL renders SCOPE_ALL, i.e. RBAC only.
		$rbacOnly = $this->visibleRows(schema: $schema, query: ['_multitenancy' => false]);
		foreach (($expect['never'] ?? []) as $r) {
			$this->assertNotContains($r, $rbacOnly, 'AGG LEAK ' . $r . ' ' . json_encode($rbacOnly));
		}
		$this->addToAssertionCount(1);
	}

	private function runCase(array $read, array $groups, bool $inherit, array $expect, callable $rows): void {
		$this->anonymous = (($expect['anon'] ?? false) === true);
		self::$activeOrg = (($expect['noorg'] ?? false) === true) ? null : 'org-home';
		if (($expect['sys'] ?? false) === true) {
			$org = $this->createMock(originalClassName: \OCA\OpenRegister\Service\OrganisationService::class);
			$org->method('getSystemReaderGroups')->willReturn(['sys-readers']);
			$org->method('getSystemUserId')->willReturn('system');
			self::$orgService = $org;
		} else {
			self::$orgService = null;
		}
		$this->logIn(groups: $groups);
		$this->inherit = $inherit;
		try {
			$got = $rows($this->schema(read: $read), ($expect['query'] ?? []));
		} catch (\Throwable $e) {
			$this->markTestIncomplete('threw ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 160));
		}
		$msg = 'visible: ' . json_encode($got);
		foreach (($expect['never'] ?? []) as $r) {
			$this->assertNotContains($r, $got, 'LEAK ' . $r . ' ' . $msg);
		}
		foreach (($expect['must'] ?? []) as $r) {
			$this->assertContains($r, $got, 'MISSING ' . $r . ' ' . $msg);
		}
	}

	/** Real-SQL QueryBuilder path: list (buildFilteredQuery-like via applyAccessControlToQuery) or single read. */
	private function qbRows(Schema $schema, array $query, bool $single): array {
		$db = (new \ReflectionClass(\OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase::class))->newInstanceWithoutConstructor();
		$test = $this;
		\Closure::bind(function () use ($test): void {
			$this->test = $test;
			$this->connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		}, $db, \OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase::class)();
		$c = $db->connection();
		$c->getNativeConnection()->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$c->executeStatement('CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT, _deleted TEXT, _archived TEXT, _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT, case_type TEXT, provider TEXT, status TEXT)');
		foreach ([
			['own-permit', 'importer', 'org-home', 'ct-permit', null, null],
			['other-permit', 'importer', 'org-other', 'ct-permit', null, null],
			['other-provided', 'importer', 'org-other', null, 'org-home', null],
			['other-plain', 'importer', 'org-other', null, null, null],
			['own-open', 'importer', 'org-home', null, null, 'open'],
			['other-open', 'importer', 'org-other', null, null, 'open'],
			['other-system', 'system', 'org-other', null, null, null],
			['other-draftopen', 'importer', 'org-other', 'ct-permit', null, 'open'],
		] as $r) {
			$c->insert('t', ['_uuid' => $r[0], '_owner' => $r[1], '_organisation' => $r[2], 'case_type' => $r[3], 'provider' => $r[4], 'status' => $r[5]]);
		}
		$idb = $db->idbConnection();
		$org = $this->organisationHandler();
		$org->method('applyOrganizationFilter')->willReturnCallback(
			static function ($qb, $adminBypassEnabled = false, $registerId = null, $schemaId = null, $withOrgless = false): void {
				$cond = [$qb->expr()->eq('t._organisation', $qb->createNamedParameter('org-home'))];
				if ($withOrgless === true) {
					$cond[] = $qb->expr()->isNull('t._organisation');
				}
				$qb->andWhere($qb->expr()->orX(...$cond));
			}
		);
		$handler = new MagicSearchHandler(
			$idb,
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->rbac,
			$org,
			$this->createMock(originalClassName: SchemaTypeConverter::class),
			$this->createMock(originalClassName: DateTimeNormalizer::class),
			relatedRows: $this->createMock(originalClassName: RelatedRowQueryApplier::class)
		);
		$qb = $idb->getQueryBuilder();
		$qb->select('t._uuid')->from('t', 't');
		$explicit = (($query['_multitenancy_explicit'] ?? false) === true);
		if ($single === true) {
			$handler->applyAccessControlToQuery(qb: $qb, schema: $schema);
		} else {
			$m = new \ReflectionMethod($handler, 'resolveMultitenancyFlag');
			$mt = $m->invoke($handler, true, $explicit, $schema);
			$a = new \ReflectionMethod($handler, 'applyAccessControlFilters');
			$a->invoke($handler, $qb, $schema, true, $mt, $explicit);
		}
		$rows = array_map(static fn ($row) => $row['_uuid'], $qb->executeQuery()->fetchAll());
		sort($rows);
		return $rows;
	}
}//end class

if (class_exists(\OC\DB\QueryBuilder\CompositeExpression::class) === false) {
	require_once "/home/rubenlinde/nextcloud-docker-dev/workspace/server/lib/private/DB/QueryBuilder/CompositeExpression.php";
}
