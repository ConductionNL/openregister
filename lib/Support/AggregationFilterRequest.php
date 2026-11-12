<?php

/**
 * OpenRegister AggregationFilterRequest.
 *
 * The filter parts of one aggregation request, before they are read against a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

/**
 * The bracketed filters, every request parameter and the action's control parameters, as
 * {@see FilterParams::forAggregation()} reads them.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
 */
final class AggregationFilterRequest {

	/**
	 * Constructor.
	 *
	 * @param array<array-key, mixed> $bracket       The `filter[...]` map.
	 * @param array<array-key, mixed> $params        Every request parameter.
	 * @param string[]                $controlParams The action's control parameters.
	 */
	public function __construct(
		public readonly array $bracket,
		public readonly array $params,
		public readonly array $controlParams,
	) {
	}//end __construct()
}//end class
