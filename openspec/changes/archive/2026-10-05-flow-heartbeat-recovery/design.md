# Design: flow-heartbeat-recovery

## Context

Measured before deciding anything:

- **The node re-read has always existed.** `UserTaskNode::execute()`
  (`lib/Service/Flow/Nodes/UserTaskNode.php`) re-enters on every wake, reads
  `taskOrNull()` and applies the outcome bag when the task is terminal.
  `PortalTaskNode` mirrors it. The single-stream walk and the stream walk
  both re-dispatch a parked node on resume (`FlowStreamWalk::begin()`:
  "Suspended streams become eligible again").
- **The slot is the node's only memory.** The task uuid lives in the node's
  per-node resume slot (#3325's scoping) and nowhere else the node can
  reach. Lose the slot and the node MUST create a new task — its own
  idempotency guard reads the slot to decide.
- **`persistResult()` was the only writer that lost it.**
  `FlowResumeState::storableWhen(suspended:)` returned null for every
  non-suspended pass end. `advanceStream()` finalising `queued` (sibling
  work enabled) is a routine pass end for a run with parallel human
  branches, and a claim refusal produces `queued` too.

## Decisions

### D-1: Fix the state, not the walk

The heartbeat is made honest by making the state it reads durable, not by
adding a recovery sweep to `FlowRunWorker`. A worker-side "re-read every
suspended run's tasks" would be a second delivery mechanism with its own
addressing rules, racing the node's own re-read. With the slot intact, the
existing wake (`findDue()` → `advance()` → `execute()` → node re-entry) does
everything the defect report asks: re-read, apply, advance.

`storableWhen()` keeps slots for every status outside `FlowRun::TERMINAL`.
Terminal runs still drop them, for the original reason: anything still held
belongs to a node the run never came back to, and the dispatcher has already
cleared every node that returned.

### D-2: The recovery is audited on the task, attributed to the completer

The guarded signal seam records a refusal
(`FlowRunSignalService::auditRefusal()`); without a matching entry the trail
ends there and reads as though the answer never reached the run. When a node
reads its task terminal with NO signal in the walk's context, the wake was a
heartbeat, not the completion's signal — `context['signal']` is set by
`signal()` and survives into the woken walk, so its absence is the
discriminator. The node then calls
`FlowTaskBridge::recordHeartbeatRecovery()`, which appends a
`heartbeat-recovered` audit entry on the task via `TaskService::record()`,
actor = `completedBy` — the fact recorded is THAT PERSON's answer arriving
late, not the cron job acting. Best-effort: a failure to write the entry is
logged and swallowed, because it must never turn a recovered run back into a
wedged one.

### D-3: The symmetric cases need no new mechanism

Stated explicitly, as the defect report asks:

- **A task completed while the run was not yet suspended (the race).**
  `signal()` returns null for a run that is `running` or `queued`, so the
  completion's wake is lost. The run then parks with a non-null heartbeat
  (`🔴 THE HEARTBEAT IS NEVER NULL`, UserTaskNode), and the next wake
  re-reads the task — with the slot now durable, the race costs at most one
  heartbeat period of latency. No pre-suspension re-check is added: the node
  cannot read an answer before it has parked on the question, and the
  heartbeat already bounds the wait.
- **A task whose sequence concluded (`TaskSequenceService`).** A sequence
  drives every per-task transition through `TaskService`'s verbs, so the
  task named by the node's slot reaches its terminal state on the same row
  the heartbeat re-reads. Terminality is a property of that row
  (`isInTerminalState()`); the re-read covers sequence-concluded tasks with
  no sequence-specific handling.

### D-4: Wrong-slot isolation is preserved by construction

Each node reads only the slot the dispatcher scoped to it
(`FlowNodeResumeState`), so a heartbeat wake recovers exactly the nodes
whose OWN tasks are terminal; a sibling parked on an open task re-suspends
with its slot untouched. `FlowHeartbeatRecoveryTest::testOnlyTheNodeWhoseTaskEndedRecovers`
pins it.

## Risks

- Keeping slots on `queued`/`running` stores per-node state a little longer
  than before. That state is exactly what a parked node needs on its next
  wake; nodes that returned were already cleared by the dispatcher, so no
  stale cursor can leak into a later pass.
- `recordHeartbeatRecovery()` re-dispatches `TaskTerminalEvent`, because
  every `TaskService::record()` on a terminal task does. The re-entrancy is
  closed by an existing guard rather than by a new one, and the chain is
  short enough to state in full: the listener calls
  `FlowTaskBridge::continueRun()`, which calls `FlowRunService::signal()`,
  which returns null for any run that is not `suspended`. At the moment the
  recovery is recorded the run row says `running` — `execute()` sets and
  persists that before the walk begins — so the signal is refused and no
  second walk starts. The recovery is written from inside that walk, and the
  walk finishes normally.
