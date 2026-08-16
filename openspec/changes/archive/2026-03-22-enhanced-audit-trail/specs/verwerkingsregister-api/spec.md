## ADDED Requirements

### Requirement: The system MUST provide a verwerkingsregister (processing register) API
A dedicated API endpoint MUST return an overview of all processing activities recorded in the audit trail, grouped by processing activity ID.

#### Scenario: List all processing activities
- **WHEN** a GET request is made to `/api/audit-trails/verwerkingsregister`
- **THEN** the system MUST return a JSON array of distinct processing activities
- **AND** each entry MUST include `processingActivityId`, `processingActivityUrl`, `organisationId`, `organisationIdType`, `confidentiality`, and `retentionPeriod`
- **AND** each entry MUST include `entryCount` (number of audit entries for this activity)
- **AND** each entry MUST include `firstSeen` and `lastSeen` timestamps

#### Scenario: Filter verwerkingsregister by organisation
- **WHEN** a GET request is made to `/api/audit-trails/verwerkingsregister?organisationId=00000001234567890000`
- **THEN** the system MUST return only processing activities for that organisation

#### Scenario: Empty verwerkingsregister
- **WHEN** no audit trail entries have a `processingActivityId` set
- **THEN** the endpoint MUST return an empty JSON array `[]`

### Requirement: The system MUST support data subject access requests (inzageverzoek)
An API endpoint MUST allow querying all audit trail entries related to a specific data subject, identified by a search term in the `changed` field.

#### Scenario: Query audit entries for a data subject
- **WHEN** a GET request is made to `/api/audit-trails/inzageverzoek?identifier=123456789`
- **THEN** the system MUST search all audit trail entries where the `changed` JSON field contains the identifier
- **AND** return a JSON response with all matching entries grouped by schema
- **AND** each group MUST include the schema UUID, schema name (if available), and the list of matching entries

#### Scenario: Inzageverzoek with no matching entries
- **WHEN** a GET request is made to `/api/audit-trails/inzageverzoek?identifier=nonexistent`
- **THEN** the system MUST return `{"results": [], "totalEntries": 0}`

#### Scenario: Inzageverzoek requires identifier parameter
- **WHEN** a GET request is made to `/api/audit-trails/inzageverzoek` without an `identifier` parameter
- **THEN** the system MUST return HTTP 400 with `{"error": "identifier parameter is required"}`

### Requirement: The system MUST support audit trail export
An API endpoint MUST allow exporting audit trail entries in JSON or CSV format for external compliance auditing.

#### Scenario: Export audit trail as JSON
- **WHEN** a GET request is made to `/api/audit-trails/export?format=json`
- **THEN** the system MUST return all audit trail entries as a JSON array
- **AND** the response MUST include Content-Disposition header for file download

#### Scenario: Export audit trail as CSV
- **WHEN** a GET request is made to `/api/audit-trails/export?format=csv`
- **THEN** the system MUST return all audit trail entries as CSV
- **AND** the first row MUST contain column headers
- **AND** the `changed` field MUST be serialized as a JSON string within the CSV cell

#### Scenario: Export with date range filter
- **WHEN** a GET request is made to `/api/audit-trails/export?format=json&from=2025-01-01&to=2025-12-31`
- **THEN** the system MUST return only entries with `created` timestamps within the specified range

#### Scenario: Export defaults to JSON
- **WHEN** a GET request is made to `/api/audit-trails/export` without a `format` parameter
- **THEN** the system MUST default to JSON format
