## ADDED Requirements

### Requirement: The system MUST expose the data-subject rights as an admin-gated DSAR HTTP surface

`DsarController` MUST provide the shipped HTTP slice of the AVG data-subject rights as a thin wrapper over `DsarService` and `AvgComplianceService`. Every endpoint MUST require membership of the Nextcloud `admin` group, returning HTTP 403 before doing any work otherwise, because DSAR operations span the whole register surface and bypass per-schema RBAC.

- `inzage` (Art 15) MUST accept `subject` (required), optional `type`, and optional `mode` (`exact` default or `ilike`), delegate to `DsarService::findObjectsForSubject()`, and return `{subject, type, count, results}`. A missing `subject` MUST return HTTP 422.
- `portabiliteit` (Art 20) MUST use the same lookup but reduce the envelope to the machine-readable export shape `{subject, generated, count, objects}` (object payloads only, no match annotations).
- `vergetelheid` (Art 17) MUST accept `subject` (required), optional `type`, and a `dryRun` boolean; when `dryRun` is true it MUST return the matches without erasing. It MUST delegate to `DsarService::eraseObjectsForSubject()` and return the service summary.
- `rectificatie` (Art 16) MUST require `objectId` (non-zero int) and a non-empty `changes` object, returning HTTP 422 when either is missing, HTTP 404 when the object is not found / the update fails, and delegate to `DsarService::rectifyObjectForSubject()`.
- `compliance` MUST return `AvgComplianceService::runAllChecks()`.

#### Scenario: DSAR endpoints are admin-gated
- **GIVEN** a non-admin authenticated user
- **WHEN** they call any of `inzage`, `portabiliteit`, `vergetelheid`, `rectificatie`, or `compliance`
- **THEN** the response MUST be HTTP 403 with `{error}` and no DSAR work MUST be performed

#### Scenario: Inzage requires a subject
- **GIVEN** an admin calls `inzage` with no `subject`
- **THEN** the response MUST be HTTP 422 with `{error: "`subject` query parameter is required"}`
- **AND** a valid call MUST return `{subject, type, count, results}` from `DsarService::findObjectsForSubject()`

#### Scenario: Erasure supports dry-run
- **GIVEN** an admin calls `vergetelheid` with `dryRun=true` for a subject with matches
- **WHEN** the request is processed
- **THEN** the matches MUST be returned without any object being erased

#### Scenario: Rectification validates input
- **GIVEN** an admin calls `rectificatie` with `objectId=0` or empty `changes`
- **THEN** the response MUST be HTTP 422
- **AND** a valid call against a non-existent object MUST return HTTP 404 with `{error, objectId}`

### Requirement: The system MUST provide a detected-PII entity registry API

`GdprEntitiesController` MUST expose a read + delete API over the `openregister_entities` / `openregister_entity_relations` tables holding PII detected by the entity-recognition pipeline. `index` MUST support `limit`/`offset` pagination and `search` (iLike on value), `type`, and `category` filters, returning `{success, data, count, limit, offset}` where each entity carries its `relationCount`. `show` MUST return the entity plus its relations, or HTTP 404 when absent. `getTypes` and `getCategories` MUST return the distinct sorted type / category values. `getStats` MUST return `{totalEntities, totalRelations, byType, byCategory}`. `destroy` MUST delete a single entity by id, returning HTTP 404 when absent. All operational failures MUST be logged and surfaced as HTTP 500 with `{success: false, message}`.

#### Scenario: List detected entities with filters and pagination
- **GIVEN** detected entities of multiple types exist
- **WHEN** `index` is called with `type=BSN&limit=25&offset=0`
- **THEN** the response MUST be `{success: true, data, count, limit: 25, offset: 0}`
- **AND** `count` MUST be the total matching the filters (not the page size)
- **AND** each entity MUST include its `relationCount`

#### Scenario: Entity statistics
- **WHEN** `getStats` is called
- **THEN** the response data MUST include `totalEntities`, `totalRelations`, `byType`, and `byCategory`

#### Scenario: Delete a detected entity
- **GIVEN** an entity with a given id exists
- **WHEN** `destroy` is called for that id
- **THEN** the entity MUST be deleted and the response MUST be `{success: true}`
- **AND** a non-existent id MUST return HTTP 404

## Notes

- **SECURITY (cross-tenant PII exposure):** every read endpoint on `GdprEntitiesController` (`index`, `show`, `getTypes`, `getCategories`, `getStats`) is `@NoAdminRequired` and applies no owner / organisation / multi-tenancy filter. The underlying `openregister_entities` rows store the raw detected PII *values* (`e.value`), so any authenticated user can enumerate and search every detected name/email/BSN across all organisations. This contradicts the "Multi-tenant privacy isolation MUST prevent cross-organisation data access" requirement in this same capability. `destroy` is likewise `@NoAdminRequired`, so any authenticated user can delete detected-PII records — there is no audit-trail emission on this deletion path (cross-cuts `deletion-audit-trail`). Recommend gating these behind admin (as `DsarController` does) or organisation-scoping the queries.
- `DsarController::vergetelheid` performs irreversible erasure; the controller relies on `DsarService` to audit-log the deletion for legal defence (per its docblock) — the controller itself emits no audit entry.
- `DsarController` admin-gates correctly and is the right pattern; the asymmetry with `GdprEntitiesController` (which does not) is the actionable finding.
