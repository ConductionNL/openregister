# public-audit-query-endpoint Specification

## Purpose
TBD - created by archiving change public-audit-query-endpoint. Update Purpose after archive.
## Requirements
### Requirement: Unified audit query endpoint

`GET /api/v2/audit` SHALL return all recorded audit entries (any app, any schema) with optional filters and paging. Access restricted to NC admins only.

**Query Parameters:**
- `registerId` (optional) — filter by register (e.g., "procest", "pipelinq")
- `schemaId` (optional) — filter by schema (e.g., "aiAuditEntry", "paraferingAuditEntry")
- `objectId` (optional) — filter by object UUID
- `app` (optional) — shorthand filter for registerId
- `timestampStart`, `timestampEnd` (optional) — filter by creation timestamp (ISO 8601)
- `limit` (default 50, max 200) — rows per page
- `offset` (default 0) — pagination
- `sort` (default "created:desc") — sort field and order

**Response:**
```json
{
  "entries": [
    {
      "id": "uuid",
      "registerId": "procest",
      "schemaId": "aiAuditEntry",
      "objectId": "case-uuid",
      "data": { ... },
      "created": "2026-07-12T...",
      "userId": "admin"
    }
  ],
  "total": 1250,
  "limit": 50,
  "offset": 0
}
```

#### Scenario: Admin queries AI audit entries for one case

- **GIVEN** an admin user
- **WHEN** they request `GET /api/v2/audit?registerId=procest&schemaId=aiAuditEntry&objectId=<case-uuid>`
- **THEN** all AI decision logs for that case return, newest first

@e2e include Admin queries procest AI entries; verify pagination and filtering work.

#### Scenario: Non-admin request denied

- **GIVEN** an authenticated non-admin user
- **WHEN** they request the audit endpoint
- **THEN** the response is 403 Forbidden

@e2e include Non-admin makes request; verify 403.

### Requirement: Export variant

`GET /api/v2/audit/export` with the same query parameters SHALL stream an export (CSV or JSON) of the filtered audit entries.

#### Scenario: Export all procest audits as CSV

- **GIVEN** an admin
- **WHEN** they request `GET /api/v2/audit/export?registerId=procest&format=csv`
- **THEN** a CSV download streams with columns: id, registerId, schemaId, objectId, data (JSON), created, userId

@e2e include Admin exports procest audits; verify CSV content and HTTP headers (Content-Type: text/csv, Content-Disposition: attachment).

### Requirement: Query isolation (no data exposure)

The endpoint SHALL return ONLY the audit trail entries themselves — immutable frozen records. It SHALL NOT expose the current objects being audited, their content, or user data beyond what the audit entry itself contains.

#### Scenario: Audit does not leak object data

- **GIVEN** an audit entry logging "User rejected proposal 123"
- **WHEN** the admin queries the audit
- **THEN** the entry includes only what was logged (action, timestamp, user); not the full proposal content

@e2e exclude Audit entries are defined per-app; endpoint returns them as-is. No data joining or enrichment.

