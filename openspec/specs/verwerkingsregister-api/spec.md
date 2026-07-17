---
status: done
---

# verwerkingsregister-api Specification

---
status: implemented
---

## Purpose

@e2e exclude GDPR verwerkingsregister REST API — covered by Newman
GDPR Art 30 processing register API for querying processing activities, generating data subject access reports (inzageverzoek), and exporting the verwerkingsregister. Enables compliance auditing for Dutch government organisations.
## Requirements
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

### Requirement: The system MUST provide a CRUD REST surface over the dedicated verwerkingsactiviteiten catalog

Beyond the audit-trail-derived read views, the system MUST expose `VerwerkingsactiviteitenController` as a REST CRUD surface over the dedicated `oc_openregister_verwerkingsactiviteiten` table (distinct from the audit-trail aggregation). `index` MUST list activities with optional `status` and `organisation` query filters, returning `{count, results}`. `show` MUST resolve a path identifier that may be a numeric id, a uuid, or a short readable code, returning HTTP 404 when nothing matches. `create` and `update` MUST hydrate the string fields (`code`, `naam`, `beschrijving`, `doelbinding`, `rechtsgrond`, `bewaartermijn`, `technischeMaatregelen`, `organisatorischeMaatregelen`, `organisationId`, `status`) and array fields (`categorieenBetrokkenen`, `categorieenPersoonsgegevens`, `ontvangers`, `doorgifteBuitenEu`, `verwerkingsverantwoordelijke`, `contactgegevensFg`) from the payload, returning HTTP 201 on create and HTTP 422 on `InvalidArgumentException`. `destroy` MUST NOT hard-delete — it MUST set `status = 'archived'` and persist, returning HTTP 204, because audit-trail rows reference activities by uuid as a soft foreign key. Create, update, and destroy MUST be restricted to members of the Nextcloud `admin` group (HTTP 403 otherwise); list, show, and the verantwoording report MUST be available to any authenticated user.

#### Scenario: List with status filter
- **WHEN** `GET /api/avg/verwerkingsactiviteiten?status=actief` is requested
- **THEN** `index` MUST return `{count, results}` containing only activities with `status = actief`
- **AND** each result MUST be the activity's `jsonSerialize()` form

#### Scenario: Resolve by id, uuid, or code
- **GIVEN** an activity exists with a uuid and a readable code
- **WHEN** `show` is called with the numeric id, the uuid, or the code
- **THEN** each form MUST resolve to the same activity
- **AND** an unmatched identifier MUST return HTTP 404 with `{error, identifier}`

#### Scenario: Writes are admin-gated
- **GIVEN** a non-admin authenticated user
- **WHEN** they call `create`, `update`, or `destroy`
- **THEN** the response MUST be HTTP 403 with `{error}` before any persistence
- **AND** an admin performing `create` with valid fields MUST receive HTTP 201 with the persisted activity

#### Scenario: Delete soft-archives instead of removing
- **GIVEN** an existing activity referenced by audit-trail rows
- **WHEN** an admin calls `destroy`
- **THEN** the activity's `status` MUST be set to `archived` and persisted
- **AND** the row MUST remain resolvable by uuid
- **AND** the response MUST be HTTP 204

### Requirement: The system MUST provide an Art 30 §4 accountability report aggregating audit events per processing activity

`VerwerkingsactiviteitenController::verantwoording` MUST return a verantwoordingsdocument suitable for AP supervisory review: every verwerkingsactiviteit joined with the count of audit-trail rows attributed to it via `processing_activity_id`, broken down per `action`. The aggregation MUST query `openregister_audit_trails` grouped by `processing_activity_id` and `action`, scoped to the activities' uuids. The response MUST be `{count, activities}` where each activity entry is its serialized form plus an `activity` block `{totalEvents, byAction}`. Activities with no audit rows MUST report `{totalEvents: 0, byAction: []}`. A query failure during aggregation MUST degrade to empty counts rather than failing the whole report.

#### Scenario: Report aggregates audit counts per action
- **GIVEN** activity `A` (uuid `u1`) has 3 `create`, 2 `update`, and 5 `read` audit rows
- **WHEN** `verantwoording` is called
- **THEN** the entry for `A` MUST include `activity.byAction = {create: 3, update: 2, read: 5}`
- **AND** `activity.totalEvents` MUST equal 10

#### Scenario: Activity with no audit rows
- **GIVEN** activity `B` has no audit-trail rows referencing it
- **WHEN** `verantwoording` is called
- **THEN** the entry for `B` MUST include `activity = {totalEvents: 0, byAction: []}`

#### Scenario: Aggregation failure degrades gracefully
- **GIVEN** the audit-trail aggregation query throws
- **WHEN** `verantwoording` runs
- **THEN** every activity MUST report `{totalEvents: 0, byAction: []}` and the report MUST still return HTTP 200

