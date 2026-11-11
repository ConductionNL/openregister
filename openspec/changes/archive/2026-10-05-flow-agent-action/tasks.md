## 1. Event contract

- [x] 1.1 Create `lib/Event/AgentRunRequestedEvent.php` — provenance
      (`subjectUuid`/`subjectRegister`/`subjectSchema`) + requested-run payload
      (`agent`, `skill`, `prompt`, `resultField`, `requiresApproval`, `mode`,
      `flowName`, generated `correlationId`) + `getPayload()` flattener.
- [x] 1.2 Unit tests for the event: getters, payload flattening, correlation-id
      uniqueness per instance (`tests/Unit/Event/AgentRunRequestedEventTest.php`).

## 2. `FlowActionService` wiring

- [x] 2.1 Inject `IEventDispatcher` into `FlowActionService`'s constructor.
- [x] 2.2 Add the `agent` case to `runAction()`'s switch and implement
      `runAgent()`: validate `agent`/`resultField`/`mode`, render the prompt via
      the existing `render()` helper, build the event, dispatch via
      `dispatchTyped()`.
- [x] 2.3 Malformed config (missing `agent`, missing `resultField`, unsupported
      `mode`) logs a warning naming the field and skips the dispatch — mirrors
      the existing "Unknown flow action type" warning shape.

## 3. Tests

- [x] 3.1 Happy path: `type: "agent"` action dispatches `AgentRunRequestedEvent`
      with the exact rendered/derived payload (agent/skill/prompt/resultField/
      requiresApproval/mode/flowName/correlationId).
- [x] 3.2 Defaults: omitted `mode`/`requiresApproval`/`skill` resolve to
      `"async"`/`false`/`null`.
- [x] 3.3 Malformed config: missing `agent` → skipped + logged; missing
      `resultField` → skipped + logged; unsupported `mode` (e.g. `"sync"`) →
      skipped + logged. Dispatcher never called in any case.
- [x] 3.4 Failure isolation: a throwing `dispatchTyped()` call does not block a
      later action in the same flow and never throws into the caller.
- [x] 3.5 Existing calendar-event/email/unknown-type tests updated for the new
      constructor param (`IEventDispatcher` mock) and confirmed unaffected.

## Acceptance criteria

- `FlowActionService` supports a fourth flow action `type: "agent"` alongside
  `calendar-event`/`agenda-task` and `email`/`mail`.
- `AgentRunRequestedEvent` carries the exact field set in `design.md`'s event
  contract table — this is the contract the companion Hermiq change
  (`feat/flow-agent-listener`) listens against; field names and types must
  match verbatim.
- Malformed `agent` action config is logged and skipped without dispatching —
  it never corrupts the triggering object's save (existing flow semantics).
- `mode` other than `"async"` is treated as malformed config in v1.
- No new OR public API surface, no schema migration, no direct call from
  OpenRegister into any consuming app (gate-27 compliance).

## Quality checklist

- ADR-041: cross-app command via a typed `IEventDispatcher` event; OpenRegister
  never resolves or calls a sibling app's service directly.
- ADR-031: no new business-logic service added; see design.md's
  "Declarative-vs-imperative decision" section for the ADR-031 external-API
  carve-out this change relies on.
- ADR-020: diff-scoped — the pre-existing `calendar-event`/`email` actions
  stay `@spec exclude`d (unchanged); only the new `agent` action + event are
  specced here.
- Existing flow semantics preserved: a failing/malformed action never corrupts
  the triggering save; the remaining actions/flows still run.
