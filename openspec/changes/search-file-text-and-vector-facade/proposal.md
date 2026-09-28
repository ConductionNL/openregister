---
kind: code
depends_on: []
---

# Proposal: search-file-text-and-vector-facade

## Summary

An app that helps people with their documents can read the text OpenRegister
already extracted from a PDF or Word file, and can ask OpenRegister for the
passages most similar to a question, across files and records. Both answer as
the requesting user: a file or record that user may not open never appears.

## Halves this closes

Three merged or open changes in other repositories ask OpenRegister for the
same two reads. None has a row in OpenRegister's matrix; the owner moves pass
of 28 Sep 2026 handed them here.

- buildiq `ai-copilot-documents-and-code-help` (buildiq `development`
  974af86), rows `ai-code-assist` (5 competitors yes) and `ai-spec-to-app`:
  "openregister: reading the text of a PDF, Word or OpenDocument file the
  caller may read. The extractors exist ..., but the read route
  `GET /api/files/{fileId}/text` is a deprecated stub that always answers 404
  (`lib/Controller/FileTextController.php:147-160`). OpenRegister owes a
  published read that returns a file's text as the requesting user. Until it
  exists, buildiq accepts plain text and Markdown files only." This is also
  OpenRegister issue #4106.
- buildiq `ai-agents-knowledge-and-run-trace`, row `ai-agent-knowledge-base`
  (2 competitors yes, a changelog demand row): "ranked retrieval over large
  knowledge. Hermiq's open change `vector-rag` names the public vector search
  facade as OpenRegister's to build ... OpenRegister development has no such
  facade (`lib/Service/Mcp/ToolRegistryFacade.php` is the only facade)."
- hermiq `vector-rag` (open, hermiq `development` 5ac16d315) defines the
  facade it needs: `searchSemantic(query, limit, views)`,
  `searchHybrid(query, limit, views)` and `embedTexts(texts)`, rows in the
  shape `entity_id`, `entity_type` (`object` or `file`), `chunk_text`,
  `similarity`, `metadata`, "consumed the way `ToolRegistryFacade` already is".
  ADR-001's delegation table places "vector embeddings + semantic/hybrid search
  (RAG substrate)" in OpenRegister.

## What changes

- `GET /api/files/{fileId}/text` returns the extracted text of a file the
  caller can read, assembled from its stored chunks in order, with the
  extraction time. A file without extracted text answers 404 with a sentence
  that says so; a file the caller cannot read answers 404 too.
- A public facade `OCA\OpenRegister\Service\Search\VectorSearchFacade` with
  `isAvailable()`, `searchSemantic()`, `searchHybrid()` and `embedTexts()`,
  in the shape hermiq's change defines.
- Every result the facade or the file search routes return is checked against
  the caller's read rights: files through the caller's own folder, objects
  through the object permission check. The existing
  `POST /api/search/files/semantic` and `/hybrid` get the same filter.

## Out of scope

- A new embedding pipeline. The facade reads what vectorisation already
  stores.
- Text of files that were never extracted. `POST /api/files/{fileId}/extract`
  already starts that.

## Impact

- `lib/Controller/FileTextController.php` (`getFileText()` at `:147-160`).
- New `lib/Service/Search/VectorSearchFacade.php`, beside the precedent
  `lib/Service/Mcp/ToolRegistryFacade.php`.
- `lib/Controller/FileSearchController.php` (`semanticSearch()` and
  `hybridSearch()`).
- `lib/Service/McpDiscoveryService.php:671-675` (already advertises the text
  read; it becomes true).
