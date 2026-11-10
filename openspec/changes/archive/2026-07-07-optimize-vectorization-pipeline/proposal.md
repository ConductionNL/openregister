---
kind: fix
depends_on: []
adr: openspec/architecture/adr-009-performance-invariants.md
---

## Why

The embedding/vectorization pipeline is slow, costly, and can hang the worker.
Five compounding issues:

1. **Embedding HTTP has no timeout (HIGH).**
   `EmbeddingGeneratorHandler.php:265-290` builds a curl request
   (`curl_init($url)` … `curl_exec($ch)`) with no `CURLOPT_TIMEOUT`/
   `CURLOPT_CONNECTTIMEOUT`. A hung Fireworks/Ollama endpoint blocks the whole
   batch (or cron) until PHP `max_execution_time` kills it.

2. **"Batch"/"parallel" embedding is actually serial one-HTTP-per-item (HIGH).**
   `VectorEmbeddings.php:333-360` (`// Generate embeddings individually`) loops
   `embedText()` per text; `EmbeddingGeneratorHandler::embedDocuments()` (`:358-365`)
   does the same. The provider's embeddings API accepts an array `input` in one
   request — here N texts = N round-trips, and `mode:'parallel'` is a no-op.
   Combined with #1 the latency is linear and unbounded.

3. **Re-embeds unchanged chunks; `max_files=0` default = whole table (HIGH).**
   `FileVectorizationStrategy.php:113-128` selects all `openregister_chunks` where
   `source_type='file'`, capping only when `maxFiles > 0` (controller default 0 =
   uncapped); `VectorizationService.php:358` calls `generateEmbedding` for every
   item with no "already vectorized / content-hash unchanged" guard. Re-running a
   batch re-embeds an unchanged corpus — one paid HTTP call per chunk, every run.

4. **Batch vectorization runs synchronously in the HTTP request, unbounded (HIGH).**
   `FileExtractionController.php:645-663` (`vectorizeBatch`, default `max_files=0`,
   `mode='serial'`) and `ObjectsController.php:3839` process N chunks inline over
   the network and return the result in-request — the client call hangs until it
   finishes or times out; no job offload, no resume.

## What Changes

- Set `CURLOPT_CONNECTTIMEOUT` (~5s) and `CURLOPT_TIMEOUT` (~30s) on the embedding
  request; on failure mark the chunk failed and continue.
- Send the whole `input` array in a single embeddings request where the provider
  supports it; fall back to serial only when it cannot batch.
- Store a content hash per chunk and skip embedding when unchanged; make
  `max_files=0` mean "a sane default page", not "all rows".
- Offload batch vectorization to a `QueuedJob`: the endpoint enqueues and returns
  202 + a status handle; the worker processes with a batch cap and resume.

## Impact

- Affected: `lib/Service/Vectorization/Handlers/EmbeddingGeneratorHandler.php`,
  `lib/Service/Vectorization/VectorEmbeddings.php`,
  `lib/Service/Vectorization/Strategies/FileVectorizationStrategy.php`,
  `lib/Service/Vectorization/VectorizationService.php`,
  `lib/Controller/FileExtractionController.php`, `lib/Controller/ObjectsController.php`,
  a `QueuedJob` (new or existing vectorization job).
- Behavioural change: batch vectorization becomes async (202 + status). Clients
  polling the old synchronous result must switch to the status handle.
- Risk: content-hash skip must key on the exact text that was embedded so a real
  change is never skipped; verify with a change-then-revectorize test.
