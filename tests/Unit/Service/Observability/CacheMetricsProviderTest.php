<?php

/**
 * The cache hit and miss counters (production-observability, "Cache hit ratio").
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-performance-baseline-metrics
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Service\Observability\CacheMetricsProvider;
use OCA\OpenRegister\Service\Observability\RequestMetricsStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\CacheMetricsProvider
 * @uses \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class CacheMetricsProviderTest extends TestCase {

	public function testPublishesHitsThenMissesPerCache(): void {
		$metrics = $this->provider(totals: ['object' => ['hit' => 9, 'miss' => 3], 'name' => ['hit' => 4, 'miss' => 1]])->metrics();

		$this->assertSame(['cache_hits_total', 'cache_misses_total'], array_map(static fn (MetricSample $m): string => $m->name, $metrics));
		$this->assertSame(['counter', 'counter'], array_map(static fn (MetricSample $m): string => $m->type, $metrics));
		$this->assertSame(
			[['labels' => ['cache' => 'name'], 'value' => 4], ['labels' => ['cache' => 'object'], 'value' => 9]],
			$metrics[0]->samples
		);
		$this->assertSame(
			[['labels' => ['cache' => 'name'], 'value' => 1], ['labels' => ['cache' => 'object'], 'value' => 3]],
			$metrics[1]->samples
		);
	}//end testPublishesHitsThenMissesPerCache()

	public function testTheObjectAndNameCachesArePublishedAtZeroBeforeAnyLookup(): void {
		$metrics = $this->provider(totals: [])->metrics();

		$zero = [['labels' => ['cache' => 'name'], 'value' => 0], ['labels' => ['cache' => 'object'], 'value' => 0]];
		$this->assertSame($zero, $metrics[0]->samples);
		$this->assertSame($zero, $metrics[1]->samples);
	}//end testTheObjectAndNameCachesArePublishedAtZeroBeforeAnyLookup()

	public function testUnreadableTotalsPublishNothingAndWarn(): void {
		$store = $this->createMock(RequestMetricsStore::class);
		$store->method('cacheTotals')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('db down'));

		$this->assertSame([], (new CacheMetricsProvider(store: $store, logger: $logger))->metrics());
	}//end testUnreadableTotalsPublishNothingAndWarn()

	/**
	 * @param array<string, array{hit: int, miss: int}> $totals The store totals.
	 *
	 * @return CacheMetricsProvider
	 */
	private function provider(array $totals): CacheMetricsProvider {
		$store = $this->createMock(RequestMetricsStore::class);
		$store->method('cacheTotals')->willReturn($totals);
		return new CacheMetricsProvider(store: $store, logger: $this->createMock(LoggerInterface::class));
	}//end provider()
}//end class
