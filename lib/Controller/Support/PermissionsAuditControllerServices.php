<?php

/**
 * OpenRegister permissions audit controller services
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

/**
 * The service bundles of the permissions audit API.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionsAuditControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param PermissionsAuditRules $rules The rules bundle.
	 * @param PermissionsAuditScope $scope Resolves which registers and schemas a report covers bundle.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly PermissionsAuditRules $rules,
		public readonly PermissionsAuditScope $scope,
	) {
	}//end __construct()
}//end class
