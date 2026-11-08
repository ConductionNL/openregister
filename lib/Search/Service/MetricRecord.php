<?php

/**
 * OpenRegister metric record
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

/**
 * One operational metric row to record: its type, subject, outcome and metadata.
 *
 * @spec openspec/specs/production-observability/spec.md#metrics-storage-strategy
 */
final class MetricRecord {
	/**
	 * The record.
	 *
	 * @param string        $metricType Type of metric (use the MetricsService constants)
	 * @param MetricSubject $subject    The entity and user the metric is about
	 * @param MetricOutcome $outcome    The status, duration and error of the operation
	 * @param array|null    $metadata   Additional metadata
	 *
	 * @spec openspec/specs/production-observability/spec.md#metrics-storage-strategy
	 */
	public function __construct(
		public readonly string $metricType,
		public readonly MetricSubject $subject = new MetricSubject(),
		public readonly MetricOutcome $outcome = new MetricOutcome(),
		public readonly ?array $metadata = null,
	) {
	}//end __construct()
}//end class
