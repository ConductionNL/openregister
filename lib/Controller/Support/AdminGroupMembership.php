<?php

/**
 * OpenRegister AdminGroupMembership
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

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Whether the signed-in caller is a member of the `admin` group.
 *
 * Shared by the audit sink and DSAR controllers, which each carried their own
 * copy. Membership is read from the caller's group ids, so an anonymous caller
 * is never a member.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class AdminGroupMembership {
	/**
	 * Whether the signed-in caller is in the `admin` group.
	 *
	 * @param IUserSession  $userSession  The caller's session
	 * @param IGroupManager $groupManager The group manager
	 *
	 * @return bool True for a signed-in member of `admin`
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public static function ofCaller(IUserSession $userSession, IGroupManager $groupManager): bool {
		$user = $userSession->getUser();
		if ($user === null) {
			return false;
		}

		return in_array(
			needle: 'admin',
			haystack: $groupManager->getUserGroupIds($user),
			strict: true
		);
	}//end ofCaller()
}//end class
