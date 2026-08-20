---
kind: code
status: done
---

## Why

A consuming app (e.g. pipelinq) that runs in-process calls
`OCA\OpenRegister\Service\ObjectService::findAll(array $config)` /
`count(array $config)` for object queries, and that config path exposes
`filters` (eq / `in` / `gte` / `lte` / `gt` / `lt` / date-range), `sort`,
`limit`, and `offset` — but **not** server-side SUM/AVG/MIN/MAX aggregation,
and **not** a `NOT IN` exclusion filter. So apps fetch-all-and-sum-in-PHP for
rollups, and have no way to express "everything except this set".

Meanwhile OpenRegister already owns the aggregation power:
`AggregationRunner::runAdhocByRef(string $registerRef, string $schemaRef,
AggregationQuery $query)` is a fully RBAC- and tenant-scoped ad-hoc entry point
(the same gate `findAll()` applies) that returns count/sum/avg/min/max,
optionally grouped, via a native-SQL-or-PHP-fallback dispatch. It is reachable
in-process today — it was simply never documented as the consuming-app contract,
and its filter vocabulary was missing the `notIn` operator that the `NOT IN`
requirement needs.

The gap is therefore (1) **documentation** — make the in-process ad-hoc call
pattern a first-class, discoverable contract — and (2) a **small operator
addition** — wire `notIn` (and the symmetric, also-missing `ne`) through both
the aggregation filter path and the `findAll`/`count` config-filter path so
`NOT IN` is reachable on both ordinary object queries and aggregations.

## What Changes

- **`notIn` operator on the aggregation ad-hoc path.** `AggregationRunner`'s
  native SQL builder (`tryNativeAggregation`) accepts `{field: {notIn: [...]}}`
  and emits `"field" NOT IN (?, ?)`; an empty `notIn` list excludes nothing
  (emits an always-true predicate). The PHP fallback `checkOp` mirrors the same
  semantics. `AggregationQuery`'s documented operator vocabulary gains `notIn`.
- **`notIn` + `ne` on the `findAll`/`count` config-filter path.**
  `MagicSearchHandler` (both the QueryBuilder filter path and the raw-SQL UNION
  filter path) recognises `notIn` → `NOT IN (...)` and `ne` → `<> value`, so a
  consuming app can exclude a set of values on an ordinary object query without
  aggregating. An empty `notIn` list emits no clause (no malformed `NOT IN ()`).
- **Document the in-process consuming-app contract.** `docs/technical/aggregation-api.md`
  gains an "In-process (PHP service) surface" section: the exact `runAdhocByRef`
  call pattern (DI-resolve, build `AggregationQuery`, config shape, return shape,
  RBAC/tenant guarantees) plus the full filter-operator vocabulary table.

No new endpoint, no new public method, no signature change — the entry point
(`runAdhocByRef`) and the value object (`AggregationQuery`) already exist. This
is an operator addition on existing filter code plus documentation.

## Capabilities

### Modified Capabilities
- `aggregation-api`: gains a requirement that the ad-hoc aggregation primitive
  is reachable in-process by a consuming PHP service via
  `AggregationRunner::runAdhocByRef()` with the documented RBAC/tenant-scoped
  config + return contract, and that its filter vocabulary (and the
  `findAll`/`count` config-filter vocabulary) includes a `notIn` exclusion
  operator.

## Impact

- **Code:** `lib/Service/Aggregation/AggregationRunner.php` (`notIn` native-SQL
  emission + PHP `checkOp`), `lib/Service/Aggregation/AggregationQuery.php`
  (operator-vocabulary docblock), `lib/Db/MagicMapper/MagicSearchHandler.php`
  (`notIn` + `ne` in both filter sites).
- **Tests:** `tests/Service/AggregationRunnerIntegrationTest.php` (native
  `notIn`, consumer-style grouped SUM + AVG-with-notIn + MIN/MAX via
  `runAdhocByRef`; also fixes a pre-existing tenant-stamp baseline so the whole
  file passes), `tests/Unit/Service/Aggregation/AggregationRunnerNativeBucketTest.php`
  (`notIn` SQL emission + empty-list always-true), `tests/Unit/Db/MagicSearchHandlerNumericUuidFilterTest.php`
  (`notIn` / empty-`notIn` / `ne` in the findAll path).
- **Docs:** `docs/technical/aggregation-api.md` (in-process surface + operator table).
- **Consumers:** pipelinq Seam 1 Batch 2 (RoutingService requests-leg, KPI
  rollups) consumes `runAdhocByRef` + `notIn` instead of fetch-all-and-sum.
- **Security:** no new surface; `runAdhocByRef` applies the same `list` RBAC
  verdict + `_organisation` tenant predicate as `findAll`; empty `notIn` is
  fail-open-on-exclusion (retains rows) which is the SQL-correct semantics.
