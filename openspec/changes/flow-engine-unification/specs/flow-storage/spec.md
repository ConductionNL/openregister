# flow-storage

## ADDED Requirements

### Requirement: One native flow store

The system SHALL persist flow definitions in a single native table
(`oc_openregister_flows`), owned by OpenRegister. A flow definition SHALL NOT be
stored as an OpenRegister object, and SHALL NOT require a register or schema to
exist. No other app SHALL own a flow store, a flow controller, or a flow execution
service.

#### Scenario: A flow persists without any register or schema
- **WHEN** a flow is created on an instance where the `flows` register and `flow` schema do not exist
- **THEN** the flow is stored and retrievable, and no register or schema is created.

#### Scenario: Definitions live alongside the rest of the engine
- **WHEN** the flow tables are inspected
- **THEN** definitions, runs, run steps, links and state are all native tables, and no flow definition is readable through the objects API.

### Requirement: Per-app scoping

Every flow SHALL carry the id of the Nextcloud app that owns it in an `app` column.
Listing flows SHALL accept an `app` filter. An unfiltered list SHALL return flows of
every app.

#### Scenario: An app sees only its own flows
- **WHEN** a client lists flows with `app=openconnector`
- **THEN** only flows whose `app` is `openconnector` are returned, and a flow owned by `hermiq` is absent.

#### Scenario: OpenRegister sees every flow
- **WHEN** a client lists flows with no `app` filter
- **THEN** flows owned by every app are returned, including those owned by `hermiq` and `openconnector`.

### Requirement: A flow without an owner does not dispatch

Every flow SHALL carry the Nextcloud UID of the person who authored it. A trigger
fires with no acting user, so the owner is the identity a run is attributed to and
executes as. A flow whose owner is absent or empty SHALL NOT be dispatched by any
trigger or schedule, and SHALL NOT be defaulted to an empty, system or admin owner.

#### Scenario: An ownerless flow is refused at dispatch
- **WHEN** a trigger matches a flow whose `owner` is null
- **THEN** no run is queued, and the refusal is recorded with the reason.

### Requirement: Bounded execution

A flow MAY declare `limits` (`maxNodes`, `maxIterations`). The engine SHALL stop a
walk that exceeds either ceiling and record the run as stopped-by-limit rather than
continuing or looping without bound.

#### Scenario: A cyclic flow terminates
- **WHEN** a flow whose edges form a cycle is run with `limits.maxNodes` set to 10
- **THEN** the walk stops after at most 10 node executions and the run's status records that a limit stopped it.

### Requirement: Flows are organisation-scoped and guarded per flow

Flow queries SHALL be scoped to the requesting user's organisation. Running,
updating and deleting a flow SHALL each verify that the requesting user may act on
that specific flow, rather than relying on the endpoint's authentication posture
alone.

#### Scenario: A flow of another organisation is not listable
- **WHEN** a user lists flows while their organisation differs from a stored flow's organisation
- **THEN** that flow is absent from the response.

#### Scenario: Running another user's flow by id is refused
- **WHEN** an authenticated non-admin user posts a run for a flow id belonging to another organisation
- **THEN** the request is refused and no run is queued, with the same response a genuinely absent flow produces so the endpoint cannot be used to enumerate other tenants' flow ids.

#### Scenario: An unattributed flow or caller fails closed
- **WHEN** a flow carries no organisation, or the caller has no resolvable organisation
- **THEN** the check refuses rather than treating the blank value as a wildcard.

### Requirement: Apps hook in through node contribution only

An app SHALL extend the engine by contributing node types through
`RegisterFlowNodesEvent`. The engine SHALL NOT provide a per-app resolution
indirection for locating flow documents.

#### Scenario: A contributed node is executable
- **WHEN** an app registers a node type and a flow uses it
- **THEN** the node appears in the node catalogue and the engine executes it during a run.

### Requirement: Node type ids are namespaced end to end

A node's stored `type` SHALL be the catalogue id exactly as the node registry
publishes it (`{app}.{node}`). Every consumer that dispatches on, labels, or renders
configuration for a node type SHALL match that same id. A node type the engine cannot
resolve SHALL fail its step visibly rather than being skipped while the run reports
success.

#### Scenario: An unresolvable node type fails the step
- **WHEN** a flow contains a node whose `type` matches no registered node
- **THEN** that step is recorded as failed with the unresolved type in its error, and the run does not report success.

#### Scenario: A node placed from the palette executes
- **WHEN** a node is dragged from the builder palette and the flow is run
- **THEN** the node's registered implementation executes, and its configuration pane in the builder is the one registered for that same catalogue id.

## MODIFIED Requirements

### Requirement: `x-openregister-flows` declares flows of the engine type

A schema's `x-openregister-flows` SHALL declare flows of the node/edge engine type,
imported into the flow store on register import and scoped to the declaring app and
schema. It SHALL NO LONGER declare the flat `{ name, trigger, actions[] }` action
list, and the service that executed that dialect SHALL be removed.

#### Scenario: A register file ships a flow
- **WHEN** an app's register file declares `x-openregister-flows` on a schema and the register is imported
- **THEN** each declared flow is stored in the flow store with `app` set to the declaring app and its trigger schema set to that schema.

#### Scenario: The old dialect is gone
- **WHEN** a schema declares the legacy `{ name, trigger, actions[] }` shape
- **THEN** no action-list engine executes it, because no such engine exists.
