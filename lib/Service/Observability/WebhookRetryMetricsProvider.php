<?php

/**
 * The webhook retry queue gauge.
 *
 * `webhook_retry_queue_depth` counts failed deliveries whose retry is
 * scheduled but not yet due. It cannot be a declarative tableCount: the filter
 * DSL compares a column to a literal, and "not yet due" compares
 * `next_retry_at` with the clock, so it takes the `provider` escape hatch, the
 * same way `tasks_overdue_total` does.
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
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Publishes the number of queued webhook retries.
 *
 * @spec openspec/changes/production-observability
 */
final class WebhookRetryMetricsProvider implements IMetricsProvider {

	/**
	 * The metric name, without the app prefix the renderer adds.
	 */
	public const METRIC_NAME = 'webhook_retry_queue_depth';

	/**
	 * Constructor.
	 *
	 * @param WebhookLogMapperInterface $logs The webhook delivery log.
	 * @param ITimeFactory              $time The clock.
	 */
	public function __construct(
		private readonly WebhookLogMapperInterface $logs,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The retry queue depth, instance-wide.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function metrics(): array {
		$depth = $this->logs->countPendingRetries(now: $this->time->getDateTime());

		return [
			new MetricSample(
				name: self::METRIC_NAME,
				type: 'gauge',
				help: 'Failed webhook deliveries waiting for a scheduled retry',
				samples: [['labels' => [], 'value' => $depth]]
			),
		];
	}//end metrics()
}//end class
