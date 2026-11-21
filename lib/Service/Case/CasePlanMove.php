<?php

/**
 * OpenRegister Case Plan Move
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;

/**
 * One move of a plan item: the item, the state it is moved to (or asked to
 * move to), why, and the plan it sits in when the mover has it.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */
final readonly class CasePlanMove {

	/**
	 * One move.
	 *
	 * @param CaseItem $item The item, as read.
	 * @param string $to The target state (or the verb, for a refused enable/attach).
	 * @param CaseTransitionCause $why The cause, its reference, the acting identity and the reason.
	 * @param CasePlanTree|null $tree The plan, when known (for the cascade).
	 */
	public function __construct(
		public CaseItem $item,
		public string $to,
		public CaseTransitionCause $why,
		public ?CasePlanTree $tree,
	) {
	}//end __construct()
}//end class
