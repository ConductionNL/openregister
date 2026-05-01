# Tasks: RBAC Scopes

> **Status (Phase 4):** The core 3-level RBAC implementation (PermissionHandler / MagicRbacHandler / PropertyRbacHandler) is in production and verified by the unify-rbac-condition-matching + row-field-level-security integration tests. Phase 4 ships role definitions and hierarchy: schemas use a compact `authorization.roles: {roleName: [groupIds]}` syntax, registers declare `configuration.roles: [{name, actions, description?, extends?}]`, and `PermissionHandler::expandRoles()` flattens the hierarchy into action-level group lists with cycle detection + multi-parent inheritance. 11 of 13 tasks tickably complete; 2 left open: Custom Scope Definitions, OAuth 2.0 Token Scopes Integration.

## Implemented

- [x] **Scope Model Hierarchy (Register > Schema > Object > Property).** Four-level hierarchy is in place:
  - **Register-level**: `Register::getAuthorization()` provides default rules.
  - **Schema-level**: `Schema::getAuthorization()` overrides register defaults; rules consumed by `PermissionHandler::hasPermission`.
  - **Object-level (row-level)**: `match` blocks on schema rules + `MagicRbacHandler::applyRbacFilters` emit per-row SQL predicates.
  - **Property-level (field-level)**: `Schema::getPropertyAuthorization($prop)` + `PropertyRbacHandler::filterReadableProperties`.

  **Verified live** by `RbacOperatorMatchingIntegrationTest` (RLS) + `RowFieldLevelSecurityIntegrationTest` (FLS).

- [x] **Permission Types (read, create, update, delete, list).** Five-action vocabulary supported by PermissionHandler. Each action can have its own rule list per schema.

- [x] **Conditional Scopes with Dynamic Variables.** `$now`, `$userId`, `$organisation`, `@self.created`, `@self.owner` resolve at evaluation time via `ConditionMatcher`. Verified by the `$lte: $now` and `$userId` cases.

- [x] **Nextcloud Group Mapping.** Rules use `group: <ncGroupId>` to bind to Nextcloud groups; `IGroupManager::getUserGroupIds` provides the user's groups for matching.

- [x] **Scope Resolution Algorithm.** `PermissionHandler` evaluates rules in order: admin bypass first, then per-action rule list. Schema-level rules take precedence over register-level; object-level match clauses further constrain row visibility. Property-level rules apply on top of schema-level reads.

- [x] **Multi-Tenancy Integration with Scopes.** RBAC + multi-tenancy compose via the standard `MultiTenancyTrait` filters. Multi-tenant queries scope to active org first; RBAC rules apply on top.

- [x] **Scope Inheritance (Register Permissions Cascade to Schemas).** `Schema::resolveAuthorization()` falls through to `Register::getAuthorization()` when a schema has no own authorization block.

- [x] **Scope Documentation and Discovery API.** `lib/Controller/ScopesController.php` exposes `GET /apps/openregister/api/scopes` with optional `?register=…&schema=…` filters. The endpoint walks every (register, schema) pair the caller can see and probes `PermissionHandler::hasPermission` for each of the five canonical actions (`read`, `create`, `update`, `delete`, `list`), returning the truthy subset. Admin callers short-circuit to the full vocabulary, mirroring the bypass branch in `PermissionHandler::hasPermission`. Schemas where the user has zero actions are omitted (cleanly hides gated content). Verified live by `tests/Service/RbacScopeDiscoveryIntegrationTest` (5 tests): envelope-shape contract, non-admin-only-permitted-actions (creates a fresh Nextcloud user with public-only group membership and asserts admin-gated `update`/`delete` are stripped), admin-bypass returns all five actions, register-filter narrows the response, unknown-register filter yields zero scopes.

## Open / partial

- [x] **Role Definitions and Hierarchy.** Registers declare named roles under `configuration.roles: [{name, actions, description?, extends?}]`. Schemas reference roles via the compact `authorization.roles: {roleName: [groupIds]}` syntax. `PermissionHandler::expandRoles()` flattens the role assignments into per-action group lists by walking each role's `extends` chain (string OR array of role names), accumulating inherited actions before the role's own actions, deduplicating per group. Cycles like `a extends b extends a` abort safely with a logged warning, multi-parent inheritance composes the union of parent action sets, unknown role references log warnings without breaking the dispatch, and registers without role definitions on the parent log + return the rolesless authorization untouched. **Verified** by 10 unit tests in `tests/Unit/Service/Object/PermissionHandlerRoleHierarchyTest`: flat expansion, multi-role group merge, single-level extends, multi-level extends (admin → editor → viewer), array-form extends with multiple parents, cycle detection + log, unknown extends ignored, authorization without roles untouched, unknown role assignment logged, register without role definitions logged.

- [x] **OAS Scope Generation from RBAC Configuration.** `OasService::createOas` already populates the `components.securitySchemes.oauth2.flows.authorizationCode.scopes` map from the union of every schema's groups. Phase 3 extends `OasService::applyRbacToOperation()` to also emit per-operation `security: [{ "oauth2": [groups] }, { "basicAuth": [] }]` requirements, which makes the OAS a machine-readable access audit (consumed by Swagger UI, generated SDKs, and reverse proxies). The existing description-rendering and 403 response are preserved. Verified by 5 new unit tests in `tests/Unit/Service/OasServiceTest` (Oauth2 block emission, admin-when-empty, dedup, admin-first ordering, no mutation of unrelated keys).

- [x] **Scope Caching for Performance.** `PermissionHandler::hasPermission()` now short-circuits on a per-request memoisation cache keyed on `(schemaId, action, userId|null, objectOwner|null, objectUuid|null)`. The first call resolves the user via `IUserManager` + `IGroupManager` and walks the rule chain; subsequent calls within the same request reuse the verdict directly. Caching is bypassed when the schema has no ID, or when an object is supplied without a UUID (transient/in-memory entity whose data could differ between calls). The `_rbac=false` bypass short-circuits before the cache lookup, so RBAC disablement remains free. A `clearPermissionCache()` entry point is provided for long-running CLI processes that span multiple logical requests. Verified by 7 new unit tests in `tests/Unit/Service/Object/PermissionHandlerCacheTest`.

- [ ] **Custom Scope Definitions.** Not implemented — the scope vocabulary is fixed (action × entity-level). No support for app-defined custom scopes. **Open** — design question.

- [ ] **OAuth 2.0 Token Scopes Integration.** Not implemented — Nextcloud's OAuth2 layer is separate; OR doesn't translate RBAC rules into OAuth2 token scope requirements. **Open** — gated on the broader auth-system spec.

## Test coverage

The implemented portions are covered by the existing test suites:

- `tests/Service/RbacOperatorMatchingIntegrationTest` — 4 tests (schema-level RLS via `ConditionMatcher`, closed under unify-rbac-condition-matching).
- `tests/Service/RowFieldLevelSecurityIntegrationTest` — 7 tests (FLS metadata + filtering, closed under row-field-level-security).
- `tests/Unit/Service/Object/PermissionHandlerRbacTest` — 30 tests (mocked-user RBAC dispatch, all rule types).
- `tests/Unit/Service/Object/PermissionHandlerCacheTest` — 7 tests (Phase 3: request-scoped permission verdict cache, conditional-rule per-UUID re-evaluation, RBAC bypass short-circuit, clearPermissionCache invalidation).
- `tests/Unit/Service/OasServiceTest` — 190 tests including 5 new `applyRbacToOperation` tests for the per-operation OAS `security` block emission (Phase 3).
- `tests/Unit/Db/MagicMapper/MagicRbacHandlerTest` — 14 tests (SQL-emission RBAC + admin/owner bypass).
- `tests/Service/RbacScopeDiscoveryIntegrationTest` — 5 tests covering the scope discovery endpoint end-to-end (envelope shape, non-admin-only-permitted-actions, admin-bypass, register filter, unknown-register filter).
- `tests/Unit/Service/Object/PermissionHandlerRoleHierarchyTest` — 10 tests covering role expansion + the new `extends` hierarchy keyword (flat, multi-role, single + multi-level extends, array-form multi-parent extends, cycle detection, unknown extends, no-roles-key passthrough, unknown role assignment, register without role definitions).
