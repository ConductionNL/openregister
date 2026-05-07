# Tasks — Aggregations and Calculations

> **⚠️ ADR-028 gating note (MUST READ before merge)**
>
> This change currently bundles two independent mechanisms (aggregations + calculations) and exceeds the ADR-028 ≤15-tasks-per-mechanism limit. Per ADR-028 it MUST NOT merge in this form.
>
> **Resolution path:** the split lives in companion PR **#1354** (separate `aggregations-only` and `calculations-only` change directories, each ≤15 tasks). This PR (#1353) MUST either:
>
> 1. wait for **#1354** to land first and then drop this directory in favour of the split, or
> 2. be reduced in scope to a single mechanism here before merge.
>
> The self-acknowledgement in the PR description does **not** waive the ADR — it only tracks the violation. CI / supervisor MUST treat this directory as blocked until the split lands. See review comment [#3200745243](https://github.com/ConductionNL/openregister/pull/1353#discussion_r3200745243).

## 1. Schema validation

- [ ] 1.1 Add `x-openregister-aggregations` validation to `SchemaService::saveSchema()` — every filter/field/groupBy field exists, every operator known, every placeholder known, every metric known, no two aggregations share a name.
- [ ] 1.2 Add `x-openregister-calculations` validation — every property reference in `expression` exists on the schema, every function in the v1 vocabulary list, no cyclic dependencies between calculations, declared `type` matches the expression's return type (best-effort static check).

## 2. Calculation evaluator

- [ ] 2.1 Create `lib/Service/Calculation/CalculationEvaluator.php` — pure-function evaluator. Inputs: object payload + expression AST. Output: typed value or evaluation error. No I/O, no DB access, no HTTP.
- [ ] 2.2 Create `lib/Service/Calculation/ExpressionParser.php` — parses the v1 expression DSL into an AST. v1 vocabulary: bare property refs, arithmetic (`+`/`-`/`*`/`/`/`%`), comparison (`eq`/`ne`/`lt`/`lte`/`gt`/`gte`), logical (`&&`/`||`/`!`), string `concat`, conditional `if(cond, then, else)`, date `now()` / `diffDays(later, earlier)` / `formatDate(d, fmt)`.
- [ ] 2.3 Unit tests covering every operator + every function + edge cases (null inputs, type mismatches, nested expressions, conditionals).
- [ ] 2.4 Property tests: random valid expressions evaluate without exception; random invalid expressions produce a clear error code.

## 3. On-save calculation listener

- [ ] 3.1 Create `lib/Listener/CalculationOnSaveListener.php` — subscribes to `ObjectCreatingEvent` and `ObjectUpdatingEvent`. For every calculation declared with `materialise: true`, runs the evaluator and patches the field before persistence.
- [ ] 3.2 Idempotency — skip recomputation when none of the calculation's input fields changed (track via `ObjectUpdatingEvent`'s old-state).
- [ ] 3.3 Cycle detection — sort calculations by their input dependencies; reject schemas with cycles at save validation time.
- [ ] 3.4 Unit tests: input-changed triggers recompute; input-unchanged does not; downstream calculation that depends on another's materialised value sees the new value.

## 4. Virtual calculation render

- [ ] 4.1 Hook into `ObjectService::renderObject()` (or wherever the object → API response transform lives). For every calculation declared with `materialise: false`, run the evaluator at render time.
- [ ] 4.2 Cache per-request — multiple reads of the same object in one request reuse the calculation's result.
- [ ] 4.3 `_include=calculations` query param on the existing search/get endpoints — when present, virtual calculations are rendered; when absent, they're omitted (saves render cost on bulk fetches that don't need them).

## 5. Aggregation compiler

- [ ] 5.1 Create `lib/Service/Search/AggregationCompiler.php` — turns an aggregation spec + the existing filter compiler's output into the backend-specific aggregate query.
- [ ] 5.2 Postgres: emit `SELECT COUNT(*) / SUM / AVG / MIN / MAX / COUNT(DISTINCT)` over the existing dynamic table, with optional `GROUP BY` (plain field) or `GROUP BY date_trunc(<bucket>, field)`.
- [ ] 5.3 Solr: emit `facet.field` / `facet.range` / `stats.field` per metric.
- [ ] 5.4 Elasticsearch: emit `aggs` clauses with `value_count` / `sum` / `avg` / `min` / `max` / `cardinality`, optionally with `terms` or `date_histogram` buckets.
- [ ] 5.5 Each backend's `aggregate(...)` method on `SearchBackendInterface` returns either `{value: N}` or `{groups: [{key, value}]}`.

## 6. Placeholder resolver (shared with notifications-annotation)

- [ ] 6.1 Create `lib/Service/Search/PlaceholderResolver.php` — resolves `$now`, `$startOfDay`/`$startOfWeek`/`$startOfMonth`/`$startOfYear`, `$currentUser`, with offset arithmetic (`$now-7d`, `$startOfMonth-1`).
- [ ] 6.2 Used by both aggregation filter compilation AND notification trigger filters (this change ships the resolver; the notifications-annotation change consumes it).

## 7. Sugar endpoint + inline mode

- [ ] 7.1 Create `lib/Controller/AggregationController.php` — `aggregate(string $name)` resolves `(register, schema, name)` from query params + the path, looks up the aggregation in the schema, dispatches to the backend, returns response. `#[NoAdminRequired]`.
- [ ] 7.2 Register route `GET /api/objects/aggregations/{name}` in `appinfo/routes.php`.
- [ ] 7.3 Inline mode: extend the existing search endpoint to accept `_aggregate=name1,name2` — alongside the normal results, return `{results: [...], aggregations: {name1: <value>, name2: <value>}}`. Saves a round trip when a UI needs both the list and a count.

## 8. Cache + invalidation

- [ ] 8.1 Reuse the existing cache layer used by `findObjects`. Cache key for aggregations: `(register, schema, name, resolved-placeholders-hash, rbac-scope-hash)`. TTL 60s.
- [ ] 8.2 Invalidate on `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent` / `ObjectTransitionedEvent` for the affected `(register, schema)`.

## 9. Documentation

- [ ] 9.1 `docs/annotations/x-openregister-aggregations.md` — full annotation reference, every metric type, every placeholder, time-bucket semantics, RBAC interaction, cache TTL.
- [ ] 9.2 `docs/annotations/x-openregister-calculations.md` — expression DSL reference (every function with examples), virtual vs materialised, idempotency guarantees, cycle rules.
- [ ] 9.3 Worked example: decidesk's ActionItem schema annotated with both — `daysFromCreatedToCompleted` as a materialised calculation, `avgDaysToClose` as an aggregation over it.

## 10. Migration support

- [ ] 10.1 `occ openregister:validate-calculations <register> <schema>` — re-runs every calculation against existing objects and reports any whose stored value differs from the recomputed one (catches drift before re-materialising).
- [ ] 10.2 `occ openregister:rematerialise-calculations <register> <schema>` — bulk reprocesses every object's materialised calculations (used after adding a new calculation to a schema with existing data).

## 11. CI + quality

- [ ] 11.1 Test coverage ≥ 90% on the evaluator, the aggregation compiler, and the listeners.
- [ ] 11.2 `composer check:strict` passes.
- [ ] 11.3 `hydra-gates` mechanical gates pass (full repo + scope-to-diff modes).

## 12. Platform catalog

- [ ] 12.1 Add `x-openregister-aggregations` and `x-openregister-calculations` annotations to `openspec/platform-capabilities.md`.
- [ ] 12.2 Add the `GET /api/objects/aggregations/{name}` endpoint and the `_aggregate=` and `_include=calculations` query params to the same catalog.
- [ ] 12.3 Add a CHANGELOG entry under "Unreleased → Added" when this change graduates from `proposed` to `implemented`. Entry covers: `x-openregister-aggregations` and `x-openregister-calculations` annotations, `GET /api/objects/aggregations/{name}` endpoint, `_aggregate=` and `_include=calculations` query params, `ObjectTransitionedEvent` cache-invalidation hook. (Spec-only PRs MAY defer the entry to the implementing PR; this task tracks the obligation.)
