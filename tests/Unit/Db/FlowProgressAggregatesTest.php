<?php

/**
 * The progress report's grouped aggregates on the run, step and task mappers.
 *
 * The builder is fluent and records what it is asked, so what is asserted is
 * which predicates and groupings each read adds, how the grouped rows become
 * counts, and that the number of queries does not grow with the rows.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\TaskInboxAnchor;
use OCA\OpenRegister\Db\TaskInboxAudience;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskInboxDueWindow;
use OCA\OpenRegister\Db\TaskInboxFilter;
use OCA\OpenRegister\Db\TaskInboxSelection;
use OCA\OpenRegister\Db\TaskMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowRunMapper
 * @covers \OCA\OpenRegister\Db\FlowRunStepMapper
 * @covers \OCA\OpenRegister\Db\TaskMapper
 * @covers \OCA\OpenRegister\Db\StoredMomentTrait
 * @uses \OCA\OpenRegister\Db\TaskInboxCriteria
 * @uses \OCA\OpenRegister\Db\TaskInboxAnchor
 * @uses \OCA\OpenRegister\Db\TaskInboxAudience
 * @uses \OCA\OpenRegister\Db\TaskInboxDueWindow
 * @uses \OCA\OpenRegister\Db\TaskInboxFilter
 * @uses \OCA\OpenRegister\Db\TaskInboxOrder
 * @uses \OCA\OpenRegister\Db\TaskInboxSelection
 */
class FlowProgressAggregatesTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * The organisation and window every read below counts over.
	 *
	 * @return array{organisation: string, from: DateTime, to: DateTime}
	 */
	private static function window(): array {
		return [
			'organisation' => 'org-a',
			'from' => new DateTime('2026-08-01T00:00:00+00:00'),
			'to' => new DateTime('2026-10-30T00:00:00+00:00'),
		];
	}

	private static function openTasks(?string $flowId, ?DateTime $overdueAt = null): TaskInboxCriteria {
		return new TaskInboxCriteria(
			audience: new TaskInboxAudience(uid: '', isAdmin: true, scope: TaskInboxCriteria::SCOPE_ALL),
			selection: new TaskInboxSelection(
				filter: new TaskInboxFilter(isTerminal: false),
				window: new TaskInboxDueWindow(overdueAt: $overdueAt),
				anchor: new TaskInboxAnchor(flowId: $flowId)
			)
		);
	}

	private function fromCount(): int {
		return count(array_filter($this->calls, static fn (array $call): bool => $call[0] === 'from'));
	}

	public function testRunsAreCountedByFlowAndStatusInTheWindowAndOrganisation(): void {
		$mapper = new FlowRunMapper(db: $this->connectionWith(rows: [
			['flow_id' => 'flow-1', 'status' => 'completed', 'total' => '3'],
			['flow_id' => 'flow-1', 'status' => 'suspended', 'total' => 2],
			['flow_id' => 'flow-2', 'status' => 'failed', 'total' => '1'],
		]));

		$counts = $mapper->countByStatusInWindow(...['flowIds' => ['flow-1', 'flow-2'], ...self::window()]);

		$this->assertSame(['flow-1' => ['completed' => 3, 'suspended' => 2], 'flow-2' => ['failed' => 1]], $counts);
		$this->assertTrue($this->saw('groupBy', 'flow_id'));
		$this->assertTrue($this->saw('expr.in', 'flow_id'));
		$this->assertTrue($this->saw('expr.eq', 'organisation'));
		$this->assertTrue($this->saw('expr.gte', 'created'));
		$this->assertTrue($this->saw('expr.lte', 'created'));
	}

	public function testNoFlowsReadNothing(): void {
		$db = $this->connectionWith(rows: [['flow_id' => 'x', 'status' => 'completed', 'total' => 9]]);

		$this->assertSame([], (new FlowRunMapper(db: $db))->countByStatusInWindow(...['flowIds' => [], ...self::window()]));
		$this->assertSame([], (new TaskMapper(db: $db))->countInboxByFlow(criteria: self::openTasks(null), flowIds: [], organisation: 'org-a'));
		$this->assertSame(0, $this->fromCount(), 'no query runs');
	}

	public function testCompletedRunStartsAreKeyedByRunAndSkipUnreadableRows(): void {
		$mapper = new FlowRunMapper(db: $this->connectionWith(rows: [
			['uuid' => 'run-1', 'created' => '2026-09-01 10:00:00'],
			['uuid' => '', 'created' => '2026-09-01 10:00:00'],
			['uuid' => 'run-2', 'created' => null],
		]));

		$starts = $mapper->completedRunStartsInWindow(...['flowId' => 'flow-1', ...self::window()]);

		$this->assertSame(['run-1'], array_keys($starts));
		$this->assertSame('2026-09-01 10:00:00', $starts['run-1']->format('Y-m-d H:i:s'));
		$this->assertTrue($this->saw('expr.eq', 'status'));
	}

	public function testStepAggregatesCountExecutionsFailuresAndAverageSuccessfulDuration(): void {
		$mapper = new FlowRunStepMapper(db: $this->connectionWith(rows: [
			['node_id' => 'notify', 'executions' => '5', 'failed' => '1', 'average_ms' => '250.5'],
			['node_id' => 'intake', 'executions' => 2, 'failed' => 0, 'average_ms' => null],
		]));

		$aggregates = $mapper->aggregatesByNode(...['flowId' => 'flow-1', ...self::window()]);

		$this->assertSame(
			[
				'notify' => ['executions' => 5, 'failed' => 1, 'averageMs' => 250.5],
				'intake' => ['executions' => 2, 'failed' => 0, 'averageMs' => null],
			],
			$aggregates
		);
		$this->assertTrue($this->saw('groupBy', 'node_id'));
		$this->assertTrue($this->saw('from', 'openregister_flow_runs'), 'the window bounds the runs');
		$this->assertTrue($this->saw('expr.eq', 'progressrun.organisation'));
		$this->assertTrue($this->saw('expr.in', 'run_uuid'));
		$this->assertNotEmpty(array_filter($this->functions, static fn (string $sql): bool => str_starts_with($sql, 'SUM(CASE WHEN `status`')));
		$this->assertNotEmpty(array_filter($this->functions, static fn (string $sql): bool => str_starts_with($sql, 'AVG(CASE WHEN `status`')));
	}

	public function testLastFinishIsReadPerCompletedRun(): void {
		$mapper = new FlowRunStepMapper(db: $this->connectionWith(rows: [
			['run_uuid' => 'run-1', 'last_finished' => '2026-09-01 12:00:00'],
			['run_uuid' => 'run-2', 'last_finished' => null],
		]));

		$finished = $mapper->lastFinishedByRun(...['flowId' => 'flow-1', ...self::window()]);

		$this->assertSame(['run-1'], array_keys($finished));
		$this->assertTrue($this->saw('groupBy', 'run_uuid'));
		$this->assertTrue($this->saw('expr.eq', 'progressrun.status'), 'only completed runs');
	}

	public function testOpenTasksAreCountedPerNodeWithTheInboxPredicates(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: [
			['node_id' => 'legal-review', 'total' => '2'],
			['node_id' => 'intake', 'total' => 1],
		]));

		$counts = $mapper->countInboxByNode(criteria: self::openTasks('flow-1', new DateTime('2026-10-01')), organisation: 'org-a');

		$this->assertSame(['legal-review' => 2, 'intake' => 1], $counts);
		$this->assertTrue($this->saw('groupBy', 'node_id'));
		$this->assertTrue($this->saw('expr.eq', 'organisation'));
		$this->assertTrue($this->saw('expr.eq', 'is_terminal'));
		$this->assertTrue($this->saw('expr.in', 'run_uuid'), 'the flow filter');
		$this->assertTrue($this->saw('expr.neq', 'performer_type'), 'the external exclusion');
		$this->assertNotEmpty(array_filter($this->functions, static fn (string $sql): bool => str_starts_with($sql, 'COALESCE(`due_at`, `expires_at`) <')), 'the one overdue rule');
	}

	public function testOpenTasksAreCountedPerFlowThroughTheRunJoin(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: [['flow_id' => 'flow-1', 'total' => '4']]));

		$counts = $mapper->countInboxByFlow(criteria: self::openTasks(null), flowIds: ['flow-1', 'flow-2'], organisation: 'org-a');

		$this->assertSame(['flow-1' => 4], $counts);
		$this->assertTrue($this->saw('innerJoin', 'task'));
		$this->assertTrue($this->saw('groupBy', 'progressrun.flow_id'));
		$this->assertTrue($this->saw('expr.in', 'progressrun.flow_id'));
		$this->assertTrue($this->saw('expr.eq', 'task.organisation'));
	}

	public function testFinishedTasksAreCountedAndAveragedPerNode(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: [
			['node_id' => 'intake', 'created' => '2026-09-01 10:00:00', 'completed_at' => '2026-09-01 12:00:00'],
			['node_id' => 'intake', 'created' => '2026-09-02 10:00:00', 'completed_at' => '2026-09-02 14:00:00'],
			['node_id' => 'legal-review', 'created' => null, 'completed_at' => '2026-09-03 10:00:00'],
		]));

		$finished = $mapper->finishedByNodeInWindow(...['flowId' => 'flow-1', ...self::window()]);

		$this->assertSame(
			[
				'intake' => ['done' => 2, 'averageSeconds' => 10800.0],
				'legal-review' => ['done' => 1, 'averageSeconds' => null],
			],
			$finished
		);
		$this->assertTrue($this->saw('expr.gte', 'completed_at'));
		$this->assertTrue($this->saw('expr.lte', 'completed_at'));
		$this->assertTrue($this->saw('expr.in', 'run_uuid'));
	}

	public function testOpenTasksPerFlowAreCountedInstanceWideLargestFirst(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: [
			['flow_id' => 'flow-small', 'total' => '1'],
			['flow_id' => 'flow-big', 'total' => '9'],
		]));

		$counts = $mapper->countOpenByFlow(overdueAt: new DateTime('2026-10-01'));

		$this->assertSame(['flow-big' => 9, 'flow-small' => 1], $counts);
		$this->assertTrue($this->saw('innerJoin', 'task'));
		$this->assertTrue($this->saw('expr.eq', 'task.is_terminal'));
		$this->assertTrue($this->saw('groupBy', 'progressrun.flow_id'));
		$this->assertFalse($this->saw('expr.eq', 'task.organisation'), 'an operator gauge, no tenant');
		$this->assertNotEmpty(array_filter($this->functions, static fn (string $sql): bool => str_starts_with($sql, 'COALESCE(`due_at`, `expires_at`) <')));
	}

	/**
	 * The number of queries a report runs is fixed: 10 or 1,000 runs, the same reads.
	 */
	public function testTheQueryCountDoesNotGrowWithTheRuns(): void {
		$counts = [];
		foreach ([10, 1000] as $runs) {
			$this->calls = [];
			$rows = [];
			for ($i = 0; $i < $runs; $i++) {
				$rows[] = ['uuid' => 'run-' . $i, 'run_uuid' => 'run-' . $i, 'created' => '2026-09-01 10:00:00', 'last_finished' => '2026-09-01 11:00:00', 'node_id' => 'n', 'completed_at' => '2026-09-01 11:00:00', 'total' => 1];
			}

			$db = $this->connectionWith(rows: $rows);
			(new FlowRunMapper(db: $db))->completedRunStartsInWindow(...['flowId' => 'flow-1', ...self::window()]);
			(new FlowRunStepMapper(db: $db))->lastFinishedByRun(...['flowId' => 'flow-1', ...self::window()]);
			(new TaskMapper(db: $db))->finishedByNodeInWindow(...['flowId' => 'flow-1', ...self::window()]);
			$counts[$runs] = $this->fromCount();
		}

		$this->assertSame($counts[10], $counts[1000]);
	}
}
