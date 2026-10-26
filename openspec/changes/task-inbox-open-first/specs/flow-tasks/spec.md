# flow-tasks Delta

## ADDED Requirements

### Requirement: The inbox can list open tasks before finished ones

`GET /api/flow-tasks` with `openFirst=true` SHALL return every open task before every finished one, each group in the
order `sort` asks. Without `openFirst` the order SHALL be as before.

#### Scenario: A month's tasks by deadline

- **GIVEN** a finished task due 6 October and an open task due 7 October
- **WHEN** the inbox is read with `sort=dueAt&openFirst=true`
- **THEN** the open task comes first
