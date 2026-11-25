<?php

/**
 * AdministratorCheck: whether a user is an instance administrator, by Nextcloud's own check.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCP\IGroupManager;
use OCP\IUser;

/**
 * The one place a user is turned into an administrator verdict.
 *
 * It asks `IGroupManager::isAdmin()` rather than looking for `admin` in the
 * user's group ids, because Nextcloud's check also applies
 * `allowed_admin_ranges`: a member of the admin group calling from outside the
 * allowed range is answered as a non-administrator. It lives in the kernel so
 * every area can ask it without depending on another area.
 */
final class AdministratorCheck {

	/**
	 * Whether a given user is an instance administrator here.
	 *
	 * Fails closed on a missing manager or user, and on a manager that answers
	 * anything but true.
	 *
	 * @param IGroupManager|null $groupManager Nextcloud's group manager.
	 * @param IUser|string|null  $user         The user, or their uid.
	 *
	 * @return bool True when Nextcloud counts the user as an administrator.
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

		return ($groupManager->isAdmin($uid) === true);
	}//end isAdministrator()
}//end class
