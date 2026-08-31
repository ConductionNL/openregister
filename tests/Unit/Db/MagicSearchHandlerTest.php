<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for MagicSearchHandler comparison-operator filter support.
 *
 * Before this fix, array values with keys such as 'gte'/'lte' (produced by
 * PHP's bracket-notation URL parsing or buildSearchQuery's underscore
 * expansion) were silently turned into IN clauses, so
 *
 *   ?publicatiedatum[gte]=2025-12-31T23:59:59Z
 *   &publicatiedatum[lte]=2027-01-01T00:00:00Z
 *
 * generated  `publicatiedatum IN ('2025-12-31…', '2027-01-01…')`  instead of
 *            `publicatiedatum >= '2025-12-31…' AND publicatiedatum <= '2027-01-01…'`
 *
 * These tests cover buildObjectFilterConditionsSql() (the raw-SQL path).
 */
class MagicSearchHandlerTest extends TestCase {

	private IDBConnection&MockObject $db;

	private LoggerInterface&MockObject $logger;

	private MagicRbacHandler&MockObject $rbacHandler;

	private MagicOrganizationHandler&MockObject $organizationHandler;

	private MagicSearchHandler $handler;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rbacHandler = $this->createMock(MagicRbacHandler::class);
		$this->organizationHandler = $this->createMock(MagicOrganizationHandler::class);

		$this->handler = new MagicSearchHandler(
			db: $this->db,
			logger: $this->logger,
			rbacHandler: $this->rbacHandler,
			organizationHandler: $this->organizationHandler,
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($this->logger)
		);
	}//end setUp()

	/**
	 * Invoke the private buildObjectFilterConditionsSql() method via reflection.
	 *
	 * @param array $query Filters to apply (field => value).
	 * @param array $properties Schema properties array (field => ['type' => ...]).
	 * @param object $connection Mocked DB connection with a quote() method.
	 *
	 * @return string[] Generated SQL condition strings.
	 */
	private function invokeMethod(
		array $query,
		array $properties,
		object $connection,
		bool $isPostgres = true,
	): array {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql');
		$method->setAccessible(true);

		return $method->invoke($this->handler, $query, $schema, $connection, $isPostgres);
	}//end invokeMethod()

	/**
	 * Build a connection mock whose quote() method wraps values in single quotes.
	 */
	private function makeConnection(): object {
		$conn = $this->createMock(IDBConnection::class);
		$conn->method('quote')->willReturnCallback(fn ($v) => "'{$v}'");
		return $conn;
	}//end makeConnection()

	// -------------------------------------------------------------------------
	// [gte] / [lte] — the original bug report
	// -------------------------------------------------------------------------
	public function testGteProducesGreaterThanOrEqualCondition(): void {
		$conditions = $this->invokeMethod(
			query: ['publicatiedatum' => ['gte' => '2025-12-31T23:59:59Z']],
			properties: ['publicatiedatum' => ['type' => 'string']],
			connection: $this->makeConnection()
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("\"publicatiedatum\" >= '2025-12-31T23:59:59Z'", $conditions[0]);
	}//end testGteProducesGreaterThanOrEqualCondition()

	public function testLteProducesLessThanOrEqualCondition(): void {
		$conditions = $this->invokeMethod(
			query: ['publicatiedatum' => ['lte' => '2027-01-01T00:00:00Z']],
			properties: ['publicatiedatum' => ['type' => 'string']],
			connection: $this->makeConnection()
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("\"publicatiedatum\" <= '2027-01-01T00:00:00Z'", $conditions[0]);
	}//end testLteProducesLessThanOrEqualCondition()

	public function testGteAndLteTogetherProduceTwoRangeConditions(): void {
		$conditions = $this->invokeMethod(
			query: ['publicatiedatum' => ['gte' => '2025-12-31T23:59:59Z', 'lte' => '2027-01-01T00:00:00Z']],
			properties: ['publicatiedatum' => ['type' => 'string']],
			connection: $this->makeConnection()
		);

		$this->assertCount(2, $conditions);
		$this->assertSame("\"publicatiedatum\" >= '2025-12-31T23:59:59Z'", $conditions[0]);
		$this->assertSame("\"publicatiedatum\" <= '2027-01-01T00:00:00Z'", $conditions[1]);
	}//end testGteAndLteTogetherProduceTwoRangeConditions()

	// -------------------------------------------------------------------------
	// [gt] / [lt]
	// -------------------------------------------------------------------------
	public function testGtProducesStrictGreaterThanCondition(): void {
		$conditions = $this->invokeMethod(
			query: ['bedrag' => ['gt' => '100']],
			properties: ['bedrag' => ['type' => 'string']],
			connection: $this->makeConnection()
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("\"bedrag\" > '100'", $conditions[0]);
	}//end testGtProducesStrictGreaterThanCondition()

	public function testLtProducesStrictLessThanCondition(): void {
		$conditions = $this->invokeMethod(
			query: ['bedrag' => ['lt' => '500']],
			properties: ['bedrag' => ['type' => 'string']],
			connection: $this->makeConnection()
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("\"bedrag\" < '500'", $conditions[0]);
	}//end testLtProducesStrictLessThanCondition()

	// -------------------------------------------------------------------------
	// [in] as an operator key
	// -------------------------------------------------------------------------
	public function testInOperatorKeyProducesInClause(): void {
		$conditions = $this->invokeMethod(
			query: ['status' => ['in' => ['open', 'pending']]],
			properties: ['status' => ['type' => 'string']],
			connection: $this->makeConnection()
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("\"status\" IN ('open', 'pending')", $conditions[0]);
	}//end testInOperatorKeyProducesInClause()

	public function testInOperatorKeyWithSingleStringValueProducesInClause(): void {
		$conditions = $this->invokeMethod(
			query: ['status' => ['in' => 'open']],
			properties: ['status' => ['type' => 'string']],
			connection: $this->makeConnection()
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("\"status\" IN ('open')", $conditions[0]);
	}//end testInOperatorKeyWithSingleStringValueProducesInClause()

	// -------------------------------------------------------------------------
	// Plain array values (backward compatibility — must still produce IN clause)
	// -------------------------------------------------------------------------
	public function testPlainArrayValueStillProducesInClause(): void {
		$conditions = $this->invokeMethod(
			query: ['status' => ['open', 'closed']],
			properties: ['status' => ['type' => 'string']],
			connection: $this->makeConnection()
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("\"status\" IN ('open', 'closed')", $conditions[0]);
	}//end testPlainArrayValueStillProducesInClause()

	// -------------------------------------------------------------------------
	// Simple scalar equality (unchanged behaviour)
	// -------------------------------------------------------------------------
	public function testScalarValueProducesEqualityCondition(): void {
		$conditions = $this->invokeMethod(
			query: ['status' => 'open'],
			properties: ['status' => ['type' => 'string']],
			connection: $this->makeConnection()
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("\"status\" = 'open'", $conditions[0]);
	}//end testScalarValueProducesEqualityCondition()

	// -------------------------------------------------------------------------
	// Reserved-word property names must be quoted in filter conditions —
	// regression guard for the bug reported in #1956 part (b): a schema
	// property named 'status'/'case'/'order'/'group' produced a SQL syntax
	// error because the column name was interpolated raw.
	// -------------------------------------------------------------------------
	public function testReservedWordPropertyIsQuotedOnPostgresForEqualityFilter(): void {
		$conditions = $this->invokeMethod(
			query: ['case' => 'open'],
			properties: ['case' => ['type' => 'string']],
			connection: $this->makeConnection(),
			isPostgres: true
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("\"case\" = 'open'", $conditions[0]);
	}//end testReservedWordPropertyIsQuotedOnPostgresForEqualityFilter()

	public function testReservedWordPropertyIsQuotedOnMySqlForEqualityFilter(): void {
		$conditions = $this->invokeMethod(
			query: ['case' => 'open'],
			properties: ['case' => ['type' => 'string']],
			connection: $this->makeConnection(),
			isPostgres: false
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("`case` = 'open'", $conditions[0]);
	}//end testReservedWordPropertyIsQuotedOnMySqlForEqualityFilter()

	public function testReservedWordPropertyIsQuotedForRangeFilter(): void {
		$conditions = $this->invokeMethod(
			query: ['order' => ['gte' => '5', 'lte' => '10']],
			properties: ['order' => ['type' => 'string']],
			connection: $this->makeConnection(),
			isPostgres: true
		);

		$this->assertCount(2, $conditions);
		$this->assertSame("\"order\" >= '5'", $conditions[0]);
		$this->assertSame("\"order\" <= '10'", $conditions[1]);
	}//end testReservedWordPropertyIsQuotedForRangeFilter()

	public function testReservedWordPropertyIsQuotedForInFilter(): void {
		$conditions = $this->invokeMethod(
			query: ['group' => ['admin', 'user']],
			properties: ['group' => ['type' => 'string']],
			connection: $this->makeConnection(),
			isPostgres: true
		);

		$this->assertCount(1, $conditions);
		$this->assertSame("\"group\" IN ('admin', 'user')", $conditions[0]);
	}//end testReservedWordPropertyIsQuotedForInFilter()

	public function testReservedWordArrayPropertyIsQuotedForJsonbContainment(): void {
		$conditions = $this->invokeMethod(
			query: ['key' => 'foo'],
			properties: ['key' => ['type' => 'array']],
			connection: $this->makeConnection(),
			isPostgres: true
		);

		$this->assertCount(1, $conditions);
		// Array properties use a JSONB containment template; the column identifier
		// must appear quoted so the reserved word doesn't break the COALESCE expression.
		$this->assertStringContainsString('"key"', $conditions[0]);
		$this->assertStringNotContainsString('COALESCE(key,', $conditions[0]);
	}//end testReservedWordArrayPropertyIsQuotedForJsonbContainment()

	// -------------------------------------------------------------------------
	// Unknown property must still produce the 1=0 guard condition
	// -------------------------------------------------------------------------
	public function testUnknownPropertyProducesImpossibleCondition(): void {
		$conditions = $this->invokeMethod(
			query: ['nonexistent' => 'value'],
			properties: ['status' => ['type' => 'string']],
			connection: $this->makeConnection()
		);

		$this->assertCount(1, $conditions);
		$this->assertSame('1=0', $conditions[0]);
	}//end testUnknownPropertyProducesImpossibleCondition()

	// -------------------------------------------------------------------------
	// buildSearchConditionSql: reserved-word property names must be quoted so
	// PostgreSQL doesn't choke on `case::text ILIKE …` and MySQL doesn't choke
	// on `` `case` LIKE … ``. Regression guard for the Newman failure that
	// motivated PR #1437.
	// -------------------------------------------------------------------------

	/**
	 * Invoke the private buildSearchConditionSql() method via reflection.
	 *
	 * @param string $search Free-text search term.
	 * @param array $properties Schema properties (field => ['type' => ...]).
	 * @param bool $isPostgres Whether to render the PostgreSQL or MySQL flavour.
	 * @param array $query Optional query dict (e.g. `['_fuzzy' => true]`) — the
	 *                     production method reads `_fuzzy` from this dict, so
	 *                     tests that exercise the fuzzy branch pass it here
	 *                     instead of re-inlining the reflection call.
	 *
	 * @return string|null Generated SQL condition string (or null when empty).
	 */
	private function invokeBuildSearchConditionSql(
		string $search,
		array $properties,
		bool $isPostgres,
		array $query = [],
	): ?string {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildSearchConditionSql');
		$method->setAccessible(true);

		return $method->invoke(
			$this->handler,
			$search,
			$schema,
			$query,
			$this->makeConnection(),
			$isPostgres,
			null
		);
	}//end invokeBuildSearchConditionSql()

	public function testBuildSearchConditionSqlQuotesReservedWordOnPostgres(): void {
		$sql = $this->invokeBuildSearchConditionSql(
			search: 'foo',
			properties: ['case' => ['type' => 'string']],
			isPostgres: true
		);

		$this->assertNotNull($sql);
		$this->assertStringContainsString('"case"::text ILIKE', $sql);
		// Unquoted form must not appear — `"case"::text` has a quote between
		// `case` and `::text`, so the bare `case::text` substring should be absent.
		$this->assertStringNotContainsString('case::text', $sql);
	}//end testBuildSearchConditionSqlQuotesReservedWordOnPostgres()

	public function testBuildSearchConditionSqlQuotesReservedWordOnMySql(): void {
		$sql = $this->invokeBuildSearchConditionSql(
			search: 'foo',
			properties: ['case' => ['type' => 'string']],
			isPostgres: false
		);

		$this->assertNotNull($sql);
		$this->assertStringContainsString('LOWER(CAST(`case` AS CHAR)) LIKE LOWER(', $sql);
	}//end testBuildSearchConditionSqlQuotesReservedWordOnMySql()

	public function testBuildSearchConditionSqlQuotesEveryStringPropertyOnPostgres(): void {
		$sql = $this->invokeBuildSearchConditionSql(
			search: 'foo',
			properties: [
				'case' => ['type' => 'string'],
				'status' => ['type' => 'string'],
				'numeric' => ['type' => 'integer'],
			],
			isPostgres: true
		);

		$this->assertNotNull($sql);
		$this->assertStringContainsString('"case"::text ILIKE', $sql);
		$this->assertStringContainsString('"status"::text ILIKE', $sql);
		// Non-string properties must not appear in the LIKE chain.
		$this->assertStringNotContainsString('"numeric"', $sql);
	}//end testBuildSearchConditionSqlQuotesEveryStringPropertyOnPostgres()

	// -------------------------------------------------------------------------
	// WOO-544 regression guards: buildSearchConditionSql() must never emit
	// PostgreSQL-specific syntax (`::text ILIKE`, `similarity()`) on the MariaDB
	// / MySQL flavour, or the entire arm of a multi-schema search silently
	// swallows a syntax error and returns an empty resultset.
	// -------------------------------------------------------------------------

	public function testBuildSearchConditionSqlNeverEmitsPgTextCastOnMySql(): void {
		$sql = $this->invokeBuildSearchConditionSql(
			search: 'foo',
			properties: [
				'title' => ['type' => 'string'],
				'description' => ['type' => 'string'],
			],
			isPostgres: false
		);

		$this->assertNotNull($sql);
		// Neither the schema-property casts nor the metadata-field casts may use
		// PostgreSQL's `::text` syntax on the MariaDB path — that is the exact
		// silent-empty-resultset defect WOO-544 was filed to catch.
		$this->assertStringNotContainsString('::text', $sql);
		$this->assertStringNotContainsString('ILIKE', $sql);
	}//end testBuildSearchConditionSqlNeverEmitsPgTextCastOnMySql()

	public function testBuildSearchConditionSqlEmitsLowerCastForMetadataOnMySql(): void {
		$sql = $this->invokeBuildSearchConditionSql(
			search: 'foo',
			properties: [],
			isPostgres: false
		);

		$this->assertNotNull($sql);
		// MariaDB path wraps each metadata column and the pattern in LOWER()
		// so it stays case-insensitive regardless of collation.
		$this->assertStringContainsString('LOWER(CAST(_name AS CHAR)) LIKE LOWER(', $sql);
		$this->assertStringContainsString('LOWER(CAST(_description AS CHAR)) LIKE LOWER(', $sql);
		$this->assertStringContainsString('LOWER(CAST(_summary AS CHAR)) LIKE LOWER(', $sql);
	}//end testBuildSearchConditionSqlEmitsLowerCastForMetadataOnMySql()

	public function testBuildSearchConditionSqlEmitsPgTextCastForMetadataOnPostgres(): void {
		$sql = $this->invokeBuildSearchConditionSql(
			search: 'foo',
			properties: [],
			isPostgres: true
		);

		$this->assertNotNull($sql);
		// PostgreSQL path keeps `_name::text ILIKE` because ILIKE is
		// case-insensitive natively; the platform-branch must not regress.
		$this->assertStringContainsString('_name::text ILIKE', $sql);
		$this->assertStringContainsString('_description::text ILIKE', $sql);
		$this->assertStringContainsString('_summary::text ILIKE', $sql);
	}//end testBuildSearchConditionSqlEmitsPgTextCastForMetadataOnPostgres()

	public function testBuildSearchConditionSqlWithFuzzyOnMySqlDoesNotEmitSimilarity(): void {
		// The `_fuzzy=true` param is honoured only when pg_trgm is available.
		// hasPgTrgmExtension() short-circuits to false on non-PostgreSQL, so
		// even a client-supplied _fuzzy MUST NOT emit similarity() on MariaDB.
		// We prime the cached hasPgTrgm=false directly so the test doesn't need
		// a full IDBConnection platform mock — the platform-branch semantics in
		// buildSearchConditionSql are the SUT here, not the pg_trgm probe.
		$hasPgTrgmProp = new \ReflectionProperty(MagicSearchHandler::class, 'hasPgTrgm');
		$hasPgTrgmProp->setAccessible(true);
		$hasPgTrgmProp->setValue($this->handler, false);

		$sql = $this->invokeBuildSearchConditionSql(
			search: 'foo',
			properties: ['title' => ['type' => 'string']],
			isPostgres: false,
			query: ['_fuzzy' => true]
		);

		$this->assertNotNull($sql);
		$this->assertStringNotContainsString('similarity(', $sql);
		$this->assertStringNotContainsString('::text', $sql);
	}//end testBuildSearchConditionSqlWithFuzzyOnMySqlDoesNotEmitSimilarity()

	// -------------------------------------------------------------------------
	// Regression: `format: date-time` must round-trip as ISO-8601.
	//
	// A date-time property lives in a DATETIME column, so the driver hands it
	// back as 'Y-m-d H:i:s'. That string fails the schema's own `date-time`
	// format when the object is written straight back — and a UI edit is exactly
	// a read-modify-write, so every object carrying a populated date-time 400'd
	// ("Property 'occurredAt' should match format 'date-time'"). This is the read
	// path findAll() uses; MagicStatisticsHandler already normalised, this did not.
	// -------------------------------------------------------------------------

	/**
	 * Invoke the private convertRowToObjectEntity() via reflection.
	 *
	 * @param array<string,mixed> $row The raw DB row.
	 * @param array<string,mixed> $properties Schema properties.
	 *
	 * @return array<string,mixed> The hydrated object data.
	 */
	private function invokeConvertRow(array $row, array $properties): array {
		$register = $this->createMock(Register::class);
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'convertRowToObjectEntity');
		$method->setAccessible(true);
		$entity = $method->invoke($this->handler, $row, $register, $schema, '');

		return ($entity?->getObject() ?? []);
	}//end invokeConvertRow()

	public function testDateTimePropertyIsReadBackAsIso8601(): void {
		$objectData = $this->invokeConvertRow(
			row: [
				'_uuid' => 'f5c7b75c-8a72-4d15-9fb2-d91762c871e7',
				'occurred_at' => '2026-05-26 09:15:00',
			],
			properties: ['occurredAt' => ['type' => 'string', 'format' => 'date-time']]
		);

		// ISO-8601 (has the `T` separator) — not the raw 'Y-m-d H:i:s' column value,
		// which the schema's own date-time validator rejects on the way back in.
		$this->assertArrayHasKey('occurredAt', $objectData);
		$this->assertStringContainsString('T', (string)$objectData['occurredAt']);
		$this->assertStringStartsWith('2026-05-26T09:15:00', (string)$objectData['occurredAt']);
	}//end testDateTimePropertyIsReadBackAsIso8601()

	public function testDatePropertyIsReadBackAsPlainDate(): void {
		$objectData = $this->invokeConvertRow(
			row: [
				'_uuid' => 'f5c7b75c-8a72-4d15-9fb2-d91762c871e7',
				'expected_close_date' => '2026-07-15 00:00:00',
			],
			properties: ['expectedCloseDate' => ['type' => 'string', 'format' => 'date']]
		);

		$this->assertSame('2026-07-15', $objectData['expectedCloseDate']);
	}//end testDatePropertyIsReadBackAsPlainDate()

	public function testUnformattedStringPropertyIsLeftAlone(): void {
		$objectData = $this->invokeConvertRow(
			row: [
				'_uuid' => 'f5c7b75c-8a72-4d15-9fb2-d91762c871e7',
				'title' => 'Inlogproblemen na update',
			],
			properties: ['title' => ['type' => 'string']]
		);

		$this->assertSame('Inlogproblemen na update', $objectData['title']);
	}//end testUnformattedStringPropertyIsLeftAlone()
}//end class
