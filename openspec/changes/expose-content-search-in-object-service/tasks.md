# Tasks: expose-content-search-in-object-service

`kind: code` per ADR-032. Single-service wire; the storage layer (chunk store + tsvector GIN + `ChunkMapper::searchByKeyword()`) already ships from the merged `hybrid-document-search` change.

- [x] Freeze the delta spec under `openspec/changes/expose-content-search-in-object-service/specs/zoeken-filteren/spec.md` (ADDED `ZKN-CONTENT-001` / `-002` / `-003`); confirm `openspec validate expose-content-search-in-object-service` is green
  - Spec ref: specs/zoeken-filteren/spec.md (this change)
  - Acceptance: openspec validator reports "is valid"; existing `zoeken-filteren` requirements untouched
  - Done: `openspec validate expose-content-search-in-object-service` reports "is valid" (already true from the merged proposal PR #471; spec delta unchanged).
- [x] Extend `ObjectService::searchObjectsPaginated()` signature with `bool $_content_search = false` (or accept it as a `_content_search` key inside `$query`). Default false — omitted-flag path MUST be byte-identical to today's behaviour
  - Spec ref: ZKN-CONTENT-001
  - Acceptance: PHPUnit test with default (or omitted) flag proves envelope + row order + total are byte-identical to pre-change baseline for a fixed query
  - Done: per design D1, flag lives on `$query['_content_search']` (no signature change). Read in `QueryHandler::searchObjectsPaginatedDatabase()`. Covered by `QueryHandlerContentSearchTest::testContentSearchHandlerIsNeverCalledWhenFlagAbsent`/`...FlagExplicitlyFalse`.
- [x] When `_content_search=true`, call `ChunkMapper::searchByKeyword($query['_search'])` scoped by the same `_register` / `_schemas` the caller passed; map each hit back to its owning object (source_type='file' → owning object via existing file→object join; source_type='object' → source_id is the object id directly)
  - Spec ref: ZKN-CONTENT-002
  - Acceptance: unit test seeds an object with a chunk containing a phrase absent from all metadata columns; assert the object surfaces when `_content_search=true` + `_search=<phrase>` and NOT when `_content_search=false`
  - Done: new `ContentSearchHandler::augmentWithChunkMatches()`. The "existing file→object join" did not exist as a callable method — added `FileMapper::findOwningObjectUuid()` (mirrors the uuid-named-folder convention `FileMapper::getFilesForObject()`'s fallback already relies on). See Implementation Notes in design.md.
- [x] Union the metadata-match result set with the chunk-match result set; deduplicate on object id; preserve the existing envelope shape (object rows, not chunks)
  - Spec ref: ZKN-CONTENT-002, ZKN-CONTENT-003
  - Acceptance: object matching on BOTH metadata AND chunk appears exactly once in the response; no chunk id, snippet, or score field leaks into the returned rows
  - Done: dedup on `getId()` in `ContentSearchHandler`; appended rows are real `ObjectEntity` instances merged into `$results` before the render/extend pipeline runs, so `_extend`/`_fields` apply uniformly.
- [x] Wire the flag through `SearchBackendInterface` so alternate backends can implement or explicitly fall back. On MariaDB (no tsvector), fall back to `LIKE` on `text_content` without `ts_rank` — behaviour preserved, ranking degraded; document the fallback in the interface docblock
  - Spec ref: ZKN-CONTENT-001
  - Acceptance: on a MariaDB dev env the endpoint returns matching objects for a body-text query (order may differ from PostgreSQL); on PostgreSQL the results are `ts_rank`-scored
  - Done: `SearchBackendInterface` no longer exists on `development` (the Solr/Elasticsearch external index tier was removed; `QueryHandler` documents "database is the only search backend"). The actual current seam is `ChunkMapper::searchByKeyword()`'s existing PostgreSQL-vs-other-platform branch — extended with an opt-in `$allowUnrankedFallback` parameter that runs an unranked `LIKE` scan on non-PostgreSQL platforms. See Implementation Notes in design.md.
- [x] Add PHPUnit coverage: (a) default-off byte-identity, (b) opt-in body-text match found, (c) dedup on both-surface match, (d) no raw chunk payload leakage in response
  - Spec ref: ZKN-CONTENT-001, -002, -003
  - Acceptance: 4 new test methods green on both PostgreSQL and MariaDB CI matrices
  - Done: `ContentSearchHandlerTest` (13 tests), `QueryHandlerContentSearchTest` (3 tests), `ChunkMapperKeywordSearchTest` unranked-fallback additions (3 tests), `FileMapperFindOwningObjectUuidTest` (3 tests). No chunk-field leakage is structural (handler only ever returns `ObjectEntity`), asserted via `instanceof`/`assertSame` on the returned rows.
- [ ] Confirm archive gate: after impl + tests green, run `openspec archive expose-content-search-in-object-service`
  - Spec ref: —
  - Acceptance: change moved under `openspec/changes/archive/YYYY-MM-DD-expose-content-search-in-object-service`; downstream opencatalogi #136 (WOO-517) unblocks
  - Left unchecked intentionally: archiving happens after this PR merges to `development`, not in the PR itself.
