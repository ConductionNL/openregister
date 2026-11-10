<?php

/**
 * Golden test for MagicStatisticsHandler's access-relevant statistics paths.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCP\IConfig;
use OCA\OpenRegister\Db\MagicMapper\MagicTableSql;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\MagicMapper\StatisticsScope;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins, on the pre-refactor code, which register/schema tables the statistics
 * cover and which they leave out, and that every register/schema lookup they
 * make deliberately bypasses RBAC and multitenancy (`_rbac: false`,
 * `_multitenancy: false`) while the count is asked with an empty query.
 */
class MagicStatisticsHandlerGoldenTest extends TestCase {

	/**
	 * Every mapper find() call: [kind, id, _multitenancy, _rbac].
	 *
	 * @var array<int, array{0: string, 1: int, 2: mixed, 3: mixed}>
	 */
	private array $finds = [];

	/**
	 * Every count callback call: [query, registerId, schemaId].
	 *
	 * @var array<int, array{0: array, 1: int|null, 2: int|null}>
	 */
	private array $counts = [];

	/**
	 * SQL passed to prepare().
	 *
	 * @var array<int, string>
	 */
	private array $sql = [];

	/**
	 * Register ids whose find() throws.
	 *
	 * @var array<int, int>
	 */
	private array $failingRegisters = [];

	/**
	 * Schema ids whose count throws.
	 *
	 * @var array<int, int>
	 */
	private array $failingCountSchemas = [];

	/**
	 * Build the handler over a fake catalogue.
	 *
	 * @param string $platformClass The database platform class.
	 * @param bool   $withCallback  Whether to set a count callback.
	 * @param bool   $dbFails       Whether prepare() throws.
	 *
	 * @return MagicStatisticsHandler
	 */
	private function handler(
		string $platformClass=MySQLPlatform::class,
		bool $withCallback=true,
		bool $dbFails=false
	): MagicStatisticsHandler {
		$tables = [
			'oc_openregister_table_1_10',
			'oc_openregister_table_1_11',
			'oc_openregister_table_2_10',
			'oc_openregister_table_3_12',
			'oc_openregister_table_foo',
			'oc_openregister_table_4_13_x',
		];
		$stmt = $this->createMock(IPreparedStatement::class);
		$rows = array_map(static fn (string $t): array => ['table_name' => $t], $tables);
		$rows[] = false;
		$stmt->method('fetch')->willReturnOnConsecutiveCalls(...$rows);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock($platformClass));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($stmt, $dbFails) {
				$this->sql[] = $sql;
				if ($dbFails === true) {
					throw new Exception('db down');
				}

				return $stmt;
			}
		);

		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('find')->willReturnCallback(
			function ($id, $rbac=true, $multitenancy=true) {
				$this->finds[] = ['register', $id, $multitenancy, $rbac];
				if (in_array($id, $this->failingRegisters, true) === true) {
					throw new Exception('no register');
				}

				$register = new Register();
				$register->setId($id);
				$register->setTitle('R' . $id);
				return $register;
			}
		);
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id, $extend=[], $rbac=true, $multitenancy=true) {
				$this->finds[] = ['schema', $id, $multitenancy, $rbac];
				$schema = new Schema();
				$schema->setId($id);
				$schema->setTitle('S' . $id);
				return $schema;
			}
		);

		$handler = new MagicStatisticsHandler(
			sql: new MagicTableSql(db: $db, config: $this->createMock(IConfig::class), logger: $this->createMock(LoggerInterface::class)),
			catalog: new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $this->createMock(MagicMapperInterface::class)),
			dateTimeNormalizer: $this->createMock(DateTimeNormalizerInterface::class),
			schemaTypeConverter: $this->createMock(SchemaTypeConverterInterface::class)
		);
		if ($withCallback === true) {
			$handler->setCountCallback(
				function (array $query, Register $register, Schema $schema): int {
					$this->counts[] = [$query, $register->getId(), $schema->getId()];
					if (in_array($schema->getId(), $this->failingCountSchemas, true) === true) {
						throw new Exception('count failed');
					}

					return ($register->getId() * 100) + $schema->getId();
				}
			);
		}

		return $handler;
	}//end handler()

	/**
	 * Assert every find() bypassed RBAC and multitenancy explicitly.
	 *
	 * @return void
	 */
	private function assertAllFindsBypassAccess(): void {
		$this->assertNotEmpty($this->finds);
		foreach ($this->finds as $find) {
			$this->assertFalse($find[2], 'find() must pass _multitenancy: false');
			$this->assertFalse($find[3], 'find() must pass _rbac: false');
		}

		foreach ($this->counts as $count) {
			$this->assertSame([], $count[0], 'count must be asked with an empty query');
		}
	}//end assertAllFindsBypassAccess()

	/**
	 * The pairs that were counted, as "r_s".
	 *
	 * @return array<int, string>
	 */
	private function countedPairs(): array {
		return array_map(static fn (array $c): string => $c[1] . '_' . $c[2], $this->counts);
	}//end countedPairs()

	/**
	 * Only `openregister_table_{int}_{int}` tables become pairs; MySQL asks DATABASE().
	 *
	 * @return void
	 */
	public function testPairDiscoveryKeepsOnlyWellFormedTables(): void {
		$this->assertSame(
			[
				['registerId' => 1, 'schemaId' => 10],
				['registerId' => 1, 'schemaId' => 11],
				['registerId' => 2, 'schemaId' => 10],
				['registerId' => 3, 'schemaId' => 12],
			],
			$this->handler()->getAllRegisterSchemaPairs()
		);
		$this->assertCount(1, $this->sql);
		$this->assertStringContainsString('DATABASE()', $this->sql[0]);
		$this->assertStringContainsString("LIKE '*PREFIX*openregister_table_%'", $this->sql[0]);
	}//end testPairDiscoveryKeepsOnlyWellFormedTables()

	/**
	 * PostgreSQL asks current_schema(), and the catalog is scanned once per request.
	 *
	 * @return void
	 */
	public function testPostgresUsesCurrentSchemaAndTheListIsMemoised(): void {
		$handler = $this->handler(platformClass: PostgreSQLPlatform::class);
		$handler->getAllRegisterSchemaPairs();
		$this->assertCount(4, $handler->getAllRegisterSchemaPairs());
		$this->assertCount(1, $this->sql);
		$this->assertStringContainsString('current_schema()', $this->sql[0]);
		$this->assertStringNotContainsString('DATABASE()', $this->sql[0]);
	}//end testPostgresUsesCurrentSchemaAndTheListIsMemoised()

	/**
	 * A failing catalog scan covers no pairs and is retried on the next call.
	 *
	 * @return void
	 */
	public function testCatalogueFailureYieldsNoPairsAndIsNotMemoised(): void {
		$handler = $this->handler(dbFails: true);
		$this->assertSame([], $handler->getAllRegisterSchemaPairs());
		$this->assertSame([], $handler->getAllRegisterSchemaPairs());
		$this->assertCount(2, $this->sql);
		$this->assertSame(
			['total' => 0, 'size' => 0, 'invalid' => 0, 'deleted' => 0, 'locked' => 0],
			$handler->getStatistics()
		);
	}//end testCatalogueFailureYieldsNoPairsAndIsNotMemoised()

	/**
	 * Unfiltered statistics count every pair, reading entities without RBAC or multitenancy.
	 *
	 * @return void
	 */
	public function testStatisticsWithoutFilterCoverEveryPairAndBypassAccess(): void {
		$stats = $this->handler()->getStatistics();
		$this->assertSame(
			['total' => 110 + 111 + 210 + 312, 'size' => 0, 'invalid' => 0, 'deleted' => 0, 'locked' => 0],
			$stats
		);
		$this->assertSame(['1_10', '1_11', '2_10', '3_12'], $this->countedPairs());
		$this->assertAllFindsBypassAccess();
	}//end testStatisticsWithoutFilterCoverEveryPairAndBypassAccess()

	/**
	 * Filter inputs and the pairs they must count.
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: array, 3: array<int, string>}>
	 */
	public static function filterCases(): array {
		return [
			'register int'             => [1, null, [], ['1_10', '1_11']],
			'register int other'       => [2, null, [], ['2_10']],
			'register unknown'         => [9, null, [], []],
			'register array'           => [[1, 3], null, [], ['1_10', '1_11', '3_12']],
			'register array strict'    => [['1', '3'], null, [], []],
			'register empty array'     => [[], null, [], []],
			'schema int'               => [null, 10, [], ['1_10', '2_10']],
			'schema array'             => [null, [11, 12], [], ['1_11', '3_12']],
			'schema array strict'      => [null, ['10'], [], []],
			'register and schema'      => [1, 10, [], ['1_10']],
			'register and schema miss' => [2, 11, [], []],
			'exclude one'              => [null, null, [['register' => 1, 'schema' => 10]], ['1_11', '2_10', '3_12']],
			'exclude cast strings'     => [null, null, [['register' => '2', 'schema' => '10']], ['1_10', '1_11', '3_12']],
			'exclude needs both keys'  => [null, null, [['register' => 1], ['schema' => 10]], ['1_10', '1_11', '2_10', '3_12']],
			'exclude null schema'      => [null, null, [['register' => 1, 'schema' => null]], ['1_10', '1_11', '2_10', '3_12']],
			'exclude with filter'      => [1, null, [['register' => 1, 'schema' => 11]], ['1_10']],
		];
	}//end filterCases()

	/**
	 * The register, schema and exclusion filters decide exactly which pairs are counted.
	 *
	 * @param mixed                $registerId The register filter.
	 * @param mixed                $schemaId   The schema filter.
	 * @param array                $exclude    The exclusions.
	 * @param array<int, string>   $expected   The pairs counted, as "r_s".
	 *
	 * @return void
	 *
	 * @dataProvider filterCases
	 */
	public function testStatisticsFiltersIncludeAndExcludePairs(mixed $registerId, mixed $schemaId, array $exclude, array $expected): void {
		$stats = $this->handler()->getStatistics(new StatisticsScope(registerId: $registerId, schemaId: $schemaId, exclude: $exclude));
		$this->assertSame($expected, $this->countedPairs());
		$total = 0;
		foreach ($expected as $pair) {
			[$r, $s] = explode('_', $pair);
			$total += ((int)$r * 100) + (int)$s;
		}

		$this->assertSame($total, $stats['total']);
		if ($expected !== []) {
			$this->assertAllFindsBypassAccess();
		}
	}//end testStatisticsFiltersIncludeAndExcludePairs()

	/**
	 * An unreadable register or a failing count leaves the pair out; successful reads are cached.
	 *
	 * @return void
	 */
	public function testStatisticsSkipAnUnreadableRegisterOrFailingCountAndCacheLookups(): void {
		$this->failingRegisters = [2];
		$this->failingCountSchemas = [12];
		$handler = $this->handler();
		$this->assertSame(110 + 111, $handler->getStatistics()['total']);
		$this->assertSame(['1_10', '1_11', '3_12'], $this->countedPairs());
		$this->assertSame(
			[['register', 1], ['schema', 10], ['schema', 11], ['register', 2], ['register', 3], ['schema', 12]],
			array_map(static fn (array $f): array => [$f[0], $f[1]], $this->finds)
		);

		// A second call reuses the cached entities; the failed register is asked again.
		$this->finds = [];
		$handler->getStatistics();
		$this->assertSame([['register', 2]], array_map(static fn (array $f): array => [$f[0], $f[1]], $this->finds));
		$this->assertAllFindsBypassAccess();
	}//end testStatisticsSkipAnUnreadableRegisterOrFailingCountAndCacheLookups()

	/**
	 * Without a count callback every pair counts zero, but entities are still read.
	 *
	 * @return void
	 */
	public function testStatisticsWithoutCallbackCountZero(): void {
		$this->assertSame(0, $this->handler(withCallback: false)->getStatistics()['total']);
		$this->assertCount(6, $this->finds);
		$this->assertAllFindsBypassAccess();
	}//end testStatisticsWithoutCallbackCountZero()

	/**
	 * Grouped statistics cover only the requested schemas, compared strictly, with uncached reads.
	 *
	 * @return void
	 */
	public function testGroupedBySchema(): void {
		$this->assertSame([], $this->handler()->getStatisticsGroupedBySchema([]));
		$this->assertSame([], $this->counts);
		$this->assertSame([], $this->finds);

		$this->failingRegisters = [3];
		$handler = $this->handler();
		$this->assertSame(
			[10 => ['total' => 110 + 210, 'size' => 0], 12 => ['total' => 0, 'size' => 0], 99 => ['total' => 0, 'size' => 0]],
			$handler->getStatisticsGroupedBySchema([10, 12, 99])
		);
		$this->assertSame(['1_10', '2_10'], $this->countedPairs());
		$this->assertSame(
			[['register', 1], ['schema', 10], ['register', 2], ['schema', 10], ['register', 3]],
			array_map(static fn (array $f): array => [$f[0], $f[1]], $this->finds)
		);
		$this->assertAllFindsBypassAccess();

		// Uncached: a second call reads the entities again; strict ids.
		$this->finds = [];
		$this->counts = [];
		$this->assertSame(['10' => ['total' => 0, 'size' => 0]], $handler->getStatisticsGroupedBySchema(['10']));
		$this->assertSame([], $this->counts);
	}//end testGroupedBySchema()

	/**
	 * A failing count leaves its pair out of the grouped total.
	 *
	 * @return void
	 */
	public function testGroupedBySchemaSkipsAFailingCount(): void {
		$this->failingCountSchemas = [11];
		$this->assertSame(
			[11 => ['total' => 0, 'size' => 0], 10 => ['total' => 320, 'size' => 0]],
			$this->handler()->getStatisticsGroupedBySchema([11, 10])
		);
		$this->assertAllFindsBypassAccess();
	}//end testGroupedBySchemaSkipsAFailingCount()

	/**
	 * Chart data follows the register/schema filter and leaves unreadable pairs out.
	 *
	 * @return void
	 */
	public function testChartsFilterAndBypassAccess(): void {
		$this->failingRegisters = [3];
		$handler = $this->handler();
		$this->assertSame(
			['labels' => ['R1', 'R2'], 'series' => [221, 210]],
			$handler->getRegisterChartData()
		);
		$this->assertSame(
			['labels' => ['S10'], 'series' => [320]],
			$handler->getSchemaChartData(schemaId: 10)
		);
		$this->assertSame(
			['labels' => ['S10', 'S11'], 'series' => [110, 111]],
			$handler->getSchemaChartData(registerId: 1)
		);
		$this->assertAllFindsBypassAccess();
	}//end testChartsFilterAndBypassAccess()
}//end class
