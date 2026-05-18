# Spec: nested-aggregations

**Status:** proposed
**Scope:** openregister
**Tier:** or-core-extensions
**Depends on:** aggregations-backend-native (sibling — flows through the same runner), faceting-configuration (sibling — strict delineation), row-field-level-security (RLS applies before aggregation)

## Motivation (context for the new sibling spec)

Specter's intelligence pipeline surfaced multi-level aggregation as
a top-10 gap (661 mentions: role-based EX metrics 593, rollup-of-rollup
34, nested-aggregation 34). Cross-checking against OR's existing
spec library:

- `faceting-configuration` covers facets — "given a property, how
  many objects fall into each bucket". This is one-level group-by
  with cardinality, designed for sidebar UI.
- `aggregations-backend-native` (the in-flight change) covers
  single-pass execution of `count` / `sum` / `avg` / `min` / `max`
  metrics with an optional single `groupBy`, dispatched to Postgres /
  Solr / Elasticsearch / PHP fallback.

What is NOT covered:
- **Multi-level group-by** — group by `status`, then by `assignee`,
  then by `team`. Each level produces nested buckets with their own
  metrics.
- **Rollup-of-rollup** — compute a metric per group, then aggregate
  those per-group metrics (e.g. "average team completion rate across
  departments" = average of per-team averages, NOT average across
  all rows).
- **HAVING-style post-aggregation filters** — show only groups whose
  aggregate metric exceeds a threshold (e.g. "teams with > 100 open
  tasks").
- **Role-scoped aggregations** — an aggregation result MUST honour
  the calling user's RLS so different roles see different totals
  for the same query (per `row-field-level-security`).

This spec is the sibling — it explicitly delineates from faceting
and from the single-pass aggregations runner, and adds the
declarative shape for nested group-by + HAVING + rollup-of-rollup.
Execution flows through the same `AggregationRunner::run()` entry
point per design.md D6.

## ADDED Requirements

### REQ-NAG-001: This spec SHALL explicitly delineate from faceting-configuration and aggregations-backend-native

This is a documentation-style requirement that the spec body must
satisfy at the top. The three responsibilities MUST be defined as:

- **Faceting** (per `faceting-configuration`): "given a property,
  how many objects fall into each bucket". One level. Optimised for
  UI sidebar rendering. Pagination-aware.
- **Aggregation** (per `aggregations-backend-native`): "compute a
  numeric metric (`count`/`sum`/`avg`/`min`/`max`) over a filtered
  set, optionally grouped by one field". Single backend pass.
- **Nested aggregation** (this spec): "compute a metric over groups
  of groups, optionally with HAVING filters on the aggregate
  values, with role-aware RLS filtering applied before grouping".

Any new aggregation feature MUST fit into exactly one of the three
categories. If a feature spans categories, the spec author MUST
split it across the existing capabilities rather than blur the
delineation.

#### Scenario: Spec reader can identify each capability's responsibility

- **GIVEN** a feature request "show count of tasks per team per
  status, only for teams with >50 tasks"
- **WHEN** the spec reader consults this requirement
- **THEN** the feature MUST be classified as nested-aggregation
  (multi-level group-by + HAVING)
- **AND** the spec reader MUST NOT route it to
  `faceting-configuration` (cardinality > 1 level) or to
  `aggregations-backend-native` (HAVING is not supported)

#### Scenario: Single-level group-by stays in aggregations-backend-native

- **GIVEN** a feature request "count tasks per team"
- **WHEN** the spec reader consults this requirement
- **THEN** the feature MUST stay in `aggregations-backend-native`
  (single groupBy, no HAVING, no nested levels)
- **AND** MUST NOT migrate to this spec

### REQ-NAG-002: AggregationRunner SHALL accept a nested groupBy array in declarative aggregation definitions

The existing `x-openregister-aggregations` schema annotation (per
ADR-031 — declarative-first) MUST be extended to accept a nested
groupBy of up to N levels (N defaults to 3 per REQ-NAG-007). The
shape:

```jsonc
{
  "x-openregister-aggregations": {
    "tasksByTeamAndStatus": {
      "metric": "count",
      "groupBy": ["team", "status"],   // nested: outer = team, inner = status
      "filters": { "...": "..." }
    }
  }
}
```

`AggregationRunner::run()` MUST parse the array and produce a
nested result structure where each outer-group bucket contains its
own metric + a `buckets` array of inner-group buckets. The result
shape MUST be deterministic and JSON-Schema-validatable.

#### Scenario: Two-level nested groupBy produces nested buckets

- **GIVEN** an aggregation declared as above on schema `Task`
- **AND** tasks: 3 in team-A/open, 2 in team-A/closed, 1 in team-B/open
- **WHEN** the runner executes the aggregation
- **THEN** the result MUST be:
  ```jsonc
  {
    "buckets": [
      { "key": "team-A", "count": 5, "buckets": [
          { "key": "open", "count": 3 },
          { "key": "closed", "count": 2 }
        ] },
      { "key": "team-B", "count": 1, "buckets": [
          { "key": "open", "count": 1 }
        ] }
    ]
  }
  ```

#### Scenario: Three-level nested groupBy works within depth limit

- **GIVEN** an aggregation with `groupBy: ["department", "team", "status"]`
- **WHEN** the runner executes
- **THEN** the result MUST nest three levels deep
- **AND** each leaf bucket MUST carry the metric value

#### Scenario: Backwards compat — single-string groupBy still works

- **GIVEN** a pre-existing aggregation declared with the old shape
  `"groupBy": "status"` (a string, not array)
- **WHEN** the runner executes
- **THEN** the result MUST match the existing single-level shape per
  `aggregations-backend-native` (one-level buckets, no nesting)
- **AND** no migration of existing aggregation declarations MUST be
  required

### REQ-NAG-003: AggregationRunner SHALL accept a HAVING clause that filters groups by aggregate value

The `x-openregister-aggregations` annotation MUST accept a `having`
clause that filters OUTER-group results (and optionally nested-group
results) by the aggregate metric value. The operator vocabulary MUST
reuse OR's existing query-operator vocabulary (`$gte`, `$lte`, `$eq`,
`$gt`, `$lt`, `$ne`, `$in`) per design.md D5 / Open Question 3 —
NOT a SQL `HAVING` string. Shape:

```jsonc
{
  "x-openregister-aggregations": {
    "busyTeams": {
      "metric": "count",
      "groupBy": ["team"],
      "having": { "count": { "$gte": 50 } }
    }
  }
}
```

Buckets whose aggregate fails the HAVING predicate MUST be excluded
from the result. The HAVING clause MUST NOT affect the metric
computation — it only filters the output.

#### Scenario: HAVING excludes groups below threshold

- **GIVEN** an aggregation declared with `having: { count: { $gte: 50 } }`
- **AND** teams: A=70 tasks, B=30 tasks, C=120 tasks
- **WHEN** the runner executes
- **THEN** the result MUST contain only buckets for teams A and C
- **AND** team B MUST NOT appear

#### Scenario: HAVING with named metric reference

- **GIVEN** an aggregation with `metric: "sum", field: "amount"`
  and `having: { sum: { $gt: 10000 } }`
- **WHEN** the runner executes
- **THEN** only buckets whose `sum(amount) > 10000` MUST be returned

#### Scenario: HAVING applies at the outer level by default; per-level HAVING uses path

- **GIVEN** an aggregation with `groupBy: ["team", "status"]` and
  `having: { count: { $gte: 50 } }`
- **WHEN** the runner executes
- **THEN** the HAVING clause MUST filter OUTER (team) buckets only
- **AND** the inner (status) buckets within each surviving outer
  bucket MUST be unfiltered
- **WHEN** the declaration instead carries
  `having: { "buckets.count": { $gte: 10 } }`
- **THEN** the HAVING clause MUST filter INNER buckets only (each
  status bucket with count<10 dropped); outer buckets are unfiltered

### REQ-NAG-004: AggregationRunner SHALL support rollup-of-rollup composition via a `rollup` reference

A nested aggregation MAY declare a `rollup` field naming another
aggregation. The named aggregation MUST execute first, then the
declaring aggregation aggregates its results. Shape:

```jsonc
{
  "x-openregister-aggregations": {
    "avgPerTeam": {
      "metric": "avg",
      "field": "completionRate",
      "groupBy": ["team"]
    },
    "avgOfTeamAverages": {
      "metric": "avg",
      "field": "avg",                    // the metric output from rollup
      "rollup": "avgPerTeam"
    }
  }
}
```

The runner MUST execute `avgPerTeam` first, take its bucket array,
and compute the outer metric over THAT array (not the original
underlying rows). This is the canonical pattern for "average of
averages" / "median of medians" / "max of sums" — composing one
aggregation over another. The runner MUST detect circular
`rollup` chains and reject the schema at import time.

#### Scenario: Rollup composes correctly — average of per-team averages

- **GIVEN** the two aggregations declared above
- **AND** tasks: team-A avg(completionRate)=80, team-B avg=60, team-C avg=70
- **WHEN** `avgOfTeamAverages` is executed
- **THEN** the result MUST be `(80 + 60 + 70) / 3 = 70`
- **AND** the result MUST NOT equal the simple `avg(completionRate)`
  over all rows (which would weight by row count and would differ)

#### Scenario: Circular rollup is rejected at schema import

- **GIVEN** aggregation `a` declares `rollup: "b"` and `b` declares
  `rollup: "a"`
- **WHEN** the schema is imported via `ConfigurationService::importFromApp()`
- **THEN** the import MUST fail with the error
  `Circular rollup chain detected: a -> b -> a`

### REQ-NAG-005: Nested aggregation execution SHALL flow through the existing AggregationRunner with backend dispatch

Per design.md D6, this spec MUST NOT introduce a parallel runner.
The existing `AggregationRunner::run()` entry point is the single
runner. Backend dispatch MUST follow the same order as
`aggregations-backend-native` (Solr → Elasticsearch → Postgres →
PHP fallback), with per-backend nested-aggregation implementations:

- **Postgres**: nested `GROUP BY a, b, c` + window functions where
  needed + `HAVING` clauses.
- **Solr**: pivot facets (`facet.pivot=a,b,c`) for groupBy +
  `facet.pivot.mincount` for count-based HAVING; stats facets for
  sum/avg.
- **Elasticsearch**: nested `terms` aggregations + `bucket_selector`
  pipeline for HAVING.
- **PHP fallback**: in-memory nested grouping; MUST log a WARNING
  when invoked on >100K rows per the perf budget.

Each backend's `aggregate()` method (introduced by
`aggregations-backend-native`) MUST be extended to accept nested
groupBy arrays + HAVING. The response MUST always carry the
`backend` attribution field per `aggregations-backend-native`.

#### Scenario: Postgres backend handles nested groupBy via single SQL

- **GIVEN** an aggregation `count, groupBy: ["team", "status"]`
  against a Postgres-backed magic table
- **WHEN** the runner executes
- **THEN** ONE SQL statement MUST be issued of shape
  `SELECT team, status, COUNT(*) FROM <table> WHERE <filters> GROUP BY team, status`
- **AND** the response MUST carry `backend: "postgres"`
- **AND** the row results MUST be reshaped into the nested-bucket
  envelope per REQ-NAG-002

#### Scenario: Backend dispatch falls back through the chain

- **GIVEN** a Solr-indexed schema with an aggregation that includes
  `having: {avg: {$gte: 50}}` (Solr's facet API has limited HAVING
  support for averages)
- **WHEN** the runner executes
- **THEN** the runner MAY attempt Solr first; if Solr rejects the
  shape, it MUST fall through to Elasticsearch / Postgres / PHP
  in order
- **AND** the response MUST attribute the backend that actually
  produced the result (per `aggregations-backend-native`)

#### Scenario: PHP fallback emits a perf warning on large datasets

- **GIVEN** a 200K-row magic table with no Solr/ES backend
- **AND** Postgres is unavailable (e.g. SQLite test env)
- **WHEN** a 2-level nested aggregation runs through the PHP fallback
- **THEN** the response MUST succeed
- **AND** the response MUST carry `backend: "php-fallback"`
- **AND** a structured WARNING MUST be logged:
  `Nested aggregation 'X' ran via PHP fallback on 200000 rows; consider Postgres/Solr/ES backend for production`

### REQ-NAG-006: RLS MUST be applied BEFORE aggregation, not after

Per `row-field-level-security`, every read query through
`MagicRbacHandler::applyRbacFilters()` filters rows the user is not
authorised to see. For nested aggregations, the RLS filtering MUST
happen at the query level (a SQL WHERE clause, a Solr `fq`, an ES
`bool.filter`) BEFORE the GROUP BY runs. This guarantees the
returned counts / sums / averages are role-scoped: the same
aggregation query issued by user A and user B may return different
results, each reflecting only the rows the issuing user can see.
Computing aggregates over the full dataset and then filtering
buckets is INCORRECT — leaks the unfiltered count via inference.

#### Scenario: Two users see different aggregate counts on the same query

- **GIVEN** schema `Task` has RLS rule
  `{ "read": [{ "group": "behandelaars", "match": { "department": "$activeDepartment" } }] }`
- **AND** 100 tasks total: 60 in dept-A, 40 in dept-B
- **AND** user `alice` (dept-A) and user `bob` (dept-B) both in group
  `behandelaars`
- **WHEN** both users execute the aggregation `count, groupBy: ["status"]`
- **THEN** `alice`'s result MUST aggregate over only 60 rows
- **AND** `bob`'s result MUST aggregate over only 40 rows
- **AND** neither MUST see counts derived from the other's rows

#### Scenario: RLS-filter SQL precedes GROUP BY in the emitted query

- **GIVEN** the same setup as above
- **WHEN** `alice` executes the aggregation against a Postgres-backed
  schema
- **THEN** the emitted SQL MUST be of shape
  `SELECT status, COUNT(*) FROM <table> WHERE department = 'dept-A' GROUP BY status`
- **AND** the WHERE clause MUST come from `MagicRbacHandler::applyRbacFilters()`,
  inserted by the existing single integration point

### REQ-NAG-007: Nested aggregation depth SHALL default to 3 and SHALL be configurable per-query

The default maximum depth for nested groupBy is 3 levels.
Aggregations declared with >3 levels MUST be rejected at schema
import. Operators MAY raise the limit per administration via
`IAppConfig` setting `openregister.aggregations.maxNestedDepth`
(integer, range 1..10). A query that exceeds the configured limit
MUST be rejected at runtime with HTTP 400 and a structured error
naming the configured limit.

#### Scenario: Default 3-level limit is enforced at schema import

- **GIVEN** an aggregation declared with `groupBy: ["a", "b", "c", "d"]`
- **WHEN** the schema is imported
- **THEN** the import MUST fail with
  `Nested aggregation depth 4 exceeds configured limit 3`

#### Scenario: Operator raises the limit to 5

- **GIVEN** `IAppConfig` set
  `openregister.aggregations.maxNestedDepth = 5`
- **WHEN** an aggregation with `groupBy: ["a", "b", "c", "d", "e"]`
  is imported
- **THEN** the import MUST succeed
- **WHEN** another aggregation with 6 levels is imported
- **THEN** the import MUST fail with depth-exceeds-5 error

### REQ-NAG-008: Nested aggregation results SHALL be cacheable per the existing AggregationCache

Per `aggregations-backend-native` Requirement "AggregationRunner
MUST cache results for 60s", the same `AggregationCache` MUST cache
nested-aggregation results. The cache key MUST include the
groupBy array, the having clause, the rollup reference, and the
user's RLS scope digest (per REQ-NAG-006 — different users get
different results, so different cache slots). Invalidation MUST
fire on the same `ObjectCreatedEvent` / `ObjectUpdatedEvent` /
`ObjectDeletedEvent` / `ObjectTransitionedEvent` for the affected
`(register, schema)`.

#### Scenario: Cache hit returns within 5 ms

- **GIVEN** an executed nested aggregation populated the cache for
  user `alice`'s scope
- **WHEN** `alice` re-runs the same aggregation within 60 seconds
- **THEN** the response MUST carry `X-OR-Cache: hit`
- **AND** the total request time MUST be under 5 ms

#### Scenario: Different RLS scopes get different cache slots

- **GIVEN** `alice` (dept-A) and `bob` (dept-B) have run the same
  aggregation
- **WHEN** the cache is inspected
- **THEN** TWO cache slots MUST exist, one per RLS-scope digest
- **AND** `alice`'s cache hit MUST NOT serve `bob`'s result

#### Scenario: Object mutation invalidates the cache for the affected schema

- **GIVEN** the cache holds nested-aggregation results for
  schema `Task`
- **WHEN** a `Task` is created, updated, deleted, or transitioned
- **THEN** the cache MUST evict ALL entries for `(register, Task)`
- **AND** subsequent calls MUST recompute (no stale data)
