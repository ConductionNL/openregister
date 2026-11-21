<?php

/**
 * The search and embedding metrics.
 *
 * `search_requests_total{type}`, the `search_duration_seconds{type}`
 * histogram and `embeddings_generated_total{status}`. They cannot be
 * declarative tableCounts: the search type label is derived from the
 * recorded metric type, the histogram needs bucket sums, and the embedding
 * outcome folds every non-success status into `failure`. So they take the
 * `provider` escape hatch, like `webhook_retry_queue_depth`.
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
use OCA\OpenRegister\Search\Service\SearchMetricsReader;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes search counts, search latency and embedding outcomes.
 *
 * @spec openspec/changes/production-observability
 */
final class SearchMetricsProvider implements IMetricsProvider {

	/**
	 * Constructor.
	 *
	 * @param SearchMetricsReader $reader The metrics aggregates.
	 * @param LoggerInterface     $logger The logger.
	 */
	public function __construct(
		private readonly SearchMetricsReader $reader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The search families, then the embedding family.
	 *
	 * A family whose query fails is left out and logged; the other one is
	 * still published.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function metrics(): array {
		$samples = [];

		try {
			$stats   = $this->reader->searchStats();
			$samples = [$this->requestCounter(stats: $stats), $this->durationHistogram(stats: $stats)];
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] search metrics unavailable: '.$e->getMessage());
		}

		try {
			$samples[] = $this->embeddingCounter(counts: $this->reader->embeddingCounts());
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] embedding metrics unavailable: '.$e->getMessage());
		}

		return $samples;
	}//end metrics()

	/**
	 * `search_requests_total{type}`.
	 *
	 * @param array<string, array{total: int, timed: int, sumMs: float, buckets: array<int, int>}> $stats Per type.
	 *
	 * @return MetricSample
	 */
	private function requestCounter(array $stats): MetricSample {
		$points = [];
		foreach ($stats as $type => $row) {
			$points[] = ['labels' => ['type' => $type], 'value' => $row['total']];
		}

		return new MetricSample(
			name: 'search_requests_total',
			type: 'counter',
			help: 'Total search requests by search type',
			samples: $points
		);
	}//end requestCounter()

	/**
	 * `search_duration_seconds{type}`: cumulative buckets, `+Inf`, sum and count of the timed searches.
	 *
	 * @param array<string, array{total: int, timed: int, sumMs: float, buckets: array<int, int>}> $stats Per type.
	 *
	 * @return MetricSample
	 */
	private function durationHistogram(array $stats): MetricSample {
		$points = [];
		foreach ($stats as $type => $row) {
			$points = [
				...$points,
				...HistogramPoints::points(labels: ['type' => $type], buckets: $row['buckets'], count: $row['timed'], sumMs: $row['sumMs']),
			];
		}

		return new MetricSample(
			name: 'search_duration_seconds',
			type: 'histogram',
			help: 'Search duration in seconds by search type',
			samples: $points
		);
	}//end durationHistogram()

	/**
	 * `embeddings_generated_total{status}`.
	 *
	 * @param array{success: int, failure: int} $counts The outcomes.
	 *
	 * @return MetricSample
	 */
	private function embeddingCounter(array $counts): MetricSample {
		return new MetricSample(
			name: 'embeddings_generated_total',
			type: 'counter',
			help: 'Total embedding generations by outcome',
			samples: [
				['labels' => ['status' => 'success'], 'value' => $counts['success']],
				['labels' => ['status' => 'failure'], 'value' => $counts['failure']],
			]
		);
	}//end embeddingCounter()
}//end class
