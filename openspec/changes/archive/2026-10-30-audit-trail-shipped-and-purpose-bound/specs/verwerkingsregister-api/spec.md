# verwerkingsregister-api

## ADDED Requirements

### Requirement: A registry query carries an administered purpose bound to the processing register (REQ-ATS-005)

A query to a registry source SHALL carry a purpose chosen from an
administered list, and each purpose SHALL be bound to an entry in the
processing activity register. A query with no purpose, or with a purpose
that is not bound, SHALL be refused. The purpose SHALL be recorded on the
audit entry for that query.

#### Scenario: a person search names its grondslag

- **GIVEN** an administered purpose bound to a processing activity
- **WHEN** a BRP query is made under it
- **THEN** the query runs and the audit entry names the purpose

#### Scenario: an unbound purpose is refused

- **GIVEN** a purpose that names no processing activity
- **WHEN** a query is made under it
- **THEN** the query is refused, naming the purpose

#### Scenario: queries are countable per purpose

- **GIVEN** a month of queries under three purposes
- **WHEN** the audit trail is read by purpose
- **THEN** each purpose carries its own count
- @e2e exclude {reporting query, covered by unit tests}
