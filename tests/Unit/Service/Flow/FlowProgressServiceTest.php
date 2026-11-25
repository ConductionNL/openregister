<?php

/**
 * FlowProgressService: composing the run, step and task aggregates.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
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

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunStepMapperInterface;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Flow\FlowProgressService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowProgressService
 * @covers \OCA\OpenRegister\Service\Flow\FlowProgressTaskReads
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\TaskInboxCriteria
 * @uses \OCA\OpenRegister\Db\TaskInboxAnchor
 * @uses \OCA\OpenRegister\Db\TaskInboxAudience
 * @uses \OCA\OpenRegister\Db\TaskInboxDueWindow
 * @uses \OCA\OpenRegister\Db\TaskInboxFilter
 * @uses \OCA\OpenRegister\Db\TaskInboxOrder
 * @uses \OCA\OpenRegister\Db\TaskInboxSelection
 * @uses \OCA\OpenRegister\Service\Task\TaskTemporalProjection
 */
class FlowProgressServiceTest extends TestCase {

	private FlowRunMapperInterface&MockObject $runs;

	private FlowRunStepMapperInterface&MockObject $steps;

	private TaskMapper&MockObject $tasks;

	protected function setUp(): void {
		parent::setUp();
		$this->runs = $this->createMock(FlowRunMapperInterface::class);
		$this->steps = $this->createMock(FlowRunStepMapperInterface::class);
		$this->tasks = $this->createMock(TaskMapper::class);
	}

	private function service(): FlowProgressService {
		return new FlowProgressService(runs: $this->runs, steps: $this->steps, tasks: $this->tasks, temporal: new TaskTemporalProjection());
	}

	private function permitFlow(string $uuid = 'flow-1', string $name = 'Permit'): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setName($name);
		$flow->setOrganisation('org-a');
		$flow->setNodes([
			['id' => 'intake', 'type' => 'openregister.user-task', 'label' => 'Intake'],
			['id' => 'legal-review', 'type' => 'openregister.user-task', 'label' => 'Legal review'],
			['id' => 'notify', 'type' => 'openregister.send-email', 'name' => 'Notify applicant'],
			['label' => 'no id, skipped'],
		]);

		return $flow;
	}

	private static function from(): DateTime {
		return new DateTime('2026-08-01T00:00:00+00:00');
	}

	private static function to(): DateTime {
		return new DateTime('2026-10-30T00:00:00+00:00');
	}

	/**
	 * The spec's scenario: two runs waiting at "Legal review", one task past its due date.
	 */
	public function testATeamLeadSeesWhereWorkIsStuck(): void {
		$this->runs->method('countByStatusInWindow')->willReturn(['flow-1' => ['completed' => 1, 'suspended' => 2]]);
		$this->runs->method('completedRunStartsInWindow')->willReturn(['run-1' => new DateTime('2026-09-01T10:00:00+00:00')]);
		$this->steps->method('lastFinishedByRun')->willReturn(['run-1' => new DateTime('2026-09-01T12:00:00+00:00')]);
		$this->steps->method('aggregatesByNode')->willReturn(['notify' => ['executions' => 1, 'failed' => 0, 'averageMs' => 120.0]]);
		$this->tasks->method('finishedByNodeInWindow')->willReturn(['intake' => ['done' => 3, 'averageSeconds' => 3600.0]]);
		$this->tasks->method('countInboxByNode')->willReturnCallback(
			static fn (TaskInboxCriteria $criteria): array => $criteria->overdueAt === null ? ['legal-review' => 2] : ['legal-review' => 1]
		);

		$report = $this->service()->forFlow(flow: $this->permitFlow(), from: self::from(), to: self::to());

		$this->assertSame('flow-1', $report['flow']);
		$this->assertSame(['total' => 3, 'byStatus' => ['completed' => 1, 'suspended' => 2]], $report['runs']);
		$this->assertSame(['averageSeconds' => 7200.0, 'measured' => 1, 'durationUnknown' => 0], $report['duration']);
		$this->assertSame(['intake', 'legal-review', 'notify'], array_column($report['steps'], 'nodeId'));

		[$intake, $legal, $notify] = $report['steps'];
		$this->assertSame(['Legal review', 'human', 2, 1, 0], [$legal['name'], $legal['kind'], $legal['open'], $legal['overdue'], $legal['done']]);
		$this->assertSame('/api/flow-tasks?scope=all&flow=flow-1&node=legal-review&overdue=true&isTerminal=false', $legal['tasksHref']);
		$this->assertSame('/api/flow-tasks?scope=all&flow=flow-1&node=legal-review&isTerminal=false', $legal['openTasksHref']);
		$this->assertSame('/api/flow-tasks?scope=all&flow=flow-1&node=legal-review&isTerminal=true', $legal['doneTasksHref']);
		$this->assertSame([3, 3600.0], [$intake['done'], $intake['averageTaskSeconds']]);
		$this->assertSame(['Notify applicant', 'automatic', 1, 0, 120.0], [$notify['name'], $notify['kind'], $notify['executions'], $notify['failed'], $notify['averageDurationMs']]);
	}

	/**
	 * Task counts read the inbox as an administrator over scope=all, narrowed
	 * to the flow, its open tasks and the flow's organisation.
	 */
	public function testTaskCountsUseTheInboxReadOfTheFlowsOrganisation(): void {
		$seen = [];
		$this->tasks->expects($this->exactly(2))->method('countInboxByNode')->willReturnCallback(
			function (TaskInboxCriteria $criteria, string $organisation) use (&$seen): array {
				$seen[] = [$criteria->isAdmin, $criteria->scope, $criteria->flowId, $criteria->isTerminal, $criteria->overdueAt !== null, $organisation];
				return [];
			}
		);
		$this->runs->method('countByStatusInWindow')->willReturnCallback(
			function (array $flowIds, string $organisation): array {
				$this->assertSame(['flow-1'], $flowIds);
				$this->assertSame('org-a', $organisation);
				return [];
			}
		);

		$this->service()->forFlow(flow: $this->permitFlow(), from: self::from(), to: self::to());

		$this->assertSame(
			[[true, 'all', 'flow-1', false, false, 'org-a'], [true, 'all', 'flow-1', false, true, 'org-a']],
			$seen
		);
	}

	/**
	 * A completed run whose steps were pruned is duration unknown, not zero.
	 */
	public function testAPrunedRunIsDurationUnknown(): void {
		$this->runs->method('completedRunStartsInWindow')->willReturn([
			'run-1' => new DateTime('2026-09-01T10:00:00+00:00'),
			'run-2' => new DateTime('2026-09-02T10:00:00+00:00'),
		]);
		$this->steps->method('lastFinishedByRun')->willReturn(['run-1' => new DateTime('2026-09-01T11:00:00+00:00')]);

		$report = $this->service()->forFlow(flow: $this->permitFlow(), from: self::from(), to: self::to());

		$this->assertSame(['averageSeconds' => 3600.0, 'measured' => 1, 'durationUnknown' => 1], $report['duration']);
	}

	public function testNoCompletedRunHasNoAverage(): void {
		$this->runs->method('completedRunStartsInWindow')->willReturn([]);
		$this->steps->method('lastFinishedByRun')->willReturn([]);

		$report = $this->service()->forFlow(flow: $this->permitFlow(), from: self::from(), to: self::to());

		$this->assertSame(['averageSeconds' => null, 'measured' => 0, 'durationUnknown' => 0], $report['duration']);
		$this->assertSame(['total' => 0, 'byStatus' => []], $report['runs']);
	}

	/**
	 * A node that has numbers but left the definition still gets a row, named by its id.
	 */
	public function testARemovedNodeKeepsItsRow(): void {
		$this->steps->method('aggregatesByNode')->willReturn(['old-step' => ['executions' => 4, 'failed' => 4, 'averageMs' => null]]);

		$steps = $this->service()->forFlow(flow: $this->permitFlow(), from: self::from(), to: self::to())['steps'];

		$this->assertSame(['intake', 'legal-review', 'notify', 'old-step'], array_column($steps, 'nodeId'));
		$this->assertSame(['old-step', null, 'automatic', 4], [$steps[3]['name'], $steps[3]['type'], $steps[3]['kind'], $steps[3]['failed']]);
	}

	/**
	 * The report carries counts and durations, never content.
	 */
	public function testTheReportCarriesNoContent(): void {
		$this->tasks->method('countInboxByNode')->willReturn(['legal-review' => 1]);

		$report = $this->service()->forFlow(flow: $this->permitFlow(), from: self::from(), to: self::to());
		$keys = array_keys($report['steps'][1]);

		foreach (['title', 'assignee', 'subject', 'description'] as $content) {
			$this->assertNotContains($content, $keys);
		}
	}

	public function testFlowsAreComparedOnTotals(): void {
		$flows = [$this->permitFlow('flow-1', 'Permit'), $this->permitFlow('flow-2', 'Complaint')];
		$this->runs->expects($this->once())->method('countByStatusInWindow')->willReturn(['flow-1' => ['completed' => 2, 'failed' => 1]]);
		$this->tasks->expects($this->exactly(2))->method('countInboxByFlow')->willReturnCallback(
			function (TaskInboxCriteria $criteria, array $flowIds, string $organisation): array {
				$this->assertSame(['flow-1', 'flow-2'], $flowIds);
				$this->assertSame('org-a', $organisation);
				$this->assertNull($criteria->flowId);
				return $criteria->overdueAt === null ? ['flow-1' => 5, 'flow-2' => 1] : ['flow-1' => 2];
			}
		);

		$rows = $this->service()->forFlows(flows: $flows, from: self::from(), to: self::to());

		$this->assertSame(
			[
				['flow' => 'flow-1', 'name' => 'Permit', 'runs' => ['total' => 3, 'byStatus' => ['completed' => 2, 'failed' => 1]], 'tasks' => ['open' => 5, 'overdue' => 2]],
				['flow' => 'flow-2', 'name' => 'Complaint', 'runs' => ['total' => 0, 'byStatus' => []], 'tasks' => ['open' => 1, 'overdue' => 0]],
			],
			$rows
		);
	}

	public function testNoFlowsReadNothing(): void {
		$this->runs->expects($this->never())->method('countByStatusInWindow');
		$this->tasks->expects($this->never())->method('countInboxByFlow');

		$this->assertSame([], $this->service()->forFlows(flows: [], from: self::from(), to: self::to()));
	}
}
