# Design — Retrofit aggregations-backend-native

> Retrofit change. Tasks describe retroactive annotation, not new
> implementation work. The code paths these REQs describe already
> exist in `lib/Service/Aggregation/` and `lib/Listener/`.

## Context

The in-flight change `aggregations-backend-native` extends the
`zoeken-filteren` capability with four high-level requirements:

- backend dispatch (Solr → ES → Postgres → PHP fallback)
- operator-filter translation on Postgres
- 60 s read-through cache
- backend attribution on every response

Those requirements specify *what* the runner does at the dispatch
level. They leave implicit five further behaviours the implementation
already enforces — security gates, fail-closed cache semantics,
operator allow-listing, and rising-edge threshold semantics. This
retrofit captures those five behaviours as testable REQs under a new
capability spec, `aggregations-backend-native`.

## Why a new capability rather than extending zoeken-filteren

`zoeken-filteren` covers search/filter primitives (find / list /
facet). Aggregation runtime is adjacent but distinct:

- Different security surface (RBAC list gate + `bypassRbac` reserved
  for non-controller callers + active-org predicate inside SQL).
- Different fail-closed semantics (cache key composition, native →
  PHP fallback chain, threshold rising-edge transitions).
- Different ownership of side effects (notifications fire on
  threshold-crossings; cache invalidation on write events).

Conflating these with search/filter would dilute the search spec's
scope. The new capability spec is the right home.

## Mapping: REQ → code units → tasks

- **REQ-ABN-001** — `AggregationCache::get/set/getAdhoc/setAdhoc/adhocName/evictForSchema/rbacScopeHash` → task-1
- **REQ-ABN-002** — `AggregationRunner::run/runAdhoc` external/native/fallback chain + `PHP_FALLBACK_ROW_CAP` + `detectBackendName` → task-2
- **REQ-ABN-003** — `AggregationRunner::run` + `runAdhoc` + `runCrossSchema` RBAC gates → task-3
- **REQ-ABN-004** — `AggregationRunner::tryNativeAggregation` soft-delete + `_organisation` predicates + operator allow-list + DB-error fallback → task-4
- **REQ-ABN-005** — `AggregationThresholdListener::handle/evaluate/compare` rising-edge + 30-day TTL → task-5

## Out of scope

- Annotation validators (`AggregationAnnotationValidator`,
  `WidgetAnnotationValidator`). These belong in a schema-annotation
  validation capability, not the aggregation runtime spec.
- Solr / ES translator internals (`SolrAggregationQueryBuilder`,
  `ElasticsearchAggregationQueryBuilder`). The in-flight change's
  task list already specifies the translation surface; observed
  builder behaviour is covered by those tasks' unit tests.
- Timeseries request validator (`TimeseriesRequestValidator`).
  Already covered by `add-time-bucket-aggregation` change spec.
- Cross-schema `@self.*` resolution detail. Captured in REQ-ABN-003's
  cross-schema scenario at the contract level only — the recursive
  resolver is implementation detail.

## Notes on observed-but-suspicious behaviour

These are deliberately surfaced as notes in the spec, not
"fixed" by tightening the REQ:

- **Strict float equality in `compare()`.** REQ-ABN-005 specifies
  what the listener does (`===` on `(float)` conversions); a
  follow-up could relax to epsilon-based comparison.
- **Coarse cache eviction.** REQ-ABN-001 specifies the
  fail-closed contract; the `ICache::clear()` invalidation is
  documented as a known coarse-grained tradeoff.
- **Hard-coded PHP fallback row cap.** REQ-ABN-002 specifies the
  `truncated` flag; making the cap pluggable per-schema is a
  future hardening step.
