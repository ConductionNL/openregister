<?php

/**
 * The webhook delivery latency histogram provider.
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

use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Service\Observability\WebhookDurationMetricsProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\WebhookDurationMetricsProvider
 * @uses   \OCA\OpenRegister\Service\Observability\HistogramPoints
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricSample
 * @uses   \OCA\OpenRegister\AppHost\Observability\PrometheusRenderer
 */
final class WebhookDurationMetricsProviderTest extends TestCase {

	/**
	 * The mapper is asked for the spec's bounds and the histogram renders with them.
	 *
	 * @return void
	 */
	public function testPublishesTheHistogramWithTheSpecBounds(): void {
		$logs = $this->createMock(WebhookLogMapperInterface::class);
		$logs->expects($this->once())
			->method('deliveryDurationStats')
			->with([100, 250, 500, 1000, 2500, 5000, 10000, 30000])
			->willReturn(['timed' => 5, 'sumMs' => 3200.0, 'buckets' => [100 => 1, 250 => 2, 500 => 3, 1000 => 4, 2500 => 4, 5000 => 5, 10000 => 5, 30000 => 5]]);

		$metrics = (new WebhookDurationMetricsProvider(logs: $logs, logger: $this->createMock(LoggerInterface::class)))->metrics();

		$this->assertCount(1, $metrics);
		$this->assertSame('webhook_delivery_duration_seconds', $metrics[0]->name);
		$this->assertSame('histogram', $metrics[0]->type);

		$out = (new PrometheusRenderer())->render('openregister', $metrics);
		$this->assertStringContainsString("# TYPE openregister_webhook_delivery_duration_seconds histogram\n", $out);
		$this->assertStringContainsString("openregister_webhook_delivery_duration_seconds_bucket{le=\"0.1\"} 1\n", $out);
		$this->assertStringContainsString("openregister_webhook_delivery_duration_seconds_bucket{le=\"30.0\"} 5\n", $out);
		$this->assertStringContainsString("openregister_webhook_delivery_duration_seconds_bucket{le=\"+Inf\"} 5\n", $out);
		$this->assertStringContainsString("openregister_webhook_delivery_duration_seconds_sum 3.2\n", $out);
		$this->assertStringContainsString("openregister_webhook_delivery_duration_seconds_count 5\n", $out);
	}//end testPublishesTheHistogramWithTheSpecBounds()

	/**
	 * A failing query publishes nothing and logs a warning.
	 *
	 * @return void
	 */
	public function testAFailingQueryPublishesNothing(): void {
		$logs = $this->createMock(WebhookLogMapperInterface::class);
		$logs->method('deliveryDurationStats')->willThrowException(new RuntimeException('no column'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[Observability] webhook delivery durations unavailable: no column');

		$this->assertSame([], (new WebhookDurationMetricsProvider(logs: $logs, logger: $logger))->metrics());
	}//end testAFailingQueryPublishesNothing()
}//end class
