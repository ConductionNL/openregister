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
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
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
use OCA\OpenRegister\Service\Rbac\ReadRuleTenancy;
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
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;

/**
 * Tests that only a rule marked `crossOrganisation: true` reads other organisations' rows.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacFilterCall
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
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
 * @uses   \OCA\OpenRegister\Db\MagicMapper\OrganisationScopeCondition
 * @uses   \OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler
 * @uses   \OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator
 * @uses   \OCA\OpenRegister\Service\Rbac\MatrixRowWhere
 * @uses   \OCA\OpenRegister\Service\Rbac\MatrixRowsFromApps
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 */
class MagicRbacHandlerConditionalRuleTenancyTest extends TestCase {

	/**
	 * The handling-team rule dossiq projects onto its case schema.
	 */
	private const TEAM_RULE = ['group' => 'team-toezicht', 'match' => ['caseType' => ['$in' => ['ct-permit']]]];

	/**
	 * stackiq's provider rule, without the marker.
	 */
	private const PROVIDER_RULE = ['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']];

	/**
	 * The same provider rule, marked as crossing organisations.
	 */
	private const MARKED_PROVIDER_RULE = self::PROVIDER_RULE + ['crossOrganisation' => true];

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
			identity: new MagicMapperIdentity(
				userSession: $this->userSession,
				groupManager: $this->groupManager,
				userManager: $userManager
			),
			settings: new MagicRbacSettings(
				appConfig: $appConfig
			),
			support: new MagicRbacSupport(
				conditionMatcher: $conditionMatcher,
				container: $container,
				logger: $logger
			)
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
		$ownOrg = ['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation']];
		$ownOrgEq = ['group' => 'aanbod-beheerder', 'match' => ['_organisation' => ['$eq' => '$organisation'], 'status' => 'open']];
		$providerEq = ['group' => 'aanbod-beheerder', 'match' => ['provider' => ['$eq' => '$organisation']]];
		$all = ['other-open', 'other-permit', 'other-plain', 'other-provided', 'own-open', 'own-permit'];

		return [
			'team member sees only her own organisation\'s case'      => [[self::TEAM_RULE], ['team-toezicht'], ['own-permit']],
			'an unmarked provider rule stays inside the organisation' => [[self::PROVIDER_RULE], ['aanbod-beheerder'], []],
			'a marked provider rule reads across organisations'       => [[self::MARKED_PROVIDER_RULE], ['aanbod-beheerder'], ['other-provided']],
			'an unmarked provider rule through $eq stays inside'      => [[$providerEq], ['aanbod-beheerder'], []],
			'a marked provider rule through $eq reads across'         => [
				[$providerEq + ['crossOrganisation' => true]],
				['aanbod-beheerder'],
				['other-provided'],
			],
			'an unmarked provider rule beside a team rule stays inside' => [
				[self::PROVIDER_RULE, self::TEAM_RULE],
				['aanbod-beheerder', 'team-toezicht'],
				['own-permit'],
			],
			'a marked provider rule widens the filter beside a team rule' => [
				[self::MARKED_PROVIDER_RULE, self::TEAM_RULE],
				['aanbod-beheerder', 'team-toezicht'],
				['other-provided', 'own-permit'],
			],
			'a marked team rule reads across organisations'           => [
				[self::TEAM_RULE + ['crossOrganisation' => true]],
				['team-toezicht'],
				['other-permit', 'own-permit'],
			],
			'a crossing flag that is not literally true does nothing' => [
				[self::TEAM_RULE + ['crossOrganisation' => 'true']],
				['team-toezicht'],
				['own-permit'],
			],
			'a marked rule not held does not widen the filter'        => [
				[self::PROVIDER_RULE, self::TEAM_RULE + ['crossOrganisation' => true]],
				['aanbod-beheerder'],
				[],
			],
			'an unconditional authenticated rule keeps the filter'    => [
				[self::MARKED_PROVIDER_RULE, 'authenticated'],
				['aanbod-beheerder'],
				['other-provided', 'own-open', 'own-permit'],
			],
			'a conditional authenticated rule keeps the filter'       => [
				[self::MARKED_PROVIDER_RULE, ['group' => 'authenticated', 'match' => ['status' => 'open']]],
				['aanbod-beheerder'],
				['other-provided', 'own-open'],
			],
			'a marked authenticated rule reads across organisations'  => [
				[['group' => 'authenticated', 'match' => ['status' => 'open'], 'crossOrganisation' => true]],
				[],
				['other-open', 'own-open'],
			],
			'an own-organisation rule beside an unmarked provider rule' => [
				[$ownOrg, self::PROVIDER_RULE],
				['aanbod-beheerder'],
				['own-open', 'own-permit'],
			],
			'an own-organisation rule beside a marked provider rule'  => [
				[$ownOrg, self::MARKED_PROVIDER_RULE],
				['aanbod-beheerder'],
				['other-provided', 'own-open', 'own-permit'],
			],
			'an own-organisation rule through $eq beside a marked provider' => [
				[$ownOrgEq, self::MARKED_PROVIDER_RULE],
				['aanbod-beheerder'],
				['other-provided', 'own-open'],
			],
			'an own-organisation rule alone keeps the filter'         => [[$ownOrg], ['aanbod-beheerder'], ['own-open', 'own-permit']],
			'a foreign organisation pin reads nothing'                => [
				[['group' => 'aanbod-beheerder', 'match' => ['_organisation' => 'org-other']], self::PROVIDER_RULE],
				['aanbod-beheerder'],
				[],
			],
			'a simple group rule stays inside the organisation'       => [
				[self::TEAM_RULE, 'case-admins'],
				['team-toezicht', 'case-admins'],
				['own-open', 'own-permit'],
			],
			'a marked group rule without match reads every row'       => [
				[self::TEAM_RULE, ['group' => 'case-admins', 'crossOrganisation' => true]],
				['team-toezicht', 'case-admins'],
				$all,
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
	 * The aggregation and count fast path knows the boundary is waived or
	 * widened exactly where the list returns another organisation's rows.
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

		$schema = $this->schema(read: $read);
		$search = $this->searchHandler();

		$this->assertSame(
			expected: (preg_grep('/^other-/', $expected) !== []),
			actual: $search->organisationBoundaryWaivedByRbac(schema: $schema)
				|| $search->organisationBoundaryHasCrossOrganisationBranch(schema: $schema)
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
	 * A single-object read through an unmarked provider rule keeps the organisation filter.
	 *
	 * @return void
	 */
	public function testSingleObjectReadThroughAnUnmarkedProviderRuleKeepsTheOrganisationFilter(): void {
		$this->logIn(groups: ['aanbod-beheerder']);

		$organisation = $this->organisationHandler();
		$organisation->expects($this->once())->method('applyOrganizationFilter');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $this->queryBuilder(),
			schema: $this->schema(read: [self::PROVIDER_RULE])
		);

	}//end testSingleObjectReadThroughAnUnmarkedProviderRuleKeepsTheOrganisationFilter()

	/**
	 * A single-object read through a marked rule without match drops the organisation filter.
	 *
	 * @return void
	 */
	public function testSingleObjectReadThroughAMarkedRuleWithoutMatchDropsTheOrganisationFilter(): void {
		$this->logIn(groups: ['aanbod-beheerder']);

		$organisation = $this->organisationHandler();
		$organisation->expects($this->never())->method('applyOrganizationFilter');
		$organisation->expects($this->never())->method('resolveOrganizationScope');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $this->queryBuilder(),
			schema: $this->schema(read: [['group' => 'aanbod-beheerder', 'crossOrganisation' => true]])
		);

	}//end testSingleObjectReadThroughAMarkedRuleWithoutMatchDropsTheOrganisationFilter()

	/**
	 * A single-object read through a marked provider rule keeps the
	 * organisation scope and widens it by the rule's rows.
	 *
	 * @return void
	 */
	public function testSingleObjectReadThroughAMarkedProviderRuleWidensTheOrganisationScope(): void {
		$this->logIn(groups: ['aanbod-beheerder']);

		$organisation = $this->organisationHandler();
		$organisation->expects($this->never())->method('applyOrganizationFilter');
		$organisation->expects($this->once())->method('resolveOrganizationScope');

		$this->searchHandler(organisation: $organisation)->applyAccessControlToQuery(
			qb: $this->queryBuilder(),
			schema: $this->schema(read: [self::MARKED_PROVIDER_RULE])
		);

	}//end testSingleObjectReadThroughAMarkedProviderRuleWidensTheOrganisationScope()

	/**
	 * Match shapes, none of which crosses organisations without the marker.
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
			'_organisation in a membership set'  => [['_organisation' => ['$memberOf' => ['schema' => 'm', 'field' => 'a']]], false],
			'membership set'                     => [['administrationId' => ['$memberOf' => ['schema' => 'm', 'field' => 'a']]], true],
		];

	}//end matchCases()

	/**
	 * Only the marker takes a rule across organisations, whatever its match binds.
	 *
	 * @param array $match        The rule's match.
	 * @param bool  $bindsTenancy Whether the match binds a field to the caller's tenancy.
	 *
	 * @return void
	 */
	#[DataProvider('matchCases')]
	public function testOnlyTheMarkerCrossesOrganisations(array $match, bool $bindsTenancy): void {
		$this->logIn(groups: ['g']);
		$unmarked = $this->schema(read: [['group' => 'g', 'match' => $match]]);
		$marked = $this->schema(read: [['group' => 'g', 'match' => $match, 'crossOrganisation' => true]]);

		$this->assertFalse(condition: $this->rbac->hasConditionalRulesBypassingMultitenancy(schema: $unmarked), message: 'unmarked lifts');
		$this->assertFalse(condition: $this->rbac->hasCrossOrganisationBranch(schema: $unmarked), message: 'unmarked widens');
		$this->assertFalse(condition: $this->rbac->hasConditionalRulesBypassingMultitenancy(schema: $marked), message: 'marked lifts');
		$this->assertTrue(condition: $this->rbac->hasCrossOrganisationBranch(schema: $marked), message: 'marked widens');
		$this->assertSame(expected: $bindsTenancy, actual: (new ReadRuleTenancy())->bindsFieldToCallerTenancy(rule: ['match' => $match]));

	}//end testOnlyTheMarkerCrossesOrganisations()

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
	 * A crossing rule that names the caller as its user does not lift the
	 * filter, also when it names a group the caller is in: the user decides
	 * whether it is held, as on the RBAC filter.
	 *
	 * @return void
	 */
	public function testAUserHeldCrossingRuleDoesNotLiftTheFilter(): void {
		$this->logIn(groups: ['aanbod-beheerder']);

		$this->assertFalse(
			condition: $this->rbac->hasConditionalRulesBypassingMultitenancy(
				schema: $this->schema(
					read: [['user' => 'femke', 'group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']]]
				)
			)
		);

	}//end testAUserHeldCrossingRuleDoesNotLiftTheFilter()

	/**
	 * A marked rule whose match is not a list of conditions admits nothing,
	 * so it neither lifts nor widens the organisation filter.
	 *
	 * @return void
	 */
	public function testAMarkedRuleWithAMalformedMatchDoesNotCross(): void {
		$this->logIn(groups: ['aanbod-beheerder']);
		$schema = $this->schema(read: [['group' => 'aanbod-beheerder', 'match' => 'open', 'crossOrganisation' => true]]);

		$this->assertFalse(condition: $this->rbac->hasConditionalRulesBypassingMultitenancy(schema: $schema));
		$this->assertFalse(condition: $this->rbac->hasCrossOrganisationBranch(schema: $schema));

	}//end testAMarkedRuleWithAMalformedMatchDoesNotCross()

	/**
	 * A department matrix row marked as crossing organisations reaches the
	 * decision through the resolved block, and an unmarked one does not.
	 *
	 * @return void
	 */
	public function testAMarkedMatrixRowWidensTheOrganisationFilter(): void {
		$this->logIn(groups: ['aanbod-beheerder']);
		$row = ['value' => 'team-a', 'group' => 'aanbod-beheerder', 'actions' => ['read'], 'where' => ['provider' => '$organisation']];
		$matrix = static fn (array $row): array => [
			'read'   => [],
			'matrix' => ['field' => 'assignedGroup', 'userSource' => ['groups' => true], 'rows' => [$row]],
		];

		$marked = new Schema();
		$marked->setId(8);
		$marked->setAuthorization($matrix(row: $row + ['crossOrganisation' => true]));
		$this->assertTrue(condition: $this->rbac->hasCrossOrganisationBranch(schema: $marked));

		$unmarked = new Schema();
		$unmarked->setId(9);
		$unmarked->setAuthorization($matrix(row: $row));
		$this->assertFalse(condition: $this->rbac->hasCrossOrganisationBranch(schema: $unmarked));

	}//end testAMarkedMatrixRowWidensTheOrganisationFilter()

	/**
	 * A marked user rule is held only by the user it names.
	 *
	 * @return void
	 */
	public function testAMarkedUserRuleIsHeldOnlyByItsUser(): void {
		$this->logIn(groups: ['aanbod-beheerder']);

		$this->assertFalse(
			condition: $this->rbac->hasConditionalRulesBypassingMultitenancy(
				schema: $this->schema(read: [['user' => 'someone-else', 'crossOrganisation' => true]])
			)
		);
		$this->assertTrue(
			condition: $this->rbac->hasConditionalRulesBypassingMultitenancy(
				schema: $this->schema(read: [['user' => 'femke', 'crossOrganisation' => true]])
			)
		);

	}//end testAMarkedUserRuleIsHeldOnlyByItsUser()

	/**
	 * stackiq's provider holds an own-organisation rule beside the provider
	 * rule on usage, connection and aiSystem. With the marker on the
	 * provider rule it reads the rows of other organisations that name it as
	 * provider; without it, only its own.
	 *
	 * @return void
	 */
	public function testProbeStackiqProviderCrossesOnlyWithTheMarker(): void {
		$this->logIn(groups: ['aanbod-beheerder']);
		$ownOrg = ['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation']];

		$marked = $this->visibleRows(schema: $this->schema(read: [$ownOrg, self::MARKED_PROVIDER_RULE]));
		$this->assertContains(needle: 'other-provided', haystack: $marked);
		$this->assertNotContains(needle: 'other-permit', haystack: $marked);

		$unmarked = $this->visibleRows(schema: $this->schema(read: [$ownOrg, self::PROVIDER_RULE]));
		$this->assertNotContains(needle: 'other-provided', haystack: $unmarked);

	}//end testProbeStackiqProviderCrossesOnlyWithTheMarker()

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
		$schema->setProperties(
			[
				'caseType' => ['type' => 'string'],
				'provider' => ['type' => 'string'],
				'status'   => ['type' => 'string'],
			]
		);
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
		$conditions = $this->searchHandler()->buildWhereClause(request: new WhereClauseRequest(query: [], table: new MagicReadTable(schema: $schema)));

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
