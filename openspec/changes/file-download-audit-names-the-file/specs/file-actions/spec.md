# file-actions Delta

## ADDED Requirements

### Requirement: A download audit row names the file

The `file.downloaded` audit row SHALL carry the file's name in its data (`fileName`) beside its id.

#### Scenario: A medical report is opened

- **WHEN** a reader opens "Medisch verslag.pdf" on a case
- **THEN** the case's audit trail has a `file.downloaded` row whose `changed.data.fileName` is "Medisch verslag.pdf"
