## Purpose

Defines what a flow run records about the objects it touches. A run already records what it DID, step by step; this capability makes it record what it did it TO, so an object can name the run and node that changed it and a run can list everything it changed.

## ADDED Requirements

### Requirement: Every object write caused by a flow run is attributed to that run, node and step @e2e exclude write-path attribution asserted by AuditTrailMapper + dispatcher integration tests; no user-facing surface performs the write

While a flow run is executing a step, every audit-trail row produced by any write SHALL carry the executing run's uuid, the node id, and the step sequence number. The attribution SHALL be ambient — derived from the executing run rather than passed by the writing code — so that a write performed by code with no knowledge of flows, including another app called into by a node, is attributed identically to a write performed by the node itself.

A row written outside any flow run SHALL carry no attribution: the three values SHALL be absent, and absence SHALL be distinguishable from a run whose identifiers are empty strings.

#### Scenario: A node's own write is attributed
- **WHEN** a flow run executes a node that writes an object
- **THEN** the resulting audit-trail row names the run uuid, that node's id, and that step's sequence number

#### Scenario: A write made by another app during the step is attributed
- **WHEN** a node calls into another app, and that app writes an object during the step
- **THEN** the resulting audit-trail row carries the same run, node and step as the node's own writes
- **AND** the writing app is not required to know it is running inside a flow

#### Scenario: A write outside any run is unattributed
- **WHEN** an object is written by a user action, an import, or a background job with no flow run executing
- **THEN** the resulting audit-trail row has no run, node or step value

#### Scenario: Attribution does not survive the step that established it
- **WHEN** a flow run finishes, fails, or suspends
- **AND** any object is subsequently written in the same process
- **THEN** that write is unattributed
- **AND** this holds when the step ended by throwing, not only when it ended normally

#### Scenario: Two runs writing the same object are separately attributed
- **WHEN** two different flow runs each write the same object
- **THEN** each write produces its own audit-trail row naming its own run and node
- **AND** neither row's attribution is overwritten by the other

### Requirement: A run reports the objects it touched, grouped by node @e2e exclude read surface covered by controller tests; the UI consuming it is specified under flow-engine

The system SHALL expose the objects a run touched, addressed by run uuid, grouped by the node that touched each. Each entry SHALL name the object, the action performed on it, the node, and the step sequence, so a reader can reconstruct the order in which the run changed things.

The response SHALL be scoped by the same visibility rule that governs reading the run itself. A caller who may not read a run SHALL NOT learn what it touched.

#### Scenario: A completed run lists what it changed
- **WHEN** a caller who may read a run requests the objects it touched
- **THEN** the response lists every object the run wrote, grouped by node, in step order

#### Scenario: A run that touched nothing returns an empty result
- **WHEN** a run completed without writing any object
- **THEN** the response is an empty collection and not an error

#### Scenario: Visibility is not widened by the new surface
- **WHEN** a caller who may NOT read a run requests the objects it touched
- **THEN** the request is refused
- **AND** the refusal does not reveal whether the run exists or what it touched

#### Scenario: A suspended run reports what it has touched so far
- **WHEN** a run is suspended awaiting a signal
- **THEN** the objects touched by the steps already executed are reported
- **AND** the result is not withheld until the run completes

### Requirement: An object reports the flow runs that touched it @e2e exclude query-layer filter; asserted by audit-trail query tests

The audit-trail query SHALL accept a run uuid as a filter, and audit-trail records SHALL expose their run, node and step values to readers permitted to read them. Reading the history of a single object SHALL therefore answer which run and which node caused each change, without a separate lookup.

#### Scenario: An object's history names the responsible node
- **WHEN** an object's audit history is read after a flow run changed it
- **THEN** each row caused by the run names that run, the node, and the step

#### Scenario: Filtering by run returns only that run's writes
- **WHEN** the audit trail is queried filtered by a run uuid
- **THEN** only rows attributed to that run are returned

#### Scenario: Attribution is visible only to readers of the row
- **WHEN** a caller reads an audit-trail record they are permitted to read
- **THEN** the run, node and step values are present
- **AND** no attribution is disclosed for records the caller may not read

### Requirement: Attribution outlives the run record but never claims more than it knows @e2e exclude retention interaction; asserted by retention job tests

The attribution SHALL be stored as a plain identifier stamp rather than a referential link, so that pruning a run under retention does not alter, delete, or invalidate the audit rows it caused. A stamp naming a run that no longer exists SHALL remain readable as a historical fact.

#### Scenario: Pruning a run leaves its attribution intact
- **WHEN** flow-run retention deletes a run and its steps
- **THEN** audit rows attributed to that run keep their run, node and step values
- **AND** the hash chain over those rows still verifies

#### Scenario: A dangling run reference reads as history, not as an error
- **WHEN** an object's history names a run that has since been pruned
- **THEN** the reader is shown the recorded identifiers
- **AND** the response does not fail because the run cannot be resolved
