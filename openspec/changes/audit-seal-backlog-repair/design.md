# Design — audit seal backlog repair

## Measurements this design is built on

All taken on the shared dev instance (Postgres 16, `oc_openregister_audit_trails`)
on 2026-07-28.

| Fact | Value | Why it constrains the design |
|---|---|---|
| Total rows | 227,063 | — |
| Sealed | 118,912 (52%) | half the range contributes only a chain link |
| Unsealed | 108,151 (48%) | the backlog to drain |
| Unsealed id span | 31,518 → 290,493 | equals `min(id)`→`max(id)`: **interleaved, not a tail** |
| Table size | 1,141 MB | — |
| Average row | **5,270 B** | payload columns are large and TOASTed |
| Backlog cursor | pkey Index Scan + Filter, **784 ms / 2,000 ids** | `idx_audit_hash` is NOT used |
| Observed seal rate (per-row path, contended) | **~152 rows/min** | ~12 h for this backlog |

The two numbers that drive everything: rows are **big** (5.3 KB) and unsealed rows
are **interleaved across the whole table**.

## Q1 — How do we do a bulk repair at all?

`sealRows(int[] $ids)` already exists and is correct: it takes the lock once,
range-`SELECT`s `[min(ids), max(ids)]`, walks the range in id order adopting
stored hashes for sealed rows and computing hashes for unsealed ones, then issues
a single `CASE`-based `UPDATE`.

**It cannot be called with the whole backlog.** `sealRowsLocked()` does
`select('*')` + `fetchAll()` over the entire `[min,max]` span. With
`min=31,518` / `max=290,493` that is ~227,000 rows × 5,270 B ≈ **1.14 GB
materialised in PHP** — a guaranteed OOM. This is the single most important
constraint, and it is why the fix is a *driver around* `sealRows()`, not a call
to it.

So the bulk repair is a **windowed driver**:

1. Read the next page of unsealed ids, ascending (`ORDER BY id ASC LIMIT n`).
2. Cut a window bounded by **both** row count and id span (see Q2).
3. Call `sealRows()` for that window.
4. Repeat from the last id processed. Each window is committed independently, so
   the pass is resumable and interruptible.

**Ascending order is mandatory, and the pass cannot be parallelised.** Row *N*'s
`previousHash` is row *N−1*'s `hash`. Two workers sealing different windows
concurrently would each call `getHashBefore()` against a region the other is
still writing, chaining a link over a stale predecessor — a false tamper alarm.
The existing exclusive lock already serialises this; the driver must not try to
work around it.

## Q2 — How do we make it fast?

Four changes, in order of measured impact.

### a. One lock per window, not per row (the main win)

Today: 1 lock acquisition + 3 queries **per row**, and up to 150 ms of retry
before giving up. Windowed: 1 lock acquisition + 3 queries **per window**. At a
2,000-row window that is a ~2,000× reduction in lock traffic.

### b. Two-phase fetch — stop reading whole rows we do not hash

`sealRowsLocked()` currently fetches full rows for the entire span, but a
**sealed** row contributes exactly one thing: its stored `hash`. Its 5.3 KB
payload is read and discarded. With 52% of rows sealed, roughly half the bytes
are wasted, and in a sparse region it is far worse.

Split the read:

1. `SELECT id, hash FROM ... WHERE id BETWEEN :min AND :max ORDER BY id ASC`
   — narrow, no TOAST detoast, gives the full chain skeleton.
2. `SELECT * FROM ... WHERE id IN (:unsealed_ids)` — full payloads **only** for
   rows that will actually be hashed.

This also largely defuses the span hazard: bytes fetched now scale with the
number of *unsealed* rows in the window, not with the window's id span.

### c. A partial index for the backlog cursor

The cursor query cannot use `idx_audit_hash` — the `hash IS NULL OR hash = ''`
disjunction defeats it, and the plan degrades to a primary-key scan with a
filter at **784 ms per 2,000 ids**. Over 108k rows that is ~42 s of pure cursor
overhead, and it grows as the sealed prefix lengthens, because the scan must skip
every sealed row it has already passed.

Add:

```sql
CREATE INDEX idx_audit_unsealed
    ON oc_openregister_audit_trails (id)
    WHERE hash IS NULL OR hash = '';
```

The index only contains backlog rows, so it shrinks toward empty as the drain
progresses — the cursor gets *faster* over time instead of slower. Create it via
a standard migration; the additive index is the only schema change in this
change.

### d. Window sizing

Bound each window by **both** limits, whichever is hit first:

- `MAX_WINDOW_ROWS = 2000` unsealed rows (≈ 10.5 MB of payload at 5,270 B/row)
- `MAX_WINDOW_SPAN = 20000` ids

The span cap matters because of interleaving: 2,000 unsealed ids scattered across
a 200,000-id range would make phase-1 read a 200,000-row skeleton. Capping the
span keeps every window's cost bounded regardless of how sparse the region is.

**Expected result.** Per window: one lock, one narrow range read, one payload read
of ≤2,000 rows, one `CASE` update. The dominant cost becomes the payload read,
not lock contention — turning a ~12-hour serial crawl into a pass measured in
minutes. The tasks require this be *measured* on the real backlog rather than
asserted.

## Q3 — How do we avoid repairing rows that do not need it?

Three independent guards, because getting this wrong corrupts the chain:

1. **The cursor only selects unsealed rows.** Sealed rows are never candidates.
2. **`sealRowsLocked()` already refuses to re-hash a sealed row** — it adopts the
   stored hash as the next link and `continue`s, so a sealed row never enters the
   `$updates` map. This behaviour is currently implicit; this change makes it an
   explicit, tested requirement.
3. **The `UPDATE` is keyed to the computed id list**, so even a wrong window
   cannot write outside it.

This is not merely an optimisation. Re-hashing an already-sealed row would
recompute its `previousHash` against whatever the current chain looks like and
overwrite a valid link — manufacturing exactly the tamper alarm the chain exists
to detect. **Never re-seal a sealed row** is a correctness rule, not a
performance rule.

Idempotency falls out of this: a second run finds an empty cursor and does
nothing, satisfying the existing `spec.md:109` requirement that re-running the
backfill is a no-op.

## Q4 — Should it be a cron job?

**Yes — as a bounded drain, and it must not be the only mechanism.**

Three roles, deliberately separated:

| Mechanism | Role | Bound |
|---|---|---|
| Per-insert `sealRow()` | seals the common single-write case immediately | unchanged |
| Batched `sealRows()` on bulk paths | seals imports/mass writes at batch granularity | per batch |
| **New `SealAuditBacklogJob` (TimedJob)** | drains whatever fail-soft left behind | **hard cap per run** |

Reasoning:

- **Why a job is needed.** Sealing is fail-soft by design — on contention the row
  is left unsealed and the code comments promise "a later seal pass picks it up".
  No such pass exists. The job *is* that promise, and without it every contended
  write permanently degrades the chain.
- **Why the write path keeps sealing.** Deferring all sealing to cron would widen
  the window in which a row is unverifiable, and on an instance where cron is
  broken the chain would silently stop being tamper-evident. Note this fleet has
  already been bitten by exactly that: one poison job bricking cron fleet-wide.
  Immediate sealing stays the default; the job is the safety net.
- **Why it must be hard-bounded.** The incident that started this work was an
  unbounded maintenance pass that ran for 74 minutes and had ~12 hours left. The
  job MUST cap work per invocation (both rows and wall-clock) and exit cleanly,
  leaving the rest for the next tick. A background job that cannot finish is the
  failure mode being fixed, and must not be reintroduced.
- **Why an `occ` command too.** Operators need to drain a large backlog
  deliberately, with progress output, without waiting for cron ticks — and to
  reproduce/measure the fix. The command and the job share one driver class so
  they cannot diverge.

The job must also **not fight the write path for the lock**. Because it is
background work it should retry more patiently than a user-facing insert, but
still yield rather than starve writers: bounded attempts, and abandon the window
(not the pass) when the lock stays busy.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Backlog drain | **Imperative** (`TimedJob` + `Command` + driver service) | ADR-031's explicit exception for *scheduled bulk work*. This is not a derived field, aggregation or lifecycle transition; it rewrites integrity columns on historical rows under an advisory lock in strict sequential order. No declarative construct can express chain-ordered, lock-serialised bulk mutation. |

## Verification surfacing

`verifyChain()` skips null-hash rows and still returns `valid: true`
(`AuditHashService.php:522`). The comment attributes this to "pre-migration
entries", but no cutover marker exists in `lib/`, so it applies to every unsealed
row — including ones written minutes ago.

This change introduces an explicit cutover marker (persisted app config, set at
migration time) so the two populations can finally be told apart:

- unsealed rows **before** the marker → legacy, counted as `skippedNullHashes`,
  do not fail the chain (preserves current behaviour for genuine legacy data)
- unsealed rows **after** the marker → a break: `valid: false`, as
  `spec.md:98` already requires

`verifyChain()` gains an `unsealedAfterCutover` count so the backlog is visible
in the response rather than hidden behind a passing boolean.

## Seed data

Not applicable — this change introduces no OpenRegister schemas or registers. It
operates on the existing `openregister_audit_trails` table only.

## Rollout note

On any instance with an existing backlog, `verifyChain()` will begin returning
`valid: false` once the cutover marker is in place and before the drain
completes. That is the honest answer, but it is a visible change for consumers of
the verification endpoint. The migration sets the marker to the time it runs, so
pre-existing rows are classified as legacy and the alarm reflects only rows that
*should* have been sealed by the current code.
