<?php

/**
 * OpenRegister ValidatedTimeseriesRequest
 *
 * The parts of an ad-hoc timeseries request that have passed the field,
 * metricField, interval and grouping checks.
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
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * Validated request parts handed from the checks to the query factory.
 */
final class ValidatedTimeseriesRequest {
	/**
	 * Constructor.
	 *
	 * @param array<int, string>                                                $allowed     Allow-listed field names.
	 * @param mixed                                                             $metricField The metric field, or null for count.
	 * @param array{field: string, start: string, end: string, gap: string}|null $dateBucket  The time bucket, if any.
	 * @param array<string, mixed>|null                                         $groupBy     The categorical grouping, if any.
	 */
	public function __construct(
		public readonly array $allowed,
		public readonly mixed $metricField,
		public readonly ?array $dateBucket,
		public readonly ?array $groupBy,
	) {
	}//end __construct()
}//end class
