# Tasks — Object writes under 500 ms

## Results so far (2026-07-29)

Measured with `tests/perf/object-create.sh` on `larpingapp/character`, same
two-field payload throughout.

| | baseline | now |
|---|---|---|
| wall, median | 13,688 ms | 1,282 ms |
| **write path** (wall − instance floor) | ~12,800 ms | **183 ms median / 300 ms p95** |
| schema sequential scans per create | 3,019 | 23–36 |
| event dispatch inside the create | 1,900 ms | 6–8 ms (deferred) |
| DB insert | 76 ms | 76 ms (never the problem) |

**The write path is inside the 500 ms budget. Wall-clock is not, and the gap
is not the write path.**

An authenticated Nextcloud request that does *no object work at all*
(`/ocs/v2.php/cloud/capabilities`) costs **864–1,099 ms** on this instance.
Nextcloud boots every enabled app on every request, and this instance has 92
enabled. That floor is larger than the entire budget, so no amount of work
here can bring wall-clock under 500 ms. It is PHP-side, not database: sampling
`pg_stat_activity` across boot-only requests showed almost no queries, and
opcache is healthy (3,395 cached scripts of 16,229 keys, 0 OOM restarts, 0
hash restarts, 91 % hit rate) — so it is not cache thrashing either.

**Consequence for the requirement:** the 500 ms budget must be stated against
the write path's own cost, with the instance floor measured and subtracted.
The harness does this and reports both. Judging wall time alone measures how
many apps the machine has installed.

### What actually cost the time

Not what the proposal predicted. The proposal blamed schema resolution, the
2,728-branch union, and the transaction count. The first two were real but
their *causes* were elsewhere, and both were in the event-dispatch path rather
than in storage:

1. **96 % of all schema reads came from DocuDesk**, not OpenRegister. Its
   `EnrichmentRunner` listens on object-created and, to answer the boolean
   "is enrichment enabled", called `getAllSettings()` — the admin-settings
   payload, which walks every register and every schema. 1,471 `find()` calls
   per create, on 1,471 *distinct* schemas, so no cache could have helped.
   Fixed in docudesk `0495b9ec`.

2. **The 2,728-table union was reached through a widened fallback.**
   `ObjectService::find()` retries on a miss and was dropping the caller's
   register as well as its schema, so a legitimate "not in this register"
   answer was produced by scanning the whole instance. The flow-resolver
   registry asks every resolver in turn, and each non-owning one paid that
   scan to say "not mine".

3. **The remaining cost was ~22 event listeners**, not the insert. The insert
   is 76 ms; the dispatch was 234–501 ms.

Task 7 (one transaction) is still open and still worth doing — the commit
count is 132 per create, down from 12,541 but nowhere near 1.

Ordered by measured payoff. Each task states the number it must move and how
that number is read, so "done" is a measurement rather than an opinion.

## Measurement harness (do this first, it gates everything else)

- [x] **Task 0 — a repeatable benchmark.**
      `tests/perf/object-create.sh`: warms the instance, then times N creates
      against a fixed register/schema, capturing per-run wall time plus the
      counter deltas used throughout this change:

      ```sql
      SELECT seq_scan FROM pg_stat_user_tables WHERE relname='oc_openregister_schemas';
      SELECT seq_scan FROM pg_stat_user_tables WHERE relname='oc_openregister_registers';
      SELECT xact_commit FROM pg_stat_database WHERE datname='nextcloud';
      ```

      Report min/median/p95, not a single run — the baseline spread was
      13.6 s → 99.1 s on identical payloads.

      ⚠️ Never sample `pg_stat_activity` from a client whose stdout is a pipe
      that can close under it. Doing so during this investigation SIGPIPE'd a
      backend and took the whole cluster into recovery (`server process was
      terminated by signal 13: Broken pipe` → `all server processes
      terminated; reinitializing`, ~40 s outage). Redirect to files.

      Baseline to beat, recorded 2026-07-28 on `larpingapp/character`:
      13.6 / 17.8 / 20.4 / 41.0 / 62.8 / 99.1 s; 5,135 schema seq scans;
      12,541 commits.

## Schema resolution — the largest single cost

- [x] **Task 1 — attribute the 5,135 `find()` calls.**
      Add a counter + sampled backtrace behind an app-config flag
      (`perf_trace_schema_reads`), run one create, and produce a call-site
      histogram. The fix depends entirely on which of these it is:
      - the same schema re-resolved under different `(_rbac, _multitenancy)`
        flag combinations (cache key too specific → normalise the key);
      - an uncached sibling (`findAll` / `findMultiple` / `findBySlug` /
        `findBySlugInIds`) on the hot path (→ route through the identity map);
      - a genuine walk of thousands of distinct schemas (→ the caller is
        wrong, not the mapper).
      **Do not skip to task 2 before this histogram exists.** Guessing here is
      how the CloudEvent recursion went unnoticed for so long.

- [ ] **Task 2 — one identity map in front of every schema read.**
      Every path that returns a `Schema` goes through a single request-scoped
      map keyed on the schema **id**, with id/uuid/slug all resolving into it,
      independent of the `_rbac`/`_multitenancy` flags (those govern
      *visibility of the result*, not *which row is loaded* — apply them after
      the map, never as part of its key). Target: ≤ 5 schema reads per create.

- [ ] **Task 3 — make the miss path cheap.**
      Only for reads that genuinely miss the map:
      - stop `SELECT *` where only metadata is needed — the `properties` blob
        is ~2 KB per row and is hydrated 5,135 times today;
      - add a functional index `ON oc_openregister_schemas (LOWER(slug))` so
        the slug arm stops forcing a seq scan (confirmed: `Rows Removed by
        Filter: 1916`);
      - split the three-way `uuid OR LOWER(slug) OR id` disjunction into the
        one arm the caller actually has — a disjunction over three columns
        cannot use an index on any of them.

- [ ] **Task 4 — cross-request cache.**
      APCu layer keyed on `schema.id + schema.updated`, so a cold worker does
      not repay the whole cost. Invalidated by the existing
      `clearFindCache()` mutation hook, extended to also drop the APCu entry.

## Reference resolution — the 4-second query

- [x] **Task 5 — stop reaching the instance-wide fan-out from the write path.**
      SOLVED, but not by the mechanism written here. The fan-out was not being
      reached through untyped relation properties at all — it was reached
      through `ObjectService::find()`'s cross-schema fallback, which dropped
      the caller's REGISTER along with its schema on a miss. It now keeps the
      register, and `MagicMapper` accepts a `registerIdScope` that filters
      candidate tables. One create now issues no instance-wide union.

      The originally-specified work — resolving a `$ref` property against its
      declared schema's table — is still worth doing for read paths, and is
      NOT done. It is folded into task 6.

- [ ] **Task 6 — replace the global fan-out with an index table.**
      For genuinely untyped references, `oc_openregister_object_index
      (uuid, slug, uri, register_id, schema_id, deleted_at)`, maintained on
      write, so an unknown reference is one index probe. Backfill migration +
      a repair step for existing rows. The 2,728-branch `UNION ALL` is then
      deleted, not optimised — 86 % of its cost is planning, which no index
      can touch.

## Transaction and post-write work

- [ ] **Task 7 — one transaction per write.**
      Wrap the create/update path in a single `beginTransaction()` /
      `commit()`. Target: `xact_commit` delta of 1, down from 12,541.
      Note the failure mode already hit once in this codebase: a partially
      committed multi-step write left orphaned schema rows behind when a later
      step failed (fixed in the schema-dedup command by the same means).

- [x] **Task 8 — move post-write work behind the commit.**
      CloudEvent fan-out, audit-trail hash sealing, notification history and
      `oc_activity` writes move into a `QueuedJob` dispatched after commit.
      Requirements:
      - the job is dispatched only after the transaction commits (never from
        inside it — a rollback must not leave a queued job for a write that
        did not happen);
      - failure of the job never fails the write;
      - ordering per object is preserved.

      DONE for `ObjectCreatedEvent`, opt-in via `defer_object_events`, off by
      default (a `sync` flow's whole point is that its effects land before the
      save returns, so this cannot be flipped on for everyone by fiat).

      ⚠️ **The job MUST carry the acting user.** The first version did not, and
      it was a flawless no-op: the job ran, threw nothing, logged nothing, and
      produced ZERO CloudEvents where the inline path produced one. A
      background job has no session, and OpenRegister reads are
      organisation-filtered against the session user, so every listener that
      consults the register saw an empty instance and skipped. Deferring side
      effects without carrying identity does not move the work — it deletes it.
      Verified after the fix: 1 CloudEvent, matching inline.

      Still to defer: update and delete events, audit sealing, activity.

- [ ] **Task 9 — specify the new consistency contract.**
      A 201 now means "persisted", not "persisted and fanned out". State it in
      the spec, and give callers who need the old guarantee a documented way to
      wait. This is the one caller-visible change in this change; it is a
      contract, not an implementation detail.

## Verification

- [ ] **Task 10 — gate the budget.**
      Task 0's harness runs in CI against a seeded instance and fails the
      build when p95 exceeds 500 ms, so this cannot regress silently the way
      it accumulated.

- [ ] **Task 11 — re-measure the full matrix.**
      Create/update/delete × (no relations, typed relations, untyped
      references) × (cold worker, warm worker). Record the same three counters
      per cell. Ship the table in the change's completion note.

- [ ] **Task 12 — verify through the UI, not just the API.**
      An API-green measurement is not a UI-green measurement. Confirm on a
      fresh browser session against the live instance that a create through
      the actual form returns inside the budget.

## Housekeeping (independent of the budget, worth doing here)

- [ ] **Task 13 — purge the 46,227 accumulated CloudEvents.**
      99.3 % of them were generated *from other events* by the recursion
      defect fixed in openconnector#1086. They are junk, not history. Purge
      with an explicit `WHERE source = '/objects/com.nextcloud.openregister
      .object.created'` predicate — SELECT and eyeball the count before
      deleting anything.
