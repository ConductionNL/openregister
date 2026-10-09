# linked-entity-types

## ADDED Requirements

### Requirement: Related objects MUST be found without scanning every schema

`RelationHandler::getUses()` and `getUsedBy()` SHALL first ask one
cross-table lookup which magic tables hold a match (the related UUIDs for
uses, a `_relations` reference to the object for used by), and SHALL then
read only those tables, through the same filtered query as before so access
rules apply unchanged. They SHALL NOT load every register, every schema, or
query every magic table. Registers and schemas are loaded once per request.

#### Scenario: Related on a pipelinq lead

- **GIVEN** a lead that uses two objects and is used by two objects, in an instance with 23 registers and 333 magic tables
- **WHEN** the lead page asks `/uses` and `/used`
- **THEN** each answer reads only the tables that hold a match
- **AND** returns the same objects as before
- @e2e exclude {query plan; covered by tests/Unit/Service/Object/RelationHandlerScalesWithRelationsTest.php and a live timing on :8099}
