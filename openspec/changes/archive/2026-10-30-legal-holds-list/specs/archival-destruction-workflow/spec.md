## ADDED Requirements

### Requirement: An archivist lists the records under an active legal hold

`GET /api/archival/legal-holds` SHALL list every record whose `retention.legalHold` is active, across the magic tables and the legacy blob table, each with its active holds. A released hold SHALL NOT be listed. When the scan stops at its cap the answer SHALL say `truncated: true`. Only an archivist or an administrator SHALL get the list.

#### Scenario: one held record among three
- **GIVEN** a record with an active hold, one with a released hold and one without a hold
- **WHEN** an archivist asks for the legal holds
- **THEN** the answer SHALL list the first record with its hold and `total` 1
