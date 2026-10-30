# flow-tasks

## ADDED Requirements

### Requirement: A task key is unique

The tasks table SHALL refuse a second task with a key another task carries. An upgrade that finds keys already shared
SHALL warn with their count and leave the table unchanged.

#### Scenario: Two saves add the same keyed task

- **GIVEN** a task with key `dossiq:once:rule:t:r:case-1`
- **WHEN** a second task with that key is inserted
- **THEN** the database refuses it
- @e2e exclude {database constraint, covered by the migration unit test}

#### Scenario: An instance with shared keys

- **GIVEN** two stored tasks sharing a key
- **WHEN** the upgrade runs
- **THEN** it warns with the count and adds no index
- @e2e exclude {migration path, covered by the migration unit test}
