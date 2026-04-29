# Aggregations and Calculations

## Problem
Apps reinvent two patterns over the existing `findObjects` query path: (a) hand-written PHP loops that count/sum/average objects matching a filter (~750 lines across decidesk + pipelinq's analytics services); (b) hand-coded view-side templates that combine fields into derived display values (decidesk's `propertyItems()` ternaries, every `*Detail.vue`).

Both are SQL-shaped. The existing `zoeken-filteren` spec already covers filter compilation, RBAC, indexing, and the three backends (Postgres / Solr / Elasticsearch). Aggregations and calculations are extensions on the same query path, not new engines.

## Proposed Solution
Extend `zoeken-filteren` with two declarative annotations:

- `x-openregister-aggregations` — declared count/sum/avg/min/max/count_distinct queries with optional groupBy + time-bucket. Exposed via `GET /api/objects/aggregations/{name}` (sugar) and inline via `_aggregate=name1,name2` on the existing search endpoint.
- `x-openregister-calculations` — declared computed fields (formula expressions over other properties + a small built-in vocabulary). Materialised at save time when `materialise: true`; otherwise computed at response render. Materialised fields are aggregatable.

Reuses the existing filter compiler, RBAC scope, cache layer, and backend dispatch (Postgres/Solr/ES). Adds a small expression evaluator for calculations and an aggregation pass on top of the existing query plan.
