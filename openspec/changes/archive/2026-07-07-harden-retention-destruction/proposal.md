---
kind: fix
depends_on: []
adr: openspec/architecture/adr-003-immutable-hash-chained-audit-trail.md
---

## Why

The retention/destruction subsystem has three defects that matter for a feature
that permanently deletes governed data:

1. **Unbounded eligibility scan + N+1 (MED/HIGH).**
   `RetentionService::findEligibleForDestruction()` (`lib/Service/RetentionService.php:621-690`)
   runs `SELECT id FROM openregister_objects WHERE retention IS NOT NULL` with no
   `setMaxResults`/`setFirstResult`, `fetchAll()`s the whole set, then does one
   `objectMapper->find()` per row (`:643`). The sibling scan in the *same job*
   (`DestructionCheckJob::sendPreDestructionNotifications()`,
   `lib/BackgroundJob/DestructionCheckJob.php:204-273`) already paginates in
   500-row batches citing "OPS-7" — the eligibility scan that decides what gets
   destroyed never got the same fix, so it can load the full retained-object set
   into memory and OOM the job worker.

2. **No re-entrancy guard on destruction execution (MED).**
   `DestructionExecutionJob` (`lib/BackgroundJob/DestructionExecutionJob.php:104-122`)
   checks `status === 'approved'` once, then only writes `status = 'executed'` at
   the very end (`:195`), with no in-progress transition. As a `QueuedJob`, a
   retry after a mid-run failure or a duplicate dispatch lets two runs both read
   `approved`, both iterate the same list, and both call `deleteObject()` +
   `createAuditTrailEntry()` on overlapping UUIDs — double deletion, double audit.

3. **Dead pre-delete mutation (LOW).** `:157-159` sets
   `archiefstatus = 'vernietigd'` on the in-memory entity and never persists it
   before the hard `deleteObject()` at `:176-183` — pure dead code (harmless,
   but misleading, and if the intent was an audit `changed` diff it silently
   never happens).

## What Changes

- Paginate `findEligibleForDestruction()` in bounded batches, mirroring
  `sendPreDestructionNotifications()`; avoid the per-row `find()` N+1 by
  batch-loading scope.
- Add a compare-and-set status transition (`approved` → `executing`) at the top
  of `DestructionExecutionJob::run()` before iterating; a second concurrent run
  sees `executing` and exits. Optionally dedupe on `destructionListUuid` via a
  short-lived lock row.
- Remove the dead `archiefstatus` mutation, or wire it to actually persist /
  feed the audit diff if that was the intent.

## Impact

- Affected: `lib/Service/RetentionService.php`,
  `lib/BackgroundJob/DestructionExecutionJob.php`.
- Behavioural change: destruction runs become memory-bounded and idempotent; no
  API change.
- Risk: the CAS transition must be a real atomic write (not read-then-write) or
  it reintroduces the race it fixes.
