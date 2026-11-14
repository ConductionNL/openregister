<?php

/**
 * How a flow's work is going: runs, durations and per-step task counts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunStepMapperInterface;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;

/**
 * Adds up what the run, step and task tables already store; stores nothing.
 *
 * Every number is a grouped aggregate over an indexed path inside the window,
 * so the number of queries per call is fixed whatever the number of runs.
 * Counts and durations only: no titles, assignees or subjects.
 *
 * Task counts use the inbox's own predicates as an administrator reading
 * `scope=all` would, narrowed to the flow, the node and the organisation, so
 * a step's overdue count is the number of rows its `tasksHref` lists.
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
 */
class FlowProgressService {

	/**
	 * Node types that raise tasks for a person.
	 *
	 * @var array<int, string>
	 */
	private const HUMAN_NODE_TYPES = ['openregister.user-task', 'openregister.portal-task'];

	/**
	 * The task inbox route a step's counts link to.
	 *
	 * @var string
	 */
	private const TASKS_ROUTE = '/api/flow-tasks';

	/**
	 * Constructor.
	 *
	 * @param FlowRunMapperInterface $runs     Run counts by status and completed run starts.
	 * @param FlowRunStepMapperInterface $steps    Node executions and last finishes.
	 * @param TaskMapper             $tasks    Open, overdue and finished tasks.
	 * @param TaskTemporalProjection $temporal  The one clock overdue is read against.
	 * @param FlowProgressTaskReads  $taskReads The inbox reads task counts use.
	 */
	public function __construct(
		private readonly FlowRunMapperInterface $runs,
		private readonly FlowRunStepMapperInterface $steps,
		private readonly TaskMapper $tasks,
		private readonly TaskTemporalProjection $temporal,
		private readonly FlowProgressTaskReads $taskReads = new FlowProgressTaskReads(),
	) {
	}//end __construct()

	/**
	 * The progress of one flow over a window.
	 *
	 * @param Flow     $flow The flow, already read with the caller's access.
	 * @param DateTime $from Count from this moment.
	 * @param DateTime $to   Count up to this moment.
	 *
	 * @return array<string, mixed> The runs, the run duration and one row per step.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
	 */
	public function forFlow(Flow $flow, DateTime $from, DateTime $to): array {
		$flowId = (string)$flow->getUuid();
		$organisation = (string)$flow->getOrganisation();
		$now = $this->temporal->now();

		$byStatus = ($this->runs->countByStatusInWindow(flowIds: [$flowId], organisation: $organisation, from: $from, to: $to)[$flowId] ?? []);
		$counts = [
			'open' => $this->tasks->countInboxByNode(criteria: $this->taskReads->openTasks(flowId: $flowId, overdueAt: null), organisation: $organisation),
			'overdue' => $this->tasks->countInboxByNode(criteria: $this->taskReads->openTasks(flowId: $flowId, overdueAt: $now), organisation: $organisation),
			'finished' => $this->tasks->finishedByNodeInWindow(flowId: $flowId, organisation: $organisation, from: $from, to: $to),
			'executions' => $this->steps->aggregatesByNode(flowId: $flowId, organisation: $organisation, from: $from, to: $to),
		];

		return [
			'flow' => $flowId,
			'window' => ['from' => $from->format(DateTime::ATOM), 'to' => $to->format(DateTime::ATOM)],
			'runs' => ['total' => array_sum($byStatus), 'byStatus' => $byStatus],
			'duration' => $this->runDuration(flowId: $flowId, organisation: $organisation, between: [$from, $to]),
			'steps' => $this->stepRows(flow: $flow, counts: $counts),
		];
	}//end forFlow()

	/**
	 * Per-flow totals for a page of flows: runs by status, open and overdue tasks.
	 *
	 * @param array<int, Flow> $flows The flows, already listed with the caller's access.
	 * @param DateTime         $from  Count from this moment.
	 * @param DateTime         $to    Count up to this moment.
	 *
	 * @return array<int, array<string, mixed>> One entry per flow, in the given order.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-all-flows-can-be-compared-on-one-list
	 */
	public function forFlows(array $flows, DateTime $from, DateTime $to): array {
		if ($flows === []) {
			return [];
		}

		$flowIds = array_map(static fn (Flow $flow): string => (string)$flow->getUuid(), $flows);
		$organisation = (string)$flows[0]->getOrganisation();
		$byStatus = $this->runs->countByStatusInWindow(flowIds: $flowIds, organisation: $organisation, from: $from, to: $to);
		$open = $this->tasks->countInboxByFlow(
			criteria: $this->taskReads->openTasks(flowId: null, overdueAt: null),
			flowIds: $flowIds,
			organisation: $organisation
		);
		$overdue = $this->tasks->countInboxByFlow(
			criteria: $this->taskReads->openTasks(flowId: null, overdueAt: $this->temporal->now()),
			flowIds: $flowIds,
			organisation: $organisation
		);

		$rows = [];
		foreach ($flows as $flow) {
			$flowId = (string)$flow->getUuid();
			$statuses = ($byStatus[$flowId] ?? []);
			$rows[] = [
				'flow' => $flowId,
				'name' => (string)$flow->getName(),
				'runs' => ['total' => array_sum($statuses), 'byStatus' => $statuses],
				'tasks' => ['open' => ($open[$flowId] ?? 0), 'overdue' => ($overdue[$flowId] ?? 0)],
			];
		}

		return $rows;
	}//end forFlows()

	/**
	 * The average duration of the window's completed runs, from creation to
	 * the last step's finish. A completed run without step rows (pruned by
	 * retention) is counted as unknown, never averaged as zero.
	 *
	 * @param string                          $flowId       The flow.
	 * @param string                          $organisation The organisation.
	 * @param array{0: DateTime, 1: DateTime} $between      The window's first and last moment.
	 *
	 * @return array{averageSeconds: float|null, measured: int, durationUnknown: int}
	 */
	private function runDuration(string $flowId, string $organisation, array $between): array {
		[$from, $to] = $between;
		$starts = $this->runs->completedRunStartsInWindow(flowId: $flowId, organisation: $organisation, from: $from, to: $to);
		$finishes = $this->steps->lastFinishedByRun(flowId: $flowId, organisation: $organisation, from: $from, to: $to);

		$seconds = 0;
		$measured = 0;
		$unknown = 0;
		foreach ($starts as $runUuid => $created) {
			if (isset($finishes[$runUuid]) === false) {
				$unknown++;
				continue;
			}

			$measured++;
			$seconds += max(0, $finishes[$runUuid]->getTimestamp() - $created->getTimestamp());
		}

		$average = null;
		if ($measured > 0) {
			$average = (float)($seconds / $measured);
		}

		return ['averageSeconds' => $average, 'measured' => $measured, 'durationUnknown' => $unknown];
	}//end runDuration()

	/**
	 * One row per step: the flow's nodes in definition order, then any node
	 * that has numbers but is no longer in the definition.
	 *
	 * @param Flow                               $flow   The flow.
	 * @param array<string, array<string, mixed>> $counts The open, overdue, finished and executions aggregates.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function stepRows(Flow $flow, array $counts): array {
		$nodes = $this->definedNodes(flow: $flow);
		foreach ($counts as $aggregate) {
			foreach (array_keys($aggregate) as $nodeId) {
				$nodeId = (string)$nodeId;
				if ($nodeId !== '' && isset($nodes[$nodeId]) === false) {
					$nodes[$nodeId] = ['name' => $nodeId, 'type' => null];
				}
			}
		}

		$rows = [];
		foreach ($nodes as $nodeId => $node) {
			$rows[] = $this->stepRow(flowId: (string)$flow->getUuid(), nodeId: (string)$nodeId, node: $node, counts: $counts);
		}

		return $rows;
	}//end stepRows()

	/**
	 * The flow definition's nodes, by id, with their display name and type.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array<string, array{name: string, type: string|null}>
	 */
	private function definedNodes(Flow $flow): array {
		$nodes = [];
		foreach (($flow->getNodes() ?? []) as $node) {
			if (is_array($node) === false || trim((string)($node['id'] ?? '')) === '') {
				continue;
			}

			$nodeId = (string)$node['id'];
			$name = trim((string)($node['label'] ?? ($node['name'] ?? '')));
			if ($name === '') {
				$name = $nodeId;
			}

			$type = null;
			if (is_string($node['type'] ?? null) === true) {
				$type = $node['type'];
			}

			$nodes[$nodeId] = ['name' => $name, 'type' => $type];
		}

		return $nodes;
	}//end definedNodes()

	/**
	 * One step's numbers and the inbox links behind its task counts.
	 *
	 * @param string                              $flowId The flow.
	 * @param string                              $nodeId The node.
	 * @param array{name: string, type: ?string}  $node   Its display name and type.
	 * @param array<string, array<string, mixed>> $counts The aggregates.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function stepRow(string $flowId, string $nodeId, array $node, array $counts): array {
		$open = (int)($counts['open'][$nodeId] ?? 0);
		$overdue = (int)($counts['overdue'][$nodeId] ?? 0);
		$finished = ($counts['finished'][$nodeId] ?? ['done' => 0, 'averageSeconds' => null]);
		$executions = ($counts['executions'][$nodeId] ?? ['executions' => 0, 'failed' => 0, 'averageMs' => null]);

		$kind = 'automatic';
		if (in_array($node['type'], self::HUMAN_NODE_TYPES, true) === true || $open > 0 || $finished['done'] > 0) {
			$kind = 'human';
		}

		return [
			'nodeId' => $nodeId,
			'name' => $node['name'],
			'type' => $node['type'],
			'kind' => $kind,
			'open' => $open,
			'done' => (int)$finished['done'],
			'overdue' => $overdue,
			'averageTaskSeconds' => $finished['averageSeconds'],
			'executions' => (int)$executions['executions'],
			'failed' => (int)$executions['failed'],
			'averageDurationMs' => $executions['averageMs'],
			'tasksHref' => $this->tasksHref(flowId: $flowId, nodeId: $nodeId, filters: ['overdue' => 'true', 'isTerminal' => 'false']),
			'openTasksHref' => $this->tasksHref(flowId: $flowId, nodeId: $nodeId, filters: ['isTerminal' => 'false']),
			'doneTasksHref' => $this->tasksHref(flowId: $flowId, nodeId: $nodeId, filters: ['isTerminal' => 'true']),
		];
	}//end stepRow()

	/**
	 * The task inbox link for one step's tasks.
	 *
	 * @param string                $flowId  The flow.
	 * @param string                $nodeId  The node.
	 * @param array<string, string> $filters The extra inbox filters.
	 *
	 * @return string The link, relative to the app's base.
	 */
	private function tasksHref(string $flowId, string $nodeId, array $filters): string {
		$query = array_merge(['scope' => TaskInboxCriteria::SCOPE_ALL, 'flow' => $flowId, 'node' => $nodeId], $filters);

		return self::TASKS_ROUTE . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}//end tasksHref()
}//end class
