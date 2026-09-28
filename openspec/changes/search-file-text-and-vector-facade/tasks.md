# Tasks: search-file-text-and-vector-facade

## 1. File text

- [ ] 1.1 `getFileText()` through the caller's folder and ordered chunks, with the two 404 sentences; remove the stub. Verify: `FileTextControllerTest` for a readable extracted file, a readable file without chunks, and a file of another user.

## 2. Read-rights filter

- [ ] 2.1 `VectorResultFilter::readable()` for files and objects, batched, over-fetch of three times the limit. Verify: `tests/Unit/Service/Search/VectorResultFilterTest.php` with results from two users.
- [ ] 2.2 Apply the filter in `FileSearchController::semanticSearch()` and `hybridSearch()`. Verify: `FileSearchControllerTest` asserts another user's file never appears.

## 3. Facade

- [ ] 3.1 `VectorSearchFacade` with `isAvailable()`, `searchSemantic()`, `searchHybrid()` and `embedTexts()`, the row shape of hermiq `vector-rag`, the views filter and the limit cap. Verify: `tests/Unit/Service/Search/VectorSearchFacadeTest.php` asserts the shape and that the filter ran.
- [ ] 3.2 Record the facade as a public contract in `openspec/specs/vector-embeddings/spec.md` at archive time. Verify: the spec lists the four signatures.

## 4. Proof and docs

- [ ] 4.1 Newman: extract a PDF, read `GET /api/files/{id}/text` as its owner (200) and as another user (404).
- [ ] 4.2 Document the text read and the facade in `docs/`, and close issue #4106 with the PR.

Acceptance:
- No route or facade method returns text from a file or record the caller cannot open.
