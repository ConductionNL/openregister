# flow-tasks

## ADDED Requirements

### Requirement: Tasks can be found by their external key

`TaskService::findByKey` SHALL return every task carrying the key, terminal ones included, and nothing for a blank key.

#### Scenario: A task added once per case

- **GIVEN** a task with key `dossiq:once:rule:t:r:case-1`
- **WHEN** the caller asks for that key
- **THEN** it gets that task, and for a blank key it gets none
- @e2e exclude {service lookup, covered by unit tests}
