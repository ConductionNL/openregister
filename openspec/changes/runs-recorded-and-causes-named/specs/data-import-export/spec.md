# data-import-export

## ADDED Requirements

### Requirement: An import leaves a durable record with an outcome per row (REQ-RCN-005)

An import SHALL write a run record carrying the file name, a fingerprint of
the file, the column mapping, the conflict policy, the actor, the moment,
and the counts of created, updated, skipped and refused rows. Each row SHALL
have an outcome on the run naming what happened to it and, where it was
skipped or refused, why, with the object it wrote or matched where there is
one. The run SHALL be readable after the import has finished, and its rows
SHALL be paged.

#### Scenario: last month's load is still readable

- **GIVEN** an import run that finished four weeks ago
- **WHEN** it is read
- **THEN** the file, the mapping, the policy, the actor and the counts are returned
- **AND** its rows are readable with their outcomes

#### Scenario: a refused row says why

- **GIVEN** a run holding a row refused by the conflict policy
- **WHEN** that row is read
- **THEN** the reason names the policy and the objects it matched

#### Scenario: the written object is reachable from the row

- **GIVEN** a run holding a row that created an object
- **WHEN** the row is read
- **THEN** it names the object it created
- @e2e exclude {run read, covered by unit tests}

### Requirement: The failed rows of an import are exportable and retryable (REQ-RCN-006)

The refused and skipped rows of a run SHALL be exportable in the shape they
were submitted, so they can be corrected and loaded again. A retry SHALL
create a new run naming the original as its cause, and SHALL NOT change the
original. Audit entries produced by a run SHALL name that run as their
cause.

#### Scenario: the corrections are loaded as their own run

- **GIVEN** a run with twelve refused rows, exported and corrected
- **WHEN** they are imported again
- **THEN** a new run is created naming the original as its cause
- **AND** the original run is unchanged

#### Scenario: the trail points back at the load

- **GIVEN** an object created by an import run
- **WHEN** its audit trail is read
- **THEN** the creating entry names the run as its cause

### Requirement: Import detail is pruned while the run survives (REQ-RCN-007)

Per-row detail SHALL be pruned by the retention pass on an administered
period, and the run header with its counts SHALL survive the prune. A run
whose detail has been pruned SHALL say so rather than reading as a run with
no rows.

#### Scenario: an old run keeps its shape

- **GIVEN** a run whose per-row detail has passed the retention period
- **WHEN** the retention pass runs and the run is read afterwards
- **THEN** the header and the counts are returned
- **AND** the run reports that its row detail was pruned
- @e2e exclude {retention pass, covered by unit tests}
