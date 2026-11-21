<?php

/**
 * The request alert outcomes as gauges.
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
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes `error_rate_exceeded` and `response_time_exceeded`: 1 while the last judged
 * window is over its threshold, 0 otherwise or without a threshold. The outcomes are the
 * ones `RequestAlertJob` stored; a scrape never judges a window itself. Outcomes that
 * cannot be read publish 0, with a warning.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */
class RequestAlertMetricsProvider implements IMetricsProvider {

	/**
	 * Constructor.
	 *
	 * @param RequestAlertEvaluator $evaluator Holds the outcomes.
	 * @param LoggerInterface       $logger    Logs outcomes that cannot be read.
	 */
	public function __construct(
		private readonly RequestAlertEvaluator $evaluator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The error rate gauge, then the response time gauge.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
	 */
	public function metrics(): array {
		try {
			$outcomes = $this->evaluator->outcomes();
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] request alert outcomes could not be read: '.$e->getMessage());
			$outcomes = ['errorRateExceeded' => false, 'responseTimeExceeded' => false];
		}

		return [
			new MetricSample(
				name: 'error_rate_exceeded',
				type: 'gauge',
				help: 'Whether the 5xx share of the last judged window is over the configured threshold',
				samples: [['labels' => [], 'value' => (int) $outcomes['errorRateExceeded']]]
			),
			new MetricSample(
				name: 'response_time_exceeded',
				type: 'gauge',
				help: 'Whether the p95 response time of the last judged window is over the configured threshold',
				samples: [['labels' => [], 'value' => (int) $outcomes['responseTimeExceeded']]]
			),
		];
	}//end metrics()
}//end class
