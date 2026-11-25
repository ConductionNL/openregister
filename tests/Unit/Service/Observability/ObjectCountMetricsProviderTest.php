<?php

/**
 * The live object count per register and schema (production-observability, "Object counts by register and schema").
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-register-schema-and-object-count-metrics
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Service\Observability\ObjectCountMetricsProvider;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\ObjectCountMetricsProvider
 * @uses \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class ObjectCountMetricsProviderTest extends TestCase {

	/**
	 * Live rows per prefixed magic table name.
	 *
	 * @var array<string, int>
	 */
	private array $magicRows = [];

	/**
	 * Grouped rows of the legacy table.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $legacyRows = [];

	/**
	 * Whether the legacy table exists.
	 *
	 * @var boolean
	 */
	private bool $legacyExists = false;

	/**
	 * Register ids and schema ids that exist.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $ids = ['openregister_registers' => [], 'openregister_schemas' => []];

	/**
	 * Every SQL string prepared.
	 *
	 * @var string[]
	 */
	private array $prepared = [];

	/**
	 * What the legacy query filtered on.
	 *
	 * @var string[]
	 */
	private array $legacyWhere = [];

	/**
	 * The configured table prefix.
	 *
	 * @var string
	 */
	private string $prefix = 'oc_';

	/**
	 * Whether the count query throws.
	 *
	 * @var boolean
	 */
	private bool $unionFails = false;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	public function testTheTableListingStaysInsideTheInstancesOwnDatabase(): void {
		$this->ids = ['openregister_registers' => [1], 'openregister_schemas' => [5]];
		$this->magicRows = ['oc_openregister_table_1_5' => 3];

		$this->metric();

		$this->assertStringContainsString('FROM information_schema.tables', $this->prepared[0]);
		$this->assertStringEndsWith('WHERE table_name LIKE ? AND table_schema = DATABASE()', $this->prepared[0]);
	}//end testTheTableListingStaysInsideTheInstancesOwnDatabase()

	public function testCountsTheLiveRowsOfEveryMagicTableByRegisterAndSchema(): void {
		$this->ids = ['openregister_registers' => [1, 2], 'openregister_schemas' => [5, 6]];
		$this->magicRows = ['oc_openregister_table_1_5' => 500, 'oc_openregister_table_1_6' => 200, 'oc_openregister_table_2_5' => 7];

		$sample = $this->metric();

		$this->assertSame('objects_total', $sample->name);
		$this->assertSame('gauge', $sample->type);
		$this->assertSame(
			[
				['labels' => ['register' => '1', 'schema' => '5'], 'value' => 500],
				['labels' => ['register' => '1', 'schema' => '6'], 'value' => 200],
				['labels' => ['register' => '2', 'schema' => '5'], 'value' => 7],
			],
			$sample->samples
		);
		$union = $this->prepared[1];
		$this->assertStringContainsString(
			'SELECT 1 AS register_id, 5 AS schema_id, COUNT(*) AS cnt FROM oc_openregister_table_1_5 WHERE _deleted IS NULL',
			$union
		);
		$this->assertSame(2, substr_count($union, ' UNION ALL '));
	}//end testCountsTheLiveRowsOfEveryMagicTableByRegisterAndSchema()

	public function testATableOfAMissingRegisterOrSchemaIsLeftOut(): void {
		$this->ids = ['openregister_registers' => [1], 'openregister_schemas' => [5]];
		$this->magicRows = ['oc_openregister_table_1_5' => 3, 'oc_openregister_table_9_5' => 4, 'oc_openregister_table_1_8' => 2];

		$this->assertSame([['labels' => ['register' => '1', 'schema' => '5'], 'value' => 3]], $this->metric()->samples);
	}//end testATableOfAMissingRegisterOrSchemaIsLeftOut()

	public function testAnEmptyTableAndAnUnrelatedTableAreNotPublished(): void {
		$this->ids = ['openregister_registers' => [1], 'openregister_schemas' => [5, 6]];
		$this->magicRows = [
			'oc_openregister_table_1_5' => 0,
			'oc_openregister_table_1_6' => 1,
			'oc_openregister_table_1_6_old' => 99,
			'other_openregister_table_1_5' => 50,
		];

		$this->assertSame([['labels' => ['register' => '1', 'schema' => '6'], 'value' => 1]], $this->metric()->samples);
		$this->assertStringNotContainsString('_old', $this->prepared[1]);
		$this->assertStringNotContainsString('other_', $this->prepared[1]);
	}//end testAnEmptyTableAndAnUnrelatedTableAreNotPublished()

	public function testTheLegacyRowsThatAreNotDeletedAreAdded(): void {
		$this->ids = ['openregister_registers' => [1], 'openregister_schemas' => [5, 6]];
		$this->magicRows = ['oc_openregister_table_1_5' => 10];
		$this->legacyExists = true;
		$this->legacyRows = [['register' => '1', 'schema' => '5', 'cnt' => '2'], ['register' => '1', 'schema' => '6', 'cnt' => '4']];

		$this->assertSame(
			[
				['labels' => ['register' => '1', 'schema' => '5'], 'value' => 12],
				['labels' => ['register' => '1', 'schema' => '6'], 'value' => 4],
			],
			$this->metric()->samples
		);
		$this->assertSame(['deleted IS NULL'], $this->legacyWhere);
	}//end testTheLegacyRowsThatAreNotDeletedAreAdded()

	public function testTablesAreCountedInBatches(): void {
		$this->ids = ['openregister_registers' => [1], 'openregister_schemas' => range(1, ObjectCountMetricsProvider::BATCH_SIZE + 1)];
		foreach (range(1, ObjectCountMetricsProvider::BATCH_SIZE + 1) as $schema) {
			$this->magicRows['oc_openregister_table_1_'.$schema] = 1;
		}

		$this->assertCount(ObjectCountMetricsProvider::BATCH_SIZE + 1, $this->metric()->samples);
		$this->assertCount(3, $this->prepared, 'one table listing and two batches');
		$this->assertSame(0, substr_count($this->prepared[2], 'UNION ALL'));
	}//end testTablesAreCountedInBatches()

	public function testTheConfiguredPrefixIsUsed(): void {
		$this->prefix = 'nc_';
		$this->ids = ['openregister_registers' => [1], 'openregister_schemas' => [5]];
		$this->magicRows = ['nc_openregister_table_1_5' => 8, 'oc_openregister_table_1_5' => 99];

		$this->assertSame([['labels' => ['register' => '1', 'schema' => '5'], 'value' => 8]], $this->metric()->samples);
	}//end testTheConfiguredPrefixIsUsed()

	public function testAFailedCountPublishesNoSamplesAndWarns(): void {
		$this->ids = ['openregister_registers' => [1], 'openregister_schemas' => [5]];
		$this->magicRows = ['oc_openregister_table_1_5' => 8];
		$this->unionFails = true;
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('object counts could not be read'));

		$this->assertSame([], $this->metric()->samples);
	}//end testAFailedCountPublishesNoSamplesAndWarns()

	public function testAPrefixThatIsNotAnIdentifierPublishesNoSamples(): void {
		$this->prefix = 'oc; DROP';
		$this->ids = ['openregister_registers' => [1], 'openregister_schemas' => [5]];
		$this->magicRows = ['oc; DROPopenregister_table_1_5' => 8];
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame([], $this->metric()->samples);
		$this->assertSame([], $this->prepared);
	}//end testAPrefixThatIsNotAnIdentifierPublishesNoSamples()

	private function metric(): MetricSample {
		$metrics = $this->provider()->metrics();
		$this->assertCount(1, $metrics);
		return $metrics[0];
	}//end metric()

	private function provider(): ObjectCountMetricsProvider {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('dbtableprefix', 'oc_')->willReturnCallback(fn (): string => $this->prefix);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(fn (string $table): bool => $table === 'openregister_objects' && $this->legacyExists);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		$db->method('prepare')->willReturnCallback(fn (string $sql): IPreparedStatement => $this->statement(sql: $sql));

		return new ObjectCountMetricsProvider(db: $db, config: $config, logger: $this->logger);
	}//end provider()

	private function statement(string $sql): IPreparedStatement {
		$this->prepared[] = $sql;
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturnCallback(
			function (mixed $params = null) use ($sql): IResult {
				if (str_contains($sql, 'information_schema') === true) {
					$this->assertSame([$this->prefix.'openregister_table_%'], $params);
					$names = array_keys($this->magicRows);
					return $this->rows(rows: array_map(static fn (string $name): array => ['table_name' => $name], $names));
				}

				if ($this->unionFails === true) {
					throw new RuntimeException('boom');
				}

				$arm = '/SELECT (\d+) AS register_id, (\d+) AS schema_id, COUNT\(\*\) AS cnt FROM (\S+) WHERE _deleted IS NULL/';
				preg_match_all($arm, $sql, $arms, PREG_SET_ORDER);
				return $this->rows(
					rows: array_map(
						fn (array $arm): array => ['register_id' => $arm[1], 'schema_id' => $arm[2], 'cnt' => (string) $this->magicRows[$arm[3]]],
						$arms
					)
				);
			}
		);

		return $statement;
	}//end statement()

	private function queryBuilder(): IQueryBuilder {
		$table = '';
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('isNull')->willReturnCallback(static fn (string $column): string => $column.' IS NULL');
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('select')->willReturnSelf();
		$qb->method('selectAlias')->willReturnSelf();
		$qb->method('groupBy')->willReturnSelf();
		$qb->method('from')->willReturnCallback(
			function (string $from) use (&$table, $qb): IQueryBuilder {
				$table = $from;
				return $qb;
			}
		);
		$qb->method('where')->willReturnCallback(
			function (mixed $where) use ($qb): IQueryBuilder {
				$this->legacyWhere[] = (string) $where;
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$table): IResult {
				if ($table === 'openregister_objects') {
					return $this->rows(rows: $this->legacyRows);
				}

				return $this->rows(rows: array_map(static fn (int $id): array => ['id' => $id], $this->ids[$table]));
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return IResult
	 */
	private function rows(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		return $result;
	}//end rows()
}//end class
