## ADDED Requirements

### Requirement: A person draws parallel paths and waits in the flow editor, and the flow runs as drawn

The flow editor SHALL let a person draw two or more edges out of one node to start parallel paths, mark the node where they meet as waiting for all of them (`join: true`), and place an await-signal node where the process waits for something outside it. The saved flow SHALL run exactly as drawn. A process imported from BPMN SHALL open on the same canvas as nodes and edges, and SHALL be edited and saved like a drawn flow.

#### Scenario: Two checks run side by side and the decision waits for both
- **GIVEN** a flow in the editor with a start node and a node `Beslissen`
- **WHEN** an author draws edges from the start node to `Budget toetsen` and to `Vergunning toetsen`, draws both into `Beslissen`, and turns on "Wait for all paths" on `Beslissen`
- **AND** saves the flow
- **THEN** the stored flow has two edges out of the start node and `join: true` on `Beslissen`
- **AND** a run reaches `Beslissen` once, after both checks have finished
- @e2e exclude {specified only; task 2.1 adds the e2e}

#### Scenario: A process waits for an answer from outside
- **GIVEN** the flow above with an await-signal node `Wachten op advies` placed before `Beslissen`
- **WHEN** a run reaches `Wachten op advies`
- **THEN** the run page shows the run waiting at that node
- **AND** when the signal arrives the run continues to `Beslissen`
- @e2e exclude {specified only; task 2.1 adds the e2e}

#### Scenario: A process modelled in another tool is edited here
- **GIVEN** a BPMN file with a parallel gateway that splits into two tasks and joins them again
- **WHEN** an administrator imports it and opens the flow
- **THEN** the canvas shows the two paths and the joined node with "Wait for all paths" on
- **AND** after the administrator moves a node and saves, the flow runs as before
- @e2e exclude {specified only; task 1.3 adds the import e2e}
