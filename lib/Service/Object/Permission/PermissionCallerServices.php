<?php

/**
 * OpenRegister permission handler caller services: who asks and the instance switches
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Permission
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

namespace OCA\OpenRegister\Service\Object\Permission;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Permission handler caller services: who asks and the instance switches.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionCallerServices {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession  $userSession  User session for getting current user.
	 * @param IUserManager  $userManager  User manager for getting user objects.
	 * @param IGroupManager $groupManager Group manager for checking user groups.
	 * @param IAppConfig    $appConfig    App config for the inheritFromPublic default and the enforce_default_closed flag.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IUserManager $userManager,
		public readonly IGroupManager $groupManager,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
