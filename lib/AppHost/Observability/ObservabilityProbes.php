<?php

/**
 * OpenRegister AppHost — Observability Probes
 *
 * The health checks and metrics one host app declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\AppHost\Observability
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

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * The health checks and metrics one host app declares.
 *
 * @psalm-immutable
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.2
 */
final readonly class ObservabilityProbes {
	/**
	 * Constructor.
	 *
	 * @param HealthCheckDescriptor[] $checks Parsed health checks.
	 * @param MetricDescriptor[] $metrics Parsed metrics.
	 */
	public function __construct(
		public array $checks,
		public array $metrics,
	) {
	}//end __construct()
}//end class
