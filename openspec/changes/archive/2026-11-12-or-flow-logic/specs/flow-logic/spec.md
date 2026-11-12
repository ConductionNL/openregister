## ADDED Requirements

### Requirement: Edges route by condition (REQ-FL-001)

When several transitions are enabled, the engine SHALL fire the first whose
edge `condition` (a JSONLogic expression) holds. Routing is a property of the
edge, so any node with several conditioned outgoing edges is a branch — no
special router node type is required.

The condition SHALL be evaluated against the first item as the list's
representative.

#### Scenario: A branch takes the edge whose condition holds

- **GIVEN** a node with a `>10` edge and a `<=10` edge
- **WHEN** the item's value is 42
- **THEN** the `>10` branch runs and the other does not
- **AND** when the value is 3, the `<=10` branch runs instead

### Requirement: An unconditioned edge is the default (REQ-FL-002)

An edge with no condition SHALL be the default/else: eligible, but taken only
when no conditioned sibling matched. A matching conditioned edge SHALL beat the
default regardless of declaration order.

#### Scenario: The default is taken only on no match

- **GIVEN** a conditioned edge and an unconditioned edge
- **WHEN** the condition does not hold
- **THEN** the unconditioned edge is taken
- **AND** when it does hold, the conditioned edge is taken instead

### Requirement: A dead-end choice ends the run (REQ-FL-003)

When every enabled transition is gated by a condition that did not hold and
there is no default edge, the run SHALL end (`completed`) at that choice point
rather than re-evaluating the same un-fireable transitions until the ceiling.

#### Scenario: No matching case and no default

- **GIVEN** a switch whose only edge is a condition that cannot match
- **WHEN** the run reaches it
- **THEN** the run ends `completed` and no further node runs

### Requirement: A step can end the run deliberately (REQ-FL-004)

A step SHALL be able to end the run by throwing `FlowStop`. A plain stop SHALL
end it `stopped`; an error stop SHALL end it `failed` with the message.

`FlowStop` SHALL be caught before the generic error handler, so a deliberate
stop is never treated as a step failure and never subject to an `onError`
policy.

#### Scenario: A stop ends the run cleanly

- **GIVEN** a flow whose first edge is a Stop step
- **WHEN** the run reaches it
- **THEN** the run ends `stopped` with the step's message
- **AND** no node after the stop runs

#### Scenario: An error stop fails the run

- **GIVEN** a Stop step configured as an error
- **WHEN** the run reaches it
- **THEN** the run ends `failed` with the message as its error

### Requirement: One bad node does not blank the palette (REQ-FL-005)

Building the palette SHALL skip a node whose metadata throws, logging it,
rather than letting one node's failure remove every node from the palette.

#### Scenario: A node with a broken icon is skipped

- **GIVEN** a registered node whose `getIcon()` throws
- **WHEN** the palette is built
- **THEN** the other nodes are still present

@e2e exclude branching and stop are engine behaviour — covered by PHPUnit; the branch-condition editor is covered by the flow editor's own change
