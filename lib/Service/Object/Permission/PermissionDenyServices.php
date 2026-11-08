<?php

/**
 * OpenRegister permission handler deny services: the deny grammar, its staging switch and the grantable set
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

use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyResolverInterface;
use OCA\OpenRegister\Service\Rbac\GrantConstraints;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;

/**
 * Permission handler deny services: the deny grammar, its staging switch and the grantable set.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionDenyServices {
	/**
	 * Bundle the services.
	 *
	 * @param ?DenyResolverInterface $denyResolver        Shared deny-grammar reader.
	 * @param ?DenyEnforcementMode $denyEnforcementMode The staging switch.
	 * @param ?PermissionCatalogueInterface $permissionCatalogue The grantable set.
	 * @param ?GrantConstraints    $grantConstraints    Reads an entry's end and the area it is confined to.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly ?DenyResolverInterface $denyResolver = null,
		public readonly ?DenyEnforcementMode $denyEnforcementMode = null,
		public readonly ?PermissionCatalogueInterface $permissionCatalogue = null,
		public readonly ?GrantConstraints $grantConstraints = null,
	) {
	}//end __construct()
}//end class
