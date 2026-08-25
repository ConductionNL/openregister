---
kind: code
depends_on: []
---

## Why

OpenRegister's `MagicRbacHandler` unconditionally resolves the caller's session to compute RBAC conditions: admin-group members bypass all rules, and `_owner = <userId>` is OR'd into the result set for any authenticated user. This is correct for normal data surfaces, but breaks public API endpoints that **must** return identical results regardless of who is calling — such as OpenCatalogi's `GET /apps/opencatalogi/api/search`, which is a documented public search endpoint used by citizens, journalists, and scrapers. Requirement SCH-PFTS-001 mandates uniform visibility on that endpoint: the same query must return the same rows for an anonymous user and a logged-in admin. Currently the only way to enforce this is a hand-rolled PHP post-filter (`isObjectPublic()`), which forces the search scope into a two-schema straitjacket — a known architectural problem diagnosed in WOO-536.

This change adds a single boolean flag `_rbac_as_public: true` to OpenRegister's query dictionary. When set, the RBAC engine forces an anonymous context: it skips the admin-group bypass, suppresses the `_owner = <userId>` OR-in condition, and computes conditions using only the `public` group's matching rules — as if the caller were unauthenticated, regardless of their actual session state. Apps that need public-endpoint discipline set the flag on every query they dispatch; apps that don't set it see no change (default `false`, backwards-compatible). Per ADR-022, this is a new generic primitive on OpenRegister that consuming apps (starting with OpenCatalogi) will use rather than each rolling their own anonymous-context logic.

## What Changes

- **NEW:** `$asPublic: bool = false` parameter on `MagicRbacHandler::buildRbacConditionsSql(Schema, action, bool $asPublic)`. When `true`, the method forces `$userId = null` and `$userGroups = []` at the top of its body, skips the `in_array('admin', $userGroups)` early-return, and proceeds with the rest of its body unchanged. The existing `_owner = <userId>` OR-in at lines 850-853 auto-skips because `$userId === null`.
- **NEW:** `$asPublic: bool = false` parameter on `MagicRbacHandler::applyRbacFilters(qb, Schema, action, bool $asPublic)` — same forced-anon-context pattern for the single-schema path.
- **NEW:** `_rbac_as_public` reserved parameter in `MagicSearchHandler::getReservedParams()`.
- **MODIFIED:** `MagicSearchHandler::buildRbacConditionSql(Schema, bool $asPublic)` — accepts and passes through `$asPublic` to `$this->rbacHandler->buildRbacConditionsSql(...)`.
- **MODIFIED:** `MagicSearchHandler::buildWhereConditionsSql(...)` — reads `$_rbacAsPublic = $query['_rbac_as_public'] ?? false` and passes it to `buildRbacConditionSql`.
- **MODIFIED:** `MagicSearchHandler::buildFilteredQuery(...)` — reads the flag and threads it into `applyAccessControlFilters`.
- **MODIFIED:** `MagicSearchHandler::applyAccessControlFilters(..., bool $_rbacAsPublic = false)` — accepts the flag and passes it to `applyRbacFilters`.
- **No breaking change.** Default is `false` everywhere, preserving existing semantics. Multi-schema propagation is automatic: `_rbac_as_public` in `$searchQuery` is dict-copied to `$unionQuery` and `$schemaCountQuery` at lines 7869 and 7834 of `MagicMapper`, so no changes there are needed.

## Capabilities

### New Capabilities

- `rbac-as-public-toggle`: A query-flag primitive (`_rbac_as_public: true`) on the OpenRegister search-query dictionary that forces an anonymous RBAC evaluation context regardless of the caller's session state. Used by public API endpoints to enforce uniform visibility for all callers.

### Modified Capabilities

(none — the new flag is additive; existing `rbac-scopes` requirement scenarios are unaffected because the default is `false`.)

## Impact

- **Code (openregister only):**
  - `lib/Db/MagicMapper/MagicRbacHandler.php` — add `$asPublic` param to `buildRbacConditionsSql` and `applyRbacFilters`; add forced-anon-context guard at method entry.
  - `lib/Db/MagicMapper/MagicSearchHandler.php` — add `$asPublic` threading through `buildRbacConditionSql`, `buildWhereConditionsSql`, `buildFilteredQuery`, `applyAccessControlFilters`; add `_rbac_as_public` to `getReservedParams()`.
  - `tests/Unit/Db/MagicMapper/MagicRbacHandlerTest.php` (or a sibling `MagicRbacHandlerAsPublicTest.php`) — unit tests for the new behaviour.
- **API contract:** The `_rbac_as_public` key is now a reserved query parameter (added to `getReservedParams()`). Additive, non-breaking. Existing callers that don't supply the key see no change.
- **Cross-app:** OpenCatalogi's `assemblePublicSearchResults()` will set `_rbac_as_public: true` alongside `_rbac: true` on every query it dispatches — implementing Q1 Option B (uniform public-endpoint visibility) via this primitive. Other consuming apps (DocuDesk, Pipelinq, Procest, ZaakAfhandelApp) are unaffected.
- **Security:** The flag narrows, not widens, the result set for authenticated users — it removes owner and admin grants, not adds them. There is no path through which setting `_rbac_as_public: true` grants access that would otherwise be denied.
- **ADR references:**
  - **ADR-022** (apps-consume-or-abstractions): this adds a new primitive to OR that consuming apps use; it does NOT add app-local RBAC reimplementation to OpenCatalogi.
  - **ADR-023** (action-authorization): the flag is a per-request scope override of the action-authorization computation — narrowing the effective identity to anonymous for a specific query.
  - **ADR-005** (security): public endpoints have different contracts than admin surfaces; uniform visibility for citizens/scrapers is an explicit security property.
  - **ADR-032** (spec-sizing): `kind: code`, small (~30 LOC change + tests), no `design.md` required.
