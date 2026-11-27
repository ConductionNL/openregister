<?php

/**
 * The overdue-task gauge, published once for the whole instance.
 *
 * Tasks are OpenRegister records, so the fleet's task gauges belong here and
 * are counted at the source rather than re-counted in every leaf app.
 * `tasks_total` is declarative (a tableCount over `openregister_tasks`,
 * grouped by `state`) and needs no code. `tasks_overdue_total` cannot be:
 * overdue is "COALESCE(due_at, expires_at) < now", and the declarative filter
 * DSL compares ONE column to a LITERAL, so it can express neither the
 * two-column effective deadline nor the clock. The `provider` escape hatch is
 * what the engine offers for exactly that, and taking it keeps the comparison
 * the one `TaskTemporalProjection` and `TaskMapper` already make instead of
 * approximating it with a second definition.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/apphost-observability/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Db\TaskMapper;

/**
 * Publishes `openregister_tasks_overdue_total` and the per-flow open and
 * overdue gauges `openregister_tasks_open_by_flow` and
 * `openregister_tasks_overdue_by_flow`.
 *
 * @spec openspec/specs/apphost-observability/spec.md
 */
final class TaskMetricsProvider implements TaskMetricsProviderInterface {

	/**
	 * The sample name, without the engine's `openregister_` prefix.
	 *
	 * It must match the `provider` descriptor's `name` in src/manifest.json:
	 * the engine renders what the provider returns, so the manifest entry
	 * documents the metric and this constant produces it.
	 */
	public const METRIC_NAME = 'tasks_overdue_total';

	/**
	 * Open tasks per flow, without the engine's `openregister_` prefix.
	 */
	public const OPEN_BY_FLOW = 'tasks_open_by_flow';

	/**
	 * Overdue open tasks per flow, without the engine's `openregister_` prefix.
	 */
	public const OVERDUE_BY_FLOW = 'tasks_overdue_by_flow';

	/**
	 * The most flows that get a series of their own; the rest share `flow="other"`.
	 */
	public const MAX_FLOW_SERIES = 100;

	/**
	 * The label value the flows beyond the cap are summed under.
	 */
	public const OTHER_FLOWS = 'other';

	/**
	 * Constructor.
	 *
	 * @param TaskMapper              $mapper   Counts the overdue open rows.
	 * @param TaskTemporalProjection  $temporal The one clock overdue is read against.
	 */
	public function __construct(
		private readonly TaskMapper $mapper,
		private readonly TaskTemporalProjection $temporal,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return MetricSample[] The overdue gauge and the two per-flow gauges.
	 *
	 * @spec openspec/specs/apphost-observability/spec.md
	 */
	public function metrics(): array {
		$now = $this->temporal->now();
		$overdue = $this->mapper->countOverdueOpen(now: $now);
		$openByFlow = $this->mapper->countOpenByFlow(overdueAt: null);
		$overdueByFlow = $this->mapper->countOpenByFlow(overdueAt: $now);

		// The flows with the most open tasks get a series; the same flows in
		// both gauges, so open and overdue chart side by side per flow.
		$charted = array_slice(array_keys($openByFlow), 0, self::MAX_FLOW_SERIES);

		return [
			new MetricSample(
				name: self::METRIC_NAME,
				type: 'gauge',
				help: 'Open tasks whose effective deadline has passed',
				samples: [['labels' => [], 'value' => $overdue]]
			),
			new MetricSample(
				name: self::OPEN_BY_FLOW,
				type: 'gauge',
				help: 'Open tasks per flow; flows beyond the 100 with the most open tasks are summed under flow="other"',
				samples: $this->perFlow(counts: $openByFlow, charted: $charted)
			),
			new MetricSample(
				name: self::OVERDUE_BY_FLOW,
				type: 'gauge',
				help: 'Open tasks past their effective deadline per flow; the flows of tasks_open_by_flow, the rest under flow="other"',
				samples: $this->perFlow(counts: $overdueByFlow, charted: $charted)
			),
		];
	}//end metrics()

	/**
	 * One sample per charted flow, then the rest summed under `flow="other"`
	 * when any flow is beyond the cap.
	 *
	 * @param array<string, int> $counts  Count keyed by flow id.
	 * @param array<int, string> $charted The flows that get a series, in order.
	 *
	 * @return array<int, array{labels: array<string, string>, value: int}> The samples.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-operators-can-chart-open-and-overdue-work-per-flow
	 */
	private function perFlow(array $counts, array $charted): array {
		$samples = [];
		foreach ($charted as $flowId) {
			$samples[] = ['labels' => ['flow' => (string)$flowId], 'value' => (int)($counts[$flowId] ?? 0)];
			unset($counts[$flowId]);
		}

		if ($counts !== []) {
			$samples[] = ['labels' => ['flow' => self::OTHER_FLOWS], 'value' => array_sum($counts)];
		}

		return $samples;
	}//end perFlow()
}//end class
