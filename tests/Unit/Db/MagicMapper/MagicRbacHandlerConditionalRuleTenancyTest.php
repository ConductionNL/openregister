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
 */
class MagicRbacHandlerConditionalRuleTenancyTest extends TestCase {

	/**
	 * The handling-team rule dossiq projects onto its case schema.
	 */
	private const TEAM_RULE = ['group' => 'team-toezicht', 'match' => ['caseType' => ['$in' => ['ct-permit']]]];

	/**
	 * The provider rule the rbac-scopes spec names as crossing organisations.
	 */
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
	public function testListReturnsTheExpectedRows(array $read, array $groups, array $expected): void {
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
	 * A case schema with the given read rules and no public inheritance.
	 *
	 * @param array $read The read rules.
	 *
	 * @return Schema
	 */
	private function schema(array $read): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Case');
		$schema->setAuthorization(['read' => $read, 'inheritFromPublic' => false]);

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
	private function visibleRows(Schema $schema): array {
		$conditions = $this->searchHandler()->buildWhereConditionsSql(query: [], schema: $schema);

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
