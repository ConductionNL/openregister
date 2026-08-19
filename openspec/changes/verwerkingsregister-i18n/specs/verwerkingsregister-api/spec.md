## MODIFIED Requirements

### Requirement: The system MUST provide a CRUD REST surface over the dedicated verwerkingsactiviteiten catalog

Beyond the audit-trail-derived read views, the system MUST expose `VerwerkingsactiviteitenController` as a REST CRUD surface over the dedicated `oc_openregister_verwerkingsactiviteiten` table (distinct from the audit-trail aggregation), reachable under `/api/avg/processing-activities`. `index` MUST list activities with optional `status` and `organisation` query filters, returning `{count, results}`. `show` MUST resolve a path identifier that may be a numeric id, a uuid, or a short readable code, returning HTTP 404 when nothing matches. `create` and `update` MUST hydrate the string fields (`code`, `name`, `description`, `purpose`, `legalBasis`, `retentionPeriod`, `technicalMeasures`, `organisationalMeasures`, `organisationId`, `status`) and array fields (`dataSubjectCategories`, `personalDataCategories`, `recipients`, `internationalTransfers`, `controller`, `dpoContactDetails`) from the payload, returning HTTP 201 on create and HTTP 422 on `InvalidArgumentException`. `destroy` MUST NOT hard-delete — it MUST set `status = 'archived'` and persist, returning HTTP 204, because audit-trail rows reference activities by uuid as a soft foreign key. Create, update, and destroy MUST be restricted to members of the Nextcloud `admin` group (HTTP 403 otherwise); list, show, and the accountability report MUST be available to any authenticated user.

#### Scenario: List with status filter
- **WHEN** `GET /api/avg/processing-activities?status=published` is requested
- **THEN** `index` MUST return `{count, results}` containing only activities with `status = published`
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

`VerwerkingsactiviteitenController::verantwoording` MUST return an accountability report suitable for AP supervisory review, reachable under `/api/avg/accountability`: every verwerkingsactiviteit joined with the count of audit-trail rows attributed to it via `processing_activity_id`, broken down per `action`. The aggregation MUST query `openregister_audit_trails` grouped by `processing_activity_id` and `action`, scoped to the activities' uuids. The response MUST be `{count, activities}` where each activity entry is its serialized form plus an `activity` block `{totalEvents, byAction}`. Activities with no audit rows MUST report `{totalEvents: 0, byAction: []}`. A query failure during aggregation MUST degrade to empty counts rather than failing the whole report.

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
