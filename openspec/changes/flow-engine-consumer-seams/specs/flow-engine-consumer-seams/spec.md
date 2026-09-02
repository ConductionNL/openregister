## Purpose

The two seams every consuming app was re-implementing: a guarded server-side
signal verb, and native acting-identity scoping for contributed nodes.

## ADDED Requirements

### Requirement: A server-side signal passes the same guard as the HTTP resume

The engine SHALL provide a guarded server-side signal verb —
`FlowRunSignalService::signalAs()` — that resumes a suspended run on behalf
of a NAMED actor. It SHALL apply the same recorded-assignee rule as the HTTP
resume endpoint, group resolution included, through the same implementation
(`FlowRunAssignee`), so the two paths cannot drift.

A refusal SHALL be a typed exception naming its reason (run not found, actor
not the assignee, run not suspended) and SHALL be audited with the run, the
actor and the recorded assignee. A refused signal SHALL NOT touch the run.

The unguarded primitive `FlowRunService::signal()` SHALL remain for
engine-internal delivery and SHALL be documented as such; consumers outside
the engine use the guarded verb.

A caller MAY name the node its answer addresses. The guard then SHALL check
that node's recorded assignee; naming a node that is not awaiting an answer
SHALL fall back to the run-level rule, so addressing can never loosen the
guard. A step that records no assignee remains answerable by anyone, per the
existing contract.

The HTTP resume endpoints SHALL delegate to this seam, so exactly one guard
exists.

#### Scenario: The recorded assignee may answer
- **GIVEN** a run suspended on a step assigned to alice
- **WHEN** `signalAs` is called with actor alice
- **THEN** the run MUST become due and carry the payload
- @e2e exclude server-side PHP seam — covered by FlowRunSignalServiceTest

#### Scenario: A member of the assigned group may answer
- **GIVEN** a run suspended on a step assigned to group reviewers
- **WHEN** `signalAs` is called with an actor who is in reviewers
- **THEN** the run MUST become due and carry the payload
- @e2e exclude server-side PHP seam — covered by FlowRunSignalServiceTest

#### Scenario: A stranger is refused and the run is untouched
- **GIVEN** a run suspended on a step assigned to alice
- **WHEN** `signalAs` is called with actor mallory
- **THEN** the call MUST be refused with reason not-assignee
- **AND** the run MUST remain suspended with no payload delivered
- **AND** the refusal MUST be audited
- @e2e exclude server-side PHP seam — covered by FlowRunSignalServiceTest

#### Scenario: An unassigned step is answerable by anyone
- **GIVEN** a run suspended on a step that records no assignee
- **WHEN** `signalAs` is called with any actor
- **THEN** the run MUST become due
- @e2e exclude server-side PHP seam — covered by FlowRunSignalServiceTest

#### Scenario: Addressing a node checks that node's assignee
- **GIVEN** a run awaiting node A (assigned to alice) and node B (assigned to bob)
- **WHEN** `signalAs` is called with actor bob naming node B
- **THEN** the run MUST become due
- @e2e exclude server-side PHP seam — covered by FlowRunSignalServiceTest

#### Scenario: Addressing a silent node cannot loosen the guard
- **GIVEN** a run awaiting node A, assigned to alice
- **WHEN** `signalAs` is called with actor mallory naming a node that is not asking
- **THEN** the call MUST be refused with reason not-assignee
- @e2e exclude server-side PHP seam — covered by FlowRunSignalServiceTest

### Requirement: A contributed node executes under the run's acting identity

The step dispatcher SHALL execute every CONTRIBUTED node (one whose class is
not the engine's own) inside `ObjectService::runAs()` scoped to the run's
`runAs` identity, so every read and write the node performs inherits the
run's rights without the contributing app building a wrapper.

The identity SHALL be validated at execution time: an identity that resolves
to no account, or to a disabled account, SHALL refuse the step loudly rather
than execute it as anyone else. A run whose context names NO identity SHALL
run the node bare, under the ambient session — the interactive path.

The scoping SHALL narrow, never grant: a run whose identity lacks a right is
still refused it. The engine's own nodes, which already scope themselves,
SHALL be unaffected. A contributed node that must manage its own identity
MAY declare so via `IFlowSelfScopedNode`, the documented escape hatch.

The engine SHALL export the context key under which the acting identity
travels (`FlowRunService::RUN_AS_CONTEXT_KEY`), and its value SHALL remain
`runAs` — it is stored inside parked runs' contexts and cannot move.

#### Scenario: A contributed node's write executes as the run owner
- **GIVEN** a run whose runAs is alice, an enabled account
- **WHEN** the dispatcher executes a contributed node
- **THEN** the node MUST execute inside `ObjectService::runAs(alice)`
- @e2e exclude dispatcher-internal — covered by RegistryStepDispatcherRunAsTest

#### Scenario: An unresolvable identity refuses loudly
- **GIVEN** a run whose runAs names no existing account
- **WHEN** the dispatcher executes a contributed node
- **THEN** the step MUST be refused with a message naming the identity
- @e2e exclude dispatcher-internal — covered by RegistryStepDispatcherRunAsTest

#### Scenario: A disabled identity refuses loudly
- **GIVEN** a run whose runAs names a disabled account
- **WHEN** the dispatcher executes a contributed node
- **THEN** the step MUST be refused with a message naming the identity
- @e2e exclude dispatcher-internal — covered by RegistryStepDispatcherRunAsTest

#### Scenario: The engine's own nodes are unaffected
- **GIVEN** a run whose runAs is set
- **WHEN** the dispatcher executes one of the engine's own nodes
- **THEN** the dispatcher MUST NOT wrap it — the node scopes itself
- @e2e exclude dispatcher-internal — covered by RegistryStepDispatcherRunAsTest

#### Scenario: A self-scoped contributed node runs bare
- **GIVEN** a contributed node declaring `IFlowSelfScopedNode`
- **WHEN** the dispatcher executes it on a run whose runAs is set
- **THEN** the dispatcher MUST NOT wrap it
- @e2e exclude dispatcher-internal — covered by RegistryStepDispatcherRunAsTest
