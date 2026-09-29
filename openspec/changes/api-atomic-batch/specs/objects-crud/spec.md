# objects-crud Specification (delta)

## ADDED Requirements

### Requirement: REQ-ATOMIC-001 An atomic batch is written whole or not at all

A bulk save with `atomic: true` SHALL write every row or none. A refused row SHALL roll back the batch, the answer SHALL name its index and reason, and no event or webhook SHALL be sent for a rolled back batch.

#### Scenario: one bad row stops the batch

- **GIVEN** an atomic batch of three rows whose third fails validation
- **WHEN** a client posts it to the bulk endpoint
- **THEN** no row is stored, the answer names row index 2, and no webhook fires
- @e2e exclude {specified only; task 1 adds the test}
