# Design — Multi-field (cross-tab) groupBy

## Context

`AggregationQuery` captures an aggregation request; `AggregationRunner` executes it three ways — a named-annotation path (`run()`), an ad-hoc value-object path (`runAdhoc()`), and a cross-schema path (`runCrossSchema()`) — each dispatching to a Postgres/MySQL/SQLite native-SQL fast path (`tryNativeAggregation()`) or a PHP fallback (`bucketInPhp()` / `computeGrouped()`).

Before this change every path gated categorical grouping on a single scalar `groupBy['field']`:
- `AggregationQuery::getGroupByField(): ?string` returned exactly one field.
- Native path: `if ($groupBy !== null && isset($groupBy['field']))` → `GROUP BY "<one col>"`.
- PHP fallback: `if (is_array($groupBy) && isset($groupBy['field']))` → bucket on one field.

A `groupBy` supplied as a **list** (`["a","b"]`) has no `'field'` key, so all three paths skipped grouping and raised no error — the extra dimension vanished silently. This is the orphaned/phantom-capability failure mode: shillinq's `groupBy:["vendorId","dueDateBucket"]` declarations looked healthy but produced no cross-tab.

## Goals / Non-Goals

**Goals**
- Honour a multi-field `groupBy` on the native-SQL path (all three engines) and the PHP fallback, with identical grouped output.
- Keep the single-field shape and its `{key, value}` result byte-for-byte backward compatible.
- Fail loudly (`InvalidArgumentException`) on malformed groupBy — never silent partial behaviour.
- Prove native ⇄ PHP agreement with real database output, not fixtures.

**Non-Goals**
- Time-bucket × categorical combinations (still mutually exclusive, unchanged).
- Translatable-group-key projection for multi-field keys (documented limitation — the composite `keys` map returns raw values; single-field projection is unchanged).
- A GraphQL/REST surface change: the runner + value object are the capability; wiring the multi-field shape through additional controllers is out of scope for this change.

## Seed Data (ADR-001)

This change is `kind: code`. It ships **no** `lib/Settings/*register*.json` seed — the capability is a data-layer primitive, not a config-register chain.

The only config-shaped artifacts are **test fixtures built at runtime, nothing committed as binary**:

- An in-memory SQLite magic table `oc_register_1_schema_ap_tx` created by `AggregationRunnerMultiFieldGroupByTest::seedSqlite()` with columns `_deleted`, `_organisation`, `vendor_id`, `due_date_bucket`, `state`, `amount` (snake_case mirrors `MagicMapper` column sanitisation).
- A 7-row canonical dataset (`AggregationRunnerMultiFieldGroupByTest::dataset()`), keyed by schema property name for the PHP path and inserted into the SQLite columns for the native path:

  | vendorId | dueDateBucket | state    | amount |
  |----------|---------------|----------|--------|
  | V1       | current       | issued   | 100    |
  | V1       | current       | issued   | 50     |
  | V1       | 30days        | overdue  | 200    |
  | V2       | current       | issued   | 300    |
  | V2       | 30days        | disputed | 75     |
  | V2       | 30days        | overdue  | 25     |
  | V3       | current       | paid     | 999    |

  Filter `state IN [issued, partially-paid, overdue, disputed]` excludes the `paid` row (proving filters hold on both paths). Two-field `groupBy: [vendorId, dueDateBucket]`, metric `sum(amount)` → `(V1,current)=150`, `(V1,30days)=200`, `(V2,current)=300`, `(V2,30days)=100`.

The `_organisation` column is seeded with the `__no_active_org__` sentinel so the runner's multi-tenant predicate (`_organisation = ?`, null active org → sentinel) matches the rows under test. Placeholder values only — no real tenant data.

## Decisions

### D1 — Three accepted groupBy shapes, one canonicaliser
`AggregationQuery::normaliseGroupByFields(mixed): array` maps every shape to an ordered candidate list: plain list `['a','b']` → itself; `{fields:['a','b']}` → the list; `{field:'a'}` → `['a']`; anything else → `[]`. **Rationale:** shillinq already declares the plain-list shape; `{fields:...}` is the explicit cross-tab shape from openregister#432's acceptance criteria; `{field:...}` is the legacy shape. One shared static keeps the value object and the runner from drifting. The canonicaliser returns **raw** candidates (not filtered) so the caller validates and rejects — filtering silently here would reintroduce the very silent-ignore bug.

### D2 — Strict validation in `create()`
Every group field must be a non-empty string; the list must be non-empty; fields must be distinct. Violations throw `InvalidArgumentException` with a message containing `groupBy MUST include a non-empty \`field\`` (superset of the legacy message, so the existing `{field:''}` test still passes). **Rationale:** loud failure over silent partial output.

### D3 — Native multi-column `GROUP BY`, composite key projection
`tryNativeAggregation()` emits `SELECT "col_a" AS g0, "col_b" AS g1, <agg> AS agg ... GROUP BY "col_a", "col_b"`, using the platform `$quote` (double-quote for Postgres/SQLite, backtick for MySQL — fixing a latent hard-coded double-quote in the old single-field branch). Each row maps `g<i>` back to the **original property name** so native and PHP keys match. Single field → `{key, value}`; multi → `{keys: {prop: val}, value}`.

### D4 — Relaxed categorical platform gate
The old gate bailed non-Postgres for every non-dateBucket query. It now bails non-Postgres only for the **ungrouped scalar** case (`dateBucket === null && count($groupFields) === 0`). **Rationale:** the aggregate SQL and identifier quoting are already platform-branched and battle-tested in the dateBucket path; enabling categorical grouping on MySQL/SQLite satisfies openregister#432's "Postgres/MySQL/SQLite emit GROUP BY a, b" acceptance and makes real in-memory-SQLite testing of the native path possible. The ungrouped scalar path stays Postgres-only (still depends on the Postgres numeric cast).

### D5 — PHP fallback buckets on the tuple
`computeGrouped(rows, metric, field, array $groupFields)` builds a per-row tuple `{prop => value}`, keys the bucket on `json_encode($tuple)`, and emits `{key, value}` (single) or `{keys, value}` (multi). First-seen tuple order; the metric pipeline (`computeMetric`) is unchanged. A shared `AggregationRunner::resolveGroupFields()` normalises + de-dups + drops invalid members defensively for the named-annotation path (whose spec is author-trusted, not `create()`-validated).

### D6 — Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Multi-field groupBy execution | **imperative** (runner extension) | ADR-031: the declarative path applies to **named** behaviours; this is a query-execution primitive, extended in the existing `AggregationRunner`. No new service class. |
| Named aggregations (`x-openregister-aggregations`) | **declarative** (unchanged) | The annotation surface is untouched; it simply stops silently dropping the second dimension because the runner now honours the list shape. |
| Field allow-list | **declarative** (`Schema::getProperties()`) | Unchanged — the caller (REST/GraphQL) still validates fields against the schema before building the query. |

No `lib/Settings/*register*.json` patch. No new notification/`x-openregister-*` dialect (ADR-031 notification dialect untouched).

## Risks / Trade-offs

- **Behaviour change on MySQL/SQLite:** single-field categorical grouping that previously returned `backend: "php-fallback"` now returns `backend: "sqlite"/"mysql"`. The grouped values are identical (verified by the agreement test); only the `backend` annotation changes. Acceptable — the native path is the intended steady state.
- **Translatable multi-field keys:** the composite `keys` map returns raw stored values; per-language projection (single-field only) no-ops for multi-field. Documented non-goal; revisit if a translatable cross-tab dimension is requested.
- **Cache key:** the resolved `groupBy` (any shape) is part of the cache key unchanged — two shapes that normalise to the same fields hash differently, which only costs an extra miss, never a wrong hit.

## Migration Plan

Additive and non-breaking; no data migration. Ship OR change, then a separate shillinq PR to un-inert `agedPayablesDetail` / `agedPayablesSummary`.

## Open Questions

None blocking. Follow-up: surface multi-field cross-tab through the GraphQL `groupBy` argument + REST timeseries endpoint when a client needs it (out of scope here).
