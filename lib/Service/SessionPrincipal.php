<?php

/**
 * OpenRegister SessionPrincipal
 *
 * Who is signed in, which groups they are in, and the accounts they can be resolved to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The signed-in principal: the session, its group memberships and account lookup.
 *
 * A named constructor aggregate (ADR-011 Rule 4), autowired by the container. It
 * holds no permission or tenancy guard; the services that decide access keep
 * their guards as direct constructor parameters.
 *
 * @spec openspec/specs/api-authorization/spec.md
 */
final class SessionPrincipal {

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The current session and its user.
	 * @param IGroupManager $groupManager Group membership and admin lookups.
	 * @param IUserManager  $userManager  Account lookups by uid and password checks.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly IUserManager $userManager,
	) {
	}//end __construct()
}//end class
