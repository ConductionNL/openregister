# Tasks

## 1. Row probe

- [x] 1.1 `MagicMapper::tablesHoldingRows()`: one `LIMIT 1` probe per table in one statement per 50 tables.
- [x] 1.2 `MagicMapper::pairsWithRows()`: plain-search keys only, known tables only, failure keeps every pair.
- [x] 1.3 `searchAcrossMultipleTablesWithUnion()` and the union branch of `countAcrossMultipleTables()` build arms for the kept pairs; no kept pair answers an empty page with total 0.

## 2. Verification

- [x] 2.1 Unit tests: `MagicMapperUnionRowProbeTest`.
- [x] 2.2 Live on the sandbox reference stack: timings before and after, 20 answers byte-identical, in the PR body.
