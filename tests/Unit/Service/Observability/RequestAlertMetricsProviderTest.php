<?php

/**
 * The request alert gauges (production-observability, "Error rate threshold").
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
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Service\Observability\RequestAlertEvaluator;
use OCA\OpenRegister\Service\Observability\RequestAlertMetricsProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\RequestAlertMetricsProvider
 * @uses \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class RequestAlertMetricsProviderTest extends TestCase {

	public function testPublishesTheStoredOutcomesAsGauges(): void {
		$evaluator = $this->createMock(RequestAlertEvaluator::class);
		$evaluator->expects($this->never())->method('evaluate');
		$evaluator->method('outcomes')->willReturn(['errorRateExceeded' => true, 'responseTimeExceeded' => false]);

		$metrics = (new RequestAlertMetricsProvider(evaluator: $evaluator, logger: $this->createMock(LoggerInterface::class)))->metrics();

		$this->assertSame(['error_rate_exceeded', 'response_time_exceeded'], array_map(static fn (MetricSample $m): string => $m->name, $metrics));
		$this->assertSame(['gauge', 'gauge'], array_map(static fn (MetricSample $m): string => $m->type, $metrics));
		$this->assertSame([['labels' => [], 'value' => 1]], $metrics[0]->samples);
		$this->assertSame([['labels' => [], 'value' => 0]], $metrics[1]->samples);
	}//end testPublishesTheStoredOutcomesAsGauges()

	public function testUnreadableOutcomesPublishZeroAndWarn(): void {
		$evaluator = $this->createMock(RequestAlertEvaluator::class);
		$evaluator->method('outcomes')->willThrowException(new RuntimeException('config down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('config down'));

		$metrics = (new RequestAlertMetricsProvider(evaluator: $evaluator, logger: $logger))->metrics();

		$this->assertSame([['labels' => [], 'value' => 0]], $metrics[0]->samples);
		$this->assertSame([['labels' => [], 'value' => 0]], $metrics[1]->samples);
	}//end testUnreadableOutcomesPublishZeroAndWarn()
}//end class
