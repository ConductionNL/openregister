<?php

/**
 * AggregationLayout — how the result of an aggregation request is laid out.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * The grouping, time buckets, metric list and running-total flag of one aggregation request:
 * together they decide whether the answer is one value, a values map, groups or buckets.
 *
 * Passed to AggregationQuery::create(), which validates it before building the query.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final readonly class AggregationLayout {
	/**
	 * Lay out a request.
	 *
	 * @param array<string, mixed>|null                                $groupBy    Optional grouping spec (single-field, multi-field or plain list).
	 * @param array<string, mixed>|null                                $dateBucket Optional date-bucket spec; `{field, start, end, gap}`.
	 * @param array<int, array{metric: string, field?: ?string}>|null $metrics    Optional multi-metric list.
	 * @param bool                                                     $cumulative Optional running-total flag (REQ-AGG-103).
	 */
	public function __construct(
		public ?array $groupBy = null,
		public ?array $dateBucket = null,
		public ?array $metrics = null,
		public bool $cumulative = false,
	) {
	}//end __construct()
}//end class
