# Aggregation API

OpenRegister exposes **two** aggregation surfaces. Pick the right one for your use case:

| Surface | Surface owner | When to use |
|---|---|---|
| **Named declarative** — `x-openregister-aggregations` schema annotation | App author / schema author | KPI tiles, business-rule counts, anything the app owns and ships with its register. Cached for 60s. |
| **Runtime ad-hoc** — REST `/aggregate/timeseries` + GraphQL `groupBy` | Client (per-request) | Dashboard charts, ad-hoc bucketing, "let the user pick a date range". No cache. |

This page documents the **ad-hoc primitive** (added by the `add-time-bucket-aggregation` change). For the named surface see `x-openregister-aggregations` documentation.

## When to use each

The named declarative surface is the right home for behaviours the **schema author** controls — KPIs, counts, business-rule rollups. Those are part of the app's contract and live in `lib/Settings/{app}_register.json`.

The ad-hoc primitive is the right home for behaviours the **client** controls — the user picks a date range, the dashboard widget picks the bucketing interval, the chart picks the metric. None of that belongs in the schema register; it's request-scoped.

A rule of thumb: if you'd hard-code the metric and field in the dashboard's source code, use the named surface. If the user gets to pick them at runtime, use the ad-hoc surface.

## REST surface

### Endpoint

```
GET /api/objects/aggregations/{register}/{schema}/timeseries
```

### Query parameters

| Param | Required | Notes |
|---|---|---|
| `field` | yes | The field to group / bucket on. MUST be a declared property of `{schema}` OR one of `_created`, `_updated`, `_deleted_at`. |
| `interval` | no | One of `MINUTE`, `HOUR`, `DAY`, `WEEK`, `MONTH`, `QUARTER`, `YEAR`. When set, the field is time-bucketed via Postgres `date_trunc()`. When absent, the field is grouped categorically. |
| `from` | required when `interval` set | ISO-8601 lower bound, inclusive. |
| `to` | required when `interval` set | ISO-8601 upper bound, exclusive. |
| `metric` | no | One of `count`, `sum`, `avg`, `min`, `max`. Default `count`. |
| `metricField` | required when `metric != count` | Field to aggregate over. MUST be a declared schema property. |
| `filter[...]` | no | Reuses the existing object-collection filter vocabulary (`filter[status]=active`, `filter[duration][gte]=10`). |

### Sub-day intervals require date-time fields

Bucketing by `MINUTE` or `HOUR` requires the field's JSON-Schema `format` to be `date-time` (not `date`). A `date`-only field can only be bucketed by `DAY`, `WEEK`, `MONTH`, `QUARTER`, or `YEAR`. The endpoint returns `400 Bad Request` if the constraint is violated.

### Response shape

```json
{
  "groups": [
    { "key": "2026-05-21T00:00:00Z", "value": 42 },
    { "key": "2026-05-22T00:00:00Z", "value": 17 }
  ],
  "backend": "postgres",
  "cached": false
}
```

- `key`: bucket label. For `interval`-bucketed queries this is an ISO-8601-UTC string at the start of the bucket. For categorical groupBy it's the value of the groupBy field.
- `value`: the aggregated metric (always a number; an integer for `count`, a float for other metrics).
- `backend`: `"postgres"` (native `date_trunc` path) or `"php-fallback"` (non-Postgres environments).
- `cached`: always `false` on the ad-hoc path. Caching is tracked in [issue #1610](https://github.com/ConductionNL/openregister/issues/1610).

### Empty buckets

Buckets with zero rows are **omitted** from the response — `GROUP BY` does not emit empty groups. The client fills empties at render time. See [issue #1607](https://github.com/ConductionNL/openregister/issues/1607) for cumulative / windowed series.

### Status codes

| Code | When |
|---|---|
| `200` | Happy path. |
| `400` | Validation failure (unknown field, bad interval, missing bounds, etc.). |
| `403` | Caller lacks `list` permission on the schema. |
| `404` | Register or schema not found. |

### Example

```bash
curl -s 'http://localhost:8080/index.php/apps/openregister/api/objects/aggregations/openconnector/calllogs/timeseries?field=created&interval=DAY&from=2026-05-01T00:00:00Z&to=2026-05-22T00:00:00Z' \
  -u admin:admin \
  -H 'OCS-APIRequest: true' \
  | jq .
```

## GraphQL surface

Every auto-generated list query accepts an optional `groupBy: GroupByInput` argument. When supplied, the connection result includes a non-null `groups: [GroupBucket!]` field.

### Types (auto-generated)

```graphql
input GroupByInput {
  field: String!
  interval: TimeInterval
  from: String        # required when interval is set
  to: String          # required when interval is set
  metric: AggregationMetric = COUNT
  metricField: String # required when metric != COUNT
}

enum TimeInterval { MINUTE HOUR DAY WEEK MONTH QUARTER YEAR }
enum AggregationMetric { COUNT SUM AVG MIN MAX }
type GroupBucket { key: String! value: Float! }
```

### Example query

```graphql
query CallsPerDay {
  calllogs(
    filter: { status: "error" }
    groupBy: {
      field: "created"
      interval: DAY
      from: "2026-05-01T00:00:00Z"
      to: "2026-05-22T00:00:00Z"
    }
  ) {
    totalCount
    groups {
      key
      value
    }
  }
}
```

`totalCount` is the size of the filtered set; the sum of `groups[*].value` equals `totalCount` when `metric: COUNT`.

When the client does not request `groupBy`, the `groups` field is `null` (not an empty array — `null` means "no aggregation requested").

### Validation errors

Validation problems surface as GraphQL field-errors on the `groups` field. The rest of the connection (edges, pageInfo, totalCount, facets) still resolves normally.

## Performance notes

- **Postgres index**: for any field commonly used as a bucketing target (`created`, `updated`, custom date columns), declare a btree index on the magic-table column. `date_trunc()` against an indexed timestamp column is sub-50ms on tens of millions of rows.
- **Row-level RBAC**: the multi-tenant predicate (`_organisation = ?`) and the schema's `PermissionHandler::canRead()` verdict both apply BEFORE bucketing. Aggregations cannot leak rows the caller could not read row-by-row.
- **Non-Postgres fallback**: SQLite and MySQL fall through to the PHP-side bucketer (`backend: "php-fallback"`). Correct, but slow on tables > 10k rows — the row cap is 10 000 and `truncated: true` is set when exceeded. Native MySQL / SQLite bucketing is tracked in [issue #1609](https://github.com/ConductionNL/openregister/issues/1609).
- **No cache**: ad-hoc queries hit Postgres on every request. Caching is tracked in [issue #1610](https://github.com/ConductionNL/openregister/issues/1610).

## Non-goals (deferred)

| Topic | Issue |
|---|---|
| Multi-field groupBy (`groupBy: [status, priority]`) | [#1606](https://github.com/ConductionNL/openregister/issues/1606) |
| Running / cumulative series | [#1607](https://github.com/ConductionNL/openregister/issues/1607) |
| Multi-metric in one request (`count` + `sum`) | [#1608](https://github.com/ConductionNL/openregister/issues/1608) |
| Native MySQL / SQLite bucketing | [#1609](https://github.com/ConductionNL/openregister/issues/1609) |
| Caching of ad-hoc queries | [#1610](https://github.com/ConductionNL/openregister/issues/1610) |
