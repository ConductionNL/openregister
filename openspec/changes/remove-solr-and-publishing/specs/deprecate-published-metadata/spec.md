## ADDED Requirements

### Requirement: Remove Register and Schema Published Entity Columns
The `oc_openregister_registers` and `oc_openregister_schemas` tables MUST NOT contain `published` or `depublished` columns, and the `Register` and `Schema` entities MUST NOT define `$published`/`$depublished` properties, getters, or setters. The `RegisterMapper` and `SchemaMapper` MUST NOT accept a `$published` filter parameter, `RegistersController`/`SchemasController` MUST NOT contain an `isPublishedEntity()` gate, and `MultiTenancyTrait` MUST NOT contain a published-bypass branch. A database migration MUST drop these columns and their indexes (`*_published_idx` and related) idempotently so re-running it on an already-migrated database is a no-op.

#### Scenario: Register/Schema CRUD without published columns
- **GIVEN** the column-removal migration has run
- **WHEN** a register or schema is created, read, updated, or deleted
- **THEN** no `published`/`depublished` columns MUST be referenced in the resulting SQL
- **AND** the entity MUST be persisted and serialised successfully without those fields

#### Scenario: Anonymous Register/Schema visibility uses RBAC, not columns
- **GIVEN** the published columns have been removed
- **AND** a register has an authorization rule granting the `public` group `read`
- **WHEN** an anonymous caller lists or shows that register
- **THEN** visibility MUST be decided by RBAC rule evaluation (see `auth-system`), not by a `published` column

### Requirement: Register/Schema Published-Column to RBAC Migration Path
Removal of the Register/Schema `published`/`depublished` columns is a BREAKING change for instances that relied on those columns to expose definitions to anonymous callers. The change MUST ship a documented migration path that lets operators preserve existing anonymous visibility by re-expressing it as an RBAC authorization rule granting the `public` group `read` (optionally time-windowed via `publicatiedatum`/`$now`). Anonymous read access MUST NOT silently break: the documentation MUST state that any register/schema previously published (had `published` set, `depublished` null) requires a `public`-group `read` rule to remain anonymously visible after the migration.

#### Scenario: Operator preserves anonymous visibility after column removal
- **GIVEN** a register that was anonymously visible via the legacy `published` column
- **WHEN** the operator follows the migration documentation
- **THEN** the documentation MUST instruct the operator to add an authorization rule `{"read": [{"group": "public"}]}` (or a `publicatiedatum`/`$now` time-window) to that register
- **AND** after applying the rule, anonymous `GET /api/registers/{id}` MUST return HTTP 200 as before

### Requirement: File auto-share config key renamed from autoPublish to autoShare
The file auto-share behaviour (auto-creating a Nextcloud share link for a file uploaded as an object property value) is preserved, but its configuration key MUST be renamed from the ambiguous `autoPublish` to the clearly file-scoped `autoShare`. `FilePropertyHandler` MUST read `autoShare` (at the property level, falling back to the schema-level `autoShare`) to decide whether to share an uploaded file. `autoPublish` MUST NOT be read — not as an object-publishing key and not as a file-share fallback. This is a BREAKING config rename: schemas/properties that used `autoPublish` to auto-share files MUST be migrated to `autoShare`, and the change MUST ship documentation describing the manual migration (consistent with the manual operator-migration approach used for the published-column removal). When a legacy `autoPublish` key is encountered it MUST be ignored (no auto-share) and a deprecation warning MUST be logged so operators can find and migrate it. The schema-editor file-config UI MUST expose the toggle under the `autoShare` name.

#### Scenario: New autoShare key auto-creates the file share
- **GIVEN** a schema/property whose file configuration sets `autoShare: true`
- **WHEN** a file is attached to an object through that property
- **THEN** `FilePropertyHandler` MUST request a Nextcloud share link for the uploaded file (`share: true`)
- **AND** the behaviour MUST be independent of the removed object/register/schema publishing

#### Scenario: Legacy autoPublish key is ignored and logged
- **GIVEN** a schema/property whose file configuration still sets `autoPublish: true` and no `autoShare`
- **WHEN** a file is attached to an object through that property
- **THEN** the file MUST NOT be auto-shared (the legacy key is ignored)
- **AND** a deprecation warning naming `autoPublish` → `autoShare` MUST be logged

#### Scenario: autoShare default is no auto-share
- **GIVEN** a schema/property whose file configuration sets neither `autoShare` nor `autoPublish`
- **WHEN** a file is attached to an object through that property
- **THEN** the file MUST NOT be auto-shared

## MODIFIED Requirements

### Requirement: Backward Compatibility
Schema configuration containing the deprecated object-publishing keys (`objectPublishedField`, `objectDepublishedField`) MUST be ignored without raising an error, and a deprecation warning MUST be logged when these keys are encountered. The `published`/`depublished` fields on the Register and Schema entities are now also removed (anonymous visibility is expressed via RBAC `public`-group rules — see `auth-system`). Nextcloud file publish/depublish operations remain out of scope (handled by Nextcloud share management). The file auto-share behaviour is preserved but **its config key is renamed from `autoPublish` to `autoShare`** — see the dedicated requirement below; `autoPublish` MUST NOT be read for any purpose (object publishing or file sharing).

#### Scenario: Deprecated Config Keys Ignored
- **GIVEN** a schema with `objectPublishedField` in its configuration
- **WHEN** an object is saved
- **THEN** the config key MUST be ignored
- **AND** a deprecation warning MUST be logged
