# Tasks: or-flow-connectivity-and-last-run

- [ ] `IFlowTerminalNode` marker interface; `StopNode` implements it. A separate
      interface, NOT a method on `IFlowNode` — openconnector and hermiq
      implement `IFlowNode` from their own repos and would fatal on load
      (precedent: `IFlowNodeConfigKeys`, or-flow-preflight).
- [ ] Terminality resolved through `FlowNodeRegistry`, so a contributed terminal
      step needs no OpenRegister change. Honour `exit: true` on the node.
- [ ] Connectivity check: every non-exit node has ≥1 outgoing edge; report the
      offending node ids.
- [ ] Wire the check into `FlowNodePreflight` as a WARNING (never blocking), so
      `POST /api/flow/validate` and the save path both surface it.
- [ ] Save (`POST`/`PUT /api/flows`) stores the flow and returns the warning.
- [ ] `FlowService::run()` refuses a dead-ended flow: no `FlowRun` created,
      `status` = `error`, `status_message` names the nodes.
- [ ] Apply the same refusal on the trigger and schedule dispatch paths — a
      guard only on `POST /run` leaves cron-fired flows unguarded, which is most
      of them.
- [ ] Clear `status` back to `ok` when a run is accepted.
- [ ] Migration adding `status`, `status_message`, `last_run_uuid`,
      `last_run_status`, `last_run_message`, `last_run_at` — all nullable, no
      backfill.
- [ ] `Flow` entity + `FlowMapper` + `jsonSerialize()` carry the new fields
      (camelCase in the API, as the existing fields are).
- [ ] `FlowRunService` writes the last-run fields when a run reaches a terminal
      state — not per step, not on queue.
- [ ] Unit tests: exit by registered terminal type, exit by `exit: true`, dead
      end warns on save, dead end refuses to run, refusal sets status+message.
- [ ] Test the schedule/trigger refusal path specifically, with a positive
      control proving a well-formed scheduled flow still dispatches.
- [ ] Test last-run write-back on completion, on failure, and that a queued run
      does not overwrite it.

## Acceptance criteria

- A flow always either exits deliberately or reports an error — never stops silently.
- Saving a dead-ended flow succeeds and warns; running one is refused.
- The refusal applies to manual, trigger and schedule dispatch alike.
- A refused flow is distinguishable from a never-run flow without reading run history.
- Last-run fields are null for a flow that has never run, never invented by the migration.

## Quality checklist

- Tests run on the container's PHP 8.4, not the host.
- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- Depends on `or-flow-action-nodes` — the exit definition is meaningless until
  nodes carry step types.
