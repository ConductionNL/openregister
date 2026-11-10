<?php

/**
 * What an aggregation computes, and over which rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * The metric, the field it reads and the filter that selects the rows.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class AggregationMeasure {
	/**
	 * Constructor.
	 *
	 * @param string               $metric The metric (count, sum, avg, min, max).
	 * @param string|null          $field  The field the metric reads; null for count.
	 * @param array<string, mixed> $filter The filter that selects the rows.
	 */
	public function __construct(
		public readonly string $metric,
		public readonly ?string $field = null,
		public readonly array $filter = [],
	) {
	}//end __construct()
}//end class
