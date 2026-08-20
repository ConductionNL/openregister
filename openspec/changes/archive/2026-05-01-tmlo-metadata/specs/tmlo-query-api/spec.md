## ADDED Requirements

### Requirement: Query objects by archival status

The system SHALL provide API query parameters to filter objects by their TMLO archival metadata. The following query parameters SHALL be supported on the existing objects list endpoint:

- `tmlo.archiefstatus` -- Filter by archival status (exact match)
- `tmlo.archiefnominatie` -- Filter by archival nomination (exact match)
- `tmlo.archiefactiedatum[from]` and `tmlo.archiefactiedatum[to]` -- Filter by archival action date range
- `tmlo.vernietigingsCategorie` -- Filter by destruction category (exact match)

#### Scenario: Filter objects by archiefstatus

- **WHEN** a GET request is made to `/api/objects/{register}/{schema}?tmlo.archiefstatus=semi_statisch`
- **THEN** only objects with `tmlo.archiefstatus = "semi_statisch"` SHALL be returned

#### Scenario: Filter objects by archiefactiedatum range

- **WHEN** a GET request is made with `tmlo.archiefactiedatum[from]=2025-01-01&tmlo.archiefactiedatum[to]=2025-12-31`
- **THEN** only objects with archiefactiedatum within the specified range SHALL be returned

#### Scenario: Filter objects ready for destruction

- **WHEN** a GET request is made with `tmlo.archiefnominatie=vernietigen&tmlo.archiefstatus=semi_statisch`
- **THEN** only objects nominated for destruction that are in semi-static status SHALL be returned

### Requirement: Archival status summary endpoint

The system SHALL provide a summary endpoint that returns counts of objects grouped by archival status for a given register and schema.

#### Scenario: Get archival status summary

- **WHEN** a GET request is made to `/api/objects/{register}/{schema}/tmlo/summary`
- **THEN** the response SHALL contain counts per archiefstatus: `{ "actief": N, "semi_statisch": N, "overgebracht": N, "vernietigd": N }`

#### Scenario: Summary for register without TMLO

- **WHEN** a summary is requested for a register without TMLO enabled
- **THEN** the response SHALL return a 400 error indicating TMLO is not enabled on this register
