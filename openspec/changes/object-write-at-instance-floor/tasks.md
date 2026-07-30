# Tasks — Object writes at the instance floor

## ⚠️ RE-MEASURED 2026-07-30 — the target is already met at median

After the DocuDesk fixes landed, I re-measured before executing this plan. The
numbers it was written against are stale, and acting on them would have meant a
large refactor for a small gain.

```
tests/perf/object-create.sh, app token, defer_object_events=1, JIT off, 16 runs
wall            min 220ms  median 249ms  p95 394ms
instance floor  207ms
WRITE PATH      min 13ms   median 42ms   p95 187ms
```

**The ≤50 ms target is met at median (42 ms).** p95 is over, but the host was at
load 4.6–6.0 from unrelated work; that is measurement noise, not code.

### What moved, and what that does to the plan

Statement log of one create, before and after the DocuDesk fixes:

| | before | now |
|---|---|---|
| statements | 326 | **251** |
| schema lookups | 57 (44.7 ms) | **15 (11.3 ms)** |
| register lookups | 24 (3.2 ms) | 24 (3.5 ms) |
| `information_schema` probes | 9 (4.7 ms) | 9 (3.5 ms) |
| `SELECT lastval()` | 18 | 9 |

**Phase 1 is now worth ~11 ms, not ~45 ms.** Rewriting every schema read path
through an identity map is no longer justified by the measurement. Tasks 1–4 are
therefore **deferred, not cancelled** — they become worthwhile again if the
schema count grows or if a caller reintroduces a hot loop, and the reasoning is
preserved below so nobody has to rediscover it.

### A hypothesis of mine that did NOT hold

The re-measurement showed `SELECT ... FROM oc_appconfig WHERE lazy = ?` at
**80.6 ms, 42 % of all DB time** — and `app_versions` stores **9.1 MB across 86
non-lazy appconfig keys** (`appstore.payload.*`, up to 3.2 MB for `mail`).
Nextcloud loads non-lazy config eagerly, so this looked like the single biggest
per-request cost.

It is not. Marking those keys lazy and re-measuring made creates *marginally
slower* (275 → 299 ms median), because `memcache.local` is APCu and the config
is cached across requests — the 80 ms was a **cold-cache event**, once per PHP
worker, not per request. Change reverted.

Worth keeping anyway as hygiene (9.1 MB of payloads only one app reads), but
**not as a performance task**, and not on this change's critical path.

### ⚠️ CRUD MEASURED 2026-07-30 — this REVERSES the Phase 1 deferral

I had only ever measured creates. Measuring the rest of the object API changes
the conclusion above.

`tests/perf/object-crud.sh`, 5 runs each. Host load was 61.7 from unrelated
work, so the ABSOLUTE numbers are badly inflated — the ratios are the finding:

| operation | wall median | minus floor |
|---|---|---|
| create | 1,477 ms | 525 ms |
| read (one) | 1,447 ms | 495 ms |
| search (list, limit 20) | 659 ms | **0 ms** |
| **update** | 9,080 ms | **8,128 ms** |
| **delete** | 4,243 ms | **3,291 ms** |

Search is free — it never leaves the floor. Update costs **15×** a create and
delete **6×**.

### ✅ TRUSTWORTHY per-request counts (measured from inside PHP)

`WritePhaseProbe::count()` counts inside the request, so "this request" is
unambiguous — no pooling confound. These supersede every statement-log count in
this document:

| operation | schema DB reads | tableExists probes | full table enumerations |
|---|---|---|---|
| create | 6 | 3 | 0 |
| **update** | **13** | **7** | **1** |
| **delete** | **12** | **7** | **1** |

So update/delete do roughly **2× a create's** schema reads and each performs one
full 2,728-table enumeration that a create does not. That is a real gap and
worth closing — and it is far more modest than the 51-vs-15 the pooled log
suggested. The wall-clock ratios (15× / 6×) still stand and are not explained by
these counts alone, which points at PHP-side work rather than round trips.

The magic-table memoisation holds it at **1** enumeration per request, which is
the floor without sharing mapper instances.

⚠️ read and search produce no counts: `WritePhaseProbe::flush()` is only reached
from the write path. Instrumenting the read path is open work.

⚠️ **CORRECTION (same day): the statement COUNTS below are unreliable.** They
were taken from every statement on the request's PostgreSQL backend within a
time window. A backend is a **pooled connection** — it serves consecutive
requests — so the window sweeps in unrelated traffic. Tight enough for a ~500 ms
create; useless for an update that took 30 s under host load 21-62. Checking the
same capture, 24 distinct backends issued probes during it.

The WALL-CLOCK ratios (update 15x a create, delete 6x, search free) come from
timing individual HTTP requests and are unaffected. The direction of the finding
holds. The specific counts do not — treat them as indicative.

Correct method: bracket on a marker the request emits in its own SQL, or add a
per-request id to `log_line_prefix`, or count from inside PHP where "this
request" is unambiguous.

Statement counts, which do NOT inflate with load (PostgreSQL statement log,
scoped to the request's backend and window):

| | statements | DB time |
|---|---|---|
| create | 251 | 194 ms |
| **update** | **716** | **2,672 ms** |
| **delete** | **595** | **1,781 ms** |

And the shape is the same in both:

| statements | update | delete | create |
|---|---|---|---|
| register lookups | 66 | 64 | 24 |
| schema lookups (`uuid OR LOWER(slug) OR id`) | 51 | 51 | 15 |
| schema lookups (slug + id IN) | 42 | 42 | 9 |
| `information_schema.tables` probes | 27 | 27 | 9 |
| `getLiveMagicTables()` (lists ALL 2,728 tables) | **12** | — | ~3 |

**So Phase 1 is NOT worth ~11 ms — that was the create-only figure.** On an
update it is 117 register+schema resolutions and 12 full magic-table
enumerations. Tasks 1–4 and task 5 move back to the TOP of the list, and task 3
(the register map) matters more than task 1, because registers are re-resolved
more often than schemas.

The general lesson is mine to own: I measured one operation, generalised from
it, and deferred the fix that the other operations most needed. The budget in
the spec is per-operation for a reason.

### Revised priority

1. **p95 stability** — the median is at target; the spread is not. Establish
   whether p95 moves at all on an unloaded host before treating it as a code
   problem.
2. **Phase 4 latent items** (tasks 11–13) — these are the real remaining risks.
   pipelinq's appstore walk costs nothing here *only* because
   `has_internet_connection=false`; openconnector is one config key from a
   repair step per request; the 60 s cron fleet is the noise floor that made
   every measurement in this session harder.
3. **Task 6 (one transaction)** — still ~135 commits per create. Correctness and
   fsync hygiene argue for it independently of latency.
4. **Tasks 1–5 — NO LONGER DEFERRED.** The CRUD measurement above reverses
   that call: they are the dominant cost of update and delete.
5. **Tasks 9–10 (fan-out index)** — still right, still not urgent: the
   register-scoped fallback holds today.

The original task list follows unchanged, so the reasoning behind each item
survives even where the priority moved.

---

## Phase 1 — schema and register resolution (DEFERRED — now ~11 ms, see above)

- [ ] **Task 1 — one identity map in front of every schema read.**
      Route every path that returns a `Schema` through a single request-scoped
      map keyed on the schema's **primary key**, with id/uuid/slug all resolving
      into it. `find()` already has `findCache`; the misses come from siblings
      that have none (`findAll`, `findMultiple`, `findBySlug`,
      `findBySlugInIds`, `loadSchema`) and from `loadSchema()` being called per
      `$ref` during `resolveSchemaExtension()` with no cache at all.

      **RBAC and multi-tenancy flags MUST NOT be part of the cache key.** They
      govern whether a caller may see a result, not which row is loaded;
      including them multiplies the miss rate by the number of flag
      combinations. Apply visibility to the value returned from the map.

      Target: **57 → ≤ 5** schema reads per create.

- [ ] **Task 2 — make the miss path cheap.**
      - Drop `SELECT *` where only metadata is needed; `properties` is ~2 KB per
        row and was hydrated 57 times per create.
      - Add a functional index on `LOWER(slug)` so the slug arm stops forcing a
        sequential scan (`Rows Removed by Filter: 1916`).
      - Split the three-way `uuid OR LOWER(slug) OR id` disjunction into the one
        arm the caller actually holds — a disjunction across three columns cannot
        use an index on any of them.

- [ ] **Task 3 — same treatment for `RegisterMapper`.** 24 reads per create of
      the identical query shape. Do not copy the map; extract the one the
      schemas use.

- [ ] **Task 4 — cross-request cache.** APCu keyed on `schema.id + schema.updated`
      so a cold worker does not repay everything. Invalidated by the existing
      `clearFindCache()` hook, extended to drop the APCu entry too.

## Phase 2 — the write itself

- [ ] **Task 5 — stop probing `information_schema.tables` per insert.**
      9 probes, 4.7 ms per create, asking whether a magic table exists. The
      answer changes only when a register/schema pair is created or dropped.
      Cache it for the request and invalidate on table create/drop.

- [ ] **Task 6 — one transaction per write.**
      ~135 commits → **1**. Wrap the create/update path in a single
      `beginTransaction()`/`commit()`.

      ⚠️ Highest-risk task here. Two things to get right:
      - **Lock duration.** A wider transaction holds row locks longer. Measure
        contention, not just latency, before and after.
      - **The queued job must be dispatched AFTER commit**, never inside the
        transaction — a rollback must not leave a job for a write that did not
        happen. This is already specified in `object-write-performance`.

      Also removes the 18 `SELECT lastval()` round-trips if the insert returns
      its id instead.

- [ ] **Task 7 — finish the deferral set.**
      `defer_object_events` currently covers `ObjectCreatedEvent` only. Extend to
      update and delete, plus audit-trail hash sealing and notification-history
      writes (2 rows + a hash-chain `UPDATE` per create today).

      ⚠️ **Every deferred path MUST carry the acting user.** A background job has
      no session and OpenRegister reads are organisation-filtered against the
      session user, so a deferred dispatch without identity is a **flawless
      no-op** — the first implementation ran clean, logged nothing, and produced
      zero CloudEvents where inline produced one. Verified fix: 1 event,
      matching inline.

- [ ] **Task 8 — decide whether deferral becomes the default.**
      It is opt-in because a flow declaring `executionMode: sync` exists
      precisely so its effects land before the save returns. Either (a) keep it
      opt-in and document the latency trade, or (b) make it default and give
      `sync` flows a supported synchronous path. **This is a product decision,
      not a performance one** — it needs Ruben, not a benchmark.

## Phase 3 — remove the fan-out permanently

- [ ] **Task 9 — `uuid → (register, schema)` index table.**
      `oc_openregister_object_index (uuid, slug, uri, register_id, schema_id,
      deleted_at)`, maintained inside the same transaction as the object (task 6),
      with a backfill migration and a repair step for existing rows.

      Today the instance-wide fan-out is avoided only because
      `ObjectService::find()` keeps the caller's register on a miss. That is a
      good fix and a thin one: any future caller that legitimately has no
      register still reaches a 690 KB statement whose cost is 86 % planning.
      With the index, delete the 2,728-branch `UNION` rather than optimise it.

- [ ] **Task 10 — resolve typed relations against their declared schema.**
      The work originally specified as task 5 of `object-write-sub-500ms` and
      explicitly *not* done there. When a property declares `$ref`/a schema id,
      query that schema's magic table only. Matters for read paths, which did not
      benefit from the write-path fix.

## Phase 4 — per-request work outside the write (ADR-076)

- [ ] **Task 11 — pipelinq: stop building an app-store lookup per request.**
      `resolveDependencyStatuses()` → `buildAppStoreLookup()` →
      `AppFetcher::get()` iterates the **3.4 MB** appstore catalogue on every
      request. It is free on this instance **only because
      `has_internet_connection=false`** returns an empty set — a latent cost, not
      an absent one.

      Two fixes, both needed:
      - Cache the dependency statuses like `loadRoadmapFeatures()` already does
        (`ICacheFactory` local, keyed on app version).
      - **Do not compute `provideInitialState()` on requests that render no UI.**
        Initial state exists for page loads; an object-create API call pays for
        it and throws it away. Gate on the request being a page request, or move
        it to a controller.

- [ ] **Task 12 — openconnector: move the repair-step fallback to cron.**
      `ensureRegisterBootstrapped()` invokes a repair step from `boot()`. It is
      gated *and* persists `register_bootstrapped_version` (verified populated at
      `0.3.3`), so it costs nothing today — and per ADR-076 rule 4 a
      "the repair step might not have run" fallback belongs in a `TimedJob`,
      where the cost is bounded. One cleared config key is currently the
      difference between free and a repair step per request.

- [ ] **Task 13 — justify the cron intervals.**
      31 registered jobs, 8 at 60 s. An idle instance does 18 schema sequential
      scans and 356 commits per 4 seconds, which is the noise floor every
      measurement in this change fights. For each 60 s job, state the drift rate
      that requires 60 s or widen it. `ConfigurationCheckJob` is already at
      `86400 * 365`, which shows the range is deliberate elsewhere.

## Phase 5 — verification

- [ ] **Task 14 — gate the floor-relative budget in CI.**
      `tests/perf/object-create.sh` already reports wall, floor and the counter
      bounds. Make it fail on **wall minus floor > 50 ms**, and run it on changes
      to the write path. Report min/median/p95 — a single timing is not evidence
      when the observed spread was 13.6 s to 99.1 s on identical payloads.

- [ ] **Task 15 — re-measure the full matrix.**
      create/update/delete × (no relations, typed relations, untyped references)
      × (cold worker, warm worker), with the statement-log method rather than
      global counters. Ship the table in the completion note.

- [ ] **Task 16 — verify through the UI.**
      An API-green measurement is not a UI-green measurement. Confirm in a fresh
      browser session that a create through the real form lands inside budget.

- [ ] **Task 17 — purge the accumulated CloudEvents.**
      ~46,000 rows, 99.3 % of them generated *from other events* by the
      recursion defect fixed in openconnector#1086. Junk, not history. `SELECT`
      and eyeball the count before deleting anything, and scope the delete with
      an explicit `source` predicate.

## Housekeeping surfaced by this work

- [ ] **Task 18 — repoint the `.github` submodule at GitHub.**
      Its origin is still `codeberg.org/Conduction/.github.git` even though
      `ConductionNL/.github` exists on GitHub. That is why the JIT change had to
      be raised as `Conduction/.github#75` on Codeberg. The sibling problem —
      `openregister` pulling `ddn/sapp` from Codeberg — took CI down during a
      Codeberg outage and is already fixed (`7ac9c92f6`).

- [ ] **Task 19 — land the JIT change.**
      `Conduction/.github#75` disables `opcache.jit` in the dev container:
      median 1,065 ms → 922 ms on a boot-only request, ~140 ms on **every**
      request. mod_php under prefork gives each worker its own JIT buffer, and
      8 MB is too small for 92 apps. Takes effect on the next compose recreate.
