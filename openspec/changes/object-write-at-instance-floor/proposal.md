# Object writes at the instance floor

## Why

`object-write-sub-500ms` got a create from **13,688 ms to 322 ms median /
476 ms p95**. The 500 ms budget is met. This change closes the remaining gap.

The instance floor — an authenticated request that does no object work — is
**172–213 ms** on the development instance. A create is therefore
**~110–260 ms of our own work** on top of it, and we now know exactly what that
work is, because it was measured rather than guessed.

Target: **the write path costs ≤ 50 ms above the floor**, i.e. a create lands
at roughly 220–260 ms on this instance. Not zero — a write has to write — but
close enough that the object API stops being the thing anyone profiles.

### What the remaining cost actually is

Full PostgreSQL statement logging of one create (2026-07-29, scoped to the
request's backend and time window): **326 statements, 176.8 ms of statement
time**.

| n | ms | statement | fix |
|---|---|---|---|
| 57 | 44.7 | `SELECT * FROM oc_openregister_schemas WHERE uuid=? OR LOWER(slug)=? OR id=?` | task 1–3 |
| 24 | 3.2 | register lookups (same shape) | task 3 |
| 18 | 0.8 | `SELECT lastval()` | task 5 |
| 9 | 4.7 | `SELECT 1 FROM information_schema.tables WHERE table_name=?` | task 4 |
| — | — | 2 audit rows + hash-chain `UPDATE`, 2 notification rows | task 6 |

Plus **~130–141 committed transactions per create** where there should be one,
each an fsync the request waits on.

The schema query is a sequential scan **by construction**: `SELECT *` hydrates
a ~2 KB `properties` blob and `LOWER(slug)` cannot use an index on `slug`
(`Rows Removed by Filter: 1916` against 1,929 rows).

### Per-request work outside the write

Auditing all 24 apps' `boot()` and `register()` (ADR-076) found the write path
is not the only thing paying:

- **pipelinq** builds an app-store lookup on every request:
  `resolveDependencyStatuses()` → `buildAppStoreLookup()` → `AppFetcher::get()`,
  which iterates the **3.4 MB** appstore catalogue. It is free on this instance
  **only because `has_internet_connection=false`** makes the fetcher return an
  empty set. Turn internet on and every request parses 3.4 MB. It also computes
  `provideInitialState()` on API requests, which never render a UI.
- **openconnector** invokes a repair step from `boot()`
  (`ensureRegisterBootstrapped`). It is correctly gated *and* persists its key,
  so it costs nothing today — and is one cleared config key away from running a
  repair step on every request. ADR-076 rule 4 says that fallback belongs in a
  cron job.
- **OpenRegister registers 31 cron jobs, 8 of them at 60 s.** An idle instance
  was measured doing 18 schema sequential scans and 356 commits per 4 seconds.
  That is the noise floor every other measurement fights, and it is self-inflicted.

### Why it got slow in the first place

Worth stating, because it predicts the next regression. Nothing here was a bad
commit. Three things grew together:

| | early | now |
|---|---|---|
| listeners on `ObjectCreatedEvent` | ~1–2 | **63, across 14 apps** |
| schemas / magic tables | handful | **1,929 / 2,728** |
| enabled apps | a few | **92** |

Code paths that are O(tables) or O(schemas) cost nothing at 20 and seconds at
2,728. The 2,728-branch `UNION` was 690 KB of SQL whose cost was **86 %
planning** — invisible to indexing, and only reachable because a fallback
dropped the caller's register.

## What Changes

Ordered by measured payoff. Each item states the number it must move.

1. **Schema resolution: one identity map, one cheap miss path.** 57 reads → ≤ 5.
2. **Stop probing `information_schema` per insert.** 9 → 0.
3. **One transaction per write.** ~135 commits → 1.
4. **Defer the remaining side effects.** Update/delete events, audit sealing,
   notification history — created is already deferred behind
   `defer_object_events`; finish the set and resolve whether it becomes default.
5. **Kill the fan-out permanently** with a `uuid → (register, schema)` index,
   so the register-scoped fallback stops being the only thing standing between
   us and a 690 KB statement.
6. **Fix pipelinq's per-request app-store lookup and API-request initial state.**
7. **Move openconnector's repair-step fallback to cron** (ADR-076 rule 4).
8. **Justify or widen the 60 s cron intervals** so the measurement floor stops
   moving under us.

Non-goals: no change to the magic-table storage model, no change to the public
write contract beyond the already-specified deferral contract, no reduction in
what is eventually persisted.

## Impact

- **Affected specs**: extends `object-write-performance` (from
  `object-write-sub-500ms`) with a floor-relative budget and the counter bounds
  it still fails.
- **Affected code**: `lib/Db/SchemaMapper.php`, `lib/Db/RegisterMapper.php`,
  `lib/Db/MagicMapper.php`, `lib/Service/Object/SaveObject.php`,
  `lib/BackgroundJob/`, a new migration for the identifier index; plus
  `pipelinq/lib/AppInfo/Application.php` and
  `openconnector/lib/AppInfo/Application.php` in their own repos.
- **Affected apps**: every app writes through this path, so this is a fleet-wide
  latency change. Behaviour is unchanged except where the deferral contract
  (already specified) applies.
- **Risk**: task 3 (one transaction) is the highest — widening a transaction
  around a write that currently autocommits changes lock duration and failure
  atomicity. It is also where a partially-committed multi-step write already bit
  this codebase once (orphaned schema rows during the #2150 dedup), which is the
  argument *for* doing it, carefully.
