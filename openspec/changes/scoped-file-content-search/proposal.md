# Scoped file content search: an app finds a user's files by a word in them

## Why

filinq's `work-content-search` change lets a user find a document by a word
in it, on My documents and in the documents panel on a record. filinq asks
OpenRegister through one class that looks for the service
`OCA\OpenRegister\Service\File\ScopedContentSearchService`. OpenRegister had
no such service: `ChunkMapper::searchByKeyword()` searches every chunk on the
instance, unscoped. Issue #11037, item 1.

## What changes

- `ScopedContentSearchService::searchFiles(string $words, array $fileIds, string $userId)`
  searches the extracted text of the listed files that the user can read
  (resolved in the user's own folder; at most 500 ids), and answers
  `{results: [{fileId, rank, snippet}], unextracted: [fileId]}`: one hit per
  file, best rank first, the snippet HTML-escaped with the words in `<mark>`,
  and the readable listed files that have no extracted text yet.
- A new `Files\Db\FileChunkSearch` holds `searchFileChunks()` (ranked `ts_rank` on PostgreSQL,
  an unranked substring match elsewhere) and `fileIdsWithChunks()`, both
  limited to the given file ids; `ChunkMapper` itself is unchanged.

## Out of scope

Items 2 and 3 of #11037: file hits in the unified search provider and a file
deep-link hook for apps. They stay open on the issue.

## Impact

Additive. No schema, route or migration change. No caller changes.
