<?php

/**
 * The Nextcloud user directory: the signed-in user, accounts by id and group membership.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The Nextcloud user directory: who is signed in (session), the account behind
 * an id (users) and the groups an account is in (groups).
 *
 * ADR-011 Rule 4 constructor aggregate for AuthorizationService, EndpointService
 * and UserLinkWriter. It holds no permission or tenancy guard: deciding what a
 * user may do stays with the consumer.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class UserDirectory {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession  $session The signed-in user.
	 * @param IUserManager  $users   Accounts by id, and password checks.
	 * @param IGroupManager $groups  Group membership.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function __construct(
		public readonly IUserSession $session,
		public readonly IUserManager $users,
		public readonly IGroupManager $groups,
	) {
	}//end __construct()
}//end class
