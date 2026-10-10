# Flow Decision Tables Specification (delta)

## ADDED Requirements

### Requirement: Named decision tables are stored in OpenRegister (REQ-SDT-01)

OpenRegister MUST ship a core register `decision-tables` with a schema `decision-table` carrying `key`, `name`, `hitPolicy`, `inputs`, `outputs` and `rules`, imported by a repair step, so any app can author a table once and any flow can use it.

#### Scenario: The register exists after an upgrade
- **GIVEN** an instance upgraded to this version
- **WHEN** the repair steps have run
- **THEN** the register `decision-tables` with schema `decision-table` MUST exist

### Requirement: A stored table the evaluator cannot run is refused at save (REQ-SDT-02)

Saving a `decision-table` object MUST be refused with error code `decision-table-invalid` when `DecisionTableValidator` reports a problem, or when another table already carries the same `key`. Objects of other schemas MUST NOT be affected.

#### Scenario: A rule with too few input entries
- **GIVEN** a table whose rule has fewer input entries than the table has inputs
- **WHEN** it is saved
- **THEN** the save MUST be refused naming the problem

#### Scenario: A duplicate key
- **GIVEN** a stored table with key `parcel-carrier`
- **WHEN** a second table with key `parcel-carrier` is saved
- **THEN** the save MUST be refused naming the first table

### Requirement: A table is resolved by key or id (REQ-SDT-03)

`DecisionTableStore::find()` MUST return the table whose `key` equals the reference, else the table whose uuid equals it. A reference that matches no table MUST raise an error, never return an empty table. A stored table the evaluator cannot run MUST raise an error on read. `evaluate()` MUST run the resolved table through `DecisionTableEvaluator`.

#### Scenario: Unknown reference
- **WHEN** `find('nope')` is called and no table carries `nope`
- **THEN** it MUST raise an error naming `nope`

### Requirement: The flow step uses a stored table by reference (REQ-SDT-04)

The `openregister.decision-table` step MUST accept `tableRef` as an alternative to `table`. A configuration with both, or neither, MUST be refused at save. A `tableRef` that names no executable table MUST be refused at save and MUST fail the step at run. The evaluation record MUST carry the stored table's key.

#### Scenario: A flow decides with a stored table
- **GIVEN** a stored table with key `parcel-carrier`
- **AND** a step configured with `tableRef: parcel-carrier`
- **WHEN** the step runs on an item
- **THEN** the item MUST carry the table's outcome, as with an inline table
