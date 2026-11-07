<?php

/**
 * PermissionHandlerInterface: the public surface of PermissionHandler, for callers to type against.
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
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Service\Rbac\MatrixRowsFromApps;

/**
 * Every public method of PermissionHandler. Callers depend on this interface, not on the class.
 */
interface PermissionHandlerInterface {

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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC flag follows established API patterns
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

	/**
	 * The deny rule that is allowed to change this answer, or null.
	 *
	 * The ENFORCED verdict, which is the pure resolution in
	 * {@see denialFor()} read through the switch in
	 * {@see \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode}. Every
	 * enforcement point calls this one and none of them reads the switch itself,
	 * so the object read and the list query cannot end up in different modes
	 * inside one request.
	 *
	 * THE THREE MODES, in the order they cost anything:
	 *
	 *  - `off`       nothing is resolved and nothing is recorded.
	 *  - `staging`   the denial is resolved, recorded and dropped. The default.
	 *  - `enforcing` the denial is resolved and returned.
	 *
	 * The administrator exemption and the precedence rule live one method down,
	 * in the resolution itself, because they are properties of the deny and not
	 * of the rollout.
	 *
	 * @param array|null           $authorization The already-cascaded authorization block.
	 * @param string               $action        The verb being decided.
	 * @param string|null          $userId        The caller, or null to resolve from the session.
	 * @param ObjectEntity|null    $object        The row under evaluation, when there is one.
	 * @param array<string, mixed> $context       Where this happened, for the staging record.
	 *
	 * @return array{rule: mixed, principal: string, action: string, conditional: bool}|null
	 *         The denial that decides it, or null when none reaches this caller
	 *         or the instance is not enforcing yet.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function enforcedDenialFor(
		?array $authorization,
		string $action,
		?string $userId = null,
		?ObjectEntity $object = null,
		array $context = [],
	): ?array;

	/**
	 * The verbs this caller may exercise on one row.
	 *
	 * Resolved through {@see hasPermission()}, verb by verb, which is the same
	 * decision the read itself made and is memoised per request on
	 * `(user, schema, action, owner, uuid)`. So a record returned with its
	 * actions costs the resolutions the client would have had to provoke
	 * anyway, and the client stops guessing: today it either renders a delete
	 * button nobody may press, or hides one somebody may (design D-9).
	 *
	 * THE SCHEMA VERBS ARE NOT IN THE LIST. `create`, `list` and `manage` are
	 * answers about the schema and the register, not about this row, and a
	 * record that carried them would invite a client to read them as rights ON
	 * the row. The row verbs are `read`, `update`, `delete`, `destroy` and every
	 * custom verb an app declared, because those are the ones an object screen
	 * offers.
	 *
	 * @param Schema            $schema The schema the row belongs to.
	 * @param ObjectEntity|null $object The row, when the question is about one.
	 * @param string|null       $userId The caller, or null to resolve from the session.
	 *
	 * @return array<int, string> The verbs, in catalogue order.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function permittedActionsFor(
		Schema $schema,
		?ObjectEntity $object = null,
		?string $userId = null,
	): array;

	/**
	 * Why this caller may or may not do each of these verbs.
	 *
	 * The answer per action names the rule that decided it: the object's own
	 * block, the schema rule, the register default, the named role, or the deny
	 * that removed it. A security officer asking "why can this person update
	 * this dossier" got a yes before this existed, which answers a different
	 * question, and an absence had no reason at all.
	 *
	 * THE COST IS A FIELD, NOT A PASS. The cascade is resolved once here, the
	 * same resolution the verdict uses, and the levels are then read back for
	 * the naming. Nothing is evaluated twice (ADR-009).
	 *
	 * STAGED DENIES RIDE ALONG. Below `enforcing` the grant stands and the deny
	 * that would remove it is reported as `stagedDeny`, so the field that says
	 * why a person may act also says what stops them the day the switch moves.
	 *
	 * @param Schema             $schema  The schema being reported on.
	 * @param array<int, string> $actions The verbs to report.
	 * @param string|null        $userId  The caller, or null to resolve from the session.
	 * @param ObjectEntity|null  $object  The row, when the question is about one.
	 *
	 * @return array<string, array<string, mixed>> The provenance, keyed by action.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function provenanceFor(Schema $schema, array $actions, ?string $userId = null, ?ObjectEntity $object = null): array;

	/**
	 * The deny rule that removes this verb from this caller, or null.
	 *
	 * The PURE resolution, with no reference to the enforcement switch. It
	 * answers "which rule denies this", which is the question provenance asks in
	 * every mode, including `staging` where the answer is reported and not
	 * applied. Callers that want the enforced verdict call
	 * {@see enforcedDenialFor()} instead.
	 *
	 * This is the whole of the negative half of the model, and it is one method
	 * so that the object read, the relation-path check and both list emitters
	 * cannot honour different halves of it.
	 *
	 * PRECEDENCE. A deny is checked before every grant, including the owner
	 * bypass and a grant inherited from an ancestor object. Deny wins; that is
	 * the only rule that keeps the answer predictable, and letting a more
	 * specific grant put the verb back is how a deny quietly stops working.
	 *
	 * THE ADMINISTRATOR IS EXEMPT, on purpose and in one place. A deny that
	 * reached the `admin` group could remove the right to edit the rules that
	 * removed it, and an instance in that state is recoverable only from the
	 * database. The save-time refusal in {@see
	 * \OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator} guards the
	 * register's own `manage` verb; this guards the platform administrator.
	 *
	 * A CONDITIONAL DENY NEEDS A ROW. An entry carrying a `match` clause removes
	 * the verb only for the objects the clause selects, so with no object in
	 * hand it denies nothing: the caller is asking about the schema, not about a
	 * row, and refusing there would turn a row-scoped rule into a blanket one.
	 *
	 * @param array|null        $authorization The already-cascaded authorization block.
	 * @param string            $action        The verb being decided.
	 * @param string|null       $userId        The caller, or null to resolve from the session.
	 * @param ObjectEntity|null $object        The row under evaluation, when there is one.
	 *
	 * @return array{rule: mixed, principal: string, action: string, conditional: bool}|null
	 *         The denial that decided it, or null when no deny reaches this caller.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function denialFor(?array $authorization, string $action, ?string $userId = null, ?ObjectEntity $object = null): ?array;

	/**
	 * Reset the per-request permission verdict cache.
	 *
	 * Long-running CLI processes (e.g. background jobs that span multiple
	 * requests within a single PHP process) can call this to invalidate the
	 * memoised verdicts without instantiating a new handler.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
	 */
	public function clearPermissionCache(): void;

	/**
	 * Clear the per-request inheritFromPublic resolution cache.
	 *
	 * The cache keys purely on schema id, which is collision-free only while
	 * schema/register/IAppConfig authorization is immutable mid-request. Any
	 * path that mutates authorization and then re-reads it within the same
	 * request must bust this cache to avoid serving a stale verdict.
	 *
	 * @param int|null $schemaId Specific schema to evict, or null to clear all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function clearInheritFromPublicCache(?int $schemaId = null): void;

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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC flag follows established API patterns
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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC/multitenancy flags follow established API patterns
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
	 * @param array|null $authorization The schema's authorization array
	 * @param string $groupId The group ID to check
	 * @param string $action The CRUD action (create, read, update, delete)
	 * @param string|null $userId Optional user ID for owner check
	 * @param string|null $userGroup Optional user group for admin check
	 * @param string|null $objectOwner Optional object owner for ownership check
	 * @param array|null $objectData Optional object data for conditional matching
	 * @param string|null $objectOrganisation Optional object organisation (folded into @self.organisation)
	 *
	 * @return bool True if the group has permission
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function hasGroupPermission(
		?array $authorization,
		string $groupId,
		string $action,
		?string $userId = null,
		?string $userGroup = null,
		?string $objectOwner = null,
		?array $objectData = null,
		?string $objectOrganisation = null,
	): bool;

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

	/**
	 * Return the list of user IDs authorised to read a given object.
	 *
	 * This is used by NotifyPushListener to fan out per-user push events without
	 * iterating every Nextcloud user with a per-user permission check. The approach
	 * is query-based:
	 *
	 * 1. Resolve the effective authorization for the object's schema.
	 * 2. Extract the groups that have `read` permission.
	 * 3. Fetch member user IDs for each group via IGroupManager (one DB call per group).
	 * 4. Return the deduplicated union.
	 *
	 * Special cases:
	 *   - No authorization configured → empty array (caller should treat as "all users"
	 *     and broadcast or skip push, depending on policy).
	 *   - Authorization exists but object owner is known → always include the owner.
	 *   - `public` group in the read list → empty array (caller should treat as broadcast).
	 *   - `admin` group in the read list → empty array (caller should treat as broadcast
	 *     or resolve admin members separately).
	 *
	 * @param ObjectEntity $object The object whose authorised readers are requested.
	 *
	 * @return array<string> Deduplicated list of user IDs, or empty array when the
	 *                       object is publicly readable (no restriction) or the schema
	 *                       cannot be resolved.
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-3
	 */
	public function getReadableByUsers(ObjectEntity $object): array;

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
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/file-actions/spec.md
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

	/**
	 * Decide whether a caller may perform a lifecycle transition that
	 * declares a per-transition `authorization` list.
	 *
	 * This is the declarative counterpart to the `requires` PHP-guard seam on
	 * `x-openregister-lifecycle` transitions: a schema author lists the
	 * Nextcloud group ids (and/or register-named roles) permitted to drive a
	 * given transition, and OpenRegister enforces it on the standard
	 * `saveObject()` path WITHOUT the author having to ship a guard class.
	 *
	 * Resolution rules (reusing the SAME `IGroupManager` membership check that
	 * every other RBAC verdict in this handler trusts):
	 *  - The `admin` group always passes (mirrors {@see hasGroupPermission()}).
	 *  - A bare string entry is a literal NC group id; the caller passes when
	 *    {@see IGroupManager::isInGroup()} is true for it.
	 *  - An entry shaped `{ "role": "<name>" }` is expanded to the NC group ids
	 *    assigned to that register-named role via the schema's
	 *    `authorization.roles` map (the same `roles: {name: [groups]}` syntax
	 *    {@see expandRoles()} consumes), then matched the same way.
	 *
	 * Fail-closed posture (CWE-863): an EMPTY list authorises nobody; an
	 * anonymous caller (null/unknown uid) is denied; an entry that cannot be
	 * resolved to a concrete group is skipped (never widens access). A
	 * transition WITHOUT an `authorization` list never reaches this method —
	 * the listener only calls it when the key is present — so existing
	 * transitions are completely unaffected (additive).
	 *
	 * @param array<int, mixed> $authorizationList Group ids / `{role}` entries permitted to perform the transition.
	 * @param string|null $userId Caller uid, or null for an anonymous principal.
	 * @param Schema $schema Schema the transition belongs to (for named-role expansion).
	 *
	 * @return bool True when the caller is authorised; false (fail-closed) otherwise.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Anonymous guard + admin bypass + literal-vs-role entry handling are each one irreducible branch.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
	 */
	public function isTransitionAuthorized(array $authorizationList, ?string $userId, Schema $schema): bool;
}//end interface
