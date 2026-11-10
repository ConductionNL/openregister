<?php

/**
 * FlowAccessInterface: the FlowAccess as the flow controllers reach it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\IUser;

/**
 * The calling user and their named flow rights, typed for the flow controllers.
 *
 * Declares only what callers outside the Service component use. Bound in
 * ContractBindings to FlowAccess, so a controller reads the same decision the
 * runnable guard does.
 */
interface FlowAccessInterface {

	/**
	 * The calling user, or null when there is no session.
	 *
	 * Returned rather than folded into `may()` so the caller can still tell an
	 * anonymous request (401) apart from an authenticated one that was refused
	 * (403). Collapsing the two would answer "forbidden" to a caller whose real
	 * problem is that they are not signed in.
	 *
	 * @return IUser|null The calling user, or null.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
	 */
	public function currentUser(): ?IUser;

	/**
	 * Whether a user holds a named flow right.
	 *
	 * @param IUser $user The acting user.
	 * @param string $action The right's name.
	 *
	 * @return boolean Whether the right is held.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
	 */
	public function may(IUser $user, string $action): bool;

	/**
	 * Whether the calling user is a Nextcloud administrator.
	 *
	 * Fails CLOSED: an unresolvable session answers false, so a caller whose
	 * identity cannot be established gets the reduced palette rather than the
	 * administrator's.
	 *
	 * @return boolean Whether the caller is an administrator.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
	 */
	public function callerIsAdmin(): bool;
}//end interface
