<?php

/**
 * PermissionEnforcementInterface: Refuses an action the caller may not perform.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\Schema;

/**
 * The throwing permission check of PermissionHandler.
 *
 * Callers that need only this role depend on it, not on the whole handler.
 */
interface PermissionEnforcementInterface {

	/**
	 * Check permission and throw exception if not granted
	 *
	 * @param Schema                 $schema  Schema to check permissions for.
	 * @param string                 $action  Action to check permission for.
	 * @param PermissionCheckOptions $options The caller, object owner, object and register of the check.
	 * @param bool                   $_rbac   Whether to enforce RBAC checks.
	 *
	 * @return void
	 *
	 * @throws Exception If permission is not granted
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function checkPermission(
		Schema $schema,
		string $action,
		PermissionCheckOptions $options = new PermissionCheckOptions(),
		bool $_rbac = true,
	): void;
}//end interface
