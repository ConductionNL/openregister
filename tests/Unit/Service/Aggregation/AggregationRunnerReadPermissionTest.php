<?php

/**
 * An aggregate is a read of many rows: it asks for READ, and counts only
 * the rows the caller may read.
 *
 * The gate used to ask for `list`, a verb most leaf-app schemas never
 * declare (learniq's blocks name read/create/update/delete), so every
 * non-admin was refused: "You do not have permission to aggregate schema
 * engagement-score", and every staff stat tile showed a dash.
 *
 * Opening the gate is only safe if the fast path's own SQL applies the read
 * rule's row filter, the way the list does. Without it, a caller whose read
 * rule is `{group: authenticated, match: {learnerId: $userId}}` would be
 * counted over every learner's rows. These tests run the native SQL against a
 * real SQLite table with the real MagicRbacHandler predicate, so the counts
 * are what a database answers, not what a mock was told to say.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\DB\IPreparedStatement;
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
 * No `covers` metadata, deliberately; see AggregationRunnerNativeBucketTest and #2847.
 */
class AggregationRunnerReadPermissionTest extends TestCase {
	private const TABLE = 'register_1_schema_engagement_score';

	/**
	 * learniq's EngagementScore authorization block, verbatim from
	 * ConductionNL/learniq lib/Settings/learniq_register.json (development,
	 * 2026-10-02). No `list` key.
	 */
	private const LEARNIQ_AUTHORIZATION = [
		'read' => [
			'instructors',
			'team-leads',
			'coordinators',
			'administration-managers',
			['group' => 'authenticated', 'match' => ['learnerId' => '$userId']],
		],
		'create' => ['instructors', 'hr', 'compliance-officers', 'team-leads'],
		'update' => ['instructors', 'hr', 'compliance-officers', 'team-leads'],
	];

	private PDO $pdo;

	/**
	 * Build the table: three rows for learner-a, two for learner-b.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		// The non-Postgres RBAC predicate is written in the MySQL dialect.
		// SQLite's json_extract() already returns the unquoted scalar, so
		// JSON_UNQUOTE is the identity here; registering it lets the REAL
		// predicate run instead of a hand-written stand-in.
		$this->pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$this->pdo->exec(
			'CREATE TABLE "oc_' . self::TABLE . '" ('
			. '_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT,'
			. ' _deleted TEXT, _archived TEXT, learner_id TEXT, level TEXT)'
		);
		$rows = [
			['u1', 'learner-a', 'low'],
			['u2', 'learner-a', 'low'],
			['u3', 'learner-a', 'high'],
			['u4', 'learner-b', 'low'],
			['u5', 'learner-b', 'high'],
		];
		$insert = $this->pdo->prepare(
			'INSERT INTO "oc_' . self::TABLE . '" (_uuid, _owner, learner_id, level) VALUES (?, ?, ?, ?)'
		);
		foreach ($rows as [$uuid, $learner, $level]) {
			// Owned by a system account, so the owner-admit cannot explain a count.
			$insert->execute([$uuid, 'system-importer', $learner, $level]);
		}
	}//end setUp()

	/**
	 * A teacher with schema-level `read` and no `list` aggregates, over every row.
	 *
	 * @return void
	 */
	public function testAReaderWithoutListMayAggregateAndSeesEveryRowItMayRead(): void {
		$runner = $this->runner(userId: 'teacher-1', groups: ['instructors'], grants: ['read']);

		$result = $runner->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->countByLevel());

		$this->assertSame(['high' => 2, 'low' => 3], $this->counts($result));
	}//end testAReaderWithoutListMayAggregateAndSeesEveryRowItMayRead()

	/**
	 * A learner whose only read rule is conditional on its own learnerId may
	 * aggregate, and is counted over its OWN rows only. Before the fix the
	 * gate refused; with the gate opened but no row predicate, it would have
	 * been counted over all five rows.
	 *
	 * @return void
	 */
	public function testAConditionalReaderIsCountedOverItsOwnRowsOnly(): void {
		$runner = $this->runner(userId: 'learner-a', groups: ['students'], grants: []);

		$result = $runner->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->countByLevel());

		$this->assertSame(['high' => 1, 'low' => 2], $this->counts($result));
	}//end testAConditionalReaderIsCountedOverItsOwnRowsOnly()

	/**
	 * A caller no read rule admits is refused, as before.
	 *
	 * @return void
	 */
	public function testACallerWithoutReadIsRefused(): void {
		$schema = $this->schema(
			authorization: ['read' => ['instructors'], 'create' => ['instructors']]
		);
		$runner = $this->runner(userId: 'outsider', groups: ['students'], grants: []);

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('You do not have permission to aggregate schema "engagement-score".');

		$runner->runAdhoc(register: $this->register(), schema: $schema, query: $this->countByLevel());
	}//end testACallerWithoutReadIsRefused()

	/**
	 * A schema that declares `list` keeps working for a caller holding it.
	 *
	 * @return void
	 */
	public function testAListGrantStillAdmits(): void {
		$schema = $this->schema(
			authorization: ['list' => ['auditors'], 'read' => ['auditors']]
		);
		$runner = $this->runner(userId: 'auditor-1', groups: ['auditors'], grants: ['list']);

		$result = $runner->runAdhoc(register: $this->register(), schema: $schema, query: $this->countByLevel());

		$this->assertSame(['high' => 2, 'low' => 3], $this->counts($result));
	}//end testAListGrantStillAdmits()

	/**
	 * An internal caller that passes `bypassRbac` is not narrowed by the
	 * session user's read rule: it has already decided who sees the figure.
	 *
	 * @return void
	 */
	public function testBypassRbacSkipsTheRowPredicate(): void {
		$schema = $this->schema();
		$schema->setConfiguration(
			[
				'x-openregister-aggregations' => [
					'byLevel' => ['metric' => 'count', 'groupBy' => ['field' => 'level']],
				],
			]
		);
		$runner = $this->runner(userId: 'learner-a', groups: ['students'], grants: [], schema: $schema);

		$result = $runner->run(registerRef: 'learniq', schemaRef: 'engagement-score', name: 'byLevel', bypassRbac: true);

		$this->assertSame(['high' => 2, 'low' => 3], $this->counts($result));
	}//end testBypassRbacSkipsTheRowPredicate()

	/**
	 * Where the list waives the organisation boundary for a reader reaching
	 * the rows through an RBAC rule, the aggregate does too: a teacher in a
	 * different organisation from the rows counts what its list shows.
	 * Measured live before this: list 1189, aggregate 0.
	 *
	 * @return void
	 */
	/**
	 * runAdhoc() honours bypassRbac on the native SQL path, as run() does.
	 *
	 * A conditional reader (learner-a may read only its own three rows) asks as
	 * the system: every row counts. Live pass O14: the internal-system mode
	 * only skipped the read gate, and the row predicate still narrowed the count.
	 *
	 * @return void
	 */
	public function testAdhocBypassRbacSkipsTheRowPredicate(): void {
		$runner = $this->runner(userId: 'learner-a', groups: ['students'], grants: []);

		$result = $runner->runAdhoc(
			register: $this->register(),
			schema: $this->schema(),
			query: $this->countByLevel(),
			bypassRbac: true
		);

		$this->assertSame(['high' => 2, 'low' => 3], $this->counts($result));
	}//end testAdhocBypassRbacSkipsTheRowPredicate()

	/**
	 * A materialised aggregate resolved with no user (occ, cron) counts every row.
	 *
	 * This is AggregateReferenceResolver's call: runAdhocByRef(..., bypassRbac: true)
	 * under the command line. With no user the row predicate is `1 = 0`, so on
	 * the live instance every aggregate under `occ openregister:rematerialise-calculations`
	 * resolved to 0 (live pass O14: an enrolment kept totalPublishedLessonCount 0
	 * for a course with three published lessons).
	 *
	 * @return void
	 */
	public function testAdhocByRefAsTheSystemWithoutAUserCountsEveryRow(): void {
		$runner = $this->runner(userId: null, groups: [], grants: [], schema: $this->schema());

		$result = $runner->runAdhocByRef(
			registerRef: 'learniq',
			schemaRef: 'engagement-score',
			query: $this->countByLevel(),
			bypassRbac: true
		);

		$this->assertSame(['high' => 2, 'low' => 3], $this->counts($result));
	}//end testAdhocByRefAsTheSystemWithoutAUserCountsEveryRow()

	/**
	 * A system figure never goes through the caller-scoped ad-hoc cache.
	 *
	 * The cache key holds the caller's uid and organisation, not bypassRbac:
	 * storing the system's count there would answer the same caller's own,
	 * narrower question with it, and reading there would hand the system a
	 * narrowed figure.
	 *
	 * @return void
	 */
	public function testAdhocBypassRbacNeitherReadsNorWritesTheCallerScopedCache(): void {
		$cache = $this->createMock(AggregationCache::class);
		$cache->expects($this->never())->method('getAdhoc');
		$cache->expects($this->never())->method('setAdhoc');
		$runner = $this->runner(userId: 'learner-a', groups: ['students'], grants: [], cache: $cache);

		$result = $runner->runAdhoc(
			register: $this->register(),
			schema: $this->schema(),
			query: $this->countByLevel(),
			bypassRbac: true
		);

		$this->assertSame(['high' => 2, 'low' => 3], $this->counts($result));
	}//end testAdhocBypassRbacNeitherReadsNorWritesTheCallerScopedCache()

	public function testTheOrganisationBoundaryFollowsTheListDecision(): void {
		$otherOrg = ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-elsewhere']];

		$waived = $this->runner(
			userId: 'teacher-1',
			groups: ['instructors'],
			grants: ['read'],
			orgScope: $otherOrg,
			boundaryWaived: true
		);
		$this->assertSame(
			['high' => 2, 'low' => 3],
			$this->counts($waived->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->countByLevel()))
		);

		$applied = $this->runner(
			userId: 'teacher-1',
			groups: ['instructors'],
			grants: ['read'],
			orgScope: $otherOrg,
			boundaryWaived: false
		);
		$this->assertSame(
			[],
			$this->counts($applied->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->countByLevel())),
			'where the list applies the boundary, so does the aggregate'
		);
	}//end testTheOrganisationBoundaryFollowsTheListDecision()

	/**
	 * Where the list keeps the organisation boundary and widens it by the
	 * org-less rows (a grant from the register cascade), the aggregate does the
	 * same: it counts the caller's own organisation and the org-less rows, and
	 * never another organisation's.
	 *
	 * @return void
	 */
	public function testARegisterCascadeGrantCountsOrganisationlessRowsButNotAnotherOrganisation(): void {
		$insert = $this->pdo->prepare(
			'INSERT INTO "oc_' . self::TABLE . '" (_uuid, _owner, _organisation, learner_id, level) VALUES (?, ?, ?, ?, ?)'
		);
		$insert->execute(['u6', 'system-importer', 'org-home', 'learner-a', 'low']);
		$insert->execute(['u7', 'system-importer', 'org-other', 'learner-a', 'high']);
		$homeOrg = ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-home']];

		$admitted = $this->runner(
			userId: 'teacher-1',
			groups: ['instructors'],
			grants: ['read'],
			orgScope: $homeOrg,
			organisationlessAdmitted: true
		);
		$this->assertSame(
			['high' => 2, 'low' => 4],
			$this->counts($admitted->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->countByLevel())),
			'the five org-less rows and the own-organisation row, not the other organisation\'s'
		);

		$strict = $this->runner(
			userId: 'teacher-1',
			groups: ['instructors'],
			grants: ['read'],
			orgScope: $homeOrg,
			organisationlessAdmitted: false
		);
		$this->assertSame(
			['low' => 1],
			$this->counts($strict->runAdhoc(register: $this->register(), schema: $this->schema(), query: $this->countByLevel())),
			'without the cascade grant only the own-organisation row counts'
		);
	}//end testARegisterCascadeGrantCountsOrganisationlessRowsButNotAnotherOrganisation()

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * Count grouped by level.
	 *
	 * @return AggregationQuery The query.
	 */
	private function countByLevel(): AggregationQuery {
		return AggregationQuery::create(metric: 'count', groupBy: ['field' => 'level']);
	}//end countByLevel()

	/**
	 * Group key => value, sorted by key.
	 *
	 * @param array<string, mixed> $result The envelope.
	 *
	 * @return array<string, mixed> The counts.
	 */
	private function counts(array $result): array {
		$this->assertArrayHasKey('groups', $result, 'expected a grouped envelope: '.json_encode($result));
		$counts = [];
		foreach ($result['groups'] as $group) {
			$counts[(string)$group['key']] = $group['value'];
		}

		ksort($counts);
		return $counts;
	}//end counts()

	/**
	 * The schema under test.
	 *
	 * @param array<string, mixed> $authorization Its authorization block.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $authorization = self::LEARNIQ_AUTHORIZATION): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('engagement-score');
		$schema->setAuthorization($authorization);
		$schema->setProperties(['learnerId' => ['type' => 'string'], 'level' => ['type' => 'string']]);

		return $schema;
	}//end schema()

	/**
	 * The register under test.
	 *
	 * @return Register The register.
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('learniq');
		$register->setSchemas([1]);

		return $register;
	}//end register()

	/**
	 * A runner whose caller is $userId in $groups, holding the schema-level
	 * verbs in $grants, over the real SQLite table and the real RBAC predicate.
	 *
	 * @param string|null   $userId The caller, or null for no user (occ, cron).
	 * @param array<string> $groups The caller's groups.
	 * @param array<string> $grants The schema-level verbs PermissionHandler grants.
	 * @param Schema|null   $schema The schema run() resolves, when testing run().
	 * @param array<string, mixed> $orgScope The organisation scope the handler reports.
	 * @param bool          $boundaryWaived Whether the list would waive the organisation boundary.
	 * @param bool          $organisationlessAdmitted Whether the list widens the boundary by org-less rows.
	 * @param AggregationCache|null $cache The cache, when a test watches it.
	 *
	 * @return AggregationRunner The runner.
	 */
	private function runner(
		?string $userId,
		array $groups,
		array $grants,
		?Schema $schema = null,
		array $orgScope = ['mode' => MagicOrganizationHandler::SCOPE_ALL],
		bool $boundaryWaived = false,
		bool $organisationlessAdmitted = false,
		?AggregationCache $cache = null,
	): AggregationRunner {
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		// Only the `$userId` variable is in play; ConditionMatcher's own
		// resolution of it is covered by its own suite.
		$conditionMatcher = $this->createMock(ConditionMatcher::class);
		$conditionMatcher->method('resolveDynamicValue')->willReturnCallback(
			static fn (mixed $value): mixed => ($value === '$userId') ? $userId : $value
		);

		$rbac = new MagicRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$conditionMatcher,
			$this->createMock(ContainerInterface::class),
			new NullLogger(),
			new RbacResolvers(
				objectScopeResolver: null,
				objectGrantResolver: null,
				denyResolver: new DenyResolver(new DenyEntryMatcher())
			)
		);

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getTableNameForRegisterSchema')->willReturn(self::TABLE);
		$magicMapper->method('organisationBoundaryWaivedByRbac')->willReturn($boundaryWaived);
		$magicMapper->method('organisationlessRowsAdmittedByRbac')->willReturn($organisationlessAdmitted);
		$magicMapper->method('rbacRowPredicateSql')->willReturnCallback(
			static fn (Schema $s, string $action = 'read'): ?string => $rbac->buildRbacRowPredicateSql(schema: $s, action: $action)
		);
		$magicMapper->method('callerQualifiesForAction')->willReturnCallback(
			static fn (Schema $s, string $action = 'read'): bool => $rbac->callerQualifiesForAction(schema: $s, action: $action)
		);
		// The PHP fallback must not be what answers: an empty fallback would
		// turn a broken native query into a plausible "no rows".
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (): array {
				$this->fail('the native SQL path must answer; the fallback was reached');
			}
		);

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action): bool => in_array($action, $grants, true)
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(SqlitePlatform::class));
		$db->method('prepare')->willReturnCallback(fn (string $sql): IPreparedStatement => $this->statement(sql: $sql));

		if ($cache === null) {
			$cache = $this->createMock(AggregationCache::class);
			$cache->method('get')->willReturn(null);
			$cache->method('getAdhoc')->willReturn(null);
		}

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getActiveOrganisation')->willReturn(null);

		$orgHandler = $this->createMock(MagicOrganizationHandler::class);
		$orgHandler->method('resolveOrganizationScope')->willReturn($orgScope);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		if ($schema !== null) {
			$registerMapper->method('find')->willReturn($this->register());
			$schemaMapper->method('find')->willReturn($schema);
			$schemaMapper->method('findInIds')->willReturn($schema);
		}

		$translationHandler = $this->createMock(TranslationHandler::class);

		return new AggregationRunner(
			magicMapper: $magicMapper,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			placeholders: new PlaceholderResolver($userSession),
			db: $db,
			cache: $cache,
			permissionHandler: $permissionHandler,
			userSession: $userSession,
			organisationService: $organisationService,
			organizationHandler: $orgHandler,
			translationHandler: $translationHandler,
			languageService: $this->createMock(LanguageService::class),
		);
	}//end runner()

	/**
	 * A prepared statement backed by the real SQLite connection.
	 *
	 * @param string $sql The SQL the runner prepared.
	 *
	 * @return IPreparedStatement The statement.
	 */
	private function statement(string $sql): IPreparedStatement {
		$pdoStatement = $this->pdo->prepare($sql);
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturnCallback(
			function (?array $params = null) use ($pdoStatement) {
				$pdoStatement->execute($params ?? []);
				return $this->createMock(\OCP\DB\IResult::class);
			}
		);
		$statement->method('fetch')->willReturnCallback(
			static fn () => $pdoStatement->fetch(PDO::FETCH_ASSOC)
		);

		return $statement;
	}//end statement()
}//end class
