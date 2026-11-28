<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableSql;
use OCA\OpenRegister\Db\MagicMapper\StatisticsScope;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A statistics walk over the magic tables loads its registers in one query
 * and exposes the table list it read, so counting needs no existence probe per table.
 */
class MagicStatisticsHandlerPairWalkTest extends TestCase {

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * Fail the count of this register/schema pair, when set.
	 *
	 * @var array{0: int, 1: int}|null
	 */
	private ?array $failingPair = null;

	/**
	 * The handler under test.
	 *
	 * @var MagicStatisticsHandler
	 */
	private MagicStatisticsHandler $handler;

	/**
	 * The register ids each count was handed.
	 *
	 * @var array<int, int>
	 */
	private array $countedRegisters = [];

	protected function setUp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper = $schemaMapper;
		$schemaMapper->method('find')->willReturnCallback(
			static function (int|string $id): Schema {
				$schema = new Schema();
				$schema->setId((int) $id);
				return $schema;
			}
		);

		$this->handler = new MagicStatisticsHandler(
			sql: new MagicTableSql(db: $this->createMock(IDBConnection::class), config: $this->createMock(IConfig::class), logger: $logger),
			catalog: new SchemaCatalog(registers: $this->registerMapper, schemas: $schemaMapper, tables: $this->createMock(MagicMapperInterface::class)),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			schemaTypeConverter: new SchemaTypeConverter()
		);
		$this->handler->setCountCallback(
			function (array $query, Register $register, Schema $schema): int {
				if ($this->failingPair === [(int) $register->getId(), (int) $schema->getId()]) {
					throw new \RuntimeException('table gone');
				}

				$this->countedRegisters[] = (int) $register->getId();
				return 2;
			}
		);

		$memo = new \ReflectionProperty(MagicStatisticsHandler::class, 'magicTablesCache');
		$memo->setValue($this->handler, ['openregister_table_1_10', 'openregister_table_1_11', 'openregister_table_2_10']);
	}

	public function testThePairWalkLoadsItsRegistersInOneQuery(): void {
		$this->registerMapper->expects($this->once())->method('findMultipleOptimized')->with([1, 2])->willReturn(
			[1 => $this->register(id: 1), 2 => $this->register(id: 2)]
		);
		$this->registerMapper->expects($this->never())->method('find');

		$stats = $this->handler->getStatistics(new StatisticsScope());

		$this->assertSame(6, $stats['total']);
		$this->assertSame([1, 1, 2], $this->countedRegisters);
	}

	public function testARegisterTheBulkReadMissesIsReadOnItsOwn(): void {
		$this->registerMapper->method('findMultipleOptimized')->willReturn([1 => $this->register(id: 1)]);
		$this->registerMapper->expects($this->once())->method('find')->with(2)->willReturn($this->register(id: 2));

		$this->assertSame(6, $this->handler->getStatistics(new StatisticsScope())['total']);
	}

	public function testAFailedBulkReadFallsBackToOneReadPerRegister(): void {
		$this->registerMapper->method('findMultipleOptimized')->willThrowException(new \Exception('no bulk'));
		$this->registerMapper->expects($this->exactly(2))->method('find')->willReturnCallback(
			fn (int|string $id): Register => $this->register(id: (int) $id)
		);

		$this->assertSame(6, $this->handler->getStatistics(new StatisticsScope())['total']);
	}

	public function testTheChartWalkLoadsItsRegistersInOneQueryFirst(): void {
		$this->registerMapper->expects($this->once())->method('findMultipleOptimized')->with([1, 2])->willReturn(
			[1 => $this->register(id: 1), 2 => $this->register(id: 2)]
		);
		// Each pair still asks find(); RegisterMapper answers it from the bulk read (RegisterMapperBulkReadCacheTest).
		$this->registerMapper->method('find')->willReturnCallback(fn (int|string $id): Register => $this->register(id: (int) $id));

		$chart = $this->handler->getSchemaChartData();

		$this->assertSame(6, array_sum($chart['series']));
		$this->assertSame([1, 1, 2], $this->countedRegisters);
	}

	/**
	 * @spec openspec/changes/dashboard-chart-counts-once/specs/built-in-dashboards/spec.md
	 */
	public function testTheChartWalkLoadsItsSchemasInOneQueryFirst(): void {
		$this->registerMapper->method('find')->willReturnCallback(fn (int|string $id): Register => $this->register(id: (int) $id));
		$this->schemaMapper->expects($this->once())->method('preloadUnfiltered')->with([10, 11]);

		$this->assertSame(6, array_sum($this->handler->getRegisterChartData()['series']));
	}

	/**
	 * @spec openspec/changes/dashboard-chart-counts-once/specs/built-in-dashboards/spec.md
	 */
	public function testAFailedSchemaBulkReadLeavesEachSchemaToItsOwnRead(): void {
		$this->registerMapper->method('find')->willReturnCallback(fn (int|string $id): Register => $this->register(id: (int) $id));
		$this->schemaMapper->method('preloadUnfiltered')->willThrowException(new \Exception('no bulk'));

		$this->assertSame(6, array_sum($this->handler->getSchemaChartData()['series']));
	}

	/**
	 * @spec openspec/changes/dashboard-chart-counts-once/specs/built-in-dashboards/spec.md
	 */
	public function testBothObjectChartsCountEachTableOnceInsideAWindow(): void {
		$this->registerMapper->method('find')->willReturnCallback(fn (int|string $id): Register => $this->register(id: (int) $id));

		[$byRegister, $bySchema] = $this->handler->rememberPairCounts(
			fn (): array => [$this->handler->getRegisterChartData(), $this->handler->getSchemaChartData()]
		);

		$this->assertSame(6, array_sum($byRegister['series']));
		$this->assertSame(6, array_sum($bySchema['series']));
		$this->assertSame([1, 1, 2], $this->countedRegisters, 'each table counted once for both charts');

		$this->handler->getRegisterChartData();
		$this->assertCount(6, $this->countedRegisters, 'outside the window every chart counts again');
	}

	/**
	 * @spec openspec/changes/dashboard-chart-counts-once/specs/built-in-dashboards/spec.md
	 */
	public function testAPairWhoseCountFailsStaysOutOfBothChartsInsideAWindow(): void {
		$this->registerMapper->method('find')->willReturnCallback(fn (int|string $id): Register => $this->register(id: (int) $id));
		$this->failingPair = [2, 10];

		[$byRegister, $bySchema] = $this->handler->rememberPairCounts(
			fn (): array => [$this->handler->getRegisterChartData(), $this->handler->getSchemaChartData()]
		);

		$this->assertSame(4, array_sum($byRegister['series']));
		$this->assertSame(4, array_sum($bySchema['series']));
		$this->assertCount(1, $byRegister['labels'], 'register 2 has no countable table, so no bar');
	}

	public function testListedMagicTablesIsWhatTheWalkReadWithoutReadingAgain(): void {
		$this->assertSame(['openregister_table_1_10', 'openregister_table_1_11', 'openregister_table_2_10'], $this->handler->listedMagicTables());

		$this->handler->forgetMagicTableList();

		$this->assertSame([], $this->handler->listedMagicTables(), 'nothing listed yet: no catalogue query is made to answer');
	}

	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}
}//end class
