<?php

/**
 * Unit tests for the searchable-property-index change: the baseline `_name`
 * pg_trgm GIN index and the opt-in `searchable: true` per-property GIN index
 * created by MagicMapper::createTableIndexes() (and re-run by the retrofit path
 * MagicMapper::updateTableIndexes()).
 *
 * These tests exercise the index-creation SQL directly by capturing every
 * executeStatement() call against a mocked IDBConnection, so they run in the
 * bare php:8.3-cli CI environment (Doctrine platform + IDBConnection are mocked;
 * no live database is required).
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Locks the three behaviours introduced by searchable-property-index:
 *
 *   1. Every magic table gets an unconditional `pg_trgm` GIN index on its
 *      `_name` column when the platform is PostgreSQL AND pg_trgm is available;
 *      the index is skipped (no error) otherwise.
 *   2. A schema string property flagged `searchable: true` gets a `pg_trgm`
 *      GIN index on its column; a non-string property so flagged is tolerated
 *      (logged warning, table creation still completes).
 *   3. The retrofit entry point updateTableIndexes() re-runs the same
 *      index-creation routine, so both index kinds are created on an existing
 *      table when its schema changes.
 */
class MagicMapperSearchableIndexTest extends TestCase
{

    /**
     * Captured SQL statements passed to IDBConnection::executeStatement().
     *
     * @var array<int,string>
     */
    private array $capturedSql = [];

    /**
     * Captured log messages keyed by level (warning|debug|info).
     *
     * @var array<string,array<int,string>>
     */
    private array $capturedLogs = ['warning' => [], 'debug' => [], 'info' => []];

    /**
     * Reset capture buffers before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->capturedSql  = [];
        $this->capturedLogs = ['warning' => [], 'debug' => [], 'info' => []];
    }//end setUp()

    /**
     * Build a MagicMapper with only the fields createTableIndexes() touches.
     *
     * @param AbstractPlatform&MockObject $platform      Database platform mock.
     * @param int                         $pgTrgmCount   pg_extension COUNT(*) for pg_trgm.
     * @param string|null                 $throwOnNeedle Throw from executeStatement when the SQL contains this needle.
     *
     * @return MagicMapper Mapper with db, logger and config injected.
     */
    private function makeMapper(AbstractPlatform $platform, int $pgTrgmCount, ?string $throwOnNeedle=null): MagicMapper
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getDatabasePlatform')->willReturn($platform);

        // Capture (and optionally fail) every executeStatement call.
        $db->method('executeStatement')->willReturnCallback(
            function (string $sql) use ($throwOnNeedle): int {
                $this->capturedSql[] = $sql;
                if ($throwOnNeedle !== null && str_contains($sql, $throwOnNeedle) === true) {
                    throw new \OCP\DB\Exception('column type incompatible with gin_trgm_ops');
                }
                return 0;
            }
        );

        // hasPgTrgmExtension(): prepare -> execute -> fetchOne() returns count.
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn($pgTrgmCount);
        $stmt = $this->createMock(IPreparedStatement::class);
        $stmt->method('execute')->willReturn($result);
        $db->method('prepare')->willReturn($stmt);

        $logger = $this->createMock(LoggerInterface::class);
        foreach (['warning', 'debug', 'info'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message) use ($level): void {
                    $this->capturedLogs[$level][] = $message;
                }
            );
        }

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturn('oc_');

        $reflection = new ReflectionClass(MagicMapper::class);
        $mapper     = $reflection->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'logger' => $logger, 'config' => $config] as $name => $value) {
            $prop = $reflection->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($mapper, $value);
        }

        return $mapper;
    }//end makeMapper()

    /**
     * Build a Schema mock returning the given property map from getProperties().
     *
     * @param array<string,mixed> $properties Schema property definitions.
     *
     * @return Schema&MockObject Schema mock.
     */
    private function makeSchema(array $properties): Schema
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn($properties);
        return $schema;
    }//end makeSchema()

    /**
     * Invoke the private createTableIndexes() with the given schema properties.
     *
     * @param MagicMapper         $mapper     Mapper under test.
     * @param string              $tableName  Bare magic table name.
     * @param array<string,mixed> $properties Schema properties.
     *
     * @return void
     */
    private function runCreateIndexes(MagicMapper $mapper, string $tableName, array $properties): void
    {
        $method = new ReflectionMethod(MagicMapper::class, 'createTableIndexes');
        $method->setAccessible(true);
        $method->invokeArgs(
            $mapper,
            [$tableName, $this->createMock(Register::class), $this->makeSchema($properties)]
        );
    }//end runCreateIndexes()

    /**
     * Assert that at least one captured SQL statement contains the needle.
     *
     * @param string $needle Substring to look for.
     *
     * @return void
     */
    private function assertSqlContains(string $needle): void
    {
        foreach ($this->capturedSql as $sql) {
            if (str_contains($sql, $needle) === true) {
                $this->assertTrue(true);
                return;
            }
        }

        $this->fail("No executed SQL contained '$needle'. Captured: ".implode(' | ', $this->capturedSql));
    }//end assertSqlContains()

    /**
     * Assert that no captured SQL statement contains the needle.
     *
     * @param string $needle Substring that must be absent.
     *
     * @return void
     */
    private function assertNoSqlContains(string $needle): void
    {
        foreach ($this->capturedSql as $sql) {
            if (str_contains($sql, $needle) === true) {
                $this->fail("Unexpected SQL contained '$needle': $sql");
            }
        }

        $this->assertTrue(true);
    }//end assertNoSqlContains()

    // -------------------------------------------------------------------------
    // Baseline `_name` trgm index (task 5.1)
    // -------------------------------------------------------------------------

    /**
     * PostgreSQL + pg_trgm available -> a GIN trgm index on `_name` is created.
     *
     * @return void
     */
    public function testBaselineNameTrgmIndexCreatedOnPostgresWithPgTrgm(): void
    {
        $mapper = $this->makeMapper($this->createMock(PostgreSQLPlatform::class), pgTrgmCount: 1);
        $this->runCreateIndexes($mapper, 'openregister_table_1_1', []);

        $this->assertSqlContains('openregister_table_1_1_name_trgm_idx');
        $this->assertSqlContains('USING GIN (_name gin_trgm_ops)');
    }//end testBaselineNameTrgmIndexCreatedOnPostgresWithPgTrgm()

    /**
     * PostgreSQL WITHOUT pg_trgm -> no `_name` trgm index, no error.
     *
     * @return void
     */
    public function testBaselineNameTrgmIndexSkippedWithoutPgTrgm(): void
    {
        $mapper = $this->makeMapper($this->createMock(PostgreSQLPlatform::class), pgTrgmCount: 0);
        $this->runCreateIndexes($mapper, 'openregister_table_1_1', []);

        $this->assertNoSqlContains('gin_trgm_ops');
        // The regular Postgres GIN index on _relations must still be created.
        $this->assertSqlContains('USING GIN (_relations)');
    }//end testBaselineNameTrgmIndexSkippedWithoutPgTrgm()

    /**
     * MariaDB -> no `_name` trgm index (and no Postgres-only indexes), no error.
     *
     * @return void
     */
    public function testBaselineNameTrgmIndexSkippedOnMariaDb(): void
    {
        $mapper = $this->makeMapper($this->createMock(MariaDBPlatform::class), pgTrgmCount: 0);
        $this->runCreateIndexes($mapper, 'openregister_table_1_1', []);

        $this->assertNoSqlContains('gin_trgm_ops');
        $this->assertNoSqlContains('USING GIN');
        // But the platform-agnostic UUID index is still created.
        $this->assertSqlContains('openregister_table_1_1_uuid_idx');
    }//end testBaselineNameTrgmIndexSkippedOnMariaDb()

    // -------------------------------------------------------------------------
    // Opt-in `searchable: true` property flag (task 5.2)
    // -------------------------------------------------------------------------

    /**
     * A string property with `searchable: true` gets a pg_trgm GIN index.
     *
     * @return void
     */
    public function testSearchableStringPropertyCreatesTrgmIndex(): void
    {
        $mapper = $this->makeMapper($this->createMock(PostgreSQLPlatform::class), pgTrgmCount: 1);
        $this->runCreateIndexes(
            $mapper,
            'openregister_table_1_1',
            ['title' => ['type' => 'string', 'searchable' => true]]
        );

        $this->assertSqlContains('openregister_table_1_1_title_trgm_idx');
        $this->assertSqlContains('USING GIN ("title" gin_trgm_ops)');
    }//end testSearchableStringPropertyCreatesTrgmIndex()

    /**
     * A property WITHOUT `searchable: true` gets no trgm index.
     *
     * @return void
     */
    public function testPropertyWithoutSearchableGetsNoTrgmIndex(): void
    {
        $mapper = $this->makeMapper($this->createMock(PostgreSQLPlatform::class), pgTrgmCount: 1);
        $this->runCreateIndexes(
            $mapper,
            'openregister_table_1_1',
            ['title' => ['type' => 'string']]
        );

        $this->assertNoSqlContains('_title_trgm_idx');
    }//end testPropertyWithoutSearchableGetsNoTrgmIndex()

    /**
     * A non-string property marked `searchable: true` whose index creation fails
     * (incompatible column type) is logged as a warning and does NOT abort table
     * creation — the routine still reaches its completion debug log.
     *
     * @return void
     */
    public function testSearchableNonStringLogsWarningAndDoesNotAbort(): void
    {
        // Fail the trgm-index statement for the `amount` column specifically.
        $mapper = $this->makeMapper(
            $this->createMock(PostgreSQLPlatform::class),
            pgTrgmCount: 1,
            throwOnNeedle: '_amount_trgm_idx'
        );
        $this->runCreateIndexes(
            $mapper,
            'openregister_table_1_1',
            ['amount' => ['type' => 'number', 'searchable' => true]]
        );

        // The incompatible index attempt was made and swallowed with a warning.
        $this->assertSqlContains('_amount_trgm_idx');
        $warnings = implode(' | ', $this->capturedLogs['warning']);
        $this->assertStringContainsString('Skipped searchable trgm index', $warnings);

        // The outer catch (which would fire on an un-swallowed exception) did NOT.
        $this->assertStringNotContainsString('Failed to create some table indexes', $warnings);

        // And the routine ran to completion (final debug log emitted).
        $debug = implode(' | ', $this->capturedLogs['debug']);
        $this->assertStringContainsString('Created table indexes', $debug);
    }//end testSearchableNonStringLogsWarningAndDoesNotAbort()

    /**
     * `searchable: true` is a no-op on MariaDB (no trgm index, no error).
     *
     * @return void
     */
    public function testSearchableIsNoOpOnMariaDb(): void
    {
        $mapper = $this->makeMapper($this->createMock(MariaDBPlatform::class), pgTrgmCount: 0);
        $this->runCreateIndexes(
            $mapper,
            'openregister_table_1_1',
            ['title' => ['type' => 'string', 'searchable' => true]]
        );

        $this->assertNoSqlContains('gin_trgm_ops');
        $this->assertSqlContains('openregister_table_1_1_uuid_idx');
    }//end testSearchableIsNoOpOnMariaDb()

    // -------------------------------------------------------------------------
    // Retrofit path (task 5.3)
    // -------------------------------------------------------------------------

    /**
     * The public retrofit entry point updateTableIndexes() re-runs the full
     * index-creation routine, so both the baseline `_name` index and a
     * newly-`searchable`-flagged property get indexed on an existing table.
     *
     * @return void
     */
    public function testRetrofitViaUpdateTableIndexesCreatesBothIndexKinds(): void
    {
        $mapper = $this->makeMapper($this->createMock(PostgreSQLPlatform::class), pgTrgmCount: 1);

        $mapper->updateTableIndexes(
            'openregister_table_1_1',
            $this->createMock(Register::class),
            $this->makeSchema(['title' => ['type' => 'string', 'searchable' => true]])
        );

        $this->assertSqlContains('openregister_table_1_1_name_trgm_idx');
        $this->assertSqlContains('openregister_table_1_1_title_trgm_idx');
    }//end testRetrofitViaUpdateTableIndexesCreatesBothIndexKinds()
}//end class
