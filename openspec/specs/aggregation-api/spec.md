---
status: done
---

# aggregation-api Specification

## Purpose
Provides a runtime, client-controlled aggregation primitive over REST and GraphQL that buckets the rows of a register-schema collection by a named field, with optional time-bucketing and count/sum/avg/min/max metrics. Enforces row-level RBAC and multi-tenant filtering before aggregating, generates native SQL per database engine (with a PHP fallback), and caches results for up to 60 seconds with event-driven invalidation.

@e2e exclude Backend aggregation primitive over REST + GraphQL (groupBy, time-bucketing, metric, per-engine SQL dialect generation, validation, multi-tenant filtering, ad-hoc cache eviction). No OpenRegister UI surface — the aggregation result feeds dashboards but the primitive itself is a data-layer/HTTP contract exercised via PHPUnit (dialect/validator units) and Newman (REST/GraphQL contract). Covered by Newman/PHPUnit.
## Requirements
### Requirement: The system SHALL expose an ad-hoc aggregation primitive over REST and GraphQL

OpenRegister SHALL surface a runtime, client-controlled aggregation primitive that buckets the rows of a single register-schema collection by a named field, with optional time-bucketing via a `date_trunc`-style interval and optional `from`/`to` bounds. This primitive is distinct from the named-annotation surface (`x-openregister-aggregations` resolved via `/api/objects/aggregations/{register}/{schema}/{name}`): the named surface is schema-author-declared; this primitive is client-controlled per request.

The primitive SHALL be available on both REST and GraphQL with the same response shape `{ groups: [{ key: String, value: Number }] }`, where `key` is the bucket label (categorical: the value of the groupBy field; bucketed: an ISO-8601-UTC string at the bucket-start) and `value` is the aggregated metric (count, sum, avg, min, or max).

#### Scenario: Categorical groupBy over the REST endpoint
- **GIVEN** a register `softwarecatalogus`, schema `applications` with property `status: string`
- **WHEN** the client issues `GET /api/objects/aggregations/softwarecatalogus/applications/timeseries?field=status`
- **THEN** the response status SHALL be `200`
- **AND** the response body SHALL match `{ groups: [{ key: String, value: Int }], backend: "postgres" | "php-fallback", cached: Boolean }`
- **AND** every `groups[i].key` SHALL be a distinct value of `status` present in the RBAC-filtered row set
- **AND** every `groups[i].value` SHALL equal the COUNT of rows in that bucket

#### Scenario: Time-bucketed groupBy by DAY over the REST endpoint
- **GIVEN** a register `openconnector`, schema `calllogs` with property `created: date-time`
- **WHEN** the client issues `GET /api/objects/aggregations/openconnector/calllogs/timeseries?field=created&interval=DAY&from=2026-05-01T00:00:00Z&to=2026-05-22T00:00:00Z`
- **THEN** the response status SHALL be `200`
- **AND** every `groups[i].key` SHALL be an ISO-8601-UTC string at midnight UTC on a day in the range `[from, to)`
- **AND** every `groups[i].value` SHALL equal the COUNT of `calllogs` whose `created` falls in that day-bucket
- **AND** buckets with zero rows SHALL be omitted from the response (the client fills empties at render time)

#### Scenario: Time-bucketed groupBy by HOUR
- **GIVEN** the same schema as above
- **WHEN** the client issues the same request with `interval=HOUR&from=2026-05-21T00:00:00Z&to=2026-05-22T00:00:00Z`
- **THEN** every `groups[i].key` SHALL be an ISO-8601-UTC string at an hour boundary in the range
- **AND** the maximum number of buckets returned SHALL be `24` (one per hour)

### Requirement: The REST timeseries endpoint SHALL validate inputs and reject malformed requests

The endpoint `GET /api/objects/aggregations/{register}/{schema}/timeseries` SHALL enforce the following input rules and respond with `400 Bad Request` (and a JSON body `{ error: <message> }`) on violation:

- `field` MUST be a non-empty string and MUST be a declared property of `{schema}` OR one of the allow-listed magic-table metadata columns (`_created`, `_updated`, `_deleted_at`). Any other column name is rejected.
- If `interval` is provided, it MUST be one of `MINUTE|HOUR|DAY|WEEK|MONTH|QUARTER|YEAR` (case-insensitive). Other values are rejected.
- If `interval` is provided, both `from` AND `to` MUST be provided AND MUST be parseable as ISO-8601 datetimes. Missing or unparseable bounds are rejected.
- If `interval` requires sub-day granularity (`MINUTE`, `HOUR`), the field's schema property `format` MUST be `date-time` (a `date`-only field cannot be bucketed by hour). Sub-day interval against a `date`-format field is rejected.
- If `metric` is provided, it MUST be one of `count|sum|avg|min|max` (case-insensitive). Other values are rejected.
- If `metric` is not `count`, `metricField` MUST be provided and MUST be a declared property of `{schema}`. Missing `metricField` for a non-count metric is rejected.
- If both `interval` (time-bucket) AND a categorical `groupBy` clause are present (defensive — the endpoint does not currently accept categorical+temporal in one call), the request is rejected.

#### Scenario: Unknown field is rejected
- **WHEN** the client requests `?field=__totally_made_up`
- **THEN** the response status SHALL be `400`
- **AND** the response body SHALL include `{ error: "<message containing the field name>" }`

#### Scenario: Sub-day interval against date-only field is rejected
- **GIVEN** schema `meetings` with property `meetingDate: { type: string, format: date }`
- **WHEN** the client requests `?field=meetingDate&interval=HOUR&from=...&to=...`
- **THEN** the response status SHALL be `400`
- **AND** the response body SHALL state that sub-day intervals require a `date-time` field

#### Scenario: Missing `from`/`to` when `interval` is set
- **WHEN** the client requests `?field=created&interval=DAY` with no `from` or `to`
- **THEN** the response status SHALL be `400`

#### Scenario: Non-count metric without metricField
- **WHEN** the client requests `?field=status&metric=sum`
- **THEN** the response status SHALL be `400`

### Requirement: The system SHALL apply row-level RBAC and multi-tenant filtering before bucketing

Every ad-hoc aggregation request SHALL execute against a row set that has already been filtered by:

1. The active organisation's multi-tenant predicate (`"_organisation" = ?` against the authenticated user's active organisation; null active org SHALL yield no rows).
2. The schema's read-permission check via `PermissionHandler::canRead($schema)` for the authenticated user. Denial SHALL produce HTTP `403 Forbidden` (REST) or a GraphQL field-error (GraphQL).
3. Soft-delete filtering (`_deleted IS NULL OR _deleted = 'null'::jsonb`) identical to the named-aggregation path in `AggregationRunner::tryNativeAggregation()`.
4. Property-level RBAC from `PropertyRbacHandler`: if `field` or `metricField` is denied for the authenticated user, the request SHALL be rejected with `403`.

#### Scenario: Aggregation respects multi-tenant filter
- **GIVEN** two tenants `tenant-a` and `tenant-b`, each owning 10 rows in schema `calllogs`
- **AND** the authenticated user's active organisation is `tenant-a`
- **WHEN** the client requests `?field=created&interval=DAY&from=...&to=...`
- **THEN** the sum of `groups[*].value` SHALL be `10` (only tenant-a rows)
- **AND** no tenant-b row SHALL be counted

#### Scenario: Forbidden schema returns 403
- **GIVEN** the authenticated user lacks read permission on schema `applications`
- **WHEN** the client requests `?field=status`
- **THEN** the response status SHALL be `403`

### Requirement: The system SHALL use a native time-bucket expression on every supported database and fall back to PHP only on unrecognised engines

When the underlying database is PostgreSQL, the aggregator SHALL execute a single `SELECT date_trunc($gap, "$field")::text AS bucket, <metric_sql> AS agg FROM <table> WHERE <rbac_predicate> AND "$field" >= ? AND "$field" < ? GROUP BY bucket ORDER BY bucket` query and SHALL annotate the response with `backend: "postgres"`.

When the underlying database is MySQL, the aggregator SHALL execute a single `SELECT DATE_FORMAT(\`$field\`, '<format>') AS bucket, <metric_sql> AS agg FROM <table> WHERE <rbac_predicate> AND \`$field\` >= ? AND \`$field\` < ? GROUP BY bucket ORDER BY bucket` query, where `<format>` is the MySQL `DATE_FORMAT` string corresponding to the `gap` vocabulary (`'%Y-%m-%dT%H:%i:00Z'` for minute, `'%Y-%m-%dT%H:00:00Z'` for hour, `'%Y-%m-%dT00:00:00Z'` for day, `'%Y-%m-01T00:00:00Z'` for month, `'%Y-01-01T00:00:00Z'` for year). For `week` the query SHALL emit `DATE_FORMAT(field - INTERVAL ((DAYOFWEEK(field) + 5) %% 7) DAY, '%Y-%m-%dT00:00:00Z')` to align with ISO-Monday week-start semantics. For `quarter` the query SHALL emit `CONCAT(YEAR(field), '-', LPAD(((QUARTER(field) - 1) * 3 + 1), 2, '0'), '-01T00:00:00Z')`. The response SHALL be annotated with `backend: "mysql"`.

When the underlying database is SQLite, the aggregator SHALL execute the equivalent query using `strftime('<format>', "$field")`, where `<format>` is the SQLite strftime string for the `gap` (`'%Y-%m-%dT%H:%M:00Z'` for minute, `'%Y-%m-%dT%H:00:00Z'` for hour, `'%Y-%m-%dT00:00:00Z'` for day, `'%Y-%m-01T00:00:00Z'` for month, `'%Y-01-01T00:00:00Z'` for year). For `week` the query SHALL use the strftime `weekday 0` modifier to back-shift to the previous Monday. For `quarter` the query SHALL emit a `CASE WHEN strftime('%m', field) IN ('01','02','03') THEN ... END` expression. The response SHALL be annotated with `backend: "sqlite"`.

When the database is none of the above (an engine OpenRegister does not natively target), the aggregator SHALL pull the RBAC-filtered row set, bucket in PHP using a `date_trunc` polyfill keyed on the `gap` vocabulary, and SHALL annotate the response with `backend: "php-fallback"`. The PHP path SHALL produce the same response shape and the same bucket-key format as the native paths.

#### Scenario: Postgres path annotates `backend: "postgres"`
- **GIVEN** the database is PostgreSQL
- **WHEN** the client requests a DAY-bucketed series
- **THEN** the response SHALL include `backend: "postgres"`

#### Scenario: MySQL path annotates `backend: "mysql"` and emits DATE_FORMAT
- **GIVEN** the database is MySQL
- **WHEN** the client requests a DAY-bucketed series
- **THEN** the response SHALL include `backend: "mysql"`
- **AND** the emitted SQL SHALL contain `DATE_FORMAT` against the bucketed field with the matching format string
- **AND** the bucket keys SHALL be ISO-8601-UTC strings matching what Postgres would have returned

#### Scenario: SQLite path annotates `backend: "sqlite"` and emits strftime
- **GIVEN** the database is SQLite
- **WHEN** the client requests a DAY-bucketed series
- **THEN** the response SHALL include `backend: "sqlite"`
- **AND** the emitted SQL SHALL contain `strftime` against the bucketed field with the matching format string
- **AND** the bucket keys SHALL be ISO-8601-UTC strings matching what Postgres would have returned

#### Scenario: Unrecognised engine falls back to PHP
- **GIVEN** the database is neither PostgreSQL nor MySQL nor SQLite (i.e. a hypothetical custom backend)
- **WHEN** the client requests a DAY-bucketed series
- **THEN** the response SHALL include `backend: "php-fallback"`
- **AND** the bucket keys SHALL be ISO-8601-UTC strings matching what the native paths would have returned

### Requirement: The aggregation primitive SHALL share a single execution helper across REST and GraphQL

Both the REST `AggregationController::timeseries()` action and the GraphQL `GraphQLResolver::resolveList()` resolver SHALL build a single `OCA\OpenRegister\Service\Aggregation\AggregationQuery` value object and dispatch through one `AggregationRunner::runAdhoc(Register $r, Schema $s, AggregationQuery $q): array` method.

`runAdhoc()` SHALL perform the RBAC + multi-tenant gating defined in this spec, then call the existing `tryNativeAggregation()` (extended with the `date_trunc` path) on Postgres, or the PHP fallback otherwise. The return value SHALL match `{ groups: [{ key, value }], backend: string }` and the controller / resolver SHALL not perform additional row-level filtering on top of what `runAdhoc()` returns.

#### Scenario: REST and GraphQL share execution path
- **WHEN** REST `GET /api/objects/aggregations/openconnector/calllogs/timeseries?field=created&interval=DAY&from=...&to=...` is called
- **AND** the equivalent GraphQL `query { calllogs(groupBy: { field: "created", interval: DAY, from: "...", to: "..." }) { groups { key value } } }` is called
- **THEN** both responses SHALL contain identical `groups` arrays (same keys, same values, same ordering)
- **AND** both responses SHALL be served by the same `AggregationRunner::runAdhoc()` invocation pattern

### Requirement: The system SHALL document the ad-hoc aggregation primitive and its index recommendations

OpenRegister SHALL ship documentation at `docs/annotations/x-openregister-aggregations.md` (or a sibling page linked from there) that:

- Documents the REST endpoint, all query parameters, validation rules, and response shape.
- Documents the GraphQL `groupBy: GroupByInput` argument, the `GroupBucket` type, and the `TimeInterval` / `AggregationMetric` enums.
- Recommends a btree index on any field commonly used as a bucketing target (e.g. `created`, `updated`) for installations with large schemas.
- Clearly delineates the ad-hoc primitive from the named-annotation surface (`x-openregister-aggregations`), documenting when an app author SHOULD prefer one over the other.

#### Scenario: Documentation describes both surfaces
- **WHEN** a developer reads the aggregation docs
- **THEN** they SHALL find a section "Runtime ad-hoc primitive" covering REST + GraphQL
- **AND** they SHALL find a section "Named declarative aggregations" linking to `x-openregister-aggregations`
- **AND** they SHALL find guidance on when to use which

### Requirement: The system SHALL cache ad-hoc aggregation results for up to 60 seconds with read-through semantics

`AggregationRunner::runAdhoc()` SHALL check `AggregationCache` for a cached result envelope before executing any native or PHP-fallback computation. On hit, the runner SHALL return the cached envelope with `cached: true` and SHALL NOT execute the underlying aggregation. On miss, the runner SHALL execute the aggregation, store the resulting envelope in the cache with `cached: false`, and return that envelope to the caller.

The cache key SHALL be derived from `(registerSlug, schemaSlug, sha1(json_encode(AggregationQuery::toArray())), resolvedFilter, rbacScopeHash)` where:

- `AggregationQuery::toArray()` returns a stable associative array of the query's `metric`, `field`, `filter`, `groupBy`, and `dateBucket` slots, with filter sub-arrays sorted by key so that two structurally-equivalent filters hash identically.
- `rbacScopeHash` is the SHA-1 of the active user's UID (or `'anonymous'` when no user is logged in), inherited from the existing named-aggregation cache key derivation.

The cache entry SHALL share the storage backend with the named-aggregation cache (the `openregister_aggregations` distributed cache). The name slot SHALL be prefixed with the literal `adhoc:` so ad-hoc entries are visually distinct from named entries when the cache is dumped for debugging.

The TTL SHALL be the existing 60-second class constant `AggregationCache::TTL`. The cache SHALL gracefully degrade to no-cache when the distributed cache backend is unavailable (cache writes silently no-op; cache reads return `null` for miss).

#### Scenario: Cache miss populates, second call hits
- **GIVEN** the cache is empty for the (register, schema, query) triple
- **WHEN** the client issues an ad-hoc aggregation request
- **THEN** the response SHALL include `cached: false` and the underlying SQL SHALL be executed
- **AND** the result envelope SHALL be stored in the cache
- **WHEN** the client issues an identical request within 60 seconds
- **THEN** the response SHALL include `cached: true`
- **AND** the underlying SQL SHALL NOT be executed
- **AND** the `groups` (or `value`) field SHALL equal the first response's groups (or value)

#### Scenario: Differing query is a different cache entry
- **GIVEN** the cache contains a result for `{field: 'created', interval: 'DAY', from: '2026-05-01', to: '2026-05-22'}`
- **WHEN** the client issues a different request `{field: 'created', interval: 'HOUR', from: '2026-05-21', to: '2026-05-22'}`
- **THEN** the response SHALL include `cached: false` and the underlying SQL SHALL be executed

#### Scenario: Filter-key reordering hits the same cache entry
- **GIVEN** the cache contains a result for filter `{status: 'open', priority: 'high'}`
- **WHEN** the client issues an otherwise-identical request with filter `{priority: 'high', status: 'open'}`
- **THEN** the response SHALL include `cached: true`

### Requirement: The ad-hoc aggregation cache SHALL be invalidated on every object lifecycle event for the affected schema

The existing `AggregationCacheInvalidationListener` SHALL evict ad-hoc cache entries alongside named-aggregation entries on every `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, and `ObjectTransitionedEvent` for the affected `(register, schema)` pair. The listener SHALL achieve this by reusing the existing `AggregationCache::evictForSchema()` method, which evicts every entry in the `openregister_aggregations` distributed cache regardless of whether the entry was written by the named-aggregation path or the ad-hoc path.

The eviction granularity SHALL match the existing coarse-but-bounded approach: a full cache flush on the `openregister_aggregations` namespace, with the 60-second TTL ceiling bounding staleness in the event of a missed evict.

#### Scenario: Inserting a row evicts the ad-hoc cache for the schema
- **GIVEN** the cache contains an ad-hoc result for `(softwarecatalogus, applications, query=X)`
- **WHEN** a new `applications` row is inserted (firing `ObjectCreatedEvent`)
- **THEN** the next ad-hoc request for `(softwarecatalogus, applications, query=X)` SHALL include `cached: false`
- **AND** the underlying SQL SHALL be re-executed against the now-updated row set

#### Scenario: Transitioning an object evicts the ad-hoc cache
- **GIVEN** the cache contains an ad-hoc result for `(softwarecatalogus, applications, query=Y)`
- **WHEN** an `applications` row transitions state (firing `ObjectTransitionedEvent`)
- **THEN** the next ad-hoc request for `(softwarecatalogus, applications, query=Y)` SHALL include `cached: false`

### Requirement: The ad-hoc aggregation primitive SHALL be reachable in-process by a consuming PHP service

A consuming app running inside the same Nextcloud process SHALL be able to
obtain a server-side aggregation (count / sum / avg / min / max, optionally
grouped) over a register-schema collection WITHOUT looping back over HTTP and
WITHOUT hydrating all rows and aggregating in PHP. The entry point SHALL be
`AggregationRunner::runAdhocByRef(string $registerRef, string $schemaRef,
AggregationQuery $query)`.

`AggregationQuery::create(string $metric, ?string $field, array $filter, ?array
$groupBy, ?array $dateBucket)` SHALL build the request: `metric` one of
`count|sum|avg|min|max`; `field` required for non-`count` metrics; `filter` a
per-field map of scalar-equality or operator sub-maps; `groupBy` an optional
`{field: <name>}`.

`runAdhocByRef()` SHALL apply the SAME authorization and tenancy gate as
`findAll()`: the schema's `list` RBAC verdict for the active user and the
active-organisation `_organisation` predicate, BOTH evaluated before any SQL
executes. It SHALL throw `NotAuthorizedException` when the caller lacks `list`
permission and `RuntimeException` when the register/schema ref cannot be
resolved.

The return SHALL be an array. Ungrouped: `{ value, backend, cached }` where
`value` is an `int` for `count`, a `float` for `sum`/`avg`/`min`/`max`, and
`null` for an empty matching set. Grouped: `{ groups: [{ key, value }], backend,
cached }` with one bucket per distinct group value. `backend` SHALL report the
engine that served the request (`postgres` / `mysql` / `sqlite` / `php-fallback`).

#### Scenario: Consumer obtains a grouped SUM via runAdhocByRef

- **GIVEN** a register+schema with objects carrying a numeric `priority` and a
  categorical `taskStatus`, and an authenticated caller with `list` permission
- **WHEN** the consumer builds `AggregationQuery::create(metric: 'sum', field:
  'priority', groupBy: ['field' => 'taskStatus'])` and calls
  `runAdhocByRef(registerRef, schemaRef, query)`
- **THEN** the result SHALL contain `groups`, one bucket per distinct
  `taskStatus`, each `value` equal to the SUM of `priority` over the rows in that
  bucket that the caller may read
- @e2e exclude In-process PHP-service aggregation contract; verified by PHPUnit integration test + live container verification, no browser flow.

#### Scenario: Consumer obtains an ungrouped AVG / MIN / MAX via runAdhocByRef

- **GIVEN** the same register+schema and caller
- **WHEN** the consumer calls `runAdhocByRef` with `metric: 'avg'` / `'min'` /
  `'max'` and `field: 'priority'`
- **THEN** the result SHALL contain a scalar `value` equal to the arithmetic
  mean / minimum / maximum of `priority` over the RBAC-filtered rows, or `null`
  when no rows match
- @e2e exclude In-process PHP-service aggregation contract; verified by PHPUnit + live container verification, no browser flow.

#### Scenario: runAdhocByRef enforces the list-permission gate

- **GIVEN** a caller who lacks `list` permission on the schema
- **WHEN** the consumer calls `runAdhocByRef` for any metric on that schema
- **THEN** the call SHALL throw `NotAuthorizedException` and no aggregation SHALL
  be computed
- @e2e exclude Backend authorization gate; verified by the existing AggregationRunner RBAC unit coverage, no browser flow.

### Requirement: The aggregation and findAll filter vocabularies SHALL include a notIn exclusion operator

The system SHALL support a `notIn` operator in both the aggregation ad-hoc
filter map and the `ObjectService::findAll(array $config)` / `count(array
$config)` config-filter map. `{field: {notIn: [a, b, …]}}` SHALL match rows
whose `field` is NOT one of the listed values, translating to a `NOT IN (...)`
SQL predicate.
An empty `notIn` list SHALL exclude nothing (retain all rows) and SHALL NOT emit
a malformed `NOT IN ()` clause. The findAll config-filter map SHALL likewise
support `ne` (`{field: {ne: x}}` → `field <> x`).

The aggregation native-SQL path SHALL bind one parameter per `notIn` operand
and SHALL fall back to the PHP path only on query shapes it cannot translate;
the PHP-fallback path SHALL apply the same `notIn` semantics so both paths agree.

#### Scenario: notIn excludes the listed values in an aggregation

- **GIVEN** a register+schema with objects whose `taskStatus` ranges over
  `open` / `in-progress` / `completed`
- **WHEN** an aggregation runs with `filter: {taskStatus: {notIn: ['completed',
  'open']}}` and `metric: 'count'`
- **THEN** the result SHALL count only the rows whose `taskStatus` is neither
  `completed` nor `open`
- @e2e exclude Backend filter-operator translation; verified by PHPUnit integration + native-SQL-emission unit + live verification, no browser flow.

#### Scenario: Empty notIn list retains all rows

- **GIVEN** the same register+schema
- **WHEN** an aggregation runs with `filter: {taskStatus: {notIn: []}}` and
  `metric: 'count'`
- **THEN** the result SHALL count ALL readable rows (the empty exclusion list
  removes nothing) and the SQL SHALL NOT contain a `NOT IN ()` clause
- @e2e exclude Backend filter-operator edge case; verified by PHPUnit integration + native-SQL-emission unit, no browser flow.

#### Scenario: notIn and ne are reachable on an ordinary findAll query

- **GIVEN** a schema collection queried via `ObjectService::findAll(['filters' =>
  ['status' => ['notIn' => ['archived', 'deleted']]]])`
- **WHEN** the query executes
- **THEN** the emitted SQL SHALL contain a `NOT IN (...)` predicate on the
  `status` column, and a sibling `{status: {ne: 'completed'}}` filter SHALL emit
  a `<> 'completed'` inequality
- @e2e exclude Backend filter-operator translation in the magic-table search handler; verified by PHPUnit unit test, no browser flow.

### Requirement: The register catalog scan runs once per request

The register catalog scan SHALL be memoized per request — resolving the set of
magic-table register/schema pairs (the `information_schema` scan) runs once. It
SHALL NOT be re-executed once per register in a list response or once per
`findBySchema()` call.

#### Scenario: Registers list scans the catalog once

- **WHEN** `GET /api/registers` with `@self.stats` returns a page of registers
- **THEN** the magic-table catalog is scanned once for the request
- **AND** register statistics are produced by a grouped query set, not one
  `getStatistics()` call per register

### Requirement: A single request-scoped entity cache

Per-request memoization of schemas, registers, and objects SHALL use one shared
cache. There SHALL NOT be multiple uncoordinated per-request caches for the same
entities.

#### Scenario: Repeated entity resolution hits one cache

- **WHEN** a request resolves the same schema/register multiple times across
  mappers and the render path
- **THEN** the resolution is served from a single request-scoped cache

### Requirement: Full-table name warmup is background-only

The name-cache warmup that loads the full object table SHALL run only in its
background job, never be auto-triggered synchronously within a user-facing
request.

#### Scenario: A request never triggers the full warmup

- **WHEN** a controller path needs object names and the in-memory name cache is empty
- **THEN** it does not synchronously load the entire objects table

### Requirement: The system SHALL honour a multi-field (cross-tab) groupBy

The aggregation engine (`AggregationQuery` + `AggregationRunner`, native-SQL and PHP-fallback paths) SHALL accept a `groupBy` expressed as an ordered list of two or more scalar fields and SHALL produce one grouped row per distinct field **tuple** (a cross-tab), applying the same metric (count/sum/avg/min/max), filters, RBAC gate, and multi-tenant predicate as a single-field groupBy.

A multi-field `groupBy` MAY be supplied in either the explicit shape `{ fields: ["a", "b"] }` or the plain ordered-list shape `["a", "b"]`. The single-field shape `{ field: "a" }` SHALL remain accepted and behaviourally unchanged.

The engine SHALL NOT silently ignore extra group fields. A malformed `groupBy` — an empty list, an empty-string member, a non-string member, or duplicate fields — SHALL be rejected with an `InvalidArgumentException` (HTTP 400 at the controller boundary), never partially honoured.

Result shape:
- A **single-field** group row SHALL keep the backward-compatible shape `{ key: <fieldValue>, value: <metric> }`.
- A **multi-field** group row SHALL expose a composite key map `{ keys: { "a": <valueA>, "b": <valueB> }, value: <metric> }`, with every declared group field present, so a consumer can pivot the result into a cross-tab.

On PostgreSQL, MySQL and SQLite the categorical groupBy SHALL execute natively as `GROUP BY <col_a>, <col_b>, ...` over the sanitised magic-table columns; the PHP fallback SHALL bucket on the same field tuple and SHALL produce grouped rows that agree with the native path.

#### Scenario: Two-field native groupBy returns one row per distinct tuple
- **GIVEN** a register/schema magic table with rows carrying `vendorId` ∈ {V1, V2, V3} and `dueDateBucket` ∈ {current, 30days}
- **AND** a filter `state IN [issued, partially-paid, overdue, disputed]` that excludes the `paid` rows
- **WHEN** an aggregation runs with metric `sum(amount)` and `groupBy: { fields: ["vendorId", "dueDateBucket"] }`
- **THEN** the native path SHALL emit SQL containing `GROUP BY "vendor_id", "due_date_bucket"`
- **AND** the result SHALL contain exactly the tuples `(V1,current)=150`, `(V1,30days)=200`, `(V2,current)=300`, `(V2,30days)=100`
- **AND** each group row SHALL carry `keys: { vendorId: ..., dueDateBucket: ... }` and a numeric `value`

#### Scenario: Single-field groupBy stays backward compatible
- **GIVEN** the same dataset
- **WHEN** an aggregation runs with `groupBy: { field: "vendorId" }`
- **THEN** each group row SHALL carry a scalar `key` and a `value`
- **AND** no group row SHALL carry a composite `keys` map

#### Scenario: Native and PHP-fallback paths agree
- **GIVEN** the same dataset and `groupBy: { fields: ["vendorId", "dueDateBucket"] }` with metric `sum(amount)`
- **WHEN** the aggregation is computed once via the native-SQL path (`backend: "sqlite"`) and once via the PHP fallback (`backend: "php-fallback"`)
- **THEN** the two grouped result sets SHALL contain the same tuples with the same values

#### Scenario: Malformed multi-field groupBy is rejected
- **WHEN** an `AggregationQuery` is built with `groupBy: ["vendorId", ""]` or `groupBy: ["vendorId", "vendorId"]`
- **THEN** construction SHALL throw `InvalidArgumentException`
- **AND** no partial (single-field or ungrouped) aggregation SHALL be produced

@e2e exclude Data-layer aggregation primitive with no OpenRegister UI surface — the multi-field groupBy is exercised against a real in-memory SQLite database via PHPUnit (`AggregationRunnerMultiFieldGroupByTest`) proving the native `GROUP BY a, b` output and native ⇄ PHP-fallback agreement, plus value-object shape/validation units (`AggregationQueryTest`). Covered by PHPUnit.
