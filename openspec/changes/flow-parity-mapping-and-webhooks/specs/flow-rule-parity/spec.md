# flow-rule-parity

## ADDED Requirements

### Requirement: Anything a rule can do, a flow can do

Every capability reachable through `EndpointService::processRules()` SHALL also be
reachable as a flow node. An integration SHALL NOT be forced into the endpoint
model in order to reach a single capability.

This does NOT deprecate the rule pipeline. Endpoints remain the HTTP surface; the
requirement is that the flow engine is not missing capabilities the other
orchestration model already has, because as long as it is, the fleet has two
orchestration models and the choice between them is made by capability rather
than by fit.

#### Scenario: The vocabularies match
- **WHEN** the rule types dispatched by the endpoint pipeline are compared with the node types in the flow catalogue
- **THEN** every rule type except the `custom` escape hatch has a corresponding node.

#### Scenario: An integration is not forced to change model
- **WHEN** an integration needs synchronisation, a file write and a mapping
- **THEN** all three are available as flow nodes, and the integration does not have to be rebuilt as an endpoint rule chain.

### Requirement: Capabilities are contributed, not special-cased

Each new node SHALL be contributed by the app that owns the capability, through
the existing `RegisterFlowNodesEvent`. The engine SHALL NOT gain knowledge of any
specific app, and SHALL NOT branch on node type.

OpenConnector SHALL contribute the integration nodes (`synchronization`,
`extend_external_input`, `download`, the file-part pair). OpenRegister SHALL
contribute those that operate on its own data (`extend_input`, `locking`,
`audit_trail`, `write_file`, `javascript`).

#### Scenario: The engine has no app knowledge
- **WHEN** the engine source is inspected after the new nodes ship
- **THEN** it references no app id and no specific node type, and dispatch is still by registry lookup.

#### Scenario: A missing contributor degrades honestly
- **WHEN** a flow uses a node type whose contributing app is not installed
- **THEN** the step fails naming the unresolved node type, rather than being skipped as a no-op.

### Requirement: A node that cannot run says so

A node that cannot perform its work SHALL fail its step. It SHALL NOT complete
having done nothing.

A skipped step inside a completed run is indistinguishable from one that ran and
had no effect. That ambiguity is the failure this whole programme exists to
remove, and every node added under this change inherits the obligation.

#### Scenario: An unperformable step fails rather than passes
- **WHEN** any node added by this change cannot complete its work — a missing file, an unreachable source, an unresolvable reference
- **THEN** the run records that step as failed with a reason, and does not record it as completed.
