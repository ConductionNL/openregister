# organisation-usage-measured Specification

## Purpose
An organisation reports its usage: members, groups, storage and requests. Each figure is reported only when OpenRegister measured it, and null when it could not, so a missing figure never reads as zero.

## Requirements

### Requirement: Organisation usage reports only what is measured

An organisation's `usage` block SHALL report a figure only when OpenRegister
measured it, and SHALL report null, never 0, for a figure it cannot measure.

#### Scenario: One organisation is measured

- **GIVEN** a viewer with access to an organisation
- **WHEN** they read `GET /api/organisations/{uuid}`
- **THEN** `usage.users` and `usage.groups` count the organisation's members and groups
- **AND** `usage.storage` is the bytes in the folders of the organisation's live objects
- **AND** `usage.requests` is this hour's request counter when the organisation has a request quota and APCu is enabled, else null
- **AND** `usage.bandwidth` is null

#### Scenario: A storage read fails

- **GIVEN** the object tables cannot be read
- **WHEN** usage is measured
- **THEN** `usage.storage` is null

#### Scenario: Lists do not claim usage

- **WHEN** an organisation is serialised outside the single read
- **THEN** `usage.storage`, `usage.bandwidth` and `usage.requests` are null

#### Scenario: A refused organisation is not measured

- **GIVEN** a viewer without access to the organisation
- **WHEN** they read it
- **THEN** the response is 403 and nothing is measured
