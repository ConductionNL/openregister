<?php

/**
 * PermissionScopeInterface: Narrows object sets and groups to what the caller may reach.
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

/**
 * UUID filtering, the active organisation and group verdicts of PermissionHandler.
 *
 * Callers that need only this role depend on it, not on the whole handler.
 */
interface PermissionScopeInterface {

	/**
	 * Filter UUIDs based on RBAC and multi-tenancy permissions
	 *
	 * Takes an array of UUIDs, loads the corresponding objects, and filters them
	 * based on current user permissions and organization context.
	 *
	 * @param array<string> $uuids Array of object UUIDs to filter.
	 * @param bool $_rbac Whether to apply RBAC filtering.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return string[] Filtered array of UUIDs
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function filterUuidsForPermissions(array $uuids, bool $_rbac, bool $_multitenancy): array;

	/**
	 * Get the active organisation UUID for the current context
	 *
	 * @return string|null The active organisation UUID or null if none set
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function getActiveOrganisationForContext(): ?string;

	/**
	 * Check if a specific group has permission for a CRUD action on a schema
	 *
	 * Rules:
	 * - Admin group always has all permissions
	 * - Object owner always has all permissions for their specific objects
	 * - If no authorization is set, everyone has permission
	 * - If authorization is set but the action is not listed, no one has
	 *   permission (fail-closed) — except the admin/owner bypasses above
	 *
	 * Deduplication note:
	 *   Conditional match evaluation (rules with a `match` clause) is delegated to
	 *   {@see \OCA\OpenRegister\Service\ConditionMatcher}. ConditionMatcher is the
	 *   single PHP-side conditional-match evaluator used across the RBAC stack:
	 *   PropertyRbacHandler (property-level), PermissionHandler (schema-level), and
	 *   MagicRbacHandler::hasPermission() (row-level PHP path). The SQL emission
	 *   path in MagicRbacHandler::applyRbacFilters() is the only specialised
	 *   interpreter of the same rule grammar — it produces SQL WHERE fragments
	 *   instead of PHP verdicts. Do not introduce a fourth match evaluator here.
	 *
	 * @param GroupRuleRequest $request The authorization block and action, the group and the caller's
	 *                                  group, the user and object owner, and the object data and organisation.
	 *
	 * @return bool True if the group has permission
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function hasGroupPermission(GroupRuleRequest $request): bool;

	/**
	 * Get all groups that have permission for a specific action
	 *
	 * @param array|null $authorization The schema's authorization array
	 * @param string $action The CRUD action to check
	 *
	 * @return array Array of group IDs that have permission, or empty array if all groups have permission
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function getAuthorizedGroups(?array $authorization, string $action): array;
}//end interface
