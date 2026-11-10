## 1. Schema exploration sampling

- [ ] 1.1 In `SchemaService::exploreSchemaProperties()` (`lib/Service/SchemaService.php:128`), pass a bounded `$limit` (configurable) to `findBySchema()`; analyse a sample, not the full table.
- [ ] 1.2 Return a "sampled N of M objects" indicator in the exploration result so callers know it is statistical.

## 2. Export streaming

- [ ] 2.1 Replace the `'_limit' => 999999` fetch in `ExportService::fetchObjectsForExport()` (`:487-493`) with a paged loop; stream rows into the writer.
- [ ] 2.2 If a hard cap is retained, `log()` the number dropped — no silent truncation.

## 3. Import chunking

- [ ] 3.1 Wire `DEFAULT_CHUNK_SIZE` into the JSON (`:880-909`) and CSV (`:1067-1097`) row loops — flush `saveObjects()` every N rows instead of buffering all rows.
- [ ] 3.2 If chunking is not implemented, remove the misleading "Chunked Processing" docblock and unused constants.

## 4. Text-extraction size guard

- [ ] 4.1 In `TextExtractionService`, check `$file->getSize()` against a configurable ceiling before each `getContent()` (`:971,1390,1497,1768`); skip oversized files with a logged, non-fatal outcome and a clear status.

## 5. Verification

- [ ] 5.1 Test: exploration on a large schema fetches only the sample size.
- [ ] 5.2 Test: export of a large set does not buffer the whole result (assert batching); any cap is logged.
- [ ] 5.3 Test: import of many rows flushes in chunks (assert multiple saveObjects calls).
- [ ] 5.4 Test: an oversized file is skipped with a logged reason, not an OOM/fatal.
- [ ] 5.5 `composer check:strict` passes.

## Acceptance criteria

- No path loads a full object table into memory in one pass.
- Export streams/pages; import chunks (or the false chunking claim is removed).
- Oversized files are rejected before content load, with a logged reason.
- Any introduced cap is logged, never silent.
