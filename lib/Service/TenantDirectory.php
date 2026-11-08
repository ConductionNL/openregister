<?php

/**
 * The group and user managers a tenant's groups and admin user are provisioned in.
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

/**
 * The group and user managers a tenant's groups and admin user are provisioned in.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class TenantDirectory {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IGroupManager $groupManager Creates and reads the tenant groups.
	 * @param IUserManager  $userManager  Reads the tenant admin user.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly IGroupManager $groupManager,
		public readonly IUserManager $userManager,
	) {
	}//end __construct()
}//end class
