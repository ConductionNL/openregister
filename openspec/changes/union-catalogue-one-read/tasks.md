# Tasks

## 1. One catalogue read

- [x] 1.1 `MagicMapper::primeTableCatalogue()`: one `information_schema.columns`
      read over the tables of a union search fills the column caches and the
      table-exists memo; tables not returned keep the one-table path.
- [x] 1.2 `searchAcrossMultipleTablesWithUnion()` primes before building arms.

## 2. One count

- [x] 2.1 `searchObjectsGloballyBySearch()` counts its total with
      `countAcrossMultipleTables()`; the per-table `countInTables()` is removed.
- [x] 2.2 A single-statement UNION search carries its total in a
      `COUNT(*) OVER ()` window (`runUnionBatch(withTotal: true)`); the global
      search uses it and counts separately only for an empty page or many batches.

- [x] 2.3 The same for the unified-search provider's multi-schema search;
      queries that filter on properties (or carry `_where`) are counted per
      table first, as before (`filtersOnProperties()`).

## 3. One read for the registers and schemas

- [x] 3.1 `RegisterMapper::preloadUnfiltered()`, `SchemaMapper::preloadUnfiltered()`
      (and on their interfaces), `RequestIdentityMap::idsNotHeldUnfiltered()`.
- [x] 3.2 `MagicMapper::globallyReadableTables()` preloads both before its loop.

## 4. Ordered union on PostgreSQL

- [x] 4.1 `runUnionBatch()` reads its arms as one derived table (golden fixture:
      that wrapper only).

## 5. Verification

- [x] 5.1 Unit tests: `MagicMapperCatalogueBatchTest` (one query answers both
      questions, missing table not recorded, no re-read, single table and
      SQLite untouched, failed read falls back);
      `MagicMapperSearchObjectsPaginatedTest` (one count across the tables; the
      statement's own total, no count), `MagicMapperUnionBatchGoldenTest` (window
      SQL, total taken from the rows, empty page and plain statement carry none);
      `RegisterMapperFindGoldenTest`, `SchemaMapperFindGoldenTest` (one query, finds
      answered from it, nothing for one or cached ids), `RequestIdentityMapTest`.
- [x] 5.2 Live on the sandbox reference stack: queries and timings before and
      after, answers byte-identical, in the PR body.
