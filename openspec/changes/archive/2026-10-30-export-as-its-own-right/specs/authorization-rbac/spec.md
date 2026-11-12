# authorization-rbac

## ADDED Requirements

### Requirement: Export is its own permission verb (REQ-EXP-001)

The authorization layer SHALL carry an `export` verb, evaluated
independently of `read`. Every export path, the API included, SHALL check
it, and a refusal SHALL name the verb. A principal MAY hold `read` without
`export`. On upgrade the verb SHALL default to granted wherever `read` is
granted, so that no existing instance loses a working export silently.

#### Scenario: a reader who may not take the data

- **GIVEN** a principal holding read but not export on a register
- **WHEN** they export the list
- **THEN** the request is refused, naming the export verb

#### Scenario: the API is gated too

- **GIVEN** the same principal and a token that carries their rights
- **WHEN** the export endpoint is called
- **THEN** it is refused the same way

#### Scenario: an upgraded instance keeps working

- **GIVEN** an instance upgraded from before this change
- **WHEN** a principal who could export before exports
- **THEN** it succeeds
- @e2e exclude {migration behaviour, covered by unit tests}
