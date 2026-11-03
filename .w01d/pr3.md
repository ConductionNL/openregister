Splits `DbalObjectSourceProvider::aggregate` (73 LOC, McCabe 19, 8 params) into one step per result shape. The public signature and the behaviour stay the same.

## What changed
- `aggregate()` goes from 73/19/8 to 37/9/8 (LOC/McCabe/params).
- New private steps, each at most 18 LOC, McCabe 5 and 2 params: `runAggregation()` (routing and the 502 wrap), `connectForAggregation()` (503, or null for a missing driver), `aggregateGrouped()` and `aggregateScalar()`.
- `aggregateDateBucket()` goes from 7 to 2 params and `applyAggregationFilter()` from 4 to 2: both take the bound query.
- New value objects: `DbalAggregation` (metric, field, filter, columns, plus the value-field allowlist check), `DbalAggregationQuery` (connection, table, aggregate expression, aggregation) and `DbalAggregationShape` (groupBy, dateBucket).
- The method-level CyclomaticComplexity, NPathComplexity and ExcessiveMethodLength suppressions are removed; phpmd passes without them.

## Verification
- Golden test `DbalObjectSourceProviderAggregateGoldenTest`: 50 scenarios against a real SQLite table. It covers every metric, the metric and field allowlists, source resolution, the 503 and missing-driver branches, scalar and operator filters (unknown and integer keys), groupBy (unknown, empty and integer fields), a query-backed source and query errors. A SQLite connection that reports the MySQL platform, with `DATE_FORMAT` registered, runs the real date-bucket query (day, month, year, bounds, filters, the week fallback). Values are compared with `JSON_PRESERVE_ZERO_FRACTION`, so an int and a float differ. Recorded on the old code and re-run in plain mode.
- Capped mutants: old code 24 killed, new code 32 killed. Equivalent survivors: a null or empty field cast to `''` (no column has that name, old and new), loose group-column matching (column names are not numeric strings, old) and casting an integer filter key to a string (new). A no-op control mutant survives on both.
- Targeted pcov over the 31 test files that reach the provider or the aggregation runner: 285 tests, 0 risky, exit 0.
- `coverage-guard --deletion-neutral` against development: 55.05% to 82.75%, OK.
- phpcs, phpmd, phpstan and psalm on the touched files exit 0. The 138 ObjectSource tests pass.
- Gate 16 (diff): 0. Gate 119: 0 = development.
- The full suite is left to CI.

## SIG numbers (gate 122 reader, lib/Service/Object* + ObjectService.php, against development)
- size >15/30/60: 78.0/49.2/16.5 to 77.8/49.0/16.2
- McCabe >5/10/25: 54.9/22.3/4.9 to 54.7/22.0/4.9
- params >=3/5/7: 46.9/18.9/8.8 to 46.6/18.7/8.6

Not covered by the golden: the PostgreSQL `::timestamptz` casts, which need a Postgres platform.

This PR was prepared with AI assistance (Claude Code) and reviewed by the lane before opening.
