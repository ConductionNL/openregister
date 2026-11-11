<?php

/**
 * The user and group directories the RBAC services resolve principals in.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Who a user or a group is, and which groups a user is in.
 *
 * A constructor bundle, autowired by Nextcloud: it carries no behaviour.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PrincipalDirectory {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserManager  $userManager  Resolves a user and their display name.
	 * @param IGroupManager $groupManager Resolves a group and a user's groups.
	 */
	public function __construct(
		public readonly IUserManager $userManager,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
