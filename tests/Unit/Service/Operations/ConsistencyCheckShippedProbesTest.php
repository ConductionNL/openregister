<?php

/**
 * Unit tests for ConsistencyCheckService — the shipped probes and their bookkeeping.
 *
 * ConsistencyCheckServiceTest proves a writing probe is refused. These tests
 * run the three probes the service ships with against a recording query
 * builder and assert what each one reads (table, columns, the anti-join or
 * the stale-run window), and pin the rest: a probe whose table is missing is
 * reported unavailable instead of clean, an unknown slug answers null, and
 * the repair plan names the table and the action.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConsistencyCheckShippedProbesTest extends TestCase {

	/**
	 * Per query builder created: the calls it received, as "method args".
	 *
	 * @var array<int, string[]>
	 */
	private array $calls = [];

	/** @var array<int, mixed> Named parameter values, in order. */
	private array $params = [];

	/** @var array<string, array|\Throwable> Rows (or a failure) per table the probe reads. */
	private array $tables = [];

	/** @var array<int, int> The row cap each executed probe asked for. */
	private array $limits = [];

	private IDBConnection $db;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->builder());
	}

	private function builder(): IQueryBuilder {
		$index = count($this->calls);
		$this->calls[$index] = [];
		$from = '';
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['isNotNull', 'notIn', 'eq', 'lt'] as $op) {
			$expr->method($op)->willReturnCallback(fn (...$args) => $op . '(' . implode(',', array_map('strval', array_filter($args, static fn ($a) => $a !== null))) . ')');
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('getConnection')->willReturn($this->db);
		$qb->method('createNamedParameter')->willReturnCallback(function ($value) {
			$this->params[] = $value;
			return ':p' . (count($this->params) - 1);
		});
		$qb->method('createFunction')->willReturnCallback(function (string $sql) {
			$function = $this->createMock(IQueryFunction::class);
			$function->method('__toString')->willReturn('(' . $sql . ')');
			return $function;
		});
		foreach (['select', 'from', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($qb, $method, $index, &$from) {
				$this->calls[$index][] = $method . ' ' . implode(',', array_map('strval', array_filter($args, static fn ($a) => $a !== null)));
				if ($method === 'from') {
					$from = $args[0];
				}
				return $qb;
			});
		}
		$qb->method('getSQL')->willReturnCallback(function () use (&$from) {
			return 'SELECT * FROM ' . $from;
		});
		$qb->method('setMaxResults')->willReturnCallback(function (int $max) use ($qb) {
			$this->limits[] = $max;
			return $qb;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$from) {
			$rows = $this->tables[$from] ?? [];
			if ($rows instanceof \Throwable) {
				throw $rows;
			}
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn($rows);
			$result->expects($this->once())->method('closeCursor');
			return $result;
		});
		return $qb;
	}

	public function testItShipsThreeProbesUnderStableSlugs(): void {
		$service = new ConsistencyCheckService($this->db);

		$this->assertSame(['orphan-relations', 'orphan-follows', 'runs-never-closed'], $service->slugs());
	}

	public function testTheOrphanRelationsProbeAntiJoinsRelationTargetsAgainstObjects(): void {
		$this->tables = ['openregister_object_relations' => [['id' => 1, 'uuid' => 'r1', 'source_uuid' => 'a', 'target_uuid' => 'gone']]];

		$finding = (new ConsistencyCheckService($this->db))->checkOne('orphan-relations');

		$this->assertSame(1, $finding['count']);
		$this->assertSame([['id' => 1, 'uuid' => 'r1', 'source_uuid' => 'a', 'target_uuid' => 'gone']], $finding['objects']);
		$this->assertNull($finding['unavailable']);
		$this->assertSame(
			[
				['select id,uuid,source_uuid,target_uuid', 'from openregister_object_relations', 'where isNotNull(target_uuid)', 'andWhere notIn(target_uuid,(SELECT * FROM openregister_objects))'],
				['select uuid', 'from openregister_objects'],
			],
			$this->calls
		);
		$this->assertSame([ConsistencyCheckService::MAX_ROWS_PER_PROBE], $this->limits);
	}

	public function testTheOrphanFollowsProbeReadsTheWatchersTable(): void {
		$finding = (new ConsistencyCheckService($this->db))->checkOne('orphan-follows');

		$this->assertSame(0, $finding['count']);
		$this->assertSame(
			['select id,object_uuid,user_id', 'from openregister_watchers', 'where isNotNull(object_uuid)', 'andWhere notIn(object_uuid,(SELECT * FROM openregister_objects))'],
			$this->calls[0]
		);
	}

	public function testTheStaleRunsProbeLooksForRunsStillRunningAfterADay(): void {
		$before = time();
		(new ConsistencyCheckService($this->db))->checkOne('runs-never-closed');

		$this->assertSame(
			['select id,job_class,started', 'from openregister_job_runs', 'where eq(outcome,:p0)', 'andWhere lt(started,:p1)'],
			$this->calls[0]
		);
		$this->assertSame('running', $this->params[0]);
		$this->assertInstanceOf(DateTime::class, $this->params[1]);
		$age = $before - $this->params[1]->getTimestamp();
		$this->assertGreaterThanOrEqual(86400 - 5, $age);
		$this->assertLessThanOrEqual(86400 + 5, $age);
	}

	public function testAProbeOverAMissingTableIsUnavailableNotClean(): void {
		$this->tables = [
			'openregister_watchers' => new RuntimeException('Table openregister_watchers does not exist'),
			'openregister_job_runs' => [['id' => 9]],
		];

		$report = (new ConsistencyCheckService($this->db))->check();

		$this->assertSame(3, $report['checked']);
		$this->assertSame(1, $report['inconsistent'], 'An unavailable probe is not counted as inconsistent.');
		$follows = $report['findings'][1];
		$this->assertSame(
			['slug' => 'orphan-follows', 'title' => 'Follows on an object that is gone', 'description' => 'A follow row whose object no longer exists in the object table.', 'count' => 0, 'objects' => [], 'unavailable' => 'Table openregister_watchers does not exist'],
			$follows
		);
		$this->assertSame(1, $report['findings'][2]['count']);
	}

	public function testAnUnknownSlugHasNoFindingAndNoRepairPlan(): void {
		$service = new ConsistencyCheckService($this->db);

		$this->assertNull($service->checkOne('nope'));
		$this->assertNull($service->repairPlan('nope'));
		$this->assertSame([], $this->calls, 'An unknown slug runs no query.');
	}

	public function testTheRepairPlanNamesTheTableAndTheAction(): void {
		$service = new ConsistencyCheckService($this->db);

		$this->assertSame(
			['slug' => 'runs-never-closed', 'table' => 'openregister_job_runs', 'action' => 'Mark the runs failed, naming the worker that did not come back.'],
			$service->repairPlan('runs-never-closed')
		);
		$this->assertSame(
			['slug' => 'orphan-relations', 'table' => 'openregister_object_relations', 'action' => 'Delete the relation rows.'],
			$service->repairPlan('orphan-relations')
		);
	}
}
