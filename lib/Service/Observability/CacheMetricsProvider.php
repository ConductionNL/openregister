<?php

/**
 * Cache hit and miss counters of the object and name caches.
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
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-performance-baseline-metrics
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes `cache_hits_total{cache}` and `cache_misses_total{cache}`.
 *
 * `Service\Object\CacheHandler` counts each object and name lookup in APCu through
 * `RequestMetricsRecorder`, and the request-driven flush adds them to cumulative
 * `openregister_metrics` rows, so the hit ratio is `hits / (hits + misses)` per cache.
 * The `object` and `name` caches are always published, at zero before any lookup.
 * Totals that cannot be read publish neither family, with a warning.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-performance-baseline-metrics
 */
class CacheMetricsProvider implements IMetricsProvider {

	/**
	 * The caches always published.
	 *
	 * @var string[]
	 */
	public const CACHES = ['object', 'name'];

	/**
	 * Constructor.
	 *
	 * @param RequestMetricsStore $store  The flushed rows.
	 * @param LoggerInterface     $logger The logger.
	 */
	public function __construct(
		private readonly RequestMetricsStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The hit counter, then the miss counter.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-performance-baseline-metrics
	 */
	public function metrics(): array {
		try {
			$totals = $this->store->cacheTotals();
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] cache metrics unavailable: '.$e->getMessage());
			return [];
		}

		foreach (self::CACHES as $cache) {
			$totals[$cache] ??= ['hit' => 0, 'miss' => 0];
		}

		ksort($totals);

		return [
			$this->counter(name: 'cache_hits_total', help: 'Cache lookups answered from the cache, by cache', totals: $totals, outcome: 'hit'),
			$this->counter(name: 'cache_misses_total', help: 'Cache lookups that went to the database, by cache', totals: $totals, outcome: 'miss'),
		];
	}//end metrics()

	/**
	 * One counter family over the caches.
	 *
	 * @param string                                    $name    The family name.
	 * @param string                                    $help    Its help text.
	 * @param array<string, array{hit: int, miss: int}> $totals  The totals per cache.
	 * @param string                                    $outcome `hit` or `miss`.
	 *
	 * @return MetricSample
	 */
	private function counter(string $name, string $help, array $totals, string $outcome): MetricSample {
		$samples = [];
		foreach ($totals as $cache => $counts) {
			$samples[] = ['labels' => ['cache' => (string) $cache], 'value' => $counts[$outcome]];
		}

		return new MetricSample(name: $name, type: 'counter', help: $help, samples: $samples);
	}//end counter()
}//end class
