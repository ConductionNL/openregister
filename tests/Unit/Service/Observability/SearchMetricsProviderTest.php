<?php

/**
 * The search and embedding metrics provider.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCA\OpenRegister\Search\Service\SearchMetricsReader;
use OCA\OpenRegister\Service\Observability\SearchMetricsProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\SearchMetricsProvider
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricSample
 * @uses   \OCA\OpenRegister\Service\Observability\HistogramPoints
 * @uses   \OCA\OpenRegister\AppHost\Observability\PrometheusRenderer
 */
final class SearchMetricsProviderTest extends TestCase {

	/**
	 * Request counts per type, a histogram with seconds bounds, and embedding outcomes.
	 *
	 * @return void
	 */
	public function testPublishesCountsHistogramAndEmbeddingOutcomes(): void {
		$reader = $this->createMock(SearchMetricsReader::class);
		$reader->expects($this->once())->method('searchStats')->willReturn([
			'keyword' => ['total' => 100, 'timed' => 98, 'sumMs' => 4900.0, 'buckets' => [50 => 40, 100 => 70, 250 => 90, 500 => 95, 1000 => 97, 2500 => 98, 5000 => 98]],
			'semantic' => ['total' => 30, 'timed' => 30, 'sumMs' => 15000.0, 'buckets' => [50 => 0, 100 => 1, 250 => 5, 500 => 10, 1000 => 20, 2500 => 29, 5000 => 30]],
		]);
		$reader->expects($this->once())->method('embeddingCounts')->willReturn(['success' => 500, 'failure' => 12]);

		$metrics = (new SearchMetricsProvider(reader: $reader, logger: $this->createMock(LoggerInterface::class)))->metrics();

		$this->assertSame(['search_requests_total', 'search_duration_seconds', 'embeddings_generated_total'], array_map(static fn (MetricSample $m): string => $m->name, $metrics));
		$this->assertSame('counter', $metrics[0]->type);
		$this->assertSame(
			[['labels' => ['type' => 'keyword'], 'value' => 100], ['labels' => ['type' => 'semantic'], 'value' => 30]],
			$metrics[0]->samples
		);
		$this->assertSame('histogram', $metrics[1]->type);
		$this->assertCount(20, $metrics[1]->samples);
		$this->assertSame(['labels' => ['type' => 'keyword', 'le' => '0.05'], 'value' => 40, 'suffix' => '_bucket'], $metrics[1]->samples[0]);
		$this->assertSame(['labels' => ['type' => 'keyword', 'le' => '1.0'], 'value' => 97, 'suffix' => '_bucket'], $metrics[1]->samples[4]);
		$this->assertSame(['labels' => ['type' => 'keyword', 'le' => '+Inf'], 'value' => 98, 'suffix' => '_bucket'], $metrics[1]->samples[7]);
		$this->assertSame(['labels' => ['type' => 'keyword'], 'value' => 4.9, 'suffix' => '_sum'], $metrics[1]->samples[8]);
		$this->assertSame(['labels' => ['type' => 'keyword'], 'value' => 98, 'suffix' => '_count'], $metrics[1]->samples[9]);
		$this->assertSame(['labels' => ['type' => 'semantic', 'le' => '2.5'], 'value' => 29, 'suffix' => '_bucket'], $metrics[1]->samples[15]);
		$this->assertSame(
			[['labels' => ['status' => 'success'], 'value' => 500], ['labels' => ['status' => 'failure'], 'value' => 12]],
			$metrics[2]->samples
		);
	}//end testPublishesCountsHistogramAndEmbeddingOutcomes()

	/**
	 * The rendered exposition carries the spec's series names and bucket bounds.
	 *
	 * @return void
	 */
	public function testRendersAsPrometheusHistogram(): void {
		$reader = $this->createMock(SearchMetricsReader::class);
		$reader->method('searchStats')->willReturn([
			'hybrid' => ['total' => 20, 'timed' => 2, 'sumMs' => 300.0, 'buckets' => [50 => 0, 100 => 1, 250 => 1, 500 => 2, 1000 => 2, 2500 => 2, 5000 => 2]],
		]);
		$reader->method('embeddingCounts')->willReturn(['success' => 0, 'failure' => 0]);

		$out = (new PrometheusRenderer())->render(
			'openregister',
			(new SearchMetricsProvider(reader: $reader, logger: $this->createMock(LoggerInterface::class)))->metrics()
		);

		$this->assertStringContainsString("openregister_search_requests_total{type=\"hybrid\"} 20\n", $out);
		$this->assertStringContainsString("# TYPE openregister_search_duration_seconds histogram\n", $out);
		$this->assertStringContainsString("openregister_search_duration_seconds_bucket{type=\"hybrid\",le=\"0.1\"} 1\n", $out);
		$this->assertStringContainsString("openregister_search_duration_seconds_bucket{type=\"hybrid\",le=\"5.0\"} 2\n", $out);
		$this->assertStringContainsString("openregister_search_duration_seconds_bucket{type=\"hybrid\",le=\"+Inf\"} 2\n", $out);
		$this->assertStringContainsString("openregister_search_duration_seconds_sum{type=\"hybrid\"} 0.3\n", $out);
		$this->assertStringContainsString("openregister_search_duration_seconds_count{type=\"hybrid\"} 2\n", $out);
		$this->assertStringContainsString("openregister_embeddings_generated_total{status=\"failure\"} 0\n", $out);
	}//end testRendersAsPrometheusHistogram()

	/**
	 * A failing search query drops the search families only; the embedding family still publishes.
	 *
	 * @return void
	 */
	public function testAFailingSearchQueryKeepsTheEmbeddingFamily(): void {
		$reader = $this->createMock(SearchMetricsReader::class);
		$reader->method('searchStats')->willThrowException(new RuntimeException('no table'));
		$reader->method('embeddingCounts')->willReturn(['success' => 1, 'failure' => 2]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[Observability] search metrics unavailable: no table');

		$metrics = (new SearchMetricsProvider(reader: $reader, logger: $logger))->metrics();

		$this->assertCount(1, $metrics);
		$this->assertSame('embeddings_generated_total', $metrics[0]->name);
	}//end testAFailingSearchQueryKeepsTheEmbeddingFamily()

	/**
	 * A failing embedding query keeps the search families.
	 *
	 * @return void
	 */
	public function testAFailingEmbeddingQueryKeepsTheSearchFamilies(): void {
		$reader = $this->createMock(SearchMetricsReader::class);
		$reader->method('searchStats')->willReturn([]);
		$reader->method('embeddingCounts')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[Observability] embedding metrics unavailable: down');

		$metrics = (new SearchMetricsProvider(reader: $reader, logger: $logger))->metrics();

		$this->assertSame(['search_requests_total', 'search_duration_seconds'], array_map(static fn (MetricSample $m): string => $m->name, $metrics));
	}//end testAFailingEmbeddingQueryKeepsTheSearchFamilies()
}//end class
