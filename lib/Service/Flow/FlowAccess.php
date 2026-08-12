<?php

/**
 * Who may do what with a flow.
 *
 * The three collaborators behind FlowController's two authorisation helpers —
 * the session, the group manager and the flow action-rights matrix — gathered
 * behind one seam, the same shape `FederatedConfigAccess` already gives the
 * federation endpoints.
 *
 * Extracted because FlowController's constructor had reached ten parameters and
 * PHPMD's ExcessiveParameterList was failing `PHP Quality (phpmd)` on it. The
 * honest way past that is fewer collaborators, not a suppression: these three
 * were only ever used together, by two private methods, to answer two questions.
 * Behind one service the controller takes eight parameters and the flow policy
 * becomes testable without standing up a controller.
 *
 * Deliberately answers BOOLEANS and hands back the user, rather than returning
 * responses. "Not signed in" and "signed in but refused" are different HTTP
 * answers (401 vs 403) and shaping them is the controller's job; deciding them
 * is this class's.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The flow endpoints' authorisation questions, in one place.
 */
class FlowAccess {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Resolves the calling user.
	 * @param IGroupManager $groupManager Answers whether that user is an administrator.
	 * @param OpenRegisterActionAuthService $actionAuth The flow action-rights matrix.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly OpenRegisterActionAuthService $actionAuth,
	) {

	}//end __construct()

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
	public function currentUser(): ?IUser {
		return $this->userSession->getUser();
	}//end currentUser()

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
	public function may(IUser $user, string $action): bool {
		return $this->actionAuth->can(user: $user, action: $action);
	}//end may()

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
	public function callerIsAdmin(): bool {
		$user = $this->currentUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end callerIsAdmin()
}//end class
