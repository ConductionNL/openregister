# Tasks: object-text-extraction-in-chunks

## 1. Listener and job

- [x] 1.1 `ObjectChangeListener` defers the object id through `ListenerDeferralService` in background mode (and for an unknown mode), chunk size from `textExtractionChunkSize`, dedupe on the id; `listenerDeferral=inline` keeps one `ObjectTextExtractionJob` per object.
- [x] 1.2 `ObjectTextExtractionChunkJob` (actor-forwarded): extracts each entry, skips an entry without an id, continues after a failure, no-op when `objectExtractionMode` is `none`.

## 2. Tests

- [x] 2.1 `tests/Unit/Listener/ObjectTextExtractionInChunksTest.php`: the listener with the real `ListenerDeferralService`: 250 saves → 3 chunks, configurable and invalid chunk size, the 4,000-character limit, an object saved twice, a single save, the kill switch.
- [x] 2.2 `tests/Unit/BackgroundJob/ObjectTextExtractionChunkJobTest.php`: every entry extracted in order, a failure does not stop the chunk, an entry without an id, extraction off.
- [x] 2.3 The existing `ObjectChangeListener` tests (unit, gap, golden) run with the kill switch on, so they keep recording the per-object behaviour unchanged.
- [ ] 2.4 Rig measurement before and after (700 objects saved item by item in one process, and as one bulk save with events): queued jobs per class and the time to work through them, in the PR body.

## 3. Docs

- [x] 3.1 `docs/technical/text-extraction.md`: object extraction in background mode, the two settings, bulk writes without events.
- [x] 3.2 `docs/user/settings-configuration.md`: the extraction mode applies to objects, in batches of at most 100.

Acceptance:

- After a flow run that saves 700 objects, at most 7 extraction jobs are queued, not 700.
- `listenerDeferral=inline` gives the old behaviour back.
