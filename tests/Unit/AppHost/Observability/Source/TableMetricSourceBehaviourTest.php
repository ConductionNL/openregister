<?php

/**
 * Tests for TableMetricSource beyond the basics in MetricSourceTest: HELP
 * defaulting, every filter operator, unsafe columns, query failures and
 * label fallbacks for grouped rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Observability\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Observability\Source;

use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives collect() against a query builder that records what it is asked.
 */
class TableMetricSourceBehaviourTest extends TestCase {

	/** @var array<int, string> Expression calls, as "op:column:param". */
	private array $where = [];

	/** @var array<int, mixed> Values bound as named parameters. */
	private array $params = [];

	/** @var array<int, string> Columns added via addSelect / addGroupBy. */
	private array $grouped = [];

	/** @var IDBConnection&MockObject */
	private IDBConnection $db;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IResult&MockObject */
	private IResult $result;

	/** @var IQueryBuilder&MockObject */
	private IQueryBuilder $qb;

	protected function setUp(): void {
		$this->result = $this->createMock(IResult::class);

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'like'] as $op) {
			$expr->method($op)->willReturnCallback(
				fn ($column, $param): string => $op.':'.$column.':'.$param
			);
		}

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));

		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->qb->method('func')->willReturn($func);
		$this->qb->method('expr')->willReturn($expr);
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('addSelect')->willReturnCallback(
			function (string $column): IQueryBuilder {
				$this->grouped[] = 'select:'.$column;
				return $this->qb;
			}
		);
		$this->qb->method('addGroupBy')->willReturnCallback(
			function (string $column): IQueryBuilder {
				$this->grouped[] = 'group:'.$column;
				return $this->qb;
			}
		);
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value): string {
				$this->params[] = $value;
				return ':p'.(count($this->params) - 1);
			}
		);
		$this->qb->method('andWhere')->willReturnCallback(
			function (string $clause): IQueryBuilder {
				$this->where[] = $clause;
				return $this->qb;
			}
		);
		$this->qb->method('executeQuery')->willReturn($this->result);

		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturn($this->qb);

		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function source(): TableMetricSource {
		return new TableMetricSource(db: $this->db, logger: $this->logger);
	}

	/**
	 * @param array<string, mixed> $source
	 */
	private function descriptor(array $source, ?string $help=null): MetricDescriptor {
		return new MetricDescriptor(
			name: 'rows',
			type: 'counter',
			kind: 'tableCount',
			source: ['kind' => 'tableCount'] + $source,
			help: $help
		);
	}

	public function testHelpDefaultsToTheTableNameWhenTheDescriptorHasNone(): void {
		$this->db->expects($this->once())->method('tableExists')->with('app_items')->willReturn(false);

		$samples = $this->source()->collect('app', $this->descriptor(source: ['table' => 'app_items']));

		$this->assertCount(1, $samples);
		$this->assertSame('Row count of app_items', $samples[0]->help);
		$this->assertSame('rows', $samples[0]->name);
		$this->assertSame('counter', $samples[0]->type);
		$this->assertSame([], $samples[0]->samples);
	}

	public function testExplicitHelpIsKept(): void {
		$this->db->method('tableExists')->willReturn(false);

		$samples = $this->source()->collect('app', $this->descriptor(source: ['table' => 'app_items'], help: 'Items'));

		$this->assertSame('Items', $samples[0]->help);
	}

	public function testMissingTableKeyIsRefusedBeforeAnyQuery(): void {
		$this->db->expects($this->never())->method('tableExists');

		$this->expectExceptionMessage('tableCount table "" is not allowlisted.');
		$this->source()->collect('app', $this->descriptor(source: []));
	}

	public function testUppercaseTableIsRefused(): void {
		$this->db->expects($this->never())->method('tableExists');

		$this->expectExceptionMessage('tableCount table "App_Items" is not allowlisted.');
		$this->source()->collect('app', $this->descriptor(source: ['table' => 'App_Items']));
	}

	public function testEveryFilterOperatorBecomesAWhereClauseAndUnknownOnesAreIgnored(): void {
		$this->db->method('tableExists')->willReturn(true);
		$this->result->expects($this->once())->method('fetchOne')->willReturn('9');
		$this->result->expects($this->once())->method('closeCursor');
		$this->logger->expects($this->never())->method('warning');

		$samples = $this->source()->collect(
			'app',
			$this->descriptor(
				source: [
					'table'  => 'app_items',
					'filter' => [
						'status' => ['eq' => 'open', 'neq' => 'gone', 'like' => 'o%', 'between' => 'ignored'],
						'size'   => ['lt' => 10, 'lte' => 11, 'gt' => 1, 'gte' => 2],
					],
				]
			)
		);

		$this->assertSame([['labels' => [], 'value' => 9]], $samples[0]->samples);
		$this->assertSame(
			[
				'eq:status::p0',
				'neq:status::p1',
				'like:status::p2',
				'lt:size::p4',
				'lte:size::p5',
				'gt:size::p6',
				'gte:size::p7',
			],
			$this->where
		);
		// The unknown operator still binds its parameter, but adds no clause.
		$this->assertSame(['open', 'gone', 'o%', 'ignored', 10, 11, 1, 2], $this->params);
	}

	public function testUnsafeFilterColumnIsLoggedAndYieldsNoSamples(): void {
		$this->db->method('tableExists')->willReturn(true);
		$this->qb->expects($this->never())->method('executeQuery');
		$this->logger->expects($this->once())->method('warning')->with(
			'[AppHost\\Metrics] tableCount "rows" (app myapp) failed: Unsafe column identifier "a;b".',
			$this->anything()
		);

		$samples = $this->source()->collect(
			'myapp',
			$this->descriptor(source: ['table' => 'app_items', 'filter' => ['a;b' => ['eq' => 1]]])
		);

		$this->assertCount(1, $samples);
		$this->assertSame([], $samples[0]->samples);
		$this->assertSame('Row count of app_items', $samples[0]->help);
	}

	public function testUnsafeGroupByColumnIsLoggedAndYieldsNoSamples(): void {
		$this->db->method('tableExists')->willReturn(true);
		$this->qb->expects($this->never())->method('executeQuery');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('Unsafe column identifier "x y"'),
			$this->anything()
		);

		$samples = $this->source()->collect(
			'myapp',
			$this->descriptor(source: ['table' => 'app_items', 'groupBy' => ['x y']])
		);

		$this->assertSame([], $samples[0]->samples);
		$this->assertSame([], $this->grouped);
	}

	public function testAQueryFailureIsLoggedAndYieldsNoSamples(): void {
		$this->db->method('tableExists')->willReturn(true);
		$this->result->method('fetchOne')->willThrowException(new RuntimeException('connection lost'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[AppHost\\Metrics] tableCount "rows" (app myapp) failed: connection lost',
			$this->anything()
		);

		$samples = $this->source()->collect('myapp', $this->descriptor(source: ['table' => 'app_items']));

		$this->assertSame([], $samples[0]->samples);
	}

	public function testGroupedRowsRenameMappedColumnsKeepOthersAndFallBackForEmptyValues(): void {
		$this->db->method('tableExists')->willReturn(true);
		$this->result->expects($this->once())->method('fetchAll')->willReturn(
			[
				['cnt' => '4', 'status' => 'open', 'owner' => 'ann'],
				['status' => '', 'owner' => null],
			]
		);
		$this->result->expects($this->once())->method('closeCursor');
		$this->logger->expects($this->never())->method('warning');

		$samples = $this->source()->collect(
			'app',
			$this->descriptor(
				source: [
					'table'         => 'app_items',
					'groupBy'       => ['k1' => 'status', 'k2' => 'owner'],
					'labelDefaults' => ['status' => 'unknown'],
					'labelMap'      => ['owner' => 'who'],
					'filter'        => ['status' => ['neq' => 'gone']],
				]
			)
		);

		$this->assertSame(
			[
				['labels' => ['status' => 'open', 'who' => 'ann'], 'value' => 4],
				// Empty string takes the default; NULL with no default becomes ''; missing cnt is 0.
				['labels' => ['status' => 'unknown', 'who' => ''], 'value' => 0],
			],
			$samples[0]->samples
		);
		$this->assertSame(['select:status', 'group:status', 'select:owner', 'group:owner'], $this->grouped);
		$this->assertSame(['neq:status::p0'], $this->where);
	}

	public function testGroupedQueryWithNoRowsYieldsAnEmptyFamily(): void {
		$this->db->method('tableExists')->willReturn(true);
		$this->result->method('fetchAll')->willReturn([]);

		$samples = $this->source()->collect(
			'app',
			$this->descriptor(source: ['table' => 'app_items', 'groupBy' => ['status']])
		);

		$this->assertCount(1, $samples);
		$this->assertSame([], $samples[0]->samples);
		$this->assertSame('rows', $samples[0]->name);
	}

	public function testKindIsTableCount(): void {
		$this->assertSame('tableCount', $this->source()->kind());
	}
}//end class
