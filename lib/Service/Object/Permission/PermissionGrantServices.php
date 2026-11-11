<?php

/**
 * OpenRegister permission handler grant services: deny and derived grants
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



/**
 * Permission handler grant services: deny and derived grants.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionGrantServices {
	/**
	 * Bundle the services.
	 *
	 * @param PermissionDenyServices         $deny    The deny bundle.
	 * @param PermissionDerivedGrantServices $derived The derived grant bundle.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly PermissionDenyServices $deny,
		public readonly PermissionDerivedGrantServices $derived,
	) {
	}//end __construct()
}//end class
