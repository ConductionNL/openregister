## ADDED Requirements

### Requirement: Apps contribute node types through a registration event (REQ-FN-001)

An app SHALL be able to add step types to the flow palette without any change
to OpenRegister. Discovery SHALL use a dispatched `RegisterFlowNodesEvent`,
mirroring core's `RegisterOperationsEvent`, so an app contributing a flow node
writes the same listener it would write for Nextcloud Flow.

The event SHALL be dispatched lazily — only when the node catalogue is needed —
and exactly once per request. OpenRegister's own built-in nodes SHALL be
contributed through the same event rather than seeded directly, so the
contribution path is exercised by its owner.

#### Scenario: An app contributes a node

- **GIVEN** an app listening for `RegisterFlowNodesEvent`
- **WHEN** the node catalogue is first read
- **THEN** the app's node appears in it

#### Scenario: Contribution is collected once per request

- **GIVEN** a registry whose catalogue is read three times
- **WHEN** those reads happen in one request
- **THEN** the registration event is dispatched exactly once

### Requirement: A node declares metadata the way an IOperation does (REQ-FN-002)

`IFlowNode` SHALL expose `getDisplayName()`, `getDescription()`, `getIcon()`
and `isAvailableForScope()` with the same meaning as
`OCP\WorkflowEngine\IOperation`, and SHALL use Nextcloud's own
`IManager::SCOPE_*` constants. A palette SHALL therefore be renderable from the
catalogue alone, with no hard-coded list of node types anywhere.

A node SHALL additionally provide the two things `IOperation` cannot:
`validateConfig()` and `execute()`.

#### Scenario: The palette is built from the catalogue

- **GIVEN** a registered node
- **WHEN** the palette is requested
- **THEN** each entry carries `id`, `displayName`, `description` and `icon`

#### Scenario: The palette respects scope

- **GIVEN** one admin-scoped node and one user-scoped node
- **WHEN** the palette is requested for the user scope
- **THEN** only the user-scoped node is offered

### Requirement: A node type is owned by exactly one app (REQ-FN-003)

A node id SHALL be namespaced by its owning app. Registering an id that is
already taken SHALL be refused and logged, and the first registration SHALL
win.

Allowing the second to overwrite would make the behaviour of a flow depend on
app load order, so which app's code ran would differ between instances — a
defect that only ever appears on someone else's system.

#### Scenario: A duplicate registration is refused

- **GIVEN** two apps registering the id `test.tag`
- **WHEN** the catalogue is read
- **THEN** the first registration is the one resolved
- **AND** the collision is logged as a warning

### Requirement: An unknown step type fails loudly (REQ-FN-004)

Resolving a step whose `type` no installed app provides SHALL throw, naming the
type and pointing at the likely cause. It SHALL NOT be skipped.

A skipped step produces a run that reports success while never having done the
work — the failure mode this codebase has repeatedly paid for.

A step carrying NO `type` at all SHALL pass its items through unchanged. That
is not leniency about unknown types; it is the pure-routing edge, where an
author drew an edge to shape the graph and asked for no work on it.

#### Scenario: An unknown type is refused

- **GIVEN** a step of type `ghost.step` that no app provides
- **WHEN** the run reaches it
- **THEN** it throws, naming `ghost.step`

#### Scenario: A routing-only edge passes items through

- **GIVEN** a step with no `type`
- **WHEN** it is dispatched
- **THEN** its input items are returned unchanged

### Requirement: Consumers need no dispatcher of their own (REQ-FN-005)

OpenRegister SHALL provide `RegistryStepDispatcher`, resolving each step's
`type` out of the catalogue and calling that node. A consuming app SHALL
contribute node types and nothing else — no dispatcher, no engine, no graph
walking.

A dispatcher containing a type switch is half an engine, which is how this
fleet acquired six of them.

#### Scenario: A step reaches the node that owns its type

- **GIVEN** a registered node `test.tag`
- **WHEN** a step of that type is dispatched with a config
- **THEN** that node's `execute()` receives the step's items and config
- **AND** its returned items are the step's output

### Requirement: A node is configured once and applied per item (REQ-FN-006)

A node SHALL receive the whole input item list and return its output list, so
one authored configuration applies to every item without the author drawing a
loop. Each output item SHALL carry a `pairedItem` naming the input it came
from.

#### Scenario: One configuration reshapes every item

- **GIVEN** three items and an "Edit fields" step setting `status`
- **WHEN** the step runs
- **THEN** all three items carry `status`
- **AND** each output item's `pairedItem` names its own input

### Requirement: A configuration that would do nothing is refused (REQ-FN-007)

`validateConfig()` SHALL be called when a flow is saved, not when it runs, and
SHALL reject a configuration under which the step could not do its job.

Catching it at save time means the author learns in the editor rather than from
a scheduled run at 3am, and a step that silently does nothing is worse than one
that refuses to save.

#### Scenario: An empty "Edit fields" configuration is rejected

- **GIVEN** an "Edit fields" step with nothing to set, rename or remove
- **WHEN** the flow is saved
- **THEN** validation fails and names what is missing

### Requirement: Nextcloud Flow can start an OpenRegister flow (REQ-FN-008)

OpenRegister SHALL register a `Run an OpenRegister flow` operation on core's
workflow engine, so any Nextcloud Flow rule can be a flow's entry point. The
operation SHALL refuse a rule that names no flow.

The reverse bridge SHALL NOT be built: an `IOperation` cannot be invoked as a
flow step, because `onEvent()` returns void and receives an event rather than
data. Faking it would mean synthesising an `Event` and an `IRuleMatcher` and
discarding output that does not exist.

The flow SHALL NOT be executed inline in the operation. A Flow operation runs
inside the dispatch of the event that triggered it — often a file write or a
share change — and an arbitrary graph does not belong on the critical path of a
user action.

#### Scenario: A rule naming no flow is rejected

- **GIVEN** a Nextcloud Flow rule using this operation with an empty flow id
- **WHEN** the rule is saved
- **THEN** validation fails asking for a flow

@e2e exclude node contribution and dispatch are backend-only — covered by PHPUnit; the palette's rendering is covered by the flow editor's own tests
