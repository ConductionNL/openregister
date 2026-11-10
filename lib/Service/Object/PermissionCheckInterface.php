<?php

/**
 * PermissionCheckInterface: Answers whether the caller may perform one action on a schema or object.
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
 * The yes/no permission verdict of PermissionHandler, for callers that only ask.
 *
 * Callers that need only this role depend on it, not on the whole handler.
 */
interface PermissionCheckInterface {

	/**
	 * Check if current user has permission to perform action on schema
	 *
	 * Implements the RBAC permission checking logic:
	 * - Admin group always has all permissions
	 * - Object owner always has all permissions for their specific objects
	 * - If no authorization configured, all users have all permissions
	 * - If authorization is configured but the action is not granted, access is
	 *   denied (fail-closed)
	 * - Otherwise, check if user's groups match the required groups for the action
	 *
	 * Property-level RBAC (fine-grained per-property authorization arrays, plus
	 * `writeOnly` secret stripping) is implemented in
	 * {@see \OCA\OpenRegister\Service\PropertyRbacHandler} and applied on the
	 * read path by {@see \OCA\OpenRegister\Service\Object\RenderObject::renderEntity()}.
	 * This handler owns object/schema-level RBAC only.
	 *
	 * @param Schema $schema The schema to check permissions for.
	 * @param string $action The CRUD action (create, read, update, delete).
	 * @param string|null $userId Optional user ID (defaults to current user).
	 * @param string|null $objectOwner Optional object owner for ownership check.
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param ObjectEntity|null $object Optional object entity for conditional authorization matching.
	 * @param string|null $register The register written to, for a check that names no object (a create, a delete by id).
	 *                              Only a declared action's elevation reads it.
	 *
	 * @return bool True if user has permission, false otherwise
	 *
	 * @throws Exception If user session is invalid or user groups cannot be determined
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function hasPermission(
		Schema $schema,
		string $action,
		?string $userId = null,
		?string $objectOwner = null,
		bool $_rbac = true,
		?ObjectEntity $object = null,
		?string $register = null,
	): bool;
}//end interface
