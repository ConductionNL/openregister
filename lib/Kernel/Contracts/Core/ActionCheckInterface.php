<?php
/**
 * ActionCheckInterface: whether a user may perform one named action.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCP\IUser;

/**
 * Answers whether a user holds one action right of Open Register's action matrix.
 *
 * Core asks (an owned webhook checks that its owner still holds `webhook.own`);
 * the action matrix (Platform) answers and is bound to this contract.
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
 */
interface ActionCheckInterface {

	/**
	 * Whether the user may perform the named action. Never throws for a refusal.
	 *
	 * @param IUser  $user   The user.
	 * @param string $action Dot-separated action name, e.g. `webhook.own`.
	 *
	 * @return bool True when the user may perform the action.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function can(IUser $user, string $action): bool;
}//end interface
