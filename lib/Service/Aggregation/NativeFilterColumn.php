<?php

/**
 * NativeFilterColumn
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
 * The quoted column a filter predicate is added for, and the native query it is added to.
 *
 * Built by AggregationRunner while it renders one filter; it never leaves that class.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class NativeFilterColumn {
	/**
	 * Constructor.
	 *
	 * @param NativeAggregationQuery $query  The query being built
	 * @param string                 $column The quoted column
	 */
	public function __construct(
		public readonly NativeAggregationQuery $query,
		public readonly string $column,
	) {
	}//end __construct()
}//end class
