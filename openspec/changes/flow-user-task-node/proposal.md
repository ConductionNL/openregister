---
kind: code
depends_on: [flow-task-entity]
---

# Proposal: flow-user-task-node

## Summary

Put a person into the graph. A new node type `openregister.user-task`
creates a task through `flow-task-entity`'s `TaskService`, suspends the run,
and continues when that task is completed — with the completion payload
written onto every item so the next node can branch on it. It carries an
`advance` budget (ADR-098 Decision 9) saying how far the completing request
may push the run before handing back to the worker, and it terminates its
tasks when the run or the branch that owns them dies.

`openregister.await-signal` is not replaced. It keeps machine-to-machine
signals; this node takes human and agent work, which is the half that needs
an owner, an inbox and a cancellation story.

## Why

**The engine can already pause for a person. It just cannot ask one.**
`AwaitSignalNode` suspends a run and waits for `POST
/api/flow-runs/{uuid}/resume` (`appinfo/routes.php:1312`). That is the whole
mechanism, and three things are missing from it, all measurable in the file
itself:

1. **Nobody is asked.** The node takes an `assignee`, and its own config
   help says what it is worth: "A user or group id. Recorded with the
   request; it does not by itself restrict who may answer."
   (`lib/Service/Flow/Nodes/AwaitSignalNode.php:200-203`). The value is
   written into the node's resume slot (`AwaitSignalNode.php:366-371`) and
   read by nothing.
2. **Nobody can find the question.** The request is recorded in the run's
   own resume state; there is no row anywhere that says "this person owes an
   answer". "What is waiting for me?" is answerable only by walking
   suspended runs and reading a JSON column. `flow-task-entity` builds the
   row and the inbox that fixes this; nothing in the graph creates one.
3. **Anyone can answer.** `FlowRunController::resume()` checks only that the
   caller may RUN the flow (`lib/Controller/FlowRunController.php:423-436`).
   It never checks that the caller is the person being waited on, because
   there is no person being waited on.

**A signal is the wrong shape for human work.** `signal()` writes the
payload to `$context['signal']` — ONE slot per run
(`FlowRunService.php:74`, `:530`), consumed and cleared by the walk it wakes
(`:807`). That is correct for "one question, one answer" and wrong for work
that gets claimed, reassigned, delegated, chased and sometimes cancelled
before anybody touches it. None of those verbs have anywhere to live on a
context key.

**And a paused run has no completion latency control.** `signal()` parks the
run as due and returns; the worker advances it on its next pass, and the
stock system cron is every five minutes (`AwaitSignalNode.php:82-83`). For
an approval that is the right trade, and `FlowRunService::signal()`'s own
docblock says so. For a form a person just submitted and is watching, five
minutes of "nothing happened" is the trade being made backwards. The engine
already has the other path — `FlowService::run()` advances inline with
`rethrow: true` when a caller asked to wait (`FlowService.php:511`) — but no
node can ask for it. ADR-098 Decision 9 makes it a per-node budget.

**The retrofit bug is cancellation.** When a run is stopped, or a parallel
branch settles a choice, the tasks it created are moot. If nothing
terminates them they stay in inboxes as actionable work forever, and people
do them. `flow-task-entity` specifies the propagation; this change is what
supplies the run-terminal and branch-moot events that drive it, because it
is the only thing that knows which node created which task.

## What Changes

- **A new node, `openregister.user-task`**, in `lib/Service/Flow/Nodes/`,
  implementing `IFlowNode`, `IFlowNodeConfigKeys` and `IFlowNodeConfigForm`
  and registered like every other built-in through
  `lib/Listener/FlowNodeRegistrationListener.php`. Its `configForm()` is
  served by `GET /api/flow/node-catalog`
  (`lib/Controller/FlowController.php:213`, `appinfo/routes.php:508`), which
  already publishes `configForm` for any node declaring one
  (`FlowNodeRegistry.php:243-250`) — so the builder gets the form with no
  editor change.
- **The config says what task to create**: title and description templates,
  candidate users / groups / role, routing strategy, priority, `due_at` and
  `expires_at` references, and the `outcome` vocabulary the flow will branch
  on. Every one of these is passed THROUGH to `TaskService::create()`; this
  node validates and templates, it does not re-model the task.
- **It suspends and resumes like `AwaitSignalNode`, deliberately.** First
  pass: create the task, record it in the node's own resume slot
  (`FlowNodeResumeState`, `lib/Service/Flow/FlowNodeResumeState.php:39-65`),
  throw `FlowSuspension`. Later passes: ask the TASK whether it is terminal,
  not the run context. Two user-task nodes in one flow therefore keep
  independent state, which the per-node slot already guarantees — that is
  the case a flat context bag breaks silently.
- **The completion payload lands on every item**, under a configured
  `outcomeKey` (default `task`), carrying the outcome, the comment, the
  completing identity, the performer type and any `on_behalf_of`. Onto the
  ITEMS rather than the run, for the reason `AwaitSignalNode.php:294-296`
  gives: *"a Switch cannot branch on something only the run holds."*
- **A rejecting outcome is a BRANCH, not a failure.** Default is to carry on
  and let the author route on the outcome; `failOnReject` stays opt-in, the
  same shape as `AwaitSignalNode.php:277-287`. Being told no is the flow
  working.
- **An `advance` budget** (ADR-098 D9) on the node: `0` (default) parks the
  run for the worker exactly as `signal()` does today; `N` continues at most
  N transitions inside the completing request; `"all"` continues until the
  next suspension, the next user task, or an end. Unlimited is spelled
  `"all"` — **never `null`**, because a null budget and an absent one
  coerce identically in PHP and JSON and the accident would be "run to
  completion synchronously" (design.md, D-4). Every value stays bounded by
  `FlowEngine::MAX_TRANSITIONS` (`lib/Service/Flow/FlowEngine.php:103`,
  1000) and by the pre-hop oversight check
  (`FlowEngine.php:425`, `assertOversightAllows()`), which is fail-closed
  (`FlowOversightRegistry.php:104-120`) and stays so on the in-request path.
- **Cancellation propagation is wired here.** A run reaching a terminal
  status terminates every non-terminal task created by any of its user-task
  nodes; a branch decision that makes a node unreachable terminates that
  node's task with a reason naming the branch. The reason is recorded, the
  task disappears from inboxes as actionable, and the audit names the
  propagation source as actor.
- **The heartbeat is kept.** `flow-engine`'s spec already requires it — *"A
  node that suspends waiting on a signal MUST ALSO carry a heartbeat
  `resumeAt`"* — and the reason holds here: a completion can land while the
  run is mid-walk and has not suspended yet. Measured, it holds harder than
  the spec says: `findAbandonedSignals()` matches only
  `resume_at IS NULL` (`lib/Db/FlowRunMapper.php:589-605`), and **no shipped
  node ever suspends with null** — `WaitNode.php:192` and
  `AwaitSignalNode.php:266` are the only two `FlowSuspension` throws in
  `lib/`, and both pass a time. A user-task node that parked on null would
  be the first run in the fleet the 14-day reaper
  (`FlowRunWorker.php:94`, `:311-349`) could actually FAIL — and it would
  fail approvals that are merely slow.

## What does NOT change

Each of these is a named change in the ADR-098 chain and is explicitly OUT
of scope here:

- **`flow-task-entity`** — the `openregister_tasks` table, `TaskService`,
  `TaskAuthorizationService`, the inbox query, the CMMN lifecycle, the
  performer model, the routing strategies, the append-only audit. This node
  is a CONSUMER of all of it. It defines no task field and no lifecycle
  verb of its own.
- **`flow-task-forms`** — structured completion payloads over the lifecycle
  transition `inputs` contract and the nc-vue form family. This node's
  completion payload is a typed but hand-specified bag; where the form comes
  from is that change's question.
- **`flow-task-inbox-projections`** — `INotificationManager` notifications
  and the CalDAV VTODO projection with its authorizing write-back listener.
  Creating a task here notifies nobody and writes no calendar entry.
- **`flow-business-timers`** — SLA arithmetic, business days, escalation
  matrices, opschorting, and the sweep that acts on `expires_at`. This node
  configures where those two timestamps come FROM; it never enforces one.
- **`flow-bpmn-interchange`** — mapping `bpmn:userTask` onto this node on
  import and back out on export. This change ships the node the mapping will
  target; it ships no BPMN.
- **`openregister.await-signal` itself.** It stays, unmodified, with its
  heartbeat, its `failOnReject` and its nudge-is-not-an-answer rule. The
  division of labour is stated once and held: **a signal is for a system
  that will call back; a user task is for a performer who must be found,
  told, and allowed to say no.** A flow waiting on a payment provider keeps
  using `await-signal`; a flow waiting on a case handler uses this.

## Capabilities

### New Capabilities
- `flow-user-task-node`: the `openregister.user-task` step node — task
  creation from node config, suspend/resume against task terminality,
  per-item outcome placement, rejection-as-branch, the `advance` budget, and
  cancellation propagation from run and branch terminality onto the tasks
  the node created.

### Modified Capabilities
<!-- None. flow-engine's requirements are unchanged: the engine still lowers a
     document, still suspends on FlowSuspension, still refuses a hop the
     oversight registry vetoes, still stops at MAX_TRANSITIONS. The advance
     budget is a ceiling this capability imposes on a walk it initiates, not
     a change to how the walk works. -->

## Impact

- **Affected code**: new `lib/Service/Flow/Nodes/UserTaskNode.php`; new
  `lib/Service/Flow/FlowTaskBridge.php` (node ↔ `TaskService`, plus the
  advance-budget continuation); `lib/Listener/FlowNodeRegistrationListener.php`
  (registers the node); a listener on task completion that signals the run
  and, per budget, advances it; a listener on run terminality that
  propagates cancellation. `lib/Service/Flow/Nodes/AwaitSignalNode.php` is
  NOT touched.
- **Affected APIs**: no new endpoint. Completion happens on
  `flow-task-entity`'s task verbs, which are authorized fail-closed;
  `POST /api/flow-runs/{uuid}/resume` keeps working for `await-signal` and
  MUST NOT be able to complete a user task — that would reintroduce the very
  hole at `FlowRunController.php:423-436` this chain exists to close.
- **Affected UI**: the node appears in the builder palette automatically via
  `FlowNodeRegistry::palette()`; no editor change is required for its form.
  The task inbox is `flow-task-entity`'s surface, not this change's.
- **Affected apps**: none required. Consumers arrive with
  `flow-approval-consolidation`; hermiq, procest and openconnector gain a
  target for their human steps but migrate in their own changes.
- **Depends on**: `flow-task-entity` (which itself depends on
  `flow-definition-versioning`). A `node_id` recorded on a task is a pointer
  into a definition, and it only means anything while that definition is
  pinned for the life of the run.
- **ADRs**: ADR-098 D1 (one engine), D3 (performer types — an agent
  completes a user task through the same verbs), D9 (the `advance` budget,
  and `"all"` never `null`); ADR-065 (the node joins the single engine);
  ADR-031 (declarative-vs-imperative — argued in design.md).
