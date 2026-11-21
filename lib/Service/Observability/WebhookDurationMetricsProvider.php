<?php

/**
 * The webhook delivery latency histogram.
 *
 * `webhook_delivery_duration_seconds` aggregates the `duration_ms` each
 * delivery attempt records. A histogram needs bucket sums, which no
 * declarative kind computes, so it takes the `provider` escape hatch.
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes how long webhook deliveries take.
 *
 * @spec openspec/changes/production-observability
 */
final class WebhookDurationMetricsProvider implements IMetricsProvider {

	/**
	 * The metric name, without the app prefix the renderer adds.
	 */
	public const METRIC_NAME = 'webhook_delivery_duration_seconds';

	/**
	 * The bucket upper bounds, in milliseconds: 0.1 … 30 seconds.
	 */
	public const BUCKETS_MS = [100, 250, 500, 1000, 2500, 5000, 10000, 30000];

	/**
	 * Constructor.
	 *
	 * @param WebhookLogMapperInterface $logs   The webhook delivery log.
	 * @param LoggerInterface           $logger The logger.
	 */
	public function __construct(
		private readonly WebhookLogMapperInterface $logs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The histogram, or nothing when the log cannot be read.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function metrics(): array {
		try {
			$stats = $this->logs->deliveryDurationStats(boundsMs: self::BUCKETS_MS);
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] webhook delivery durations unavailable: '.$e->getMessage());
			return [];
		}

		return [
			new MetricSample(
				name: self::METRIC_NAME,
				type: 'histogram',
				help: 'Webhook delivery request duration in seconds',
				samples: HistogramPoints::points(labels: [], buckets: $stats['buckets'], count: $stats['timed'], sumMs: $stats['sumMs'])
			),
		];
	}//end metrics()
}//end class
