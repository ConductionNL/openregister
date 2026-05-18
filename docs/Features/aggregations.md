# x-openregister-aggregations

Declares named server-side aggregations on a schema. The OpenRegister runtime executes
these via the most capable available backend and caches results for 60 seconds.

## Schema definition

```json
{
  "x-openregister-aggregations": [
    {
      "name": "totalCases",
      "metric": "count"
    },
    {
      "name": "openCases",
      "metric": "count",
      "filters": { "status": "open" }
    },
    {
      "name": "avgCaseValue",
      "metric": "avg",
      "field": "value"
    },
    {
      "name": "casesByStatus",
      "metric": "count",
      "groupBy": { "field": "status" }
    }
  ]
}
```

## Fields

| Field     | Required | Description |
|-----------|----------|-------------|
| `name`    | yes      | Unique identifier within the schema |
| `metric`  | yes      | `count` \| `sum` \| `avg` \| `min` \| `max` |
| `field`   | no*      | Source field for non-count metrics |
| `filters` | no       | Map of field → value or operator object |
| `groupBy` | no       | `{ "field": "<fieldName>" }` |

\* Required when metric is `sum`, `avg`, `min`, or `max`.

## REST endpoint

```
GET /apps/openregister/api/objects/aggregations/{register}/{schema}/{name}
```

**Response:**

```json
{
  "value": 42,
  "groups": null,
  "backend": "postgres",
  "cached": false
}
```

When `groupBy` is set, `groups` is an array of `{ "group": "...", "value": ... }` objects.

**Headers:**

- `X-OR-Cache: hit` — result came from the 60-second distributed cache
- `X-OR-Cache: miss` — result was freshly computed

## Filter operators

```json
{ "status": "open" }                        // equality
{ "status": { "in": ["open", "draft"] } }  // IN list
{ "status": { "ne": "deleted" } }          // not-equal
{ "amount": { "gte": 100 } }               // range (gte/gt/lte/lt)
```

## Backend dispatch order

1. **Solr** — if a Solr backend is available
2. **Elasticsearch** — if an ES backend is available
3. **PostgreSQL native SQL** — direct query on the magic table
4. **PHP fallback** — iterates all objects in memory (last resort)

Results are identical across all backends. The `backend` field in the response
identifies which path was taken.
