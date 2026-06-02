---
kind: code
depends_on: []
---

# Time-bucket aggregation across GraphQL + REST

## Why

Every Conduction-app dashboard needs the same ad-hoc primitive: "group these rows by day-of-`created` between `from` and `to` and return `{ key, value }` series." The openconnector dashboard rebuild (6 KPIs + 6 charts — Calls daily/hourly, Jobs daily/hourly, Sync daily/hourly) is blocked on it today, and `nextcloud-vue` `CnChartWidget.dataSource` cannot grow its `bucket: { field, interval, fromVar, toVar }` shorthand until OR exposes the underlying primitive.

OR's current aggregation surface is two-half: GraphQL collection queries support `aggregate: 'count'` (returns `totalCount`) but no grouping; REST `AggregationController` runs **named, schema-declared** aggregations from `x-openregister-aggregations` annotations (`/api/objects/aggregations/{register}/{schema}/{name}`). Neither lets a client say "bucket arbitrary field `created` by `DAY` between `from` and `to`" without first round-tripping a schema-annotation deploy. The in-flight `aggregations-backend-native` change shipped the `AggregationQuery::dateBucket` value-object field but did NOT wire it through to Postgres native execution, REST, or GraphQL.

## What Changes

- **GraphQL**: extend the auto-generated list query on every schema with a new optional argument `groupBy: GroupByInput`, where `GroupByInput = { field: String!, interval: TimeInterval, from: String, to: String }` and `TimeInterval = DAY | HOUR | WEEK | MONTH`. When `groupBy` is supplied, the connection returns `groups: [{ key: String!, value: Int! }]` alongside the existing `totalCount` / `edges` / `pageInfo`. When `interval` is omitted, the result is categorical grouping (e.g. by `status`); when present, the named field is `date_trunc`'d to the interval and rows outside `from`/`to` are excluded.
- **REST**: add `GET /api/objects/{register}/{schema}/aggregate/timeseries?field=<f>&interval=<i>&from=<iso>&to=<iso>&filter[...]=<v>` returning `{ groups: [{ key, value }], backend: "postgres" | "php-fallback", cached: bool }`. Same row-level filtering (multi-tenancy + RBAC) as the regular `/objects` list. Filter params reuse the existing `_search`/`filter[...]` operator vocabulary.
- **Backend**: extend `AggregationRunner::tryNativeAggregation()` to honour `AggregationQuery::dateBucket` via Postgres `date_trunc($gap, "$field")` — with explicit `WHERE "$field" >= ? AND "$field" < ?` bounds when `start`/`end` are set. SQLite + MySQL fall back to the existing PHP path (documented; OR's primary platform is Postgres).
- **Cross-backend**: AggregationQuery already has `dateBucket: { field, start, end, gap }` plumbed through to Solr (`facet.range`) and Elasticsearch (`date_histogram`). This change unblocks them by exercising the field end-to-end and adding Postgres as a third translator-target.
- **Security**: bucketing happens AFTER row-level RBAC + multi-tenant filtering (same `_organisation = ?` predicate `tryNativeAggregation` already enforces). Field allow-listing: only datetime-shaped properties from the schema may be used as `interval`-bucketed fields; only properties declared on the schema may be used as `groupBy.field` (no arbitrary column access).
- **Response shape parity**: the REST timeseries response and the GraphQL `groups` field MUST emit the same `{ key: <iso-bucket-start | string>, value: <int> }` element shape so `CnChartWidget` can normalise once.
- **Documentation**: extend `openspec/specs/graphql-api/spec.md` with the `groupBy` requirement + scenarios; create new `aggregation-api` spec covering both REST + GraphQL ad-hoc bucketing semantics.

**Non-breaking.** Adds optional GraphQL argument + new REST endpoint. The existing `aggregate: 'count'` GraphQL arg, the existing `/api/objects/aggregations/{register}/{schema}/{name}` route, and the existing `x-openregister-aggregations` annotation surface all continue to work unchanged.

## Capabilities

### New Capabilities
- `aggregation-api`: ad-hoc aggregation primitive across REST + GraphQL — categorical groupBy on a schema field + time-bucketed groupBy via `date_trunc` with optional `from`/`to` bounds. Distinct from `x-openregister-aggregations` annotations (which are **named**, schema-declared, and resolve through `AggregationController`) — this capability covers the runtime, client-controlled primitive.

### Modified Capabilities
- `graphql-api`: list queries gain an optional `groupBy: GroupByInput` argument; the auto-generated connection types gain a `groups: [GroupBucket!]` field on the response. Existing `totalCount` / `edges` / `pageInfo` behaviour unchanged.

## Impact

**Code touched:**
- `lib/Service/Aggregation/AggregationRunner.php` — extend `tryNativeAggregation()` with `dateBucket` path (Postgres `date_trunc`).
- `lib/Service/GraphQL/SchemaGenerator.php` + `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php` — new `GroupByInput`, `TimeInterval` enum, `GroupBucket` type; thread `groupBy` arg into list-query field args.
- `lib/Service/GraphQL/GraphQLResolver.php` — extract `groupBy` arg, call `AggregationRunner` (or build an `AggregationQuery` directly + dispatch), surface `groups` on the connection result.
- `lib/Controller/AggregationController.php` — new `timeseries()` action OR a new sibling controller (`AggregateController`) to keep route ownership clean.
- `appinfo/routes.php` — register the new REST timeseries route.
- Tests: `tests/Unit/Service/Aggregation/AggregationRunnerDateBucketTest.php`, `tests/Unit/Controller/Aggregation*Test.php`, `tests/Unit/Service/GraphQL/SchemaGeneratorGroupByTest.php`, `tests/Integration/GraphQLGroupByIntegrationTest.php`.
- Docs: `docs/annotations/x-openregister-aggregations.md` gains a "Runtime ad-hoc primitive" note pointing at the new spec.

**APIs:**
- New: `GET /api/objects/{register}/{schema}/aggregate/timeseries`.
- Extended: every auto-generated GraphQL list field — added optional `groupBy` arg + `groups` field on the connection.

**Dependencies / consumers:**
- Unblocks `nextcloud-vue` `CnChartWidget.dataSource` `bucket: { field, interval, fromVar, toVar }` shorthand.
- Unblocks the openconnector dashboard rebuild (6 charts).
- Every Conduction app dashboard (procest, pipelinq, decidesk, shillinq, etc.) will eventually consume this primitive — the response shape MUST stay stable.

**Non-goals (deferred to follow-up issues, filed at planning time):**
- Multi-field `groupBy` (one field per query only) — [#1606](https://github.com/ConductionNL/openregister/issues/1606).
- Running-window aggregates / cumulative series — [#1607](https://github.com/ConductionNL/openregister/issues/1607).
- Multi-metric (`count` + `sum` simultaneously); each request is one metric — [#1608](https://github.com/ConductionNL/openregister/issues/1608).
- MySQL / SQLite native bucketing (PHP fallback only for now) — [#1609](https://github.com/ConductionNL/openregister/issues/1609).
- Caching of ad-hoc bucket queries — `AggregationCache` is keyed on the named-aggregation name; ad-hoc cache keying is — [#1610](https://github.com/ConductionNL/openregister/issues/1610).
- `groupBy` on the JOIN side of cross-schema queries (no issue — explicitly out of scope for the primitive).
