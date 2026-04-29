# Aggregations Annotation

## Problem
Apps reinvent the same recipe over the existing `findObjects` query path: hand-written PHP loops that count/sum/average objects matching a filter (~750 lines of analytics services across decidesk + pipelinq). Both are SQL-shaped. The existing `zoeken-filteren` spec already covers filter compilation, RBAC, indexing, and the three backends (Postgres / Solr / Elasticsearch).

## Proposed Solution
Add the `x-openregister-aggregations` schema annotation and extend `findObjects` (i.e., the implemented `zoeken-filteren` spec) with a single new query path:

- Schema declares a map of name → `{metric: count|sum|avg|min|max|count_distinct, field?, filter?, groupBy?}` at the schema root.
- `GET /api/objects/aggregations/{name}` is the sugar endpoint; the existing search endpoint also accepts inline `_aggregate=name1,name2`.
- Reuses the existing filter compiler, RBAC scope, cache layer, and three-backend dispatch unchanged. Adds a small aggregation pass on top of the same compiled filter.

Computed metrics (e.g., `avgDaysToClose`) are intentionally **out of scope** for this change. The expression DSL for derived fields ships in the parallel `calculations-annotation` change. When both annotations land, an aggregation can target a stored calculation field — but the two annotations are independently shippable.
