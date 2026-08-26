# Tasks: flow-parallel-streams

## 1. Storage

- [ ] 1.1 Migration: `openregister_flow_claims` (`run_uuid`, `place`, `owner`,
      `stream_id`, `transition`, `claimed_at`) with UNIQUE `(run_uuid, place)`
      — the unique index IS the lock — and an index on `claimed_at` for the
      reaper; `openregister_flow_streams` (`run_uuid`, `stream_id`,
      `ordinal_path`, `parent_stream_id`, `status`, `resume_at`,
      `next_sequence`, `error`, `created`, `updated`) with UNIQUE
      `(run_uuid, stream_id)` and an index on `(run_uuid, status)`;
      `place_items` (json, nullable) + `firings` (int, notnull, default 0) on
      `openregister_flow_runs`; `stream_id` + `ordinal_path` (string 255,
      nullable) on `openregister_flow_steps`.
- [ ] 1.2 `lib/Db/FlowStream.php` + `FlowStreamMapper` (find by run, find by
      run+stream, allocate the next sequence with the conditional-UPDATE shape
      of `lib/Db/SequenceMapper.php:84-93`) and `lib/Db/FlowClaim.php` +
      `FlowClaimMapper` (insert-or-refuse, release by owner, count held per
      run, find older than a cutoff). Stream status reuses `FlowRun`'s seven
      constants (`lib/Db/FlowRun.php:98-110`) rather than a second vocabulary.
- [ ] 1.3 Repair step in the same migration: one stream per marked place on
      every non-terminal run, ordinals in sorted place-name order,
      `next_sequence` from `highestSequence() + 1`
      (`lib/Db/FlowRunStepMapper.php:81-97`); existing step rows stamped with
      the root path `0001` and the root stream id; `place_items` left null so
      it seeds from the flat `items` on first read. Guarded on existence, and
      logging the ordinal caveat of design.md Migration Plan step 3.

## 2. Claim protocol

- [ ] 2.1 `FlowPlaceClaims` — `acquire(run, streamId, transition, places)`
      sorts `froms ∪ tos` bytewise, INSERTs each place in its OWN committed
      transaction, and on the first unique violation DELETEs what it already
      took and returns a refusal. It never waits and never retries in place.
      The commit-per-insert is load-bearing: taking a claim inside the firing's
      transaction would make a rival INSERT block on the row lock for the
      duration of the step (design.md Decision 1).
- [ ] 2.2 Pass identity + cap enforcement: `owner` is a per-pass token
      (instance, pid, pass uuid) stamped on every claim; a claim is refused
      when the run already holds `FlowConcurrency::DEFAULT_LIMIT`
      (`lib/Service/Flow/FlowConcurrency.php:72`) claims, clamped by
      `MAX_LIMIT` (`:83`) through the same `max(1, min(...))` as
      `boundedLimit()` (`:188-194`), and when the pass holds
      `BATCH × DEFAULT_LIMIT` across all runs (`lib/BackgroundJob/FlowRunWorker.php:62`).

## 3. The commit path

- [ ] 3.1 `FlowRunCommit` — one method holding the whole critical section:
      `beginTransaction()`, `SELECT ... FOR UPDATE` the run row, recompute from
      the value read INSIDE the lock, apply the delta, write marking +
      `place_items` + the step row + the stream row + `firings + 1` + the
      derived status, `commit()`. No I/O and no user code inside it; the
      dispatch stays outside. `IDBConnection` transaction handling follows
      `lib/Service/SequenceService.php:76-115`.
- [ ] 3.2 `FlowRunMarkingStore::setMarking()`
      (`lib/Service/Flow/FlowRunMarkingStore.php:102-105`) stops writing
      `$marking->getPlaces()` wholesale and takes a delta — one token off each
      `from`, one onto each taken `to`. The whole-value write is removed, not
      wrapped: leaving it reachable leaves the lost update reachable.
- [ ] 3.3 Per-place items persisted: `place_items` written by the same
      transaction as the marking, and `FlowItemPlacement::seedPlaceItems()`
      (`lib/Service/Flow/FlowItemPlacement.php:90-103`) reads it when present,
      falling back to today's same-list-to-every-place seed when null so an
      in-flight run's behaviour across the upgrade is identical.

## 4. The stream walk

- [ ] 4.1 `FlowStreamScheduler` — round-robin over a run's advanceable streams
      rather than draining one to exhaustion, bounded by task 2.2's cap. A
      stream whose claim is refused yields to the next; a stream that parks
      yields; neither returns the run.
- [ ] 4.2 `FlowEngine::run()` (`lib/Service/Flow/FlowEngine.php:310-546`)
      becomes a per-stream walk: `FlowSuspension` (`:474-493`) parks the
      stream that raised it and releases its claim instead of returning the
      run, and the empty-enabled-set exit (`:312-322`) no longer decides the
      run's fate. Terminality is decided only by `FlowRunCommit` from the
      marking it just wrote (design.md Decision 4).
- [ ] 4.3 Stream lineage: a firing that marks K taken output places mints K
      child streams with `parent.0001 … parent.000K` in `getTos()` declaration
      order; a join folds its inputs back to their longest common prefix and
      resumes that stream's `next_sequence`; a path that would exceed the
      column fails the run with a named error rather than sorting wrongly.
- [ ] 4.4 Derived run status written by `FlowRunCommit`: `running` while any
      stream holds a live claim, `queued` while any stream has an enabled
      transition, `suspended` when all are parked, else the most severe
      terminal (`failed` > `dead_letter` > `stopped` > `completed`).
      `resume_at` is `MIN` over NON-NULL stream wake times, null only when
      every stream waits on a signal — a plain `MIN` would hide a due timer
      from `findDue()` (`lib/Db/FlowRunMapper.php:503-507`). No eighth status
      value is added.

## 5. Run-log ordering

- [ ] 5.1 `FlowRunService::recordSteps()`
      (`lib/Service/Flow/FlowRunService.php:669-732`) stops reading
      `highestSequence() + 1` (`:677`) and takes its position from the stream
      row inside `FlowRunCommit`'s transaction, writing `stream_id` and
      `ordinal_path` on every row; `FlowRunStepMapper::findByRun()` then orders
      `ordinal_path ASC, sequence ASC, id ASC`, replacing the bare
      `ORDER BY sequence ASC` (`lib/Db/FlowRunStepMapper.php:62`). A
      by-timestamp ordering is available explicitly and is never the default.

## 6. Bounds, oversight and recovery

- [ ] 6.1 The transition ceiling becomes the persisted `firings` count checked
      against `MAX_TRANSITIONS` (`lib/Service/Flow/FlowEngine.php:103`),
      replacing the per-pass local at `:299`/`:325`, and keeping the existing
      failure message (`:335`) so a cycle that parks each lap now trips it.
- [ ] 6.2 `assertOversightAllows()` (`FlowEngine.php:425`) is called per firing
      inside the claim, never hoisted per pass and never cached. A refusal ends
      the RUN: unstarted streams do not start, a stream already inside
      `dispatch()` commits that firing and then stops, and the refusing check's
      id is recorded via `FlowStop::checkId()` (`:454-456`).
- [ ] 6.3 `FlowRunWorker::reapStale()` (`lib/BackgroundJob/FlowRunWorker.php:226-275`)
      also releases claims older than its EXISTING cutoff (`:251-261`) — the
      same expression, not a second constant — fails the abandoned stream
      naming the branch, applies the run's error policy to its siblings, and
      logs the abandonment. Reaped, never re-dispatched, matching `:207-218`.

## 7. Advance budget (ADR-098 D9)

- [ ] 7.1 Task completion's `advance: 0 | N | "all"` advances the COMPLETING
      stream only, taking claims through `FlowPlaceClaims` exactly as a worker
      does. A refused claim ENDS the advance and returns the run's state; a
      join consuming the completing branch's place is inside the budget;
      `"all"` remains bounded by the ceiling, by per-firing oversight and by
      `FlowRunService::DEFAULT_MAX_RUNTIME_MINUTES` (`FlowRunService.php:63`).

## 8. Tests

- [ ] 8.1 Concurrency properties, driven through two real database
      connections: the t=1..24 interleaving of design.md Decision 3 leaves both
      effects committed; two workers on one transition produce exactly one
      dispatch, one marking write and one step row; two disjoint branches both
      proceed; a contended claim is skipped and the firing stays enabled; a
      join with simultaneous arrivals fires exactly once reading both branches'
      items; a join enabled by the last commit of a finished pass is fired by
      the next pass and the run is never reported completed in between.
- [ ] 8.2 Determinism and regression: two runs whose branches finish in
      opposite orders produce identical canonical logs; the real interleaving
      is still readable by timestamp; a twelve-token marking holds at most five
      claims; a cap above the ceiling is clamped; and a single-stream flow
      produces byte-identical marking, `resumeAt`, status and step ordering to
      the pre-change engine — the assertion that carries the BREAKING
      `flow-engine` delta.
- [ ] 8.3 Recovery, status and migration: a claim whose holder died is released
      after the cutoff, its branch failed and named, never re-dispatched; a live
      long-running firing is not reaped; a branch waiting days on a signal is
      not failed as abandoned by `findStale()`
      (`lib/Db/FlowRunMapper.php:543-547`); one branch waiting and one working
      reads as `running`; and the migration back-fill over a seeded database
      with in-flight multi-token runs, applied twice with identical results.

## Acceptance criteria

- Two branches of one run that share no place advance at the same time, and a
  branch waiting on a human answer, a timer or a remote call holds up no
  sibling. Measured against the failing case in proposal.md, not asserted.
- Token loss is unrepresentable, not rare. No code path writes the marking from
  a value read outside the run-row lock: a grep for `setMarking(` returns only
  delta callers, and the whole-value write at
  `lib/Service/Flow/FlowRunMarkingStore.php:102-105` no longer exists.
- No two firings whose place sets intersect are ever both dispatched. Every
  dispatch is preceded by a committed claim on every place it touches, and no
  claim attempt ever waits.
- A run's canonical log is a function of the path taken. Two runs of one flow
  over the same path produce identical `(ordinal_path, sequence)` sequences
  whatever the timing, and no row's position comes from a run-wide counter.
- The run status set still has exactly seven values, and `findQueued()`,
  `findDue()`, `findStale()` and `hasActiveRun()` are unchanged queries that
  return the same runs they would have returned before.
- The stream cap reads `FlowConcurrency::DEFAULT_LIMIT` and `MAX_LIMIT`
  directly. No second pair of numbers exists anywhere in the change.
- The claim reaper uses the SAME cutoff expression as `reapStale()`. A grep
  finds one occurrence of that expression, not two.
- A run's `firings` never exceeds `MAX_TRANSITIONS`, across all streams and all
  passes, and reaching it fails the run with the existing message.
- An oversight refusal stops every branch of the run, and no branch begins a
  firing after one has been raised.
- A single-stream flow behaves identically to today — same marking, same
  `resumeAt`, same status, same step order — which is what makes the
  `flow-engine` spec change safe for existing flows.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- New PHP files carry `@license EUPL-1.2` and `@copyright 2026 Conduction B.V.`
- `@spec` annotations point at
  `openspec/specs/flow-parallel-streams/spec.md` anchors.
- References ADR-098 Decision 7 (streams run simultaneously) and Decision 9
  (advance budgets), ADR-065 (one engine), ADR-031 (the imperative engine-core
  path is argued in design.md, not assumed).
- Depends on `flow-definition-versioning`: every stream of one run resolves the
  SAME pinned definition. Assert it — two streams of one run walking two
  graphs is the failure this dependency exists to prevent.
- SQLite's lack of row-level locking is noted in the test suite so a green
  SQLite run is not read as evidence the locking works.
