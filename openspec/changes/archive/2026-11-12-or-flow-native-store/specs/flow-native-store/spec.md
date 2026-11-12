## ADDED Requirements

### Requirement: OpenRegister resolves flows stored as its own objects (REQ-NS-001)

OpenRegister SHALL contribute a flow resolver, registered through
`RegisterFlowResolversEvent`, that resolves flows stored as OpenRegister objects
in a configurable register and schema (`flow_register` / `flow_schema`,
defaulting to `flows` / `flow`). It SHALL return a flow's `{nodes, edges}`, load a
run's subject, and list the enabled flows wired to a fired event. An object that
is not shaped like a flow SHALL NOT be resolved as one, and an absent flow store
SHALL resolve to nothing rather than error.

#### Scenario: A flow object resolves

- **GIVEN** a flow object (with nodes and edges) in the flow store
- **WHEN** its id is resolved
- **THEN** its flow document is returned

#### Scenario: A non-flow object is not a flow

- **GIVEN** an object in the flow store with no nodes or edges
- **WHEN** its id is resolved
- **THEN** the resolver returns null

#### Scenario: No flow store, no triggers

- **GIVEN** no flow register exists
- **WHEN** flows for an event are listed
- **THEN** the result is empty and no error is raised

@e2e exclude backend resolver — covered by OpenRegisterFlowResolverTest and
live-verified on 8080 (resolved a real flow-shaped object through the OR resolver;
absent store returned null); the flow-authoring UI is a separate change
