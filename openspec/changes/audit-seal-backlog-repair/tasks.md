# Tasks — audit seal backlog repair

## 1. Schema and marker

- [ ] Add a migration creating the partial index `idx_audit_unsealed` on
      `oc_openregister_audit_trails (id) WHERE hash IS NULL OR hash = ''`
      - the backlog cursor currently degrades to a pkey scan + filter (784 ms / 2,000 ids)
      - the index shrinks as the backlog drains, so the cursor speeds up over time
- [ ] Persist a hash-chain cutover marker in the same migration (app config,
      set to the migration's run time)
      - existing instances classify their current backlog as legacy, not as an alarm

## 2. Driver

- [ ] Add `AuditSealBacklogService` — the single driver shared by the command and the job
- [ ] Implement the ascending unsealed-id cursor using the partial index
- [ ] Implement window cutting bounded by BOTH `MAX_WINDOW_ROWS` and `MAX_WINDOW_SPAN`
      - a sparse region must not widen the range read
- [ ] Drive `AuditHashService::sealRows()` once per window, committing per window
      - windows are sequential; never concurrent (chain order is mandatory)
- [ ] Return per-pass counters (rows sealed, windows processed, windows abandoned on lock)

## 3. Read-path efficiency

- [ ] Split `sealRowsLocked()` into a narrow `id, hash` skeleton read over the range
      plus a full-row read restricted to the unsealed ids
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

- [ ] Classify null-hash rows in `verifyChain()` against the cutover marker:
      pre-cutover counted as `skippedNullHashes`, post-cutover returns `valid: false`
- [ ] Report the post-cutover unsealed count in the `verifyChain()` result

## 6. Tests

- [ ] Unit-test the driver: ascending order, span and count bounds, resumability,
      and that a drained backlog re-runs as a no-op
- [ ] Unit-test that sealed rows interleaved in a window are byte-identical after a pass
      - guards the false-tamper-alarm failure mode
- [ ] Unit-test the cutover classification in `verifyChain()` (both populations,
      plus a drained backlog restoring `valid: true`)
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
