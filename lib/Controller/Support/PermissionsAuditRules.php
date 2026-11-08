<?php

/**
 * OpenRegister permissions audit rules
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

use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyResolverInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\PermissionReportScopeInterface;

/**
 * The domain services of the permissions audit API.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionsAuditRules {
	/**
	 * Bundle the services.
	 *
	 * @param PermissionCatalogueInterface   $catalogue    The grantable set, for the declared/undeclared split.
	 * @param DenyEnforcementMode   $enforcement  The staging switch, reported beside every rule.
	 * @param DenyResolverInterface          $denyResolver The one reader of the deny grammar.
	 * @param PermissionReportScopeInterface $scope        Resolves which registers and schemas a report covers.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly PermissionCatalogueInterface $catalogue,
		public readonly DenyEnforcementMode $enforcement,
		public readonly DenyResolverInterface $denyResolver,
		public readonly PermissionReportScopeInterface $scope,
	) {
	}//end __construct()
}//end class
