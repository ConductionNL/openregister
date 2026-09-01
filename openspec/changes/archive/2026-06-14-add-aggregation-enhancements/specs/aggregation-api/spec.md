## MODIFIED Requirements

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

## ADDED Requirements

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
