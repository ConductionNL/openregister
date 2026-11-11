# runtime-schema-api Specification (delta)

## ADDED Requirements

### Requirement: REQ-SDRAFT-001 A schema edit can be held as a draft until it is published

A schema SHALL accept a draft of its definition that does not affect validation of records until it is published. Publishing SHALL apply the draft through the normal update, with its version bump and changelog entry; discarding SHALL remove it.

#### Scenario: a draft does not refuse live records

- **GIVEN** a published schema and a draft that makes `email` required
- **WHEN** a client saves a record without `email`
- **THEN** the record is saved
- @e2e exclude {specified only; task 1 adds the test}

#### Scenario: publishing applies the draft

- **GIVEN** the same draft
- **WHEN** the administrator publishes it
- **THEN** a record without `email` is refused, the schema version is bumped and the changelog has one entry for the change
- @e2e exclude {specified only; task 1 adds the test}
