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

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Db\TaskMapper;

/**
 * Publishes `openregister_tasks_overdue_total`.
 *
 * @spec openspec/specs/apphost-observability/spec.md
 */
final class TaskMetricsProvider implements IMetricsProvider {

	/**
	 * The sample name, without the engine's `openregister_` prefix.
	 *
	 * It must match the `provider` descriptor's `name` in src/manifest.json:
	 * the engine renders what the provider returns, so the manifest entry
	 * documents the metric and this constant produces it.
	 */
	public const METRIC_NAME = 'tasks_overdue_total';

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
	 * @return MetricSample[] The overdue gauge.
	 *
	 * @spec openspec/specs/apphost-observability/spec.md
	 */
	public function metrics(): array {
		$overdue = $this->mapper->countOverdueOpen(now: $this->temporal->now());

		return [
			new MetricSample(
				name: self::METRIC_NAME,
				type: 'gauge',
				help: 'Open tasks whose effective deadline has passed',
				samples: [['labels' => [], 'value' => $overdue]]
			),
		];
	}//end metrics()
}//end class
