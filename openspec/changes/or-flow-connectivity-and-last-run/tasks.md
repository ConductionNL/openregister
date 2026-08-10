# Tasks: or-flow-connectivity-and-last-run

- [x] `IFlowTerminalNode` marker interface; `StopNode` implements it. A separate
      interface, NOT a method on `IFlowNode` — openconnector and hermiq
      implement `IFlowNode` from their own repos and would fatal on load
      (precedent: `IFlowNodeConfigKeys`, or-flow-preflight).
- [x] Terminality resolved through `FlowNodeRegistry`, so a contributed terminal
      step needs no OpenRegister change. Honour `exit: true` on the node.
- [x] Connectivity check: every non-exit node has ≥1 outgoing edge; report the
      offending node ids.
- [x] Wire the check into `FlowNodePreflight` as a WARNING (never blocking), so
      `POST /api/flow/validate` and the save path both surface it.
- [x] Save (`POST`/`PUT /api/flows`) stores the flow and returns the warning.
- [x] `FlowService::run()` refuses a dead-ended flow: no `FlowRun` created,
      `status` = `error`, `status_message` names the nodes. Implemented in
      `FlowRunService::queue()`, the single choke point `run()` and every other
      dispatch path delegate to.
- [x] Apply the same refusal on the trigger and schedule dispatch paths — a
      guard only on `POST /run` leaves cron-fired flows unguarded, which is most
      of them. Covered by guarding `queue()`; the schedule sweep additionally
      catches PER FLOW, so one refusal cannot stop later due flows from firing.
- [x] Clear `status` back to `ok` when a run is accepted.
- [x] Migration adding `status`, `status_message`, `last_run_uuid`,
      `last_run_status`, `last_run_message`, `last_run_at` — all nullable, no
      backfill.
- [x] `Flow` entity + `FlowMapper` + `jsonSerialize()` carry the new fields
      (camelCase in the API, as the existing fields are).
- [x] `FlowRunService` writes the last-run fields when a run reaches a terminal
      state — not per step, not on queue.
- [x] Unit tests: exit by registered terminal type, exit by `exit: true`, dead
      end warns on save, dead end refuses to run, refusal sets status+message.
      `FlowDeadEndTest` pins the connectivity verdict and the refusal message,
      each with a positive control proving the same graph goes quiet once wired.
- [ ] Test the schedule/trigger refusal path specifically, with a positive
      control proving a well-formed scheduled flow still dispatches.
      **NOT DONE** — needs `FlowRunService` constructed with a mocked container,
      flow mapper and preflight. The verdict it consumes is covered by
      `FlowDeadEndTest`; the dispatch wiring itself is not yet pinned.
- [ ] Test last-run write-back on completion, on failure, and that a queued run
      does not overwrite it. **NOT DONE** — same harness as above.

## Verification note — why the suite was not run locally

The unit suite could not be executed against this change on this machine. Once
`lib/base.php` loads, Nextcloud's app autoloader resolves `OCA\OpenRegister\*`
to the **installed** app under `/var/www/html/custom_apps/openregister`, not to
the working copy under test — measured directly with
`ReflectionClass::getFileName()`.

CI's "copy the app OUT of /var/www/html" step does not prevent this. CI is
immune only because it DEPLOYS the code under test first, so the installed copy
and the copy under test are identical. Run that same recipe locally against an
older deployment and the suite reports on the DEPLOYED app: a green that says
nothing about the change. The suite therefore runs in CI, which is the
authoritative gate for this change.

## Acceptance criteria

- A flow always either exits deliberately or reports an error — never stops silently.
- Saving a dead-ended flow succeeds and warns; running one is refused.
- The refusal applies to manual, trigger and schedule dispatch alike.
- A refused flow is distinguishable from a never-run flow without reading run history.
- Last-run fields are null for a flow that has never run, never invented by the migration.

## Quality checklist

- Tests run on the container's PHP 8.4, not the host.
- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
