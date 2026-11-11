## ADDED Requirements

### Requirement: A userless read inside runAsSystem is a system read
A read made without a user inside `ObjectService::runAsSystem()` (SystemOperationContext) SHALL be treated as a system read by the magic-table access filters, the same way a userless read on the command line already is: RBAC filtering (query-builder and raw-SQL paths) and the organisation boundary SHALL NOT clamp it. This SHALL NOT open any user-facing read: a logged-in user inside the scope MUST still be filtered as that user, a forced-anonymous evaluation (AnonymousEvaluationContext) MUST still be filtered as anonymous, and a userless web read outside the scope MUST still be filtered. SaaS mode keeps the organisation boundary.

#### Scenario: A system write's calculation resolves its references in a web request
- **GIVEN** a web request with no user, and a case written inside `runAsSystem()` whose calculations declare references to `caseType` and `statusType`, schemas readable by staff only
- **WHEN** ReferenceResolver reads those objects with RBAC and multitenancy on
- **THEN** the referenced objects MUST be found, so the case gets its deadline and status label

#### Scenario: A logged-in user inside the scope is still that user
- **GIVEN** a logged-in non-admin user inside `runAsSystem()`
- **WHEN** a staff-only schema is read
- **THEN** the read MUST be filtered by that user's rights

#### Scenario: Outside the scope nothing changes
- **GIVEN** a web request with no user, outside `runAsSystem()`
- **WHEN** a staff-only schema is read
- **THEN** the read MUST be filtered as anonymous

### Requirement: Related schemas are a catalog read
`GET /api/schemas/{id}/related` SHALL resolve the schema and scan the other schemas without the multitenancy filter (a metadata read, like `GET /api/schemas/{id}`), so a caller who may see the schema gets its related schemas whatever their active organisation.

#### Scenario: A non-admin in another organisation gets related schemas
- **GIVEN** a non-admin whose active organisation does not own schema `module`
- **WHEN** they request `GET /api/schemas/module/related`
- **THEN** the response MUST be HTTP 200 with the incoming and outgoing related schemas, not "Schema not found"
