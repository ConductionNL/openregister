## ADDED Requirements

### Requirement: Schema and register METADATA-READ lookups MUST bypass multi-tenancy; metadata WRITE lookups MUST enforce it

OpenRegister's multi-tenancy isolation lives at the OBJECT-row level via `MultiTenancyTrait` on `MagicMapper` queries (see existing requirement "Multi-tenancy isolation MUST restrict data access to the user's active organisation"). Schema and register **definitions** are a globally-visible catalog — this is already the established contract via `@PublicPage` on `SchemasController::index`/`show`, both of which pass `_multitenancy: false` to `SchemaMapper::find`/`findAll`.

To eliminate inconsistent inheritance of the `_multitenancy: true` default, every code path whose **purpose** is to **resolve a schema or register entity for reading metadata, computing over its data, or rendering it to a consumer** MUST pass `_multitenancy: false` to `SchemaMapper::find` / `SchemaMapper::findAll` / `RegisterMapper::find` / `RegisterMapper::findAll`. Conversely, every code path whose **purpose** is to **authorize an administrative mutation against the entity** (create, update, patch, delete, upload-as-update) MUST keep the default `_multitenancy: true`. The mapper's default of `true` is intentionally the safe-for-mutation default; the policy is per-caller, not per-mapper.

The `Schema "%s" not found.` / `Register "%s" not found.` 404 path is preserved: an unknown ref still results in `DoesNotExistException` regardless of the tenancy argument; nothing else about lookup semantics changes.

#### Scenario: Tenant user lists schemas via the public catalog endpoint
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schemas exist with `organisation = 'org-uuid-1'`, `organisation = 'org-uuid-2'`, and `organisation IS NULL`
- **WHEN** `jan` calls `GET /api/schemas`
- **THEN** the request MUST succeed (HTTP 200)
- **AND** the response MUST contain schemas from all three groups, scoped only by the existing read-accessibility published gate (not by tenancy)
- **AND** `SchemaMapper::findAll(..., _multitenancy: false)` MUST be the underlying call

#### Scenario: Admin without active organisation runs an aggregation that resolves a schema by ref
- **GIVEN** an admin user (in the `admin` Nextcloud group) with NO active organisation set
- **AND** a schema `meldingen` exists with `organisation = 'org-uuid-1'`
- **WHEN** the admin calls an aggregation endpoint whose runner invokes `AggregationRunner::loadSchema(schemaRef: 'meldingen')`
- **THEN** the schema MUST resolve via `SchemaMapper::find('meldingen', _multitenancy: false)`
- **AND** the aggregation runner MUST proceed (no `Schema "meldingen" not found.` 404)
- **AND** any object-row enumeration the runner performs subsequently MUST still be tenant-filtered by `MultiTenancyTrait` against `MagicMapper`, per the existing object-row multi-tenancy requirement

#### Scenario: Background job (system actor) resolves a register for aggregation
- **GIVEN** a scheduled job running as the system actor (no Nextcloud session, no active organisation)
- **AND** a register `zaken` exists with `organisation = 'org-uuid-2'`
- **WHEN** the job invokes `AggregationRunner::loadRegister(registerRef: 'zaken')`
- **THEN** the register MUST resolve via `RegisterMapper::find('zaken', _multitenancy: false)`
- **AND** the job MUST proceed without a `Register "zaken" not found.` 404

#### Scenario: Tenant user reads a single schema (download, related, stats, publish/depublish lookups)
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schema `producten` exists with `organisation = 'org-uuid-2'`
- **WHEN** `jan` calls `GET /api/schemas/producten/download`, `/related`, `/stats`, or hits the GET lookup inside the publish/depublish flow
- **THEN** the schema MUST resolve via `SchemaMapper::find('producten', ..., _multitenancy: false)`
- **AND** the response MUST be HTTP 200 (subject to the existing read-accessibility published gate when the caller is anonymous)

#### Scenario: Tenant user attempts to UPDATE a schema owned by another tenant
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schema `producten` exists with `organisation = 'org-uuid-2'`
- **AND** `jan` does NOT have schema-manage permission on `producten`
- **WHEN** `jan` calls `PUT /api/schemas/producten`
- **THEN** the underlying lookup MUST be `SchemaMapper::find('producten')` with default `_multitenancy: true` (mutation-gating lookup MUST NOT bypass tenancy)
- **AND** the mutation MUST be rejected with HTTP 404 (the schema is not in `jan`'s tenant scope and is therefore unresolvable for the purpose of authorizing a mutation) OR HTTP 403 (if the schema is resolvable but `checkSchemaManagePermission` denies)
- **AND** no schema record MUST be modified

#### Scenario: Tenant user attempts to DELETE a schema owned by another tenant
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schema `interne-notities` exists with `organisation = 'org-uuid-2'`
- **WHEN** `jan` calls `DELETE /api/schemas/interne-notities`
- **THEN** the underlying lookup MUST be `SchemaMapper::find('interne-notities')` with default `_multitenancy: true`
- **AND** the mutation MUST be rejected (404 or 403 per `checkSchemaManagePermission`)
- **AND** no schema record MUST be deleted

#### Scenario: Tenant user attempts to UPLOAD-AS-UPDATE a schema owned by another tenant
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schema `bezwaarschriften` exists with `organisation = 'org-uuid-2'`
- **WHEN** `jan` calls `POST /api/schemas/bezwaarschriften/upload` with a JSON body
- **THEN** the underlying existing-schema lookup MUST be `SchemaMapper::find($id)` with default `_multitenancy: true`
- **AND** the mutation MUST be rejected (the existing-schema branch fails to resolve, OR the manage-permission check denies)

#### Scenario: Unknown schema ref returns 404 regardless of tenancy state
- **GIVEN** no schema with ref `does-not-exist` is persisted
- **WHEN** any caller (tenant user, admin, or background job) invokes `AggregationRunner::loadSchema(schemaRef: 'does-not-exist')`
- **THEN** `SchemaMapper::find('does-not-exist', _multitenancy: false)` MUST throw `DoesNotExistException`
- **AND** the runner MUST rethrow as `RuntimeException` with message `Schema "does-not-exist" not found.`
- **AND** `AggregationController` MUST translate that into HTTP 404

#### Scenario: Object-row data remains tenant-isolated independently of metadata read
- **GIVEN** user `jan` has active organisation `org-uuid-1`
- **AND** schema `meldingen` is resolvable to `jan` via the metadata-read bypass (regardless of the schema's own `organisation`)
- **WHEN** `jan` lists objects under that schema (`GET /api/objects/{register}/meldingen`)
- **THEN** the OBJECT-row query MUST be tenant-filtered by `MultiTenancyTrait` (see the existing object-row multi-tenancy requirement)
- **AND** only objects with `_organisation = 'org-uuid-1'` (plus RBAC matches) MUST be returned
- **AND** schema-definition metadata reads (now bypassing tenancy) MUST NOT widen object-row access in any way

#### Scenario: The metadata-read bypass MUST NOT change the in-memory mapper default
- **GIVEN** a new caller is added that calls `SchemaMapper::find($id)` without specifying `_multitenancy`
- **THEN** the call MUST continue to use the default `_multitenancy: true` (the safe-for-mutation default)
- **AND** the new caller MUST explicitly opt into `_multitenancy: false` if it is a metadata-read path, per this requirement

## Standards & References (additions)
- This requirement specialises the existing `auth-system` requirement "Multi-tenancy isolation MUST restrict data access to the user's active organisation" for schema and register metadata reads.
- Precedent for the catalog-read bypass: existing requirement "Register and schema read endpoints MUST remain reachable when OpenRegister is restricted to a user group" (added by the `register-schema-read-accessibility` change, archived).
- Operational precedent: `BackfillSystemOwnerCommand` (REQ-001) already passes `_rbac: false, _multitenancy: false` for schema/register lookups when running maintenance.
