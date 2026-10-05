---
kind: code
depends_on: [flow-user-task-node, flow-parallel-streams]
---

# Proposal: flow-heartbeat-recovery

## Summary

Make the user-task heartbeat honest: a suspended run whose completion signal
was refused or lost must recover on its next heartbeat wake instead of
re-suspending forever. The recovery mechanism already exists — the node
re-reads its task on every re-entry — but the state it depends on did not
survive: a pass that ends `queued` dropped every parked node's resume slot,
losing the uuid of the task the node was waiting on. This change keeps the
slots for every run that can still advance, and records a heartbeat-recovered
delivery on the task's audit so the trail no longer ends at the refusal.

## Why

**Observed on the acceptance rig, wedged forever.** A UserTask's completion
signal was refused (`[FlowRunSignalService] Refused a signal: the actor is
not the awaiting step's assignee` — the assignee group did not exist at
signal time). The suspended run's 30-minute heartbeat then fired, re-suspended
for another 30 minutes, and never advanced: `resume_at` rolled 08:07 → 08:37
→ … while the task sat `completed` and the group had long been created. The
heartbeat exists precisely to recover a missed wake; it recovered nothing.

**The node was never the problem.** `UserTaskNode::execute()` has always
re-read its task through `FlowTaskBridge::taskOrNull()` on every re-entry and
applied the outcome when the task is terminal — the unit suite proves it, and
a run driven through the real engine, dispatcher and stream commit path
recovers correctly (`FlowHeartbeatRecoveryTest`). What wedges is upstream:

**`persistResult()` dropped every parked node's resume slot whenever a pass
ended anything but `suspended`.** `FlowResumeState::storableWhen(suspended:)`
read NOT-SUSPENDED as "nothing left to continue from", which conflates it
with TERMINAL. A pass legitimately ends `queued` while a node parked in an
EARLIER pass still waits: the in-request advance of a sibling branch
(`FlowTaskBridge::continueRun()` → `advanceStream()`) finalises `queued`
whenever other enabled work remains, and a claim refused on contention does
the same. The parked user-task node then lost its `taskUuid` slot; its next
wake found an empty slot and — exactly as its own guard demands — created a
NEW task rather than re-reading the original. From that moment:

- the completion of the ORIGINAL task could never address the node's slot,
  and its signal was refused against the new slot's recorded assignee — the
  refusal observed on the rig;
- every heartbeat re-read the NEW, open task and re-suspended, rolling
  `resume_at` forever;
- a duplicate task sat in somebody's inbox.

`FlowHeartbeatRecoveryTest::testAnInRequestAdvanceKeepsTheSiblingNodesParkedSlot`
reproduces the drop red on the unfixed code.

## What changes

1. **Slots survive every live pass end.** `FlowResumeState::storableWhen()`
   now keeps the per-node slots for any non-terminal status (`suspended`,
   `queued`, `running`) and drops them only when the run is terminal.
   `persistResult()` derives that from `FlowRun::TERMINAL`.
2. **A recovered delivery is recorded.** When `UserTaskNode` or
   `PortalTaskNode` reads its task terminal WITHOUT a signal in hand (no
   `context['signal']` — the completion's wake never arrived), it records
   `heartbeat-recovered` on the task's audit through the new
   `FlowTaskBridge::recordHeartbeatRecovery()`, attributed to the task's
   `completedBy`. Best-effort: an audit failure never un-recovers the run.
3. **Nothing else.** No second delivery mechanism, no new sweep, no new
   column: the heartbeat wake, the node re-read and the outcome application
   are exactly the paths that already existed.

## Out of scope

Runs already wedged before this fix (slot lost, duplicate task created)
cannot be recovered retroactively: the original task's uuid is gone from the
run. They end at the abandoned-signal reaper or by manual retry, as today.
