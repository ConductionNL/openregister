<?php

/**
 * Unit tests for the cumulative (running-total) time-bucket primitive
 * (REQ-AGG-103).
 *
 * Covers:
 *  - Postgres emits a `SUM(...) OVER (ORDER BY bucket)` window column
 *    when `cumulative: true` and wires the returned `cumulative_agg`
 *    column into each group's `cumulative` key.
 *  - MySQL/SQLite do NOT get a SQL window — the runner falls through to
 *    the `addCumulativeColumn()` PHP post-pass over the ordered buckets.
 *  - The PHP-fallback bucketer (unrecognised engine) also uses the PHP
 *    post-pass.
 *  - SQL-window output (Postgres) and PHP-post-pass output (MySQL) MUST
 *    agree bucket-for-bucket on the same underlying data (design D3
 *    parity requirement).
 *  - The ad-hoc cache key differentiates `cumulative: true` from
 *    `cumulative` absent/false (REQ-AGG-105).
 *  - The existing (non-cumulative) timeseries response shape is
 *    unchanged when `cumulative` is not requested.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationRunner
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationQuery
 */
class AggregationRunnerCumulativeTest extends TestCase
{

    private MagicMapper&MockObject $magicMapper;

    private RegisterMapper&MockObject $registerMapper;

    private SchemaMapper&MockObject $schemaMapper;

    private PlaceholderResolver $placeholderResolver;

    private IDBConnection&MockObject $db;

    private AggregationCache&MockObject $cache;

    private PermissionHandler&MockObject $permissionHandler;

    private IUserSession&MockObject $userSession;

    private OrganisationService&MockObject $organisationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->magicMapper    = $this->createMock(MagicMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->db    = $this->createMock(IDBConnection::class);
        $this->cache = $this->createMock(AggregationCache::class);
        $this->permissionHandler   = $this->createMock(PermissionHandler::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->placeholderResolver = new PlaceholderResolver($this->userSession);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->permissionHandler->method('hasPermission')->willReturn(true);
        $this->cache->method('getAdhoc')->willReturn(null);
        $this->cache->method('setAdhoc');
        $this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_calllogs');

    }//end setUp()

    // -----------------------------------------------------------------------
    // AggregationQuery-level validation (REQ-AGG-103).
    // -----------------------------------------------------------------------

    public function testCumulativeRequiresDateBucket(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cumulative MUST only be combined with a dateBucket');

        AggregationQuery::create(metric: 'count', cumulative: true);

    }//end testCumulativeRequiresDateBucket()

    public function testCumulativeWithDateBucketIsAccepted(): void
    {
        $query = AggregationQuery::create(
            metric: 'count',
            dateBucket: ['field' => 'created', 'start' => '2026-05-01T00:00:00Z', 'end' => '2026-05-22T00:00:00Z', 'gap' => 'day'],
            cumulative: true
        );

        $this->assertTrue($query->isCumulative());
        $this->assertTrue($query->cumulative);

    }//end testCumulativeWithDateBucketIsAccepted()

    public function testCumulativeDefaultsToFalse(): void
    {
        $query = AggregationQuery::create(metric: 'count');
        $this->assertFalse($query->isCumulative());

    }//end testCumulativeDefaultsToFalse()

    public function testToArrayIncludesCumulativeFlag(): void
    {
        $withCumulative = $this->dayBucketQuery(cumulative: true);
        $withoutCumulative = $this->dayBucketQuery(cumulative: false);

        $this->assertTrue($withCumulative->toArray()['cumulative']);
        $this->assertFalse($withoutCumulative->toArray()['cumulative']);

    }//end testToArrayIncludesCumulativeFlag()

    // -----------------------------------------------------------------------
    // Native SQL — Postgres emits a SUM(...) OVER(...) window.
    // -----------------------------------------------------------------------

    public function testPostgresEmitsSqlWindowWhenCumulativeRequested(): void
    {
        $this->wirePlatform(platform: $this->createMock(PostgreSQLPlatform::class));
        $captured = $this->captureSqlWithRows(
            [
                ['bucket' => '2026-05-01T00:00:00Z', 'agg' => 3, 'cumulative_agg' => 3],
                ['bucket' => '2026-05-02T00:00:00Z', 'agg' => 5, 'cumulative_agg' => 8],
                ['bucket' => '2026-05-03T00:00:00Z', 'agg' => 2, 'cumulative_agg' => 10],
            ]
        );

        $runner = $this->makeRunner();
        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeSchema(),
            query: $this->dayBucketQuery(cumulative: true)
        );

        $this->assertStringContainsString(
            'SUM(COUNT(*)) OVER (ORDER BY bucket) AS cumulative_agg',
            (string) $captured['sql'],
            'Postgres path MUST emit a native SQL running-total window when cumulative is requested'
        );
        $this->assertSame('postgres', $result['backend']);
        $this->assertSame([3, 8, 10], array_column($result['groups'], 'cumulative'));
        $this->assertSame([3, 5, 2], array_column($result['groups'], 'value'), 'per-bucket value MUST be unchanged');

    }//end testPostgresEmitsSqlWindowWhenCumulativeRequested()

    public function testCumulativeAbsentLeavesSqlAndShapeUnchanged(): void
    {
        $this->wirePlatform(platform: $this->createMock(PostgreSQLPlatform::class));
        $captured = $this->captureSqlWithRows(
            [
                ['bucket' => '2026-05-01T00:00:00Z', 'agg' => 3],
                ['bucket' => '2026-05-02T00:00:00Z', 'agg' => 5],
            ]
        );

        $runner = $this->makeRunner();
        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeSchema(),
            query: $this->dayBucketQuery(cumulative: false)
        );

        $this->assertStringNotContainsString(
            'OVER (',
            (string) $captured['sql'],
            'cumulative absent MUST NOT add a window function to the SQL'
        );
        $this->assertArrayNotHasKey(
            'cumulative',
            $result['groups'][0],
            'cumulative absent MUST NOT add a cumulative key to the response shape'
        );

    }//end testCumulativeAbsentLeavesSqlAndShapeUnchanged()

    // -----------------------------------------------------------------------
    // Native SQL — MySQL/SQLite use the PHP post-pass, not a SQL window.
    // -----------------------------------------------------------------------

    public function testMysqlUsesPhpPostPassNotSqlWindow(): void
    {
        $this->wirePlatform(platform: $this->createMock(MySQLPlatform::class));
        // MySQL rows carry only `bucket`/`agg` — no `cumulative_agg` column
        // is ever requested from this engine.
        $captured = $this->captureSqlWithRows(
            [
                ['bucket' => '2026-05-01T00:00:00Z', 'agg' => 3],
                ['bucket' => '2026-05-02T00:00:00Z', 'agg' => 5],
                ['bucket' => '2026-05-03T00:00:00Z', 'agg' => 2],
            ]
        );

        $runner = $this->makeRunner();
        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeSchema(),
            query: $this->dayBucketQuery(cumulative: true)
        );

        $this->assertStringNotContainsString(
            'OVER (',
            (string) $captured['sql'],
            'MySQL MUST NOT get a native SQL window — it uses the PHP post-pass instead'
        );
        $this->assertSame('mysql', $result['backend']);
        $this->assertSame([3, 8, 10], array_column($result['groups'], 'cumulative'));

    }//end testMysqlUsesPhpPostPassNotSqlWindow()

    // -----------------------------------------------------------------------
    // PHP fallback (unrecognised engine).
    // -----------------------------------------------------------------------

    public function testPhpFallbackComputesRunningTotal(): void
    {
        $this->wirePlatform(platform: $this->createMock(AbstractPlatform::class));
        $this->db->expects($this->never())->method('prepare');

        $rows = [
            $this->makeObjectEntity(created: '2026-05-01T10:00:00Z'),
            $this->makeObjectEntity(created: '2026-05-01T11:00:00Z'),
            $this->makeObjectEntity(created: '2026-05-01T12:00:00Z'),
            $this->makeObjectEntity(created: '2026-05-02T10:00:00Z'),
            $this->makeObjectEntity(created: '2026-05-02T11:00:00Z'),
            $this->makeObjectEntity(created: '2026-05-03T10:00:00Z'),
            $this->makeObjectEntity(created: '2026-05-03T11:00:00Z'),
        ];
        $this->magicMapper->method('findAllInRegisterSchemaTable')->willReturn($rows);

        $runner = $this->makeRunner();
        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeSchema(),
            query: $this->dayBucketQuery(cumulative: true)
        );

        $this->assertSame('php-fallback', $result['backend']);
        // 3 objects on day 1, 2 on day 2, 2 on day 3 → running total 3, 5, 7.
        $this->assertSame([3, 2, 2], array_column($result['groups'], 'value'));
        $this->assertSame([3, 5, 7], array_column($result['groups'], 'cumulative'));

    }//end testPhpFallbackComputesRunningTotal()

    // -----------------------------------------------------------------------
    // Parity — SQL window (Postgres) vs PHP post-pass (MySQL) MUST agree.
    // -----------------------------------------------------------------------

    /**
     * The spec (REQ-AGG-103 / design D3) requires the SQL-window path and
     * the PHP-post-pass path to produce IDENTICAL output for the same
     * underlying per-bucket data. This test feeds the same four per-bucket
     * `agg` values through both code paths — Postgres (native SQL window,
     * simulated via a mocked row set carrying the pre-computed
     * `cumulative_agg` a real `SUM(...) OVER (ORDER BY bucket)` would
     * return) and MySQL (which always falls through to
     * `addCumulativeColumn()`, the PHP post-pass) — and asserts the
     * resulting `cumulative` sequences are byte-identical.
     *
     * @return void
     */
    public function testSqlWindowAndPhpPostPassAgreeOnTheSameData(): void
    {
        $aggValues = [4, 0, 11, 7];
        // The running total a correct implementation (either path) MUST
        // produce for [4, 0, 11, 7]: 4, 4, 15, 22.
        $expectedCumulative = [4, 4, 15, 22];

        $bucketKeys = [
            '2026-06-01T00:00:00Z',
            '2026-06-02T00:00:00Z',
            '2026-06-03T00:00:00Z',
            '2026-06-04T00:00:00Z',
        ];

        // --- Postgres: native SQL window. The mocked row carries
        // `cumulative_agg` set to the value a real `SUM(...) OVER (ORDER
        // BY bucket)` would compute — this is what the runner is
        // responsible for wiring through untouched.
        $postgresRows = [];
        foreach ($bucketKeys as $index => $key) {
            $postgresRows[] = [
                'bucket'         => $key,
                'agg'            => $aggValues[$index],
                'cumulative_agg' => $expectedCumulative[$index],
            ];
        }

        $this->wirePlatform(platform: $this->createMock(PostgreSQLPlatform::class));
        $this->captureSqlWithRows($postgresRows);
        $postgresRunner = $this->makeRunner();
        $postgresResult = $postgresRunner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeSchema(),
            query: $this->dayBucketQuery(cumulative: true)
        );

        // --- MySQL: PHP post-pass. The mocked rows carry ONLY `agg` — the
        // runner itself must compute the running total in PHP.
        $mysqlDb    = $this->createMock(IDBConnection::class);
        $mysqlRows  = [];
        foreach ($bucketKeys as $index => $key) {
            $mysqlRows[] = ['bucket' => $key, 'agg' => $aggValues[$index]];
        }

        $mysqlDb->method('getDatabasePlatform')->willReturn($this->createMock(MySQLPlatform::class));
        $stmt   = $this->createMock(IPreparedStatement::class);
        $cursor = new \ArrayIterator($mysqlRows);
        $stmt->method('execute')->willReturn($this->createMock(IResult::class));
        $stmt->method('fetch')->willReturnCallback(
            function () use ($cursor) {
                if ($cursor->valid() === false) {
                    return false;
                }

                $row = $cursor->current();
                $cursor->next();
                return $row;
            }
        );
        $mysqlDb->method('prepare')->willReturn($stmt);

        $mysqlRunner = new AggregationRunner(
            magicMapper: $this->magicMapper,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            placeholders: $this->placeholderResolver,
            db: $mysqlDb,
            cache: $this->cache,
            permissionHandler: $this->permissionHandler,
            userSession: $this->userSession,
            organisationService: $this->organisationService,
            translationHandler: $this->createMock(TranslationHandler::class),
            languageService: $this->createMock(LanguageService::class),
        );
        $mysqlResult = $mysqlRunner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeSchema(),
            query: $this->dayBucketQuery(cumulative: true)
        );

        $this->assertSame(
            $expectedCumulative,
            array_column($postgresResult['groups'], 'cumulative'),
            'Postgres SQL-window path MUST report the expected running total'
        );
        $this->assertSame(
            $expectedCumulative,
            array_column($mysqlResult['groups'], 'cumulative'),
            'MySQL PHP-post-pass path MUST report the expected running total'
        );
        $this->assertSame(
            array_column($postgresResult['groups'], 'cumulative'),
            array_column($mysqlResult['groups'], 'cumulative'),
            'SQL-window (Postgres) and PHP-post-pass (MySQL) outputs MUST be identical — design D3 parity requirement'
        );

    }//end testSqlWindowAndPhpPostPassAgreeOnTheSameData()

    // -----------------------------------------------------------------------
    // Cache key differentiation (REQ-AGG-105).
    // -----------------------------------------------------------------------

    public function testCacheKeyDiffersBetweenCumulativeAndPlainRequests(): void
    {
        $captured = [];
        $this->cache->method('getAdhoc')->willReturnCallback(
            function (string $registerSlug, string $schemaSlug, AggregationQuery $q) use (&$captured) {
                $captured[] = $q->toArray();
                return null;
            }
        );

        $this->wirePlatform(platform: $this->createMock(PostgreSQLPlatform::class));
        $this->captureSqlWithRows([]);
        $runner = $this->makeRunner();

        $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeSchema(),
            query: $this->dayBucketQuery(cumulative: true)
        );
        $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeSchema(),
            query: $this->dayBucketQuery(cumulative: false)
        );

        $this->assertCount(2, $captured);
        $hashes = array_map(
            static fn (array $arr) => sha1((string) json_encode($arr)),
            $captured
        );
        $this->assertNotSame(
            $hashes[0],
            $hashes[1],
            'the ad-hoc cache key MUST differ between a cumulative and a non-cumulative request'
        );

    }//end testCacheKeyDiffersBetweenCumulativeAndPlainRequests()

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    private function dayBucketQuery(bool $cumulative=false): AggregationQuery
    {
        return AggregationQuery::create(
            metric: 'count',
            dateBucket: [
                'field' => 'created',
                'start' => '2026-05-01T00:00:00Z',
                'end'   => '2026-05-22T00:00:00Z',
                'gap'   => 'day',
            ],
            cumulative: $cumulative
        );

    }//end dayBucketQuery()

    /**
     * Capture the SQL passed to `db->prepare()` and feed the given fake
     * rows back through `stmt->fetch()`.
     *
     * @param array<int, array<string, mixed>> $rows Rows to yield in order, then `false`.
     *
     * @return \ArrayObject<string, mixed> Mutable container with a `sql` key.
     */
    private function captureSqlWithRows(array $rows): \ArrayObject
    {
        $captured = new \ArrayObject(['sql' => null]);
        $cursor   = new \ArrayIterator($rows);
        $stmt     = $this->createMock(IPreparedStatement::class);
        $stmt->method('execute')->willReturn($this->createMock(IResult::class));
        $stmt->method('fetch')->willReturnCallback(
            function () use ($cursor) {
                if ($cursor->valid() === false) {
                    return false;
                }

                $row = $cursor->current();
                $cursor->next();
                return $row;
            }
        );

        $this->db->method('prepare')->willReturnCallback(
            function (string $sql) use ($stmt, $captured) {
                $captured['sql'] = $sql;
                return $stmt;
            }
        );

        return $captured;

    }//end captureSqlWithRows()

    private function wirePlatform(AbstractPlatform $platform): void
    {
        $this->db->method('getDatabasePlatform')->willReturn($platform);

    }//end wirePlatform()

    private function makeObjectEntity(string $created): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setObject(['created' => $created]);
        return $entity;

    }//end makeObjectEntity()

    private function makeSchema(): Schema
    {
        $schema = new Schema();
        $schema->setSlug('calllogs');
        $schema->setId(1);
        return $schema;

    }//end makeSchema()

    private function makeRegister(): Register
    {
        $register = new Register();
        $register->setSlug('openconnector');
        $register->setSchemas([1]);
        return $register;

    }//end makeRegister()

    private function makeRunner(): AggregationRunner
    {
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
