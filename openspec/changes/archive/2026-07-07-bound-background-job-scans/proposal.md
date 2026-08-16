---
kind: fix
depends_on: []
adr: openspec/architecture/adr-009-performance-invariants.md
---

## Why

Several TimedJobs re-scan entire object sets every tick with no watermark, batch
cap, or resume cursor — cost grows forever with data, and one of them has a
correctness bug that silently drops work.

1. **Temporal sweep re-scans + rewrites every temporal object hourly (HIGH).**
   `TemporalCalculationSweepService.php:192` calls
   `findAllInRegisterSchemaTable()` (no limit) then `saveObject()` per object
   (`:235`) through the full write path; `TemporalCalculationSweepJob.php:61` runs
   every 3600s, enabled by default. On a schema with 100k open cases this is a
   full-table load + per-object recompute + N writes per hour, forever.

2. **Name warmup loads the entire objects table into one array (HIGH).**
   `NameCacheWarmupJob` → `CacheHandler.php:1330` `findAll()` (no limit), building
   an in-memory UUID→name map. Millions of objects → multi-GB resident / OOM.

3. **DSAR DPIA detection re-enumerates the whole case register every tick (MED).**
   `DsarDpiaDetectionJob.php:310-319` `findAll()` (no limit), then per-uuid
   `find()` (`:249`) for flagged cases; enabled by default (`:145`).

4. **Scheduled notifications always process the SAME first 5000 — objects past
   5000 never fire (MED + correctness bug).**
   `ScheduledNotificationJob.php:317` `findBySchema()` loads the whole schema
   table; `:333-344` caps with `array_slice($objects, 0, 5000)`. The comment says
   "deferred to next run" but it always slices the *same* first 5000, so any
   object beyond index 5000 never triggers a notification. Runs every 60s.

## What Changes

- Add a per-schema watermark to the temporal sweep: only process objects whose
  next tier-crossing time ≤ now (computable from the materialised calc), plus a
  per-run batch cap with a resume offset.
- Stream the name warmup in pages (`setMaxResults`/offset) with a
  `SELECT uuid, name` projection, writing directly to the distributed cache —
  never materialise the whole table.
- Page the DSAR DPIA scan and/or restrict it to cases modified since a watermark;
  batch the per-uuid re-reads.
- Fix `ScheduledNotificationJob`: push the trigger filter into SQL with
  `_limit`/`_offset` and iterate pages with a **persisted offset cursor** so all
  objects are eventually processed (not the same first 5000 each run).

## Impact

- Affected: `lib/Service/Calculation/TemporalCalculationSweepService.php`,
  `lib/Service/Object/CacheHandler.php`, `lib/BackgroundJob/DsarDpiaDetectionJob.php`,
  `lib/BackgroundJob/ScheduledNotificationJob.php`.
- Behavioural change: `ScheduledNotificationJob` now fires for all objects (bug
  fix) — verify no duplicate fires when the cursor wraps. Others are pure
  efficiency, output unchanged.
- Risk: watermark/cursor logic must be correct or it can skip or double-process;
  add tests that a full pass covers every eligible object exactly once.
