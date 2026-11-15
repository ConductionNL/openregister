# Tasks: search-file-text-and-vector-facade

## 1. File text

- [x] 1.1 `getFileText()` through the caller's folder and ordered chunks, with the two 404 sentences; remove the stub. Verify: `FileTextControllerTest` for a readable extracted file, a readable file without chunks, and a file of another user.
      (verified: lib/Files/Controller/FileTextController.php `getFileText()` answers `{fileId, text, chunkCount, extractedAt}` (plus the earlier `file_id` and `length`), refuses with "File not found." and answers "No text has been extracted from this file yet." without chunks; the count and time come from the new `TextExtractionService::readExtractedText()`. tests/Unit/Controller/FileTextControllerTest.php pins all three answers, tests/Unit/Service/TextExtractionReadBackTest.php the count and time, tests/Unit/Controller/FileAccessGuardGoldenTest.php the refusal.)

## 2. Read-rights filter

- [x] 2.1 `VectorResultFilter::readable()` for files and objects, batched, over-fetch of three times the limit. Verify: `tests/Unit/Service/Search/VectorResultFilterTest.php` with results from two users. (verified: lib/Service/Search/VectorResultFilter.php — files through `FileReadScope`, objects through `ObjectOperationsInterface::find()` with RBAC and multitenancy, each entity checked once, unknown types dropped, `OVER_FETCH = 3`; tests/Unit/Service/Search/VectorResultFilterTest.php)
- [x] 2.2 Apply the filter in `FileSearchController::semanticSearch()` and `hybridSearch()`. Verify: `FileSearchControllerTest` asserts another user's file never appears. (verified, already on main through `FileReadScope`: lib/Files/Controller/FileSearchController.php, tests/Unit/Controller/FileSearchReadScopeTest.php)

## 3. Facade

- [x] 3.1 `VectorSearchFacade` with `isAvailable()`, `searchSemantic()`, `searchHybrid()` and `embedTexts()`, the row shape of hermiq `vector-rag`, the views filter and the limit cap. Verify: `tests/Unit/Service/Search/VectorSearchFacadeTest.php` asserts the shape and that the filter ran. (verified: lib/Service/Search/VectorSearchFacade.php, `generateEmbedding()` added to `VectorizationServiceInterface` so the facade depends on the interface; tests/Unit/Service/Search/VectorSearchFacadeTest.php — dropping the read filter fails 2 of its tests)
- [ ] 3.2 Record the facade as a public contract in `openspec/specs/vector-embeddings/spec.md` at archive time. Verify: the spec lists the four signatures.

## 4. Proof and docs

- [ ] 4.1 Newman: extract a PDF, read `GET /api/files/{id}/text` as its owner (200) and as another user (404).
- [x] 4.2 Document the text read and the facade in `docs/`, and close issue #4106 with the PR. (verified: docs/api/vector-search-api.md, sections "Reading a file's extracted text" and "Vector search facade"; the PR body closes #4106.)

Acceptance:
- No route or facade method returns text from a file or record the caller cannot open.
