# Tasks: scoped-file-content-search

## 1. Build

- [x] 1.1 `FileChunkSearch::searchFileChunks()` and `::fileIdsWithChunks()`, limited to the given file ids (REQ-SFCS-001).
- [x] 1.2 `ScopedContentSearchService::searchFiles()`: readable files only, one hit per file with a marked snippet, `unextracted` (REQ-SFCS-001).
- [x] 1.3 PHPUnit `ScopedContentSearchServiceTest` with doubled `FileChunkSearch` and `IRootFolder`.

## 2. Verify

- [ ] 2.1 Live: on the dev instance with extracted files, filinq's My documents in content mode finds a document by a word that is not in its name (filinq `work-content-search` 2.1).
