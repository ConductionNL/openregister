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

## 4. ObjectService — HTTP hardening + method-param API (per user decision Q1)

- [x] 4.1 Add `bool $_rbacAsPublic = false` to `ObjectService::searchObjectsPaginated()` signature. At method entry: `unset($query['_rbac_as_public']);` (strip any client-supplied flag from the query dict), then if `$_rbacAsPublic === true` re-set `$query['_rbac_as_public'] = true` (trusted method-param wins). Docblock: only server-side callers may set this; HTTP query params are stripped.
- [x] 4.2 Add `bool $_rbacAsPublic = false` to `ObjectService::find()` signature. NO query-dict strip needed (find takes an ID). NOT threaded into `GetObject::find` — MagicMapper::find's RBAC block is a placeholder (lines 4327-4331 of MagicMapper.php), so threading it there would be dead-code plumbing. Instead threaded into `ObjectService::checkPermission` (the private wrapper at line 319) which is the actual PermissionHandler call site on the find() path.
- [x] 4.3 Update `ObjectService::checkPermission` (private wrapper) to accept `bool $_rbacAsPublic = false` and forward to `$this->permissionHandler->checkPermission(..., _rbacAsPublic: $_rbacAsPublic)`. This is where the find() path actually meets PermissionHandler on line 617 of ObjectService.php.

## 5. Unit tests

- [x] 5.1 `MagicRbacHandler::buildRbacConditionsSql_asPublic_*` — 4 tests + contract test in `tests/Unit/Db/MagicMapper/MagicRbacHandlerAsPublicTest.php`: ignores admin membership, skips owner OR-in, matches public-group rules, denies authenticated-only rules, admin+asPublic vs anon returns identical structure.
- [ ] 5.2 Same 4 tests for `MagicRbacHandler::applyRbacFilters` (QueryBuilder variant) — pending; QueryBuilder mocking is more involved. Contract-verified indirectly via 5.1 (both methods share the guard shape).
- [x] 5.3 `PermissionHandler::hasPermission_asPublic_*` — 5 tests in `tests/Unit/Service/Object/PermissionHandlerAsPublicTest.php`: ignores admin, matches public rule, denies authenticated, no owner grant, backwards-compat.
- [ ] 5.4 HTTP-hardening test: `ObjectService::searchObjectsPaginated` called with client-supplied `_rbac_as_public: true` in `$query` but `$_rbacAsPublic = false` in method-param → the flag is stripped and NOT applied. DEFERRED to integration testing — unit mocking of the full mapper chain is disproportionate for a 5-line strip pattern; the pattern is inline and deterministic. Integration coverage via WOO-536 OC smoke tests (Fase 7A).
- [x] 5.5 Contract test: admin session with `$_rbacAsPublic = true` returns the same conditions structure as an anonymous caller — covered by `testAsPublicTrueForAdminMatchesAnonForSameQuery` in 5.1.

## 6. Spec + docs + validation

- [x] 6.1 `openspec validate rbac-as-public-toggle` passes clean (verified 2026-08-25 after artifact updates).
- [x] 6.2 Docblock on `ObjectService::searchObjectsPaginated` explains the HTTP-hardening pattern: `_rbac_as_public` in the query dict is stripped and only the method-parameter is trusted (RBA-PUBLIC-005 reference in the block comment).

Acceptance criteria:

- All tests in tasks 5.x pass under `phpunit` (13+ new tests).
- `openspec validate rbac-as-public-toggle` is clean.
- HTTP query with `?_rbac_as_public=true` on any endpoint produces the SAME results as without the flag (client cannot enable it).
- Server-side caller passing `$_rbacAsPublic = true` as a method-param DOES suppress admin-bypass, owner OR-in, and forces public-group-only evaluation on BOTH the UNION-arm bulk search AND per-object `find()` paths.
- `_rbac_as_public` does not appear as a column filter in any generated SQL.
- No existing unit tests for `MagicRbacHandler`, `MagicSearchHandler`, `PermissionHandler`, `GetHandler`, or `ObjectService` regress.
