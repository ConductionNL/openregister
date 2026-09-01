# migration-mapping-packs Specification

## Purpose
TBD - created by archiving change migration-mapping-packs. Update Purpose after archive.
## Requirements
### Requirement: The system MUST validate migration pack definitions structurally before storing them
A migration pack definition SHALL be a JSON document with a slug `id` (lowercase letters/digits/hyphens, unique per instance), a non-empty `name`, a `sourceFormat` from `csv|json|excel`, a strict-semver `version`, a non-empty `fieldMappings` array (each entry with non-empty `source` and `target` strings, optional boolean `required`, optional `transform`), an `idStrategy` (`{type: "generate"}` or `{type: "sourceField", field: "<non-empty>"}`), and optional `defaults` (object) and `skipRows` (positive integers). Transform blocks SHALL declare a `type` from `trim|date|bool-map|concat|lookup|const` and carry that type's required fields (`map` for bool-map/lookup, `fields` for concat, `value` for const). Structurally invalid definitions SHALL be rejected at create/update/import time with HTTP 422, never stored and never deferred to import time.

#### Scenario: Valid pack definition is accepted
- **GIVEN** an admin submits a pack with a slug id, name, `sourceFormat: "csv"`, `version: "1.0.0"`, one field mapping with source and target, and `idStrategy: {type: "generate"}`
- **WHEN** they call `POST /api/migration-packs`
- **THEN** the pack is stored and returned with HTTP 201

#### Scenario: Unknown transform type is rejected
- **WHEN** an admin submits a pack whose field mapping declares `transform: {type: "reverse"}`
- **THEN** the system returns HTTP 422 naming the allowed transform set

#### Scenario: Field mapping without a target is rejected
- **WHEN** an admin submits a pack whose field mapping has a `source` but no `target`
- **THEN** the system returns HTTP 422 identifying the offending mapping index

#### Scenario: Non-semver version is rejected
- **WHEN** an admin submits a pack with `version: "v1"` (or any non-`MAJOR.MINOR.PATCH` value)
- **THEN** the system returns HTTP 422

#### Scenario: Duplicate pack slug is rejected
- **GIVEN** a pack with id `zgw-zaken-json` already exists
- **WHEN** an admin creates another pack with the same id
- **THEN** the system returns HTTP 422 stating the id already exists

### Requirement: The mapping engine MUST apply pack transforms per row and never leak unresolved references
`MappingEngine::mapRow()` SHALL map one parsed source row (flat column names for CSV/Excel; JSON-Pointer `/a/b` paths for JSON, with `~0`/`~1` unescaping) onto target schema properties, applying the mapping's transform: `trim` (whitespace), `date` (strict `sourceFormat` parse re-emitted as `targetFormat`, default `Y-m-d`), `bool-map`/`lookup` (map resolution with optional `default`), `concat` (primary + extra fields joined by `separator`), `const` (fixed value, applied even for empty sources). Pack `defaults` SHALL seed the mapped row and survive when an optional mapping resolves to nothing. A `required` mapping whose source is missing/empty SHALL error the row. A `lookup` or `bool-map` whose source value has no map entry and no configured `default` SHALL ERROR the row — the unresolved source value SHALL never pass through as the target property's literal value. An unparseable `date` SHALL error the row. Every mapping error SHALL carry the row number, source column/pointer, target property, and transform type.

#### Scenario: Transforms map a legacy row onto schema properties
- **GIVEN** a pack mapping `Naam→title (trim)`, `Actief→active (bool-map {"J": true, "N": false})`, and `Start→startDate (date d-m-Y → Y-m-d)`
- **WHEN** the engine maps the row `{Naam: "  Acme  ", Actief: "J", Start: "05-01-2024"}`
- **THEN** the mapped data is `{title: "Acme", active: true, startDate: "2024-01-05"}` with no errors

#### Scenario: Unresolved lookup value errors the row instead of leaking the literal
- **GIVEN** a pack mapping `ZaakType→zaakTypeCode` with `lookup` map `{"https://cat/known": "KNOWN"}` and no default
- **WHEN** the engine maps a row whose `ZaakType` is `https://cat/unmapped`
- **THEN** the row is reported as an error naming row number, source `ZaakType`, and transform `lookup`
- **AND** `zaakTypeCode` is absent from the mapped data — the raw URL is never written through

#### Scenario: Required source field missing errors the row
- **GIVEN** a pack where the `Naam→title` mapping is `required`
- **WHEN** the engine maps a row with an empty `Naam`
- **THEN** the row errors with a message naming the missing required source field

#### Scenario: Unparseable date errors the row
- **GIVEN** a `date` transform with `sourceFormat: "d-m-Y"`
- **WHEN** the source value is `not-a-date`
- **THEN** the row errors naming the value and format, and the target property is not set

#### Scenario: idStrategy sourceField supplies the upsert id
- **GIVEN** a pack with `idStrategy: {type: "sourceField", field: "Uuid"}`
- **WHEN** the engine maps a row containing `Uuid: "abc-123"`
- **THEN** the mapped data carries `id: "abc-123"`; with `{type: "generate"}` no id is set and the import pipeline creates a new object

#### Scenario: JSON-Pointer sources resolve nested values
- **GIVEN** a mapping with source `/zaak/status`
- **WHEN** the engine maps the decoded object `{zaak: {status: "open"}}`
- **THEN** the target receives `"open"`; a pointer that resolves to nothing on an optional mapping is silently skipped

### Requirement: Migration packs MUST be manageable via admin-gated REST CRUD with JSON file import/export
The system SHALL expose `/api/migration-packs` REST routes: `index`/`show`/`export` available to any authenticated user (packs are shared instance assets the import flow must browse), `create`/`update`/`destroy`/`import` restricted to Nextcloud administrators (HTTP 403 otherwise; HTTP 401 for anonymous callers on all routes). `export` SHALL download the pack's definition as a standalone JSON file named `<packSlug>.json`; `import` SHALL accept an uploaded pack JSON file and upsert by the document's own `id` (create when absent, update the existing row when present), so packs round-trip between instances.

#### Scenario: Non-admin cannot mutate packs
- **GIVEN** an authenticated non-admin user
- **WHEN** they call `POST /api/migration-packs`, `PUT /api/migration-packs/{id}`, or `DELETE /api/migration-packs/{id}`
- **THEN** each call returns HTTP 403

#### Scenario: Any authenticated user can browse and export packs
- **GIVEN** an authenticated non-admin user
- **WHEN** they call `GET /api/migration-packs` and `GET /api/migration-packs/{id}/export`
- **THEN** the list returns HTTP 200 and the export downloads the pack definition as JSON

#### Scenario: Pack JSON export/import round-trips between instances
- **GIVEN** a pack exported as `zgw-zaken-json.json` from one instance
- **WHEN** an admin uploads that file to `POST /api/migration-packs/import` on another instance
- **THEN** an identical pack is created there; re-importing an updated file with the same `id` updates the existing row instead of erroring

#### Scenario: Anonymous callers are rejected everywhere
- **WHEN** an unauthenticated request hits any `/api/migration-packs` route
- **THEN** the system returns HTTP 401

### Requirement: The import endpoint MUST execute migration packs inside the existing row pipeline
`POST /api/registers/{id}/import` SHALL accept an optional `packId` parameter naming a stored pack by its slug. When present on a CSV or JSON import, every source row SHALL be mapped through the pack's field mappings before the existing schema-driven validate/save pipeline; the single write path (`ObjectService::saveObjects()` for CSV, `ObjectService::saveObject()` for JSON) SHALL be preserved unchanged. Rows listed in the pack's `skipRows` SHALL be skipped. A row with mapping errors SHALL be excluded from the save batch (never partially mapped) and reported in the existing per-row error format with `type: MigrationPackMappingError` and a message labeled with the source column and transform. An unknown `packId` SHALL return HTTP 400 before any file processing. `packId` on an Excel or configuration-bundle import SHALL return HTTP 400 with guidance rather than being silently ignored.

#### Scenario: CSV import with a pack maps columns before the normal save pipeline
- **GIVEN** a stored CSV pack mapping `Naam→title` and `Actief→active (bool-map)`
- **WHEN** a user with manage permission imports a CSV with those legacy columns passing `packId`
- **THEN** the saved objects carry `title`/`active` values produced by the pack and the summary reports them as created/updated via the unchanged bulk save path

#### Scenario: Mapping errors surface in the existing per-row error report
- **GIVEN** a CSV import with a pack where one row's required source is empty and another row's lookup is unresolved
- **WHEN** the import runs
- **THEN** both rows are excluded from the save, each reported with its row number, the source column, the transform, and `type: MigrationPackMappingError`, while valid rows still import

#### Scenario: Unknown packId fails fast
- **WHEN** a user imports with `packId: "does-not-exist"`
- **THEN** the system returns HTTP 400 naming the missing pack before reading the file

#### Scenario: packId on an unsupported import type is rejected, not ignored
- **WHEN** a user passes `packId` on an Excel or configuration-bundle import
- **THEN** the system returns HTTP 400 explaining packs apply to CSV/JSON row imports

### Requirement: Dry-run import MUST map and validate all rows while saving nothing
`POST /api/registers/{id}/import` SHALL accept a `dryRun` boolean parameter. When true (with or without a pack), the import SHALL map every row, validate each mapped object against the target schema via the read-only validation handler, and return a per-row report (`dryRun: true`, `validRows`, `invalidRows`, and per-row `{index, valid, errors, preview}`) — while invoking NO object save operation of any kind: `created`/`updated`/`unchanged` SHALL be empty and no object, audit row, or other persistent artifact SHALL be produced. This SHALL be genuinely side-effect-free — a pre-import quote, not a write-then-rollback.

#### Scenario: Dry-run maps and validates without saving
- **GIVEN** a CSV of 2 valid rows and a pack
- **WHEN** the user imports with `packId` and `dryRun=1`
- **THEN** the response reports `dryRun: true`, 2 valid rows with mapped previews, and empty created/updated lists
- **AND** `ObjectService::saveObjects()` and `saveObject()` are never invoked

#### Scenario: Dry-run reports schema-invalid rows per row
- **GIVEN** a row that maps successfully but fails target-schema validation
- **WHEN** a dry-run import runs
- **THEN** that row is reported `valid: false` with the validation error message, counted in `invalidRows`, and nothing is saved

### Requirement: The system MUST seed one built-in reference pack marked as a template
On install and upgrade, a repair step SHALL seed the `zgw-zaken-json` pack (`builtin: true`, no owner) mapping the ZGW Zaken API export shape — `identificatie`, `omschrijving`, `startdatum`, `einddatum`, `status`, `zaaktype` (URL→code lookup), `vertrouwelijkheidaanduiding` — onto generic case-like target properties. Its description SHALL state it is a template requiring target-schema selection and a real zaaktype catalogue mapping; its zaaktype lookup SHALL ship with only a placeholder map entry and no default, so unmapped zaaktype URLs fail loudly by design. Seeding SHALL be idempotent (skipped when the slug exists, including after admin customisation or deletion-then-recreate) and SHALL never fail the migration on error. Packs for proprietary vendor formats SHALL NOT be fabricated.

#### Scenario: Reference pack is present after install
- **WHEN** OpenRegister is installed or upgraded
- **THEN** `GET /api/migration-packs` lists `zgw-zaken-json` with `builtin: true` and a description marking it as a template

#### Scenario: Seeding never duplicates or overwrites an existing pack
- **GIVEN** an admin has customised the seeded `zgw-zaken-json` pack
- **WHEN** the repair step runs again on the next upgrade
- **THEN** the customised pack is left untouched and no duplicate is created

