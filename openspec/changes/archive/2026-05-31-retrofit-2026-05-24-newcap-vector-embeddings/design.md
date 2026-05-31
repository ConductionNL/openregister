# Design — Retrofit Phase 4c new capability `vector-embeddings`

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

## Context

`/opsx-coverage-scan` on 2026-05-24 placed `lib/Service/VectorizationService.php` and the six files under `lib/Service/Vectorization/` into Phase 4c — Bucket 2c, "code exists, no spec to point at." The architect plan called for minting a single new `vector-embeddings` capability spanning all 48 methods. This document records the design decisions that produced the five-REQ shape.

## Why one capability, not two

The architect's batch was titled `vector-embeddings-and-semantic-search`. After reading the code, the two halves diverge along a clear interface line:

- **Embedding generation + storage + batch + stats** — owned by this cap. Public entry through `VectorizationService` facade.
- **Vector-query execution (KNN / cosine / RRF / hybrid merge)** — owned by `search/spec.md` REQ-002 (PR #1791). Public entry through `FileSearchController` and `VectorSearchHandler`.

Lumping both under one cap would have duplicated PR #1791's REQ-002. Splitting along the existing search-spec boundary keeps each cap testable in isolation and avoids the double-spec problem flagged in the batch JSON notes.

The four facade query methods (`VectorizationService::semanticSearch`, `::hybridSearch`, `VectorEmbeddings::semanticSearch`, `::hybridSearch`) are pure delegation glue: they call `generateEmbedding()` (in scope here, REQ-001) and then `VectorSearchHandler->semanticSearch(...)` (out of scope, owned by search). Annotating them dual-owned is fragile — once #1791 lands, a tiny follow-up can `@spec`-pointer them at search REQ-002.

## REQ granularity calls

Five REQs map 1:1 to the five behavioral domains identified in the proposal table:

- **REQ-001 — generation** is independent of where vectors land. The provider matrix (OpenAI / Fireworks / Ollama), the LLPhant integration, and the cached-generator pattern are testable purely by invoking `generateEmbedding('hello')` and inspecting the result shape. Splitting into "default" vs "custom config" sub-REQs was rejected because both code paths exit the same `EmbeddingGeneratorHandler::getGenerator()` chokepoint — the only difference is whether config came from `SettingsService` or the caller's payload.
- **REQ-002 — storage** is independent of how the vector was generated. The Postgres `openregister_vectors` table layout, the Solr atomic-update payload shape, and the UTF-8 sanitiser are testable by handing the storage handler a precomputed embedding array. Grouping storage with generation would have produced a 30-bullet REQ.
- **REQ-003 — batch orchestration** is independent of which strategy is registered. The orchestrator's contract (loop, error-capture, aggregated counts, serial-vs-parallel branch) is testable with a mock `VectorizationStrategyInterface`. It needs its own REQ because it does NOT call back into REQ-001 or REQ-002 directly — it delegates through the strategy.
- **REQ-004 — object strategy** is the only concrete strategy implementation visible in this tree. Its title-extraction fallback chain (4-level lookup) and its `@self`/`_register` metadata-folding are observable in production today and worth pinning. File strategy is `future-pass:next`.
- **REQ-005 — stats + rotation** is independent of generation and storage. Stats read from whatever backend stored the vectors; rotation detection compares `SettingsService` config to distinct `embedding_model` values seen in `openregister_vectors`. It bundles in `clearAllEmbeddings` because all three are admin-surface utility operations that share the "scan the table, summarise" pattern.

## Why we did not extend chat-ai

`chat-ai/spec.md` REQ-001 says the LLM pipeline "retrieves relevant context from registered objects and Nextcloud files using the active agent's RAG configuration." That sentence references RAG at the *consumer* level — what the chat pipeline does with the embeddings, not how the embeddings get there. Extending chat-ai to cover embedding generation / storage / batch / stats would have:

- Coupled a backend infrastructure cap to a frontend user-flow cap (chat-ai is about agents, conversations, messages, feedback).
- Forced the chat-ai spec to grow scenarios about provider testing, Solr atomic updates, and table-scan model-mismatch detection that no chat-ai consumer ever sees.
- Made the chat-ai cap fail-on-implementation-change every time we swap embedding backends.

The shared boundary stays clean: chat-ai consumes vector search through `VectorSearchHandler` (owned by search), and search consumes embedding generation through `VectorEmbeddings::generateEmbedding` (owned by this new cap).

## Why we did not extend search

PR #1791's `search` spec is *also* retrofit and is still pre-merge. We considered folding the 42 vector-embedding methods into search to keep search-related territory in one place, but rejected because:

- Search REQ-002 explicitly scopes itself to "vector search" and the KNN / cosine / RRF math. It is not about *producing* embeddings.
- The 42 methods in this cap include a strategy interface, a multi-provider factory, a Postgres BLOB writer, a Solr atomic-update path, a UTF-8 sanitiser, a model-rotation auditor, and a JSON-serialiser. Half of those have nothing to do with search at all (e.g. `clearAllEmbeddings`, `testEmbedding`, `getDefaultDimensions`).
- Search's BackendInterface model is for indexing implementation (Solr / Elasticsearch) — not for orchestration of multi-provider LLM API clients.

Two narrow capabilities is more honest than one swollen one.

## Drop list rationale

Six methods deliberately not annotated:

| Method | Reason |
|--------|--------|
| `VectorizationService::semanticSearch` | Facade delegation to `VectorSearchHandler` — owned by `search/spec.md` REQ-002 (PR #1791). |
| `VectorizationService::hybridSearch` | Same as above. |
| `VectorEmbeddings::semanticSearch` | Same as above. |
| `VectorEmbeddings::hybridSearch` | Same as above. |

These four are listed as `future-pass:next` in `tasks.md` for a follow-up annotation once search merges.

## Notes-section drift policy

This retrofit run is *annotation-only*. The Notes section in the spec flags six observed-but-suspicious behaviors:

1. `openregister_vectors.embedding` is PHP-`serialize()`'d into a BLOB, not stored in `pgvector` or `JSONB`. This means it cannot be used for native Postgres KNN — every similarity calc has to deserialise into PHP. Search PR #1791's KNN path goes through Solr for this reason. (Already flagged in PR #1791 notes too.)
2. `EmbeddingGeneratorHandler::createFireworksGenerator` builds a curl-based anonymous LLPhant adapter rather than wrapping LLPhant's OpenAI client (Fireworks is OpenAI-API-compatible). It works, but it ignores LLPhant's retry / timeout config.
3. `EmbeddingGeneratorHandler::getDefaultDimensions` falls back to `1536` for unknown models, which is the OpenAI ada-002 size — silently wrong for any non-OpenAI provider. Callers that supply their own dimensions via storage args avoid this; the fallback only bites the `generateEmbedding()` return shape.
4. `VectorStorageHandler::storeVectorInSolr` parameters `$totalChunks`, `$chunkText`, `$metadata` are accepted but never sent to Solr — only `$entityId`, `$embedding`, `$model`, `$dimensions`, `$chunkIndex` make it into the atomic-update payload. The Postgres path uses all of them.
5. `VectorStorageHandler::storeVectorInSolr` returns a `crc32` of the document ID cast to `int`, while the DB path returns the autoincrement `id`. Callers that compare the two IDs across backends would see false negatives.
6. `ObjectVectorizationStrategy::serializeObject` always returns `json_encode($object, JSON_PRETTY_PRINT)` — the `includeMetadata` / `includeRelations` / `maxNestingDepth` config knobs are read from settings but never applied. Self-documented as `TODO: Implement configurable serialization`.

None of these are fixed here. Future tightening belongs in proper `add-*` changes.

## Scope guard

No production code changes. No "while we're in here" refactors. If a method's observable behavior looks buggy (and several do, see above), the Notes section flags it; the spec does not silently re-describe it as the correct behavior.

## Source

`/tmp/or-scan/rspec-newcap-vector-embeddings-and-semantic-search.json` (48 methods, 7 files). Retrofit playbook: `.github/docs/claude/retrofit.md`. Sibling reference: PR #1791 (`search`), `chat-ai/spec.md` REQ-001 RAG sentence.
