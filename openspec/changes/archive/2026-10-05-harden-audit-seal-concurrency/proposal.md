---
kind: code
---

## Why

Two follow-ups from the write-path performance wave (PR #399 review):

1. **Seal passes race each other (openregister#406).** `AuditHashService::
   sealRow()` and `sealRows()` both read a predecessor hash
   (`getHashBefore()`) and then UPDATE. Two interleaved passes — a
   concurrent writer whose row lands inside a batch's id range, or a
   boundary row sealed between another pass's predecessor read and its
   UPDATE — can chain one link over a stale predecessor. The next
   `verifyChain()` then reports a break at that boundary: a FALSE tamper
   alarm (not tamper-hiding — resealing recomputes deterministically).
   Pre-existing in `sealRow()`, widened to the batch duration by
   `sealRows()`.

   On top of the race, `getHashBefore()` had a documented weakness: when
   the immediately-prior row is UNSEALED (a fail-soft leftover), the next
   seal chained from genesis while `verifyChain()`'s walk skips null-hash
   rows and carries the last SEALED hash forward — a PERMANENT false break
   at that link.

2. **Dead ChunkProcessingHandler (openregister#407).** `lib/Service/Object/
   SaveObjects/ChunkProcessingHandler.php` has zero callers in `lib/` and
   `appinfo/` — `SaveObjects` performs chunk processing through its own
   internal `processObjectsChunk()`. The class is an orphaned capability:
   implemented, tested, DI-resolvable, and invoked by nothing.

## What Changes

- Serialize ALL seal passes under one exclusive advisory lock
  (`OCP\Lock\ILockingProvider`, key `openregister/audit-seal`) taken by
  both `sealRow()` and `sealRows()`. A well-known advisory lock is used
  instead of `SELECT ... FOR UPDATE` because the primary race is a row
  INSERTed into the range by a concurrent uncommitted transaction — row
  locks cannot lock a row that does not exist yet (and gap locking is
  engine-specific), while a single advisory key serializes the passes
  themselves and cannot deadlock with the surrounding request transaction.
- Lock acquisition is bounded (3 attempts, 50ms apart) and fail-soft:
  on sustained contention the pass logs a warning and leaves the rows
  unsealed — a later seal pass chains them — instead of blocking the
  write path. The lock is always released, including when the seal pass
  throws.
- `getHashBefore()` now returns the hash of the nearest PRIOR **SEALED**
  row (filtering `hash IS NULL` / `hash = ''`), exactly mirroring
  `verifyChain()`'s skip-null walk. Sealing "row N unsealed, row N+1
  sealed" now verifies instead of permanently breaking. This also fixes
  ranged verification (`verifyChain(from: ...)`) starting right after an
  unsealed row.
- Delete dead `ChunkProcessingHandler` (class + unit tests + integration
  test section); correct the spec references that presented it as the
  bulk chunking implementation (`SaveObjects` itself chunks).

## Impact

- Affected specs: `audit-hash-chain` (serialization requirement made
  concrete + new nearest-sealed-predecessor requirement, via delta);
  `object-lifecycle` REQ-004, `data-import-export`, and
  `method-decomposition` (implementation references corrected from
  `ChunkProcessingHandler` to `SaveObjects`' internal chunk processing —
  edited canonically because their `REQ-NNN` heading style cannot be
  targeted by a MODIFIED delta).
- Affected code: `lib/Service/AuditHashService.php`;
  `lib/Service/Object/SaveObjects/ChunkProcessingHandler.php` (deleted);
  `tests/Unit/Service/AuditHash*`, `tests/Unit/Service/Object/SaveObjects/
  ChunkProcessingHandlerTest.php` (deleted), `tests/Service/
  SaveObjectHandlersIntegrationTest.php`.
- No API or schema changes. `AuditHashService`'s constructor gains
  `ILockingProvider` + `LoggerInterface` (both DI-autowired).
- Failure-mode change: under seal-lock contention rows are left unsealed
  (warning logged) instead of risking a false tamper alarm; unsealed rows
  are chained over by both sealing and verification.
