# schema-import Delta

## ADDED Requirements

### Requirement: An import adopts its app's own unlinked schema

When a register's own schema set holds no schema with an imported slug, the import SHALL reuse the importing app's own
schema row with that slug that no register lists (the highest version when there are several) instead of creating a
new row. It SHALL NOT reuse another app's row.

#### Scenario: A row an earlier import left unlinked

- **GIVEN** dossiq's `workDigest` 1.0.0 in no register
- **WHEN** dossiq imports `workDigest` 1.0.1 into its register
- **THEN** that row is updated and linked, and no second `workDigest` row is created

#### Scenario: Another app's row

- **GIVEN** pipelinq's `workDigest` in no register
- **WHEN** dossiq imports `workDigest`
- **THEN** dossiq gets its own row
