<?php

/**
 * OpenRegister AppHost — Metrics Provider Interface
 *
 * Escape hatch for metrics that cannot be expressed by the closed declarative
 * source-kind set (e.g. shillinq's customer-bridge circuit-breaker state,
 * nldesign's CSS-file parsing). An app registers an implementation under the
 * container alias `OCA\OpenRegister\AppHost\IMetricsProvider::{appId}` (the
 * ADR-035 provider-alias discovery pattern); a `{"kind":"provider"}` descriptor
 * merges its rendered samples into the generic metrics response.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Interface
 * @package  OCA\OpenRegister\AppHost
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

namespace OCA\OpenRegister\AppHost;

use OCA\OpenRegister\AppHost\Observability\MetricSample;

/**
 * Imperative metrics provider for the AppHost observability engine.
 */
interface IMetricsProvider {
	/**
	 * Produce the provider's metric samples.
	 *
	 * Returned samples are rendered by the engine's Prometheus renderer with
	 * the app's `{app}_` prefix and HELP/TYPE lines, exactly like declarative
	 * metrics, so providers never emit raw exposition text themselves.
	 *
	 * @return MetricSample[] The provider's samples.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.5
	 */
	public function metrics(): array;
}//end interface
