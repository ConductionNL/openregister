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
- `backend`: which engine served the query — `"postgres"` (native `date_trunc`), `"mysql"` (native `DATE_FORMAT`), `"sqlite"` (native `strftime`), or `"php-fallback"` (unrecognised engine).
- `cached`: `true` on a read-through cache hit, `false` on miss or the first request after invalidation. See [Cache](#cache) below.

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

## In-process (PHP service) surface

A consuming app (e.g. pipelinq) that already runs inside the same Nextcloud
process should NOT loop over HTTP back to the REST endpoint, and it should
NOT fetch-all-and-sum-in-PHP. Instead it calls the runner directly. This is
the in-process ad-hoc primitive — the same RBAC + multi-tenancy gate and the
same native-or-fallback dispatch the REST/GraphQL surfaces use.

### Call pattern

```php
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;

// 1. DI-resolve the runner (constructor inject it in your service).
public function __construct(
    private readonly AggregationRunner $aggregationRunner,
) {}

// 2. Build a query value object and run it against a register+schema ref.
//    runAdhocByRef() applies the schema's `list` RBAC verdict for the
//    active user and the active-organisation tenant filter, exactly like
//    findAll() does, before any SQL executes.
$query = AggregationQuery::create(
    metric: 'sum',                       // count | sum | avg | min | max
    field: 'amount',                     // required for sum/avg/min/max; null for count
    filter: [                            // same operator vocabulary as findAll's config filters
        'status' => ['notIn' => ['cancelled', 'draft']],
        'createdAt' => ['gte' => '$startOfMonth'],   // placeholders are resolved
    ],
    groupBy: ['field' => 'costCenter'],  // optional — omit for a single scalar
);

$result = $this->aggregationRunner->runAdhocByRef(
    registerRef: 'finance',              // register slug / uuid / id
    schemaRef: 'invoice',                // schema slug / uuid / id
    query: $query,
);
```

### Method signature

```php
AggregationRunner::runAdhocByRef(
    string $registerRef,
    string $schemaRef,
    AggregationQuery $query
): array
```

Throws `NotAuthorizedException` (→ HTTP 403) when the caller lacks `list`
permission on the schema, and `RuntimeException` (→ 404) when the register
or schema ref can't be resolved.

### Return shape

Ungrouped (no `groupBy`):

```php
['value' => 1234.5, 'backend' => 'postgres', 'cached' => false]
```

Grouped (`groupBy` set): one bucket per distinct group value.

```php
[
  'groups' => [
    ['key' => 'cc-100', 'value' => 800.0],
    ['key' => 'cc-200', 'value' => 434.5],
  ],
  'backend' => 'postgres',
  'cached'  => false,
]
```

`value` is an `int` for `count`, a `float` for `sum`/`avg`/`min`/`max`, and
`null` when the metric has no matching rows (empty set). `backend` reports
the engine that served the request (see the Backend matrix below).

### Filter operator vocabulary

Per field, the filter map accepts a bare scalar (equality) or an operator
sub-map. Supported operators:

| Operator | Operand | Meaning |
|---|---|---|
| (scalar) | scalar | `field = value` |
| `in` | array | `field IN (...)` |
| `notIn` | array | `field NOT IN (...)` — empty array excludes nothing (retains all rows) |
| `ne` | scalar | `field <> value` |
| `gt` / `gte` / `lt` / `lte` | scalar | range comparisons |

The same `in` / `notIn` / `ne` / `gt` / `gte` / `lt` / `lte` operator
vocabulary is also reachable through the standard `ObjectService::findAll(array $config)`
/ `count(array $config)` config path (`config['filters']`), so a consuming
app can exclude a set of values (`'status' => ['notIn' => [...]]`) on an
ordinary object query without aggregating.

## Backend matrix

The runner picks the matching native bucketing primitive for the active database engine and falls back to PHP only on engines OpenRegister doesn't natively target.

| Database | Bucketing path | `backend` field |
|---|---|---|
| PostgreSQL | `date_trunc($gap, "$field")::text` | `postgres` |
| MySQL / MariaDB | `DATE_FORMAT("$field", '<format>')` (ISO-Monday week-start; `CONCAT(YEAR, ..., '-01T00:00:00Z')` for quarter) | `mysql` |
| SQLite | `strftime('<format>', "$field")` (ISO-Monday via `weekday 0` + `-6 days`; `CASE` on `strftime('%m')` for quarter) | `sqlite` |
| Other / unknown | RBAC-filtered hydrate + PHP `date_trunc` polyfill (`gmdate`) | `php-fallback` |

All four paths emit identical wire shape: ISO-8601-UTC bucket keys (`Y-m-d\TH:i:s\Z`), the same `groups[i].value` coercion (int for `count`, float otherwise), and the same RBAC + multi-tenancy gate. The `backend` field lets a caller observe which engine served the request without changing how the response is consumed.

## Cache

Ad-hoc results are served via a 60-second distributed cache:

- **Storage**: same `openregister_aggregations` distributed cache the named-aggregation path uses (`AggregationCache`).
- **Read-through**: on entry to `runAdhoc()`, the runner derives the key from `(registerSlug, schemaSlug, sha1(json_encode($query->toArray())), filter, rbacScopeHash)`, prefixed with `adhoc:`. A hit returns the stored envelope with `cached: true`; a miss falls through to the native-or-fallback dispatch and the resulting envelope is written back.
- **Key stability**: `AggregationQuery::toArray()` ksort-sorts the filter map (recursively, into operator sub-arrays), so `filter[a, b]` and `filter[b, a]` produce identical cache keys.
- **RBAC scoping**: the key includes `sha1(uid)` (or `sha1('anonymous')`) so two callers with different list-permission verdicts on the same `(metric, field, filter)` tuple never read each other's results.
- **Invalidation**: `AggregationCacheInvalidationListener` evicts the entire `(register, schema)` cache on every `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, and `ObjectTransitionedEvent`. The eviction is coarse (`ICache::clear()` flushes the whole `openregister_aggregations` namespace) but bounded by the 60-second TTL ceiling on missed evicts.
- **Stampede tolerance**: no distributed lock — the 60-second TTL bounds duplicate-miss compute. Revisit if a high-traffic dashboard surfaces stampede symptoms in production.

## Performance notes

- **Database index**: for any field commonly used as a bucketing target (`created`, `updated`, custom date columns), declare a btree index on the magic-table column. The native bucketing expression operates against an indexed column on every supported engine, so the cost stays in the database where it belongs.
- **Row-level RBAC**: the multi-tenant predicate (`_organisation = ?`) and the schema's `PermissionHandler::canRead()` verdict both apply BEFORE bucketing. Aggregations cannot leak rows the caller could not read row-by-row.
- **PHP fallback ceiling**: the PHP-fallback path on unrecognised engines caps the hydrated row set at 10 000 and sets `truncated: true` when exceeded. Native paths (Postgres / MySQL / SQLite) operate over the full set in SQL.

## Non-goals (deferred)

| Topic | Issue |
|---|---|
| Multi-field groupBy (`groupBy: [status, priority]`) | [#1606](https://github.com/ConductionNL/openregister/issues/1606) |
| Running / cumulative series | [#1607](https://github.com/ConductionNL/openregister/issues/1607) |
| Multi-metric in one request (`count` + `sum`) | [#1608](https://github.com/ConductionNL/openregister/issues/1608) |
