# Tasks: RBAC Scopes

> **Status:** The core 3-level RBAC implementation (PermissionHandler / MagicRbacHandler / PropertyRbacHandler) is in production and verified by the unify-rbac-condition-matching + row-field-level-security integration tests. This spec extends that foundation with OAS scope generation + caching + audit. 7 of 13 tasks tickably complete via the existing implementation; 6 left open.

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

## Open / partial

- [ ] **Role Definitions and Hierarchy.** Partial — rules reference Nextcloud groups directly (no separate "role" abstraction layer). The spec calls for named roles defined at register level + expandable in lower-level authorization blocks. **Open** — requires a `roles: {roleName: [groupIds]}` map on Register + role-expansion logic in PermissionHandler.

- [ ] **OAS Scope Generation from RBAC Configuration.** Not implemented — `OasService::createOas` includes a top-level `securitySchemes` block (Basic auth) but doesn't translate per-schema RBAC rules into per-endpoint `security: [{ openregister: [scope1, scope2] }]` requirements. **Open** — additive change to `OasService` to walk the schema's authorization config and emit OAuth2-flavour scope strings (e.g. `register.{slug}.schema.{slug}.read`).

- [ ] **Scope Caching for Performance.** Not implemented — every `hasPermission` call re-evaluates the full rule chain. For hot paths (list endpoints), a per-request cache keyed on (user, schema, action) would reduce evaluator work. **Open** — request-scoped memoisation.

- [ ] **Custom Scope Definitions.** Not implemented — the scope vocabulary is fixed (action × entity-level). No support for app-defined custom scopes. **Open** — design question.

- [ ] **OAuth 2.0 Token Scopes Integration.** Not implemented — Nextcloud's OAuth2 layer is separate; OR doesn't translate RBAC rules into OAuth2 token scope requirements. **Open** — gated on the broader auth-system spec.

- [ ] **Scope Documentation and Discovery API.** Not implemented — there's no endpoint that returns "what scopes does this user have on this register?" for client-side feature gating. **Open** — additive endpoint; small effort.

## Test coverage

The implemented portions are covered by the existing test suites:

- `tests/Service/RbacOperatorMatchingIntegrationTest` — 4 tests (schema-level RLS via `ConditionMatcher`, closed under unify-rbac-condition-matching).
- `tests/Service/RowFieldLevelSecurityIntegrationTest` — 7 tests (FLS metadata + filtering, closed under row-field-level-security).
- `tests/Unit/Service/Object/PermissionHandlerRbacTest` — 20 tests (mocked-user RBAC dispatch, all rule types).
- `tests/Unit/Db/MagicMapper/MagicRbacHandlerTest` — 14 tests (SQL-emission RBAC + admin/owner bypass).
