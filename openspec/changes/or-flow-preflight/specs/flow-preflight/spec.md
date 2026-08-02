## ADDED Requirements

### Requirement: A flow's step types are resolved when it is stored, not when it runs (REQ-PF-001)

When an object shaped like a flow graph is created or updated, OpenRegister SHALL
resolve every `edges[].type` it declares against the live `FlowNodeRegistry`
before the object is persisted. An edge with no `type` is a pass-through and
SHALL be skipped, matching `RegistryStepDispatcher`.

A type that does not resolve SHALL be classified by the app that owns it, which
the `<app>.<node>` namespacing contract on `IFlowNode::getId()` makes derivable:

- When the owning app IS enabled on this instance, the save SHALL be REFUSED. No
  installation can supply the type — the app is present and does not have it — so
  this is version drift or a typo, and never a legitimate absence.
- When the owning app is NOT enabled, the save SHALL proceed and a structured
  warning SHALL be logged naming the flow, the edge, the type and the app.
- A type carrying no `<app>.` namespace SHALL be refused, since no app can ever
  provide it.

A refusal SHALL name every offending type in one message, not the first only.

The graph's SHAPE SHALL NOT be validated at save time: a flow being drawn is
legitimately half-connected, and refusing a draft would make the editor unusable.

#### Scenario: A type missing from an app that is installed is refused

- **GIVEN** openregister is enabled and does not provide `openregister.explode`
- **WHEN** a flow naming that type on an edge is saved
- **THEN** the save is refused and the message names the type, the edge and the app

#### Scenario: A type owned by an app that is not installed is allowed

- **GIVEN** openconnector is not enabled
- **WHEN** a flow naming `openconnector.source-call` is imported
- **THEN** the object is stored and a warning naming the app is logged

#### Scenario: An unnamespaced type is refused

- **GIVEN** a flow edge whose type is `explode`
- **WHEN** the flow is saved
- **THEN** the save is refused, because no app can own an unnamespaced type

#### Scenario: An ordinary object is not treated as a flow

- **GIVEN** an object with no `nodes`/`edges` graph
- **WHEN** it is saved
- **THEN** preflight does not run and the save is unaffected

@e2e exclude backend save-path guard — covered by FlowNodePreflightTest,
FlowNodePreflightListenerTest and FlowNodePreflightRegressionTest, the last of
which replays or#2247 (the `hydra-file-findings` graph verbatim against a registry
holding every node an or#2244 instance had) through the real FlowNodeRegistry,
the real preflight and the real ObjectCreatingEvent, with a positive control
proving the same document saves once the node exists

### Requirement: A flow document can be preflighted without being saved (REQ-PF-002)

OpenRegister SHALL expose `POST /api/flow/validate`, which resolves a submitted
flow document's step types and returns `{valid, blocking, warnings}` plus a
`message` when anything blocks. It SHALL answer 200 for a document that cannot
run — an unrunnable flow is a valid answer, not a failed request — and 400 only
when the body does not describe a flow graph at all. It SHALL use the same
`FlowNodePreflight` as the save-path guard so the two answers cannot drift.

#### Scenario: A blocked document reports its findings

- **GIVEN** a flow naming a type its enabled owner does not provide
- **WHEN** it is posted to the validate endpoint
- **THEN** the response is 200 with `valid: false` and the finding listed

#### Scenario: A body that is not a graph is rejected

- **GIVEN** a body with no nodes or edges
- **WHEN** it is posted to the validate endpoint
- **THEN** the response is 400

@e2e exclude read-only backend endpoint — covered by FlowControllerTest
