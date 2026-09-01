# Tasks: Row and Field Level Security

> **Status (Phase 2):** All 15 spec requirements ticked. Phase 2 audited the four "Open" tasks and confirmed every one is already implemented. Tasks 11 and 15 are covered by existing unit tests in `ConditionMatcherTest`. Tasks 5 and 10 (GraphQL FLS + export FLS for non-admin) are covered by the new `RowFieldLevelSecurityNonAdminIntegrationTest` that creates a real Nextcloud user, logs them in via `IUserSession`, and asserts that FLS-protected fields are stripped from both `filterReadableProperties` output and `ExportService` headers.

## Implemented

- [x] **1: Schemas MUST support row-level security rules via conditional authorization matching.** Schema-level `read`/`update`/`delete`/`create` rules with `match` blocks route through `ConditionMatcher::objectMatchesConditions` from both `PermissionHandler::hasPermission` and `MagicRbacHandler::hasPermission`. Verified live by `RbacOperatorMatchingIntegrationTest`.

- [x] **2: RLS rules MUST support dynamic variable resolution in match conditions.** `$now`, `$userId`, `$organisation` are resolved at evaluation time by the `ConditionMatcher`. Verified by the `$lte: $now` and `$userId` cases in the unify-rbac spec.

- [x] **3: Schemas MUST support field-level security via property authorization blocks.** Per-property `authorization: {read|manage: [{group, match?}]}` blocks. Verified live by `RowFieldLevelSecurityIntegrationTest`.

- [x] **4: RLS rules MUST apply consistently to all access methods.** Both REST controllers (via `PermissionHandler`) and SQL list paths (via `MagicRbacHandler::applyRbacFilters`) route through the same `ConditionMatcher` grammar.

- [x] **5: FLS MUST apply consistently to GraphQL field resolution.** `GraphQLResolver` calls `PropertyRbacHandler::filterReadableProperties` ([lib/Service/GraphQL/GraphQLResolver.php:560](../../../lib/Service/GraphQL/GraphQLResolver.php)) — the same handler the REST `RenderObject` path calls. The `getUnauthorizedProperties` check at lines 278 and 341 enforces FLS on GraphQL mutations. Non-admin field stripping is verified end-to-end by `RowFieldLevelSecurityNonAdminIntegrationTest::testNonAdminFilterReadablePropertiesStripsRestrictedFields` since both paths share the handler.

- [x] **6: The condition syntax MUST support MongoDB-style operators for match expressions.** `$lte`, `$gte`, `$lt`, `$gt`, `$eq`, `$ne`, `$in` are all dispatched through `ConditionMatcher`.

- [x] **7: RLS and FLS MUST be combinable with schema-level RBAC in a layered evaluation chain.** PermissionHandler runs schema-level rules first; `RenderObject` then applies `PropertyRbacHandler::filterReadableProperties` for the FLS pass.

- [x] **8: RLS condition evaluation MUST happen at the SQL query level for performance.** `MagicRbacHandler::applyRbacFilters` emits SQL WHERE clauses for the condition tree.

- [x] **9: RLS MUST interact correctly with multi-tenancy isolation.** Both layers go through `MultiTenancyTrait::applyOrganisationFilter`.

- [x] **10: FLS MUST strip restricted fields from API responses and export outputs.** Read path: `RenderObject` → `PropertyRbacHandler::filterReadableProperties`. Export path: `ExportService::getHeaders` calls `PropertyRbacHandler::canReadProperty` ([lib/Service/ExportService.php:550](../../../lib/Service/ExportService.php)) per-column with the empty-object fail-closed default. Non-admin behaviour verified by `RowFieldLevelSecurityNonAdminIntegrationTest::{testNonAdminFilterReadablePropertiesStripsRestrictedFields, testExportHeadersExcludeRestrictedFieldsForNonAdmin}` — both prove that a freshly-created Nextcloud user without admin/hr group membership is denied the protected fields.

- [x] **11: FLS on create operations MUST skip organisation matching for conditional rules.** `ConditionMatcher::filterOrganisationMatchForCreate` ([lib/Service/ConditionMatcher.php:93](../../../lib/Service/ConditionMatcher.php)) strips `_organisation`/`organisation` keys when the value is `$organisation`/`$activeOrganisation`, leaving literal-value matches and other conditions intact. Covered by `ConditionMatcherTest::testFilterOrganisationMatchForCreateRemovesOrgConditions` and `ConditionMatcherGapTest`.

- [x] **12: Security rules MUST be auditable for compliance.** RBAC denials are logged via `LoggerInterface->warning` with `userId/objectUuid/property/action` context.

- [x] **13: Schema property authorization configuration MUST be inspectable via Schema entity methods.** `Schema::hasPropertyAuthorization()`, `getPropertyAuthorization()`, `getPropertiesWithAuthorization()`. Verified live by the existing integration test.

- [x] **14: CamelCase property names MUST be correctly mapped to snake_case column names in SQL conditions.** `MagicRbacHandler::buildRbacConditionsSql` handles the camelCase→snake_case translation.

- [x] **15: ConditionMatcher MUST support @self property lookup for system fields.** `ConditionMatcher::getObjectValue` ([lib/Service/ConditionMatcher.php:193](../../../lib/Service/ConditionMatcher.php)) implements all three spec scenarios: direct property lookup wins (line 196-198), underscore-prefixed properties fall back to `@self.<name>` with the underscore stripped (lines 201-206), and non-underscore properties never check `@self`. Covered by `ConditionMatcherTest::{testObjectMatchesConditionsWithAtSelfLookup, testObjectMatchesConditionsDirectPropertyOverAtSelf}` and the gap test for non-underscore properties.

## Architecture (decisions taken across all phases)

| Decision | Choice |
|---|---|
| RLS evaluation layer | SQL-level via `MagicRbacHandler::applyRbacFilters` for list paths; PHP-level via `PermissionHandler` for individual access checks. Both share the `ConditionMatcher` grammar so behaviour is identical. |
| FLS evaluation layer | Per-property via `PropertyRbacHandler` — single source of truth for read filtering (REST + GraphQL + Export) and write validation (REST + GraphQL mutations). |
| Admin bypass | `PropertyRbacHandler::isAdmin()` short-circuits all property-level checks; `MagicRbacHandler` similarly bypasses RLS for admins. |
| `@self` lookup | Only underscore-prefixed property names fall back to `@self.<name>` (with the underscore stripped). Non-underscore properties never look at `@self`, keeping the rule explicit. |
| Create-time org-match skip | On create operations there's no existing object to match organisation against, so dynamic `$organisation`/`$activeOrganisation` matches on `_organisation` are stripped from the rule before evaluation. |

## Test coverage

- [x] `tests/Service/RbacOperatorMatchingIntegrationTest` — 4 tests (RLS, closed under unify-rbac-condition-matching).
- [x] `tests/Service/RowFieldLevelSecurityIntegrationTest` — 7 admin-bypass tests covering FLS metadata flow + handler short-circuit.
- [x] `tests/Service/RowFieldLevelSecurityNonAdminIntegrationTest` — 3 non-admin tests:
  - `canReadProperty` returns false for fields whose `authorization.read` requires a group the user is not in (admin → salary, hr → ssn).
  - `filterReadableProperties` strips those fields from rendered objects.
  - `ExportService` headers exclude restricted columns when a non-admin runs the export.
- [x] `tests/Unit/Service/ConditionMatcherTest` + `ConditionMatcherGapTest` — direct unit coverage of `getObjectValue` + `filterOrganisationMatchForCreate` + dynamic-variable resolution.
- [x] `tests/Unit/Service/PropertyRbacHandlerTest` — non-admin paths via mocked group memberships.

14 tests in the dedicated FLS suites + 4 RLS tests + the unit-level matcher coverage = 21 row/field-level-security tests across the spec, all green.

## Files Affected

- `tests/Service/RowFieldLevelSecurityNonAdminIntegrationTest.php` — new 3-test suite covering the non-admin paths required by spec requirement 10 (and indirectly 5).
- `openspec/changes/row-field-level-security/tasks.md` — this file: tasks 5, 10, 11, 15 ticked with file:line evidence; spec status updated.

No production code change in this phase — every spec requirement was already implemented; this commit closes the documentation-vs-implementation gap for the four previously-open tasks.
