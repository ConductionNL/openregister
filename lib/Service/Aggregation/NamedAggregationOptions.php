<?php

/**
 * OpenRegister NamedAggregationOptions
 *
 * How a named aggregation runs: the read gate, the parent row and a narrowing filter.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * The optional settings of a named aggregation run.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class NamedAggregationOptions {

	/**
	 * Constructor.
	 *
	 * @param bool                 $bypassRbac  Internal-system mode: skip the read gate and the row predicate.
	 *                                          Only for non-controller callers that already hold an
	 *                                          authoritative reason (report rendering, threshold listeners).
	 * @param array<string, mixed> $parentRow   Parent object's values for `@self.<field>` references in a
	 *                                          cross-schema `where`; ignored without a `from` key.
	 * @param array<string, mixed> $extraFilter Constraints that narrow, never relax, the declared filter.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		public readonly bool $bypassRbac,
		public readonly array $parentRow = [],
		public readonly array $extraFilter = [],
	) {
	}//end __construct()
}//end class
