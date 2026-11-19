<?php
/**
 * ActionRightCheckInterface: whether a user holds a named action right.
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
 * The question an area asks the action-rights matrix: may this user do this?
 *
 * The matrix itself is Platform's; it binds its answer in its own bindings.
 * The catalogue of rights is {@see ActionRightsInterface}.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */
interface ActionRightCheckInterface {

	/**
	 * Whether the user may perform the named action; never throws for a refusal.
	 *
	 * @param IUser  $user   The acting user.
	 * @param string $action The right's dot-separated name.
	 *
	 * @return bool True when the user holds the right.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
	 */
	public function can(IUser $user, string $action): bool;
}//end interface
