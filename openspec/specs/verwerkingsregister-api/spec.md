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

### Requirement: The system MUST support subject-identifier audit-trail lookup (Art 15 AVG — Dutch: inzageverzoek)
An API endpoint MUST allow querying all audit trail entries related to a specific data subject, identified by a search term in the `changed` field. `AuditTrailController::subjectAuditTrail` (renamed from `inzageverzoek` by the `verwerkingsregister-i18n` change) is reachable at `GET /api/audit-trails/subject-lookup` (renamed from `/api/audit-trails/inzageverzoek`). This endpoint is distinct in purpose from the `DsarController::access` endpoint (`GET /api/avg/access`) — this one searches audit-trail entries by identifier; `DsarController::access` searches the PII entity index for objects referencing a subject.

#### Scenario: Query audit entries for a data subject
- **WHEN** a GET request is made to `/api/audit-trails/subject-lookup?identifier=123456789`
- **THEN** the system MUST search all audit trail entries where the `changed` JSON field contains the identifier
- **AND** return a JSON response with all matching entries grouped by schema
- **AND** each group MUST include the schema UUID, schema name (if available), and the list of matching entries

#### Scenario: Subject lookup with no matching entries
- **WHEN** a GET request is made to `/api/audit-trails/subject-lookup?identifier=nonexistent`
- **THEN** the system MUST return `{"results": [], "totalEntries": 0}`

#### Scenario: Subject lookup requires identifier parameter
- **WHEN** a GET request is made to `/api/audit-trails/subject-lookup` without an `identifier` parameter
- **THEN** the system MUST return HTTP 400 with `{"error": "identifier parameter is required"}`

### Requirement: The instance-wide audit reads are admin-only
The audit reads that answer about the whole trail, whichever register, schema or organisation an entry belongs to, SHALL be answered to members of the Nextcloud `admin` group only: the subject lookup (`GET /api/audit-trails/subject-lookup`), the processing activity overview (`GET /api/audit-trails/processing-activities`) and the purpose report (`GET /api/avg/purposes/report`). A signed-in non-admin SHALL receive HTTP 403 and an anonymous caller HTTP 401, before the trail is read. The `logs` statistics of a schema or register (`@self.stats` on the schema and register reads, and `GET /api/schemas/{id}/stats`) count the trail the same way, so a non-admin or anonymous caller SHALL be given `{"total": 0, "size": 0}` there and the trail SHALL NOT be counted. A non-admin reads the entries of the objects they may read through the scoped audit list.

#### Scenario: a non-admin cannot search the whole trail for a subject
- **GIVEN** a signed-in user who is not in the `admin` group
- **WHEN** they request `/api/audit-trails/subject-lookup?identifier=123456789`
- **THEN** the response is HTTP 403 and the trail is not searched
- @e2e exclude {access gate, asserted in tests/Unit/Controller/InstanceWideAuditReadsTest.php}

#### Scenario: a non-admin cannot count the trail
- **GIVEN** a signed-in user who is not in the `admin` group
- **WHEN** they request the processing activity overview or the purpose report
- **THEN** the response is HTTP 403 and the trail is not counted
- @e2e exclude {access gate, asserted in tests/Unit/Controller/InstanceWideAuditReadsTest.php}

#### Scenario: a non-admin is not given the trail count of a schema or register
- **GIVEN** a signed-in non-admin or anonymous caller
- **WHEN** they read a schema or register with `_extend=@self.stats`, or a schema's stats
- **THEN** `logs` is `{"total": 0, "size": 0}` and the trail is not counted, while an administrator is given the counts
- @e2e exclude {asserted in tests/Unit/Controller/SchemasControllerTest.php and RegistersControllerTest.php}

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

Beyond the audit-trail-derived read views, the system MUST expose `VerwerkingsactiviteitenController` as a REST CRUD surface over the dedicated `oc_openregister_verwerkingsactiviteiten` table (distinct from the audit-trail aggregation), reachable under `/api/avg/processing-activities`. `index` MUST list activities with optional `status` and `organisation` query filters, returning `{count, results}`. `show` MUST resolve a path identifier that may be a numeric id, a uuid, or a short readable code, returning HTTP 404 when nothing matches. `create` and `update` MUST hydrate the string fields (`code`, `name`, `description`, `purpose`, `legalBasis`, `retentionPeriod`, `technicalMeasures`, `organisationalMeasures`, `organisationId`, `status`) and array fields (`dataSubjectCategories`, `personalDataCategories`, `recipients`, `internationalTransfers`, `controller`, `dpoContactDetails`) from the payload, returning HTTP 201 on create and HTTP 422 on `InvalidArgumentException`. `destroy` MUST NOT hard-delete — it MUST set `status = 'archived'` and persist, returning HTTP 204, because audit-trail rows reference activities by uuid as a soft foreign key. Create, update, and destroy MUST be restricted to members of the Nextcloud `admin` group (HTTP 403 otherwise); list and show MUST be available to any authenticated user, within their active organisation: a non-admin MUST be given only the activities whose `organisationId` is their active organisation, an `organisation` query parameter MUST NOT widen that, an activity without an `organisationId` MUST NOT be given to a non-admin, and a non-admin without an active organisation MUST be given an empty list and HTTP 404 on show; a member of the `admin` group MUST be given every activity. The accountability report is scoped as its own requirement states.

#### Scenario: List with status filter
- **WHEN** `GET /api/avg/processing-activities?status=published` is requested
- **THEN** `index` MUST return `{count, results}` containing only activities with `status = published`
- **AND** each result MUST be the activity's `jsonSerialize()` form

#### Scenario: Resolve by id, uuid, or code
- **GIVEN** an activity exists with a uuid and a readable code
- **WHEN** `show` is called with the numeric id, the uuid, or the code
- **THEN** each form MUST resolve to the same activity
- **AND** an unmatched identifier MUST return HTTP 404 with `{error, identifier}`

#### Scenario: A non-admin sees their active organisation's activities only
- **GIVEN** activities of organisations `A` and `B`, and a non-admin member of both whose active organisation is `A`
- **WHEN** they list the activities, or read one of `B` by id, uuid or code
- **THEN** the list holds only the activities of `A`, and the read of `B`'s activity is HTTP 404
- @e2e exclude {asserted in tests/Unit/Controller/ProcessingActivitiesScopeTest.php}

#### Scenario: A non-admin without an active organisation sees no activity
- **GIVEN** a non-admin with no active organisation
- **WHEN** they list the activities or read one
- **THEN** the list is `{count: 0, results: []}` and the read is HTTP 404
- @e2e exclude {asserted in tests/Unit/Controller/ProcessingActivitiesScopeTest.php}

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

`VerwerkingsactiviteitenController::verantwoording` MUST return an accountability report suitable for AP supervisory review, reachable under `/api/avg/accountability`: every verwerkingsactiviteit joined with the count of audit-trail rows attributed to it via `processing_activity_id`, broken down per `action`. The aggregation MUST query `openregister_audit_trails` grouped by `processing_activity_id` and `action`, scoped to the activities' uuids. The response MUST be `{count, activities}` where each activity entry is its serialized form plus an `activity` block `{totalEvents, byAction}`. Activities with no audit rows MUST report `{totalEvents: 0, byAction: []}`. A query failure during aggregation MUST degrade to empty counts rather than failing the whole report.

The report MUST answer within the caller's organisation. Audit rows carry no reliable organisation, so the scope MUST be taken from the activities: a non-admin caller MUST be given only the activities whose `organisationId` is their active organisation, and audit rows MUST be counted only for those activities. An activity without an `organisationId` MUST NOT be given to a non-admin. A non-admin without an active organisation MUST be given `{count: 0, activities: []}` and no audit row MUST be counted. A member of the `admin` group MUST be given every activity and its counts. An anonymous caller MUST receive HTTP 401.

#### Scenario: A non-admin gets their active organisation's activities
- **GIVEN** activities of organisations `A` and `B`, and a non-admin member of both whose active organisation is `A`
- **WHEN** they request the accountability report
- **THEN** the report lists only the activities of `A`, and the audit rows are counted for those activities only
- @e2e exclude {asserted in tests/Unit/Controller/AccountabilityReportScopeTest.php}

#### Scenario: A non-admin without an active organisation gets nothing
- **GIVEN** a non-admin with no active organisation
- **WHEN** they request the accountability report
- **THEN** the response is `{count: 0, activities: []}` and no audit row is counted
- @e2e exclude {asserted in tests/Unit/Controller/AccountabilityReportScopeTest.php}

#### Scenario: An administrator gets every activity
- **GIVEN** a member of the `admin` group
- **WHEN** they request the accountability report
- **THEN** every activity is listed with its counts, as before
- @e2e exclude {golden recorded on the previous code in tests/Unit/Controller/AccountabilityReportScopeTest.php}

#### Scenario: Report aggregates audit counts per action
- **GIVEN** activity `A` (uuid `u1`) has 3 `create`, 2 `update`, and 5 `read` audit rows
- **WHEN** the accountability report is requested
- **THEN** the entry for `A` MUST include `activity.byAction = {create: 3, update: 2, read: 5}`
- **AND** `activity.totalEvents` MUST equal 10

#### Scenario: Activity with no audit rows
- **GIVEN** activity `B` has no audit-trail rows referencing it
- **WHEN** the accountability report is requested
- **THEN** the entry for `B` MUST include `activity = {totalEvents: 0, byAction: []}`

#### Scenario: Aggregation failure degrades gracefully
- **GIVEN** the audit-trail aggregation query throws
- **WHEN** the accountability report is requested
- **THEN** every activity MUST report `{totalEvents: 0, byAction: []}` and the report MUST still return HTTP 200

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

#### Scenario: a search by ids that names no table is refused like the table read

- **GIVEN** a schema that asks for a purpose, and a request on `/api/objects` with `_ids`, alone or beside only `register` or only `schema`
- **WHEN** one of the ids is a row of that schema and the request declares no usable purpose
- **THEN** the read is refused with 403 `PURPOSE_REFUSED`, as `GET /api/objects/{register}/{schema}` refuses it
- **AND** ids that only hit tables without the annotation answer as before
- @e2e exclude {needs an administered purpose and processing activity; proven on a live NC 34 + PostgreSQL bench, unit tests pin the refusal and the leave-out}

#### Scenario: a text search that names no table leaves out the tables it may not read

- **GIVEN** a schema that asks for a purpose
- **WHEN** `/api/objects?_search=` runs without a usable declared purpose
- **THEN** the search answers 200 with the matches of the other tables, and none of that schema
- **AND** with a usable declared purpose the rows of that schema are in the answer
- @e2e exclude {needs an administered purpose and processing activity; proven on a live NC 34 + PostgreSQL bench, unit tests pin the refusal and the leave-out}

#### Scenario: queries are countable per purpose

- **GIVEN** a month of queries under three purposes
- **WHEN** the audit trail is read by purpose
- **THEN** each purpose carries its own count
- @e2e exclude {reporting query, covered by unit tests}
