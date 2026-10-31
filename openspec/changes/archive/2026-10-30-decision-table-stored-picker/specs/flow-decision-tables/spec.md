## ADDED Requirements

### Requirement: A stored decision table is picked from the register

The decision-table node's form MUST offer the stored table as a select whose options are the tables in the
decision-tables register.

#### Scenario: Picking a table
- GIVEN the table "Welstandsgebieden Zuiddrecht" in the decision-tables register
- WHEN an author edits a decision table step
- THEN "Stored decision table" offers it in a list, and picking it stores its id
