<?php

/**
 * AdminGate - one answer to "is the caller an instance administrator"
 *
 * Every controller that gates an endpoint on admin rights has so far carried
 * its own private isCurrentUserAdmin(), which means its own IUserSession and
 * IGroupManager dependencies. That is a dozen copies of six lines, and on a
 * controller already near its coupling budget it is two dependencies spent on
 * a question that has nothing to do with what the controller is for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Answers whether the signed-in caller is an instance administrator.
 */
class AdminGate implements AdminGateInterface {
	/**
	 * Constructor.
	 *
	 * Both collaborators are nullable so a unit test may build a controller
	 * without a session; the gate then refuses, which is the safe direction.
	 *
	 * @param IUserSession|null  $userSession  Resolves the caller.
	 * @param IGroupManager|null $groupManager Answers whether that caller is an admin.
	 */
	public function __construct(
		private readonly ?IUserSession $userSession = null,
		private readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()

	/**
	 * Whether the caller is an instance administrator.
	 *
	 * Fails closed when either collaborator is absent, or when nobody is signed
	 * in, so an unanswerable question is never read as a yes.
	 *
	 * @return bool True when the signed-in caller is an admin.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function isAdmin(): bool {
		if ($this->userSession === null || $this->groupManager === null) {
			return false;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return self::isAdministrator(groupManager: $this->groupManager, user: $user);
	}//end isAdmin()

	/**
	 * Whether a given user is an instance administrator, by Nextcloud's own check.
	 *
	 * The one place a user is turned into an administrator verdict. It asks
	 * `IGroupManager::isAdmin()` rather than looking for `admin` in the user's
	 * group ids, because Nextcloud's check also applies `allowed_admin_ranges`:
	 * a member of the admin group calling from outside the allowed range is
	 * answered as a non-administrator. Fails closed on a missing manager or user.
	 *
	 * @param IGroupManager|null $groupManager Nextcloud's group manager.
	 * @param IUser|string|null  $user         The user, or their uid.
	 *
	 * @return bool True when Nextcloud counts the user as an administrator here.
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public static function isAdministrator(?IGroupManager $groupManager, IUser|string|null $user): bool {
		if ($groupManager === null || $user === null) {
			return false;
		}

		$uid = $user;
		if ($user instanceof IUser) {
			$uid = $user->getUID();
		}

		if ($uid === '') {
			return false;
		}

		return $groupManager->isAdmin($uid);
	}//end isAdministrator()

	/**
	 * The response a refused caller gets.
	 *
	 * Lives with the gate so every caller refuses in the same shape. The point
	 * of gating explicitly rather than letting SecurityMiddleware throw is that
	 * the 403 looks like every other 403 in this app; that only holds if the
	 * body is written in one place.
	 *
	 * @param string $doing What the caller was trying to read or change.
	 *
	 * @return JSONResponse A 403 naming the requirement.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function refusal(string $doing): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'Administrator privileges are required to ' . $doing,
			],
			statusCode: 403
		);
	}//end refusal()
}//end class
