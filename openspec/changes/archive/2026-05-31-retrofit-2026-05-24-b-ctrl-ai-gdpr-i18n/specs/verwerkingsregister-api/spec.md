## ADDED Requirements

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

## Notes

- `index`, `show`, and `verantwoording` are `@NoAdminRequired` *and* apply no organisation / multi-tenancy scoping — any authenticated user can read the entire processing register and the supervisory-review report across all tenants. The controller docblock states this is intentional (Art 30 §4 availability to supervisory authorities), but in a multi-tenant deployment it leaks one tenant's verwerkingsregister to another tenant's users. Flag against the multi-tenant isolation requirement in `avg-verwerkingsregister`.
- `aggregateAuditCounts` reads `processing_activity_id` from `openregister_audit_trails`; this couples the dedicated catalog to the audit-trail schema by a string uuid soft-FK, which is why `destroy` soft-archives rather than deletes.
