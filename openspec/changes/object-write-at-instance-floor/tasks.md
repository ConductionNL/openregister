# Tasks — Object writes at the instance floor

Baseline to beat (2026-07-30, `tests/perf/object-create.sh`, app-token auth,
`defer_object_events=1`, JIT disabled, host load ~6):

```
wall            min 294ms  median 322ms  p95 476ms
instance floor  172-213ms
write path      ~110-260ms above floor
per create      326 statements, 176.8ms DB time, ~135 commits
```

Target: **write path ≤ 50 ms above the floor**, measured in the same run.

⚠️ Measure with an **app token**. Basic auth with the account password adds
~600 ms of bcrypt per request and will bury every result here. The harness warns
about it.

⚠️ `pg_stat_*` counters are **database-global**. Cron pollutes them (18 schema
scans + 356 commits per 4 idle seconds). Either subtract an idle sample taken in
the same run, or use the PostgreSQL statement log scoped to the request's
backend pid and time window — that is how the 326-statement breakdown was
obtained, and it is the authoritative method.

---

## Phase 1 — schema and register resolution (44.7 ms + 3.2 ms of DB time)

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
