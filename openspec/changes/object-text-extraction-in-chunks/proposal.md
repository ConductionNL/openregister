---
kind: code
depends_on: []
---

# Proposal: object-text-extraction-in-chunks

## Summary

A flow that writes many objects leaves one text-extraction job per object,
and the next cron pass works through all of them before any other job gets
its turn. OpenCatalogi's publiccode harvest writes about 700 components per
run. After one run, 1,375 `ObjectTextExtractionJob`s sat in the queue, and the
next scheduled harvest stayed `queued` for more than 8 minutes
(openregister#4241, measured 2026-10-01). This change queues object text
extraction in chunks: N saves in one process leave at most ⌈N / chunk size⌉
jobs, each of which extracts at most the chunk size.

## Why this route

The decision of 2026-10-20 (Wilco, Ruben, Robert) and 2026-10-23 asked for a
batch job in the existing cron queue, with a maximum number of objects per
job, instead of one job per object. It placed the batch in `saveObjects()`.
Locating the write paths showed that the harvest writes item by item through
`saveObject()` (the `openregister.object-write` flow node without `bulk:
true`), so a batch inside `saveObjects()` would not reach it.

OpenRegister already has the mechanism the decision describes:
`ListenerDeferralService` collects listener work per process, across single
and bulk saves, and queues one actor-forwarded job per chunk, within the
job-argument limit (requirement "Deferred listener jobs MUST be chunk-level,
idempotent, and stale-safe"). `ObjectChangeListener` now uses it, like five
other listeners do. No new deferral mechanism is introduced.

## What changes

- `ObjectChangeListener`, extraction mode `background`: the object id goes to
  `ListenerDeferralService` (job `ObjectTextExtractionChunkJob`, chunk size
  app config `textExtractionChunkSize`, default 100, dedupe key the object
  id) instead of `IJobList::add()` per object. The unknown-mode fallback
  follows the same path.
- New `ObjectTextExtractionChunkJob` (an `ActorForwardedJob`): extracts each
  object of its chunk through `TextExtractionService::extractObject()`, skips
  an entry without an id, keeps going after a failure, and does nothing when
  `objectManagement.objectExtractionMode` is `none`.
- `listenerDeferral=inline` restores the previous behaviour, one
  `ObjectTextExtractionJob` per object.

## What does not change

- `immediate`, `cron` and `manual` extraction modes.
- A bulk save without events raises no object events, so it still gets no
  text extraction; a schema opts in with `fireEventsOnBulkWrites`.
- `ObjectTextExtractionJob` stays, for the kill switch and for jobs already
  queued before an upgrade.

## Out of scope

- Extraction mode per schema instead of OpenRegister-wide, and a periodic
  batch run that indexes the next x objects (named "later" in the decision).
- Objects in `cron` extraction mode: the listener skips them for a
  scheduled job that does not exist for objects.

## ADRs

- ADR-012 (deduplication): reuses `ListenerDeferralService` and
  `ActorForwardedJob`; no second deferral or batching mechanism.
