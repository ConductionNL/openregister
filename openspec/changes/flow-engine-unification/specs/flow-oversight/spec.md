# flow-oversight

## ADDED Requirements

### Requirement: Per-hop auditing is engine-wide and optional

The engine SHALL be able to write an audit-trail entry for every node
execution, for **every** node type rather than only for nodes that run agents.
It SHALL be configurable by an administrator app setting and overridable per
flow, and SHALL default to **off** at the instance level, because one entry per
node per run is write volume that most flows do not need — the run step rows
already carry the operational history.

#### Scenario: Auditing covers node types that are not agent steps
- **WHEN** a flow with auditing enabled runs a graph of object-write and routing nodes
- **THEN** an audit-trail entry is written for each of those hops, not only for agent hops.

#### Scenario: Auditing is off unless asked for
- **WHEN** a flow is created on an instance where the administrator has not enabled hop auditing
- **THEN** its runs write no per-hop audit-trail entries, while still recording their step rows.

#### Scenario: A flow opts in against the instance default
- **WHEN** a flow sets its own audit flag to true on an instance whose default is off
- **THEN** that flow's hops are audit-trailed and other flows' hops are not.

### Requirement: The oversight gate is engine-wide, optional, and on by default

The engine SHALL consult a set of registered oversight checks before each hop.
It SHALL be configurable by an administrator app setting and overridable per
flow, and SHALL default to **on**, because it is the mechanism that stops a
running flow — a safety rail defaulting to off protects only the flows someone
remembered to configure.

#### Scenario: The kill switch stops a running flow
- **WHEN** the kill switch is thrown while a multi-node flow is mid-walk
- **THEN** the next hop does not execute, the run is recorded as stopped with the vetoing check named, and the hops already completed keep their recorded results.

#### Scenario: A flow may be exempted, but only explicitly
- **WHEN** a flow sets its own oversight flag to false
- **THEN** its hops are not gated, while a flow that merely leaves the flag unset continues to follow the instance default.

#### Scenario: Changing the instance default moves the unset flows
- **WHEN** the administrator turns the oversight default off
- **THEN** flows that never set their own flag stop being gated, and a flow that explicitly set true is still gated.

### Requirement: Oversight checks are contributed, not hardcoded

An app SHALL be able to register an oversight check through a registration
event, the same way it contributes node types. The engine SHALL NOT hardcode any
app-specific check. A check SHALL be able to veto a hop with a reason, and any
veto SHALL stop the run rather than skipping the hop.

#### Scenario: An app contributes a budget check
- **WHEN** an app registers an oversight check that vetoes once a spend budget is exhausted, and a flow reaches that state mid-walk
- **THEN** the run stops at that hop with the check's reason recorded, without the engine containing any knowledge of budgets.

#### Scenario: A veto stops rather than skips
- **WHEN** a registered check vetoes a hop
- **THEN** the run terminates at that point and the remaining nodes are not executed, so a vetoed hop can never be mistaken for a completed walk.

#### Scenario: A failing check does not silently open the gate
- **WHEN** a registered oversight check itself throws
- **THEN** the hop is refused and the run stops, rather than the failure being treated as an absence of objection.
