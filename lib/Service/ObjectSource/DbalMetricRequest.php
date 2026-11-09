<?php

/**
 * One metric a dashboard asks a DBAL-backed schema for: what to compute, over which field, filtered and shaped how.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * The metric, its field, the filter and the result shape of one live aggregation.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class DbalMetricRequest {
	/**
	 * Constructor.
	 *
	 * @param string               $metric count/sum/avg/min/max.
	 * @param string|null          $field  Metric field (required for sum/avg/min/max).
	 * @param array<string, mixed> $filter Filter map ({field: scalar} or {field: {op: value}}).
	 * @param DbalAggregationShape $shape  The optional groupBy and date-bucket specs.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $metric,
		public readonly ?string $field,
		public readonly array $filter,
		public readonly DbalAggregationShape $shape,
	) {
	}//end __construct()
}//end class
