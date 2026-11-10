## ADDED Requirements

### Requirement: Agent flow action dispatches a typed cross-app event

`FlowActionService` SHALL support a flow action of `type: "agent"` in the
`x-openregister-flows` vocabulary. When such an action fires, the service
SHALL render the action's `prompt` template against the triggering object's
data (using the same `{{field}}` interpolation the `calendar-event`/`email`
actions already use) and dispatch an `AgentRunRequestedEvent` via
`IEventDispatcher::dispatchTyped()`. OpenRegister SHALL NOT invoke an agent
runtime inline and SHALL NOT call a consuming app's service directly (gate-27,
ADR-041).

#### Scenario: Agent action dispatches the event with the full payload

- **WHEN** a schema's `x-openregister-flows` declares a flow whose trigger
  matches, with an action `{ "type": "agent", "agent": "<uuid>", "skill":
  "<slug>", "prompt": "Classify {{name}}", "resultField": "categorySlug",
  "requiresApproval": true, "mode": "async" }`
- **THEN** `FlowActionService` dispatches one `AgentRunRequestedEvent` whose
  `subjectUuid`/`subjectRegister`/`subjectSchema` identify the triggering
  object, `agent` and `skill` match the config, `prompt` is the
  fully-rendered string (placeholders resolved), `resultField` matches the
  config, `requiresApproval` is `true`, `mode` is `"async"`, `flowName`
  matches the owning flow's name, and `correlationId` is a non-empty,
  per-dispatch-unique string

#### Scenario: Omitted optional fields default correctly

- **WHEN** an `agent` action config omits `skill`, `requiresApproval`, and `mode`
- **THEN** the dispatched event's `skill` is `null`, `requiresApproval` is
  `false`, and `mode` is `"async"`

### Requirement: Malformed agent action config is skipped without dispatching

`FlowActionService` SHALL treat an `agent` action missing a required field
(`agent` or `resultField`) as malformed configuration: it SHALL log a warning
identifying the missing field and SHALL NOT dispatch `AgentRunRequestedEvent`.
This mirrors the existing behaviour for an unrecognised action `type` and
preserves the flow engine's invariant that a failing/malformed action never
corrupts the triggering object's save.

#### Scenario: Missing agent reference is skipped and logged

- **WHEN** an `agent` action config omits `agent`
- **THEN** no `AgentRunRequestedEvent` is dispatched
- **AND** a warning is logged identifying the missing `agent` reference

#### Scenario: Missing resultField is skipped and logged

- **WHEN** an `agent` action config omits `resultField`
- **THEN** no `AgentRunRequestedEvent` is dispatched
- **AND** a warning is logged identifying the missing `resultField`

### Requirement: Only async mode is supported in v1

`FlowActionService` SHALL accept only `mode: "async"` for the `agent` action
in v1. Sync inline execution is explicitly out of scope
(SPECTR-NEXTCLOUD-PLAN.md §5.2 point 5). A config declaring any other `mode`
value SHALL be treated as malformed: logged and skipped, with no event
dispatched.

#### Scenario: Unsupported mode is skipped and logged

- **WHEN** an `agent` action config declares `"mode": "sync"`
- **THEN** no `AgentRunRequestedEvent` is dispatched
- **AND** a warning is logged identifying the unsupported mode

### Requirement: A throwing dispatch never corrupts the save or blocks other actions

`FlowActionService` SHALL catch a throwing `dispatchTyped()` call (e.g. a
misbehaving listener in a consuming app) via its existing per-action error
isolation, consistent with the existing flow engine contract. The triggering
object's save SHALL be unaffected and any remaining actions in the same flow
SHALL still execute.

#### Scenario: Dispatch failure does not block a later action

- **WHEN** a flow declares an `agent` action followed by an `email` action,
  and dispatching the agent action's event throws
- **THEN** the `email` action still executes
- **AND** no exception propagates out of `FlowActionService::run()`
