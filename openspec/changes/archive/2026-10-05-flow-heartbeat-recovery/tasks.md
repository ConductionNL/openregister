# Tasks: flow-heartbeat-recovery

## 1. Keep the slots alive

- [x] 1.1 `FlowResumeState::storableWhen()` keeps the per-node slots for any
      non-terminal status and drops them only on a terminal one; parameter
      renamed `suspended` → `live` so the call site states the rule.
- [x] 1.2 `FlowRunService::persistResult()` derives liveness from
      `FlowRun::TERMINAL` and passes it through, with a comment naming the
      wedge the old `suspended`-only rule caused.

## 2. Record the recovery

- [x] 2.1 `FlowTaskBridge::recordHeartbeatRecovery()`: append a
      `heartbeat-recovered` entry on the task's audit via
      `TaskService::record()`, actor = the task's `completedBy`, reason
      naming the run; log the recovery; swallow and log an audit failure.
- [x] 2.2 `UserTaskNode::execute()`: on a terminal read with no
      `context['signal']`, call `recordHeartbeatRecovery()` before applying
      the outcome, which stays identical on both paths.
- [x] 2.3 `PortalTaskNode::execute()`: the same call on its first pass over
      a terminal task — the two nodes share the wedge.

## 3. Prove it

- [x] 3.1 `tests/Unit/Service/Flow/FlowHeartbeatRecoveryTest.php` — the
      wedge reproduction driven through the real engine, dispatcher, node,
      stream walk, claims and commit over in-memory mappers:
      the in-request advance keeps the sibling's slot (RED on the unfixed
      `storableWhen`), the heartbeat recovers a refused signal with
      attribution, a still-open task re-parks unchanged, and only the
      addressed node's slot recovers.
- [x] 3.2 `UserTaskNodeTest` — a heartbeat-recovered completion is audited
      to the completer; a signal-delivered completion records no recovery.
- [x] 3.3 `PortalTaskNodeTest` — the same pair for the portal node.
- [x] 3.4 `FlowResumeStateTest` — `storableWhen()` keeps slots while live,
      drops them on terminal, stores nothing when empty.
