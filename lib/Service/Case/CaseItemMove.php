<?php

/**
 * One plan item's move to a target state, inside its plan.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;

/**
 * The item, the state it is asked to reach and the plan it sits in.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */
final readonly class CaseItemMove {
	/**
	 * Describe the move.
	 *
	 * @param CaseItem     $item The item.
	 * @param string       $to   The target state.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function __construct(
		public CaseItem $item,
		public string $to,
		public CasePlanTree $tree,
	) {
	}//end __construct()
}//end class
