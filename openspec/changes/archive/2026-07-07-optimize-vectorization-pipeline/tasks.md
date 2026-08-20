## 1. HTTP timeouts

- [ ] 1.1 In `EmbeddingGeneratorHandler.php:265-290`, set `CURLOPT_CONNECTTIMEOUT` (~5s) and `CURLOPT_TIMEOUT` (~30s); on timeout/error mark the chunk failed and continue the batch.

## 2. True batch embedding

- [ ] 2.1 In `VectorEmbeddings.php:333-360` and `EmbeddingGeneratorHandler::embedDocuments()` (`:358-365`), send the full `input` array in one embeddings request where the provider supports it; serial fallback only when it cannot.

## 3. Content-hash skip + sane default cap

- [ ] 3.1 Store a content hash per chunk; in `VectorizationService.php:358` skip embedding chunks whose hash is unchanged and already vectorized.
- [ ] 3.2 In `FileVectorizationStrategy.php:113-128`, make `max_files=0` mean a sane default page size, not uncapped.

## 4. Async batch vectorization

- [ ] 4.1 Change `FileExtractionController::vectorizeBatch()` (`:645-663`) and `ObjectsController.php:3839` to enqueue a `QueuedJob` and return 202 + a status/job handle instead of processing inline.
- [ ] 4.2 Process in the worker with a batch cap and resume cursor.

## 5. Verification

- [ ] 5.1 Test: a hung embedding endpoint fails the chunk within the timeout, batch continues.
- [ ] 5.2 Test: N texts embed in one provider request (assert single HTTP call).
- [ ] 5.3 Test: re-running vectorization on an unchanged corpus makes zero embedding calls; changing a chunk re-embeds only that chunk.
- [ ] 5.4 Test: `vectorizeBatch` returns 202 promptly and the job completes the work.
- [ ] 5.5 `composer check:strict` passes.

## Acceptance criteria

- Embedding calls have connect + read timeouts.
- Batch embedding is one request per batch, not per item.
- Unchanged chunks are not re-embedded; `max_files=0` is not unbounded.
- Batch vectorization runs in a background job, not the request thread.
