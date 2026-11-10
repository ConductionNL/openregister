---
kind: fix
depends_on: []
---

## Why

Several code paths load unbounded amounts of data into memory in a single pass —
a stability hazard on any register that grows past small demo scale:

1. **Schema property exploration.** `SchemaService::exploreSchemaProperties()`
   calls `objectEntityMapper->findBySchema($schemaId)` (`lib/Service/SchemaService.php:128`)
   with no limit; `MagicMapper::findBySchema()` (`lib/Db/MagicMapper.php:7351`)
   defaults `$limit=null` and fetches **every** object for the schema, then
   analyses all of them in PHP. The analysis is statistical — a bounded sample
   suffices — but it currently scans the whole table.

2. **Export.** `ExportService::fetchObjectsForExport()`
   (`lib/Service/ExportService.php:487-493`) calls `searchObjects()` with
   `'_limit' => 999999` ("get all objects") and builds an in-memory `Spreadsheet`
   from the full result set. No streaming, no pagination.

3. **Import.** `ImportService` (`:880-909` JSON, `:1067-1097` CSV) advertises
   "Chunked Processing" with `DEFAULT_CHUNK_SIZE`/`MAX_CONCURRENT` constants
   (`:96-117`) but appends every row to `$allObjects[]` and calls `saveObjects()`
   once — the chunk constants are dead config; large imports buffer the whole
   payload.

4. **Text extraction.** `TextExtractionService` calls `$file->getContent()`
   unconditionally (`:971,1390,1497,1768`) before handing the full string to
   PDF/Word/Spreadsheet parsers, with no `getSize()` guard — a large upload can
   OOM the worker. `MAX_CHUNKS_PER_FILE` bounds output, not input.

## What Changes

- Schema exploration: sample a bounded number of objects (configurable, e.g.
  N most-recent) instead of the full table; document that the profile is a
  sample.
- Export: page through results in bounded batches and stream rows into the
  writer rather than buffering the whole result set (or cap + warn per ADR:
  "no silent caps").
- Import: wire the existing chunk constants into the row loop — flush
  `saveObjects()` every N rows — or remove the misleading docblock/constants.
- Text extraction: check `$file->getSize()` against a configurable ceiling
  before `getContent()`; skip/reject oversized files with a logged, non-fatal
  outcome.

## Impact

- Affected: `lib/Service/SchemaService.php`, `lib/Service/ExportService.php`,
  `lib/Service/ImportService.php`, `lib/Service/TextExtractionService.php`.
- Behavioural change: exploration returns a sampled profile (note it in the
  response); very large exports stream; oversized files are skipped with a
  logged reason. Where a cap is introduced, it MUST be logged, not silent.
- Risk: sampling changes exploration output slightly — surface "sampled N of M".
