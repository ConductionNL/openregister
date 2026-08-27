<?php

/**
 * Unit tests for the MySQL / SQLite native time-bucket paths in
 * `AggregationRunner::tryNativeAggregation()`.
 *
 * Verifies the runner detects each platform, emits the matching native
 * bucketing expression (`DATE_FORMAT` / `strftime`), uses the right
 * identifier quoting (backticks vs double-quotes), and reports the
 * platform short name in the response `backend` field.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * No `@covers` metadata, deliberately — `beStrictAboutCoverageMetadata="true"`
 * discards the coverage of any test that touches a collaborator it did not
 * name. See {@see AggregationJoinAndCompositeGroupByTest} and #2847.
 */
class AggregationRunnerNativeBucketTest extends TestCase {

	private MagicMapper&MockObject $magicMapper;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private PlaceholderResolver $placeholderResolver;

	private IDBConnection&MockObject $db;

	private AggregationCache&MockObject $cache;

	private PermissionHandler&MockObject $permissionHandler;

	private IUserSession&MockObject $userSession;

	private OrganisationService&MockObject $organisationService;

	protected function setUp(): void {
		parent::setUp();

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->cache = $this->createMock(AggregationCache::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->placeholderResolver = new PlaceholderResolver($this->userSession);

		$this->userSession->method('getUser')->willReturn(null);
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->cache->method('getAdhoc')->willReturn(null);
		$this->cache->method('setAdhoc');
		$this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_calllogs');

	}//end setUp()

	public function testMysqlPlatformEmitsDateFormatWithBackticks(): void {
		$this->wirePlatform(platform: $this->createMock(MySQLPlatform::class));
		$captured = $this->captureSql();

		$runner = $this->makeRunner();
		$result = $runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: $this->dayBucketQuery()
		);

		$this->assertNotNull($captured['sql'], 'runAdhoc MUST prepare a SQL statement on MySQL');
		$this->assertStringContainsString(
			"DATE_FORMAT(`created`, '%Y-%m-%dT00:00:00Z')",
			$captured['sql'],
			'MySQL path MUST emit DATE_FORMAT with the day-gap format string'
		);
		$this->assertStringContainsString('`oc_register_1_schema_calllogs`', $captured['sql'], 'MySQL path MUST use backticks for the table');
		$this->assertStringContainsString('`_organisation`', $captured['sql'], 'MySQL path MUST use backticks for the org column');
		$this->assertSame('mysql', $result['backend'], 'response backend MUST be "mysql"');

	}//end testMysqlPlatformEmitsDateFormatWithBackticks()

	public function testMysqlHourBucketEmitsHourFormat(): void {
		$this->wirePlatform(platform: $this->createMock(MySQLPlatform::class));
		$captured = $this->captureSql();

		$runner = $this->makeRunner();
		$runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				dateBucket: ['field' => 'created', 'start' => '2026-05-21T00:00:00Z', 'end' => '2026-05-22T00:00:00Z', 'gap' => 'hour']
			)
		);

		$this->assertStringContainsString(
			"DATE_FORMAT(`created`, '%Y-%m-%dT%H:00:00Z')",
			$captured['sql'],
			'MySQL HOUR gap MUST use %Y-%m-%dT%H:00:00Z format'
		);

	}//end testMysqlHourBucketEmitsHourFormat()

	public function testMysqlMinuteBucketUsesPercentI(): void {
		// MySQL minute placeholder is %i, NOT %M (which is full month name).
		// This is the most common transcription error porting strftime to
		// DATE_FORMAT — pin it with a test.
		$this->wirePlatform(platform: $this->createMock(MySQLPlatform::class));
		$captured = $this->captureSql();

		$runner = $this->makeRunner();
		$runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				dateBucket: ['field' => 'created', 'start' => '2026-05-21T00:00:00Z', 'end' => '2026-05-21T01:00:00Z', 'gap' => 'minute']
			)
		);

		$this->assertStringContainsString("'%Y-%m-%dT%H:%i:00Z'", $captured['sql']);
		$this->assertStringNotContainsString("'%Y-%m-%dT%H:%M:00Z'", $captured['sql'], 'MySQL must NOT emit %M for minutes — that is full month name');

	}//end testMysqlMinuteBucketUsesPercentI()

	public function testSqlitePlatformEmitsStrftimeWithDoubleQuotes(): void {
		$this->wirePlatform(platform: $this->createMock(SqlitePlatform::class));
		$captured = $this->captureSql();

		$runner = $this->makeRunner();
		$result = $runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: $this->dayBucketQuery()
		);

		$this->assertStringContainsString(
			"strftime('%Y-%m-%dT00:00:00Z', \"created\")",
			$captured['sql'],
			'SQLite path MUST emit strftime with the day-gap format string'
		);
		$this->assertStringContainsString('"oc_register_1_schema_calllogs"', $captured['sql'], 'SQLite path MUST use double-quotes for the table');
		$this->assertSame('sqlite', $result['backend'], 'response backend MUST be "sqlite"');

	}//end testSqlitePlatformEmitsStrftimeWithDoubleQuotes()

	public function testSqliteMinuteBucketUsesPercentM(): void {
		// Counter-test to testMysqlMinuteBucketUsesPercentI() — SQLite
		// strftime minute IS %M (unlike MySQL DATE_FORMAT).
		$this->wirePlatform(platform: $this->createMock(SqlitePlatform::class));
		$captured = $this->captureSql();

		$runner = $this->makeRunner();
		$runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				dateBucket: ['field' => 'created', 'start' => '2026-05-21T00:00:00Z', 'end' => '2026-05-21T01:00:00Z', 'gap' => 'minute']
			)
		);

		$this->assertStringContainsString("strftime('%Y-%m-%dT%H:%M:00Z'", $captured['sql'], 'SQLite MUST use %M for minutes');

	}//end testSqliteMinuteBucketUsesPercentM()

	public function testPostgresPlatformStillEmitsDateTrunc(): void {
		// Regression: the Postgres path emitted by #1611 stays unchanged.
		$this->wirePlatform(platform: $this->createMock(PostgreSQLPlatform::class));
		$captured = $this->captureSql();

		$runner = $this->makeRunner();
		$result = $runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: $this->dayBucketQuery()
		);

		$this->assertStringContainsString('date_trunc(?, "created")::text', $captured['sql'], 'Postgres path stays on date_trunc');
		$this->assertSame('postgres', $result['backend']);

	}//end testPostgresPlatformStillEmitsDateTrunc()

	public function testUnknownPlatformFallsBackToPhp(): void {
		$this->wirePlatform(platform: $this->createMock(AbstractPlatform::class));
		// Db prepare MUST NOT be called — the runner detects the unknown
		// platform and skips straight to bucketInPhp().
		$this->db->expects($this->never())->method('prepare');

		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturn([]);

		$runner = $this->makeRunner();
		$result = $runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: $this->dayBucketQuery()
		);

		$this->assertSame('php-fallback', $result['backend'], 'unknown platform MUST fall through to PHP fallback');

	}//end testUnknownPlatformFallsBackToPhp()

	public function testMysqlBucketBindsStartAndEndBounds(): void {
		$this->wirePlatform(platform: $this->createMock(MySQLPlatform::class));
		$captured = $this->captureSql();

		$runner = $this->makeRunner();
		$runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				dateBucket: ['field' => 'created', 'start' => '2026-05-01T00:00:00Z', 'end' => '2026-05-22T00:00:00Z', 'gap' => 'day']
			)
		);

		// MySQL/SQLite do NOT prepend the gap to the binding list (the
		// format string is literal SQL on those engines), so the bound
		// values are just `__no_active_org__`, start, end.
		$this->assertContains('2026-05-01T00:00:00Z', $captured['bindings'] ?? [], 'start bound MUST be bound');
		$this->assertContains('2026-05-22T00:00:00Z', $captured['bindings'] ?? [], 'end bound MUST be bound');
		$this->assertNotContains('day', $captured['bindings'] ?? [], 'MySQL gap MUST be literal SQL, not a bound parameter');

	}//end testMysqlBucketBindsStartAndEndBounds()

	public function testNotInFilterEmitsNotInSqlAndBindsOperands(): void {
		// Postgres handles every query shape natively, including the
		// ungrouped count with a notIn filter. The native path MUST emit
		// a `NOT IN (?, ?)` predicate and bind both exclusion operands.
		$this->wirePlatform(platform: $this->createMock(PostgreSQLPlatform::class));
		$captured = $this->captureSql();

		$runner = $this->makeRunner();
		$runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				filter: ['status' => ['notIn' => ['archived', 'deleted']]]
			)
		);

		$this->assertNotNull($captured['sql'], 'runAdhoc MUST prepare a SQL statement');
		$this->assertStringContainsString(
			'"status" NOT IN (?, ?)',
			$captured['sql'],
			'notIn filter MUST emit a NOT IN predicate with one placeholder per operand'
		);
		$this->assertContains('archived', $captured['bindings'] ?? [], 'first notIn operand MUST be bound');
		$this->assertContains('deleted', $captured['bindings'] ?? [], 'second notIn operand MUST be bound');

	}//end testNotInFilterEmitsNotInSqlAndBindsOperands()

	public function testEmptyNotInListRetainsAllRows(): void {
		// `notIn []` excludes nothing, so the runner MUST emit an
		// always-true predicate (1 = 1) rather than a malformed
		// `NOT IN ()` clause that errors on some engines.
		$this->wirePlatform(platform: $this->createMock(PostgreSQLPlatform::class));
		$captured = $this->captureSql();

		$runner = $this->makeRunner();
		$runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				filter: ['status' => ['notIn' => []]]
			)
		);

		$this->assertNotNull($captured['sql'], 'runAdhoc MUST prepare a SQL statement');
		$this->assertStringContainsString('1 = 1', $captured['sql'], 'empty notIn MUST emit an always-true predicate');
		$this->assertStringNotContainsString('NOT IN', $captured['sql'], 'empty notIn MUST NOT emit a NOT IN () clause');

	}//end testEmptyNotInListRetainsAllRows()

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * Capture the SQL passed to `db->prepare()` and the bindings passed
	 * to `stmt->execute()`. Statement returns no rows so the runner emits
	 * empty groups.
	 *
	 * Uses an ArrayObject so the closures' writes are visible to the
	 * calling test (a plain PHP array returned from this helper would be
	 * copied on assignment, hiding the closure mutations).
	 *
	 * @return \ArrayObject<string, mixed> Mutable container with `sql` and `bindings` keys.
	 */
	private function captureSql(): \ArrayObject {
		$captured = new \ArrayObject(['sql' => null, 'bindings' => null]);
		$stmt = $this->createMock(IPreparedStatement::class);
		$result = $this->createMock(IResult::class);
		$stmt->method('execute')->willReturnCallback(
			function (array $bindings = []) use ($captured, $result) {
				$captured['bindings'] = $bindings;
				return $result;
			}
		);
		$stmt->method('fetch')->willReturn(false);

		$this->db->method('prepare')->willReturnCallback(
			function (string $sql) use ($stmt, $captured) {
				$captured['sql'] = $sql;
				return $stmt;
			}
		);

		return $captured;
	}//end captureSql()

	/**
	 * Wire the db connection mock to return the given platform instance.
	 *
	 * @param AbstractPlatform $platform Platform double.
	 *
	 * @return void
	 */
	private function wirePlatform(AbstractPlatform $platform): void {
		$this->db->method('getDatabasePlatform')->willReturn($platform);

	}//end wirePlatform()

	private function dayBucketQuery(): AggregationQuery {
		return AggregationQuery::create(
			metric: 'count',
			dateBucket: [
				'field' => 'created',
				'start' => '2026-05-01T00:00:00Z',
				'end' => '2026-05-22T00:00:00Z',
				'gap' => 'day',
			]
		);

	}//end dayBucketQuery()

	private function makeSchema(): Schema {
		$schema = new Schema();
		$schema->setSlug('calllogs');
		$schema->setId(1);
		return $schema;
	}//end makeSchema()

	private function makeRegister(): Register {
		$register = new Register();
		$register->setSlug('openconnector');
		$register->setSchemas([1]);
		return $register;
	}//end makeRegister()

	private function makeRunner(): AggregationRunner {
		return new AggregationRunner(
			magicMapper: $this->magicMapper,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			placeholders: $this->placeholderResolver,
			db: $this->db,
			cache: $this->cache,
			permissionHandler: $this->permissionHandler,
			userSession: $this->userSession,
			organisationService: $this->organisationService,
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class),
		);

	}//end makeRunner()
}//end class
