<?php

/**
 * OpenRegister CaseItemTransition
 *
 * A user's request to move one plan item: which item, to which state, who moves it and why.
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The item, the target state, the acting identity and the reason of one transition by hand.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */
final class CaseItemTransition {

	/**
	 * Constructor.
	 *
	 * @param string      $itemUuid The item.
	 * @param string      $to       The target state.
	 * @param string|null $actor    The acting identity.
	 * @param string|null $reason   Free text.
	 */
	public function __construct(
		public readonly string $itemUuid,
		public readonly string $to,
		public readonly ?string $actor,
		public readonly ?string $reason = null,
	) {
	}//end __construct()
}//end class
