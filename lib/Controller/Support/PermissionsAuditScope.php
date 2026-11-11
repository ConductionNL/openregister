<?php

/**
 * OpenRegister permissions audit scope
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

use OCA\OpenRegister\Service\Rbac\AdminGateInterface;
use OCA\OpenRegister\Service\Rbac\ScopeAuditInterface;

/**
 * The domain services of the permissions audit API.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionsAuditScope {
	/**
	 * Bundle the services.
	 *
	 * @param ScopeAuditInterface $audit     Assembles the per-rule audit.
	 * @param AdminGateInterface  $adminGate Answers whether the caller is an admin.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly ScopeAuditInterface $audit,
		public readonly AdminGateInterface $adminGate,
	) {
	}//end __construct()
}//end class
