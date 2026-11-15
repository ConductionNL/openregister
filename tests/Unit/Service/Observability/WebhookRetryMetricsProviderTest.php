<?php

/**
 * The webhook retry queue gauge.
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

use DateTime;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Service\Observability\WebhookRetryMetricsProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Observability\WebhookRetryMetricsProvider
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class WebhookRetryMetricsProviderTest extends TestCase {

	/**
	 * The gauge counts through the mapper at the clock's instant.
	 *
	 * @return void
	 */
	public function testPublishesTheQueueDepthAtTheClocksInstant(): void {
		$now  = new DateTime('2026-11-13 12:00:00');
		$time = $this->createMock(ITimeFactory::class);
		$time->expects($this->once())->method('getDateTime')->willReturn($now);
		$logs = $this->createMock(WebhookLogMapperInterface::class);
		$logs->expects($this->once())->method('countPendingRetries')->with($this->identicalTo($now))->willReturn(3);

		$metrics = (new WebhookRetryMetricsProvider(logs: $logs, time: $time))->metrics();

		$this->assertCount(1, $metrics);
		$this->assertInstanceOf(MetricSample::class, $metrics[0]);
		$this->assertSame('webhook_retry_queue_depth', $metrics[0]->name);
		$this->assertSame('gauge', $metrics[0]->type);
		$this->assertSame([['labels' => [], 'value' => 3]], $metrics[0]->samples);
	}//end testPublishesTheQueueDepthAtTheClocksInstant()

	/**
	 * An empty queue is a zero sample, not a missing metric.
	 *
	 * @return void
	 */
	public function testAnEmptyQueueIsAZeroSample(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime());
		$logs = $this->createMock(WebhookLogMapperInterface::class);
		$logs->method('countPendingRetries')->willReturn(0);

		$metrics = (new WebhookRetryMetricsProvider(logs: $logs, time: $time))->metrics();

		$this->assertSame([['labels' => [], 'value' => 0]], $metrics[0]->samples);
	}//end testAnEmptyQueueIsAZeroSample()
}//end class
