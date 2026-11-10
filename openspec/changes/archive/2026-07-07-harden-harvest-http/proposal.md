---
kind: fix
depends_on: []
adr: openspec/architecture/adr-009-performance-invariants.md
---

## Why

The data-harvest/sync pipeline fetches remote records inefficiently and without
timeouts, so a slow upstream can stall a harvest job indefinitely and large
sources take thousands of serial round-trips.

1. **Bare Guzzle client with no timeout + N+1 per-record refetch (MED).**
   `RestApiSourceFetcher.php:60` auto-wires a bare `Client` (Guzzle default
   timeout `0` = infinite); the requests at `:106,146` pass only headers, no
   `timeout`/`connect_timeout`. `fetch()` (`:141-146`) GETs `baseUrl/{id}` per
   record — even though `gather()` already paged through the collection (often
   carrying the full item bodies). A hung upstream stalls the job with no timeout;
   `MAX_PAGES=1000` is walked serially.

2. **Records fetched strictly serially (MED).**
   `HarvestPipelineService.php:151-172` loops `foreach ($records as $record) {
   $raw = $fetcher->fetch(...); ... }` — one HTTP call at a time. A source with
   thousands of records is thousands of sequential round-trips, compounded by the
   missing timeout.

## What Changes

- Configure `timeout` and `connect_timeout` on the harvest HTTP client so a slow
  upstream fails fast instead of hanging the job.
- When the collection endpoint (`gather()`) already returns full object bodies,
  capture them and skip the per-id `fetch()` (eliminate the N+1).
- Fetch records with bounded concurrency (Guzzle pool/promises) instead of a
  strictly serial loop, with a sane concurrency window.

## Impact

- Affected: `lib/Service/Sync/RestApiSourceFetcher.php`,
  `lib/Service/Sync/HarvestPipelineService.php`.
- Behavioural: harvest jobs complete faster and fail fast on dead upstreams;
  output unchanged.
- Risk: bounded concurrency must respect upstream rate limits — pick a
  conservative window and honour `Retry-After`/429 if the source signals it.
