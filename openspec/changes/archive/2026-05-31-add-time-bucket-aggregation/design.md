## Context

OpenRegister exposes two aggregation surfaces today:

1. **Named declarative aggregations** — schema-author declares an `x-openregister-aggregations` block on a schema; `/api/objects/aggregations/{register}/{schema}/{name}` resolves it through `AggregationRunner`. Backend dispatch (Postgres native → PHP fallback) ships in the in-flight `aggregations-backend-native` change; Solr + ES translators are merged but not yet wired into `AggregationRunner::run()`.
2. **GraphQL `aggregate: 'count'`** — every auto-generated list query returns a `totalCount` field. No grouping, no bucketing.

Neither lets a client say *"group this collection by `created` truncated to DAY, between `from` and `to`, return `{ key, value }`"* — which is the exact primitive every Conduction dashboard chart needs. Worked example: the openconnector dashboard wants 6 charts (Calls daily/hourly, Jobs daily/hourly, Sync daily/hourly). Each chart is a single bucketed query. Today the only path is a chart-specific named annotation per app, which is a schema-deploy round-trip per chart per app.

The cross-backend value object `AggregationQuery` already declares `dateBucket: { field, start, end, gap }` (shipped with `aggregations-backend-native` task 1.1) and the Solr / Elasticsearch translators (`SolrAggregationQueryBuilder`, `ElasticsearchAggregationQueryBuilder`) already honour it. What is missing is:

- Postgres translation (`date_trunc($gap, "$field")`).
- A REST entry point that builds an `AggregationQuery` from query params and calls the runner directly (the existing `AggregationController` only resolves named annotations).
- A GraphQL `groupBy: GroupByInput` argument on auto-generated list queries that does the same.

Constraints / stakeholders:
- `nextcloud-vue` `CnChartWidget.dataSource` grows a `bucket` shorthand the moment OR ships this; openconnector dashboard blocked on it.
- Every Conduction app eventually consumes the primitive — response shape must stay stable across apps, REST, and GraphQL.
- ADR-031 says prefer declarative `x-openregister-aggregations` over service-class aggregation. This work is **not** a service-class aggregation; it is a query primitive on the existing runtime, controlled per-request by the client. ADR-031 still applies for named, app-owned aggregations (KPI tiles, business-rule counts).
- ADR-032 says split mixed config-and-code. This work is `kind: code` only (PHP runner + GraphQL resolver + REST controller). No schema register patches.
- ADR-005 (security): all reads pass row-level RBAC + multi-tenant filter. Aggregation runs AFTER the filter is applied to the row set. Field allow-listing prevents arbitrary column access.

## Goals / Non-Goals

**Goals:**
- One ad-hoc bucketing primitive available on both REST and GraphQL with the same response shape `{ groups: [{ key, value }] }`.
- Postgres-native execution via `date_trunc($gap, "$field")` for `interval`-bucketed queries; categorical groupBy already shipped.
- Cross-backend forward-compat: Postgres now, Solr / ES already-translated (just need the runner dispatch wired by `aggregations-backend-native`).
- Field allow-listing enforced at the controller / resolver layer using the schema's declared property list — no arbitrary column access.
- Row-level RBAC + multi-tenant filter is applied identically to the existing native aggregation path; the `_organisation = ?` predicate is reused (no new code path that could bypass it).
- Documentation: `aggregation-api` spec covers REST + GraphQL semantics; `graphql-api` spec gains a delta for the new arg.

**Non-Goals:**
- Multi-field `groupBy` — one field per query only. A second field would explode the result set and most chart libraries can't render multi-dim. Follow-up issue.
- Running / cumulative / windowed aggregates. Follow-up issue.
- Multi-metric in one request (`count` + `sum` at the same time). Each request is one metric. Follow-up issue.
- Cross-schema `groupBy`. Each query runs against one register/schema's magic table.
- MySQL / SQLite native bucketing. Postgres only; non-Postgres returns `null` from `tryNativeAggregation` and the runner falls back to PHP-side bucketing (slow but correct). Documented.
- Caching of ad-hoc bucket queries. `AggregationCache` is keyed on `(register, schema, name, filter, rbac)` where `name` is the annotation name; ad-hoc queries have no `name`. Cache key extension is a follow-up issue.
- Replacing the `aggregate: 'count'` GraphQL arg or the `x-openregister-aggregations` named-annotation surface. Both stay.

## Decisions

### D1 — Reuse `AggregationQuery::dateBucket` value object as-is

**Decision:** Build the new REST endpoint + GraphQL resolver on top of the already-shipped `AggregationQuery::create(metric, field, filter, groupBy, dateBucket)` factory. No new value object.

**Rationale:** The value object already encodes the constraint surface (gap vocabulary `minute|hour|day|week|month|quarter|year`, required `{field, start, end, gap}` shape, mutual exclusion with `groupBy`). Adding a parallel input shape would diverge and risk drift between REST and GraphQL.

**Alternatives:** Introduce a separate `TimeseriesQuery` value object — rejected because it would duplicate validation already in `AggregationQuery::assertValidDateBucket`.

### D2 — Postgres native path: `date_trunc($gap, "$field")` + explicit bounds

**Decision:** In `AggregationRunner::tryNativeAggregation()`, when `dateBucket` is non-null, emit:

```sql
SELECT date_trunc(?, "field") AS bucket, COUNT(*) AS agg
FROM "oc_<table>"
WHERE <existing where> AND "field" >= ? AND "field" < ?
GROUP BY 1
ORDER BY 1
```

Bind `gap`, `start`, `end` as the first three placeholders. Validate `gap` against the same `DATE_BUCKET_GAPS` allow-list `AggregationQuery` enforces. Sanitise `field` via the existing `sanitizeColumnName()` helper. Bucket key is returned as Postgres timestamp text (ISO-8601-ish); the controller / resolver coerces it to an ISO-8601 string before serialising.

**Rationale:** `date_trunc` is the canonical Postgres bucketing primitive; it's index-friendly when the bucketed field has a btree index (recommended in docs). Explicit `from`/`to` bounds keep the predicate sargable. Empty `start`/`end` are allowed (= no bound) — but the REST endpoint requires both (no unbounded scans from HTTP).

**Alternatives:** `generate_series` to fill empty buckets — rejected because (a) the client can fill empties at render time more cheaply, (b) `generate_series` doesn't compose with the RBAC `WHERE`. Documented as a follow-up if charting clients complain.

### D3 — Field allow-listing via schema property list

**Decision:** Both the REST controller and the GraphQL resolver validate `dateBucket.field` and `groupBy.field` against `Schema::getProperties()` keys before constructing the `AggregationQuery`. Bucketed (interval-bucketed) fields must additionally have `format: date | date-time` or `type: integer` (timestamp epoch). Unknown / non-bucketable fields → `400 Bad Request` / GraphQL field-error.

**Rationale:** `sanitizeColumnName` defends against SQL injection (it must — it's user input that lands in raw SQL) but it does NOT prevent reading columns the user could not otherwise see (e.g. `_owner`, magic-table metadata). Schema-property allow-listing is the second layer: only declared properties of the schema may be aggregated.

**Alternatives:** Allow magic-table metadata cols (`_created`, `_updated`) — done, but only for that specific allow-list (`{ _created, _updated, _deleted_at }`), not all `_*` cols. RBAC field rules (`PropertyRbacHandler`) already apply to read; the aggregator MUST honour the same denial.

### D4 — REST endpoint shape: dedicated `/aggregate/timeseries` action

**Decision:** New route `GET /api/objects/{register}/{schema}/aggregate/timeseries` registered in `appinfo/routes.php` with handler `AggregationController::timeseries()`. Query params:

- `field` (required) — field to bucket on.
- `interval` (optional, default = categorical groupBy) — one of `MINUTE|HOUR|DAY|WEEK|MONTH|QUARTER|YEAR`.
- `from`, `to` (required when `interval` set) — ISO-8601 strings.
- `metric` (optional, default `count`) — one of `count|sum|avg|min|max`.
- `metricField` (required when `metric != count`) — field to aggregate over.
- `filter[<col>][<op>]=<val>` — reuses the existing object-collection filter vocabulary.

Response: `{ groups: [{ key, value }], backend, cached }` — same body as the GraphQL `groups` field.

**Rationale:** Co-locating with the named-aggregation route under `/aggregate/` keeps namespace clarity. `timeseries` is the ad-hoc surface; `aggregations/{name}` stays the annotation-named surface. Both go through `AggregationRunner`.

**Alternatives:** Extend the existing `/api/objects/{register}/{schema}` listing with `?aggregate=timeseries&...` — rejected because the listing response shape is `{ results, total, page }` and would have to either grow a new top-level field or fork. Dedicated endpoint = stable contract.

### D5 — GraphQL: optional `groupBy` arg on the auto-generated list query

**Decision:** `SchemaGenerator::buildQueryFields()` adds an optional `groupBy: GroupByInput` argument to every list-query field via `TypeMapperHandler::getListArgs()`. Types:

```graphql
input GroupByInput {
  field: String!
  interval: TimeInterval
  from: String
  to: String
  metric: AggregationMetric
  metricField: String
}

enum TimeInterval { MINUTE HOUR DAY WEEK MONTH QUARTER YEAR }
enum AggregationMetric { COUNT SUM AVG MIN MAX }

type GroupBucket { key: String! value: Float! }

# the existing Connection type grows:
type <Schema>Connection {
  edges: [...]
  pageInfo: ...
  totalCount: Int
  groups: [GroupBucket!]   # NEW — null when groupBy not requested
}
```

`GraphQLResolver::resolveList()` reads `args.groupBy`, builds an `AggregationQuery`, calls `AggregationRunner::runAdhoc()` (new method — extracted shared path), and attaches `groups` to the connection result. When `groupBy` is absent the field returns `null` (not an empty array — `null` is "not requested").

**Rationale:** A dedicated `GroupByInput` keeps the existing list args (`filter`, `sort`, `first`, etc.) un-touched; clients opt in.

**Alternatives:** A separate root-level `groupBy<Schema>` query field — rejected because it duplicates the filter / sort / RBAC plumbing that already lives in `resolveList()`. Sharing the resolver means one auth path, one filter parser, one cache eviction story.

### D6 — Shared adhoc path: `AggregationRunner::runAdhoc(AggregationQuery $q)`

**Decision:** Introduce a new public method on `AggregationRunner` that takes a fully-built `AggregationQuery` + the `(Register, Schema, $currentUid)` triple and returns the same `{ value, groups, backend, cached? }` shape `run()` already returns. The existing `run($registerRef, $schemaRef, $name)` keeps loading the annotation by name and continues to delegate to the same private execution helper.

**Rationale:** Both REST `timeseries()` and GraphQL `resolveList()` would otherwise duplicate the RBAC check + backend-selection logic that `run()` performs. Extracting `runAdhoc()` makes the named and ad-hoc paths share the execution helper.

**Alternatives:** Have the controller / resolver call private methods on the runner via reflection — rejected (breaks Psalm). Have them call the SQL directly — rejected (duplicates RBAC predicate).

### D7 — Multi-tenant predicate, RBAC, and audit

**Decision:**
- `runAdhoc()` calls `OrganisationService::getActiveOrganisation()` exactly like the existing `tryNativeAggregation()` and binds `_organisation = ?`. `null` active org → no rows (fail-closed).
- `PermissionHandler::canRead($schema)` is consulted before any SQL is built; deny → `NotAuthorizedException` → HTTP 403 / GraphQL error.
- Field allow-listing per D3 happens before the predicate so we don't leak the existence of a field by giving a different error code.
- No audit row is written for aggregate reads (consistent with the existing `/aggregate/{name}` path).

**Rationale:** Reuse the existing security primitives so the new surface inherits every fix that lands on the named path. Field allow-listing precedes auth-check so the response stays consistent.

### D8 — Non-Postgres fallback path

**Decision:** When `tryNativeAggregation()` returns `null` (e.g. SQLite test DB, MySQL dev box), the PHP-side runner pulls matching rows + groups them in PHP using the same bucket logic (`date_trunc` polyfill: strtotime + `format()` based on `gap`). Marked `backend: "php-fallback"` in the response.

**Rationale:** OR's production target is Postgres; SQLite is used for tests and trivial dev setups. PHP fallback keeps tests green without forcing every CI runner to be Postgres-only. Performance disclaimer in docs.

### D9 — Declarative-vs-imperative decision (ADR-031)

The behaviours touched here are:

| Behaviour | Path | Rationale |
|---|---|---|
| Ad-hoc bucketing primitive | **imperative** (PHP runner extension) | ADR-031 §"declarative path applies to **named** behaviours" — this is an ad-hoc query primitive controlled per-request by the client, not a schema-author-declared behaviour. There is no `x-openregister-*` annotation that would express "let the client bucket whatever they want at request time". |
| Named aggregations (`x-openregister-aggregations`) | **declarative** (existing — unchanged) | Already declarative; this change does NOT touch the annotation path. |
| Field allow-list | **declarative** (read from `Schema::getProperties()`) | Each schema's property list is already the canonical source of truth for what's queryable. |

No new service class is introduced — `AggregationRunner` already exists and is the right home. No `lib/Settings/openregister_register.json` patches.

## Risks / Trade-offs

- **[SQL-injection on `field`]** → Mitigated: `sanitizeColumnName()` allow-lists `[a-zA-Z0-9_]` and the schema-property check ensures the column was declared. Both gates run before binding.
- **[Unbounded scan with missing `from`/`to`]** → Mitigated: REST endpoint requires both when `interval` is set; categorical groupBy (no `interval`) doesn't require bounds because it scans within the schema's RBAC-filtered row set anyway.
- **[Postgres `date_trunc` returns timestamps with tz]** → Mitigated: cast to text in SQL (`date_trunc(?, "$field")::text AS bucket`) so PHP receives an ISO-ish string; resolver coerces to `Y-m-d\TH:i:s\Z` for response stability across Postgres versions / timezone settings.
- **[GraphQL connection-type breaking change]** → Mitigated: `groups` is a new nullable field; existing clients that don't request it see nothing change. Schema introspection still returns the same set of existing fields.
- **[Bucket keys drift between Postgres / Solr / ES]** → Mitigated: the spec mandates the response shape; per-backend translators must produce ISO-8601-UTC strings. Translator tests assert the wire shape.
- **[PHP fallback at 100k rows]** → Mitigated: documented + perf note in `aggregation-api` spec; Postgres is the production target. The PHP fallback is correct, not fast.
- **[Cache misses on ad-hoc queries]** → Mitigated: each ad-hoc request will hit Postgres. `AggregationCache` keying extension is a follow-up issue — for now `cached: false` is always emitted on the ad-hoc path.
- **[`groupBy` arg vs `aggregate: 'count'` arg confusion]** → Mitigated: existing `aggregate` arg stays; spec.md clearly delineates "count aggregate" (one number for the whole set, returned as `totalCount`) vs "groupBy bucket" (a series of bucket→value pairs, returned as `groups`).

## Migration Plan

- **Deploy:** Single PR, single OR release. No schema migration, no register/schema author action required. The new endpoint is a pure addition; the new GraphQL arg is an additive type-system change (clients that don't request it see no diff).
- **Rollback:** Revert the PR. Existing `aggregate: 'count'` + named-annotation surface continues to work because both are untouched.
- **Consumer rollout:**
  1. OR ships this change.
  2. `nextcloud-vue` `CnChartWidget.dataSource` grows the `bucket` shorthand.
  3. openconnector dashboard rebuild consumes it (6 charts).
  4. Fleet apps adopt the primitive opportunistically; the named-annotation surface remains the right home for app-owned KPIs.

## Open Questions

1. **Bucket key format on Postgres for `interval=DAY`** — `date_trunc('day', ...)` returns `2026-05-21 00:00:00+00`. Coerce to `2026-05-21` (date-only) or keep full ISO? **Decided: keep full ISO** — consistent across intervals; charts crop the date portion themselves.
2. **`HOUR` granularity on a `date` (not `datetime`) field** — should the schema-property check reject? **Decided: yes** — the field's declared `format` must be `date-time` for sub-day intervals; `date` only allows DAY+. Validation error code `400`.
3. **Empty-bucket fill** — if a day has zero rows, do we emit `{key, value: 0}` or omit it? **Decided: omit it** (Postgres `GROUP BY` doesn't emit empty groups). Client fills empties at render time. `generate_series` is a follow-up.
4. **Should the REST `/aggregate/timeseries` route mirror the path layout of `/aggregations/{name}`** (i.e. `/api/objects/aggregations/{register}/{schema}/timeseries`)? **Decided: yes — `/api/objects/aggregations/{register}/{schema}/timeseries`** to keep the namespace tidy. The `{name}` route stays `/api/objects/aggregations/{register}/{schema}/{name}`; routing dispatches `timeseries` to the new action because route order in `appinfo/routes.php` is "specific before wildcard" (memory: route ordering).
