<?php

/**
 * OpenRegister CaseStuckItemsQuery
 *
 * One page of the cross-case plan-item listing: which type, which state, which page.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The type and state filters and the page of one "which cases are stuck where" listing.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */
final class CaseStuckItemsQuery {

	/**
	 * Constructor.
	 *
	 * @param string|null $type   The plan-item type filter.
	 * @param string|null $state  The state filter.
	 * @param int         $limit  Page size.
	 * @param int         $offset Page offset.
	 */
	public function __construct(
		public readonly ?string $type,
		public readonly ?string $state,
		public readonly int $limit,
		public readonly int $offset,
	) {
	}//end __construct()
}//end class
