<?php

/**
 * OpenRegister session principal
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 *
 * @spec openspec/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Who is calling, and the groups that say what they are: the session and the group manager.
 *
 * An ADR-011 Rule 4 aggregate, autowired by the container. The controller
 * bundles that gate on the session user and the admin group take it instead of
 * the two platform services; it is not a permission or tenancy guard.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class SessionPrincipal {
	/**
	 * Bundle the session and the group manager.
	 *
	 * @param IUserSession  $userSession  The user session.
	 * @param IGroupManager $groupManager The group manager (admin checks).
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
