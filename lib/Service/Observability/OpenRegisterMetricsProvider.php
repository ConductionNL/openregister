<?php

/**
 * OpenRegister's own metrics provider: every provider-backed gauge at once.
 *
 * The observability engine resolves ONE IMetricsProvider per app and runs it
 * for each `provider` descriptor in the manifest. OpenRegister has more than
 * one gauge the declarative kinds cannot express, so this class joins them
 * under that one alias, in a fixed order, and the manifest keeps a single
 * `provider` descriptor.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Kernel\Contracts\Integrations\TaskGaugeInterface;

/**
 * Joins the task, webhook, search, storage, request, object count, cache, import and alert metrics under OpenRegister's provider alias.
 *
 * @spec openspec/changes/production-observability
 */
final class OpenRegisterMetricsProvider implements IMetricsProvider {

	/**
	 * Constructor.
	 *
	 * @param TaskGaugeInterface             $tasks     The overdue-task gauge.
	 * @param WebhookRetryMetricsProvider    $webhooks  The webhook retry queue gauge.
	 * @param SearchMetricsProvider          $search    The search and embedding metrics.
	 * @param WebhookDurationMetricsProvider $durations The webhook delivery latency histogram.
	 * @param DatabaseHealthMetricsProvider  $database  The database connection health gauge.
	 * @param StorageGrowthMetricsProvider   $storage   The storage growth threshold.
	 * @param RequestMetricsProvider         $requests  The request, request duration and error metrics.
	 * @param ObjectCountMetricsProvider     $objects   The live object count per register and schema.
	 * @param CacheMetricsProvider           $caches    The cache hit and miss counters.
	 * @param ImportJobMetricsProvider       $imports   The progress of running import jobs.
	 * @param RequestAlertMetricsProvider    $alerts    The error rate and response time alert outcomes.
	 */
	public function __construct(
		private readonly TaskGaugeInterface $tasks,
		private readonly WebhookRetryMetricsProvider $webhooks,
		private readonly SearchMetricsProvider $search,
		private readonly WebhookDurationMetricsProvider $durations,
		private readonly DatabaseHealthMetricsProvider $database,
		private readonly StorageGrowthMetricsProvider $storage,
		private readonly RequestMetricsProvider $requests,
		private readonly ObjectCountMetricsProvider $objects,
		private readonly CacheMetricsProvider $caches,
		private readonly ImportJobMetricsProvider $imports,
		private readonly RequestAlertMetricsProvider $alerts,
	) {
	}//end __construct()

	/**
	 * The samples of every joined provider, in order.
	 *
	 * Tasks, webhook retries, search, webhook durations, database health,
	 * storage growth, requests and errors, object counts, cache hits and misses, import progress, then the request alerts.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function metrics(): array {
		return [
			...$this->tasks->metrics(),
			...$this->webhooks->metrics(),
			...$this->search->metrics(),
			...$this->durations->metrics(),
			...$this->database->metrics(),
			...$this->storage->metrics(),
			...$this->requests->metrics(),
			...$this->objects->metrics(),
			...$this->caches->metrics(),
			...$this->imports->metrics(),
			...$this->alerts->metrics(),
		];
	}//end metrics()
}//end class
