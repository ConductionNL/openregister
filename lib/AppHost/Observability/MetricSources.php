<?php

/**
 * OpenRegister AppHost — Metric Sources
 *
 * The closed set of metric sources the metrics engine dispatches to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
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

use OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource;

/**
 * The closed set of metric sources, one per declarative metric kind.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.1
 */
final readonly class MetricSources {
	/**
	 * Bundle the sources.
	 *
	 * @param ObjectMetricSource    $object    objectCount/objectSum.
	 * @param TableMetricSource     $table     tableCount.
	 * @param AppConfigMetricSource $appConfig appConfig.
	 * @param ProviderMetricSource  $provider  provider escape hatch.
	 */
	public function __construct(
		public ObjectMetricSource $object,
		public TableMetricSource $table,
		public AppConfigMetricSource $appConfig,
		public ProviderMetricSource $provider,
	) {
	}//end __construct()
}//end class
