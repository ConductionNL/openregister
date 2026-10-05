## ADDED Requirements

### Requirement: The CLI purge MUST accept an import job instead of a list of UUIDs

`occ openregister:objects:purge --import-job <id>` SHALL resolve the objects whose `create`
audit row carries that job id and SHALL purge each with the command's existing rules: dry
run unless `--apply`, an archival record and a live object refused unless `--force`, an
archival record named as such in the output. UUID arguments and `--import-job` MAY be
combined; the command SHALL refuse to run when given neither. In job mode an object that no
longer exists SHALL be reported as already gone and SHALL NOT count as a failure, so the
command can be re-run after a partial purge.

@e2e exclude CLI command with no UI surface. Asserted in tests/Unit/Command/PurgeObjectCommandTest.php (testImportJobPurgesTheObjectsTheJobCreated, testImportJobKeepsTheArchivalRefusal, testImportJobReportsAMissingObjectAsAlreadyGone, testRefusesToRunWithNeitherUuidsNorAnImportJob). Covered by PHPUnit.

#### Scenario: Purging a removed example set

- **GIVEN** an app import job whose objects were soft-deleted by the app's wizard
- **WHEN** `occ openregister:objects:purge --import-job <id> --apply` runs
- **THEN** every non-archival object the job created MUST be destroyed
- **AND** every archival one MUST be refused, naming `--force`

#### Scenario: Re-running after a partial purge

- **GIVEN** a job whose objects are partly destroyed already
- **WHEN** the command runs again with `--import-job <id> --apply`
- **THEN** the destroyed ones MUST be reported as already gone
- **AND** the exit code MUST be 0 when nothing else failed

#### Scenario: Nothing named

- **WHEN** the command runs with no UUID and no `--import-job`
- **THEN** it MUST exit 1 and destroy nothing
