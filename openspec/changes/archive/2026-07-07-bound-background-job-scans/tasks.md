## 1. Temporal sweep watermark

- [ ] 1.1 In `TemporalCalculationSweepService` (`:192,235`), only process objects whose next tier-crossing time ≤ now (derive from the materialised calc); add a per-run batch cap + resume offset instead of `findAllInRegisterSchemaTable()` unbounded.

## 2. Stream name warmup

- [ ] 2.1 In `CacheHandler::warmupNameCache()` (`:1330`), page with `setMaxResults`/offset and a `SELECT uuid, name` projection, writing directly to the distributed cache; never `findAll()` the whole table into an array.

## 3. Page DSAR DPIA scan

- [ ] 3.1 In `DsarDpiaDetectionJob` (`:310-319`), page the case scan and/or filter to cases modified since a watermark; batch the per-uuid re-reads (`:249`).

## 4. Fix ScheduledNotificationJob cursor bug

- [ ] 4.1 In `ScheduledNotificationJob` (`:317,333-344`), push the trigger filter into SQL with `_limit`/`_offset`; iterate with a persisted offset cursor so objects past 5000 are eventually processed. Remove the always-same `array_slice(0, 5000)`.

## 5. Verification

- [ ] 5.1 Test: temporal sweep on a large schema processes only due objects, in bounded batches; a full cycle covers all due objects.
- [ ] 5.2 Test: name warmup never loads the full table into memory (assert paging).
- [ ] 5.3 Test: an object at index >5000 in a schema DOES eventually fire its scheduled notification; none fires twice per due window.
- [ ] 5.4 `composer check:strict` passes.

## Acceptance criteria

- No job loads a full object/case set into memory unbounded per tick.
- Scheduled notifications fire for every eligible object, not just the first 5000.
- Sweeps use a watermark/cursor so cost scales with due work, not total data.
