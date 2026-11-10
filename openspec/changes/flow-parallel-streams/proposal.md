---
kind: code
depends_on: [flow-definition-versioning]
---

# Proposal: flow-parallel-streams

## Summary

Make the independent branches of ONE run advance independently. The engine
already lowers a flow to a Petri net whose marking can hold several tokens at
once; what it does not have is a walk that treats those tokens as separate
streams. Today one branch blocking on I/O, a timer or a human answer stops
every sibling, because the walk is a single loop and a suspension returns the
whole run.

This is the engine's THIRD concurrency layer, and the only one missing:

| Layer | Unit | Where it lives today |
|---|---|---|
| 1 | items within one node | `lib/Service/Flow/FlowConcurrency.php` — bounded fan-out, input-ordered results, per-item failure isolation |
| 2 | whole sub-flows | `lib/Service/Flow/Nodes/SubFlowNode.php` — child runs advance on their own, tied by `parent_run_uuid` |
| 3 | **branches of one marking** | **nothing — this change** |

## Why

**A case is concurrent, and the engine serialises it.** ADR-098 Decision 7:
an advies request, a hoorzitting and a document check on one bezwaar are
genuinely simultaneous. Modelled as a split, they are three tokens in one
marking — and the moment any one of them reaches a human task, all three stop.

**Measured, in the code as it stands (2026-08-22):**

- **One loop, one transition at a time.** `FlowEngine::run()` is a single
  `while (true)` (`lib/Service/Flow/FlowEngine.php:310-546`). Each pass asks
  `getEnabledTransitions()` for every enabled transition and then hands the
  list to `selectTransition()`, which returns exactly ONE
  (`FlowEngine.php:344`, `:712-737`). Several enabled transitions are fired in
  succession, never concurrently.
- **A suspension is run-wide, by design and by spec.** The `FlowSuspension`
  catch returns the whole run as `suspended`
  (`FlowEngine.php:474-493`), and `openspec/specs/flow-engine/spec.md:50` says
  so in as many words: "`FlowSuspension` stops the WHOLE run and stores its
  marking; it is not scoped to the branch that threw it." A human task on one
  branch therefore parks the other two, and `resume_at = null` means the run
  waits for a signal that has nothing to do with them.
- **Resuming a multi-token run already loses per-branch data.**
  `FlowItemPlacement::seedPlaceItems()` seeds the per-place item buffers by
  assigning the SAME persisted list to every marked place
  (`lib/Service/Flow/FlowItemPlacement.php:90-102`), and the run persists one
  flat `items` array — the last firing's output
  (`lib/Service/Flow/FlowRunService.php:810`). A run that suspends holding two
  tokens resumes with both branches carrying one branch's items. Serialised,
  this is rare; with branches genuinely in flight it is the normal case.
- **The marking is a read-modify-write of one JSON column.**
  `FlowRunMarkingStore::setMarking()` writes `$marking->getPlaces()` wholesale
  onto the run (`lib/Service/Flow/FlowRunMarkingStore.php:102-105`) from a
  `Marking` read at the top of the pass. Two writers, one lost update, tokens
  gone with no error anywhere. Items already collided once on a shared output
  place — `FlowItemPlacement.php:118-122` records it as openregister#2488, and
  notes it "is invisible" in the log.
- **The run log's order is insert order.** `recordSteps()` numbers rows from
  `highestSequence() + 1` (`FlowRunService.php:669-732`) and
  `FlowRunStepMapper::findByRun()` sorts `ORDER BY sequence ASC`
  (`lib/Db/FlowRunStepMapper.php:62`). Concurrent branches make that number a
  record of which branch returned first — the exact property `FlowConcurrency`
  refuses to give up for items: "a run log whose order depends on which call
  returned first is not comparable between two runs of the same flow"
  (`FlowConcurrency.php:25-28`).
- **The transition ceiling is per-pass, not per-run.** `$fired` is a local
  initialised at `FlowEngine.php:299` and checked against
  `MAX_TRANSITIONS = 1000` (`FlowEngine.php:103`, `:325`). It resets on every
  `execute()`, so a cyclic graph that suspends each pass never trips it. That
  hole exists today across suspend/resume; branch parallelism would widen it
  from "a slow loop" to "N slow loops".

The two invariants ADR-098 D7 names are therefore not decorations — each has a
concrete counterexample in the file it names.

## What Changes

- **A stream is a first-class, persisted thing.** A run's marking is
  partitioned into streams; each stream has an id, an ordinal, a status and a
  claim. Run status stays derived from them, so nothing that queries `status`
  today changes meaning.
- **Per-branch claiming, with the database as the mutual-exclusion
  primitive.** A worker claims the PLACES a firing touches (its inputs and its
  outputs) before dispatching it, via unique-constrained rows in a new
  `openregister_flow_claims` table, taken in a fixed order. Two firings that
  share any place can never both be claimed; a firing whose claim fails is
  skipped, never blocked on.
- **Marking writes become deltas under a row lock.** The marking mutation for
  one firing removes tokens from the consumed places and adds them to the
  produced ones, inside a short transaction that locks the run row — never a
  whole-marking overwrite computed from a stale read. A lost update stops being
  unlikely and becomes unrepresentable.
- **Suspension becomes stream-scoped.** `FlowSuspension` parks the stream that
  raised it and releases its claim; sibling streams keep going. The run parks
  only when every stream is parked. **BREAKING** at the spec level for
  `flow-engine`'s "SUSPENDING is a run-level act" requirement; behaviour for a
  single-stream flow is unchanged, because a flow with one stream IS the run.
- **Per-place item buffers are persisted per place.** A new `place_items`
  column, seeded on first read from the existing flat `items` so an in-flight
  run keeps today's behaviour exactly.
- **Run-log order is by branch, never by completion.** A step row records its
  stream id and the stream's declaration ordinal; a run's canonical order is
  `(stream ordinal, sequence within stream)`, which is identical between two
  runs of the same flow. Wall-clock timestamps stay available and are never the
  default order.
- **Bounding reuses layer 1's posture and numbers.** A per-run cap on streams
  advanced in one pass, defaulting to `FlowConcurrency::DEFAULT_LIMIT` (5) and
  clamped by `MAX_LIMIT` (20), so intra-run fan-out cannot be a burst that
  per-item fan-out would have refused.
- **Crashed claims are reaped like stale runs.** `FlowRunWorker`'s existing
  cutoff — `max(flow_run_stale_minutes, flow_max_runtime_minutes + 5)`,
  `lib/BackgroundJob/FlowRunWorker.php:251-261` — also releases abandoned claims, and
  keeps that pass's posture: a reaped stream FAILS rather than silently
  re-running side effects it may already have performed
  (`FlowRunWorker.php:208-218`).
- **The transition ceiling becomes run-scoped and durable.** A persisted count
  of committed firings, checked against `MAX_TRANSITIONS`, so the ceiling
  survives suspension and covers all streams together.
- **Oversight stays per-hop and fail-closed.** `FlowOversightRegistry::
  firstRefusal()` is consulted inside each claim before each dispatch, never
  hoisted per pass. A refusal stops the RUN, not one branch: a kill switch that
  leaves a sibling writing objects is not a kill switch.
- **Advance budgets (ADR-098 D9) follow the token.** `advance: 0 | N | "all"`
  on task completion advances the COMPLETING stream, in-request, taking claims
  exactly as the worker does. Siblings are untouched, and a sibling's claim
  ends the in-request advance rather than blocking the request.

## What does NOT change

- **The flow document.** Authors already express parallelism declaratively — a
  split is an edge shape, `join: true` is a node flag, and
  `FlowGraph::joinPlace()` already gives a join one input place per incoming
  edge (`lib/Service/Flow/FlowGraph.php:103-105`). This change executes that
  declaration; it does not add a way to write it.
- **`FlowGraph::inPlace()` returning the bare node id.** It is load-bearing for
  per-item routing and for the "where is this run?" badge
  (`FlowGraph.php:46-69`); stream identity is recorded beside the marking, not
  encoded into place names.
- **The seven run statuses.** No eighth value: a new status would silently drop
  out of every existing `WHERE status = ...`, including `findQueued()`,
  `findDue()`, `findStale()` and `hasActiveRun()`.
- **Layers 1 and 2.** Per-item concurrency and sub-flow fan-out are unchanged;
  this composes beneath them.
- **Retry semantics.** Retry still queues a NEW run; a stream is never
  restarted in place.

## Capabilities

### New Capabilities
- `flow-parallel-streams`: independent branches of one run's marking advance
  simultaneously, with marking consistency under concurrent writers, a
  branch-ordered run log, a bound on intra-run fan-out, and crash recovery for
  abandoned branch claims.

### Modified Capabilities
- `flow-engine`: "SUSPENDING is a run-level act, so an EMPTY firing MUST NOT
  suspend" — suspension becomes stream-scoped, so the requirement's premise
  changes while its empty-firing rule stands (for a new reason: an empty branch
  that parks forever holds a join open rather than stopping the run).

## Impact

- **Affected specs**: new `flow-parallel-streams`; `flow-engine` (one modified
  requirement)
- **Affected code**: `FlowEngine` (the walk becomes per-stream),
  `FlowRunAdvancer` (claiming and the pass budget), `FlowRunMarkingStore`
  (delta writes), `FlowItemPlacement` + `FlowRunService` (per-place item
  persistence), `FlowRunStep`/`FlowRunStepMapper` (stream id and ordinal),
  `FlowRunWorker` (claim reaping), plus migrations for
  `openregister_flow_claims`, `openregister_flow_streams` and the new run and
  step columns
- **Affected apps**: every consumer of the shared engine (ADR-022, ADR-065)
  gains real concurrency without touching its flows; hermiq's node badge reads
  a marking that may now legitimately hold several tokens at once
- **Depends on** `flow-definition-versioning`: a stream may outlive several
  worker passes, so every stream of one run MUST resolve the SAME pinned
  definition. Without the pin, `FlowRunAdvancer.php:92` re-resolves the live
  definition each pass and two streams of one run could walk two different
  graphs
- **ADRs**: ADR-098 D7 (this decision), D9 (advance budgets), ADR-065 (one
  engine), ADR-031 (no declarative surface is touched — justified in design.md)
