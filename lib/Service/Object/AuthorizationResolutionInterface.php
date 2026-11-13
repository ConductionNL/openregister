<?php

/**
 * AuthorizationResolutionInterface: Resolves the effective and declared authorization block of a schema.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Service\Rbac\MatrixRowsFromApps;

/**
 * The authorization cascade of PermissionHandler.
 *
 * Callers that need only this role depend on it, not on the whole handler.
 */
interface AuthorizationResolutionInterface {

	/**
	 * Resolve the effective authorization for a schema.
	 *
	 * Precedence (highest wins):
	 *   1. Per-object `_authorization` (column on ObjectEntity)
	 *      — overrides schema/register rules for that specific object only,
	 *        merged action-by-action (an object that defines `update` overrides
	 *        the schema's `update`; actions absent from the object inherit from
	 *        the schema).
	 *   2. Schema-level `authorization` block.
	 *   3. Parent register's `authorization` block.
	 *   4. `null` (no rules configured → default-open under
	 *      {@see hasGroupPermission()} unless the
	 *      {@see CONFIG_ENFORCE_DEFAULT_CLOSED} flag is set).
	 *
	 * Role references in the authorization are expanded to action-level
	 * permissions.
	 *
	 * Wave-12 Fix 5: per-object `_authorization` was dead storage before this
	 * change. The audit at `/tmp/wave11-or-engine-primitives.md` Section F
	 * documents the gap.
	 *
	 * @param Schema $schema The schema to resolve authorization for.
	 * @param ObjectEntity|null $object Optional object entity whose `_authorization`
	 *                                  column (when non-empty) overrides
	 *                                  schema/register rules action-by-action.
	 *
	 * @return array|null The effective authorization array, or null if none configured.
	 *
	 * Returns null when NO authorization is configured anywhere in the cascade
	 * (callers treat that as open). It THROWS when the cascade could not be
	 * evaluated — callers MUST treat that as deny, never as "no rules".
	 *
	 * @throws AuthorizationUnresolvableException When the register cascade cannot be resolved. Callers MUST deny.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md#requirement-authorization-resolution-fails-closed
	 */
	public function resolveAuthorization(Schema $schema, ?ObjectEntity $object = null): ?array;

	/**
	 * The authorization block as DECLARED in the cascade (schema or register,
	 * then the object's own block), before the department matrix is compiled,
	 * expiries applied or a token narrows it.
	 *
	 * For a question about which rules a schema has opted into, not about what
	 * they grant now: a matrix row past its `until`, or provider rows that did
	 * not arrive, compile to nothing, and a regime decided from the compiled
	 * block would fall away with them.
	 *
	 * @param Schema            $schema The schema.
	 * @param ObjectEntity|null $object The object, whose own block layers on top.
	 *
	 * @return array<string, mixed>|null The declared block, or null when none is configured.
	 *
	 * @throws AuthorizationUnresolvableException When the register cascade cannot be resolved. Callers MUST deny.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function resolveDeclaredAuthorization(Schema $schema, ?ObjectEntity $object = null): ?array;

	/**
	 * The asker of app-contributed matrix rows, built once.
	 *
	 * Built here rather than injected for the same reason as the resolvers
	 * above: a new required constructor argument is a fatal at every existing
	 * construction site.
	 *
	 * @return MatrixRowsFromApps The asker of app-contributed matrix rows.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function matrixRowsFromApps(): MatrixRowsFromApps;

	/**
	 * Resolve whether authenticated users inherit `public` group rights for a schema.
	 *
	 * Cascade (first explicit boolean wins): schema authorization →
	 * register authorization → tenant-wide IAppConfig
	 * `openregister.rbac.inherit_from_public_default` → hard-coded `true`.
	 * A `null` (or any non-boolean) value at a level is treated as "unset" so
	 * the cascade falls through. Anonymous users are never affected by this flag.
	 *
	 * @param Schema $schema The schema being evaluated.
	 *
	 * @return bool True when authenticated users inherit `public` rights.
	 *
	 * @spec openspec/changes/rbac-disable-public-inheritance/tasks.md
	 */
	public function resolveInheritFromPublic(Schema $schema): bool;

	/**
	 * Read the tenant-wide inheritFromPublic default from IAppConfig.
	 *
	 * This is both the terminal cascade step and the fail-safe used when
	 * resolution errors out. When IAppConfig itself is unreachable it logs at
	 * error level and returns `true` (pre-PR posture) so a config-store outage
	 * does not hard-deny every authenticated read.
	 *
	 * @return bool The configured tenant default (true when unconfigured/unreachable).
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function inheritFromPublicTenantDefault(): bool;

	/**
	 * Expand role references in an authorization block to action-level permissions.
	 *
	 * If the authorization contains a 'roles' key mapping role names to group arrays,
	 * this method resolves each role's actions from the parent register's configuration
	 * and merges the resulting group-to-action mappings into the authorization.
	 *
	 * Example input:
	 *   authorization: { "roles": { "viewer": ["public"], "editor": ["behandelaars"] } }
	 *   register roles: [{ name: "viewer", actions: ["read"] }, { name: "editor", actions: ["read","create","update"] }]
	 *
	 * Example output:
	 *   { "read": ["public", "behandelaars"], "create": ["behandelaars"], "update": ["behandelaars"] }
	 *
	 * @param array $authorization The authorization block to expand.
	 * @param Schema $schema The schema (used to find parent register for role definitions).
	 *
	 * @return array The authorization with roles expanded to action-level entries.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function expandRoles(array $authorization, Schema $schema): array;
}//end interface
