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

- [x] **Custom Scope Definitions.** **Decision (2026-05-02): option A.** Implemented + verified 2026-05-02:
  - New `lib/Event/CustomScopeEvaluatingEvent.php` carrying `(schema, action, userId, userGroups, object?)` and `allow()` / `deny()` verdict mutators. The first verdict wins; subsequent calls are no-ops so the verdict order is deterministic regardless of listener registration order.
  - New `lib/Event/CustomScopeEvaluatedEvent.php` (telemetry) dispatched after a listener vote with `verdict` and `fromListener` fields. Not dispatched when no listener voted — that case falls through to the standard rule chain whose audit/log paths already cover it.
  - `PermissionHandler` gains a private `CANONICAL_ACTIONS = ['read', 'create', 'update', 'delete', 'list']` constant and an optional `IEventDispatcher` constructor dependency. In `evaluatePermission()`, after admin bypass and before the standard rule chain: if action is non-canonical AND a dispatcher is wired, dispatch the evaluating event; if a verdict was cast, return it (and dispatch the paired telemetry event). Otherwise fall through to the existing rule chain. Canonical actions skip the event entirely so the hot path stays cost-zero.
  - 5 unit tests in `tests/Unit/Service/Object/PermissionHandlerCustomScopeTest.php`: listener allow grants action, listener deny rejects, first verdict wins (allow → deny no-op), no-listener falls through to standard chain, canonical actions skip dispatch.
  - Full PermissionHandler regression: 52 tests / 128 assertions, all green.
  - **Pending follow-up (NOT a blocker for the runtime contract):** parsing `configuration.actions: [{name, description?}]` on Register, OasService emission of custom verbs, ScopesController returning them. The runtime mechanism (event-based dispatch) is what apps need to hook into; the configuration parsing is documentation surface that can land independently.
  - **Procest follow-up issue:** ZGW code in procest needs to be refactored from direct calls into event-based listeners now that custom scopes are evaluated through this dispatcher — tracked in [ConductionNL/procest#307](https://github.com/ConductionNL/procest/issues/307).

- [x] **OAuth 2.0 Token Scopes Integration.** **Resolution (2026-05-02): handed off to the `auth-system` spec.** OR doesn't translate RBAC rules into OAuth2 token scope requirements; Nextcloud's OAuth2 layer is separate. Decision (option B): the translation requirement is added to `openspec/specs/auth-system/spec.md` as `Requirement: OAuth2 token scopes MUST translate to RBAC verdicts` with 4 scenarios. The OAS already emits `security: [{ "oauth2": [groups] }, { "basicAuth": [] }]` per operation (Phase 3) so external proxies have machine-readable metadata to enforce against; the gap is internal binding from token-scope to RBAC verdict, which `auth-system` now owns. Closing on `rbac-scopes` because the work moved by design — it's documented as the boundary on this change's cross-references.

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
