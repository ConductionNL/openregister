<?php

/**
 * RowAggregation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * What the PHP path computes over hydrated rows: a metric over a field, optionally
 * per group of the group fields, or a multi-metric list (REQ-AGG-102).
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class RowAggregation {
	/**
	 * Constructor.
	 *
	 * @param string                                                 $metric      One of count/sum/avg/min/max/count_distinct.
	 * @param mixed                                                  $field       Field to aggregate over (ignored for count).
	 * @param array<int, string>                                     $groupFields Ordered field(s) used as the bucket key; empty when ungrouped.
	 * @param array<int, array{metric: string, field: ?string}>|null $metrics     Optional multi-metric list; with more than one
	 *                                                                            entry each group carries a `values` map and
	 *                                                                            `$metric`/`$field` are ignored.
	 */
	public function __construct(
		public readonly string $metric,
		public readonly mixed $field,
		public readonly array $groupFields = [],
		public readonly ?array $metrics = null,
	) {
	}//end __construct()
}//end class
