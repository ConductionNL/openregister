# bulk-action-jobs

## ADDED Requirements

### Requirement: The Tables page runs bulk actions as jobs

The Tables page SHALL list, for a selection, the bulk actions the bulk action registry offers for its schema, and SHALL run a chosen action as a bulk job: inputs and a reason where the action requires one, the preview with applied, skipped and refused counts before anything is written, commit, progress, cancel and the outcome download. A selection SHALL be an id list, or the page's current query when the user selects all matching records. Export SHALL run the export action as a job over the selection or the filtered set.

#### Scenario: set a field on forty records

- **GIVEN** forty selected records of one schema, three of which already carry `prioriteit: hoog`
- **WHEN** the user chooses Set properties with `prioriteit: hoog` and reads the preview
- **THEN** the preview says 37 applied and 3 skipped, and nothing has been written
- **AND** after commit the 37 records carry `prioriteit: hoog` and the outcome lists the 3 skipped with their reason
- @e2e exclude {specified only; task 2.3 adds tests/e2e/ci/tables-bulk-jobs.spec.ts}

#### Scenario: select all matching

- **GIVEN** a filtered list with 260 matching records and a page size of 25
- **WHEN** the user selects all 260 matching and exports them
- **THEN** the job's selection is the query and its count at creation is 260
- @e2e exclude {specified only; task 2.3 covers it}
