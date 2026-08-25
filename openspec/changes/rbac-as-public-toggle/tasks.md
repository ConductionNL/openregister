## 1. MagicRbacHandler — SQL/UNION path

- [x] 1.1 Add `bool $asPublic = false` to `buildRbacConditionsSql(Schema $schema, string $action = 'read', bool $asPublic = false)`. When true, override `$userId = null` and `$userGroups = []` at method entry so admin bypass fails and `_owner` OR-in auto-skips. Docblock the new parameter with a WOO-536/SCH-PFTS-001 reference.
- [x] 1.2 Add `bool $asPublic = false` to `applyRbacFilters(IQueryBuilder $qb, Schema $schema, string $action = 'read', bool $asPublic = false)` with identical forced-anon semantics. Docblock similarly.

## 2. MagicRbacHandler — PHP/find() path (per user decision Q2)

- [x] 2.1 Add `bool $_rbacAsPublic = false` to `PermissionHandler::hasPermission()` at line 160. When true, force `$userId = null`, `$userGroups = []`, skip admin-group bypass, do not include `_owner` grant.
- [x] 2.2 Add same parameter + semantics to `PermissionHandler::checkPermission()` at line 282.
- [x] 2.3 Add same parameter + semantics to `PermissionHandler::filterObjectsForPermissions()` and `PermissionHandler::filterUuidsForPermissions()` (thread through to `hasPermission`).

## 3. MagicSearchHandler — plumbing

- [x] 3.1 Add `_rbac_as_public` to `MagicSearchHandler::getReservedParams()`.
- [x] 3.2 Add `bool $asPublic = false` to `MagicSearchHandler::buildRbacConditionSql()` and thread to `$this->rbacHandler->buildRbacConditionsSql()`.
- [x] 3.3 In `buildWhereConditionsSql` and `buildFilteredQuery`: read `$_rbacAsPublic = $query['_rbac_as_public'] ?? false;` and thread to `buildRbacConditionSql` / `applyAccessControlFilters` respectively.
- [x] 3.4 Add `bool $_rbacAsPublic = false` to `applyAccessControlFilters(...)` and pass to `$this->rbacHandler->applyRbacFilters(..., asPublic: $_rbacAsPublic)`.
- [x] 3.5 Multitenancy interaction (RBA-PUBLIC-002): add `bool $_rbacAsPublic = false` to `resolveMultitenancyFlag(...)`. When true and `_multitenancy_explicit` is false, auto-bypass multitenancy (return `false`) so `MagicOrganizationHandler::applyOrganizationFilter` — which reads the live session directly and does NOT honour `_rbacAsPublic` — cannot filter an admin's rows to their active org while an anonymous caller receives `1=0`. Matches the pre-existing public-schema auto-bypass and preserves the SCH-PFTS-001 uniform-visibility contract. Discovered during review 2026-08-25.

## 4. ObjectService — HTTP hardening + method-param API (per user decision Q1)

- [x] 4.1 Add `bool $_rbacAsPublic = false` to `ObjectService::searchObjectsPaginated()` signature. At method entry, call the `normalizeRbacAsPublicFlag()` helper which strips any client-supplied `_rbac_as_public` from the query dict, then re-sets it only when the trusted method-parameter is `true`. Helper extracted 2026-08-25 so the security-property can be unit-tested without constructing the full ObjectService dependency graph. Docblock: only server-side callers may set this; HTTP query params are stripped.
- [x] 4.2 Add `bool $_rbacAsPublic = false` to `ObjectService::find()` signature. NO query-dict strip needed (find takes an ID). NOT threaded into `GetObject::find` — MagicMapper::find's RBAC block is a placeholder (lines 4327-4331 of MagicMapper.php), so threading it there would be dead-code plumbing. Instead threaded into `ObjectService::checkPermission` (the private wrapper at line 319) which is the actual PermissionHandler call site on the find() path.
- [x] 4.3 Update `ObjectService::checkPermission` (private wrapper) to accept `bool $_rbacAsPublic = false` and forward to `$this->permissionHandler->checkPermission(..., _rbacAsPublic: $_rbacAsPublic)`. This is where the find() path actually meets PermissionHandler on line 617 of ObjectService.php.

## 5. Unit tests

- [x] 5.1 `MagicRbacHandler::buildRbacConditionsSql_asPublic_*` — 4 tests + contract test in `tests/Unit/Db/MagicMapper/MagicRbacHandlerAsPublicTest.php`: ignores admin membership, skips owner OR-in, matches public-group rules, denies authenticated-only rules, admin+asPublic vs anon returns identical structure.
- [ ] 5.2 Same 4 tests for `MagicRbacHandler::applyRbacFilters` (QueryBuilder variant) — pending; QueryBuilder mocking is more involved. Contract-verified indirectly via 5.1 (both methods share the guard shape).
- [x] 5.3 `PermissionHandler::hasPermission_asPublic_*` — 5 tests in `tests/Unit/Service/Object/PermissionHandlerAsPublicTest.php`: ignores admin, matches public rule, denies authenticated, no owner grant, backwards-compat.
- [x] 5.4 HTTP-hardening tests — 7 tests in `tests/Unit/Service/ObjectServiceAsPublicTest.php` exercising `ObjectService::normalizeRbacAsPublicFlag` (the extracted helper backing the strip pattern): client-supplied flag stripped when method-param is false, trusted method-param re-sets the flag, string-truthy client value is also stripped (no type-coercion vulnerability), empty-query round-trips correctly.
- [x] 5.5 Contract test: admin session with `$_rbacAsPublic = true` returns the same conditions structure as an anonymous caller — covered by `testAsPublicTrueForAdminMatchesAnonForSameQuery` in 5.1.
- [x] 5.6 Multitenancy auto-bypass tests (RBA-PUBLIC-002) — 5 tests in `tests/Unit/Db/MagicMapper/MagicSearchHandlerAsPublicTest.php` exercising `resolveMultitenancyFlag`: forced-anon bypasses multitenancy on non-public schema when not explicit, explicit `_multi=true` overrides the auto-bypass, default behaviour preserved when `_rbacAsPublic` is off, pre-existing public-schema bypass still works, multitenancy stays off when it was already off.

## 6. Spec + docs + validation

- [x] 6.1 `openspec validate rbac-as-public-toggle` passes clean (verified 2026-08-25 after artifact updates).
- [x] 6.2 Docblock on `ObjectService::searchObjectsPaginated` explains the HTTP-hardening pattern: `_rbac_as_public` in the query dict is stripped and only the method-parameter is trusted (RBA-PUBLIC-005 reference in the block comment).

Acceptance criteria:

- All tests in tasks 5.x pass under `phpunit` (23+ new tests: 6 in `MagicRbacHandlerAsPublicTest`, 5 in `PermissionHandlerAsPublicTest`, 7 in `ObjectServiceAsPublicTest`, 5 in `MagicSearchHandlerAsPublicTest`).
- `openspec validate rbac-as-public-toggle` is clean.
- HTTP query with `?_rbac_as_public=true` on any endpoint produces the SAME results as without the flag (client cannot enable it).
- Server-side caller passing `$_rbacAsPublic = true` as a method-param DOES suppress admin-bypass, owner OR-in, and forces public-group-only evaluation on BOTH the UNION-arm bulk search AND per-object `find()` paths.
- Under `$_rbacAsPublic = true` the effective multitenancy is auto-bypassed unless the caller explicitly set `_multitenancy_explicit=true`, so admin and anonymous callers see the same result set (SCH-PFTS-001 uniform visibility).
- `_rbac_as_public` does not appear as a column filter in any generated SQL.
- No existing unit tests for `MagicRbacHandler`, `MagicSearchHandler`, `PermissionHandler`, `GetHandler`, or `ObjectService` regress.
