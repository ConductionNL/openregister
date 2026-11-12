# urn-resource-addressing

## ADDED Requirements

### Requirement: A record keeps its URN when it moves to another register or schema

A record's URN SHALL be assigned once and MUST keep resolving to the record after it
moves to another register or schema. Resolving the URN the record had before the move
SHALL return the record at its new address.

#### Scenario: a moved record is still found by its URN

- **WHEN** a record is moved to another schema and a client resolves the URN it had before the move
- **THEN** the resolver returns the record's new URL, and the record keeps its uuid
- @e2e exclude {backend resolver, covered by a unit test in task 2.1}
