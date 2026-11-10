## 1. Bound the eligibility scan

- [ ] 1.1 Rewrite `RetentionService::findEligibleForDestruction()` (`lib/Service/RetentionService.php:621-690`) to page the `retention IS NOT NULL` query in fixed batches (e.g. 500), mirroring `DestructionCheckJob::sendPreDestructionNotifications()`.
- [ ] 1.2 Replace the per-row `objectMapper->find()` (`:643`) with a batch fetch of the needed scope for each page (`IN (...)`).

## 2. Idempotent destruction execution

- [ ] 2.1 In `DestructionExecutionJob::run()`, before iterating batches, atomically compare-and-set the list status `approved` → `executing` (single conditional UPDATE). If the CAS does not affect a row, exit (another run owns it).
- [ ] 2.2 Set `executed` at the end as today; on failure, set a recoverable state that a retry can re-claim without double-processing already-deleted UUIDs.
- [ ] 2.3 (Optional) Add a short-lived lock row keyed by `destructionListUuid` as defence-in-depth.

## 3. Remove dead mutation

- [ ] 3.1 Delete the `archiefstatus = 'vernietigd'` in-memory set at `:157-159`, OR persist it (and confirm it feeds the audit `changed` diff) if that was the intent.

## 4. Verification

- [ ] 4.1 Test: eligibility scan over a large retained set processes in bounded memory (assert batch sizing, no full-table load).
- [ ] 4.2 Test: two concurrent `DestructionExecutionJob` runs for the same list → exactly one processes; no object is deleted twice; no duplicate audit rows.
- [ ] 4.3 `composer check:strict` passes.

## Acceptance criteria

- `findEligibleForDestruction()` never loads the full retained-object set at once.
- A duplicated/retried destruction job cannot double-delete or double-audit.
- No dead pre-delete mutation remains.
