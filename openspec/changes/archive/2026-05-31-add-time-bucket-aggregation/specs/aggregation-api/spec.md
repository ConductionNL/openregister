## ADDED Requirements

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

### Requirement: The system SHALL use Postgres `date_trunc` for native bucketing and fall back to PHP on other databases

When the underlying database is PostgreSQL, the aggregator SHALL execute a single `SELECT date_trunc($gap, "$field")::text AS bucket, <metric_sql> AS agg FROM <table> WHERE <rbac_predicate> AND "$field" >= ? AND "$field" < ? GROUP BY bucket ORDER BY bucket` query and SHALL annotate the response with `backend: "postgres"`.

When the database is not PostgreSQL (e.g. SQLite test fixtures, MySQL dev environments), the aggregator SHALL pull the RBAC-filtered row set, bucket in PHP using a `date_trunc` polyfill keyed on the `gap` vocabulary (`minute|hour|day|week|month|quarter|year`), and SHALL annotate the response with `backend: "php-fallback"`. The PHP path SHALL produce the same response shape and the same bucket-key format.

#### Scenario: Postgres path annotates `backend: "postgres"`
- **GIVEN** the database is PostgreSQL
- **WHEN** the client requests a DAY-bucketed series
- **THEN** the response SHALL include `backend: "postgres"`

#### Scenario: Non-Postgres path annotates `backend: "php-fallback"`
- **GIVEN** the database is SQLite (test fixture)
- **WHEN** the client requests a DAY-bucketed series
- **THEN** the response SHALL include `backend: "php-fallback"`
- **AND** the bucket keys SHALL be ISO-8601-UTC strings matching what Postgres would have returned

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
