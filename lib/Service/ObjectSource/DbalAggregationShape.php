<?php

/**
 * DbalAggregationShape — the shape of an aggregation result: a date-bucket
 * series, a categorical groupBy, or (neither) a scalar value.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * The optional groupBy and date-bucket specs of one aggregation; the date bucket wins over groupBy.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class DbalAggregationShape {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $groupBy    Optional categorical group spec ({field}).
	 * @param array<string, mixed>|null $dateBucket Optional date-bucket spec ({field, start, end, gap}).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function __construct(
		public readonly ?array $groupBy,
		public readonly ?array $dateBucket,
	) {
	}//end __construct()
}//end class
