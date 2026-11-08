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
use OCA\OpenRegister\Db\ObjectEntity;
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
	 * @param Schema $schema Schema to check permissions for.
	 * @param string $action Action to check permission for.
	 * @param string|null $userId User ID to check permissions for.
	 * @param string|null $objectOwner Object owner ID.
	 * @param bool $_rbac Whether to enforce RBAC checks.
	 * @param ObjectEntity|null $object Optional object entity for conditional authorization matching.
	 * @param string|null $register The register written to, for a check that names no object.
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
		?string $userId = null,
		?string $objectOwner = null,
		bool $_rbac = true,
		?ObjectEntity $object = null,
		?string $register = null,
	): void;
}//end interface
