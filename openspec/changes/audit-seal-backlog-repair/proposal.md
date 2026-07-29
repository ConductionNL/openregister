---
kind: code
---

## Why

On the shared development instance, **108,151 of 227,063 audit-trail rows (48%)
carry no hash**. They are not pre-migration leftovers: the unsealed ids span the
entire table (31,518 → 290,493 — exactly `min(id)` → `max(id)`), so they are
interleaved with sealed rows throughout, and many were written the same day this
was measured.

Three separate defects produced that state and keep it from being repaired.

### 1. The write path seals one row at a time under a global exclusive lock

`AuditTrailMapper::insertHashChained()` (`lib/Db/AuditTrailMapper.php:133`) calls
`AuditHashService::sealRow()` **per insert**. Each `sealRow()` acquires the
instance-wide exclusive advisory lock `openregister/audit-seal` and runs three
queries (row `SELECT`, predecessor `SELECT`, `UPDATE`).

Sealing is deliberately fail-soft: `acquireSealLock()` tries
`SEAL_LOCK_ATTEMPTS = 3` times with `SEAL_LOCK_RETRY_DELAY_USEC = 50000` between
attempts, then gives up and leaves the row unsealed. So under any concurrency —
two background jobs, a bulk import, a repair pass — every insert pays up to
150 ms of lock waiting and then **abandons the seal anyway**.

This is what made `occ maintenance:repair` unusable. The repair steps themselves
do not seal (no class under `lib/Repair/` references `AuditHashService`); they
write objects, and every resulting audit insert paid the lock cost. Measured
throughput while it ran: **~152 rows/min (~2.5 rows/sec)**, with ~12 hours of
work remaining. It was killed after 74 minutes.

The batched counterpart `sealRows()` — which takes the lock **once** for a whole
batch — already exists and is used at `AuditTrailMapper.php:716`. Single inserts
simply never reach it.

### 2. The backfill the spec promises does not exist

`openspec/specs/audit-hash-chain/spec.md:105` already requires:

> **Scenario: Legacy pre-cutover rows are sealed by backfill**
> - **WHEN** the backfill repair step runs against pre-existing null-hash rows
> - **THEN** each row is sealed with `previousHash`/`hash` in insertion order
> - **AND** re-running the step is a no-op (already-hashed rows are skipped)

There is no such step. No repair class, background job or `occ` command in the
app seals a backlog. The requirement is green-but-dead, which is why 108k rows
have accumulated with nothing able to drain them.

The gap is inherited directly from the change that introduced the lock.
`openspec/changes/harden-audit-seal-concurrency` (complete, 12/12 tasks, not yet
archived) made lock acquisition bounded and fail-soft, and its task 1.2 records
the trade-off in as many words:

> on sustained contention log a warning and leave rows unsealed (**later seal
> pass chains them**) instead of blocking the write path

That design is sound *provided* the later seal pass exists. It does not. This
change is the missing half: it builds the pass that the fail-soft behaviour has
been assuming since it shipped. Without it, every contended write permanently
degrades the chain, which is precisely the accumulation measured above.

### 3. `verifyChain()` reports `valid: true` over the unsealed rows

`AuditHashService::verifyChain()` skips any row whose hash is null or empty
(`lib/Service/AuditHashService.php:522` — `$skippedNullHashes++; continue;`) and
still returns `valid: true`. The inline comment calls these "pre-migration
entries", but **no cutover marker exists anywhere in `lib/`**, so the skip
applies to every unsealed row regardless of when it was written.

The spec explicitly forbids this (`spec.md:98`):

> **Scenario: Post-cutover null hash is a break, not a skip**
> - **THEN** it returns `valid: false`
> - **AND** it does NOT silently count the row as `skippedNullHashes`

The practical effect is the serious one: the tamper-evidence check currently
answers "valid" on a table where **48% of rows are unverified**. A row that was
never sealed is indistinguishable from a row an attacker unsealed. This makes
the backlog an integrity problem, not merely a performance one.

## What Changes

- Add a **bulk seal driver** that drains the unsealed backlog in bounded,
  resumable windows, in ascending id order, taking the seal lock **once per
  window** instead of once per row.
- Expose it as both an `occ` command (operator-triggered, observable) and a
  bounded **background job** (unattended drain), so a fail-soft skip on the write
  path is always eventually repaired. This implements the backfill requirement
  that `spec.md:105` already states.
- Make the driver **skip work that is not needed**: the cursor selects only
  unsealed rows, and already-sealed rows are never re-hashed (re-hashing a sealed
  row would rewrite a valid link and manufacture a tamper alarm).
- Add a **partial index** on the unsealed predicate. Measured today, the backlog
  cursor cannot use `idx_audit_hash` — it degrades to a primary-key index scan
  with a filter, costing **784 ms per 2,000 ids**.
- Fix the **memory hazard** that makes a naive bulk call impossible: cap window
  span and row count, and stop fetching whole rows for rows that only contribute
  a chain link.
- Make the backlog **observable**: report unsealed counts from `verifyChain()`
  rather than silently skipping, and stop returning `valid: true` when
  post-cutover rows are unsealed.

Explicitly **not** in scope: removing the seal from the write path, weakening the
lock, or changing the hash algorithm or canonical-JSON payload. The chain must
keep verifying exactly as it does today.

## Capabilities

### Modified Capabilities

- `audit-hash-chain`: gains a bounded, resumable, idempotent bulk seal pass
  (command + background job) that drains unsealed rows in chain order; gains an
  explicit rule that already-sealed rows are never re-hashed; and tightens
  verification so unsealed rows are surfaced instead of silently skipped.

## Impact

- **Integrity**: the tamper-evidence check stops over-reporting. This is the
  reason to do the work; expect `verifyChain()` to start returning `valid: false`
  on instances with a backlog until it is drained. That is the correct answer,
  but it is a visible behaviour change for anyone consuming the endpoint.
- **Performance**: one lock acquisition and two queries per window replace one
  lock acquisition and three queries per row.
- **Risk**: the driver writes `hash`/`previous_hash` on audit rows. A bug that
  seals a row against the wrong predecessor produces a false tamper alarm on the
  next `verifyChain()`. Every requirement below is written to make that
  impossible: ascending order, one writer at a time, never touch a sealed row.
- **Data**: no schema change beyond one additive index. No rows deleted, no
  payload rewritten.
