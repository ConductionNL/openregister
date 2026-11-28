# Tasks

## 1. One catalogue read

- [x] 1.1 `MagicMapper::primeTableCatalogue()`: one `information_schema.columns`
      read over the tables of a union search fills the column caches and the
      table-exists memo; tables not returned keep the one-table path.
- [x] 1.2 `searchAcrossMultipleTablesWithUnion()` primes before building arms.

## 2. Verification

- [x] 2.1 Unit tests: `MagicMapperCatalogueBatchTest` (one query answers both
      questions, missing table not recorded, no re-read, single table and
      SQLite untouched, failed read falls back).
- [x] 2.2 Live on the sandbox reference stack: queries and timings before and
      after, answers byte-identical, in the PR body.
