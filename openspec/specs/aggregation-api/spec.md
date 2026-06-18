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

