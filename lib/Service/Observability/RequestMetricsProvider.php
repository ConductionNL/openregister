<?php

/**
 * The request counter, the request duration histogram and the error counter.
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes `requests_total`, `request_duration_seconds` and `errors_total` from the flushed request rows.
 *
 * The counts are those of the last flush (at most a minute behind); the
 * `endpoint` label is the URL template of the route. A family whose query
 * fails is left out and logged; the others are still published.
 *
 * @spec openspec/changes/production-observability
 */
class RequestMetricsProvider implements IMetricsProvider {

	/**
	 * Constructor.
	 *
	 * @param RequestMetricsStore $store     The flushed rows.
	 * @param RouteTemplates      $templates The route → endpoint map.
	 * @param LoggerInterface     $logger    The logger.
	 */
	public function __construct(
		private readonly RequestMetricsStore $store,
		private readonly RouteTemplates $templates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The request counter, the duration histogram, then the error counter.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function metrics(): array {
		$samples = [];

		try {
			$requests  = $this->store->requestTotals();
			$samples[] = $this->requestCounter(requests: $requests);
			$samples[] = $this->durationHistogram(requests: $requests, buckets: $this->store->bucketTotals());
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] request metrics unavailable: '.$e->getMessage());
		}

		try {
			$samples[] = $this->errorCounter(errors: $this->store->errorTotals());
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] error metrics unavailable: '.$e->getMessage());
		}

		return $samples;
	}//end metrics()

	/**
	 * `requests_total{method,endpoint,status}`; routes sharing a template are added up.
	 *
	 * @param array<int, array{method: string, route: string, status: string, count: int, sumMs: int}> $requests The request rows.
	 *
	 * @return MetricSample
	 */
	private function requestCounter(array $requests): MetricSample {
		$points = [];
		foreach ($requests as $row) {
			$labels = ['method' => $row['method'], 'endpoint' => $this->templates->endpointOf(route: $row['route']), 'status' => $row['status']];
			$id     = implode("\n", $labels);
			$points[$id] ??= ['labels' => $labels, 'value' => 0];
			$points[$id]['value'] += $row['count'];
		}

		return new MetricSample(
			name: 'requests_total',
			type: 'counter',
			help: 'Total API requests by method, endpoint and status',
			samples: array_values($points)
		);
	}//end requestCounter()

	/**
	 * `request_duration_seconds{method,endpoint}`: cumulative buckets, `+Inf`, sum and count.
	 *
	 * @param array<int, array{method: string, route: string, status: string, count: int, sumMs: int}> $requests The request rows (for the sum).
	 * @param array<int, array{method: string, route: string, bucket: string, count: int}>               $buckets  The bucket rows.
	 *
	 * @return MetricSample
	 */
	private function durationHistogram(array $requests, array $buckets): MetricSample {
		$series = [];
		foreach ($buckets as $row) {
			$id = $this->seriesId(method: $row['method'], route: $row['route'], series: $series);
			if ($row['bucket'] === RequestMetricsRecorder::BUCKET_INF) {
				$series[$id]['overflow'] += $row['count'];
				continue;
			}

			$bound = (int) $row['bucket'];
			if (array_key_exists($bound, $series[$id]['counts']) === true) {
				$series[$id]['counts'][$bound] += $row['count'];
			}
		}

		foreach ($requests as $row) {
			$id = $this->seriesId(method: $row['method'], route: $row['route'], series: $series);
			$series[$id]['sumMs'] += $row['sumMs'];
		}

		$points = [];
		foreach ($series as $one) {
			$cumulative = [];
			$running    = 0;
			foreach ($one['counts'] as $bound => $count) {
				$running           += $count;
				$cumulative[$bound] = $running;
			}

			$points = [
				...$points,
				...HistogramPoints::points(labels: $one['labels'], buckets: $cumulative, count: $running + $one['overflow'], sumMs: (float) $one['sumMs']),
			];
		}

		return new MetricSample(
			name: 'request_duration_seconds',
			type: 'histogram',
			help: 'API request duration in seconds by method and endpoint',
			samples: $points
		);
	}//end durationHistogram()

	/**
	 * The id of a method and endpoint series, created empty when new.
	 *
	 * @param string                              $method The method.
	 * @param string                              $route  The route key.
	 * @param array<string, array<string, mixed>> $series The series so far (labels, counts, overflow, sumMs).
	 *
	 * @return string
	 */
	private function seriesId(string $method, string $route, array &$series): string {
		$labels = ['method' => $method, 'endpoint' => $this->templates->endpointOf(route: $route)];
		$id     = implode("\n", $labels);
		$series[$id] ??= [
			'labels' => $labels,
			'counts' => array_fill_keys(RequestMetricsRecorder::DURATION_BUCKETS_MS, 0),
			'overflow' => 0,
			'sumMs' => 0,
		];

		return $id;
	}//end seriesId()

	/**
	 * `errors_total{type}`: `database`, `validation` and `other` always present.
	 *
	 * @param array<string, int> $errors The error totals by type.
	 *
	 * @return MetricSample
	 */
	private function errorCounter(array $errors): MetricSample {
		$errors = [...['database' => 0, 'validation' => 0, 'other' => 0], ...$errors];
		$points = [];
		foreach ($errors as $type => $count) {
			$points[] = ['labels' => ['type' => (string) $type], 'value' => $count];
		}

		return new MetricSample(
			name: 'errors_total',
			type: 'counter',
			help: 'Total API errors by type',
			samples: $points
		);
	}//end errorCounter()
}//end class
