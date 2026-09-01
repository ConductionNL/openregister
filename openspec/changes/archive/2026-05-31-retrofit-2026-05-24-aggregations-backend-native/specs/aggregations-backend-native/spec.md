---
retrofit: true
---

# Spec: aggregations-backend-native

**Status:** proposed
**Scope:** openregister
**Tier:** or-core-extensions
**Depends on:** zoeken-filteren (sibling — backend dispatch + cache TTL contract),
nested-aggregations (sibling — multi-level groupBy + HAVING), faceting-configuration
(sibling — single-property cardinality with pagination), row-field-level-security
(RLS gate runs before aggregation).

## Motivation (context for the new capability spec)

The in-flight `aggregations-backend-native` change extended
`zoeken-filteren` with four high-level requirements:

- backend dispatch (Solr → ES → Postgres → PHP fallback),
- Postgres operator-filter translation,
- 60 s read-through cache,
- backend attribution on every response.

Those requirements state *what* the runner does — the contracts
above are necessary but not sufficient to specify the production
runtime. The implementation enforces five further behaviours that
the existing requirements leave implicit:

1. **Cache-key composition.** Naive `{register, schema, name}` keys
   leak results across RBAC scopes; the implementation hashes
   `(register, schema, name, ksort-canonicalised filter, sha1(uid))`
   and fails closed when the cache backend is unavailable.
2. **External-backend failure handling.** Solr / ES throws are
   non-fatal — the runner catches and falls through to Postgres-native.
   "Backend X is configured" never implies "backend X is the answer".
3. **RBAC gate at runner entry.** Every controller-driven call MUST
   pass `PermissionHandler::hasPermission(list, schema)` before any
   backend executes. Non-controller callers may pass `bypassRbac: true`
   when they hold an authoritative reason (dashboard read, threshold
   listener reacting to a write event).
4. **Native SQL safety.** The native fast path bypasses `MagicMapper`
   so it MUST emit its own soft-delete predicate AND its own
   `_organisation = ?` multi-tenant predicate. It rejects any filter
   shape outside the closed `(in | gt | gte | lt | lte | ne)` operator
   set by returning null (and falling back to PHP).
5. **Threshold-listener rising-edge semantics.** Aggregation-driven
   notifications fire once per `below → above` transition, persisted
   in a 30-day-TTL state cache. Re-firing on every subsequent write
   while still above the threshold is forbidden.

This spec captures those five behaviours as testable requirements.
Code already exists for all of them — this is a retrofit spec.

## ADDED Requirements

### Requirement: REQ-ABN-001 AggregationCache MUST scope cache keys by register, schema, name, canonicalised filter, and RBAC scope

The cache key MUST be composed as
`agg:{registerSlug}:{schemaSlug}:{name}:{sha1(json_encode(ksort(filter)))}:{sha1(uid ?? "anonymous")}`.
Filter sub-arrays MUST be ksort-stable (recursive) so two
structurally-equivalent filters produce identical keys. The cache
MUST fail closed: when the underlying `ICacheFactory::createDistributed()`
throws, every `get()` MUST return `null` and every `set()` MUST be a
no-op, with a warning logged once at construction. The TTL on stored
entries MUST be 60 seconds.

Ad-hoc queries reuse the same scheme via
`adhocName = 'adhoc:'.sha1(json_encode(query.toArray()))` so the
`adhoc:` prefix visually distinguishes ad-hoc entries from named
ones in cache dumps but reuses the same RBAC + filter-hash scoping.

#### Scenario: Two users sharing the same query do not share cached values

- **GIVEN** user `alice` calls `byStatus` on `(decidesk, action-item)`
  with `filter: {status: "open"}` and the result is cached
- **WHEN** user `bob` issues an identical request within the 60 s TTL
- **THEN** `bob`'s request MUST miss the cache (different `rbacScopeHash`)
- **AND** `bob`'s request MUST execute the runner end-to-end
- **AND** `bob`'s result MUST be stored under a different cache key
  than `alice`'s

#### Scenario: Filter ordering does not break cache hits

- **GIVEN** a first request with `filter: {status: "open", owner: "x"}`
  populates the cache
- **WHEN** a second request arrives with `filter: {owner: "x", status: "open"}`
  within the 60 s TTL from the same user
- **THEN** the second request MUST hit the cache (same canonicalised key)
- **AND** the response MUST carry `cached: true`

#### Scenario: Cache backend unavailable fails closed

- **GIVEN** `ICacheFactory::createDistributed()` throws at construction
- **WHEN** the runner calls `AggregationCache::get()` / `set()` /
  `evictForSchema()`
- **THEN** `get()` MUST return `null` (no exception propagated)
- **AND** `set()` MUST be a no-op
- **AND** the runner MUST still compute and return the result via
  the native or fallback path

### Requirement: REQ-ABN-002 AggregationRunner MUST dispatch external then Postgres-native then PHP fallback with non-fatal external errors

The runner MUST attempt backends in the order: external (`SearchBackendInterface`), Postgres-native fast path, PHP fallback. An external backend returning `null` (unsupported metric / unreachable / etc) MUST cause the runner to fall through to the Postgres-native fast path. The external backend *throwing* MUST also be caught and treated as a fall-through — a flaky Solr/ES instance MUST NOT break aggregation responses.

The Postgres-native fast path is tried next via
`tryNativeAggregation()`. When it returns `null` (unsupported query
shape / non-Postgres engine for non-date-bucket paths / table not
found), the runner MUST fall back to the PHP runner.

The PHP fallback MUST hydrate at most `PHP_FALLBACK_ROW_CAP = 10000`
rows. When the source table has more matching rows than the cap,
the response MUST carry `truncated: true`; native and external paths
MUST set `truncated: false` (full-set evaluation). Every response
envelope MUST carry a `backend` field with one of `"solr"` /
`"elasticsearch"` / `"external"` / `"postgres"` / `"php-fallback"`.

#### Scenario: External backend throws → fall through to native

- **GIVEN** a `SolrSearchBackend` is wired in but its HTTP client throws
- **WHEN** the runner executes `byStatus`
- **THEN** the exception MUST be caught silently inside the runner
- **AND** the runner MUST execute `tryNativeAggregation()`
- **AND** the response MUST carry `backend: "postgres"` (not `"solr"`)
- **AND** no exception MUST propagate to the controller

#### Scenario: PHP fallback over the row cap surfaces truncated

- **GIVEN** the native path returns `null` (e.g. a filter shape it can't
  translate) and the matching row set exceeds 10 000 rows
- **WHEN** the PHP fallback hydrates the row set
- **THEN** the runner MUST cap the hydrate at 10 000 rows
- **AND** the response MUST carry `truncated: true`
- **AND** the response MUST carry `backend: "php-fallback"`

#### Scenario: Backend attribution always populated

- **GIVEN** any aggregation request that reaches a backend
- **WHEN** the response is rendered
- **THEN** the JSON body MUST include a top-level `backend` field
- **AND** the value MUST be one of `"solr"`, `"elasticsearch"`,
  `"external"`, `"postgres"`, or `"php-fallback"`
- **AND** a fall-through path (e.g. Solr throws → Postgres answers)
  MUST attribute the actual backend that produced the result, not
  the originally-targeted one

### Requirement: REQ-ABN-003 AggregationRunner MUST gate aggregate execution behind RBAC list-permission on the target schema

Every controller-driven call to `AggregationRunner::run()` and `runAdhoc()` MUST consult `PermissionHandler::hasPermission(schema, action: 'list', userId)` before any backend (external / native / fallback) executes. A
negative verdict MUST raise `NotAuthorizedException` (which the
`AggregationController` maps to HTTP 403). The gate is schema-level
— per-row ACLs are explicitly NOT consulted at this layer.

The `bypassRbac: true` flag MUST be accepted ONLY from non-controller
callers that hold an independent authoritative reason for the
aggregation (e.g. `ReportRenderService` for dashboard read on behalf
of a viewer; `AggregationThresholdListener` reacting to a write
event the active session does not own). The HTTP-driven controller
path MUST NOT set `bypassRbac: true`.

Cross-schema aggregations (`spec.from` is set) MUST gate on the
*target* schema's list permission as well — not just the parent —
so a caller cannot leak counts from a schema it cannot list by
piggy-backing on a parent schema it can.

#### Scenario: Caller without list permission gets 403

- **GIVEN** user `eve` lacks the `list` action on schema `secret`
- **WHEN** `AggregationController::aggregate()` invokes the runner
  for `(reg, secret, byCount)`
- **THEN** the runner MUST raise `NotAuthorizedException`
- **AND** the controller MUST return HTTP 403
- **AND** no backend MUST be invoked

#### Scenario: Threshold listener passes bypassRbac and still aggregates

- **GIVEN** an `ObjectCreatedEvent` fires on `(reg, schema)` and the
  threshold listener evaluates the relevant aggregation
- **WHEN** the listener calls `runner.run(..., bypassRbac: true)`
- **THEN** the RBAC gate MUST be skipped (the write event already
  passed RBAC)
- **AND** the aggregation MUST execute and return the value used to
  determine threshold-state transitions

#### Scenario: Cross-schema aggregation gates on target schema

- **GIVEN** user `bob` has list permission on schema `parent` but NOT
  on cross-schema target `child`
- **WHEN** an aggregation spec with `from: "child"` runs on `parent`
- **THEN** the runner MUST raise (with the target schema in the message)
- **AND** no row from `child` MUST be aggregated

### Requirement: REQ-ABN-004 tryNativeAggregation MUST enforce soft-delete and multi-tenant predicates and reject any filter shape outside the closed operator allow-list

The native SQL fast path bypasses `MagicMapper` entirely, so it MUST
emit:

1. A soft-delete predicate that excludes rows where the magic
   `_deleted` column is non-empty. Postgres MUST use
   `(_deleted IS NULL OR _deleted = 'null'::jsonb)`; MySQL/SQLite
   MUST use `(_deleted IS NULL OR _deleted = 'null' OR _deleted = '')`.
2. A multi-tenant predicate `_organisation = ?` bound to the active
   organisation's UUID. When no organisation is active the runner
   MUST bind the literal `'__no_active_org__'` so the query
   fails-closed (matches no rows) — never bind NULL or omit the
   predicate.
3. A closed operator allow-list on filter values:
   `in` / `gt` / `gte` / `lt` / `lte` / `ne`. Any filter map whose
   operator vocabulary contains a key NOT in this set MUST cause
   `tryNativeAggregation()` to return `null` (signalling the caller
   to fall back to PHP) — it MUST NOT attempt a partial native
   translation.
4. An empty `in: []` list MUST translate to `1 = 0` (never matches),
   not to an open `IN ()` clause that would be a SQL parse error.
5. Soft failure on any DB exception: every database error inside the
   native path MUST be caught and translated to `return null` so the
   PHP fallback covers the request.

#### Scenario: Active organisation is null → fails closed

- **GIVEN** `OrganisationService::getActiveOrganisation()` returns `null`
- **WHEN** the native path emits the WHERE clause
- **THEN** the bound value for `_organisation = ?` MUST be the literal
  `__no_active_org__`
- **AND** the query MUST match no rows

#### Scenario: Unsupported filter shape falls back to PHP

- **GIVEN** a filter map `{status: {regex: "open.*"}}`
- **WHEN** `tryNativeAggregation()` validates the operator vocabulary
- **THEN** the method MUST return `null` (operator `regex` not in
  the allow-list)
- **AND** the runner MUST fall back to the PHP path
- **AND** the response MUST carry `backend: "php-fallback"`

#### Scenario: Empty `in` list short-circuits

- **GIVEN** a filter `{status: {in: []}}`
- **WHEN** the native path translates the operator
- **THEN** the WHERE fragment MUST contain `1 = 0`
- **AND** the query MUST execute (not raise a parse error)
- **AND** the result MUST be `{value: 0}` (count) or empty `groups: []`

#### Scenario: Native DB error → null → PHP fallback

- **GIVEN** the magic table for `(register, schema)` does not exist
- **WHEN** `tryNativeAggregation()` prepares + executes the SQL
- **THEN** the caught `Throwable` MUST yield `return null`
- **AND** the runner MUST execute the PHP fallback
- **AND** no exception MUST propagate to the controller

### Requirement: REQ-ABN-005 AggregationThresholdListener MUST fire notifications only on rising-edge transitions and persist state in a 30-day cache

On every `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent` / `ObjectTransitionedEvent`, the listener MUST re-evaluate every notification declared in `x-openregister-notifications` whose `trigger.type` is `"threshold"`.

For each such notification:

1. The listener MUST call `runner.run(..., bypassRbac: true)` to
   compute the aggregation value (it bypasses RBAC because the write
   event itself already passed RBAC).
2. If the result's `value` is not numeric (`int` or `float`), the
   evaluation MUST short-circuit (no notification, no state change).
3. The listener MUST compare the value against `trigger.value` using
   `trigger.op` ∈ `gt` / `gte` / `lt` / `lte` / `eq` / `ne`. Any
   unknown operator MUST yield `false` (the comparison is treated as
   "not above"; no notification fires).
4. The listener MUST dispatch the notification ONLY on a rising-edge
   transition: prior state ≠ `"above"` AND new state == `"above"`.
   Subsequent writes while still above the threshold MUST NOT re-fire.
5. The state cache key MUST be
   `threshold:{schema.id}:{notificationName}` and the TTL MUST be
   `60 * 60 * 24 * 30` seconds (30 days).
6. Exceptions during `evaluate()` MUST be caught and logged at
   `warning` — they MUST NOT abort the event handling or break
   subsequent notifications on the same schema.

#### Scenario: First breach fires; second write while still above does not

- **GIVEN** an `openTickets > 100` threshold notification with prior
  state `below`
- **WHEN** an `ObjectCreatedEvent` fires and the aggregation now
  returns `value: 120`
- **THEN** the dispatcher MUST be called once with the threshold
  payload
- **AND** the state cache key MUST be set to `above`
- **WHEN** a second `ObjectUpdatedEvent` fires and the aggregation
  still returns `value > 100`
- **THEN** the dispatcher MUST NOT be called a second time
- **AND** the state cache MUST remain `above`

#### Scenario: Non-numeric aggregation value short-circuits

- **GIVEN** an aggregation returns `{value: null}` (no rows matched)
- **WHEN** the listener evaluates the threshold
- **THEN** the evaluation MUST short-circuit before `compare()`
- **AND** the dispatcher MUST NOT be called
- **AND** the state cache MUST NOT be written

#### Scenario: Evaluator throws on one notification, others still run

- **GIVEN** two threshold notifications `A` and `B` on the same schema
- **AND** the aggregation for `A` raises during execution
- **WHEN** the listener handles a write event
- **THEN** the `Throwable` from `A` MUST be caught and logged at
  `warning`
- **AND** the evaluation of `B` MUST still execute on the same event

## Notes

- **`compare()` uses strict numeric equality (`===`) on `float`
  conversions of both sides.** Threshold transitions on `eq` / `ne`
  are therefore brittle on floating-point inputs (rounding error
  may produce non-matches even when intent is "equal"). Captured
  here as observed behaviour; consider relaxing to epsilon-based
  comparison in a follow-up.
- **Cache eviction is coarse — `ICache::clear()` wipes the entire
  `openregister_aggregations` cache for any object-write event.**
  ICache has no prefix-delete. The 60 s TTL bounds staleness when
  a clear is missed but every aggregation on every schema gets
  invalidated on every write. Documented in REQ-ABN-001's
  fail-closed scenario.
- **The PHP fallback row cap is hard-coded at 10 000.** Pluggable
  per-schema caps are a future hardening step.
- **`detectBackendName()` falls back to `"external"` for any
  `SearchBackendInterface` whose short class name doesn't contain
  `Solr` or `Elasticsearch`.** New backend implementations need
  either a recognisable name or a dedicated branch added.
