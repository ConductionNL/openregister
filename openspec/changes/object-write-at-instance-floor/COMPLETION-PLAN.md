# Completion plan — object-API performance programme

Written 2026-07-30, after the measurement work landed. This is what is left to
call the programme done, in the order it should be done, with the blockers named
rather than buried.

## Where it stands

Everything opened this session is merged to `development`:
openregister #2186 #2187 #2200 #2201 #2202, hydra #402, docudesk #350 #351,
openconnector #1101, pipelinq #662, and `Conduction/.github#75` on Codeberg.

| | start | now |
|---|---|---|
| create, median | 13,688 ms | ~249 ms |
| write path (wall − instance floor) | ~12,800 ms | ~42 ms |
| instance floor | ~950 ms | ~207 ms |

The create budget is met. What follows is the rest of the API, the deferred
structural work, and the things I could not finish honestly.

---

## Phase A — establish a trustworthy baseline (blocks everything else)

Nothing below can be validated on the current host. Load ran **4.6 → 61.7**
all session from unrelated work, and every wall-clock figure moved by 3× with
it. Two measurements were invalidated outright:

- statement counts taken from a **pooled** PostgreSQL connection by wall-clock
  window (retracted in #2202);
- an update measured at 30 s that was ~4 s on a quiet host.

- [ ] **A1. Re-run `tests/perf/object-crud.sh` on a quiet host** (load < 2).
      Record min/median/p95 per operation with the floor subtracted in the same
      run. Until this exists, treat every ratio below as provisional.
- [ ] **A2. Instrument the read path.** `WritePhaseProbe::flush()` is only
      reached from the write path, so read and search currently report no
      per-request counts. Without this, half the API is unmeasured.
- [ ] **A3. Decide whether p95 is a code problem at all.** The create median is
      42 ms above floor; p95 was 187 ms. On a loaded host that difference is
      indistinguishable from noise. Establish it on a quiet host before anyone
      optimises for it.

## Phase B — update and delete (the real remaining gap)

Trustworthy per-request counts (measured from inside PHP, not the pooled log):

| operation | schema reads | tableExists probes | full table enumerations |
|---|---|---|---|
| create | 6 | 3 | 0 |
| update | 13 | 7 | 1 |
| delete | 12 | 7 | 1 |

Update was **15×** a create and delete **6×** by wall clock. Those counts do
*not* explain that gap, which points at PHP-side work rather than round trips.

- [ ] **B1. Profile update/delete in PHP, not SQL.** The counts are modest; the
      wall-clock gap is not. Find where the time actually goes before optimising
      anything. This is the single highest-value item remaining.
- [ ] **B2. Task 3 — the register identity map.** `RegisterMapper::find()` has a
      cache keyed on `id:rbacFlag:mtFlag`. The flags multiply the miss rate.
      ⚠️ **Security-relevant**: multitenancy changes *which rows the query
      returns*, so the fix is to cache the row by identifier and apply the
      visibility predicate to the cached value — not to drop the flags and
      return whatever was cached. Do not shortcut this.
- [ ] **B3. Task 1 — the schema identity map.** Same shape, same caveat.
      `loadSchema()` is called per `$ref` during composition resolution with no
      cache at all.
- [ ] **B4. Task 5 — the `tableExists` probes.** 7 per update. My memo only
      caches positive answers (a negative can legitimately become positive when
      `ensureTableExists()` creates a table mid-request). Getting below 7 needs
      the caller to stop asking, not a bigger cache.

## Phase C — structural work, deliberately deferred

- [ ] **C1. Task 6 — one transaction per write.** Still ~135 commits per create,
      each an fsync the request waits on. ⚠️ Highest-risk item in the programme:
      it widens lock duration and changes failure atomicity. Measure contention,
      not just latency. The queued job must dispatch **after** commit — a
      rollback must not leave a job for a write that did not happen.
- [ ] **C2. Tasks 9–10 — the `uuid → (register, schema)` index.** Deletes the
      2,728-branch `UNION` rather than optimising it (86 % of its cost is
      planning, which no index touches). Currently avoided only because
      `ObjectService::find()` keeps the caller's register on a miss — a thin
      defence that the next caller can bypass.
- [ ] **C3. Task 7 — finish the deferral set.** `defer_object_events` covers
      created only; update, delete, audit sealing and notification history
      remain inline. ⚠️ **Every deferred path must carry the acting user** — the
      first implementation without it was a flawless no-op that produced zero
      CloudEvents while reporting success.
- [ ] **C4. Task 13 — justify the cron intervals.** 31 jobs, 8 at 60 s. An idle
      instance does 18 schema scans and 356 commits per 4 seconds; that is the
      noise floor every measurement here fought.

## Phase D — e2e

183 spec files across six apps (openregister 57, pipelinq 44, openconnector 33,
docudesk 20, opencatalogi 17, larpingapp 12). **None were run this session** —
under load 21–62 the results would not have been trustworthy, and a red suite I
could not attribute is worse than an unrun one.

- [ ] **D1. Run each suite on a quiet host**, one app at a time.
- [ ] **D2. Triage every failure as pre-existing or regression** before fixing
      anything. Use `git stash` / a clean worktree to establish which.
- [ ] **D3. Fix regressions from this programme's changes.** The changes with
      e2e exposure are pipelinq's `requestRendersPage()` gate (initial state now
      absent on API requests — verify no page path regressed) and openconnector's
      `boot()` → cron move (verify the register still bootstraps).
- [ ] **D4. Leave pre-existing failures documented, not silently fixed.**

## Phase E — the blocker that is not mine to decide

- [ ] **E1. ApexCharts licence.** `vue3-apexcharts` **≤ 1.8.0 is MIT**;
      **≥ 1.9.0 is a dual, revenue-gated licence** — free only for non-profits,
      educators, and businesses under **$2 M annual revenue**. CI resolved
      **1.11.1**, and openconnector's `License (npm)` gate is *correctly*
      failing.

      Affected: nextcloud-vue (the shared-deps route), openregister,
      openconnector, larpingapp, petstore, procest.

      The core `apexcharts` package is still MIT at 3.x/4.x — only the latest
      (6.6.1) moved — so the caret pins do not currently pull it. The wrapper is
      the exposure.

      **I did not write a licence override.** Doing so would silence a genuine
      compliance signal for a commercial company selling to government. Options:
      (a) pin `vue3-apexcharts < 1.9.0` fleet-wide, keeping MIT;
      (b) buy the commercial licence;
      (c) replace the charting dependency.
      **This needs Ruben, not a benchmark.**

---

## Sequencing

A → B1 → (B2, B3, B4) → C1 → C2/C3 → D. E in parallel; it blocks only
openconnector's CI going green.

Phase A is not optional. Three separate conclusions this session had to be
retracted or reversed because they were drawn from measurements taken on a
loaded host or a pooled connection:

1. "the write path is at the floor, Phase 1 is worth ~11 ms" — true for creates,
   false for update/delete;
2. "the appconfig load is 42 % of DB time" — a cold-cache event, not per-request;
3. the statement counts themselves.

Each was corrected in the open rather than quietly. The pattern is the lesson:
**measure per operation, on a quiet host, from inside the request.**
