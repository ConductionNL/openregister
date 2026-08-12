<?php

/**
 * OpenRegister AppHost — Metric Source Interface
 *
 * Contract for the closed set of declarative metric sources. Each source
 * turns one validated MetricDescriptor into a MetricSample (one metric family
 * with its labelled samples).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Interface
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
 * One metric-source executor.
 */
interface MetricSourceInterface {
	/**
	 * The source kind this executor handles (MetricDescriptor::KINDS).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.2
	 */
	public function kind(): string;

	/**
	 * Execute the descriptor for the given app and return its samples.
	 *
	 * @param string $appId Calling app id.
	 * @param MetricDescriptor $descriptor The metric descriptor.
	 *
	 * @return MetricSample[] One or more metric families (usually one).
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.2
	 */
	public function collect(string $appId, MetricDescriptor $descriptor): array;
}//end interface
