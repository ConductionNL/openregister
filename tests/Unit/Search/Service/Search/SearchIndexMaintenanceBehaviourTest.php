<?php

/**
 * Behaviour tests for SearchIndexMaintenance: row counts, table resolution, index names,
 * the identifier guard, the stored last run and an unwritable snapshot.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Search;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use OCA\OpenRegister\Search\Service\Search\SearchIndexStores;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Search\Service\Search\SearchIndexPass;

/**
 * Registers and schemas are real entities: getId() is a magic Entity method a double cannot answer.
 *
 * @covers \OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance
 * @uses   \OCA\OpenRegister\Search\Service\Search\SearchIndexStores
 * @uses   \OCA\OpenRegister\Search\Service\Search\SearchIndexRowCounts
 * @uses   \OCA\OpenRegister\Search\Service\Search\SearchIndexPass
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class SearchIndexMaintenanceBehaviourTest extends TestCase
{
    /** @var IDBConnection&MockObject */
    private IDBConnection $db;

    /** @var RegisterMapperInterface&MockObject */
    private RegisterMapperInterface $registerMapper;

    /** @var MagicMapperInterface&MockObject */
    private MagicMapperInterface $magicMapper;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $logs = [];

    /** @var list<string> */
    private array $queries = [];

    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        $this->db             = $this->createMock(IDBConnection::class);
        $this->registerMapper = $this->createMock(RegisterMapperInterface::class);
        $this->magicMapper    = $this->createMock(MagicMapperInterface::class);
        $this->appConfig      = $this->createMock(IAppConfig::class);
    }//end setUp()

    private function service(bool $postgres = true): SearchIndexMaintenance
    {
        $platform = $postgres ? $this->createMock(PostgreSQLPlatform::class) : $this->createMock(MariaDBPlatform::class);
        $platform->method('quoteIdentifier')->willReturnCallback(static fn(string $n): string => '"'.$n.'"');
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->with('dbtableprefix', 'oc_')->willReturn('nc_');

        $logger = $this->createMock(LoggerInterface::class);
        foreach (['warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    unset($context['file'], $context['line']);
                    $this->logs[] = [$level, $message, $context];
                }
            );
        }

        return new SearchIndexMaintenance(
            new SearchIndexStores($this->db, new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->createMock(\OCA\OpenRegister\Db\SchemaMapperInterface::class), tables: $this->magicMapper)),
            $this->appConfig,
            $config,
            $logger
        );
    }//end service()

    private function register(int $id): Register
    {
        $register = new Register();
        $register->setId($id);
        return $register;
    }//end register()

    private function schema(int $id, ?string $slug): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        if ($slug !== null) {
            $schema->setSlug($slug);
        }

        return $schema;
    }//end schema()

    /**
     * Register 1 holds schemas 10 (slug "people") and 11 (no slug); register 2 holds schema 20 ("cases").
     */
    private function twoRegisters(): void
    {
        $this->registerMapper->method('findAll')->willReturn([$this->register(1), 'not-a-register', $this->register(2)]);
        $this->registerMapper->method('getSchemasByRegisterId')->willReturnCallback(
            fn(int $registerId): array => match ($registerId) {
                1 => [$this->schema(10, 'people'), 'not-a-schema', $this->schema(11, null)],
                2 => [$this->schema(20, 'cases')],
            }
        );
        $this->magicMapper->method('getTableNameForRegisterSchema')->willReturnCallback(
            static fn(Register $r, Schema $s): string => 'openregister_table_'.$r->getId().'_'.$s->getId()
        );
    }//end twoRegisters()

    private function rowsResult(array $rows): IResult
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn($rows);
        return $result;
    }//end result()

    public function testTablesInScopeSkipsNonEntitiesAndFiltersOnRegisterId(): void
    {
        $this->twoRegisters();
        $service = $this->service();

        $this->assertSame(
            ['openregister_table_1_10', 'openregister_table_1_11', 'openregister_table_2_20'],
            $service->tablesInScope()
        );
        $this->assertSame(['openregister_table_2_20'], $service->tablesInScope(2));
        $this->assertSame([], $service->tablesInScope(99));
    }//end testTablesInScopeSkipsNonEntitiesAndFiltersOnRegisterId()

    public function testATableThatCannotBeResolvedIsLeftOutAndLogged(): void
    {
        $this->registerMapper->method('findAll')->willReturn([$this->register(1)]);
        $this->registerMapper->method('getSchemasByRegisterId')->willReturn([$this->schema(10, 'a'), $this->schema(11, 'b')]);
        $this->magicMapper->method('getTableNameForRegisterSchema')->willReturnCallback(
            static function (Register $r, Schema $s): string {
                if ($s->getId() === 10) {
                    throw new RuntimeException('no table yet');
                }

                return 'openregister_table_1_11';
            }
        );

        $this->assertSame(['openregister_table_1_11'], $this->service()->tablesInScope());
        $this->assertSame(
            [['warning', '[SearchIndexMaintenance] Could not resolve a magic table', ['register' => 1, 'schema' => 10, 'error' => 'no table yet']]],
            $this->logs
        );
    }//end testATableThatCannotBeResolvedIsLeftOutAndLogged()

    public function testRowCountsAreReadInOneUnionAndKeyedBySlugOrSchemaId(): void
    {
        $this->twoRegisters();
        $this->db->method('executeQuery')->willReturnCallback(
            function (string $sql): IResult {
                $this->queries[] = $sql;
                return $this->rowsResult([['t' => '0', 'n' => '5'], ['t' => 1, 'n' => '2'], ['t' => '2', 'n' => 7]]);
            }
        );

        $counts = $this->service()->rowCounts();

        $this->assertSame(['rowsInTables' => 14, 'indexed' => ['people' => 5, '11' => 2, 'cases' => 7]], $counts);
        $this->assertSame(
            [
                'SELECT 0 AS t, COUNT(*) AS n FROM "nc_openregister_table_1_10" UNION ALL '
                .'SELECT 1 AS t, COUNT(*) AS n FROM "nc_openregister_table_1_11" UNION ALL '
                .'SELECT 2 AS t, COUNT(*) AS n FROM "nc_openregister_table_2_20"',
            ],
            $this->queries
        );
    }//end testRowCountsAreReadInOneUnionAndKeyedBySlugOrSchemaId()

    public function testAFailedUnionFallsBackToOneCountPerTableAndDropsTheTotalForAnUncountedTable(): void
    {
        $this->twoRegisters();
        $this->db->method('executeQuery')->willReturnCallback(
            function (string $sql): IResult {
                $this->queries[] = $sql;
                if (str_contains($sql, 'UNION ALL') === true) {
                    throw new RuntimeException('relation missing');
                }

                if (str_contains($sql, 'table_1_11') === true) {
                    throw new RuntimeException('relation "nc_openregister_table_1_11" does not exist');
                }

                $index = (int) substr($sql, 7, 1);
                return $this->rowsResult([['t' => $index, 'n' => 3]]);
            }
        );

        $counts = $this->service()->rowCounts();

        $this->assertSame(['indexed' => ['people' => 3, 'cases' => 3]], $counts);
        $this->assertCount(4, $this->queries);
        $this->assertSame('SELECT 2 AS t, COUNT(*) AS n FROM "nc_openregister_table_2_20"', $this->queries[3]);
        $this->assertSame(
            [[
                'warning',
                '[SearchIndexMaintenance] Could not count the rows of a magic table',
                ['table' => 'openregister_table_1_11', 'error' => 'relation "nc_openregister_table_1_11" does not exist'],
            ]],
            $this->logs
        );
    }//end testAFailedUnionFallsBackToOneCountPerTableAndDropsTheTotalForAnUncountedTable()

    public function testNoTablesMeansNoQueryAndAZeroTotal(): void
    {
        $this->registerMapper->method('findAll')->willReturn([]);
        $this->db->expects($this->never())->method('executeQuery');

        $this->assertSame(['rowsInTables' => 0, 'indexed' => []], $this->service()->rowCounts());
    }//end testNoTablesMeansNoQueryAndAZeroTotal()

    public function testIndexesForAndIndexNamesForAnswerNothingOffPostgres(): void
    {
        $this->db->expects($this->never())->method('prepare');
        $service = $this->service(postgres: false);

        $this->assertFalse($service->supportsConcurrentRebuild());
        $this->assertSame([], $service->indexesFor('openregister_table_1_10'));
        $this->assertSame(['t1' => [], 't2' => []], $service->indexNamesFor(['t1', 't2']));
    }//end testIndexesForAndIndexNamesForAnswerNothingOffPostgres()

    public function testIndexNamesForNoTablesRunsNoQuery(): void
    {
        $this->db->expects($this->never())->method('prepare');

        $this->assertSame([], $this->service()->indexNamesFor([]));
    }//end testIndexNamesForNoTablesRunsNoQuery()

    public function testIndexNamesIgnoreRowsOfTablesNotAskedFor(): void
    {
        $statement = $this->createMock(IPreparedStatement::class);
        $statement->expects($this->once())->method('execute')->with(['nc_t1']);
        $statement->method('fetch')->willReturnOnConsecutiveCalls(
            ['tablename' => 'nc_t1', 'indexname' => 'i_a'],
            ['tablename' => 'nc_other', 'indexname' => 'i_b'],
            false
        );
        $this->db->method('prepare')->willReturn($statement);

        $this->assertSame(['t1' => ['i_a']], $this->service()->indexNamesFor(['t1']));
    }//end testIndexNamesIgnoreRowsOfTablesNotAskedFor()

    public function testAnIndexNameThatIsNotAnIdentifierIsRefusedAndNoStatementRuns(): void
    {
        $this->registerMapper->method('findAll')->willReturn([$this->register(1)]);
        $this->registerMapper->method('getSchemasByRegisterId')->willReturn([$this->schema(10, 'a')]);
        $this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_10');
        $statement = $this->createMock(IPreparedStatement::class);
        $statement->method('fetch')->willReturnOnConsecutiveCalls(
            ['indexname' => 'bad"; DROP TABLE x; --', 'indexdef' => 'CREATE INDEX ...'],
            false
        );
        $this->db->method('prepare')->willReturn($statement);
        $this->db->expects($this->never())->method('executeStatement');

        $report = $this->service()->rebuild(pass: new SearchIndexPass(apply: true, progress: null));

        $this->assertSame('failed', $report['state']);
        $this->assertSame(0, $report['rebuilt']);
        $this->assertSame(
            [['table' => 'openregister_table_1_10', 'index' => 'bad"; DROP TABLE x; --', 'error' => "Refusing to act on the index name 'bad\"; DROP TABLE x; --'."]],
            $report['failures']
        );
        $this->assertSame(
            ['warning', 'error'],
            array_column($this->logs, 0)
        );
        $this->assertSame('[SearchIndexMaintenance] Could not drop the leftover of a failed rebuild', $this->logs[0][1]);
    }//end testAnIndexNameThatIsNotAnIdentifierIsRefusedAndNoStatementRuns()

    public function testLastRunIsEmptyWhenNothingOrNoJsonArrayIsStored(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', SearchIndexMaintenance::LAST_RUN_KEY, '')
            ->willReturnOnConsecutiveCalls('', 'not json', '"a string"', '{"state":"completed","rebuilt":3}');
        $service = $this->service();

        $this->assertSame([], $service->lastRun());
        $this->assertSame([], $service->lastRun());
        $this->assertSame([], $service->lastRun());
        $this->assertSame(['state' => 'completed', 'rebuilt' => 3], $service->lastRun());
    }//end testLastRunIsEmptyWhenNothingOrNoJsonArrayIsStored()

    public function testASnapshotThatCannotBeWrittenThrows(): void
    {
        $this->registerMapper->method('findAll')->willReturn([]);
        $path = sys_get_temp_dir().'/no-such-dir-'.bin2hex(random_bytes(4)).'/snapshot.json';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not write the index snapshot to '{$path}'.");
        @$this->service()->snapshot($path);
    }//end testASnapshotThatCannotBeWrittenThrows()
}//end class
