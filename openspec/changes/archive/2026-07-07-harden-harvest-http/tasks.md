## 1. HTTP timeouts

- [ ] 1.1 Configure `timeout` and `connect_timeout` on the harvest client (`RestApiSourceFetcher.php:60` and the requests at `:106,146`).

## 2. Eliminate N+1 refetch

- [ ] 2.1 When `gather()` already returns full item bodies, capture them and skip the per-id `fetch()` (`:141-146`); only fetch-by-id when the collection endpoint returns references/ids.

## 3. Bounded concurrency

- [ ] 3.1 In `HarvestPipelineService::fetchAll()` (`:151-172`), replace the serial loop with a bounded-concurrency Guzzle pool/promises; honour upstream rate limits (`Retry-After`/429).

## 4. Verification

- [ ] 4.1 Test: a non-responsive upstream fails the fetch within the timeout, not indefinitely.
- [ ] 4.2 Test: a source whose collection endpoint returns full bodies performs no per-id refetch.
- [ ] 4.3 Test: harvest of many records runs with bounded concurrency (assert the pool window), results identical to serial.
- [ ] 4.4 `composer check:strict` passes.

## Acceptance criteria

- Harvest HTTP calls have connect + read timeouts.
- No per-record refetch when the collection already carries the bodies.
- Records are fetched with bounded concurrency, respecting rate limits.
