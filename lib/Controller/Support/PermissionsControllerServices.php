<?php

/**
 * OpenRegister PermissionsController services
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

use OCA\OpenRegister\Service\Rbac\DenyEnforcementModeInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;

/**
 * The collaborators of PermissionsController, wired by DI autowiring.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param PermissionCatalogueInterface $catalogue   The grantable set.
	 * @param DenyEnforcementModeInterface $enforcement The staging switch.
	 */
	public function __construct(
		public readonly PermissionCatalogueInterface $catalogue,
		public readonly DenyEnforcementModeInterface $enforcement,
	) {
	}//end __construct()
}//end class
