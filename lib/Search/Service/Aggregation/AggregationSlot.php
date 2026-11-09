<?php

/**
 * OpenRegister AggregationSlot
 *
 * The place one aggregation result occupies in the aggregation cache.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/aggregations-backend-native/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * Which cached aggregation: the register and schema it counts, its name and its resolved filter.
 *
 * The caller's RBAC scope is not part of it; AggregationCache folds that in when it derives the key.
 *
 * @spec openspec/specs/aggregations-backend-native/spec.md
 */
final class AggregationSlot {
	/**
	 * Name a slot.
	 *
	 * @param string               $registerSlug Register slug.
	 * @param string               $schemaSlug   Schema slug.
	 * @param string               $name         Aggregation name (or the ad-hoc name).
	 * @param array<string, mixed> $filter       Resolved filter (placeholders concrete).
	 */
	public function __construct(
		public readonly string $registerSlug,
		public readonly string $schemaSlug,
		public readonly string $name,
		public readonly array $filter,
	) {
	}//end __construct()
}//end class
