<?php

/**
 * Who is calling the AVG register endpoints, and are they an administrator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCP\IUser;

/**
 * The caller of the purpose list, the processing register and the processing log.
 *
 * The admin check is membership of the `admin` group as the group manager
 * reports it, the check these registers have always made.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class GdprRegisterAccess {
	/**
	 * Wrap the session services.
	 *
	 * @param ControllerSession $session Session, group manager and logger.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function __construct(
		private readonly ControllerSession $session,
	) {
	}//end __construct()

	/**
	 * The authenticated caller, or null.
	 *
	 * @return IUser|null The session user.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function user(): ?IUser {
		return $this->session->userSession->getUser();
	}//end user()

	/**
	 * The groups a user belongs to.
	 *
	 * @param IUser $user The user.
	 *
	 * @return array<int, string> The group ids.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function groupIds(IUser $user): array {
		return $this->session->groupManager->getUserGroupIds($user);
	}//end groupIds()

	/**
	 * Whether the caller is in the admin group; false without a caller.
	 *
	 * @return bool True for a member of the admin group.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function isAdmin(): bool {
		$user = $this->user();
		if ($user === null) {
			return false;
		}

		return in_array(
			needle: 'admin',
			haystack: $this->groupIds(user: $user),
			strict: true
		);
	}//end isAdmin()
}//end class
