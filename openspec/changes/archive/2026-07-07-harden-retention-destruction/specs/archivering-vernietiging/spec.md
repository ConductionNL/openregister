## ADDED Requirements

### Requirement: Destruction-eligibility scan is memory-bounded

The scan that determines which objects are eligible for destruction SHALL
process retained objects in bounded batches and SHALL NOT load the full
retained-object set into memory in a single pass.

#### Scenario: Large retained set processes in batches

- **WHEN** the destruction-eligibility scan runs against a register with many
  retention-tagged objects
- **THEN** objects are fetched and evaluated in fixed-size batches
- **AND** the job's memory footprint does not grow with the total retained count

### Requirement: Destruction execution is idempotent under retry

Executing an approved destruction list SHALL be idempotent. The job SHALL
atomically transition the list to an in-progress state before deleting objects,
so a retried or duplicated dispatch cannot delete the same objects twice or write
duplicate audit entries.

#### Scenario: Duplicate destruction dispatch processes once

- **WHEN** two runs of the destruction-execution job are dispatched for the same
  approved list
- **THEN** exactly one run performs the deletions
- **AND** the other run observes the in-progress/executed state and exits
- **AND** no object is deleted twice and no duplicate audit rows are created

#### Scenario: Mid-run failure is recoverable without double-deletion

- **WHEN** a destruction run fails partway through
- **AND** the job is retried
- **THEN** already-deleted objects are not re-processed
