## 1. Value object — AggregationQuery

- [x] 1.1 Added `AggregationQuery::normaliseGroupByFields(mixed $groupBy): array` — shared static canonicaliser for the three groupBy shapes (`{field}`, `{fields:[...]}`, plain list). Returns raw ordered candidates (caller validates).
- [x] 1.2 Extended `create()` validation to accept all three shapes and **reject** empty members, empty lists, and duplicate fields with `InvalidArgumentException` (message is a superset of the legacy `groupBy MUST include a non-empty \`field\`` so the existing `{field:''}` test still passes). Added `@SuppressWarnings(PHPMD.NPathComplexity)`.
- [x] 1.3 Added `getGroupByFields(): array` (ordered field list) and `isMultiFieldGroupBy(): bool`; kept `getGroupByField(): ?string` returning the FIRST field for backward compatibility.

## 2. Runner — native SQL path

- [x] 2.1 Added `AggregationRunner::resolveGroupFields(mixed): array` — normalise + de-dup + drop invalid members, shared across `run()`, `runAdhoc`/`bucketInPhp`, `runCrossSchema`, and `tryNativeAggregation`.
- [x] 2.2 Rewrote the native categorical branch in `tryNativeAggregation()` to emit `SELECT "col_0" AS g0, ... , <agg> AS agg ... GROUP BY "col_0", ...` over the sanitised columns, using the platform `$quote` (fixes a latent hard-coded double-quote). Projects `g<i>` back to the original property names.
- [x] 2.3 Native result shape: single-field → `{key, value}` (unchanged); multi-field → `{keys: {prop: val}, value}`.
- [x] 2.4 Relaxed the categorical platform gate so grouped queries run natively on Postgres, MySQL and SQLite; only the ungrouped scalar path stays Postgres-only.

## 3. Runner — PHP fallback

- [x] 3.1 Generalised `computeGrouped(rows, metric, field, array $groupFields)` to bucket on the field tuple (first-seen order), emitting `{key, value}` (single) or `{keys, value}` (multi).
- [x] 3.2 Routed all three `computeGrouped` call sites (`run()`, `bucketInPhp()`, `runCrossSchema()`) through `resolveGroupFields()`.

## 4. Tests

- [x] 4.1 `AggregationRunnerMultiFieldGroupByTest` — native two-field `GROUP BY` executed against **real in-memory SQLite**, asserting the emitted `GROUP BY "vendor_id", "due_date_bucket"` SQL and the exact per-tuple sums/counts.
- [x] 4.2 Single-field native groupBy keeps the backward-compatible `{key, value}` shape (no `keys` map).
- [x] 4.3 Native ⇄ PHP-fallback agreement test — same 7-row dataset both paths, order-independent tuple comparison, identical grouped sums.
- [x] 4.4 `AggregationQueryTest` — new cases for `{fields:[...]}`, plain-list, single-not-multi, empty-member rejection, duplicate-field rejection.

## 5. Verification

- [x] 5.1 Aggregation service suite green (121 tests, was 112 + 9 new); full Unit suite delta = +9 passing, zero new failures/errors vs the clean `origin/development` baseline.
- [x] 5.2 Static analysis on the changed lib files clean: PHPCS, PHPStan, Psalm, PHPMD.

## 6. Follow-up (out of scope, separate PR)

- [ ] 6.1 shillinq — un-inert `agedPayablesDetail` / `agedPayablesSummary` now that multi-field `groupBy` is honoured (tracked against openregister#432).
