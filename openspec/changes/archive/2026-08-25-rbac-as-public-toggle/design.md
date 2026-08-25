## Context

OpenRegister's `MagicRbacHandler` always resolves the current user's session to compute RBAC conditions. Two auto-bypasses run unconditionally for authenticated callers:

1. **Admin bypass** — `in_array('admin', $userGroups, true)` at `buildRbacConditionsSql` line 823 (approximately): returns `['bypass' => true]` immediately, skipping all condition computation.
2. **Owner OR-in** — `$conditions[] = "_owner = '<userId>'"` at lines 850-853: appended to the WHERE clause whenever `$userId !== null`, so an authenticated user always sees their own objects regardless of schema `read` rules.

Both are the right defaults for normal CRUD surfaces. But a public endpoint whose contract is "uniform results for all callers regardless of session" cannot use them. Phase 1 discovery (WOO-536 plan, D3 finding) confirmed there was no existing escape hatch for this.

The change is small — roughly 30 LOC across two files — but the decision of *how to thread the flag* has architectural implications for API cleanliness and maintainability.

## Goals / Non-Goals

**Goals:**

- Add a boolean flag `_rbac_as_public` to the query dictionary that forces an anonymous context in `MagicRbacHandler` for a given search query.
- When `true`: skip admin bypass, suppress `_owner` OR-in, evaluate RBAC as if `$userId = null` and `$userGroups = []`.
- Thread the flag cleanly through the two `MagicSearchHandler` paths (single-schema `buildFilteredQuery` and UNION-arm `buildWhereConditionsSql`).
- Multi-schema propagation is automatic (no changes to `MagicMapper`).
- Default `false` — fully backwards-compatible. All callers that do not set the flag see no change.
- Unit-test the four call-context combinations and a contract test (admin session + `_rbac_as_public: true` returns identical rows to anonymous).

**Non-Goals:**

- Changes to `MagicMapper::searchObjectsPaginatedMultiSchema` — the flag rides in the `$searchQuery` dict which is already dict-copied to `$unionQuery` and `$schemaCountQuery`.
- A per-schema configuration for "always enforce public context" — that is the consuming app's responsibility (see ADR-022); OpenRegister provides the primitive.
- A UI or admin setting for the flag — it is a programmatic API for consuming apps.

**In scope (per user decision 2026-08-24, revising subagent's initial non-goals):**

- **PermissionHandler `find()` path** IS in scope. `ObjectService::find()` may be called by consuming apps (OpenCatalogi's `resolveDocumentPublicationSummary` per-document refinement) — if `PermissionHandler::hasPermission` / `checkPermission` / `filterObjectsForPermissions` ignore `_rbac_as_public`, an admin session on the public endpoint could see linked publications an anonymous caller cannot (silent leak).
- **HTTP client-injection hardening** IS in scope. `_rbac_as_public` MUST NOT be settable via user-supplied HTTP query params. Only server-side callers (via method parameter) may enable it.

## Decisions

### D1. Method-parameter threading (Option 3b)

**Decision:** Thread `_rbac_as_public` from the query dict through to `buildRbacConditionsSql` and `applyRbacFilters` as a `bool $asPublic` method parameter. Read the flag once at the `MagicSearchHandler` call site (`buildWhereConditionsSql` and `buildFilteredQuery` → `applyAccessControlFilters`) and pass it down as an explicit parameter.

**Why this over Option 3a (read flag inside `MagicRbacHandler` itself):**
Option 3a would have `MagicRbacHandler` reach into the query dict directly, making the RBAC handler implicitly query-dict-aware. The handler already receives `Schema` and `action` as explicit inputs; adding a third explicit boolean is consistent with its signature discipline. The flag is the caller's policy choice; passing it explicitly makes the contract visible.

**Why this over injecting into class state:** A per-instance flag would require a new setter/resetter pair and would interact badly with caching (`$cachedActiveOrg`). Method parameters are stateless and safe under concurrent request handling.

**Alternative — synthetic session injection:** Constructing a fake anonymous `IUser` and injecting it into `MagicRbacHandler` for the duration of the call was considered. Rejected: too invasive, touches more Nextcloud interfaces, and the flag is simpler and equally safe.

### D2. Flag name: `_rbac_as_public`

Mirrors the existing `_rbac` and `_multitenancy` reserved params already in `MagicSearchHandler::getReservedParams()`. The underscore-prefixed convention is the established OR query-dict pattern. `_rbac_as_public` reads as "run RBAC as if the caller is the `public` group" — which is exactly what it does.

**Alternatives considered:**
- `_rbac_force_anon`: correct semantically but "force anon" sounds like disabling auth entirely, which could be confusing. The implementation still evaluates RBAC — it just uses the `public` group's rules.
- `_public_context`: too generic; doesn't communicate RBAC involvement.
- `_rbac_public`: shorter but potentially ambiguous ("is `public` an action or a mode?").

### D3. No changes to `MagicMapper::searchObjectsPaginatedMultiSchema`

The `_rbac_as_public` flag lives in `$searchQuery`. At lines 7834 and 7869 of `MagicMapper`, the search query dict is copied to `$schemaCountQuery` and `$unionQuery` respectively. The flag therefore propagates automatically to every per-schema UNION arm and count query without any changes to `MagicMapper`. This was verified in Phase 1 discovery.

### D4. The `_owner` suppression is free

When `$asPublic = true`, the method sets `$userId = null` before entering the condition-building loop. The existing guard `if ($userId !== null) { $conditions[] = "_owner = '$userId'"; }` at lines 850-853 then evaluates to `false` and the `_owner` clause is never emitted. No new code is needed for this; it is a natural consequence of forcing `$userId = null`.

### D5. Admin bypass skip

When `$asPublic = true`, the `if (in_array('admin', $userGroups, true)) return ['bypass' => true];` guard must be reached *after* setting `$userGroups = []`. The safest implementation: read `$asPublic` at the very top of the method body, before the user-resolution block; if `true`, immediately override `$userId = null` and `$userGroups = []`. The admin-bypass check then naturally fails (empty array) and the rest of the method proceeds unchanged.

### D6. Security: the flag narrows, never widens

`_rbac_as_public: true` cannot grant access that would otherwise be denied. It removes the owner OR-in and admin bypass — both of which **add** rows to the result set that schema `read` rules would otherwise exclude. A public-endpoint caller using `_rbac_as_public: true` will see a subset of what an admin would see without the flag, never a superset. This makes the flag safe to pass in callers that control their own query construction (i.e., server-side PHP, not client-supplied query params — the flag must be set by the app, not by the API consumer directly).

**Note:** `_rbac_as_public` should NOT be forwarded from HTTP client requests. The consuming app sets it programmatically. The `getReservedParams()` registration strips it from the SQL condition-building loop, but the app must not expose it as a documented HTTP parameter.

## Risks / Trade-offs

- **[Misuse: a caller forgets to set `_rbac_as_public: true` on a public endpoint]** → Mitigation: this is the consuming app's responsibility. The OpenCatalogi PR that follows this one sets the flag in `assemblePublicSearchResults()`, which is the single method responsible for all public search queries. The flag must be set at that chokepoint.
- **[PHP + SQL divergence]** → The flag only affects `MagicRbacHandler` (SQL path). `PermissionHandler` (PHP-side, per-object check) is NOT changed. If a consumer uses `ObjectService::find()` (single-object fetch) on a public endpoint, the PHP-side admin bypass and owner grant still apply. For the OpenCatalogi use case this is acceptable — the public search uses the UNION-arm bulk-fetch path, not per-object `find()`. Document this limitation in the spec.
- **[Flag in query dict could be client-supplied]** → `getReservedParams()` strips the flag from forwarding to SQL WHERE clauses, but if the OR REST API surface passes through raw query params to the search engine, a client could theoretically inject `_rbac_as_public=true`. Verify that the OR HTTP layer strips reserved params before they reach `MagicSearchHandler`. If not, this is a separate hardening item.
- **[Multi-schema count query uses the flag]** → Via automatic dict-copy, the count query that powers pagination `total` also uses the forced-anon context. This is the correct behaviour for a public endpoint (the total should reflect publicly visible objects, not all objects including the admin's private drafts).

## Migration Plan

1. Add `$asPublic: bool = false` to `MagicRbacHandler::buildRbacConditionsSql` and `applyRbacFilters`. Add the forced-anon guard at method entry.
2. Thread the flag through `MagicSearchHandler` — `applyAccessControlFilters`, `buildFilteredQuery`, `buildWhereConditionsSql`, `buildRbacConditionSql`. Read from `$query['_rbac_as_public'] ?? false`.
3. Add `_rbac_as_public` to `MagicSearchHandler::getReservedParams()`.
4. Write unit tests for both `buildRbacConditionsSql` and `applyRbacFilters` covering the four combinations and the admin-session contract test.
5. Land and merge as the OR precursor PR before the OpenCatalogi main PR (WOO-536).

**Rollback:** Remove the `$asPublic` param and its guards. Default `false` means no deployed code is calling this with `true` yet (the OpenCatalogi consumer ships in a separate PR). Rollback is safe.

## Answered Questions (2026-08-24)

- **Should `_rbac_as_public` be hardened against HTTP client injection?**
  **Answer: YES.** Make `_rbac_as_public` an explicit METHOD PARAMETER on
  `ObjectService::searchObjectsPaginated()` and `ObjectService::find()`, and at
  the top of each method: strip any client-supplied `_rbac_as_public` from
  `$query`, then re-set it in `$query` ONLY when the trusted method-parameter
  is `true`. Client HTTP callers passing `?_rbac_as_public=true` in the query
  string are silently ignored — the flag only takes effect when the server-side
  consuming app explicitly requests it via the method parameter. Rationale:
  defense-in-depth; the flag makes RBAC stricter (never looser) so cannot cause
  privilege escalation, but the strip pattern prevents caller confusion and
  ensures the security posture is explicit.

- **Should `PermissionHandler` (`find()` path) also honour `_rbac_as_public`?**
  **Answer: YES.** Extend the primitive to the PHP-side per-object check
  as well: `PermissionHandler::hasPermission`, `PermissionHandler::checkPermission`,
  `PermissionHandler::filterObjectsForPermissions`, and `PermissionHandler::filterUuidsForPermissions`
  all accept a new `bool $_rbacAsPublic = false` parameter with the same anon-context
  semantics (`$userId = null`, `$userGroups = []`, skip admin bypass, no owner override).
  `GetHandler::find` and `ObjectService::find` thread the parameter through.
  Rationale: consuming apps that need to do per-document refinement lookups
  (OpenCatalogi's `resolveDocumentPublicationSummary`) after the bulk search must
  see the same anon view as the bulk-fetch path — otherwise linked-object
  summaries could leak.
