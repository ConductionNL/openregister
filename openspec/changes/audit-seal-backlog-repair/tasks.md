# Tasks — audit seal backlog repair

## 1. Schema and marker

- [x] Add a migration creating the partial index `idx_audit_unsealed` on
      `oc_openregister_audit_trails (id) WHERE hash IS NULL OR hash = ''`
      - built as `WHERE hash IS NULL`, the predicate the sweep (`getEarliestUnsealedId`, `countUnsealed`) uses; PostgreSQL and SQLite only (Version1Date20261113210000)
      - the backlog cursor currently degrades to a pkey scan + filter (784 ms / 2,000 ids)
      - the index shrinks as the backlog drains, so the cursor speeds up over time
- [x] Persist a hash-chain cutover marker in the same migration (app config,
      set to the migration's run time)
      - existing instances classify their current backlog as legacy, not as an alarm
      - built as its own step, Version1Date20261124193002: the index step had already shipped, so a marker added to it would never run on an upgraded instance; stored as `audit_seal_cutover_id`, the first audit id written after the step (ids follow write order exactly, a timestamp carries the server clock); written once, never moved

## 2. Driver

- [ ] Add `AuditSealBacklogService` — the single driver shared by the command and the job
- [ ] Implement the ascending unsealed-id cursor using the partial index
- [ ] Implement window cutting bounded by BOTH `MAX_WINDOW_ROWS` and `MAX_WINDOW_SPAN`
      - a sparse region must not widen the range read
- [ ] Drive `AuditHashService::sealRows()` once per window, committing per window
      - windows are sequential; never concurrent (chain order is mandatory)
- [ ] Return per-pass counters (rows sealed, windows processed, windows abandoned on lock)

## 3. Read-path efficiency

- [x] Split `sealRowsLocked()` into a narrow `id, hash` skeleton read over the range
      plus a full-row read restricted to the unsealed ids
      - built as `linksBetween()` + `withUnsealedPayloads()`; the payload read is chunked at 1000 ids
      - sealed rows contribute only a chain link; their ~5.3 KB payload must not be fetched
      - keeps bytes read proportional to unsealed rows, not to the window's id span

## 4. Surfaces

- [ ] Add `occ openregister:audit:seal-backlog` with progress output and a `--limit` option
- [ ] Add `SealAuditBacklogJob` (TimedJob) capped by both row count and wall-clock,
      exiting cleanly at the cap
      - the incident being fixed was an unbounded pass; do not reintroduce one
- [ ] Register the command and the job, and ensure the job yields the seal lock
      rather than starving foreground writes

## 5. Verification

- [x] Classify null-hash rows in `verifyChain()` against the cutover marker:
      pre-cutover counted as `skippedNullHashes`, post-cutover returns `valid: false`
- [x] Report the post-cutover unsealed count in the `verifyChain()` result
      - `unsealedAfterCutover`, plus `sealCutoverId` (null without a marker, which keeps the old behaviour)

## 6. Tests

- [ ] Unit-test the driver: ascending order, span and count bounds, resumability,
      and that a drained backlog re-runs as a no-op
- [ ] Unit-test that sealed rows interleaved in a window are byte-identical after a pass
      - guards the false-tamper-alarm failure mode
- [x] Unit-test the cutover classification in `verifyChain()` (both populations,
      plus a drained backlog restoring `valid: true`)
      - AuditHashSealCutoverTest, Version1Date20261124193002Test
- [ ] Unit-test the job's caps and its lock-contention yield

## 7. Evidence

- [ ] Measure a real drain on a seeded backlog and record rows/sec and peak memory
      against the documented `~152 rows/min` baseline
      - the speed claim must be measured, not asserted
- [ ] Update `openspec/specs/audit-hash-chain/spec.md` with the merged requirements

## Acceptance criteria

- A backlog of ~108k interleaved unsealed rows drains without OOM and without
  blocking foreground writes
- `verifyChain()` returns `valid: false` while post-cutover rows are unsealed, and
  `valid: true` once drained
- Re-running the pass seals zero rows and issues no `UPDATE`
- No sealed row's `hash` or `previousHash` changes at any point
- `composer check:strict` and the unit suite stay green
