# Design: search-file-text-and-vector-facade

Read at openregister development 555af7212, and hermiq development 5ac16d315
(`openspec/changes/vector-rag/proposal.md`).

## Context

- `FileTextController::getFileText()` (`lib/Controller/FileTextController.php:147-160`)
  answers 404 "This endpoint is deprecated. Use chunk-based endpoints
  instead." with a TODO; no chunk route returns a file's text. The route is
  `appinfo/routes.php:1820`, and `McpDiscoveryService.php:671-675` advertises
  it (issue #4106).
- Extraction stores chunks: `ChunkMapper::findBySource($sourceType, $sourceId)`
  (`lib/Db/ChunkMapper.php:93`).
- `VectorizationService` has `semanticSearch()` (`:468`), `hybridSearch()`
  (`:495`) and `generateEmbedding()` (`:448`); `VectorSearchHandler` reads the
  vectors. These are internal classes.
- `FileSearchController::semanticSearch()` passes the query to
  `semanticSearch()` with `entity_type: file` and returns the results as they
  come (`lib/Controller/FileSearchController.php:80-110`), with no check that
  the caller may read each file. The filinq lane recorded this as a security
  candidate.
- `ToolRegistryFacade` (`lib/Service/Mcp/ToolRegistryFacade.php`) is the
  published cross-app facade pattern: a stable class, documented as a public
  contract, running in the caller's own context.

## D-1: the text read assembles chunks, as the caller

`getFileText(fileId)` resolves the file through the caller's user folder
(`IRootFolder::getUserFolder(uid)->getById(fileId)`). No node: 404 "File not
found." Found: read the chunks for source type `file` and that id, ordered by
their index, and answer `{ fileId, text, chunkCount, extractedAt }`. No
chunks: 404 "No text has been extracted from this file yet." The TODO and the
deprecation sentence go.

## D-2: one read-rights filter for every vector result

`VectorResultFilter::readable(results, uid)` keeps a `file` result only when
the caller's folder resolves its id, and an `object` result only when the
object permission check allows `read`. It runs after ranking, and the search
asks the vector store for three times the limit so a filtered page is still
full when it can be. Both `FileSearchController` routes and the facade use it.

## D-3: the facade is a contract

`VectorSearchFacade` has the four methods of hermiq's change and nothing else.
`views` narrows `object` results to the registers and schemas of those views.
Its docblock marks it a public cross-app contract like `ToolRegistryFacade`,
and a change to a signature needs an OpenSpec change. `isAvailable()` is false
when no embedding provider is configured, so hermiq keeps its keyword
fallback.

## Risks

- Checking rights per result costs a lookup each. Results are few (the
  facade's limit is capped at 50), and the lookups are batched per type.
