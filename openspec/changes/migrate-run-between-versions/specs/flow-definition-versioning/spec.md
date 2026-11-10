# flow-definition-versioning

## ADDED Requirements

### Requirement: A run can be migrated to another version, explicitly and validated

The system SHALL let a user with the flow's `run` right and `manage` on
the run's subject migrate a run to a target version with a reason and an
optional node mapping. Before applying, the system SHALL verify that every
place in the run's marking maps to a node of a compatible kind in the
target, and SHALL refuse with the offending places when it does not.
`dryRun` SHALL return the verdict and the resulting marking without
applying. Applying SHALL rewrite the pinned version and the marking in one
transaction, keep the run log, append a `migrated` entry with both
versions, the mapping, the reason and the actor, and supersede business
timers whose node changed with reason `migrated`.

#### Scenario: a renamed node is mapped and the run continues

- **GIVEN** a run parked on user task `review` under version 2 and a version 3 that renamed it `assess`
- **WHEN** the run is migrated to version 3 with mapping `{review: assess}` and a reason
- **THEN** the run reads version 3 with its token on `assess`, the task keeps its assignee, and the log holds a `migrated` entry
- @e2e exclude {proposal only; task 4.1 adds tests/e2e/ci/run-migration.spec.ts when the action ships}

#### Scenario: a removed node without a mapping refuses the migration

- **GIVEN** a run parked on `review` and a version 3 without `review` or a mapping
- **WHEN** the migration is requested
- **THEN** the response is 422 naming `review` and the run is unchanged
- @e2e exclude {validator, covered by FlowRunMigrationService unit tests}

#### Scenario: a dry run changes nothing

- **GIVEN** a valid migration request with `dryRun: true`
- **WHEN** it is sent
- **THEN** the response carries the resulting marking and the run still reads its old version
- @e2e exclude {dry run, covered by unit tests}

### Requirement: Runs can be migrated in bulk per version

`POST /api/flows/{flow}/migrate-runs` SHALL migrate every run pinned to a
source version onto a target with one mapping, in bounded batches, SHALL
skip and name every run that fails validation, and SHALL report per run.

#### Scenario: forty runs move, one is named

- **GIVEN** forty-one runs on version 2, one of them parked on an unmapped removed node
- **WHEN** the bulk migration runs
- **THEN** forty runs read version 3 and the report names the one that was skipped with its place
- @e2e exclude {bulk path, covered by unit tests}
