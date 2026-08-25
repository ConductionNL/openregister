---
status: proposed
---

# RBAC-as-Public Toggle

**Change**: `rbac-as-public-toggle`
**ADR references**: ADR-005 (security), ADR-022 (apps-consume-or-abstractions), ADR-023 (action-authorization), ADR-032 (spec-sizing)

## Purpose

Define the `_rbac_as_public` query-flag primitive in OpenRegister's `MagicRbacHandler` and `MagicSearchHandler`. When set on a search query, the RBAC engine evaluates conditions using an anonymous context — skipping admin-group bypass and owner OR-in — regardless of the caller's actual session state.

This primitive is needed by consuming apps (specifically OpenCatalogi, per WOO-536) that operate public endpoints whose contract mandates uniform results for all callers. It satisfies ADR-022 by providing the capability once in OpenRegister rather than having each consuming app re-implement anonymous-context logic.

## Context

OpenRegister's `MagicRbacHandler::buildRbacConditionsSql` and `applyRbacFilters` compute RBAC conditions from the caller's live session. Two unconditional bypasses apply for authenticated callers:

1. Admin-group bypass: `in_array('admin', $userGroups)` returns `['bypass' => true]`, skipping all condition computation.
2. Owner OR-in: `_owner = '<userId>'` is appended to the WHERE clause whenever `$userId !== null`.

These are correct for normal CRUD surfaces but break public endpoints that must return the same rows to a citizen and an admin browsing the site in an authenticated tab.

SCH-PFTS-001 (OpenCatalogi public search endpoint) mandates uniform visibility. Phase 1 discovery (WOO-536 plan, D3 finding) confirmed no existing escape hatch existed, making a small OR precursor PR necessary.

## Requirements

## ADDED Requirements

### Requirement: RBAC as public context flag (RBA-PUBLIC-001)

The OpenRegister search-query dictionary SHALL support a boolean flag `_rbac_as_public`. When `_rbac_as_public: true` is set on a query, the RBAC engine MUST evaluate conditions as if the effective user is anonymous, regardless of the actual session state. Specifically:

- `$userId` MUST be treated as `null` for the duration of RBAC computation.
- `$userGroups` MUST be treated as `[]` for the duration of RBAC computation.
- The admin-group early-return bypass MUST be skipped.
- The `_owner = <userId>` OR-in condition MUST NOT be emitted.
- All other condition computation (dynamic variable resolution, operator evaluation, `public`-group matching, `authenticated` rules) MUST proceed using the forced-null user context.

When `_rbac_as_public` is absent or `false`, the existing session-based RBAC computation MUST apply unchanged (backwards-compatible default).

#### Scenario: Admin caller with asPublic flag sees only publicly-visible rows

- **WHEN** a search query is issued with `_rbac: true` and `_rbac_as_public: true` by a user who is in the `admin` group
- **THEN** the admin-group bypass MUST NOT apply
- **AND** the result set MUST contain only rows that satisfy the schema's `public`-group `read` conditions
- **AND** the result set MUST equal the result set for an anonymous (unauthenticated) caller issuing the same query without `_rbac_as_public`

#### Scenario: Owner's draft not surfaced under asPublic flag

- **WHEN** a search query is issued with `_rbac_as_public: true` by a user who created objects stored in the schema (i.e. `_owner = <userId>` would normally match)
- **THEN** the `_owner = <userId>` OR-in condition MUST NOT be emitted
- **AND** the owner's draft objects (those not matching the `public` group's `read` conditions) MUST NOT appear in the result set

#### Scenario: Public group read conditions still evaluated under asPublic flag

- **WHEN** a schema has `read: [{ "group": "public", "match": { "publicatiedatum": { "$lte": "$now" } } }]` AND a query is issued with `_rbac_as_public: true`
- **THEN** `MagicRbacHandler` MUST evaluate the `public`-group rule using `$now` dynamic variable resolution
- **AND** only objects where `publicatiedatum <= NOW()` MUST be included in the result

#### Scenario: Authenticated-only read rule denied under asPublic flag

- **WHEN** a schema has `read: ["authenticated"]` AND a query is issued with `_rbac_as_public: true` by a logged-in user
- **THEN** the `authenticated` rule MUST be evaluated against the forced-null user context (`$userId = null`)
- **AND** the rule MUST NOT grant access (because `$userId === null`)
- **AND** the result set MUST be empty (assuming no `public` rule exists)

#### Scenario: Flag absent — existing RBAC behavior unchanged

- **WHEN** a search query is issued WITHOUT `_rbac_as_public` (or with `_rbac_as_public: false`)
- **THEN** `MagicRbacHandler` MUST compute conditions using the caller's actual session
- **AND** admin bypass, owner OR-in, and group-based evaluation MUST all function as before this change

### Requirement: asPublic flag propagates through both search paths (RBA-PUBLIC-002)

The `_rbac_as_public` flag MUST be honoured on both the single-schema path (`MagicSearchHandler::buildFilteredQuery` → `applyRbacFilters`) and the multi-schema UNION path (`buildWhereConditionsSql` → `buildRbacConditionSql` → `buildRbacConditionsSql`). The flag MUST propagate automatically to per-schema count queries used for pagination totals.

#### Scenario: Multi-schema UNION search respects asPublic on every arm

- **WHEN** `searchObjectsPaginatedMultiSchema` is called with `_rbac_as_public: true` and `_rbac: true` against a register containing three schemas
- **THEN** every UNION arm's WHERE clause MUST use the public-group conditions for its schema
- **AND** the `total` in the response MUST reflect only publicly-visible objects across all schema arms
- **AND** facet counts MUST reflect the same public-context filtering

#### Scenario: Count query uses asPublic context

- **WHEN** a multi-schema search is issued with `_rbac_as_public: true`
- **THEN** the count query (used for `total` in pagination) MUST also apply the forced-anon context
- **AND** the reported `total` MUST NOT include objects the admin could see but the public cannot

### Requirement: asPublic flag is a reserved query parameter (RBA-PUBLIC-003)

The string `_rbac_as_public` MUST be listed in `MagicSearchHandler::getReservedParams()`. It MUST NOT be forwarded as a SQL WHERE-clause filter condition (it is a control flag, not a data filter). It MUST be consumed by the RBAC plumbing only.

#### Scenario: Reserved param not forwarded to SQL filter

- **WHEN** a search query contains `_rbac_as_public: true`
- **THEN** `_rbac_as_public` MUST NOT appear as a column filter condition in the generated SQL
- **AND** `_rbac_as_public` MUST NOT appear in the `buildFilteredQuery` column-matching loop

### Requirement: asPublic flag does not widen access (RBA-PUBLIC-004)

The `_rbac_as_public` flag SHALL only narrow, never widen, the result set for any caller. An authenticated caller with `_rbac_as_public: true` MUST see a subset of what the same caller would see with `_rbac: false` (which bypasses RBAC entirely). An authenticated caller with `_rbac_as_public: true` MUST see the same set as (or a subset of) the `public`-group-eligible result set.

#### Scenario: asPublic result is subset of _rbac:false result

- **GIVEN** a schema with `read: [{ "group": "public", "match": { "status": "published" } }]`
- **WHEN** the same query is issued twice by an admin: once with `_rbac: false` and once with `_rbac: true, _rbac_as_public: true`
- **THEN** the `_rbac_as_public: true` result MUST be a subset of the `_rbac: false` result
- **AND** objects not matching `status = "published"` MUST appear in the `_rbac: false` result but NOT in the `_rbac_as_public: true` result

### Requirement: asPublic is a trusted method-parameter, HTTP-injected values are ignored (RBA-PUBLIC-005)

The `_rbac_as_public` flag SHALL only take effect when set by a trusted server-side caller via the explicit method parameter on `ObjectService::searchObjectsPaginated()` / `ObjectService::find()`. Any value of `_rbac_as_public` inside the query dictionary supplied by external HTTP callers MUST be stripped before it can influence RBAC evaluation. Only after the strip may the trusted method-parameter re-set the flag in the query dict for internal propagation.

#### Scenario: Client-supplied flag is stripped

- **WHEN** an HTTP request sends `?_rbac_as_public=true` in the query string
- **AND** `ObjectService::searchObjectsPaginated($query, ..., $_rbacAsPublic=false)` is called with the request query
- **THEN** the query dict MUST have `_rbac_as_public` stripped at the top of `searchObjectsPaginated`
- **AND** the effective RBAC behaviour MUST be identical to a request without the flag
- **AND** an authenticated caller MUST NOT lose access to their own drafts due to a client-supplied flag

#### Scenario: Server-side method-param sets the flag

- **WHEN** OC's `assemblePublicSearchResults` calls `ObjectService::searchObjectsPaginated($query, _rbac: true, _rbacAsPublic: true)`
- **THEN** `_rbac_as_public: true` MUST be present in the query dict when it reaches `MagicSearchHandler`
- **AND** the RBAC engine MUST apply the forced-anon context

### Requirement: asPublic honoured on PermissionHandler / find() path (RBA-PUBLIC-006)

The `_rbac_as_public` semantics SHALL apply to the PHP-side per-object check as well as the SQL/search paths. `PermissionHandler::hasPermission()`, `PermissionHandler::checkPermission()`, `PermissionHandler::filterObjectsForPermissions()`, and `PermissionHandler::filterUuidsForPermissions()` MUST accept a `bool $_rbacAsPublic = false` parameter that applies the same forced-anon context: `$userId = null`, `$userGroups = []`, admin-bypass skipped, owner-grant omitted. `GetHandler::find()` and `ObjectService::find()` MUST thread the parameter through.

#### Scenario: Admin caller find() with asPublic returns null on non-public object

- **GIVEN** a schema with `read: [{ "group": "public", "match": { "publicatiedatum": { "$lte": "$now" } } }]`
- **AND** an object whose `publicatiedatum` is in the future
- **WHEN** an admin calls `ObjectService::find($id, _rbac: true, _rbacAsPublic: true)`
- **THEN** the admin-bypass MUST NOT apply
- **AND** the result MUST be `null` (object not publicly visible)
- **AND** the same call by an anonymous caller MUST return the same `null`

#### Scenario: Owner find() with asPublic does not surface own draft

- **WHEN** a user creates an object with `publicatiedatum` in the future (draft)
- **AND** the same user calls `ObjectService::find($id, _rbac: true, _rbacAsPublic: true)` on their own draft
- **THEN** the `_owner = <userId>` grant MUST NOT apply
- **AND** the result MUST be `null`

## Out-of-Scope

- A UI or admin setting for this flag. It is a programmatic API for consuming app server-side code.
- Exposing `_rbac_as_public` as an HTTP query parameter for end-user clients. It is an internal server-to-server flag; consuming apps set it via explicit method parameter, not via API-consumer query string (RBA-PUBLIC-005 enforces this).
- Changes to `MagicMapper::searchObjectsPaginatedMultiSchema`. The flag propagates automatically via the existing dict-copy at lines 7834 and 7869.
