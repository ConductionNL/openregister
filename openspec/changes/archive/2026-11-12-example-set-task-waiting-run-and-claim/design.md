## Context

A live `openregister.user-task` wait, read from a running instance, is a `flow_runs` row with:

- `status` `suspended` and `resume_at` set to the next heartbeat (15 minutes by default, never null);
- `marking` `{"<step>": 1}`, and `place_items` and `items` holding one item;
- `context` with `runUuid`, `flowId`, `triggeredBy` and `resumeState.<step>`, the step's resume slot, holding `taskUuid` and `askedAt`.

The task carries `runUuid` and `nodeId`. When the task ends, `FlowTaskBridge::continueRun()` signals the run. When no stream row stands on the step, the run is left due and the worker continues it from its marking.

## Decisions

### D-1 A seeded waiting run is a live wait, not a flagged one (open question 1)

No new status and no flag. The loader writes the same row the user-task step writes, so the worker needs no exception for it:

- The heartbeat (`findDue`) re-enters the step. The step reads `taskUuid` from its slot, finds the task open and parks again. This is the path every live wait takes.
- `findAbandonedSignals` only reaps suspended runs whose `resume_at` is null. A filled seeded run always has a heartbeat, so it is never reaped.
- Completing the task signals the run, and the run goes on to the next step like a live one.

The run is written with `resume_at` null and an empty slot. Writing the task fills the slot and sets the heartbeat. The flows part is written before the tasks part, so the run exists when the task quotes it. A waiting run that no task in its fragment names is refused at check time, so a run can never stay behind with an empty slot.

No stream row is written. `continueRun()` already falls back to the worker when no stream stands on the step, and `FlowService::deleteStored()` does not sweep stream rows, so writing one would leave it behind on remove.

### D-2 Only an `openregister.user-task` step may be waited on

The slot key the loader fills is the user-task step's own (`FlowTaskBridge::SLOT_TASK_UUID`). An app's own wait step keeps its task under a key of its own (dossiq's ask step uses `taskId`), so filling `taskUuid` there would leave that step without its task. The check refuses a waiting run on any other step type. Supporting another step type means declaring its slot key, which is a later change.

### D-3 The run is quoted through the flow reference, not a new token

The flow's record entry becomes `{uuid, runs: {<run key>: <run uuid>}}`. `{{flow:<flow>.runs.<run>}}` resolves through the existing `flow` reference, which already walks a dotted path. At check time the flow stand-ins carry a stand-in uuid per run key, so a task that quotes a run fills and an unknown run is refused as "cannot resolve". A refresh keeps the runs map, because the runs stay with a flow that is updated in place.

### D-4 The claim is an audit entry, not the claim verb (open question 2)

`TaskService::claim()` only claims an unassigned task, in a conditional update, as the claiming account. A seeded task is born assigned, so calling the verb would fail, and re-dating its audit row is impossible because the audit is append-only. The loader appends one `claim` row through `TaskAuditMapper::insert()`, which accepts a preset date: action `claim`, actor `claimedBy`, state after the task's state, dated `claimedAt`. `claimedBy` must be the task's assignee and an account the set creates or adopts, and `claimedAt` is a date that is not in the future.

### D-5 Remove (open question 3)

Remove reverts the tasks part before the flows part. The task goes first, then `FlowService::deleteStored()` takes the flow with its runs and steps. The audit rows of a deleted task go with it in the engine, as for any task.

## Risks

- A seeded run on a flow that is switched off is still resumed by the worker. This is intended: the wait is real, and the step only parks again.
- The slot's `askedAt` is the install moment, not the task's declared history. The page reads the task's own `created` for "since", so nothing shows it.
