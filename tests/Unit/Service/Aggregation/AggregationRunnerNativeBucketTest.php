<?php

namespace Unit\Service\Aggregation;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// ---- Platform stubs (named so ::class returns a predictable string) ----------

/**
 * Stub platform whose class name contains 'PostgreSQL'.
 *
 * @phpcsSuppress CustomSn.Naming.ClassNaming
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class PostgreSQLPlatformStub extends AbstractPlatform
{
    public function getBooleanTypeDeclarationSQL(array $column): string { return 'BOOLEAN'; }
    public function getIntegerTypeDeclarationSQL(array $column): string { return 'INTEGER'; }
    public function getBigIntTypeDeclarationSQL(array $column): string { return 'BIGINT'; }
    public function getSmallIntTypeDeclarationSQL(array $column): string { return 'SMALLINT'; }
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string { return ''; }
    protected function initializeDoctrineTypeMappings(): void {}
    public function getClobTypeDeclarationSQL(array $column): string { return 'TEXT'; }
    public function getBlobTypeDeclarationSQL(array $column): string { return 'BYTEA'; }
    public function getName(): string { return 'postgresql'; }
    public function getCurrentDatabaseExpression(): string { return 'current_schema()'; }
}

/**
 * Stub platform whose class name contains 'MySQL'.
 *
 * @phpcsSuppress CustomSn.Naming.ClassNaming
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class MySQLPlatformStub extends AbstractPlatform
{
    public function getBooleanTypeDeclarationSQL(array $column): string { return 'TINYINT(1)'; }
    public function getIntegerTypeDeclarationSQL(array $column): string { return 'INT'; }
    public function getBigIntTypeDeclarationSQL(array $column): string { return 'BIGINT'; }
    public function getSmallIntTypeDeclarationSQL(array $column): string { return 'SMALLINT'; }
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string { return ''; }
    protected function initializeDoctrineTypeMappings(): void {}
    public function getClobTypeDeclarationSQL(array $column): string { return 'LONGTEXT'; }
    public function getBlobTypeDeclarationSQL(array $column): string { return 'LONGBLOB'; }
    public function getName(): string { return 'mysql'; }
    public function getCurrentDatabaseExpression(): string { return 'DATABASE()'; }
}

/**
 * Stub platform whose class name contains 'SQLite'.
 *
 * @phpcsSuppress CustomSn.Naming.ClassNaming
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class SqlitePlatformStub extends AbstractPlatform
{
    public function getBooleanTypeDeclarationSQL(array $column): string { return 'INTEGER'; }
    public function getIntegerTypeDeclarationSQL(array $column): string { return 'INTEGER'; }
    public function getBigIntTypeDeclarationSQL(array $column): string { return 'INTEGER'; }
    public function getSmallIntTypeDeclarationSQL(array $column): string { return 'INTEGER'; }
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string { return ''; }
    protected function initializeDoctrineTypeMappings(): void {}
    public function getClobTypeDeclarationSQL(array $column): string { return 'CLOB'; }
    public function getBlobTypeDeclarationSQL(array $column): string { return 'BLOB'; }
    public function getName(): string { return 'sqlite'; }
    public function getCurrentDatabaseExpression(): string { return "strftime('%s', 'now')"; }
}

/**
 * Stub platform whose class name contains none of the known strings.
 *
 * @phpcsSuppress CustomSn.Naming.ClassNaming
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class CustomPlatformStub extends AbstractPlatform
{
    public function getBooleanTypeDeclarationSQL(array $column): string { return 'INTEGER'; }
    public function getIntegerTypeDeclarationSQL(array $column): string { return 'INTEGER'; }
    public function getBigIntTypeDeclarationSQL(array $column): string { return 'INTEGER'; }
    public function getSmallIntTypeDeclarationSQL(array $column): string { return 'INTEGER'; }
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string { return ''; }
    protected function initializeDoctrineTypeMappings(): void {}
    public function getClobTypeDeclarationSQL(array $column): string { return 'TEXT'; }
    public function getBlobTypeDeclarationSQL(array $column): string { return 'BLOB'; }
    public function getName(): string { return 'custom'; }
    public function getCurrentDatabaseExpression(): string { return 'NULL'; }
}


// ---- Test class --------------------------------------------------------------

/**
 * Unit tests for AggregationRunner native-bucket SQL emission.
 *
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class AggregationRunnerNativeBucketTest extends TestCase
{

    private IDBConnection&MockObject $db;
    private AggregationCache&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private AggregationRunner $sut;

    protected function setUp(): void
    {
        $this->db     = $this->createMock(IDBConnection::class);
        $this->cache  = $this->createMock(AggregationCache::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new AggregationRunner(
            db: $this->db,
            cache: $this->cache,
            logger: $this->logger
        );
    }

    // -------------------------------------------------------------------------
    // detectDatabasePlatform()
    // -------------------------------------------------------------------------

    /**
     * PostgreSQL platform class maps to 'postgres'.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testDetectPostgresPlatform(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatformStub());

        $this->assertSame('postgres', $this->sut->detectDatabasePlatform());
    }

    /**
     * MySQL platform class maps to 'mysql'.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testDetectMySQLPlatform(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new MySQLPlatformStub());

        $this->assertSame('mysql', $this->sut->detectDatabasePlatform());
    }

    /**
     * SQLite platform class maps to 'sqlite'.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testDetectSQLitePlatform(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new SqlitePlatformStub());

        $this->assertSame('sqlite', $this->sut->detectDatabasePlatform());
    }

    /**
     * Unknown platform maps to 'unknown'.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testDetectUnknownPlatform(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new CustomPlatformStub());

        $this->assertSame('unknown', $this->sut->detectDatabasePlatform());
    }

    // -------------------------------------------------------------------------
    // mysqlBucketExpression()
    // -------------------------------------------------------------------------

    /**
     * MySQL DAY gap emits DATE_FORMAT with backtick-quoted field and correct format string.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testMysqlBucketExpressionDay(): void
    {
        $expr = AggregationRunner::mysqlBucketExpression(field: '_created', gap: 'day');

        $this->assertStringContainsString('DATE_FORMAT', $expr);
        $this->assertStringContainsString('`_created`', $expr);
        $this->assertStringContainsString('%Y-%m-%dT00:00:00Z', $expr);
    }

    /**
     * MySQL MINUTE gap uses %i (not %M — full month name in MySQL DATE_FORMAT).
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testMysqlBucketExpressionMinuteUsesPercentI(): void
    {
        $expr = AggregationRunner::mysqlBucketExpression(field: '_created', gap: 'minute');

        $this->assertStringContainsString('%i', $expr, 'MySQL minute must use %i, not %M');
        $this->assertStringNotContainsString('%M', $expr, 'MySQL must not use %M (full month name)');
    }

    /**
     * MySQL WEEK gap emits DAYOFWEEK-based ISO-Monday expression.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testMysqlBucketExpressionWeek(): void
    {
        $expr = AggregationRunner::mysqlBucketExpression(field: '_created', gap: 'week');

        $this->assertStringContainsString('DAYOFWEEK', $expr);
        $this->assertStringContainsString('INTERVAL', $expr);
    }

    /**
     * MySQL QUARTER gap emits CONCAT + QUARTER arithmetic.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testMysqlBucketExpressionQuarter(): void
    {
        $expr = AggregationRunner::mysqlBucketExpression(field: '_created', gap: 'quarter');

        $this->assertStringContainsString('QUARTER', $expr);
        $this->assertStringContainsString('CONCAT', $expr);
    }

    // -------------------------------------------------------------------------
    // sqliteBucketExpression()
    // -------------------------------------------------------------------------

    /**
     * SQLite DAY gap emits strftime with double-quoted field and correct format string.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testSqliteBucketExpressionDay(): void
    {
        $expr = AggregationRunner::sqliteBucketExpression(field: '_created', gap: 'day');

        $this->assertStringContainsString('strftime', $expr);
        $this->assertStringContainsString('"_created"', $expr);
        $this->assertStringContainsString('%Y-%m-%dT00:00:00Z', $expr);
    }

    /**
     * SQLite MINUTE gap uses %M (not %i — MySQL minute placeholder).
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testSqliteBucketExpressionMinuteUsesPercentM(): void
    {
        $expr = AggregationRunner::sqliteBucketExpression(field: '_created', gap: 'minute');

        $this->assertStringContainsString('%M', $expr, 'SQLite minute must use %M, not %i');
        $this->assertStringNotContainsString('%i', $expr, 'SQLite must not use %i (MySQL minute placeholder)');
    }

    /**
     * SQLite WEEK gap emits weekday modifier for ISO-Monday.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testSqliteBucketExpressionWeek(): void
    {
        $expr = AggregationRunner::sqliteBucketExpression(field: '_created', gap: 'week');

        $this->assertStringContainsString('weekday', $expr);
    }

    /**
     * SQLite QUARTER gap emits a CASE expression.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testSqliteBucketExpressionQuarter(): void
    {
        $expr = AggregationRunner::sqliteBucketExpression(field: '_created', gap: 'quarter');

        $this->assertStringContainsString('CASE', $expr);
        $this->assertStringContainsString('strftime', $expr);
    }

    // -------------------------------------------------------------------------
    // tryNativeAggregation() backend annotation + unknown-platform fallthrough
    // -------------------------------------------------------------------------

    /**
     * tryNativeAggregation() returns null for unknown platform (caller falls through to PHP).
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testTryNativeAggregationReturnsNullForUnknownPlatform(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new CustomPlatformStub());

        $query = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: [],
            groupBy: null,
            dateBucket: 'day'
        );

        $result = $this->sut->tryNativeAggregation(schemaSlug: 'testschema', query: $query);

        $this->assertNull($result);
    }

    /**
     * tryNativeAggregation() annotates backend:'mysql' for MySQL platform.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testTryNativeAggregationAnnotatesMysqlBackend(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new MySQLPlatformStub());
        $this->db->method('prepare')->willReturn($this->buildPreparedStatementMock(rows: [['bucket' => '2026-01-01T00:00:00Z', 'agg' => 3]]));

        $query = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: [],
            groupBy: null,
            dateBucket: 'day'
        );

        $result = $this->sut->tryNativeAggregation(schemaSlug: 'testschema', query: $query);

        $this->assertNotNull($result);
        $this->assertSame('mysql', $result['backend']);
    }

    /**
     * tryNativeAggregation() annotates backend:'sqlite' for SQLite platform.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testTryNativeAggregationAnnotatesSqliteBackend(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new SqlitePlatformStub());
        $this->db->method('prepare')->willReturn($this->buildPreparedStatementMock(rows: []));

        $query = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: [],
            groupBy: null,
            dateBucket: 'day'
        );

        $result = $this->sut->tryNativeAggregation(schemaSlug: 'testschema', query: $query);

        $this->assertNotNull($result);
        $this->assertSame('sqlite', $result['backend']);
    }

    /**
     * tryNativeAggregation() annotates backend:'postgres' for PostgreSQL platform.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.3
     */
    public function testTryNativeAggregationAnnotatesPostgresBackend(): void
    {
        $this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatformStub());
        $this->db->method('prepare')->willReturn($this->buildPreparedStatementMock(rows: []));

        $query = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: [],
            groupBy: null,
            dateBucket: 'day'
        );

        $result = $this->sut->tryNativeAggregation(schemaSlug: 'testschema', query: $query);

        $this->assertNotNull($result);
        $this->assertSame('postgres', $result['backend']);
    }

    /**
     * Build a mock IPreparedStatement that returns the given rows on fetchAll().
     *
     * @param array<array<string,mixed>> $rows
     *
     * @return IPreparedStatement&MockObject
     */
    private function buildPreparedStatementMock(array $rows): IPreparedStatement
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn($rows);
        $result->method('closeCursor')->willReturn(true);

        $stmt = $this->createMock(IPreparedStatement::class);
        $stmt->method('execute')->willReturn($result);

        return $stmt;
    }

    // -------------------------------------------------------------------------
    // coerceBucketKey()
    // -------------------------------------------------------------------------

    /**
     * coerceBucketKey() normalises a Postgres-style timestamp to ISO-8601-UTC.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.5
     */
    public function testCoerceBucketKeyNormalisesPostgresFormat(): void
    {
        $result = $this->sut->coerceBucketKey(key: '2026-01-15 00:00:00+00');

        $this->assertSame('2026-01-15T00:00:00Z', $result);
    }

    /**
     * coerceBucketKey() is a no-op on already-canonical ISO-8601-UTC keys.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.5
     */
    public function testCoerceBucketKeyIsNoOpOnCanonicalFormat(): void
    {
        $key    = '2026-01-15T00:00:00Z';
        $result = $this->sut->coerceBucketKey(key: $key);

        $this->assertSame($key, $result);
    }
}//end class
