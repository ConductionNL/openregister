<?php

/**
 * OpenRegister CaseTransitionCommand
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * A hand transition of one plan item: the item, the target state and why.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */
final class CaseTransitionCommand {
	/**
	 * Describe the transition.
	 *
	 * @param string      $itemUuid The item
	 * @param string|null $to       The target state, as sent
	 * @param string|null $reason   Free text
	 */
	public function __construct(
		public readonly string $itemUuid,
		public readonly ?string $to,
		public readonly ?string $reason,
	) {
	}//end __construct()
}//end class
