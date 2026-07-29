# Tasks — Object writes under 500 ms

Ordered by measured payoff. Each task states the number it must move and how
that number is read, so "done" is a measurement rather than an opinion.

## Measurement harness (do this first, it gates everything else)

- [ ] **Task 0 — a repeatable benchmark.**
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

- [ ] **Task 1 — attribute the 5,135 `find()` calls.**
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

- [ ] **Task 5 — resolve typed relations against their declared schema.**
      When a property declares `$ref` / a target schema id, resolve the
      reference against **that** schema's magic table only. `character`'s six
      relation properties all declare theirs. One branch instead of 2,728
      turns a 3.4 s plan into a sub-millisecond index probe.

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

- [ ] **Task 8 — move post-write work behind the commit.**
      CloudEvent fan-out, audit-trail hash sealing, notification history and
      `oc_activity` writes move into a `QueuedJob` dispatched after commit.
      Requirements:
      - the job is dispatched only after the transaction commits (never from
        inside it — a rollback must not leave a queued job for a write that
        did not happen);
      - failure of the job never fails the write;
      - ordering per object is preserved.

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
